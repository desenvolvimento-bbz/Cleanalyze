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

DEFAULT_SECTION_NAMES = {"ORDINARIA", "OBRAS/MELHORIAS", "IMPOSTOS/TAXAS", "FUNDO DE RESERVA"}


def extrair_secoes_do_resumo(raw_text: str) -> set:
    """Extrai nomes de seção do Resumo Financeiro Contábil (antes do TOTAL).

    Cada linha do resumo tem formato: NOME_SECAO  valor valor valor valor
    Isso nos dá as seções reais do condomínio, independente de quais sejam.
    """
    secoes = set()
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
                r"^([\w/\s\u00C0-\u024F]+?)\s{2,}(-?[\d.,]+)\s+([\d.,]+)\s+([\d.,]+)\s+(-?[\d.,]+)",
                trimmed
            )
            if m and m.group(1).strip() != "TOTAL":
                secoes.add(m.group(1).strip())
            if trimmed.startswith("TOTAL"):
                in_resumo = False
                break  # só precisamos do primeiro resumo

    return secoes if secoes else DEFAULT_SECTION_NAMES

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


def _coletar_continuacao(lines: List[str], start_idx: int, all_section_names: set = None) -> str:
    """Coleta linhas de continuação de descrição após um lançamento."""
    stop_names = all_section_names or DEFAULT_SECTION_NAMES
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
        if nxt.startswith("Condomínio") or nxt.startswith("Condominio") or nxt.startswith("Endereço"):
            break
        if nxt == "Demonstrativo de Despesas" or nxt.startswith("PAGTO FUNCIONARIOS"):
            break
        if nxt in KNOWN_SUBCATS:
            break
        if nxt in stop_names or nxt.upper() in {s.upper() for s in stop_names}:
            break
        if any(nxt == c for c in CONTA_NAMES):
            break
        # Parar em linhas de valor isolado (ex: "678,73  0,22%")
        if re.match(r"^[\d.,]+\s+[\d.,]+%$", nxt):
            break
        extra += " " + nxt
    return extra.strip()


def parse_despesas(raw_text: str, section_names: set = None) -> dict:
    """Extrai lançamentos de despesas do texto estruturado do PDF."""
    expenses = []
    periodo = ""
    condominio = ""

    if section_names is None:
        section_names = DEFAULT_SECTION_NAMES

    m = re.search(r"Período:\s+([\d/]+ a [\d/]+)", raw_text)
    if m:
        periodo = m.group(1)

    # Cabeçalho do PDF usa "Condominio:" (sem acento) ou "Condomínio:" (com acento).
    # Ancorar no início da linha para evitar casar com lançamentos tipo
    # "Honorários - Condomínio: 987 - Bloco: ..."
    m = re.search(r"^Condom[ií]nio:\s+(.+)", raw_text, re.MULTILINE)
    if m:
        condominio = m.group(1).strip()

    # Nomes que são tanto seção quanto conta (aparecem como conta dentro da própria seção)
    section_name_set = {s.upper() for s in section_names}

    lines = raw_text.split("\n")
    in_despesas = False
    current_section = ""
    current_conta = ""
    current_subcat = ""
    # Rastrear se já entramos numa seção (para distinguir seção vs conta com mesmo nome)
    seen_sections = set()

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
        skip_prefixes = ("Página:", "Período:", "Condomínio:", "Condominio:",
                         "Endereço:", "Gerente:", "Emitido em", "Nº lancto.")
        if any(line.startswith(p) for p in skip_prefixes):
            continue

        # Ignorar linhas de total
        if RE_TOTAL_CONTA.match(line) or RE_TOTAL_DESPESAS.match(line):
            continue

        # --- Detecção de Seção (hardcoded para nomes ambíguos) ---
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

        # --- Detecção dinâmica de Seção (somente nomes que NÃO são contas) ---
        line_upper = line.upper()
        conta_name_set = {c.upper() for c in CONTA_NAMES}
        is_section_only = (line_upper in section_name_set or line in section_names) and \
                          line_upper not in conta_name_set and \
                          line not in CONTA_NAMES
        if is_section_only:
            if line_upper not in seen_sections:
                seen_sections.add(line_upper)
                current_section = line
                current_conta = ""
                current_subcat = ""
                continue
            else:
                current_conta = line
                current_subcat = ""
                continue

        # --- Detecção de Conta ---
        found_conta = False
        for conta in CONTA_NAMES:
            if line == conta:
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
            cont = _coletar_continuacao(lines, i + 1, section_names)
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
            cont = _coletar_continuacao(lines, i + 1, section_names)
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

