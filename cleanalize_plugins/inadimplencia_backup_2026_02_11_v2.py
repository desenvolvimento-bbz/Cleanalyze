# cleanalize_plugins/inadimplencia.py
"""
Parser de relatórios de inadimplência (Relação Analítica de Pendentes).

REGRAS IMPLEMENTADAS:
1. is_noise_line(): Detecta e ignora linhas de ruído (cabeçalhos, rodapés, OCR corrompido)
   - Não zera variáveis de contexto (recibo_atual, vencimento_atual, etc.)
2. parse_recibo_header(): Captura forte de Recibo/Vencimento/Emissão com validação
3. Propagação de vencimento_atual: Linhas de detalhe herdam vencimento do último recibo
4. Extração segura de valor: Converte valores OCR (ex: 2531->25,31) só para contas permitidas
5. Validação: Nunca cria linha com Valor vazio (exceto continuações de histórico)
6. Debug interno: Loga [BUG] quando vencimento ou valor ficam vazios
"""
import re
from typing import List, Dict, Optional, Tuple

NOME = "inadimplencia"

# ==================== MODO DIAGNÓSTICO ====================
# Se True, imprime motivo de cada linha ignorada pelo parser
DEBUG_PARSER = True

COL_TITLES = ["Recibo", "Vencimento", "Emissão", "Conta", "Histórico", "Valor"]

# ==================== CONTAS PERMITIDAS PARA CONVERSÃO OCR ====================
# Contas onde valores sem vírgula (ex: 2531) podem ser convertidos para decimal (25,31)
# Estas são contas de cobrança típicas onde esse padrão de OCR é comum
CONTAS_PERMITIDAS_CONVERSAO_OCR = {'7', '49', '563', '671', '892', '1305'}

# ==================== FUNÇÕES DE FORMATAÇÃO ====================

def _formatar_unidade(unidade: str) -> str:
    """Formata unidade com 6 dígitos. Ex: '10' -> '000010'"""
    if not unidade:
        return unidade
    numeros = re.sub(r'\D', '', unidade)
    if numeros:
        return numeros.zfill(6)
    return unidade.upper()

def _formatar_condominio(condominio: str) -> str:
    """Formata código do condomínio com 4 dígitos. Ex: '893' -> '0893'"""
    if not condominio:
        return condominio
    numeros = re.sub(r'\D', '', condominio)
    if numeros:
        return numeros.zfill(4)
    return condominio.upper()

def _normalizar_medida_no_historico(txt: str) -> str:
    """
    Normaliza medidas de volume no histórico, corrigindo problemas de OCR.

    Correções:
    1. Decimais quebrados: "17, 39" -> "17,39"
    2. M? -> M3 (erro comum de OCR)
    3. M 3 / M ³ -> M3
    4. 17,39M3 -> 17,39 M3
    5. Padroniza para M3 maiúsculo
    """
    if not txt:
        return txt

    # Passo 1: Corrigir decimais quebrados por OCR
    txt = re.sub(r'(\d),\s+(\d{1,3})', r'\g<1>,\g<2>', txt)

    # Passo 2: Normalizar M³, M?, M 3, M ³ para M3
    txt = re.sub(r'([Mm])\s*\u00b3', r'\g<1>3', txt)   # M³ -> M3
    txt = re.sub(r'([Mm])\s*\?', r'\g<1>3', txt)       # M? -> M3
    txt = re.sub(r'([Mm])\s+3', r'\g<1>3', txt)        # M 3 -> M3

    # Passo 3: Garantir espaço antes de M3 quando colado em número
    txt = re.sub(r'(\d)([Mm]3)', r'\g<1> \g<2>', txt)

    # Passo 4: Padronizar para M3 maiúsculo
    txt = re.sub(r'([Mm])3', 'M3', txt)

    # Passo 5: M solitário (corrompido: perdeu expoente ³/3/?) -> M3
    # Ex: "0,146 M" -> "0,146 M3" (apenas quando precedido por número decimal)
    # Evita falsos positivos: só converte quando M vem após medida de volume
    txt = re.sub(r'(\d+,\d+\s+)[Mm](\s|$)', r'\1M3\2', txt)

    return txt

# ==================== DETECÇÃO DE RUÍDO (NOISE LINES) ====================

# Padrões de texto que indicam linhas de ruído (cabeçalhos, rodapés, etc.)
# Estas linhas devem ser IGNORADAS e NÃO devem zerar o contexto do recibo
NOISE_PATTERNS = [
    # Cabeçalhos/rodapés do relatório
    "relação analítica de pendentes",
    "relacao analitica de pendentes",
    "período de:",
    "posição em:",
    "especializada em condomínios",
    "legenda:",
    "emitido em",
    "genesis",
    "tipo do processo",
    "qtde de unidades",
    "quantidade de unidades",
    # Paginação (incluindo variações de OCR)
    "página",
    "p4gina",
    "pagina",
    # Cabeçalho de colunas
    "(recibo",
    "£3- recibo",
]

def is_noise_line(line: str) -> bool:
    """
    REGRA 1: Detecta se a linha é ruído (cabeçalho/rodapé/OCR corrompido).

    IMPORTANTE: Linhas de ruído são IGNORADAS mas NÃO zeram o contexto
    (recibo_atual, vencimento_atual, bloco_atual, unidade_atual).

    Detecta:
    1. Linhas vazias ou muito curtas (< 3 caracteres)
    2. Linhas que contêm padrões conhecidos de cabeçalho/rodapé
    3. Linhas de cabeçalho de colunas (Recibo Vencimento Emissão...)
    4. Texto corrompido por OCR com dígitos intercalados em palavras
       Ex: "5Bl0o3c5o5: 0 Unid1a de:1 01/0037..."

    Returns:
        True se a linha é ruído e deve ser ignorada
        False se a linha deve ser processada
    """
    if not line:
        return True

    s_stripped = line.strip()

    # Linhas vazias ou muito curtas
    if len(s_stripped) < 3:
        return True

    s_low = s_stripped.lower()

    # Verificar padrões conhecidos de ruído
    for pattern in NOISE_PATTERNS:
        if pattern in s_low:
            return True

    # Verificar linha de cabeçalho de colunas
    # (contém múltiplos nomes de colunas juntos)
    col_count = sum(1 for col in ["recibo", "vencimento", "emiss", "conta", "hist", "valor"]
                    if col in s_low)
    if col_count >= 4:
        return True

    # REGRA ESPECIAL: Detectar texto corrompido por OCR
    # Padrão: dígitos intercalados em palavras que deveriam ser texto
    # Ex: "5Bl0o3c5o5: 0 Unid1a de:1" (Bloco e Unidade corrompidos)
    # Heurística: se tem mistura de letra+dígito+letra várias vezes, é ruído
    corrupted_pattern = r'[A-Za-z]\d[A-Za-z]|[A-Za-z]\d\d[A-Za-z]'
    corrupted_matches = len(re.findall(corrupted_pattern, s_stripped))
    if corrupted_matches >= 2:
        # Múltiplas ocorrências de padrão corrompido = linha de ruído
        return True

    return False


def _is_pure_header_footer(line: str) -> bool:
    """
    Detecta cabeçalhos/rodapés PUROS do relatório.
    NÃO descarta linhas que contenham valores monetários — essas podem ter dados.
    Linhas com dados contábeis passam para o loop principal tratar.
    """
    if not line:
        return True
    s = line.strip()
    if len(s) < 3:
        return True

    s_low = s.lower()

    # Se contém valor monetário, NÃO é ruído puro (pode conter lançamento)
    if re.search(r'\d{1,3}(?:\.\d{3})*,\d{2}', s):
        return False

    # Padrões de cabeçalho/rodapé conhecidos
    for pattern in NOISE_PATTERNS:
        if pattern in s_low:
            return True

    # Cabeçalho de colunas (4+ nomes de coluna)
    col_count = sum(1 for col in ["recibo", "vencimento", "emiss", "conta", "hist", "valor"]
                    if col in s_low)
    if col_count >= 4:
        return True

    return False


def _is_corrupted_line(line: str) -> bool:
    """
    Detecta linhas corrompidas por quebra de página do PDF.
    Padrão: alternâncias frequentes letra-dígito-letra (ex: "5Bl0o3c5o5").
    Threshold >= 3 para evitar falsos positivos em linhas normais.
    """
    if not line or len(line.strip()) < 10:
        return False
    s = line.strip()
    corrupted_count = len(re.findall(
        r'[A-Za-z]\d[A-Za-z]|[A-Za-z]\d\d[A-Za-z]', s
    ))
    return corrupted_count >= 3


_SALVAGE_KEYWORD_MAP = [
    # (keyword_in_letters_only, conta, base_description)
    ('RESERVA', '49', 'FUNDO RESERVA'),
    ('BENFEITORIA', '1305', 'MELHORIAS/BENFEITORIAS'),
    ('READEQUA', '1305', 'READEQUACAO'),
    ('DOMINIO', '7', 'CONDOMINIO'),
    ('CONDOMI', '7', 'CONDOMINIO'),
    ('TAXALEITURA', '671', 'TAXA DA LEITURA'),
    ('CONSUMO', '671', 'CONSUMO DE AGUA'),
]

