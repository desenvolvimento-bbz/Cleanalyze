#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
compare_prestacao_cli.py

Compara dois PDFs de Prestação de Contas (mês anterior vs mês atual) e gera
um XLSX com as diferenças encontradas: contas novas/ausentes, subcategorias
novas/removidas, totais por conta e resumo financeiro.

Uso:
  python3 compare_prestacao_cli.py \
    --pdf-anterior "fevereiro.pdf" \
    --pdf-atual "marco.pdf" \
    --saida "comparativo.xlsx"
"""

import argparse
import re
import sys
from pathlib import Path
from typing import List, Dict, Optional, Tuple

try:
    import pdfplumber
except ImportError:
    pdfplumber = None

try:
    import openpyxl
    from openpyxl.styles import Font, PatternFill, Alignment, numbers
except ImportError:
    print("ERRO: openpyxl não instalado. Execute: pip install openpyxl", file=sys.stderr)
    sys.exit(1)


# =============================================================================
# EXTRAÇÃO DE TEXTO DO PDF
# =============================================================================

def extrair_texto_pdf(pdf_path: str) -> str:
    """Extrai texto do PDF usando pdfplumber, agrupando por coordenada Y."""
    if pdfplumber is None:
        print("ERRO: pdfplumber não instalado. Execute: pip install pdfplumber", file=sys.stderr)
        sys.exit(1)

    full_text = ""
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            words = page.extract_words(keep_blank_chars=True, y_tolerance=3)
            if not words:
                continue

            # Agrupar palavras por coordenada Y (arredondada)
            lines = {}
            for w in words:
                y_key = round(w["top"])
                if y_key not in lines:
                    lines[y_key] = []
                lines[y_key].append((w["x0"], w["text"]))

            # Ordenar por Y (top → bottom), depois por X (left → right)
            for y_key in sorted(lines.keys()):
                items = sorted(lines[y_key], key=lambda t: t[0])
                line_text = "  ".join(item[1] for item in items)
                full_text += line_text + "\n"

            full_text += "\n--- PAGE BREAK ---\n\n"

    return full_text


# =============================================================================
# PARSING DE NÚMERO BRASILEIRO
# =============================================================================

def parse_numero_br(s: str) -> Optional[float]:
    """Converte '1.234,56' ou '-1.234,56' para float."""
    if not s:
        return None
    s = s.strip()
    cleaned = s.replace(".", "").replace(",", ".")
    try:
        return float(cleaned)
    except ValueError:
        return None


# =============================================================================
# PARSING DE DESPESAS
# =============================================================================

# Nomes de contas conhecidos (ordenados do maior para menor para evitar match parcial)
CONTA_NAMES = sorted([
    "PESSOAL PROPRIO", "PESSOAL",
    "CONSERVAÇÃO/MANUTENÇÃO", "CONSERV/MANUT/CONTRATOS",
    "CONSUMOS", "IMPOSTOS/TAXAS",
    "BANCÁRIAS", "DESPESAS DIVERSAS",
    "HONORARIOS / ADMINISTRATIVO", "SEGUROS",
    "MATERIAL DE EXPEDIENTE",
    "OBRAS/MELHORIAS", "IMPOSTOS E TAXAS",
], key=lambda x: -len(x))

# Subcategorias conhecidas
KNOWN_SUBCATS = {
    "Encargos Sociais", "Outras Despesas / Beneficios", "13º Salário", "Férias",
    "Recrutamento e Selecao", "Uniformes",
    "Elevadores", "Portões/Portas", "Portões", "Bombas", "Gerador", "Grupo Gerador",
    "Hidráulico-Mat. e/ou Serviços", "Material de Limpeza", "Aquisições",
    "Sala de Ginastica", "Material para Construção", "Limp.CxD´agua/Canos/Dedetizaçã",
    "Desp. Extras Realizadas", "Consertos/Reparos Gerais",
    "Jardim", "Jardins", "Sistema de Segurança", "Tratamento Agua",
    "Purificador de Agua", "Ar Condicionado", "Lago",
    "Energia Eletrica", "Agua / Esgoto", "Telefone Fixo", "Telefonia Móvel",
    "Internet", "Consumo de Gas",
    "DCTF Web", "Impostos/Taxas",
    "Material de Escritório",
    "Correio", "Assembleias/Reuniões", "Café da Manhã Funcionarios",
    "Locação de Caçambas", "Certidões", "Material para Condomínio",
    "Taxa Administração Condominio", "Repasse Custas / Honorários",
    "Seguro Geral", "Seguro Proteção",
    "Obras/Melhorias", "Tarifa de Cobrança",
}

SECTION_NAMES = {"ORDINARIA", "OBRAS/MELHORIAS", "IMPOSTOS/TAXAS", "FUNDO DE RESERVA"}

# Regex patterns
RE_LANCTO_FULL = re.compile(
    r"^(\d{7,})\s+(\d{2}/\d{2}/\d{4})\s+(.+?)\s{2,}([\d.,]+)(?:\s+([\d.,]+)\s+([\d.,]+%))?$"
)
RE_LANCTO_SHORT = re.compile(
    r"^(\d{7,})\s+(\d{2}/\d{2}/\d{4})\s+([\d.,]+)(?:\s+([\d.,]+)\s+([\d.,]+%))?$"
)
RE_PAGTO = re.compile(r"^(PAGTO FUNCIONARIOS\s+REF\s+\w+)\s+([\d.,]+)\s+([\d.,]+)\s+([\d.,]+%)")
RE_SALARIO = re.compile(r"^(13° SALARIO.*?)\s{2,}([\d.,]+)\s+([\d.,]+)\s+([\d.,]+%)")
RE_TARIFA = re.compile(r"^Tarifa de Cobrança\s+([\d.,]+)\s+([\d.,]+)\s+([\d.,]+%)")
RE_TOTAL_CONTA = re.compile(r"^TOTAL DA CONTA\s+")
RE_TOTAL_DESPESAS = re.compile(r"^TOTAL DAS DESPESAS")


def _coletar_continuacao(lines: List[str], start_idx: int) -> str:
    """Coleta linhas de continuação de descrição após um lançamento."""
    extra = ""
    for j in range(start_idx, len(lines)):
        nxt = lines[j].strip()
        if not nxt or nxt == "--- PAGE BREAK ---":
            continue
        # Parar em linhas que indicam novo contexto
        if re.match(r"^\d{7,}", nxt):
            break
        if nxt.startswith("TOTAL") or nxt.startswith("Nº lancto") or nxt.startswith("Gerente"):
            break
        if nxt.startswith("Emitido") or nxt.startswith("Página") or nxt.startswith("Período"):
            break
        if nxt.startswith("Condomínio") or nxt.startswith("Endereço"):
            break
        if nxt == "Demonstrativo de Despesas" or nxt.startswith("PAGTO FUNCIONARIOS"):
            break
        if nxt in KNOWN_SUBCATS:
            break
        if nxt in SECTION_NAMES:
            break
        if any(nxt == c for c in CONTA_NAMES):
            break
        # Parar em linhas de valor isolado (ex: "678,73  0,22%")
        if re.match(r"^[\d.,]+\s+[\d.,]+%$", nxt):
            break
        extra += " " + nxt
    return extra.strip()


def parse_despesas(raw_text: str) -> dict:
    """Extrai lançamentos de despesas do texto estruturado do PDF."""
    expenses = []
    periodo = ""
    condominio = ""

    m = re.search(r"Período:\s+([\d/]+ a [\d/]+)", raw_text)
    if m:
        periodo = m.group(1)

    # Cabeçalho do PDF usa "Condominio:" (sem acento).
    # Padrão: "Condominio: 1219 - CONDOMINIO ED RES FAMILIA CASABLANCA"
    # Evitar casar com lançamentos tipo "Honorários - Condomínio: 987 - Bloco: ..."
    m = re.search(r"^Condominio:\s+(.+)", raw_text, re.MULTILINE)
    if m:
        condominio = m.group(1).strip()

    lines = raw_text.split("\n")
    in_despesas = False
    current_section = ""
    current_conta = ""
    current_subcat = ""

    for i, raw_line in enumerate(lines):
        line = raw_line.strip()
        if not line or line == "--- PAGE BREAK ---":
            continue

        # Detectar início da seção de Despesas
        if line == "Demonstrativo de Despesas":
            for j in range(i + 1, min(i + 4, len(lines))):
                if lines[j].strip().startswith("Página:"):
                    in_despesas = True
                    break
            continue

        if line == "Demonstrativo de Contas":
            in_despesas = False
            continue

        if not in_despesas:
            continue

        # Ignorar cabeçalhos
        skip_prefixes = ("Página:", "Período:", "Condomínio:", "Endereço:",
                         "Gerente:", "Emitido em", "Nº lancto.")
        if any(line.startswith(p) for p in skip_prefixes):
            continue

        # Ignorar linhas de total
        if RE_TOTAL_CONTA.match(line) or RE_TOTAL_DESPESAS.match(line):
            continue

        # --- Detecção de Seção ---
        if line == "ORDINARIA":
            current_section = "ORDINARIA"
            current_conta = ""
            current_subcat = ""
            continue

        if line == "OBRAS/MELHORIAS":
            if current_section == "OBRAS/MELHORIAS":
                current_conta = "OBRAS/MELHORIAS"
            else:
                current_section = "OBRAS/MELHORIAS"
                current_conta = ""
            current_subcat = ""
            continue

        if line == "IMPOSTOS/TAXAS":
            if current_section == "ORDINARIA":
                current_conta = "IMPOSTOS/TAXAS"
            elif current_section == "IMPOSTOS/TAXAS":
                current_conta = "IMPOSTOS/TAXAS"
            else:
                current_section = "IMPOSTOS/TAXAS"
                current_conta = ""
            current_subcat = ""
            continue

        # --- Detecção de Conta ---
        found_conta = False
        for conta in CONTA_NAMES:
            if line == conta and conta not in ("ORDINARIA", "OBRAS/MELHORIAS", "IMPOSTOS/TAXAS"):
                current_conta = conta
                current_subcat = ""
                found_conta = True
                break
        if found_conta:
            continue

        # --- PAGTO FUNCIONARIOS ---
        m = RE_PAGTO.match(line)
        if m:
            current_subcat = m.group(1).strip()
            expenses.append({
                "secao": current_section, "conta": current_conta,
                "subcategoria": current_subcat, "lancto": "", "data": "",
                "valor": parse_numero_br(m.group(2)),
                "totalSubcat": parse_numero_br(m.group(3)),
                "percentual": m.group(4), "descricao": m.group(1).strip()
            })
            continue

        # --- 13° SALARIO ---
        m = RE_SALARIO.match(line)
        if m:
            current_subcat = "13º Salário"
            expenses.append({
                "secao": current_section, "conta": current_conta,
                "subcategoria": current_subcat, "lancto": "", "data": "",
                "valor": parse_numero_br(m.group(2)),
                "totalSubcat": parse_numero_br(m.group(3)),
                "percentual": m.group(4), "descricao": m.group(1).strip()
            })
            continue

        # --- Tarifa de Cobrança ---
        m = RE_TARIFA.match(line)
        if m:
            current_subcat = "Tarifa de Cobrança"
            expenses.append({
                "secao": current_section, "conta": current_conta,
                "subcategoria": current_subcat, "lancto": "", "data": "",
                "valor": parse_numero_br(m.group(1)),
                "totalSubcat": parse_numero_br(m.group(2)),
                "percentual": m.group(3), "descricao": "Tarifa de Cobrança"
            })
            continue

        # --- Lançamento com descrição ---
        m = RE_LANCTO_FULL.match(line)
        if m:
            desc = m.group(3).strip()
            cont = _coletar_continuacao(lines, i + 1)
            if cont:
                desc += " " + cont
            expenses.append({
                "secao": current_section, "conta": current_conta,
                "subcategoria": current_subcat,
                "lancto": m.group(1), "data": m.group(2),
                "valor": parse_numero_br(m.group(4)),
                "totalSubcat": parse_numero_br(m.group(5)) if m.group(5) else None,
                "percentual": m.group(6) or "", "descricao": desc
            })
            continue

        # --- Lançamento sem descrição (valor + data apenas) ---
        m = RE_LANCTO_SHORT.match(line)
        if m:
            desc = ""
            for j in range(i - 1, max(0, i - 4), -1):
                prev = lines[j].strip()
                if not prev or prev == "--- PAGE BREAK ---":
                    continue
                if re.match(r"^\d{7}", prev) or prev.startswith("TOTAL"):
                    continue
                if prev.startswith("Nº lancto") or prev.startswith("Gerente"):
                    continue
                if prev.startswith("Emitido") or prev.startswith("Página"):
                    continue
                desc = prev
                break
            cont = _coletar_continuacao(lines, i + 1)
            if cont:
                desc += " " + cont
            expenses.append({
                "secao": current_section, "conta": current_conta,
                "subcategoria": current_subcat,
                "lancto": m.group(1), "data": m.group(2),
                "valor": parse_numero_br(m.group(3)),
                "totalSubcat": parse_numero_br(m.group(4)) if m.group(4) else None,
                "percentual": m.group(5) or "", "descricao": desc
            })
            continue

        # --- Detecção de subcategoria ---
        for subcat in KNOWN_SUBCATS:
            if line == subcat or line.startswith(subcat + " "):
                current_subcat = subcat
                break

    return {"periodo": periodo, "condominio": condominio, "expenses": expenses}


# =============================================================================
# PARSING DE RESUMO FINANCEIRO
# =============================================================================

def parse_resumo(raw_text: str) -> List[dict]:
    """Extrai o Resumo Financeiro Contábil do texto."""
    resumo = []
    in_resumo = False

    for line in raw_text.split("\n"):
        trimmed = line.strip()
        if not trimmed:
            continue

        if "Resumo Financeiro Contábil" in trimmed or \
           trimmed == "Saldo anterior  Créditos  Débitos  Saldo atual":
            in_resumo = True
            continue

        if in_resumo:
            m = re.match(
                r"^([\w/\s]+?)\s{2,}(-?[\d.,]+)\s+([\d.,]+)\s+([\d.,]+)\s+(-?[\d.,]+)", trimmed
            )
            if m and m.group(1).strip() != "TOTAL":
                resumo.append({
                    "categoria": m.group(1).strip(),
                    "saldoAnterior": parse_numero_br(m.group(2)),
                    "creditos": parse_numero_br(m.group(3)),
                    "debitos": parse_numero_br(m.group(4)),
                    "saldoAtual": parse_numero_br(m.group(5)),
                })
            if trimmed.startswith("TOTAL"):
                in_resumo = False

    return resumo


# =============================================================================
# PARSING DE TOTAIS POR CONTA
# =============================================================================

def parse_totais_contas(raw_text: str) -> dict:
    """Extrai totais por conta (TOTAL DA CONTA ...) do texto."""
    totais = {}
    lines = raw_text.split("\n")
    current_section = ""
    in_despesas = False

    for i, raw_line in enumerate(lines):
        trimmed = raw_line.strip()

        if trimmed == "Demonstrativo de Despesas":
            in_despesas = True
        if trimmed == "Demonstrativo de Contas":
            in_despesas = False
        if not in_despesas:
            continue

        if trimmed == "ORDINARIA":
            current_section = "ORDINARIA"
        elif trimmed == "OBRAS/MELHORIAS" and current_section != "OBRAS/MELHORIAS":
            if current_section in ("ORDINARIA", ""):
                current_section = "OBRAS/MELHORIAS"
        elif trimmed == "IMPOSTOS/TAXAS" and current_section != "ORDINARIA":
            current_section = "IMPOSTOS/TAXAS"

        # Valor na mesma linha
        m = re.match(r"^TOTAL DA CONTA\s+(.+?)\s{2,}([\d.,]+)\s+([\d.,]+%)", trimmed)
        if m:
            conta_name = m.group(1).strip()
            is_high = conta_name in ("ORDINARIA", "OBRAS/MELHORIAS", "IMPOSTOS/TAXAS")
            section = conta_name if is_high else current_section
            key = conta_name if is_high else f"{section} > {conta_name}"
            totais[key] = {
                "secao": section, "conta": conta_name,
                "total": parse_numero_br(m.group(2)),
                "percentual": m.group(3), "isHighLevel": is_high,
            }
            continue

        # Valor na próxima linha
        m = re.match(r"^TOTAL DA CONTA\s+(.+)$", trimmed)
        if m and i + 1 < len(lines):
            next_line = lines[i + 1].strip()
            vm = re.match(r"^([\d.,]+)\s+([\d.,]+%)$", next_line)
            if vm:
                conta_name = m.group(1).strip()
                is_high = conta_name in ("ORDINARIA", "OBRAS/MELHORIAS", "IMPOSTOS/TAXAS")
                section = conta_name if is_high else current_section
                key = conta_name if is_high else f"{section} > {conta_name}"
                totais[key] = {
                    "secao": section, "conta": conta_name,
                    "total": parse_numero_br(vm.group(1)),
                    "percentual": vm.group(2), "isHighLevel": is_high,
                }

    return totais


# =============================================================================
# ANÁLISE DE DIFERENÇAS
# =============================================================================

def _build_conta_subcat_map(expenses: List[dict]) -> dict:
    """Mapa: 'SECAO > CONTA' -> {'SUBCATEGORIA': [lancamentos]}"""
    m = {}
    for e in expenses:
        ck = f"{e['secao']} > {e['conta']}"
        if ck not in m:
            m[ck] = {}
        sc = e["subcategoria"]
        if sc not in m[ck]:
            m[ck][sc] = []
        m[ck][sc].append(e)
    return m


def _fmt_brl(v: float) -> str:
    """Formata float para R$ brasileiro."""
    if v is None:
        return "R$ 0,00"
    return f"R$ {v:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def analisar_diferencas(old_expenses, new_expenses, old_totais, new_totais) -> List[dict]:
    """Analisa diferenças entre dois períodos."""
    diffs = []
    old_map = _build_conta_subcat_map(old_expenses)
    new_map = _build_conta_subcat_map(new_expenses)

    all_total_keys = sorted(set(list(old_totais.keys()) + list(new_totais.keys())))
    all_conta_keys = sorted(set(list(old_map.keys()) + list(new_map.keys())))

    # 1. Contas novas/removidas
    for key in all_total_keys:
        old = old_totais.get(key)
        new = new_totais.get(key)
        if old and new:
            continue

        if not old and new and not new.get("isHighLevel"):
            conta_key = f"{new['secao']} > {new['conta']}"
            lancamentos = new_map.get(conta_key, {})
            all_lanc = []
            for subcat, items in lancamentos.items():
                for it in items:
                    all_lanc.append(
                        f"  - {subcat}: {it.get('descricao', '(sem descrição)')} | {_fmt_brl(it.get('valor', 0))}"
                    )
            diffs.append({
                "tipo": "CONTA NOVA", "secao": new["secao"], "conta": new["conta"],
                "subcategoria": "",
                "totalAnterior": 0, "totalAtual": new["total"],
                "detalhes": f'Conta "{new["conta"]}" não existia no mês anterior. Total: {_fmt_brl(new["total"])}',
                "lancamentos": all_lanc,
            })

        if old and not new and not old.get("isHighLevel"):
            conta_key = f"{old['secao']} > {old['conta']}"
            lancamentos = old_map.get(conta_key, {})
            all_lanc = []
            for subcat, items in lancamentos.items():
                for it in items:
                    all_lanc.append(
                        f"  - {subcat}: {it.get('descricao', '(sem descrição)')} | {_fmt_brl(it.get('valor', 0))}"
                    )
            diffs.append({
                "tipo": "CONTA AUSENTE", "secao": old["secao"], "conta": old["conta"],
                "subcategoria": "",
                "totalAnterior": old["total"], "totalAtual": 0,
                "detalhes": f'Conta "{old["conta"]}" existia no mês anterior mas não aparece no mês atual. Total anterior: {_fmt_brl(old["total"])}',
                "lancamentos": all_lanc,
            })

    # 2. Subcategorias novas/removidas dentro de contas que existem em ambos
    for conta_key in all_conta_keys:
        old_subcats = old_map.get(conta_key, {})
        new_subcats = new_map.get(conta_key, {})
        all_subcats = sorted(set(list(old_subcats.keys()) + list(new_subcats.keys())))

        for subcat in all_subcats:
            old_items = old_subcats.get(subcat, [])
            new_items = new_subcats.get(subcat, [])

            if old_items and not new_items:
                total_old = sum(it.get("valor", 0) or 0 for it in old_items)
                lanc_desc = [
                    f"  - {it.get('descricao', '(sem descrição)')} | {it.get('data', '')} | {_fmt_brl(it.get('valor', 0))}"
                    for it in old_items
                ]
                diffs.append({
                    "tipo": "SUBCATEGORIA REMOVIDA",
                    "secao": old_items[0]["secao"], "conta": old_items[0]["conta"],
                    "subcategoria": subcat,
                    "totalAnterior": total_old, "totalAtual": 0,
                    "detalhes": f'Subcategoria "{subcat}" em {conta_key} existia no mês anterior mas não no atual',
                    "lancamentos": lanc_desc,
                })

            if not old_items and new_items:
                total_new = sum(it.get("valor", 0) or 0 for it in new_items)
                lanc_desc = [
                    f"  - {it.get('descricao', '(sem descrição)')} | {it.get('data', '')} | {_fmt_brl(it.get('valor', 0))}"
                    for it in new_items
                ]
                diffs.append({
                    "tipo": "SUBCATEGORIA NOVA",
                    "secao": new_items[0]["secao"], "conta": new_items[0]["conta"],
                    "subcategoria": subcat,
                    "totalAnterior": 0, "totalAtual": total_new,
                    "detalhes": f'Subcategoria "{subcat}" em {conta_key} é nova no mês atual',
                    "lancamentos": lanc_desc,
                })

    return diffs


# =============================================================================
# GERAÇÃO DO XLSX
# =============================================================================

HEADER_FONT = Font(bold=True, color="FFFFFF")
HEADER_FILL_BLUE = PatternFill("solid", fgColor="4472C4")
HEADER_FILL_DARK_BLUE = PatternFill("solid", fgColor="2E75B6")
HEADER_FILL_GREEN = PatternFill("solid", fgColor="548235")
HEADER_FILL_RED = PatternFill("solid", fgColor="C00000")
FILL_YELLOW = PatternFill("solid", fgColor="FFF2CC")
FILL_PINK = PatternFill("solid", fgColor="FCE4EC")
FILL_LIGHT_GREEN = PatternFill("solid", fgColor="E2EFDA")
FILL_LIGHT_ORANGE = PatternFill("solid", fgColor="FBE5D6")
ITALIC_GRAY = Font(italic=True, color="666666")
FMT_BRL = '#,##0.00'


def _apply_header(ws, fill):
    """Aplica estilo no cabeçalho (primeira linha)."""
    for cell in ws[1]:
        cell.font = HEADER_FONT
        cell.fill = fill
        cell.alignment = Alignment(horizontal="center", wrap_text=True)


def _set_col_widths(ws, widths: List[int]):
    """Define largura das colunas."""
    for idx, w in enumerate(widths, 1):
        ws.column_dimensions[openpyxl.utils.get_column_letter(idx)].width = w


def criar_aba_despesas(wb, nome: str, data: dict):
    """Cria aba com lançamentos detalhados."""
    ws = wb.create_sheet(nome)
    headers = ["Seção", "Conta", "Subcategoria", "Nº Lançamento", "Data",
               "Valor (R$)", "Total Subcat", "Percentual", "Descrição"]
    ws.append(headers)
    _apply_header(ws, HEADER_FILL_BLUE)
    _set_col_widths(ws, [18, 28, 32, 14, 12, 14, 14, 10, 90])

    for e in data["expenses"]:
        ws.append([
            e["secao"], e["conta"], e["subcategoria"], e["lancto"], e["data"],
            e["valor"], e.get("totalSubcat"), e["percentual"], e["descricao"]
        ])

    # Formatar colunas de valor
    for row in ws.iter_rows(min_row=2, min_col=6, max_col=7):
        for cell in row:
            if isinstance(cell.value, (int, float)):
                cell.number_format = FMT_BRL

    ws.auto_filter.ref = ws.dimensions


def criar_aba_resumo(wb, old_resumo, new_resumo):
    """Cria aba com resumo financeiro comparativo."""
    ws = wb.create_sheet("Resumo Comparativo")
    headers = ["Categoria",
               "Saldo Ant. (Anterior)", "Créditos (Anterior)", "Débitos (Anterior)", "Saldo Atual (Anterior)",
               "",
               "Saldo Ant. (Atual)", "Créditos (Atual)", "Débitos (Atual)", "Saldo Atual (Atual)",
               "",
               "Var. Débitos (R$)", "Var %"]
    ws.append(headers)
    _apply_header(ws, HEADER_FILL_DARK_BLUE)
    _set_col_widths(ws, [25, 20, 18, 18, 20, 2, 20, 18, 18, 20, 2, 18, 10])

    all_cats = list(dict.fromkeys(
        [r["categoria"] for r in old_resumo] + [r["categoria"] for r in new_resumo]
    ))

    for cat in all_cats:
        old = next((r for r in old_resumo if r["categoria"] == cat), {})
        nw = next((r for r in new_resumo if r["categoria"] == cat), {})
        var_deb = (nw.get("debitos", 0) or 0) - (old.get("debitos", 0) or 0)
        var_pct = f"{(var_deb / old['debitos'] * 100):.1f}%" if old.get("debitos") else "N/A"

        ws.append([
            cat,
            old.get("saldoAnterior", 0), old.get("creditos", 0),
            old.get("debitos", 0), old.get("saldoAtual", 0),
            "",
            nw.get("saldoAnterior", 0), nw.get("creditos", 0),
            nw.get("debitos", 0), nw.get("saldoAtual", 0),
            "",
            var_deb, var_pct,
        ])

    # Formatar colunas numéricas
    for row in ws.iter_rows(min_row=2):
        for cell in row:
            if isinstance(cell.value, (int, float)) and cell.column != 6 and cell.column != 11:
                cell.number_format = FMT_BRL


def criar_aba_totais(wb, old_totais, new_totais, old_expenses, new_expenses):
    """Cria aba de totais por conta com detalhamento de contas novas/ausentes."""
    ws = wb.create_sheet("Totais por Conta")
    headers = ["Seção", "Conta", "Total Anterior (R$)", "% Anterior",
               "Total Atual (R$)", "% Atual", "Diferença (R$)", "Var %", "Observação"]
    ws.append(headers)
    _apply_header(ws, HEADER_FILL_GREEN)
    _set_col_widths(ws, [18, 30, 18, 10, 18, 10, 16, 10, 50])

    old_map = _build_conta_subcat_map(old_expenses)
    new_map = _build_conta_subcat_map(new_expenses)

    all_keys = sorted(set(list(old_totais.keys()) + list(new_totais.keys())))

    for key in all_keys:
        old = old_totais.get(key, {})
        nw = new_totais.get(key, {})
        if old.get("isHighLevel") or nw.get("isHighLevel"):
            continue

        old_total = old.get("total", 0) or 0
        new_total = nw.get("total", 0) or 0
        diff = new_total - old_total
        var_pct = f"{(diff / old_total * 100):.1f}%" if old_total else "NOVA"

        obs = ""
        if not old.get("total") and nw.get("total"):
            obs = "Conta NOVA no mês atual"
        if old.get("total") and not nw.get("total"):
            obs = "Conta AUSENTE no mês atual"
        if abs(diff) > 5000 and old.get("total") and nw.get("total"):
            obs += (" | " if obs else "") + "Variação significativa"

        row_data = [
            nw.get("secao") or old.get("secao", ""),
            nw.get("conta") or old.get("conta", ""),
            old_total, old.get("percentual", ""),
            new_total, nw.get("percentual", ""),
            diff, var_pct, obs,
        ]
        ws.append(row_data)
        row_idx = ws.max_row

        if "NOVA" in obs:
            for cell in ws[row_idx]:
                cell.fill = FILL_YELLOW
        if "AUSENTE" in obs:
            for cell in ws[row_idx]:
                cell.fill = FILL_PINK

        # Detalhar lançamentos em contas novas/ausentes
        if "NOVA" in obs or "AUSENTE" in obs:
            conta_name = nw.get("conta") or old.get("conta", "")
            secao = nw.get("secao") or old.get("secao", "")
            conta_key = f"{secao} > {conta_name}"
            source_map = new_map if "NOVA" in obs else old_map
            lancamentos = source_map.get(conta_key, {})

            for subcat, items in lancamentos.items():
                for item in items:
                    val_col = 5 if "NOVA" in obs else 3  # Total Atual vs Total Anterior
                    detail_row = [
                        "", "", None, "", None, "", None, "",
                        f"  ↳ {subcat}: {item.get('descricao', '(sem descrição)')}"
                    ]
                    if "NOVA" in obs:
                        detail_row[4] = item.get("valor")
                    else:
                        detail_row[2] = item.get("valor")
                    ws.append(detail_row)
                    for cell in ws[ws.max_row]:
                        cell.font = ITALIC_GRAY

    # Formatar colunas numéricas
    for col_idx in (3, 5, 7):
        for row in ws.iter_rows(min_row=2, min_col=col_idx, max_col=col_idx):
            for cell in row:
                if isinstance(cell.value, (int, float)):
                    cell.number_format = FMT_BRL


def criar_aba_diferencas(wb, diffs: List[dict]):
    """Cria aba com todas as diferenças detalhadas."""
    ws = wb.create_sheet("Diferenças Detalhadas")
    headers = ["Tipo", "Seção", "Conta", "Subcategoria",
               "Total Anterior (R$)", "Total Atual (R$)", "Descrição"]
    ws.append(headers)
    _apply_header(ws, HEADER_FILL_RED)
    _set_col_widths(ws, [22, 18, 28, 30, 18, 18, 70])

    color_map = {
        "CONTA NOVA": FILL_YELLOW,
        "CONTA AUSENTE": FILL_PINK,
        "SUBCATEGORIA NOVA": FILL_LIGHT_GREEN,
        "SUBCATEGORIA REMOVIDA": FILL_LIGHT_ORANGE,
    }

    for d in diffs:
        ws.append([
            d["tipo"], d["secao"], d["conta"], d.get("subcategoria", ""),
            d.get("totalAnterior", 0), d.get("totalAtual", 0), d["detalhes"],
        ])
        fill = color_map.get(d["tipo"])
        if fill:
            for cell in ws[ws.max_row]:
                cell.fill = fill

        for lanc in d.get("lancamentos", []):
            ws.append(["", "", "", "", None, None, lanc])
            for cell in ws[ws.max_row]:
                cell.font = ITALIC_GRAY

    for col_idx in (5, 6):
        for row in ws.iter_rows(min_row=2, min_col=col_idx, max_col=col_idx):
            for cell in row:
                if isinstance(cell.value, (int, float)):
                    cell.number_format = FMT_BRL

    ws.auto_filter.ref = ws.dimensions


def gerar_xlsx(old_data, new_data, old_resumo, new_resumo,
               old_totais, new_totais, diffs, output_path: str):
    """Gera o XLSX completo com todas as abas."""
    wb = openpyxl.Workbook()
    # Remover aba padrão
    wb.remove(wb.active)

    criar_aba_despesas(wb, "Mês Anterior", old_data)
    criar_aba_despesas(wb, "Mês Atual", new_data)
    criar_aba_resumo(wb, old_resumo, new_resumo)
    criar_aba_totais(wb, old_totais, new_totais, old_data["expenses"], new_data["expenses"])
    criar_aba_diferencas(wb, diffs)

    wb.save(output_path)


# =============================================================================
# MAIN
# =============================================================================

def _build_totais_comparativo(old_totais, new_totais) -> List[dict]:
    """Gera lista de totais comparativos para JSON export."""
    result = []
    all_keys = sorted(set(list(old_totais.keys()) + list(new_totais.keys())))
    for key in all_keys:
        old = old_totais.get(key, {})
        nw = new_totais.get(key, {})
        if old.get("isHighLevel") or nw.get("isHighLevel"):
            continue
        old_total = old.get("total", 0) or 0
        new_total = nw.get("total", 0) or 0
        diff = new_total - old_total
        var_pct = round(diff / old_total * 100, 1) if old_total else None
        obs = ""
        if not old.get("total") and nw.get("total"):
            obs = "NOVA"
        if old.get("total") and not nw.get("total"):
            obs = "AUSENTE"
        result.append({
            "secao": nw.get("secao") or old.get("secao", ""),
            "conta": nw.get("conta") or old.get("conta", ""),
            "totalAnterior": old_total,
            "pctAnterior": old.get("percentual", ""),
            "totalAtual": new_total,
            "pctAtual": nw.get("percentual", ""),
            "diferenca": diff,
            "varPct": var_pct,
            "obs": obs,
        })
    return result


def main():
    import json as json_mod

    ap = argparse.ArgumentParser(
        description="Compara dois PDFs de Prestação de Contas e gera XLSX comparativo."
    )
    ap.add_argument("--pdf-anterior", required=True, help="PDF do mês anterior")
    ap.add_argument("--pdf-atual", required=True, help="PDF do mês atual")
    ap.add_argument("--saida", required=True, help="Caminho do XLSX de saída")
    ap.add_argument("--json", default="", help="Caminho do JSON de saída (dados estruturados para o frontend)")

    args = ap.parse_args()

    for p, label in [(args.pdf_anterior, "PDF anterior"), (args.pdf_atual, "PDF atual")]:
        if not Path(p).is_file():
            print(f"ERRO: {label} não encontrado: {p}", file=sys.stderr)
            sys.exit(1)

    # 1. Extrair texto
    print("Extraindo texto dos PDFs...")
    text_old = extrair_texto_pdf(args.pdf_anterior)
    text_new = extrair_texto_pdf(args.pdf_atual)

    # 2. Parsear dados
    print("Parseando despesas...")
    old_data = parse_despesas(text_old)
    new_data = parse_despesas(text_new)
    old_totais = parse_totais_contas(text_old)
    new_totais = parse_totais_contas(text_new)
    old_resumo = parse_resumo(text_old)
    new_resumo = parse_resumo(text_new)

    condominio = new_data["condominio"] or old_data["condominio"] or "(não identificado)"
    print(f"Condomínio: {condominio}")
    print(f"Mês Anterior ({old_data['periodo']}): {len(old_data['expenses'])} lançamentos")
    print(f"Mês Atual    ({new_data['periodo']}): {len(new_data['expenses'])} lançamentos")

    # 3. Analisar diferenças
    diffs = analisar_diferencas(
        old_data["expenses"], new_data["expenses"], old_totais, new_totais
    )
    print(f"\nDiferenças encontradas: {len(diffs)}")
    for d in diffs:
        sub = f" > {d['subcategoria']}" if d.get("subcategoria") else ""
        print(f"  [{d['tipo']}] {d['conta']}{sub}")

    # 4. Gerar XLSX
    gerar_xlsx(old_data, new_data, old_resumo, new_resumo,
               old_totais, new_totais, diffs, args.saida)
    print(f"\nXLSX salvo em: {args.saida}")

    # 5. Gerar JSON para o frontend
    json_path = args.json if args.json else args.saida.replace(".xlsx", ".json")
    totais_comp = _build_totais_comparativo(old_totais, new_totais)

    json_data = {
        "condominio": condominio,
        "periodoAnterior": old_data["periodo"],
        "periodoAtual": new_data["periodo"],
        "lanctosAnterior": len(old_data["expenses"]),
        "lanctosAtual": len(new_data["expenses"]),
        "resumo": {
            "anterior": old_resumo,
            "atual": new_resumo,
        },
        "totaisComparativo": totais_comp,
        "diferencas": diffs,
    }

    with open(json_path, "w", encoding="utf-8") as f:
        json_mod.dump(json_data, f, ensure_ascii=False, indent=2)
    print(f"JSON salvo em: {json_path}")

    # 6. Output summary para o PHP consumir
    print(f"\n__RESULTADO__")
    print(f"condominio={condominio}")
    print(f"periodo_anterior={old_data['periodo']}")
    print(f"periodo_atual={new_data['periodo']}")
    print(f"lanctos_anterior={len(old_data['expenses'])}")
    print(f"lanctos_atual={len(new_data['expenses'])}")
    print(f"diferencas={len(diffs)}")
    print(f"json_path={json_path}")


if __name__ == "__main__":
    main()
