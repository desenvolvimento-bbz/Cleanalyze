#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
analise_anual_cli.py

Recebe 1 PDF anual de Prestação de Contas, fatia os lançamentos por mês
e gera análises comparativas:
  - Mesmo mês do ano anterior vs último mês (ex: Mar/2025 vs Mar/2026)
  - Mês anterior vs último mês (ex: Fev/2026 vs Mar/2026)
  - Análise de padrão mensal (recorrência, anomalias)
  - Alerta de Fundo de Reserva

Uso:
  python3 analise_anual_cli.py \
    --pdf "anual.pdf" \
    --saida "analise.xlsx" \
    --json "analise.json"
"""

import argparse
import json as json_mod
import re
import sys
from collections import defaultdict
from pathlib import Path
from typing import List, Dict, Optional

# Reutilizar funções do compare_prestacao_cli
from compare_prestacao_cli import (
    extrair_texto_pdf,
    parse_despesas,
    parse_resumo,
    parse_totais_contas,
    parse_numero_br,
    extrair_secoes_do_resumo,
    analisar_diferencas,
    _build_totais_comparativo,
    _build_subcategorias_por_conta,
    _build_fundo_reserva_info,
    _fmt_brl,
    CONTA_NAMES,
)

try:
    import openpyxl
    from openpyxl.styles import Font, PatternFill, Alignment
except ImportError:
    print("ERRO: openpyxl não instalado.", file=sys.stderr)
    sys.exit(1)


# =============================================================================
# FATIAMENTO POR MÊS
# =============================================================================

def fatiar_por_mes(expenses: List[dict]) -> Dict[str, List[dict]]:
    """Agrupa lançamentos por mês (MM/YYYY) usando a data de cada lançamento.

    Retorna dict ordenado: {'03/2025': [...], '04/2025': [...], ...}
    """
    por_mes = defaultdict(list)
    for e in expenses:
        data = e.get("data", "")
        m = re.match(r"\d{2}/(\d{2}/\d{4})", data)
        if m:
            por_mes[m.group(1)].append(e)
        # Lançamentos sem data (PAGTO FUNCIONARIOS, 13° SALARIO, Tarifa)
        # ficam associados ao mês corrente via contexto — ignoramos aqui
        # pois não tem data individual
    return dict(sorted(por_mes.items(), key=lambda x: _mes_sort_key(x[0])))


def _mes_sort_key(mes_ano: str) -> tuple:
    """Converte 'MM/YYYY' em (YYYY, MM) para ordenação."""
    parts = mes_ano.split("/")
    return (int(parts[1]), int(parts[0])) if len(parts) == 2 else (0, 0)


def _mes_label(mes_ano: str) -> str:
    """Converte '03/2026' em 'Mar/2026'."""
    nomes = {
        '01': 'Jan', '02': 'Fev', '03': 'Mar', '04': 'Abr',
        '05': 'Mai', '06': 'Jun', '07': 'Jul', '08': 'Ago',
        '09': 'Set', '10': 'Out', '11': 'Nov', '12': 'Dez',
    }
    parts = mes_ano.split("/")
    if len(parts) == 2:
        return f"{nomes.get(parts[0], parts[0])}/{parts[1]}"
    return mes_ano


# =============================================================================
# CONSTRUIR DADOS DE UM "MÊS VIRTUAL"
# =============================================================================

def construir_dados_mes(expenses_mes: List[dict], periodo_label: str,
                        condominio: str, section_names: set) -> dict:
    """Constrói um dict com estrutura idêntica ao parse_despesas() para um mês."""
    return {
        "periodo": periodo_label,
        "condominio": condominio,
        "expenses": expenses_mes,
    }


def construir_totais_mes(expenses_mes: List[dict], section_names: set) -> dict:
    """Calcula totais por conta a partir dos lançamentos de um mês."""
    section_name_set = {s.upper() for s in section_names}
    totais = {}

    for e in expenses_mes:
        secao = e.get("secao", "").strip()
        conta = e.get("conta", "").strip()
        if not conta:
            continue
        key = f"{secao} > {conta}"
        valor = e.get("valor", 0) or 0

        if key not in totais:
            totais[key] = {
                "secao": secao, "conta": conta,
                "total": 0, "percentual": "",
                "isHighLevel": conta.upper() in section_name_set,
            }
        totais[key]["total"] += valor

    # Calcular percentuais
    grand_total = sum(t["total"] for t in totais.values() if not t["isHighLevel"])
    for t in totais.values():
        if grand_total > 0:
            t["percentual"] = f"{(t['total'] / grand_total * 100):.2f}%"

    return totais


# =============================================================================
# LANÇAMENTOS SEM DATA (PAGTO, 13°, Tarifa) — distribuir ao último mês
# =============================================================================

def coletar_lancamentos_sem_data(expenses: List[dict]) -> List[dict]:
    """Retorna lançamentos sem data válida (resumos como PAGTO FUNCIONARIOS)."""
    sem_data = []
    for e in expenses:
        data = e.get("data", "")
        if not re.match(r"\d{2}/\d{2}/\d{4}", data):
            sem_data.append(e)
    return sem_data


# =============================================================================
# RESUMO FINANCEIRO POR MÊS (do Resumo Financeiro Contábil)
# =============================================================================

def parse_resumo_por_secao(resumo: List[dict]) -> dict:
    """Converte lista de resumo em dict por categoria."""
    return {r["categoria"]: r for r in resumo}


# =============================================================================
# ANÁLISE DE PADRÃO MENSAL
# =============================================================================

def analisar_padrao_mensal(meses_data: Dict[str, List[dict]], ultimo_mes_key: str) -> dict:
    """Analisa recorrência de subcategorias ao longo dos meses.

    Retorna:
    - ausentesNoMes: subcats que existiam no mesmo mês do ano anterior mas não no último
    - novasSemHistorico: subcats no último mês que nunca existiram antes
    - recorrenciaPorSubcat: quantos meses cada subcat apareceu
    - totaisPorMes: total geral de despesas por mês
    """
    nomes_mes = {
        '01': 1, '02': 2, '03': 3, '04': 4, '05': 5, '06': 6,
        '07': 7, '08': 8, '09': 9, '10': 10, '11': 11, '12': 12,
    }

    # Mês numérico do último mês
    ultimo_mm = ultimo_mes_key.split("/")[0] if "/" in ultimo_mes_key else "00"
    mes_atual_num = int(ultimo_mm)

    # Agrupar: chave (conta > subcat) -> set de meses (MM/YYYY)
    mensal_contagem = defaultdict(set)  # chave -> set de MM/YYYY
    mensal_totais = defaultdict(lambda: defaultdict(float))  # chave -> {MM/YYYY: total}
    totais_por_mes = defaultdict(float)

    # Tracking de contas por mês (nível conta, sem subcategoria)
    contas_por_mes = defaultdict(set)  # conta -> set de MM/YYYY
    contas_totais_por_mes = defaultdict(lambda: defaultdict(float))  # conta -> {MM/YYYY: total}

    for mes_key, expenses in meses_data.items():
        for e in expenses:
            subcat = e.get("subcategoria", "").strip()
            conta = e.get("conta", "").strip()
            valor = e.get("valor", 0) or 0
            if not conta:
                continue
            # Tracking de conta
            contas_por_mes[conta].add(mes_key)
            contas_totais_por_mes[conta][mes_key] += valor
            totais_por_mes[mes_key] += valor
            # Tracking de subcategoria (se existir)
            if subcat:
                chave = f"{conta} > {subcat}"
                mensal_contagem[chave].add(mes_key)
                mensal_totais[chave][mes_key] += valor

    # Subcats do último mês
    subcats_ultimo = defaultdict(float)
    contas_ultimo = defaultdict(float)
    for e in meses_data.get(ultimo_mes_key, []):
        subcat = e.get("subcategoria", "").strip()
        conta = e.get("conta", "").strip()
        if not conta:
            continue
        contas_ultimo[conta] += e.get("valor", 0) or 0
        if subcat:
            chave = f"{conta} > {subcat}"
            subcats_ultimo[chave] += e.get("valor", 0) or 0

    # Encontrar mesmo mês do ano anterior
    mesmo_mes_anterior = None
    for mes_key in sorted(meses_data.keys(), key=_mes_sort_key):
        mm = mes_key.split("/")[0]
        if int(mm) == mes_atual_num and mes_key != ultimo_mes_key:
            mesmo_mes_anterior = mes_key

    # Ausentes: existiam no mesmo mês anterior mas não no último
    ausentes_no_mes = []
    if mesmo_mes_anterior:
        for chave, meses in mensal_contagem.items():
            if mesmo_mes_anterior in meses and chave not in subcats_ultimo:
                partes = chave.split(" > ", 1)
                total_mesmo_mes = mensal_totais[chave].get(mesmo_mes_anterior, 0)
                meses_numeros = sorted([int(m.split("/")[0]) for m in meses])
                ausentes_no_mes.append({
                    "conta": partes[0] if len(partes) > 0 else "",
                    "subcategoria": partes[1] if len(partes) > 1 else "",
                    "totalMesmoMesAnterior": round(total_mesmo_mes, 2),
                    "mesesPresente": meses_numeros,
                    "recorrencia": len(meses),
                })

    # Novas sem histórico
    todos_meses_anteriores = set()
    for mes_key in meses_data:
        if mes_key != ultimo_mes_key:
            for e in meses_data[mes_key]:
                subcat = e.get("subcategoria", "").strip()
                conta = e.get("conta", "").strip()
                if subcat and conta:
                    todos_meses_anteriores.add(f"{conta} > {subcat}")

    novas_sem_historico = []
    for chave, total in subcats_ultimo.items():
        if chave not in todos_meses_anteriores:
            partes = chave.split(" > ", 1)
            novas_sem_historico.append({
                "conta": partes[0] if len(partes) > 0 else "",
                "subcategoria": partes[1] if len(partes) > 1 else "",
                "totalAtual": round(total, 2),
            })

    # Contas novas: existem no último mês mas nunca existiram em nenhum mês anterior
    todas_contas_anteriores = set()
    for mes_key in meses_data:
        if mes_key != ultimo_mes_key:
            for e in meses_data[mes_key]:
                conta = e.get("conta", "").strip()
                if conta:
                    todas_contas_anteriores.add(conta)

    contas_novas = []
    for conta, total in contas_ultimo.items():
        if conta not in todas_contas_anteriores:
            contas_novas.append({
                "conta": conta,
                "totalAtual": round(total, 2),
            })

    # Contas ausentes: existiam no mesmo mês do ano anterior OU no mês anterior,
    # mas não existem no último mês
    contas_ausentes = []
    contas_ausentes_set = set()  # evitar duplicatas

    # Fontes para comparação: mesmo mês ano anterior + penúltimo mês
    meses_referencia = []
    if mesmo_mes_anterior:
        meses_referencia.append(mesmo_mes_anterior)
    # Penúltimo mês (mês anterior direto)
    meses_keys_sorted = sorted(meses_data.keys(), key=_mes_sort_key)
    if len(meses_keys_sorted) >= 2:
        penultimo = meses_keys_sorted[-2]
        if penultimo != mesmo_mes_anterior:
            meses_referencia.append(penultimo)

    for ref_mes in meses_referencia:
        contas_ref = set()
        for e in meses_data.get(ref_mes, []):
            conta = e.get("conta", "").strip()
            if conta:
                contas_ref.add(conta)
        for conta in contas_ref:
            if conta not in contas_ultimo and conta not in contas_ausentes_set:
                contas_ausentes_set.add(conta)
                # Pegar o total do mês de referência mais recente
                total_ref = contas_totais_por_mes[conta].get(ref_mes, 0)
                meses_presente = sorted([int(m.split("/")[0]) for m in contas_por_mes[conta]])
                contas_ausentes.append({
                    "conta": conta,
                    "totalUltimaRef": round(total_ref, 2),
                    "mesReferencia": ref_mes,
                    "mesesPresente": meses_presente,
                    "recorrencia": len(contas_por_mes[conta]),
                })

    # Recorrência por subcat (para visão geral)
    total_meses = len(meses_data)
    recorrencia = []
    for chave, meses in sorted(mensal_contagem.items()):
        partes = chave.split(" > ", 1)
        total_geral = sum(mensal_totais[chave].values())
        recorrencia.append({
            "conta": partes[0] if len(partes) > 0 else "",
            "subcategoria": partes[1] if len(partes) > 1 else "",
            "mesesPresente": len(meses),
            "totalMeses": total_meses,
            "totalGeral": round(total_geral, 2),
            "mediaMensal": round(total_geral / len(meses), 2) if meses else 0,
        })

    return {
        "mesAtual": mes_atual_num,
        "mesAtualKey": ultimo_mes_key,
        "mesmoMesAnterior": mesmo_mes_anterior,
        "mesmoMesAnteriorLabel": _mes_label(mesmo_mes_anterior) if mesmo_mes_anterior else None,
        "contasNovas": sorted(contas_novas, key=lambda x: -x["totalAtual"]),
        "contasAusentes": sorted(contas_ausentes, key=lambda x: -x["totalUltimaRef"]),
        "ausentesNoMes": sorted(ausentes_no_mes, key=lambda x: -x["totalMesmoMesAnterior"]),
        "novasSemHistorico": sorted(novas_sem_historico, key=lambda x: -x["totalAtual"]),
        "recorrenciaPorSubcat": sorted(recorrencia, key=lambda x: -x["mesesPresente"]),
        "totaisPorMes": {k: round(v, 2) for k, v in sorted(totais_por_mes.items(), key=lambda x: _mes_sort_key(x[0]))},
    }


# =============================================================================
# FUNDO DE RESERVA (adaptado para anual)
# =============================================================================

def analisar_fundo_reserva_anual(resumo: List[dict], expenses: List[dict],
                                  meses_data: Dict[str, List[dict]],
                                  ultimo_mes_key: str) -> dict:
    """Analisa Fundo de Reserva focando no mês atual.

    O alerta só dispara se houver lançamentos de despesa no Fundo de Reserva
    no último mês (mês atual). Débitos em meses anteriores são informativos.
    """
    info = {
        "saldoAtual": 0,
        "creditosPeriodo": 0,
        "debitosPeriodo": 0,
        "temDebitoMesAtual": False,
        "debitoMesAtual": 0,
        "lancamentosMesAtual": [],
        "lancamentosAnteriores": {},
    }

    # Resumo Financeiro geral (saldo final)
    for r in resumo:
        if "FUNDO DE RESERVA" in r.get("categoria", "").upper():
            info["saldoAtual"] = r.get("saldoAtual", 0)
            info["creditosPeriodo"] = r.get("creditos", 0)
            info["debitosPeriodo"] = r.get("debitos", 0)

    # Lançamentos de Fundo de Reserva por mês
    for mes_key, exps in meses_data.items():
        lancs_mes = []
        for e in exps:
            if "FUNDO" in e.get("secao", "").upper() and "RESERVA" in e.get("secao", "").upper():
                lancs_mes.append({
                    "subcategoria": e.get("subcategoria", ""),
                    "descricao": e.get("descricao", ""),
                    "data": e.get("data", ""),
                    "valor": e.get("valor", 0),
                })
        if lancs_mes:
            if mes_key == ultimo_mes_key:
                info["lancamentosMesAtual"] = lancs_mes
                info["debitoMesAtual"] = sum(l["valor"] or 0 for l in lancs_mes)
                info["temDebitoMesAtual"] = True
            else:
                info["lancamentosAnteriores"][mes_key] = lancs_mes

    return info


# =============================================================================
# XLSX
# =============================================================================

HEADER_FONT = Font(bold=True, color="FFFFFF")
HEADER_FILL_BLUE = PatternFill("solid", fgColor="4472C4")
HEADER_FILL_DARK = PatternFill("solid", fgColor="2E75B6")
HEADER_FILL_GREEN = PatternFill("solid", fgColor="548235")
HEADER_FILL_RED = PatternFill("solid", fgColor="C00000")
FILL_NOVA = PatternFill("solid", fgColor="FCE4EC")
FILL_AUSENTE = PatternFill("solid", fgColor="FFF2CC")
FMT_BRL = '#,##0.00'


def _apply_header(ws, fill):
    for cell in ws[1]:
        cell.font = HEADER_FONT
        cell.fill = fill
        cell.alignment = Alignment(horizontal="center", wrap_text=True)


def _set_col_widths(ws, widths):
    for idx, w in enumerate(widths, 1):
        ws.column_dimensions[openpyxl.utils.get_column_letter(idx)].width = w


def criar_aba_visao_mensal(wb, meses_data, section_names):
    """Aba com totais por conta por mês — visão panorâmica."""
    ws = wb.create_sheet("Visão Mensal")
    meses_keys = sorted(meses_data.keys(), key=_mes_sort_key)
    meses_labels = [_mes_label(k) for k in meses_keys]

    headers = ["Seção", "Conta"] + meses_labels + ["Total Anual", "Média Mensal"]
    ws.append(headers)
    _apply_header(ws, HEADER_FILL_BLUE)
    widths = [18, 30] + [14] * len(meses_keys) + [16, 16]
    _set_col_widths(ws, widths)

    # Calcular totais por conta por mês
    conta_mes = defaultdict(lambda: defaultdict(float))
    conta_info = {}
    for mes_key, exps in meses_data.items():
        for e in exps:
            conta = e.get("conta", "").strip()
            secao = e.get("secao", "").strip()
            if not conta:
                continue
            key = f"{secao} > {conta}"
            conta_mes[key][mes_key] += e.get("valor", 0) or 0
            conta_info[key] = {"secao": secao, "conta": conta}

    for key in sorted(conta_mes.keys()):
        info = conta_info[key]
        row = [info["secao"], info["conta"]]
        total = 0
        n_meses = 0
        for mk in meses_keys:
            v = conta_mes[key].get(mk, 0)
            row.append(v if v else None)
            total += v
            if v:
                n_meses += 1
        media = total / n_meses if n_meses else 0
        row.extend([total, media])
        ws.append(row)

    # Formatar valores
    for row in ws.iter_rows(min_row=2, min_col=3):
        for cell in row:
            if isinstance(cell.value, (int, float)):
                cell.number_format = FMT_BRL

    ws.auto_filter.ref = ws.dimensions


def criar_aba_comparativo(wb, nome, old_data, new_data, old_totais, new_totais,
                          old_expenses, new_expenses, diffs):
    """Aba com comparativo entre dois meses (reutiliza lógica do 1:1)."""
    ws = wb.create_sheet(nome)
    headers = ["Tipo", "Seção", "Conta", "Subcategoria",
               "Total Anterior (R$)", "Total Atual (R$)", "Diferença (R$)", "Observação"]
    ws.append(headers)
    _apply_header(ws, HEADER_FILL_RED)
    _set_col_widths(ws, [22, 18, 28, 30, 18, 18, 18, 50])

    # Totais por conta
    all_keys = sorted(set(list(old_totais.keys()) + list(new_totais.keys())))
    for key in all_keys:
        old = old_totais.get(key, {})
        nw = new_totais.get(key, {})
        if old.get("isHighLevel") or nw.get("isHighLevel"):
            continue
        old_total = old.get("total", 0) or 0
        new_total = nw.get("total", 0) or 0
        diff = new_total - old_total
        obs = ""
        if not old.get("total") and nw.get("total"):
            obs = "NOVA"
        if old.get("total") and not nw.get("total"):
            obs = "AUSENTE"

        ws.append([
            "CONTA", nw.get("secao") or old.get("secao", ""),
            nw.get("conta") or old.get("conta", ""),
            "", old_total, new_total, diff, obs,
        ])
        row_idx = ws.max_row
        if "NOVA" in obs:
            for cell in ws[row_idx]:
                cell.fill = FILL_NOVA
        elif "AUSENTE" in obs:
            for cell in ws[row_idx]:
                cell.fill = FILL_AUSENTE

    # Diferenças de subcategorias
    for d in diffs:
        ws.append([
            d["tipo"], d.get("secao", ""), d.get("conta", ""),
            d.get("subcategoria", ""),
            d.get("totalAnterior", 0), d.get("totalAtual", 0),
            (d.get("totalAtual", 0) or 0) - (d.get("totalAnterior", 0) or 0),
            d.get("detalhes", ""),
        ])
        row_idx = ws.max_row
        if "NOVA" in d["tipo"]:
            for cell in ws[row_idx]:
                cell.fill = FILL_NOVA
        elif "AUSENTE" in d["tipo"] or "REMOVIDA" in d["tipo"]:
            for cell in ws[row_idx]:
                cell.fill = FILL_AUSENTE

    for col_idx in (5, 6, 7):
        for row in ws.iter_rows(min_row=2, min_col=col_idx, max_col=col_idx):
            for cell in row:
                if isinstance(cell.value, (int, float)):
                    cell.number_format = FMT_BRL

    ws.auto_filter.ref = ws.dimensions


def criar_aba_padrao_mensal(wb, analise):
    """Aba com análise de padrão mensal."""
    ws = wb.create_sheet("Padrão Mensal")
    headers = ["Conta", "Subcategoria", "Meses Presente", "Total Meses",
               "Total Geral (R$)", "Média Mensal (R$)"]
    ws.append(headers)
    _apply_header(ws, HEADER_FILL_GREEN)
    _set_col_widths(ws, [28, 30, 14, 12, 18, 18])

    for r in analise.get("recorrenciaPorSubcat", []):
        ws.append([
            r["conta"], r["subcategoria"], r["mesesPresente"],
            r["totalMeses"], r["totalGeral"], r["mediaMensal"],
        ])

    for col_idx in (5, 6):
        for row in ws.iter_rows(min_row=2, min_col=col_idx, max_col=col_idx):
            for cell in row:
                if isinstance(cell.value, (int, float)):
                    cell.number_format = FMT_BRL

    ws.auto_filter.ref = ws.dimensions


def gerar_xlsx_anual(visao_mensal_data, comparativo_mesmo_mes, comparativo_mes_anterior,
                     analise_mensal, meses_data, section_names, output_path):
    """Gera XLSX com todas as abas da análise anual."""
    wb = openpyxl.Workbook()
    wb.remove(wb.active)

    criar_aba_visao_mensal(wb, meses_data, section_names)

    if comparativo_mesmo_mes:
        cm = comparativo_mesmo_mes
        # openpyxl não aceita / no nome da aba
        label_ant = cm['labelAnterior'].replace("/", "-")
        label_atu = cm['labelAtual'].replace("/", "-")
        criar_aba_comparativo(
            wb, f"Mesmo Mes {label_ant} x {label_atu}",
            cm["oldData"], cm["newData"], cm["oldTotais"], cm["newTotais"],
            cm["oldData"]["expenses"], cm["newData"]["expenses"], cm["diffs"],
        )

    if comparativo_mes_anterior:
        ca = comparativo_mes_anterior
        label_ant2 = ca['labelAnterior'].replace("/", "-")
        label_atu2 = ca['labelAtual'].replace("/", "-")
        criar_aba_comparativo(
            wb, f"Mes Ant {label_ant2} x {label_atu2}",
            ca["oldData"], ca["newData"], ca["oldTotais"], ca["newTotais"],
            ca["oldData"]["expenses"], ca["newData"]["expenses"], ca["diffs"],
        )

    criar_aba_padrao_mensal(wb, analise_mensal)

    wb.save(output_path)


# =============================================================================
# MAIN
# =============================================================================

def main():
    ap = argparse.ArgumentParser(
        description="Analisa PDF anual de Prestação de Contas — fatiamento por mês e detecção de anomalias."
    )
    ap.add_argument("--pdf", required=True, help="PDF anual de Prestação de Contas")
    ap.add_argument("--saida", required=True, help="Caminho do XLSX de saída")
    ap.add_argument("--json", default="", help="Caminho do JSON de saída")

    args = ap.parse_args()

    if not Path(args.pdf).is_file():
        print(f"ERRO: PDF não encontrado: {args.pdf}", file=sys.stderr)
        sys.exit(1)

    # 1. Extrair texto
    print("Extraindo texto do PDF anual...")
    raw_text = extrair_texto_pdf(args.pdf)

    # 2. Parsear
    print("Parseando despesas...")
    section_names = extrair_secoes_do_resumo(raw_text)
    print(f"Seções detectadas: {', '.join(sorted(section_names))}")

    data = parse_despesas(raw_text, section_names)
    resumo = parse_resumo(raw_text)
    condominio = data["condominio"] or "(não identificado)"
    periodo = data["periodo"]

    print(f"Condomínio: {condominio}")
    print(f"Período: {periodo}")
    print(f"Total de lançamentos: {len(data['expenses'])}")

    # 3. Fatiar por mês
    meses_data = fatiar_por_mes(data["expenses"])
    meses_keys = sorted(meses_data.keys(), key=_mes_sort_key)

    print(f"\nMeses encontrados: {len(meses_keys)}")
    for mk in meses_keys:
        print(f"  {_mes_label(mk)}: {len(meses_data[mk])} lançamentos")

    # --- VALIDAÇÃO DE PERÍODO ---
    if len(meses_keys) < 12:
        n = len(meses_keys)
        palavra = "mês" if n == 1 else "meses"
        print(f"\nERRO: PDF contém apenas {n} {palavra} de lançamentos. "
              f"A análise anual requer no mínimo 12 meses (1 ano) de dados.\n"
              f"Verifique se o período do relatório cobre pelo menos 12 meses consecutivos.\n"
              f"Consulte o tutorial na página de upload para configurar o relatório corretamente.",
              file=sys.stderr)
        sys.exit(1)

    # Se tem mais de 13 meses, recortar para os últimos 13
    # (12 meses de histórico + mês atual)
    if len(meses_keys) > 13:
        total_original = len(meses_keys)
        meses_keys_recortados = meses_keys[-13:]
        meses_removidos = meses_keys[:-13]
        # Remover lançamentos dos meses excedentes
        for mk in meses_removidos:
            del meses_data[mk]
        meses_keys = meses_keys_recortados
        print(f"\nAVISO: PDF contém {total_original} meses. "
              f"Recortando para os últimos 13 (12 meses + mês atual).")
        print(f"Meses removidos: {', '.join(_mes_label(mk) for mk in meses_removidos)}")
        print(f"Período utilizado: {_mes_label(meses_keys[0])} a {_mes_label(meses_keys[-1])}")

    # Último mês e penúltimo mês
    ultimo_mes_key = meses_keys[-1]
    penultimo_mes_key = meses_keys[-2]

    # Mesmo mês do ano anterior (se existir)
    ultimo_mm = ultimo_mes_key.split("/")[0]
    ultimo_yyyy = int(ultimo_mes_key.split("/")[1])
    mesmo_mes_anterior_key = f"{ultimo_mm}/{ultimo_yyyy - 1}"
    if mesmo_mes_anterior_key not in meses_data:
        mesmo_mes_anterior_key = None

    print(f"\nÚltimo mês: {_mes_label(ultimo_mes_key)}")
    print(f"Penúltimo mês: {_mes_label(penultimo_mes_key)}")
    if mesmo_mes_anterior_key:
        print(f"Mesmo mês ano anterior: {_mes_label(mesmo_mes_anterior_key)}")
    else:
        print("AVISO: Mesmo mês do ano anterior não encontrado no período. "
              "Verifique se a data inicial do relatório inclui o mês completo.")

    # 4. Comparativo: mesmo mês do ano anterior vs último mês
    comparativo_mesmo_mes = None
    if mesmo_mes_anterior_key:
        print(f"\nComparando {_mes_label(mesmo_mes_anterior_key)} vs {_mes_label(ultimo_mes_key)}...")
        old_exps = meses_data[mesmo_mes_anterior_key]
        new_exps = meses_data[ultimo_mes_key]
        old_totais = construir_totais_mes(old_exps, section_names)
        new_totais = construir_totais_mes(new_exps, section_names)
        old_data = construir_dados_mes(old_exps, mesmo_mes_anterior_key, condominio, section_names)
        new_data = construir_dados_mes(new_exps, ultimo_mes_key, condominio, section_names)
        diffs = analisar_diferencas(old_exps, new_exps, old_totais, new_totais)

        comparativo_mesmo_mes = {
            "labelAnterior": _mes_label(mesmo_mes_anterior_key),
            "labelAtual": _mes_label(ultimo_mes_key),
            "keyAnterior": mesmo_mes_anterior_key,
            "keyAtual": ultimo_mes_key,
            "oldData": old_data,
            "newData": new_data,
            "oldTotais": old_totais,
            "newTotais": new_totais,
            "diffs": diffs,
            "totaisComparativo": _build_totais_comparativo(old_totais, new_totais),
            "subcategoriasPorConta": _build_subcategorias_por_conta(old_exps, new_exps),
        }
        print(f"  Diferenças encontradas: {len(diffs)}")

    # 5. Comparativo: mês anterior vs último mês
    print(f"\nComparando {_mes_label(penultimo_mes_key)} vs {_mes_label(ultimo_mes_key)}...")
    old_exps_prev = meses_data[penultimo_mes_key]
    new_exps_last = meses_data[ultimo_mes_key]
    old_totais_prev = construir_totais_mes(old_exps_prev, section_names)
    new_totais_last = construir_totais_mes(new_exps_last, section_names)
    old_data_prev = construir_dados_mes(old_exps_prev, penultimo_mes_key, condominio, section_names)
    new_data_last = construir_dados_mes(new_exps_last, ultimo_mes_key, condominio, section_names)
    diffs_prev = analisar_diferencas(old_exps_prev, new_exps_last, old_totais_prev, new_totais_last)

    comparativo_mes_anterior = {
        "labelAnterior": _mes_label(penultimo_mes_key),
        "labelAtual": _mes_label(ultimo_mes_key),
        "keyAnterior": penultimo_mes_key,
        "keyAtual": ultimo_mes_key,
        "oldData": old_data_prev,
        "newData": new_data_last,
        "oldTotais": old_totais_prev,
        "newTotais": new_totais_last,
        "diffs": diffs_prev,
        "totaisComparativo": _build_totais_comparativo(old_totais_prev, new_totais_last),
        "subcategoriasPorConta": _build_subcategorias_por_conta(old_exps_prev, new_exps_last),
    }
    print(f"  Diferenças encontradas: {len(diffs_prev)}")

    # 6. Análise de padrão mensal
    print("\nAnalisando padrão mensal...")
    analise_mensal = analisar_padrao_mensal(meses_data, ultimo_mes_key)
    print(f"  Subcats ausentes no mês: {len(analise_mensal['ausentesNoMes'])}")
    print(f"  Subcats novas sem histórico: {len(analise_mensal['novasSemHistorico'])}")

    # 7. Fundo de Reserva
    fundo_reserva = analisar_fundo_reserva_anual(resumo, data["expenses"], meses_data, ultimo_mes_key)

    # 8. Gerar XLSX
    print("\nGerando XLSX...")
    gerar_xlsx_anual(
        None, comparativo_mesmo_mes, comparativo_mes_anterior,
        analise_mensal, meses_data, section_names, args.saida,
    )
    print(f"XLSX salvo em: {args.saida}")

    # 9. Gerar JSON
    json_path = args.json if args.json else args.saida.replace(".xlsx", ".json")

    # Totais por mês para o frontend
    totais_mes_frontend = {}
    for mk in meses_keys:
        t = construir_totais_mes(meses_data[mk], section_names)
        totais_mes_frontend[mk] = {
            k: {"secao": v["secao"], "conta": v["conta"], "total": round(v["total"], 2),
                "percentual": v["percentual"]}
            for k, v in t.items() if not v.get("isHighLevel")
        }

    json_data = {
        "condominio": condominio,
        "periodo": periodo,
        "totalLancamentos": len(data["expenses"]),
        "meses": {mk: {"label": _mes_label(mk), "qtdLancamentos": len(meses_data[mk])}
                  for mk in meses_keys},
        "ultimoMes": ultimo_mes_key,
        "penultimoMes": penultimo_mes_key,
        "mesmoMesAnterior": mesmo_mes_anterior_key,
        "resumoFinanceiro": resumo,
        "fundoReserva": fundo_reserva,
        "comparativoMesmoMes": {
            "labelAnterior": comparativo_mesmo_mes["labelAnterior"],
            "labelAtual": comparativo_mesmo_mes["labelAtual"],
            "totaisComparativo": comparativo_mesmo_mes["totaisComparativo"],
            "subcategoriasPorConta": comparativo_mesmo_mes["subcategoriasPorConta"],
            "diferencas": comparativo_mesmo_mes["diffs"],
        } if comparativo_mesmo_mes else None,
        "comparativoMesAnterior": {
            "labelAnterior": comparativo_mes_anterior["labelAnterior"],
            "labelAtual": comparativo_mes_anterior["labelAtual"],
            "totaisComparativo": comparativo_mes_anterior["totaisComparativo"],
            "subcategoriasPorConta": comparativo_mes_anterior["subcategoriasPorConta"],
            "diferencas": comparativo_mes_anterior["diffs"],
        },
        "analiseMensal": analise_mensal,
        "totaisPorMes": totais_mes_frontend,
    }

    with open(json_path, "w", encoding="utf-8") as f:
        json_mod.dump(json_data, f, ensure_ascii=False, indent=2)
    print(f"JSON salvo em: {json_path}")

    # 10. Output para PHP
    print(f"\n__RESULTADO__")
    print(f"condominio={condominio}")
    print(f"periodo={periodo}")
    print(f"total_lancamentos={len(data['expenses'])}")
    print(f"total_meses={len(meses_keys)}")
    print(f"ultimo_mes={ultimo_mes_key}")
    print(f"penultimo_mes={penultimo_mes_key}")
    print(f"mesmo_mes_anterior={mesmo_mes_anterior_key or 'N/A'}")
    n_diffs_mm = len(comparativo_mesmo_mes["diffs"]) if comparativo_mesmo_mes else 0
    n_diffs_prev = len(diffs_prev)
    print(f"diferencas_mesmo_mes={n_diffs_mm}")
    print(f"diferencas_mes_anterior={n_diffs_prev}")
    print(f"json_path={json_path}")


if __name__ == "__main__":
    main()