def _try_salvage_corrupted(line: str, contas_conhecidas: set) -> Optional[Dict[str, str]]:
    """
    Tenta salvar dados de uma linha corrompida de quebra de pagina.

    Estrategia em 2 fases:
    Fase 1: Procura conta como token isolado + valor monetario (rapido)
    Fase 2: Extrai SOMENTE LETRAS do texto garbled, procura keywords
            conhecidos (RESERVA, BENFEITORIA, DOMINIO...) para determinar
            a conta, e pega o valor monetario do fim da linha (sempre limpo).

    Returns:
        Dict com 'conta', 'historico', 'valor' ou None se nao conseguir
    """
    if not line:
        return None

    s = line.strip()

    # === FASE 1: Token isolado (abordagem original) ===
    m_valor_end = re.search(r'(-?\d{1,3}(?:\.\d{3})*,\d{2})\s*$', s)
    if not m_valor_end:
        return None
    valor = m_valor_end.group(1)

    conta_found = None
    conta_end = 0
    for conta_code in sorted(contas_conhecidas, key=lambda x: -len(x)):
        m = re.search(r'(?:^|\s)(' + re.escape(conta_code) + r')(?:\s)', s)
        if m:
            conta_found = m.group(1)
            conta_end = m.end(1)
            break

    if conta_found:
        texto_apos = s[conta_end:m_valor_end.start()].strip()
        hist = _normalizar_medida_no_historico(texto_apos) if texto_apos else ""
        return {'conta': conta_found, 'historico': hist, 'valor': valor}

    # === FASE 2: Keyword em letters-only (para linhas muito corrompidas) ===
    text_before = s[:m_valor_end.start()]
    letters_only = re.sub(r'[^A-Za-z\u00C0-\u00FF]', '', text_before).upper()
    # Normalizar acentos para matching
    letters_norm = letters_only
    for src, dst in [('\u00cd', 'I'), ('\u00c3', 'A'), ('\u00c7', 'C'),
                     ('\u00d3', 'O'), ('\u00c9', 'E'), ('\u00ca', 'E'),
                     ('\u00da', 'U'), ('\u00d4', 'O'), ('\u00c2', 'A')]:
        letters_norm = letters_norm.replace(src, dst)

    for keyword, conta_code, desc_base in _SALVAGE_KEYWORD_MAP:
        if keyword in letters_norm:
            # Tentar extrair parcela (X/Y) do texto antes do valor
            parcela = ""
            m_parc = re.search(r'(\d{1,2}/\d{1,2})\s+' + re.escape(valor), s)
            if m_parc:
                p = m_parc.group(1)
                parts = p.split('/')
                try:
                    if all(int(x) <= 12 for x in parts):
                        parcela = p
                except ValueError:
                    pass

            hist = desc_base + (f" {parcela}" if parcela else "")
            return {'conta': conta_code, 'historico': hist, 'valor': valor}

    return None


def _normalizar_linha_recibo(line: str, debug: bool = False) -> str:
    """
    Normaliza linha de recibo removendo marcadores entre o número e a data.
    Marcadores comuns: —, -, ), (, J, =J, *, #, £, espaços extras.
    Ex: "543306 — ) 10/05/2022 10521 ..." -> "543306 10/05/2022 10521 ..."
    Não altera linhas que não sejam recibo (< 5 dígitos iniciais ou sem data).
    """
    m = re.match(
        r'^(\s*\d{5,10})'                   # grupo 1: recibo (5-10 dígitos)
        r'([^\d]+)'                          # grupo 2: marcadores (non-digits, 1+)
        r'(\d{1,2}/\d{1,2}/\d{2,4})'        # grupo 3: data DD/MM/AAAA
        r'(.*)',                             # grupo 4: resto da linha
        line
    )
    if not m:
        return line

    recibo = m.group(1)
    markers = m.group(2).strip()
    date = m.group(3)
    rest = m.group(4)

    # Se havia marcadores além de espaços, limpar
    if markers:
        cleaned = f"{recibo} {date}{rest}"
        if debug:
            print(f"[DEBUG-NORM] '{line[:70]}' -> removido: '{markers}'")
        return cleaned
    return line


def _normalizar_layout_compacto(line: str, debug: bool = False) -> str:
    """
    Normaliza linhas do layout compacto onde campos vêm colados.

    Correção aplicada apenas no trecho INICIAL da linha (primeiros ~50 chars):
    1. Data colada com emissão: "10/05/2025256394" -> "10/05/2025 256394"

    NÃO altera valores monetários, M3 nem texto do histórico.
    """
    if not line or len(line.strip()) < 15:
        return line

    # Padrão: DD/MM/YYYY seguido imediatamente por 5-7 dígitos (emissão colada)
    # Aplicar somente nos primeiros ~50 chars para não quebrar valores monetários
    m = re.search(r'(\d{2}/\d{2}/\d{4})(\d{5,7})', line[:50])
    if m:
        before = line[:m.start()]
        date_part = m.group(1)
        emissao_part = m.group(2)
        after = line[m.end():]
        line_new = f"{before}{date_part} {emissao_part}{after}"
        if debug or DEBUG_PARSER:
            print(f"[DEBUG-COMPACT] Separou data+emissao: "
                  f"'{line.strip()[:60]}' -> '{line_new.strip()[:60]}'")
        return line_new

    return line


# ==================== LINE SPLITTER ====================

# Contas típicas que aparecem nos lançamentos
CONTAS_CONHECIDAS = {'7', '49', '563', '671', '892', '1305'}

def _split_multiple_accounts(line: str) -> List[str]:
    """
    Detecta e separa múltiplas contas (lançamentos) que aparecem na mesma linha.

    Problema: O pdfplumber às vezes coloca múltiplos lançamentos na mesma linha.
    Exemplo: "671 TAXA LEITURA AGUA 5,37 892 FECH., ENTRADA/GAR PC.02/06 2531"

    Returns:
        Lista de linhas lógicas (pode ser 1 se não houver split necessário)
    """
    if not line or not line.strip():
        return [line] if line else []

    line = line.strip()

    # NÃO dividir linhas de registro principal (que contêm data DD/MM/YYYY)
    if re.search(r'\d{1,2}/\d{1,2}/\d{2,4}', line):
        return [line]

    contas_pattern = r'(?:7|49|563|671|892|1305)'
    split_pattern = rf'(\d{{1,3}}(?:\.\d{{3}})*,\d{{2}}|\d{{3,4}})\s+({contas_pattern})\s+(?=[A-Za-z])'
    matches = list(re.finditer(split_pattern, line))

    if not matches:
        return [line]

    result = []
    last_end = 0

    for match in matches:
        valor_end = match.end(1)
        conta_start = match.start(2)
        before = line[last_end:valor_end].strip()
        if before:
            result.append(before)
        last_end = conta_start

    remaining = line[last_end:].strip()
    if remaining:
        result.append(remaining)

    if len(result) <= 1:
        return [line]

    return result


def _recolher_medida_quebrada(texto_linha: str, historico_extraido: str) -> str:
    """
    Reconstrói medidas de volume que foram quebradas na extração.

    Problema: O histórico pode vir truncado (ex: "CONSUMO DE AGUA NOV/2025 17,")
    quando a linha original contém "17,39 M3".
    """
    if not historico_extraido or not texto_linha:
        return historico_extraido

    hist = historico_extraido.strip()

    # Se já contém M3/M³/M?, apenas normalizar e retornar
    if re.search(r'[Mm]\s*[³3\?]', hist):
        return _normalizar_medida_no_historico(hist)

    # Verificar se o histórico termina com número incompleto
    match_fim_incompleto = re.search(r'(\d+)(,)?(\s*)$', hist)
    if not match_fim_incompleto:
        return _normalizar_medida_no_historico(hist)

    # Procurar na linha original TODAS as medidas de volume
    padrao_medida = r'(\d+)[,.](\d{1,3})\s*([Mm]\s*[³3\?])'
    medidas_encontradas = list(re.finditer(padrao_medida, texto_linha))
    if not medidas_encontradas:
        return _normalizar_medida_no_historico(hist)

    num_incompleto = match_fim_incompleto.group(1)
    tem_virgula = match_fim_incompleto.group(2) is not None

    for m in medidas_encontradas:
        parte_inteira = m.group(1)
        parte_decimal = m.group(2)

        if parte_inteira == num_incompleto:
            medida_completa = f"{parte_inteira},{parte_decimal} M3"
            if tem_virgula:
                hist_base = re.sub(r'\d+,\s*$', '', hist)
            else:
                hist_base = re.sub(r'\d+\s*$', '', hist)
            resultado = hist_base.rstrip() + " " + medida_completa
            resultado = re.sub(r'\s+', ' ', resultado).strip()
            return _normalizar_medida_no_historico(resultado)

    return _normalizar_medida_no_historico(hist)

def _normalizar_unidade_volume(txt: str) -> str:
    """Alias para _normalizar_medida_no_historico (compatibilidade)."""
    return _normalizar_medida_no_historico(txt)

def _tem_unidade_volume(txt: str) -> bool:
    """Verifica se o texto contém unidade de volume (M3, M³, M?, m3, etc.)"""
    if not txt:
        return False
    return bool(re.search(r'[Mm]\s*[³3\?]', txt))

def _formatar_historico(historico: str, max_chars: int = 40) -> Tuple[str, str]:
    """
    Converte histórico para maiúsculas e normaliza unidades de volume.

    Retorna (descricao, complemento):
    - Se contém unidade de volume (M3/M³/M?): retorna texto COMPLETO, sem truncar
    - Caso contrário: trunca em max_chars e retorna complemento separado

    REGRA ADICIONAL: Remove valores numéricos órfãos no fim do histórico
    (restos de OCR que não foram capturados como valor)
    """
    if not historico:
        return (historico or "", "")

    texto = _normalizar_unidade_volume(historico.strip())
    texto = texto.upper()

    # Remover valores numéricos órfãos no fim (restos de OCR)
    # Padrões: "112895", "1.12895", "12895", etc. no fim do texto
    texto = re.sub(r'\s+\d{4,6}\s*$', '', texto)  # Remove "  112895" no fim
    texto = re.sub(r'\s+\d+\.\d{4,5}\s*$', '', texto)  # Remove "  1.12895" no fim
    texto = texto.strip()

    # Se contém unidade de volume, NÃO truncar
    if _tem_unidade_volume(texto):
        return (texto, "")

    # Caso contrário, aplicar truncamento normal
    if len(texto) > max_chars:
        descricao = texto[:max_chars].rstrip()
        complemento = texto[max_chars:].strip()
        return (descricao, complemento)

    return (texto, "")

def _to_upper(valor: str) -> str:
    """Converte string para maiúsculas."""
    if not valor:
        return valor
    return str(valor).upper()

def _is_medida_volume(texto_apos: str) -> bool:
    """
    Verifica se o texto após um número indica medida de volume (M3, M³, M?, etc.)
    """
    if not texto_apos:
        return False
    trecho = texto_apos[:6].strip()
    if re.match(r'^[Mm]\s*[³3\?]', trecho, re.IGNORECASE):
        return True
    if re.match(r'^\s+[Mm]\s*[³3\?]', texto_apos[:10], re.IGNORECASE):
        return True
    return False

# ==================== EXTRAÇÃO DE VALOR MONETÁRIO ====================