def parse_totais_contas(raw_text: str, section_names: set = None) -> dict:
    """Extrai totais por conta (TOTAL DA CONTA ...) do texto."""
    if section_names is None:
        section_names = DEFAULT_SECTION_NAMES

    section_name_set = {s.upper() for s in section_names}
    conta_name_set = {c.upper() for c in CONTA_NAMES}
    totais = {}
    lines = raw_text.split("\n")
    current_section = ""
    in_despesas = False
    seen_sections = set()

    for i, raw_line in enumerate(lines):
        trimmed = raw_line.strip()

        if trimmed == "Demonstrativo de Despesas":
            in_despesas = True
        if trimmed == "Demonstrativo de Contas":
            in_despesas = False
        if not in_despesas:
            continue

        # Detecção de seção: hardcoded para 3 nomes ambíguos
        trimmed_upper = trimmed.upper()
        if trimmed == "ORDINARIA":
            current_section = "ORDINARIA"
        elif trimmed == "OBRAS/MELHORIAS" and current_section != "OBRAS/MELHORIAS":
            current_section = "OBRAS/MELHORIAS"
        elif trimmed == "IMPOSTOS/TAXAS" and current_section not in ("ORDINARIA",):
            current_section = "IMPOSTOS/TAXAS"
        elif (trimmed_upper in section_name_set) and \
             (trimmed_upper not in conta_name_set) and \
             (trimmed not in CONTA_NAMES):
            if trimmed_upper not in seen_sections:
                seen_sections.add(trimmed_upper)
                current_section = trimmed

        # Valor na mesma linha
        m = re.match(r"^TOTAL DA CONTA\s+(.+?)\s{2,}([\d.,]+)\s+([\d.,]+%)", trimmed)
        if m:
            conta_name = m.group(1).strip()
            is_high = conta_name.upper() in section_name_set
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
                is_high = conta_name.upper() in section_name_set
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

        if not old and new and not new.get("isHighLevel") and new.get("conta", "").strip():
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

        if old and not new and not old.get("isHighLevel") and old.get("conta", "").strip():
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
            if not subcat.strip():
                continue  # Ignorar subcategorias sem nome
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
# v2: NOVA=vermelho (perigo), AUSENTE=amarelo (atenção)
FILL_NOVA = PatternFill("solid", fgColor="FCE4EC")          # Rosa/vermelho — conta/subcat nova
FILL_AUSENTE = PatternFill("solid", fgColor="FFF2CC")       # Amarelo — conta/subcat ausente
FILL_SUB_NOVA = PatternFill("solid", fgColor="FCE4EC")      # Rosa/vermelho — subcategoria nova
FILL_SUB_REMOVIDA = PatternFill("solid", fgColor="FFF2CC")  # Amarelo — subcategoria removida
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
                cell.fill = FILL_NOVA
        if "AUSENTE" in obs:
            for cell in ws[row_idx]:
                cell.fill = FILL_AUSENTE

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
        "CONTA NOVA": FILL_NOVA,
        "CONTA AUSENTE": FILL_AUSENTE,
        "SUBCATEGORIA NOVA": FILL_SUB_NOVA,
        "SUBCATEGORIA REMOVIDA": FILL_SUB_REMOVIDA,
    }

    # Ordenar: NOVA primeiro, AUSENTE depois
    tipo_ordem = {
        "CONTA NOVA": 0, "SUBCATEGORIA NOVA": 1,
        "CONTA AUSENTE": 2, "SUBCATEGORIA REMOVIDA": 3,
    }
    diffs_sorted = sorted(diffs, key=lambda d: tipo_ordem.get(d["tipo"], 9))

    for d in diffs_sorted:
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


def _desc_label(descricao: str) -> str:
    """Extrai label curto da descrição: parte antes do primeiro ' - '."""
    if not descricao:
        return ""
    # Ignorar transferências internas
    if "TRANSFERÊNCIA ENTRE CONTAS" in descricao.upper() or \
       "TRANSFERENCIA ENTRE CONTAS" in descricao.upper():
        return ""
    # Pegar parte antes do primeiro " - " (nome do fornecedor geralmente vem depois)
    parts = descricao.split(" - ", 1)
    label = parts[0].strip()
    # Limitar tamanho
    if len(label) > 60:
        label = label[:57] + "..."
    return label