def _converter_ocr_para_valor(token: str) -> Optional[str]:
    """
    Converte token OCR (inteiro sem vírgula) para valor monetário formatado.

    Interpreta o inteiro como centavos e formata no padrão brasileiro.
    Ex: "431" -> "4,31", "11" -> "0,11", "037" -> "0,37", "2531" -> "25,31"

    Returns:
        Valor formatado ou None se inválido
    """
    if not token or not token.strip():
        return None

    token = token.strip()
    if not re.match(r'^\d{1,6}$', token):
        return None

    try:
        num = int(token)
        if num <= 0 or num >= 10000000:
            return None
        valor_decimal = num / 100.0
        if valor_decimal >= 1000:
            parte_inteira = int(valor_decimal)
            parte_dec = int(round((valor_decimal - parte_inteira) * 100))
            return f"{parte_inteira:,}".replace(',', '.') + f",{parte_dec:02d}"
        else:
            return f"{valor_decimal:.2f}".replace('.', ',')
    except ValueError:
        return None


# Flag global: indica se a ultima extracao usou conversao OCR
_last_extraction_was_ocr = False

def _extrair_valor_monetario_final(line: str, conta: str = "") -> Optional[Tuple[str, int]]:
    """
    REGRA 4: Encontra o VALOR DO ITEM (não o Total) na linha.

    Estratégia:
    1. Linhas com M3/M³/M?/M corrompido:
       - Procura PRIMEIRO token numérico após M (OCR inteiro ou valor formatado)
       - Se OCR inteiro (ex: 431, 11, 037) -> converte centavos (4,31, 0,11, 0,37)
       - Se valor formatado (ex: 171,06) -> usa direto
    2. Linhas sem M3: pega PRIMEIRO valor formatado (com vírgula)
       - Valores subsequentes são TotalRecibo/Total -> ignorados
    3. FALLBACK: Inteiro OCR no fim (APENAS contas permitidas)

    Args:
        line: Linha de texto
        conta: Código da conta (para validar conversão OCR)

    Returns:
        (valor, posicao) ou None se não encontrar valor válido
    """
    global _last_extraction_was_ocr
    _last_extraction_was_ocr = False

    line = line.rstrip()

    # Padrão para valores monetários formatados (com vírgula decimal)
    padrao_valor = r'-?\d{1,3}(?:\.\d{3})*,\d{2}'

    # Encontrar TODOS os valores monetários na linha
    todos_valores = [(m.group(), m.start(), m.end()) for m in re.finditer(padrao_valor, line)]

    # ===== DETECÇÃO DE M3 (incluindo M corrompido sem expoente) =====
    # Normal: "0,514 M3", "0,133 M³", "0,061 M?"
    # Corrompido: "0,146 M  0,68" (M perdeu o expoente ³/3/?)
    match_medida = re.search(r'(\d+,\d+)\s*[Mm]\s*[³3\?]', line)
    if not match_medida:
        # Tentar M corrompido: número,decimal + espaço + M + (espaço + dígito à frente)
        match_medida = re.search(r'(\d+,\d+)\s+[Mm](?=\s+\d)', line)

    if match_medida:
        pos_fim_medida = match_medida.end()
        texto_apos_m3 = line[pos_fim_medida:]

        # PRIORIDADE 1: OCR inteiro ANTES de qualquer valor com vírgula
        # Ex: "  431", "  11  1.322,16", "  037  1.350,14"
        match_ocr = re.match(r'\s+(\d{1,6})(?:\s|$)', texto_apos_m3)
        if match_ocr and conta and conta in CONTAS_PERMITIDAS_CONVERSAO_OCR:
            token_ocr = match_ocr.group(1)
            valor_convertido = _converter_ocr_para_valor(token_ocr)
            if valor_convertido:
                _last_extraction_was_ocr = True
                return (valor_convertido, pos_fim_medida + match_ocr.start(1))

        # PRIORIDADE 2: Primeiro valor formatado (vírgula) após M3
        for valor, pos_ini, _ in todos_valores:
            if pos_ini >= pos_fim_medida:
                return (valor, pos_ini)

        # Nenhum valor após M3
        return None

    # ===== LINHAS SEM M3: Pegar PRIMEIRO valor formatado =====
    # REGRA: O primeiro valor é o Valor do item
    # Valores subsequentes são TotalRecibo/Total -> ignorados
    if todos_valores:
        valor, pos_ini, _ = todos_valores[0]
        return (valor, pos_ini)

    # ===== FALLBACK 1: Inteiro OCR no fim (sem vírgula) =====
    if conta and conta in CONTAS_PERMITIDAS_CONVERSAO_OCR:
        match_inteiro = re.search(r'\s(\d{2,6})\s*$', line)
        if match_inteiro:
            valor_convertido = _converter_ocr_para_valor(match_inteiro.group(1))
            if valor_convertido:
                _last_extraction_was_ocr = True
                return (valor_convertido, match_inteiro.start(1))

    # ===== FALLBACK 2: Formato quebrado "1.12895" (ponto no lugar errado) =====
    match_quebrado = re.search(r'\s(\d+)\.(\d{4,5})\s*$', line)
    if match_quebrado and conta and conta in CONTAS_PERMITIDAS_CONVERSAO_OCR:
        token_junto = match_quebrado.group(1) + match_quebrado.group(2)
        valor_convertido = _converter_ocr_para_valor(token_junto)
        if valor_convertido:
            _last_extraction_was_ocr = True
            return (valor_convertido, match_quebrado.start(1))

    return None

def _extrair_historico_e_valor(texto_apos_conta: str, conta: str = "") -> Tuple[str, str]:
    """
    Dado o texto após o campo Conta, extrai o histórico e o valor.
    """
    texto = texto_apos_conta.strip()
    resultado = _extrair_valor_monetario_final(texto, conta)
    if not resultado:
        return (texto, "")
    valor, pos_valor = resultado
    historico = texto[:pos_valor].strip()
    return (historico, valor)

# ==================== PARSE RECIBO HEADER (UNIVERSAL) ====================

# Contadores globais para debug
_debug_header_stats = {"total_linhas": 0, "headers_aceitos": 0, "headers_rejeitados": 0}
_debug_bugs = []  # Lista de linhas com problemas detectados

def _reset_debug_stats():
    """Reseta contadores de debug."""
    global _debug_header_stats, _debug_bugs
    _debug_header_stats = {"total_linhas": 0, "headers_aceitos": 0, "headers_rejeitados": 0}
    _debug_bugs = []

def _get_debug_stats() -> dict:
    """Retorna estatísticas de debug."""
    return _debug_header_stats.copy()

def _log_bug(tipo: str, recibo: str, vencimento: str, linha: str):
    """
    REGRA 6: Loga quando uma linha gera output com vencimento ou valor vazio.
    """
    global _debug_bugs
    msg = f"[BUG] {tipo} | Recibo={recibo} | Venc={vencimento} | Linha: {linha[:80]}"
    _debug_bugs.append(msg)
    print(msg)

def parse_recibo_header(line: str) -> Optional[Dict[str, str]]:
    """
    REGRA 2: Função universal para detectar e parsear cabeçalho de recibo.

    REGEX FORTE que só faz match se todos os campos estiverem presentes:
    - Recibo: 5-8 dígitos no início
    - Vencimento: DD/MM/AAAA ou DD/MM/AA (data válida)
    - Emissão: 3-6 dígitos após a data

    Se NÃO bater nesse padrão, NÃO sobrescreve recibo_atual, vencimento_atual, etc.
    """
    global _debug_header_stats

    if not line or not line.strip():
        return None

    _debug_header_stats["total_linhas"] += 1

    # REGEX FORTE: Recibo + [marcadores opcionais] + Data + Emissão
    pattern = (
        r"^\s*"
        r"(?P<recibo>\d{5,10})"             # Recibo: 5-10 dígitos
        r"[^0-9]*?"                          # Qualquer coisa não-numérica (marcadores)
        r"(?P<venc>\d{1,2}/\d{1,2}/\d{2,4})" # Vencimento: DD/MM/YYYY ou DD/MM/YY
        r"\s*"                               # Zero ou mais espaços (compacto: colado)
        r"(?P<emissao>\d{3,7})"              # Emissão: 3-7 dígitos
        r"(?:\s+(?P<resto>.*))?"             # Resto opcional (conta+hist+valor)
    )

    m = re.match(pattern, line)
    if not m:
        return None

    recibo = m.group("recibo").strip()
    venc = m.group("venc").strip()
    emissao = m.group("emissao").strip()
    resto = (m.group("resto") or "").strip()

    # VALIDAÇÃO 1: Data deve ser válida
    if not _is_valid_date(venc):
        _debug_header_stats["headers_rejeitados"] += 1
        return None

    # VALIDAÇÃO 2: Emissão deve ter entre 3 e 7 dígitos
    if not (3 <= len(emissao) <= 7):
        _debug_header_stats["headers_rejeitados"] += 1
        return None

    _debug_header_stats["headers_aceitos"] += 1

    return {
        "recibo": recibo,
        "vencimento": venc,
        "emissao": emissao,
        "resto": resto,
    }


def _is_valid_date(date_str: str) -> bool:
    """Valida se a string é uma data válida no formato DD/MM/AAAA ou DD/MM/AA."""
    if not date_str:
        return False

    if not re.match(r"^\d{1,2}/\d{1,2}/\d{2,4}$", date_str):
        return False

    parts = date_str.split("/")
    if len(parts) != 3:
        return False

    try:
        dia = int(parts[0])
        mes = int(parts[1])
        ano = int(parts[2])

        if not (1 <= dia <= 31):
            return False
        if not (1 <= mes <= 12):
            return False
        if ano < 100:
            ano = 2000 + ano if ano < 50 else 1900 + ano
        if not (1900 <= ano <= 2100):
            return False

        return True
    except (ValueError, IndexError):
        return False


# Palavras que indicam linhas de total
IGNORE_HINTS = [
    "total do recibo", "total:", "total ", "subtotal", "acumulado", "saldo",
    "totais", "total geral"
]

def _is_total_line(s: str) -> bool:
    s_low = s.lower()
    return any(h in s_low for h in IGNORE_HINTS)