def _build_conta_subcat_map_with_desc(expenses: List[dict]) -> dict:
    """Mapa: 'SECAO > CONTA' -> {'SUBCATEGORIA_OU_DESC': [lancamentos]}

    Usa descrição como fallback quando subcategoria é vazia.
    """
    m = {}
    for e in expenses:
        conta = e.get("conta", "").strip()
        if not conta:
            continue  # Ignorar expenses sem conta definida
        ck = f"{e['secao']} > {conta}"
        if ck not in m:
            m[ck] = {}
        sc = e["subcategoria"].strip()
        if not sc:
            # Fallback: usar label da descrição
            sc = _desc_label(e.get("descricao", ""))
        if not sc:
            continue
        if sc not in m[ck]:
            m[ck][sc] = []
        m[ck][sc].append(e)
    return m


def _build_subcategorias_por_conta(old_expenses, new_expenses) -> dict:
    """Mapa de subcategorias por conta com totais anterior/atual para dropdown.

    Usa descrição como fallback para subcategorias vazias.
    """
    old_map = _build_conta_subcat_map_with_desc(old_expenses)
    new_map = _build_conta_subcat_map_with_desc(new_expenses)
    all_keys = sorted(set(list(old_map.keys()) + list(new_map.keys())))

    result = {}
    for conta_key in all_keys:
        old_subcats = old_map.get(conta_key, {})
        new_subcats = new_map.get(conta_key, {})
        all_subcats = sorted(set(list(old_subcats.keys()) + list(new_subcats.keys())))

        subcats = []
        for sc in all_subcats:
            if not sc.strip():
                continue
            old_items = old_subcats.get(sc, [])
            new_items = new_subcats.get(sc, [])
            total_ant = sum(it.get("valor", 0) or 0 for it in old_items)
            total_atu = sum(it.get("valor", 0) or 0 for it in new_items)

            status = ""
            if not old_items and new_items:
                status = "NOVA"
            elif old_items and not new_items:
                status = "AUSENTE"

            subcats.append({
                "subcategoria": sc,
                "totalAnterior": total_ant,
                "totalAtual": total_atu,
                "qtdAnterior": len(old_items),
                "qtdAtual": len(new_items),
                "status": status,
            })
        if subcats:
            result[conta_key] = subcats
    return result


def _build_fundo_reserva_info(old_resumo, new_resumo, old_expenses, new_expenses) -> dict:
    """Detecta lançamentos no Fundo de Reserva e monta alerta."""
    info = {
        "temDebito": False,
        "debitoAnterior": 0,
        "debitoAtual": 0,
        "creditoAnterior": 0,
        "creditoAtual": 0,
        "saldoAnteriorInicio": 0,
        "saldoAnteriorFim": 0,
        "saldoAtualInicio": 0,
        "saldoAtualFim": 0,
        "lancamentos": [],
    }

    for r in old_resumo:
        if "FUNDO DE RESERVA" in r.get("categoria", "").upper():
            info["debitoAnterior"] = r.get("debitos", 0)
            info["creditoAnterior"] = r.get("creditos", 0)
            info["saldoAnteriorInicio"] = r.get("saldoAnterior", 0)
            info["saldoAnteriorFim"] = r.get("saldoAtual", 0)
    for r in new_resumo:
        if "FUNDO DE RESERVA" in r.get("categoria", "").upper():
            info["debitoAtual"] = r.get("debitos", 0)
            info["creditoAtual"] = r.get("creditos", 0)
            info["saldoAtualInicio"] = r.get("saldoAnterior", 0)
            info["saldoAtualFim"] = r.get("saldoAtual", 0)

    info["temDebitoAtual"] = info["debitoAtual"] > 0
    info["temDebitoAnterior"] = info["debitoAnterior"] > 0

    # Coletar lançamentos de despesa do Fundo de Reserva
    for e in old_expenses:
        if "FUNDO" in e.get("secao", "").upper() and "RESERVA" in e.get("secao", "").upper():
            info["lancamentos"].append({
                "periodo": "anterior",
                "subcategoria": e.get("subcategoria", ""),
                "descricao": e.get("descricao", ""),
                "data": e.get("data", ""),
                "valor": e.get("valor", 0),
            })
    for e in new_expenses:
        if "FUNDO" in e.get("secao", "").upper() and "RESERVA" in e.get("secao", "").upper():
            info["lancamentos"].append({
                "periodo": "atual",
                "subcategoria": e.get("subcategoria", ""),
                "descricao": e.get("descricao", ""),
                "data": e.get("data", ""),
                "valor": e.get("valor", 0),
            })

    return info