def _classificar_linha_rejeitada(linha: str, header_result: Optional[Dict]) -> str:
    """Classifica o motivo de rejeicao de uma linha candidata nao parseada."""
    if _is_total_line(linha):
        return "TOTAL"
    if is_noise_line(linha):
        return "RUIDO"
    if _is_corrupted_line(linha):
        return "CORROMPIDA"
    if header_result:
        resto = header_result.get("resto", "").strip()
        m_conta = re.match(r'^(\d+)\s+', resto)
        if m_conta and m_conta.group(1) == "7":
            return "CONTA_7_CONTEXTO"
        return "HEADER_SEM_DADOS"
    s_low = linha.lower()
    col_count = sum(1 for col in ["recibo", "vencimento", "emiss", "conta", "hist", "valor"]
                    if col in s_low)
    if col_count >= 3:
        return "CABECALHO_COLUNAS"
    if not re.match(r'^\s*\d{5,}', linha):
        return "SEM_RECIBO"
    return "REGEX_NAO_CASOU"


def _diagnosticar_linhas_nao_parseadas(texto: str, registros: List[Dict]):
    """
    Modo diagnostico avancado: varre TODAS as linhas do texto de entrada.
    Identifica linhas 'candidatas' (contem data DD/MM/YYYY e valor monetario)
    e reporta quais NAO geraram registros, com motivo provavel.

    Candidata = linha que contem:
    - Data no formato DD/MM/YYYY (4 digitos no ano)
    - Valor monetario no formato X.XXX,XX ou XXX,XX
    """
    linhas_raw = texto.splitlines()

    # Set de recibos extraidos para checagem rapida
    recibos_extraidos = set(r.get("Recibo", "") for r in registros)

    pat_data = re.compile(r'\d{1,2}/\d{1,2}/\d{4}')
    pat_valor = re.compile(r'\d{1,3}(?:\.\d{3})*,\d{2}')

    total_candidatas = 0
    parseadas = 0
    candidatas_perdidas = []

    for i, raw in enumerate(linhas_raw, start=1):
        s = raw.strip()
        if not s or len(s) < 10:
            continue

        # Candidata: tem data DD/MM/YYYY E valor monetario
        if not (pat_data.search(s) and pat_valor.search(s)):
            continue

        total_candidatas += 1

        # Normalizar e testar com parse_recibo_header
        line_norm = _normalizar_linha_recibo(s)
        line_norm = _normalizar_layout_compacto(line_norm)
        header = parse_recibo_header(line_norm)

        # Verificar se o recibo desta linha esta nos registros extraidos
        if header and header["recibo"] in recibos_extraidos:
            parseadas += 1
            continue

        # Nao parseada - classificar motivo
        motivo = _classificar_linha_rejeitada(s, header)
        candidatas_perdidas.append((i, s[:100], motivo))

    # Relatorio
    print(f"\n{'='*70}")
    print(f"DIAGNOSTICO: Varredura completa de linhas candidatas")
    print(f"{'='*70}")
    print(f"  Total linhas no texto: {len(linhas_raw)}")
    print(f"  Registros extraidos: {len(registros)}")
    print(f"  Linhas candidatas (data+valor): {total_candidatas}")
    print(f"  -> Parseadas: {parseadas}")
    print(f"  -> Nao parseadas: {len(candidatas_perdidas)}")

    if candidatas_perdidas:
        # Agrupar por motivo
        por_motivo = {}
        for num, conteudo, motivo in candidatas_perdidas:
            por_motivo.setdefault(motivo, []).append((num, conteudo))

        print(f"\n  Resumo por motivo:")
        for motivo, items in sorted(por_motivo.items()):
            print(f"    {motivo}: {len(items)} linha(s)")

        print(f"\n  Detalhamento ({len(candidatas_perdidas)} linhas):")
        for num, conteudo, motivo in candidatas_perdidas:
            print(f"    L{num:04d} [{motivo}] {conteudo}")
    else:
        print(f"\n  -> Nenhuma candidata perdida!")

    print(f"{'='*70}\n")
    return candidatas_perdidas


def _find_condominio(line: str) -> Optional[str]:
    """Procura "Condominio: 0893 - ..." ou variações."""
    m = re.search(r"[\[\(]?\s*condom[ií]n[ií]?[oó]:\s*(\d+)", line, re.IGNORECASE)
    if m:
        return m.group(1).strip()
    return None

def _find_unit_header(line: str) -> Optional[Tuple[str, str]]:
    """Procura "Bloco: XXX  Unidade: YYY" (ordem pode variar)."""
    m = re.search(r"Bloco:\s*(?P<bloco>\S+).*?Unidade:\s*(?P<unidade>\S+)", line, re.IGNORECASE)
    if m:
        return (m.group("bloco").strip(), m.group("unidade").strip())
    m = re.search(r"Unidade:\s*(?P<unidade>\S+).*?Bloco:\s*(?P<bloco>\S+)", line, re.IGNORECASE)
    if m:
        return (m.group("bloco").strip(), m.group("unidade").strip())
    return None

def _find_header_positions(line: str) -> Optional[List[int]]:
    """Dada a linha de cabeçalho, retorna a posição inicial de cada título."""
    positions = []
    current = 0
    title_alternatives = {
        "Recibo": ["Recibo"],
        "Vencimento": ["Vencimento"],
        "Emissão": ["Emissão", "Emiss"],
        "Conta": ["Conta"],
        "Histórico": ["Histórico", "Hist"],
        "Valor": ["Valor"]
    }

    for title in COL_TITLES:
        idx = -1
        alternatives = title_alternatives.get(title, [title])
        for alt in alternatives:
            idx = line.find(alt, current)
            if idx >= 0:
                break
        if idx < 0:
            return None
        positions.append(idx)
        current = idx + 1

    positions = sorted(positions)
    return positions

def _is_date(s: str) -> bool:
    return bool(re.match(r"^[0-3]?\d/[01]?\d/\d{2,4}$", s))

def _looks_like_value(s: str) -> bool:
    return bool(re.search(r"-?\d{1,3}(?:\.\d{3})*,\d{2}$", s))


def _reconciliar_recibo(registros, ocr_indices, total_recibo, debug=False):
    """
    Reconcilia valores OCR suspeitos usando o total_recibo.

    Se exatamente 1 item no grupo tem valor OCR-convertido (suspeito)
    e conhecemos o total_recibo, recalcula:
      valor_correto = total_recibo - soma(outros itens)

    Args:
        registros: lista completa de registros
        ocr_indices: indices dos registros com valor OCR no recibo atual
        total_recibo: valor total do recibo (string formato BR)
        debug: ativar logs
    """
    if not ocr_indices or not total_recibo:
        return

    if len(ocr_indices) != 1:
        if debug:
            print(f"[DEBUG-RECONCIL] {len(ocr_indices)} itens OCR no recibo -> skip")
        return

    # Converter total_recibo para float
    try:
        total_f = float(total_recibo.replace('.', '').replace(',', '.'))
    except (ValueError, AttributeError):
        return

    # Pegar todos os registros do mesmo recibo
    idx_ocr = ocr_indices[0]
    recibo_id = registros[idx_ocr]["Recibo"]

    # Somar valores dos outros itens do mesmo recibo
    soma_outros = 0.0
    for i, r in enumerate(registros):
        if r["Recibo"] != recibo_id:
            continue
        if i == idx_ocr:
            continue
        try:
            v = float(r["Valor"].replace('.', '').replace(',', '.'))
            soma_outros += v
        except (ValueError, AttributeError):
            pass

    valor_correto = total_f - soma_outros
    if valor_correto <= 0 or valor_correto > total_f:
        if debug:
            print(f"[DEBUG-RECONCIL] Valor calculado {valor_correto:.2f} fora de range -> skip")
        return

    # Formatar no padrao BR
    if valor_correto >= 1000:
        parte_int = int(valor_correto)
        parte_dec = int(round((valor_correto - parte_int) * 100))
        valor_br = f"{parte_int:,}".replace(',', '.') + f",{parte_dec:02d}"
    else:
        valor_br = f"{valor_correto:.2f}".replace('.', ',')

    valor_antigo = registros[idx_ocr]["Valor"]
    if valor_br != valor_antigo:
        if debug:
            print(f"[DEBUG-RECONCIL] Recibo {recibo_id}: "
                  f"'{valor_antigo}' -> '{valor_br}' "
                  f"(total={total_recibo}, soma_outros={soma_outros:.2f})")
        registros[idx_ocr]["Valor"] = valor_br