def _build_analise_mensal(old_expenses, new_expenses, new_periodo: str) -> dict:
    """Agrupa lançamentos do período anterior por mês e compara com mês atual.

    Identifica subcategorias que existiam no mesmo mês do ano anterior mas não
    existem no mês atual, e vice-versa.
    """
    from collections import defaultdict

    # Extrair mês/ano do período atual (formato "dd/mm/aaaa a dd/mm/aaaa")
    m = re.match(r"(\d{2})/(\d{2})/(\d{4})", new_periodo)
    mes_atual = int(m.group(2)) if m else 0
    ano_atual = int(m.group(3)) if m else 0

    # Agrupar expenses do anterior por mês -> seção > conta > subcategoria
    mensal = defaultdict(float)  # (mes, secao>conta>subcat) -> total
    mensal_contagem = defaultdict(set)  # secao>conta>subcat -> set de meses

    for e in old_expenses:
        data = e.get("data", "")
        subcat = e.get("subcategoria", "").strip()
        conta = e.get("conta", "").strip()
        if not subcat or not conta:
            continue
        m_data = re.match(r"\d{2}/(\d{2})/(\d{4})", data)
        if not m_data:
            continue
        mes = int(m_data.group(1))
        # Ignorar seção na chave para evitar falsos positivos de seção diferente
        chave = f"{conta} > {subcat}"
        valor = e.get("valor", 0) or 0
        mensal[(mes, chave)] += valor
        mensal_contagem[chave].add(mes)

    # Subcategorias do mês atual (mesma chave sem seção, só com data válida)
    subcats_atual = defaultdict(float)
    for e in new_expenses:
        subcat = e.get("subcategoria", "").strip()
        conta = e.get("conta", "").strip()
        data = e.get("data", "")
        if not subcat or not conta:
            continue
        # Ignorar linhas-resumo sem data (PAGTO FUNCIONARIOS, 13° SALARIO)
        if not re.match(r"\d{2}/\d{2}/\d{4}", data):
            continue
        chave = f"{conta} > {subcat}"
        subcats_atual[chave] += e.get("valor", 0) or 0

    # Análise: o que existia no mesmo mês do ano anterior e não existe agora
    ausentes_no_mes = []
    if mes_atual > 0:
        for chave, meses in mensal_contagem.items():
            if mes_atual in meses and chave not in subcats_atual:
                partes = chave.split(" > ", 1)
                total_mesmo_mes = mensal.get((mes_atual, chave), 0)
                ausentes_no_mes.append({
                    "conta": partes[0] if len(partes) > 0 else "",
                    "subcategoria": partes[1] if len(partes) > 1 else "",
                    "totalMesmoMesAnterior": total_mesmo_mes,
                    "mesesPresente": sorted(list(meses)),
                    "recorrencia": len(meses),
                })

    # Novidades no mês atual que nunca existiram no período anterior
    novas_sem_historico = []
    for chave, total in subcats_atual.items():
        if chave not in mensal_contagem:
            partes = chave.split(" > ", 1)
            novas_sem_historico.append({
                "conta": partes[0] if len(partes) > 0 else "",
                "subcategoria": partes[1] if len(partes) > 1 else "",
                "totalAtual": total,
            })

    # Resumo mensal: total por mês para visão geral
    totais_por_mes = defaultdict(float)
    for (mes, chave), total in mensal.items():
        totais_por_mes[mes] += total

    return {
        "mesAtual": mes_atual,
        "anoAtual": ano_atual,
        "ausentesNoMes": sorted(ausentes_no_mes, key=lambda x: -x["totalMesmoMesAnterior"]),
        "novasSemHistorico": sorted(novas_sem_historico, key=lambda x: -x["totalAtual"]),
        "totaisPorMes": {str(k): round(v, 2) for k, v in sorted(totais_por_mes.items())},
    }


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
    # Extrair seções dinâmicas do Resumo Financeiro de ambos os PDFs
    old_sections = extrair_secoes_do_resumo(text_old)
    new_sections = extrair_secoes_do_resumo(text_new)
    all_sections = old_sections | new_sections
    print(f"Seções detectadas: {', '.join(sorted(all_sections))}")

    old_data = parse_despesas(text_old, all_sections)
    new_data = parse_despesas(text_new, all_sections)
    old_totais = parse_totais_contas(text_old, all_sections)
    new_totais = parse_totais_contas(text_new, all_sections)
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
    subcats_por_conta = _build_subcategorias_por_conta(
        old_data["expenses"], new_data["expenses"]
    )
    fundo_reserva = _build_fundo_reserva_info(
        old_resumo, new_resumo, old_data["expenses"], new_data["expenses"]
    )
    analise_mensal = _build_analise_mensal(
        old_data["expenses"], new_data["expenses"], new_data["periodo"]
    )

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
        "subcategoriasPorConta": subcats_por_conta,
        "fundoReserva": fundo_reserva,
        "analiseMensal": analise_mensal,
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