class Extractor:
    @staticmethod
    def matches(texto: str) -> bool:
        """Detecta se parece relatório de inadimplência."""
        if not texto:
            return False
        has_title = re.search(r"Rela[çc][aã]o\s+Anal[ií]tica\s+de\s+Pendentes", texto, re.IGNORECASE)
        cols_hit = sum(1 for t in COL_TITLES if re.search(rf"\b{re.escape(t)}\b", texto))
        return bool(has_title and cols_hit >= 4)

    @staticmethod
    def extract_records(texto: str, debug: bool = False) -> List[Dict]:
        """
        Extrai registros do relatório de inadimplência.

        REGRAS IMPLEMENTADAS:
        1. is_noise_line(): Ignora ruído SEM zerar contexto
        2. parse_recibo_header(): Captura forte de Recibo/Vencimento/Emissão
        3. Propagação de vencimento_atual para linhas de detalhe
        4. Extração segura de valor com conversão OCR controlada
        5. Não cria linha com Valor vazio (exceto continuações)
        6. Loga [BUG] quando detecta problemas
        """
        if not texto:
            return []

        # Resetar estatísticas de debug
        _reset_debug_stats()

        linhas_raw = texto.splitlines()
        registros: List[Dict] = []

        # CONTEXTO PERSISTENTE (nunca zerado por linhas de ruído)
        condominio_atual: str = ""
        bloco_atual: str = ""
        unidade_atual: str = ""
        recibo_atual: str = ""
        vencimento_atual: str = ""  # REGRA 3: Propagado para linhas de detalhe
        col_positions: Optional[List[int]] = None

        last_row_index_with_record: Optional[int] = None

        # Reconciliacao: tracking de OCR e total_recibo por recibo
        current_recibo_ocr_indices: List[int] = []  # indices com valor OCR
        current_recibo_total: Optional[str] = None   # total_recibo candidato
        prev_recibo_id: str = ""                      # para detectar troca de recibo

        # Pré-processar linhas
        linhas = []
        for raw in linhas_raw:
            line_clean = raw.rstrip("\n")

            # Cabeçalho de colunas: sempre manter (define col_positions)
            is_column_header = (
                "Recibo" in line_clean and
                "Vencimento" in line_clean and
                "Conta" in line_clean and
                "Valor" in line_clean
            )
            if is_column_header:
                linhas.append(line_clean)
                continue

            # Cabeçalhos/rodapés puros (sem dados monetários): descartar
            # NÃO descarta linhas com valores -> main loop trata como total/item
            if _is_pure_header_footer(line_clean):
                continue

            # Linhas corrompidas de quebra de página: tentar salvar dados
            if _is_corrupted_line(line_clean):
                salvage = _try_salvage_corrupted(line_clean, CONTAS_CONHECIDAS)
                if salvage:
                    rebuilt = f"{salvage['conta']} {salvage['historico']} {salvage['valor']}"
                    linhas.append(rebuilt.strip())
                    if debug:
                        print(f"[DEBUG-SALVAGE] Corrupted: '{line_clean[:60]}' -> '{rebuilt.strip()[:60]}'")
                else:
                    if debug:
                        print(f"[DEBUG-SKIP] Corrupted sem dados: '{line_clean[:60]}'")
                # Nunca reseta contexto
                continue

            # Normalizar marcadores em linhas de recibo (—, ), J, =J, etc.)
            line_clean = _normalizar_linha_recibo(line_clean, debug=debug)

            # Normalizar layout compacto (data+emissão coladas)
            line_clean = _normalizar_layout_compacto(line_clean, debug=debug)

            # Aplicar split para linhas com múltiplas contas
            if re.match(r'^\s*\d', line_clean):
                sub_lines = _split_multiple_accounts(line_clean)
                linhas.extend(sub_lines)
            else:
                linhas.append(line_clean)

        for line in linhas:

            # 0) Linhas vazias
            if not line.strip():
                continue

            # 1) Cabeçalho de colunas - ANTES de tudo (contém "Total" que
            #    confundiria _is_total_line se verificado depois)
            has_header = (
                "Recibo" in line and
                "Vencimento" in line and
                ("Emiss" in line or "Emiss\xe3o" in line) and
                "Conta" in line and
                ("Hist" in line or "Hist\xf3rico" in line) and
                "Valor" in line
            )
            if has_header:
                pos = _find_header_positions(line)
                if pos:
                    col_positions = pos
                    last_row_index_with_record = None
                continue

            # 2) Total lines - ANTES de condomínio para evitar contaminação
            #    Ex: "Quantidade de Unidades...Condominio: 5  Total: 91.937,94"
            if _is_total_line(line):
                last_row_index_with_record = None
                if DEBUG_PARSER and debug:
                    print(f"[DEBUG][LINHA IGNORADA] Motivo: Linha de total")
                    print(f"  Conteudo: {line.strip()[:80]}")
                continue

            # 3) Cabeçalho de condomínio
            cond = _find_condominio(line)
            if cond:
                condominio_atual = cond
                continue

            # 4) Cabeçalho de unidade/bloco
            found = _find_unit_header(line)
            if found:
                bloco_atual, unidade_atual = found
                last_row_index_with_record = None
                continue

            if not col_positions:
                if DEBUG_PARSER and debug:
                    print(f"[DEBUG][LINHA IGNORADA] Motivo: Cabecalho de colunas ainda nao detectado")
                    print(f"  Conteudo: {line.strip()[:80]}")
                continue

            # 4) Detectar se é linha de continuação (sem Recibo/Data no início)
            # Regex permissiva: recibo (5+ dígitos) + qualquer lixo OCR + data DD/MM/YYYY
            # Usa [^\d]*? em vez de char class fixa para tolerar OCR (=, ~, etc.)
            is_main_record_line = bool(re.match(
                r"^\s*\d{5,}[^\d]*?[0-3]?\d/[01]?\d/\d{2,4}",
                line
            ))
            # Continuação: linha que começa com dígitos (conta) seguidos de texto
            # Aceita conta colada ao histórico (ex: "1375BENFEITORIAS 40,00")
            # ou separada por espaço (ex: "671 TAXA LEITURA 5,37")
            is_continuation_line = not is_main_record_line and bool(
                re.match(r"^\s*\d{1,8}\s*\S", line)
            )

            if is_continuation_line:
                # Extrair conta: prefixo numérico de 1-8 dígitos no início
                texto_linha = line.strip()
                m_conta_temp = re.match(r"^(\d{1,8})", texto_linha)
                conta_temp = m_conta_temp.group(1).strip() if m_conta_temp else ""

                resultado_valor = _extrair_valor_monetario_final(line, conta_temp)

                if not resultado_valor:
                    # REGRA 5: Sem valor = continuação de histórico ou linha inválida
                    texto_meio = line.strip()
                    if texto_meio and last_row_index_with_record is not None:
                        registros[last_row_index_with_record]["Histórico"] += " " + texto_meio
                    elif debug:
                        print(f"[WARN] Linha sem valor ignorada: {line[:60]}")
                    continue

                valor, valor_pos = resultado_valor

                texto_antes_valor = line[:valor_pos].strip()
                # Conta: prefixo numérico 1-8 dígitos, mesmo colada ao texto
                m_conta = re.match(r"^(\d{1,8})\s*(.+)$", texto_antes_valor)

                if not m_conta:
                    if debug or DEBUG_PARSER:
                        print(f"[DEBUG][LINHA IGNORADA] Motivo: Conta invalida (continuacao)")
                        print(f"  Conteudo: {line.strip()[:80]}")
                    continue

                conta = m_conta.group(1).strip()
                hist = m_conta.group(2).strip()
                hist = _recolher_medida_quebrada(line, hist)
                descricao, complemento = _formatar_historico(hist)

                # REGRA 3: Usar vencimento_atual se não tiver vencimento próprio
                if last_row_index_with_record is not None:
                    venc_usar = registros[last_row_index_with_record]["Vencimento"]
                    recibo_usar = registros[last_row_index_with_record]["Recibo"]
                    cond_formatado = registros[last_row_index_with_record]["Condomínio"]
                else:
                    # Usar contexto global
                    venc_usar = vencimento_atual
                    recibo_usar = recibo_atual
                    cond_formatado = _formatar_condominio(condominio_atual)

                # REGRA 6: Debug se vencimento vazio
                if not venc_usar and debug:
                    _log_bug("VENC_VAZIO", recibo_usar, venc_usar, line)

                d = {
                    "Condomínio": cond_formatado,
                    "ContaBancaria": cond_formatado,
                    "Bloco": _to_upper(bloco_atual),
                    "Unidade": _formatar_unidade(unidade_atual),
                    "Recibo": recibo_usar,
                    "Vencimento": venc_usar,
                    "Conta": conta,
                    "Histórico": descricao,
                    "Complemento": complemento,
                    "Valor": valor,
                }
                registros.append(d)
                last_row_index_with_record = len(registros) - 1

                # Tracking para reconciliacao
                if _last_extraction_was_ocr:
                    current_recibo_ocr_indices.append(len(registros) - 1)
                # Extrair total_recibo: ultimo valor com virgula na linha
                all_line_vals = [m.group() for m in
                                 re.finditer(r'-?\d{1,3}(?:\.\d{3})*,\d{2}', line)]
                if len(all_line_vals) >= 2 and all_line_vals[-1] != valor:
                    current_recibo_total = all_line_vals[-1]

                continue

            # 5) REGRA 2: Usar parse_recibo_header() para linhas principais
            header_parsed = parse_recibo_header(line)

            if header_parsed:
                recibo = header_parsed["recibo"]
                venc = header_parsed["vencimento"]
                resto = header_parsed["resto"]

                # Reconciliar recibo anterior ao trocar de recibo
                if prev_recibo_id and recibo != prev_recibo_id:
                    _reconciliar_recibo(registros, current_recibo_ocr_indices,
                                        current_recibo_total, debug=debug)
                    current_recibo_ocr_indices = []
                    current_recibo_total = None
                prev_recibo_id = recibo

                # Atualizar contexto global
                recibo_atual = recibo
                vencimento_atual = venc
                # Forçar continuações a usar contexto global atualizado
                last_row_index_with_record = None

                # Extrair conta, histórico e valor do resto da linha
                conta = ""
                hist = ""
                valor = ""

                if resto.strip():
                    m_resto = re.match(r"^(\d+)\s+(.+)$", resto.strip())
                    if m_resto:
                        conta = m_resto.group(1).strip()
                        texto_apos_conta = m_resto.group(2).strip()
                        resultado_valor = _extrair_valor_monetario_final(resto, conta)
                        if resultado_valor:
                            valor, valor_pos_resto = resultado_valor
                            hist = texto_apos_conta[:texto_apos_conta.rfind(valor)].strip() if valor in texto_apos_conta else texto_apos_conta
                            hist = _recolher_medida_quebrada(line, hist)
                        else:
                            hist = texto_apos_conta
            else:
                # Fallback: linha principal não reconhecida
                recibo = ""
                venc = ""
                conta = ""
                hist = ""
                valor = ""

                m_date = re.search(r"([0-3]?\d/[01]?\d/\d{2,4})", line)

                # Tentar extrair conta primeiro
                m_conta_temp = re.match(r"^\s*\d+.*?(\d+)\s+", line)
                conta_temp = m_conta_temp.group(1).strip() if m_conta_temp else ""

                resultado_valor = _extrair_valor_monetario_final(line, conta_temp)

                if m_date and resultado_valor:
                    venc = m_date.group(1)
                    valor, valor_pos = resultado_valor
                    texto_antes = line[:valor_pos].strip()
                    m_conta_fallback = re.match(r'^.*?(\d+)\s+(.+)$', texto_antes)
                    if m_conta_fallback:
                        conta = m_conta_fallback.group(1).strip()
                        hist = m_conta_fallback.group(2).strip()
                        hist = _recolher_medida_quebrada(line, hist)
                else:
                    if DEBUG_PARSER and debug:
                        print(f"[DEBUG][LINHA IGNORADA] Motivo: Regex nao casou (fallback sem data ou valor)")
                        print(f"  Conteudo: {line.strip()[:80]}")
                    continue

            # Validação de dados
            has_valid_value = _looks_like_value(valor) if valor else False
            has_valid_conta = bool(conta and re.match(r'^\d+$', conta))

            if not _is_date(venc) and not (has_valid_conta and has_valid_value):
                if DEBUG_PARSER and debug:
                    print(f"[DEBUG][LINHA IGNORADA] Motivo: Cabecalho invalido (data={venc}, conta={conta}, valor={valor})")
                    print(f"  Conteudo: {line.strip()[:80]}")
                continue

            # Reextrair valor para garantir consistência
            resultado_valor = _extrair_valor_monetario_final(line, conta)
            if resultado_valor:
                valor_correto, valor_pos = resultado_valor
                valor = valor_correto

                if not hist or len(hist) < 3:
                    if conta:
                        m_conta_linha = re.search(r'\b' + re.escape(conta) + r'\b', line)
                        if m_conta_linha:
                            pos_inicio_hist = m_conta_linha.end()
                            hist = line[pos_inicio_hist:valor_pos].strip()
                            hist = _recolher_medida_quebrada(line, hist)

            hist = _recolher_medida_quebrada(line, hist)

            # Normaliza campos
            recibo = recibo.strip()
            venc = venc.strip()
            conta = conta.strip()
            hist = hist.strip()
            valor = valor.strip()

            # REGRA 5: Não criar linha com Valor vazio
            if not valor:
                if debug or DEBUG_PARSER:
                    _log_bug("VALOR_VAZIO", recibo or recibo_atual, venc or vencimento_atual, line)
                    print(f"[DEBUG][LINHA IGNORADA] Motivo: Valor nao encontrado")
                    print(f"  Conteudo: {line.strip()[:80]}")
                continue

            # Validação final: conta deve ser numérica
            if conta and not re.match(r"^\d+$", conta):
                m_conta_hist = re.match(r"^(\d+)\s+(.+)$", hist)
                if m_conta_hist:
                    conta = m_conta_hist.group(1).strip()
                    hist = m_conta_hist.group(2).strip()
                    hist = _recolher_medida_quebrada(line, hist)

            cond_formatado = _formatar_condominio(condominio_atual)
            descricao, complemento = _formatar_historico(hist)

            # REGRA 3: Usar vencimento_atual se venc vazio
            if not venc:
                venc = vencimento_atual
            if not recibo:
                recibo = recibo_atual

            # REGRA 6: Debug se vencimento vazio
            if not venc and debug:
                _log_bug("VENC_VAZIO_FINAL", recibo, venc, line)

            d = {
                "Condomínio": cond_formatado,
                "ContaBancaria": cond_formatado,
                "Bloco": _to_upper(bloco_atual),
                "Unidade": _formatar_unidade(unidade_atual),
                "Recibo": recibo,
                "Vencimento": venc,
                "Conta": conta,
                "Histórico": descricao,
                "Complemento": complemento,
                "Valor": valor,
            }
            registros.append(d)
            last_row_index_with_record = len(registros) - 1

            # Tracking para reconciliacao
            if _last_extraction_was_ocr:
                current_recibo_ocr_indices.append(len(registros) - 1)
            all_line_vals = [m.group() for m in
                             re.finditer(r'-?\d{1,3}(?:\.\d{3})*,\d{2}', line)]
            if len(all_line_vals) >= 2 and all_line_vals[-1] != valor:
                current_recibo_total = all_line_vals[-1]

        # Reconciliar ultimo recibo
        _reconciliar_recibo(registros, current_recibo_ocr_indices,
                            current_recibo_total, debug=debug)

        # Log de debug final
        if debug:
            stats = _get_debug_stats()
            print(f"[DEBUG] parse_recibo_header stats:")
            print(f"        Linhas analisadas: {stats['total_linhas']}")
            print(f"        Headers aceitos: {stats['headers_aceitos']}")
            print(f"        Headers rejeitados: {stats['headers_rejeitados']}")
            if _debug_bugs:
                print(f"        Bugs detectados: {len(_debug_bugs)}")

            # Diagnostico avancado: varrer TODAS as linhas e reportar candidatas nao parseadas
            _diagnosticar_linhas_nao_parseadas(texto, registros)

        return registros


# ==================== TESTES ====================
if __name__ == "__main__":
    print("=" * 70)
    print("TESTE: is_noise_line (detecção de ruído)")
    print("=" * 70)

    testes_noise = [
        # (linha, esperado, descrição)
        ("Relação Analítica de Pendentes", True, "Título do relatório"),
        ("Período de: 01/01/2020 a 31/12/2020", True, "Período"),
        ("Legenda: A=Aberto J=Jurídico", True, "Legenda"),
        ("Emitido em 10/12/2025", True, "Emitido em"),
        ("Página 1 de 5", True, "Paginação"),
        ("P4gina 2", True, "Paginação OCR"),
        ("Genesis - Especializada em Condomínios", True, "Rodapé"),
        ("(Recibo Vencimento Emissão Conta Histórico Valor)", True, "Cabeçalho colunas"),
        ("5Bl0o3c5o5: 0 Unid1a de:1 01/0037", True, "Texto corrompido"),
        ("800384  10/12/2025 13826  7 CONDOMINIO", False, "Linha válida"),
        ("671 TAXA LEITURA AGUA 5,37", False, "Linha de detalhe"),
        ("", True, "Linha vazia"),
        ("  ", True, "Só espaços"),
    ]

    for linha, esperado, desc in testes_noise:
        resultado = is_noise_line(linha)
        status = "OK" if resultado == esperado else "FALHOU"
        print(f"  {status}: {desc}")
        print(f"       Linha: '{linha[:50]}'")
        print(f"       Resultado: {resultado} (esperado: {esperado})")
        print()

    print("=" * 70)
    print("TESTE: _converter_ocr_para_valor")
    print("=" * 70)

    testes_ocr = [
        ("431", "4,31", "431 -> 4,31"),
        ("11", "0,11", "11 -> 0,11"),
        ("037", "0,37", "037 -> 0,37"),
        ("2531", "25,31", "2531 -> 25,31"),
        ("112895", "1.128,95", "112895 -> 1.128,95"),
        ("0", None, "Zero -> None"),
        ("", None, "Vazio -> None"),
    ]

    for token, esperado, desc in testes_ocr:
        resultado = _converter_ocr_para_valor(token)
        status = "OK" if resultado == esperado else "FALHOU"
        print(f"  {status}: {desc}")
        print(f"       Resultado: '{resultado}' (esperado: '{esperado}')")
        print()

    print("=" * 70)
    print("TESTE: _extrair_valor_monetario_final (Valor do item vs Total)")
    print("=" * 70)

    testes_valor = [
        # === NON-M3: Pegar PRIMEIRO valor (não o Total) ===
        ("671 TAXA DA LEITURA  6,97  1.251,63", "671", "6,97",
         "2 valores -> PRIMEIRO (6,97) não Total (1.251,63)"),
        ("671 TAXA DA LEITURA  7,28  1.314,18", "671", "7,28",
         "2 valores -> PRIMEIRO (7,28)"),
        ("671 TAXA DA LEITURA  7,81  131471  85.380,37", "671", "7,81",
         "valor + OCR total + grand total -> PRIMEIRO (7,81)"),
        ("1305 MELHORIAS/BENFEITORIAS 1/5  65,09  1.261,19", "1305", "65,09",
         "item + total recibo -> PRIMEIRO (65,09)"),
        # === NON-M3: Valor único (sem ambiguidade) ===
        ("49 FUNDO RESERVA DEZEMBRO/2025  68,46", "49", "68,46",
         "Valor único normal"),
        ("7 CONDOMINIO DEZEMBRO/2025  1.369,14", "7", "1.369,14",
         "Valor único com milhar"),
        ("671 TAXA DA LEITURA  7,81", "671", "7,81",
         "Valor único sem total"),
        # === M3 + valor formatado após M3 ===
        ("671 CONSUMO DE AGUA NOV/2025 17,39 M3  171,06  1.616,47  1.616,47", "671", "171,06",
         "M3 + primeiro valor após M3"),
        ("563 CONSUMO AGUA - REF. FEV./19 0,205 M3  1,27  364,01", "563", "1,27",
         "M3 + valor + total -> primeiro após M3"),
        # === M3 + OCR inteiro após M3 ===
        ("671 CONSUMO DE AGUA SETEMBRO/22 0,514 M3  431", "671", "4,31",
         "M3 + OCR 431 -> 4,31"),
        ("671 CONSUMO DE AGUA OUTUBRO/2022 0,133 M3  11  1.322,16", "671", "0,11",
         "M3 + OCR 11 -> 0,11 (não 1.322,16)"),
        ("671 CONSUMO DE AGUA JUNHO/2023 0,04 M3  037  1.350,14", "671", "0,37",
         "M3 + OCR 037 -> 0,37 (não 1.350,14)"),
        # === M corrompido (sem expoente ³/3/?) ===
        ("671 CONSUMO DE AGUA MARCO/2022 0,146 M  0,68  1.184,59", "671", "0,68",
         "M corrompido -> primeiro valor após M"),
        # === FALLBACK: OCR inteiro no fim ===
        ("892 FECH., ENTRADA/GAR PC.01/06  2531", "892", "25,31",
         "OCR conta permitida"),
        ("892 FECH., ENTRADA/GAR PC.02/06  25,31", "892", "25,31",
         "Valor normal conta 892"),
        ("999 TAXA ESPECIAL 2531", "999", None,
         "OCR conta NÃO permitida -> None"),
    ]

    total_ok = 0
    total_falhou = 0
    for linha, conta, esperado, desc in testes_valor:
        resultado = _extrair_valor_monetario_final(linha, conta)
        if esperado is None:
            ok = resultado is None
            status = "OK" if ok else "FALHOU"
            print(f"  {status}: {desc}")
            print(f"       Valor: {resultado} (esperado: None)")
        else:
            valor_obtido = resultado[0] if resultado else None
            ok = valor_obtido == esperado
            status = "OK" if ok else "FALHOU"
            print(f"  {status}: {desc}")
            print(f"       Valor: '{valor_obtido}' (esperado: '{esperado}')")
        if ok:
            total_ok += 1
        else:
            total_falhou += 1
        print()

    print(f"  RESULTADO: {total_ok} OK, {total_falhou} FALHOU de {total_ok + total_falhou} testes")
    print()

    print("=" * 70)
    print("TESTE: parse_recibo_header() - REGEX FORTE")
    print("=" * 70)

    _reset_debug_stats()

    testes_header = [
        ("800384  10/12/2025 13826  7 CONDOMINIO DEZEMBRO/2025  1.369,14",
         {"recibo": "800384", "vencimento": "10/12/2025", "emissao": "13826"},
         "Formato padrão"),
        ("302807 — ) 10/04/2019 7854 7 CONDOMINIO ABRIL/2019 924,50",
         {"recibo": "302807", "vencimento": "10/04/2019", "emissao": "7854"},
         "Com marcador"),
        ("671 TAXA LEITURA AGUA 5,37",
         None,
         "Linha de detalhe (não é header)"),
        ("49 FUNDO RESERVA DEZEMBRO/2025 68,46",
         None,
         "Linha de detalhe 2 dígitos"),
        # Layout compacto: data+emissao coladas
        ("15262298 10/05/2025 256394 7 CONDOMINIO MAI/2025 1.061,90",
         {"recibo": "15262298", "vencimento": "10/05/2025", "emissao": "256394"},
         "Compacto (pos-normalizacao)"),
        ("14736459 10/09/2023 220476 7 CONDOMINIO SET/2023 417,12",
         {"recibo": "14736459", "vencimento": "10/09/2023", "emissao": "220476"},
         "Compacto com tag removida"),
    ]

    for linha, esperado, desc in testes_header:
        resultado = parse_recibo_header(linha)
        if esperado is None:
            status = "OK" if resultado is None else "FALHOU"
            print(f"  {status}: {desc}")
            print(f"       Resultado: {resultado} (esperado: None)")
        else:
            if resultado:
                match = (resultado["recibo"] == esperado["recibo"] and
                        resultado["vencimento"] == esperado["vencimento"] and
                        resultado["emissao"] == esperado["emissao"])
                status = "OK" if match else "FALHOU"
            else:
                status = "FALHOU"
            print(f"  {status}: {desc}")
            if resultado:
                print(f"       Recibo: {resultado['recibo']} (esperado: {esperado['recibo']})")
                print(f"       Venc: {resultado['vencimento']} (esperado: {esperado['vencimento']})")
            else:
                print(f"       Resultado: None (esperado: {esperado})")
        print()

    stats = _get_debug_stats()
    print("=" * 70)
    print(f"DEBUG STATS: {stats}")
    print("=" * 70)

    # ==================== TESTES: NOVAS FUNCOES ====================
    print()
    print("=" * 70)
    print("TESTE: _is_pure_header_footer")
    print("=" * 70)

    testes_pure = [
        ("Relacao Analitica de Pendentes", True, "Cabecalho sem valor"),
        ("Legenda: (A) - Acordo", True, "Legenda sem valor"),
        ("Pagina 2", True, "Paginacao sem valor"),
        ("", True, "Vazio"),
        ("Quantidade de Unidades inadimplentes do Condominio: 5  Total:  91.937,94",
         False, "Tem valor monetario -> NAO descartar"),
        ("800384  10/12/2025 13826  7 CONDOMINIO  1.369,14",
         False, "Linha recibo com valor -> NAO descartar"),
        ("671 TAXA LEITURA AGUA  5,37", False, "Detalhe com valor"),
    ]

    for linha, esperado, desc in testes_pure:
        resultado = _is_pure_header_footer(linha)
        status = "OK" if resultado == esperado else "FALHOU"
        print(f"  {status}: {desc}")
        if resultado != esperado:
            print(f"       Linha: '{linha[:60]}'")
            print(f"       Resultado: {resultado} (esperado: {esperado})")
        print()

    print("=" * 70)
    print("TESTE: _is_corrupted_line")
    print("=" * 70)

    testes_corrupt = [
        ("5Bl0o3c5o5:5  0 Unid1a de:1 01/0037", True, "Corrompida tipica"),
        ("B5l5o6c8o8:5  0 Un1id1a de:1 01/0037", True, "Corrompida variante"),
        ("800384  10/12/2025 13826  7 CONDOMINIO", False, "Linha normal"),
        ("671 TAXA LEITURA AGUA  5,37", False, "Detalhe normal"),
        ("49 FUNDO RESERVA DEZEMBRO/2025  68,46", False, "Detalhe 2 digitos"),
    ]

    for linha, esperado, desc in testes_corrupt:
        resultado = _is_corrupted_line(linha)
        status = "OK" if resultado == esperado else "FALHOU"
        print(f"  {status}: {desc}")
        if resultado != esperado:
            print(f"       Resultado: {resultado} (esperado: {esperado})")
        print()

    print("=" * 70)
    print("TESTE: _normalizar_linha_recibo")
    print("=" * 70)

    testes_norm = [
        ("543306 -- ) 10/05/2022 10521 7 CONDOMINIO MAIO/2022 1.128,95",
         "543306 10/05/2022 10521 7 CONDOMINIO MAIO/2022 1.128,95",
         "Marcadores -- )"),
        ("486836 -- =J 10/03/2021 9477  7 CONDOMINIO MARGO/2021",
         "486836 10/03/2021 9477  7 CONDOMINIO MARGO/2021",
         "Marcadores -- =J"),
        ("800384  10/12/2025 13826  7 CONDOMINIO DEZEMBRO/2025  1.369,14",
         "800384  10/12/2025 13826  7 CONDOMINIO DEZEMBRO/2025  1.369,14",
         "Sem marcadores -> inalterado"),
        ("671 TAXA LEITURA AGUA  5,37",
         "671 TAXA LEITURA AGUA  5,37",
         "Nao-recibo -> inalterado"),
    ]

    for linha, esperado, desc in testes_norm:
        # Substituir em-dash real se necessario
        linha_real = linha.replace("--", "\u2014")
        esperado_real = esperado
        resultado = _normalizar_linha_recibo(linha_real)
        status = "OK" if resultado == esperado_real else "FALHOU"
        print(f"  {status}: {desc}")
        if resultado != esperado_real:
            print(f"       Obtido:   '{resultado[:70]}'")
            print(f"       Esperado: '{esperado_real[:70]}'")
        print()

    # ==================== TESTE: _normalizar_layout_compacto ====================
    print("=" * 70)
    print("TESTE: _normalizar_layout_compacto")
    print("=" * 70)

    testes_compact = [
        ("15262298 10/05/2025256394 7 CONDOMINIO MAI/2025 1.061,90",
         "15262298 10/05/2025 256394 7 CONDOMINIO MAI/2025 1.061,90",
         "Data+emissao coladas"),
        ("14736459 10/09/2023220476 7 CONDOMINIO SET/2023 417,12",
         "14736459 10/09/2023 220476 7 CONDOMINIO SET/2023 417,12",
         "Data+emissao coladas (2)"),
        ("800384  10/12/2025 13826  7 CONDOMINIO DEZEMBRO/2025  1.369,14",
         "800384  10/12/2025 13826  7 CONDOMINIO DEZEMBRO/2025  1.369,14",
         "Ja separado -> inalterado"),
        ("671 TAXA LEITURA AGUA  5,37",
         "671 TAXA LEITURA AGUA  5,37",
         "Detalhe -> inalterado"),
    ]

    for linha, esperado, desc in testes_compact:
        resultado = _normalizar_layout_compacto(linha)
        status = "OK" if resultado == esperado else "FALHOU"
        print(f"  {status}: {desc}")
        if resultado != esperado:
            print(f"       Obtido:   '{resultado[:70]}'")
            print(f"       Esperado: '{esperado[:70]}'")
        print()

    # ==================== TESTE: Layout compacto (integracao mini) ====================
    print("=" * 70)
    print("TESTE: Layout compacto (integracao mini)")
    print("=" * 70)

    texto_compacto = """Relacao Analitica de Pendentes
Periodo de: 01/01/2023 a 31/12/2025
[Condominio: 1604 - RESIDENCIAL TESTE]
Bloco: 0  Unidade: 001
Recibo Vencimento Emissao Conta Historico Valor
15262298 10/05/2025256394 7 CONDOMINIO MAI/2025 1.061,90
15771623 10/07/2025259797 7 CONDOMINIO JUL/2025 1.280,66
14736459 AE10/09/2023220476 7 CONDOMINIO SET/2023 417,12
671 TAXA DA LEITURA  6,97  1.251,63
49 FUNDO RESERVA MAI/2025 53,10
1305 MELHORIAS/BENFEITORIAS 3/5 65,09
"""
    registros_compact = Extractor.extract_records(texto_compacto, debug=True)
    print(f"\n  Total registros compactos: {len(registros_compact)}")

    # Verificar recibos extraidos
    recibos_ok = True
    for rec_id, venc_esp, conta_esp, valor_esp in [
        ("15262298", "10/05/2025", "7", "1.061,90"),
        ("15771623", "10/07/2025", "7", "1.280,66"),
        ("14736459", "10/09/2023", "7", "417,12"),
    ]:
        match = [r for r in registros_compact
                 if r["Recibo"] == rec_id and r["Vencimento"] == venc_esp
                 and r["Conta"] == conta_esp and r["Valor"] == valor_esp]
        status = "OK" if match else "FALHOU"
        if not match:
            recibos_ok = False
        print(f"  {status}: Recibo {rec_id} Ct={conta_esp} Val={valor_esp}")

    # Verificar continuacoes
    cont_671 = [r for r in registros_compact if r["Conta"] == "671" and r["Valor"] == "6,97"]
    cont_49 = [r for r in registros_compact if r["Conta"] == "49" and r["Valor"] == "53,10"]
    cont_1305 = [r for r in registros_compact if r["Conta"] == "1305" and r["Valor"] == "65,09"]
    print(f"  {'OK' if cont_671 else 'FALHOU'}: Continuacao 671 valor=6,97")
    print(f"  {'OK' if cont_49 else 'FALHOU'}: Continuacao 49 valor=53,10")
    print(f"  {'OK' if cont_1305 else 'FALHOU'}: Continuacao 1305 valor=65,09")

    assert recibos_ok, "FALHOU: Recibos compactos nao extraidos!"
    assert cont_671, "FALHOU: Cont 671 nao extraida!"
    assert cont_49, "FALHOU: Cont 49 nao extraida!"
    assert cont_1305, "FALHOU: Cont 1305 nao extraida!"
    print("  -> OK: Layout compacto funciona")

    # Amostra
    print(f"\n  Amostra registros compactos:")
    for i, r in enumerate(registros_compact):
        print(f"    [{i}] Rec={r['Recibo']} Venc={r['Vencimento']} "
              f"Ct={r['Conta']} Hist={r['Histórico'][:30]} Val={r['Valor']}")

    # ==================== TESTE: Conta colada ao historico ====================
    print("=" * 70)
    print("TESTE: Conta colada ao historico (sem espaco)")
    print("=" * 70)

    texto_colado = """Relacao Analitica de Pendentes
[Condominio: 2000 - RESIDENCIAL TESTE COLADO]
Bloco: 1  Unidade: 010
Recibo Vencimento Emissao Conta Historico Valor
500001 10/01/2025 90001 7 CONDOMINIO JAN/2025 800,00
1375BENFEITORIAS 40,00 1.187,76
176510/10 REFORMA DA PORTARIA II 28,31
49 FUNDO RESERVA JAN/2025 40,00
"""
    registros_colado = Extractor.extract_records(texto_colado, debug=True)
    print(f"\n  Total registros colados: {len(registros_colado)}")

    # Verificar recibo principal
    rec_7 = [r for r in registros_colado if r["Conta"] == "7" and r["Valor"] == "800,00"]
    print(f"  {'OK' if rec_7 else 'FALHOU'}: Recibo 500001 Ct=7 Val=800,00")
    assert rec_7, "FALHOU: Recibo principal nao extraido!"

    # Verificar conta colada 1375
    rec_1375 = [r for r in registros_colado if r["Conta"] == "1375" and r["Valor"] == "40,00"]
    print(f"  {'OK' if rec_1375 else 'FALHOU'}: Conta colada 1375 Val=40,00")
    assert rec_1375, "FALHOU: Conta 1375 colada nao extraida!"
    if rec_1375:
        hist_1375 = rec_1375[0].get("Histórico", "")
        print(f"    Historico: '{hist_1375}'")
        assert "BENFEITORIA" in hist_1375.upper(), f"FALHOU: Historico deveria conter BENFEITORIA: '{hist_1375}'"

    # Verificar conta colada 1765
    rec_1765 = [r for r in registros_colado if r["Conta"] == "176510" and r["Valor"] == "28,31"]
    print(f"  {'OK' if rec_1765 else 'FALHOU'}: Conta colada 176510 Val=28,31")
    assert rec_1765, "FALHOU: Conta 176510 colada nao extraida!"
    if rec_1765:
        hist_1765 = rec_1765[0].get("Histórico", "")
        print(f"    Historico: '{hist_1765}'")

    # Verificar continuacao normal com espaco
    rec_49 = [r for r in registros_colado if r["Conta"] == "49" and r["Valor"] == "40,00"]
    print(f"  {'OK' if rec_49 else 'FALHOU'}: Continuacao normal 49 Val=40,00")
    assert rec_49, "FALHOU: Conta 49 normal nao extraida!"

    print("  -> OK: Conta colada ao historico funciona")

    # Amostra
    print(f"\n  Amostra registros colados:")
    for i, r in enumerate(registros_colado):
        print(f"    [{i}] Rec={r['Recibo']} Ct={r['Conta']} "
              f"Hist={r['Histórico'][:35]} Val={r['Valor']}")

    # ==================== TESTE INTEGRACAO: EXTRAÇÃO COMPLETA ====================
    print("=" * 70)
    print("TESTE INTEGRACAO: Extração com debug TXT")
    print("=" * 70)

    import os
    debug_file = os.path.join(
        os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
        "uploads",
        "20260210_140904_01604 - RESIDENCIAL SERENITA - INADIM. _1_.pdf.debug.txt"
    )

    if os.path.exists(debug_file):
        with open(debug_file, "r", encoding="utf-8") as f:
            texto_debug = f.read()

        registros_teste = Extractor.extract_records(texto_debug, debug=True)
        print(f"\n  Total registros: {len(registros_teste)}")

        # Verificacao 1: Conta 7 aparece com Vencimento e Valor preenchidos
        conta7 = [r for r in registros_teste if r["Conta"] == "7"]
        conta7_sem_venc = [r for r in conta7 if not r["Vencimento"]]
        conta7_sem_valor = [r for r in conta7 if not r["Valor"]]
        print(f"  Conta 7: {len(conta7)} registros")
        print(f"  Conta 7 sem Vencimento: {len(conta7_sem_venc)} (esperado: 0)")
        print(f"  Conta 7 sem Valor: {len(conta7_sem_valor)} (esperado: 0)")
        assert len(conta7_sem_venc) == 0, "FALHOU: Conta 7 sem Vencimento!"
        assert len(conta7_sem_valor) == 0, "FALHOU: Conta 7 sem Valor!"
        print("  -> OK: Todas conta 7 tem Vencimento + Valor")

        # Verificacao 2: Linhas M3 nao truncadas
        m3_registros = [r for r in registros_teste
                        if "M3" in r.get("Histórico", "") or "M3" in r.get("Histórico", "").upper()]
        m3_truncados = [r for r in m3_registros
                        if r["Histórico"].rstrip().endswith(",")
                        or r["Histórico"].rstrip().endswith("0,")]
        print(f"\n  Registros com M3: {len(m3_registros)}")
        print(f"  M3 truncados: {len(m3_truncados)} (esperado: 0)")
        if m3_truncados:
            for r in m3_truncados[:3]:
                print(f"    TRUNCADO: '{r['Histórico']}'")
        assert len(m3_truncados) == 0, "FALHOU: M3 truncado!"
        print("  -> OK: Nenhum M3 truncado")

        # Verificacao 3: Sem valores vazios
        sem_valor = [r for r in registros_teste if not r["Valor"]]
        print(f"\n  Registros sem Valor: {len(sem_valor)} (esperado: 0)")
        assert len(sem_valor) == 0, "FALHOU: Registros sem Valor!"
        print("  -> OK: Todos registros tem Valor")

        # Verificacao 4: Valor vs Total (spot-check linhas com 2+ valores)
        # "671 TAXA DA LEITURA  6,97  1.251,63" -> Valor deve ser 6,97
        check_671 = [r for r in registros_teste
                     if r["Conta"] == "671" and "TAXA" in r.get("Histórico", "")
                     and r["Valor"] == "6,97"]
        check_1305 = [r for r in registros_teste
                      if r["Conta"] == "1305" and "65,09" == r["Valor"]]
        print(f"\n  Spot-check 671 TAXA valor=6,97: {len(check_671)} encontrados")
        print(f"  Spot-check 1305 valor=65,09: {len(check_1305)} encontrados")

        # Verificacao 5: Nenhum condominio contaminado (ex: "5")
        condominios = set(r["Condomínio"] for r in registros_teste if r.get("Condomínio"))
        print(f"\n  Condominios unicos: {condominios}")
        assert "0005" not in condominios and "5" not in condominios, \
            "FALHOU: Condominio contaminado com '5'!"
        print("  -> OK: Sem contaminacao de condominio")

        # Verificacao 6: 4 casos obrigatorios (quebra de pagina)
        print("\n  --- Verificacao 6: Casos obrigatorios ---")

        # Caso 1: Recibo 503555 conta 7 CONDOMINIO JULHO/2021 1.065,05
        caso1 = [r for r in registros_teste
                 if r["Recibo"] == "503555" and r["Conta"] == "7"
                 and r["Valor"] == "1.065,05"]
        print(f"  Caso 1 (503555 ct7 1.065,05): {len(caso1)} encontrados")
        assert len(caso1) >= 1, "FALHOU: Recibo 503555 ct7 nao encontrado!"
        print("  -> OK")

        # Caso 2: FUNDO RESERVA JULHO/2021 53,26 (da linha corrompida 152)
        caso2 = [r for r in registros_teste
                 if r["Conta"] == "49" and r["Valor"] == "53,26"
                 and "RESERVA" in r.get("Histórico", "").upper()]
        print(f"  Caso 2 (ct49 RESERVA 53,26): {len(caso2)} encontrados")
        assert len(caso2) >= 1, "FALHOU: FUNDO RESERVA 53,26 nao recuperado!"
        print("  -> OK")

        # Caso 3: MELHORIAS/BENFEITORIAS 3/5 65,09 (da linha corrompida 222)
        caso3 = [r for r in registros_teste
                 if r["Conta"] == "1305" and r["Valor"] == "65,09"
                 and "BENFEITORIA" in r.get("Histórico", "").upper()]
        print(f"  Caso 3 (ct1305 BENFEIT 65,09): {len(caso3)} encontrados")
        assert len(caso3) >= 1, "FALHOU: MELHORIAS/BENFEITORIAS 65,09 nao recuperado!"
        print("  -> OK")

        # Caso 4: CONDOMINIO AGOSTO/2023 1.185,40 (da linha corrompida 292)
        caso4 = [r for r in registros_teste
                 if r["Conta"] == "7" and r["Valor"] == "1.185,40"
                 and "CONDOM" in r.get("Histórico", "").upper()]
        print(f"  Caso 4 (ct7 CONDOM 1.185,40): {len(caso4)} encontrados")
        assert len(caso4) >= 1, "FALHOU: CONDOMINIO 1.185,40 nao recuperado!"
        print("  -> OK: Todos 4 casos obrigatorios presentes")

        # Verificacao 7: Valor "11" reconciliado para "1,11"
        print("\n  --- Verificacao 7: Reconciliacao OCR ---")
        # Recibo 57790 (NOVEMBRO/2022), conta 671 CONSUMO AGUA OUTUBRO/2022
        caso_11 = [r for r in registros_teste
                   if r["Recibo"] == "57790" and r["Conta"] == "671"
                   and "OUTUBRO/2022" in r.get("Histórico", "").upper()]
        if caso_11:
            valor_obtido = caso_11[0]["Valor"]
            print(f"  Recibo 57790 ct671 OUTUBRO/2022: valor='{valor_obtido}' (esperado: '1,11')")
            assert valor_obtido == "1,11", f"FALHOU: Valor deveria ser 1,11 mas e {valor_obtido}"
            print("  -> OK: Reconciliacao correta (11 -> 1,11)")
        else:
            print("  [WARN] Recibo 57790 ct671 OUTUBRO/2022 nao encontrado")

        # Amostra de registros
        print(f"\n  Amostra (3 primeiros registros):")
        for i, r in enumerate(registros_teste[:3]):
            print(f"    [{i}] Rec={r['Recibo']} Venc={r['Vencimento']} "
                  f"Ct={r['Conta']} Hist={r['Histórico'][:35]} Val={r['Valor']}")
    else:
        print(f"  [SKIP] Arquivo debug nao encontrado: {debug_file}")
