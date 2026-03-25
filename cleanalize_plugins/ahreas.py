# cleanalize_plugins/ahreas.py
"""
Extractor para "Relatório de Unidades - Completo" (AHREAS)

O parser:
- Limpa cabeçalhos/rodapés (ex.: "Relatório de Unidades - Completo", "Condomínio:", "Emitido em ... Página X de Y").
- Divide o texto em blocos de unidade (de "Bloco:" até o próximo "Bloco:").
- Extrai campos principais com regex tolerantes a variações comuns e OCR.
- Faz deduplicação por (Bloco, UnidadeCodigo, CodigoCliente) e completa lacunas quando encontra repetição.
- Separa endereço em componentes (logradouro, nome, número, complemento, bairro, cidade, UF, CEP).
- Extrai dados do locatário quando presente (seção "Unidade alugada").
"""

import re
from typing import List, Dict, Tuple

NOME = "ahreas"

# Tipos de logradouro reconhecidos (ordenados por tamanho desc na hora de usar)
TIPOS_LOGRADOURO = [
    # Formas por extenso
    "Alameda", "Avenida", "Estrada", "Passagem", "Rodovia", "Travessa",
    "Calçada", "Caminho", "Chácara", "Fazenda", "Ladeira", "Parque",
    "Viaduto", "Viela", "Praça", "Largo", "Vila", "Beco", "Rua",
    # Abreviações comuns
    "ALAME", "ESTR", "Estra", "EST", "ROD", "AV", "AL", "PÇ", "TR",
]
_TIPOS_SORTED = sorted(TIPOS_LOGRADOURO, key=len, reverse=True)


# Caracteres acentuados para tornar regex tolerante a encoding quebrado
_ACCENTED = frozenset('áàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇñÑ')


def _a(s: str) -> str:
    """Torna string tolerante a encoding (troca acentos por '.' no regex).

    pdftotext no Windows pode emitir cp1252, e o PdfEngine lê como UTF-8
    com errors='replace', convertendo acentos em U+FFFD. Usando '.' no
    lugar de cada acento, o regex funciona em ambos os cenários.
    """
    return ''.join('.' if c in _ACCENTED else c for c in s)


# ========================= Normalizações auxiliares =========================

def _normalize_text(s: str) -> str:
    """Normalização leve para textos (útil pós-OCR também)."""
    if not s:
        return s
    s = s.replace("\u00A0", " ")
    s = s.replace("\u2013", "-").replace("\u2014", "-")
    s = re.sub(r"[ \t]+", " ", s)
    s = re.sub(r"\s+([,:;])", r"\1", s)
    return s.strip()


def _normalize_light(s: str) -> str:
    """Normalização que PRESERVA espaços múltiplos (limites entre campos tabulares).

    Diferente de _normalize_text, NÃO colapsa espaços múltiplos em um só.
    Isso é essencial para que regex com (?=\\s{2,}|$) funcione na detecção
    de limites entre campos tabulares do PDF.
    """
    if not s:
        return s
    s = s.replace("\u00A0", " ")
    s = s.replace("\u2013", "-").replace("\u2014", "-")
    return s.strip()


def _clean_head_foot(s: str) -> str:
    """Remove cabeçalhos e rodapés que se repetem a cada página."""
    lines = s.splitlines()
    out = []
    for ln in lines:
        l = ln.strip()
        if not l:
            out.append(ln)
            continue
        if re.match(_a(r"Relatório de Unidades"), l):
            continue
        if re.match(_a(r"Condomínio:"), l):
            continue
        if "Emitido em" in l and "Página" in l and "de" in l:
            continue
        out.append(ln)
    return "\n".join(out)


def _extract_first(pattern: str, text: str, flags=0) -> str:
    m = re.search(pattern, text, flags)
    return m.group(1).strip() if m else ""


def _all(pattern: str, text: str, flags=0) -> List[str]:
    return re.findall(pattern, text, flags)


def _merge_dict(base: Dict[str, str], add: Dict[str, str], prefer_add: bool = True) -> Dict[str, str]:
    """Completa lacunas em base com dados de add."""
    for k, v in add.items():
        if not v:
            continue
        if k not in base or not base[k] or prefer_add:
            if prefer_add or not base.get(k):
                base[k] = v
    return base


# ========================= Address parser =========================

def _parse_address(addr_str: str) -> Dict[str, str]:
    """
    Separa endereço brasileiro em componentes.
    Formato: [Tipo] [NomeRua] [Numero] [Compl] - [Bairro] - [Cidade] - [UF] - CEP: [CEP]
    """
    r = {
        'logradouro': '', 'endereco': '', 'numero': '', 'complemento': '',
        'bairro': '', 'cidade': '', 'estado': '', 'cep': '',
    }
    if not addr_str or not addr_str.strip():
        return r

    s = addr_str.strip()

    # 1. Extrair CEP do final
    m = re.search(r'CEP:\s*(\d{5}-?\d{3})\s*$', s)
    if m:
        r['cep'] = m.group(1)
        s = s[:m.start()].strip(' -\t')

    # 2. Separar por " - "
    parts = [p.strip() for p in s.split(' - ') if p.strip()]
    if not parts:
        return r

    # 3. UF (2 letras) do final
    if len(parts) >= 2 and re.match(r'^[A-Za-z]{2}$', parts[-1]):
        r['estado'] = parts.pop().upper()

    # 4. Cidade
    if len(parts) >= 2:
        r['cidade'] = parts.pop()

    # 5. Se ainda restam 2+ partes, a última é Bairro
    if len(parts) >= 2:
        r['bairro'] = parts.pop()

    # 6. O que sobrou forma o endereço (rua + número + complemento)
    street = ' - '.join(parts)
    if not street:
        return r

    # 7. Identificar tipo de logradouro no início
    for tipo in _TIPOS_SORTED:
        pat = r'^(' + _a(re.escape(tipo)) + r')[\s\.]'
        m_t = re.match(pat, street, re.IGNORECASE)
        if m_t:
            r['logradouro'] = m_t.group(1)
            street = street[m_t.end():].lstrip('. ')
            break

    # 8. Extrair número (primeiro \d+, opcionalmente com range "até" ou "/")
    m_n = re.search(_a(r'(\d+(?:\s*(?:até|a|/)\s*\d+)*)'), street)
    if m_n:
        r['endereco'] = street[:m_n.start()].strip()
        r['numero'] = m_n.group(1).strip()
        rest = street[m_n.end():].strip()
        rest = re.sub(r'^[\s\-]+', '', rest).strip()
        if rest:
            r['complemento'] = rest
    else:
        r['endereco'] = street

    return r


# ========================= Phone/email helpers =========================

def _extract_phones_emails(section_text: str) -> Dict[str, str]:
    """Extrai telefones e e-mails de um trecho de texto."""
    if not section_text:
        return {'comercial': '', 'residencial': '', 'celular': '', 'emails': ''}

    tels_com = _all(
        r"Telefone comercial\s*-\s*([0-9\(\)\+\- \.]+?)(?=\s{2,}|\s+-\s+[A-Za-z]|\n|$)",
        section_text, re.I | re.M
    )
    tels_res = _all(
        r"Telefone residencial\s*-\s*([0-9\(\)\+\- \.]+?)(?=\s{2,}|\s+-\s+[A-Za-z]|\n|$)",
        section_text, re.I | re.M
    )
    cel_list = _all(
        r"Celular\s*-\s*([0-9\(\)\+\- \.]+?)(?=\s{2,}|\s+-\s+[A-Za-z]|\n|$)",
        section_text, re.I | re.M
    )
    emails = _all(
        r"E-mail\s*-\s*([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})",
        section_text, re.I
    )

    def _join(items):
        cleaned = sorted(set(_normalize_text(x) for x in items if x.strip()))
        return " | ".join(cleaned)

    return {
        'comercial': _join(tels_com),
        'residencial': _join(tels_res),
        'celular': _join(cel_list),
        'emails': _join(emails),
    }


# ========================= Núcleo =========================

class Extractor:
    @staticmethod
    def matches(texto: str) -> bool:
        """Detecção simples: presença de 'Bloco:' e 'Unidade:' no texto limpo."""
        if not texto:
            return False
        texto = _normalize_text(texto)
        return ("Bloco:" in texto and "Unidade:" in texto)

    @staticmethod
    def _split_unidades(texto: str) -> List[str]:
        """
        Divide em blocos de unidade. Cada bloco começa em uma linha com:
          Bloco: <algo>  Unidade: <código> - <nome>  Código do cliente: <n>
        e vai até antes da próxima linha que comece com "Bloco:".
        """
        texto = _clean_head_foot(_normalize_light(texto))
        starts = [m.start() for m in re.finditer(r"(?m)^.*Bloco:\s*\S.*Unidade:\s*\S", texto)]
        if not starts:
            return []
        blocks = []
        for i, st in enumerate(starts):
            en = starts[i + 1] if i + 1 < len(starts) else len(texto)
            chunk = texto[st:en].strip()
            if chunk:
                blocks.append(chunk)
        return blocks

    @staticmethod
    def _parse_block(block: str) -> Dict[str, str]:
        """Extrai campos de um bloco de unidade."""
        b = _normalize_light(block)

        # ============ Cabeçalho (Bloco, Unidade, Código) ============
        cabec = re.search(
            _a(r"Bloco:\s*([A-Za-z0-9]+)\s+Unidade:\s*([A-Za-z0-9]{1,10})\s*-\s*(.+?)\s+Código do cliente:\s*([0-9]{1,10})"),
            b
        )
        if not cabec:
            cabec = re.search(
                r"Bloco:\s*([A-Za-z0-9]+)\s+Unidade:\s*([A-Za-z0-9]{1,10})\s*-\s*(.+)$",
                b, flags=re.M
            )
            bloco = cabec.group(1).strip() if cabec else ""
            unidade_cod = cabec.group(2).strip() if cabec else ""
            unidade_nome = cabec.group(3).strip() if cabec else ""
            codigo_cli = _extract_first(_a(r"Código do cliente:\s*([0-9]{1,10})"), b)
        else:
            bloco = cabec.group(1).strip()
            unidade_cod = cabec.group(2).strip()
            unidade_nome = cabec.group(3).strip()
            codigo_cli = cabec.group(4).strip()

        # ============ Localizar seções do bloco ============
        sec_end_cob = re.search(_a(r'Endereço de cobrança'), b, re.I)
        sec_end_cor = re.search(_a(r'Endereço de correspondência'), b, re.I)
        sec_dados_pes = re.search(r'Dados pessoais', b, re.I)
        sec_tel_unid = re.search(r'Telefone/e-mail da unidade', b, re.I)
        sec_tel_cli = re.search(r'Telefone/e-mail do cliente', b, re.I)
        sec_unid_alug = re.search(r'Unidade alugada', b, re.I)
        sec_tel_loc = re.search(_a(r'Telefone/e-mail de locatário'), b, re.I)
        sec_dados_ger = re.search(r'Dados gerais', b, re.I)
        sec_rateio = re.search(_a(r'Rateio/fraç'), b, re.I)

        def _sec(start_m, *end_ms):
            """Texto entre start_m.end() e o primeiro end_m.start() válido."""
            if not start_m:
                return ''
            s = start_m.end()
            e = len(b)
            for em in end_ms:
                if em and em.start() > s:
                    e = min(e, em.start())
            return b[s:e]

        cobranca_sec = _sec(sec_end_cob, sec_end_cor, sec_dados_pes, sec_tel_unid, sec_tel_cli)
        corresp_sec = _sec(sec_end_cor, sec_tel_unid, sec_tel_cli, sec_unid_alug, sec_dados_ger)
        loc_full_sec = _sec(sec_unid_alug, sec_dados_ger) if sec_unid_alug else ''
        loc_tel_sec = _sec(sec_tel_loc, sec_dados_ger) if sec_tel_loc else ''
        dados_ger_sec = _sec(sec_dados_ger, sec_rateio)
        rateio_sec = _sec(sec_rateio)

        # ============ A/C e Endereço de cobrança (separado) ============
        aos_cuidados = _extract_first(
            _a(r'A/C:\s*(.*?)(?=\s{2,}|Tipo de correspondência|$)'),
            cobranca_sec, re.M
        )
        end_cobr_raw = _extract_first(_a(r'Endereço:\s*(.+)'), cobranca_sec)
        addr_cobr = _parse_address(end_cobr_raw)

        # ============ Endereço de correspondência (separado) ============
        end_corr_raw = _extract_first(_a(r'Endereço:\s*(.+)'), corresp_sec)
        addr_corr = _parse_address(end_corr_raw)

        # ============ Dados pessoais ============
        tipo_pessoa = _extract_first(_a(r"Tipo de pessoa:\s*(Jurídica|Fisica|Física)"), b, re.I)
        if tipo_pessoa.lower().startswith("fis"):
            tipo_pessoa = "Física"
        elif tipo_pessoa.lower().startswith("jur"):
            tipo_pessoa = "Jurídica"

        cpf = _extract_first(r"\bCPF:\s*([0-9\.\-]{11,14})", b)
        cnpj = _extract_first(r"\bCNPJ:\s*([0-9\./\-]{14,18})", b)
        cpf_cnpj = cnpj or cpf

        # ============ Telefones/e-mails (proprietário) ============
        # Buscar na área ANTES de "Unidade alugada" ou "Dados gerais"
        owner_end = len(b)
        if sec_unid_alug:
            owner_end = sec_unid_alug.start()
        elif sec_dados_ger:
            owner_end = sec_dados_ger.start()
        owner_phones = _extract_phones_emails(b[:owner_end])

        # ============ Locatário ============
        loc_nome = loc_cpf = loc_ac = loc_forma_envio = ''
        loc_addr = {'logradouro': '', 'endereco': '', 'numero': '', 'complemento': '',
                     'bairro': '', 'cidade': '', 'estado': '', 'cep': ''}
        loc_phones = {'comercial': '', 'residencial': '', 'celular': '', 'emails': ''}

        if loc_full_sec:
            m_loc = re.search(_a(r'Locatário:\s*(\d+)\s*-\s*(.+?)(?:\s{2,}|\n|$)'), loc_full_sec)
            if m_loc:
                loc_nome = m_loc.group(2).strip()

            loc_ac = _extract_first(
                r'A/C:\s*(.*?)(?=\s{2,}|Forma de envio|$)', loc_full_sec, re.M
            )
            loc_forma_envio = _extract_first(
                r'Forma de envio:\s*([A-Za-z\-_/]+(?:\s[A-Za-z\-_/]+)*?)(?=\s{2,}|$)',
                loc_full_sec, re.M
            )
            loc_end_raw = _extract_first(_a(r'Endereço:\s*(.+)'), loc_full_sec)
            loc_addr = _parse_address(loc_end_raw)

            loc_cpf_m = re.search(r'CPF:\s*([0-9\.\-]{11,14})', loc_full_sec)
            loc_cnpj_m = re.search(r'CNPJ:\s*([0-9\./\-]{14,18})', loc_full_sec)
            loc_cpf = (loc_cnpj_m.group(1) if loc_cnpj_m else '') or \
                      (loc_cpf_m.group(1) if loc_cpf_m else '')

            loc_phones = _extract_phones_emails(loc_tel_sec)

        # ============ Dados gerais (regexes corrigidos - param antes de 2+ espaços) ============
        classificacao = _extract_first(
            _a(r"Classificação:\s*([0-9]{1,3}\s*-\s*[A-Za-zÀ-ÿ\ufffd]+(?:\s[A-Za-zÀ-ÿ\ufffd]+)*?)(?=\s{2,}|$)"),
            dados_ger_sec, re.M
        )
        tipo_unidade = _extract_first(
            r"Tipo de unidade:\s*([A-Za-z\ufffd]+(?:\s[A-Za-z\ufffd]+)*?)(?=\s{2,}|$)",
            dados_ger_sec, re.M
        )
        recebe_carta = _extract_first(
            _a(r"Recebe carta de cobrança:\s*(Sim|Não)"), dados_ger_sec, re.I
        )
        forma_boleto = _extract_first(
            r"Forma de envio boleto:\s*([A-Za-z\-_/]+(?:\s[A-Za-z\-_/]+)*?)(?=\s{2,}|$)",
            dados_ger_sec, re.M
        )
        forma_carta = _extract_first(
            _a(r"Forma de envio da carta de cobrança:\s*([A-Za-z\-_/]+(?:\s[A-Za-z\-_/]+)*?)(?=\s{2,}|$)"),
            dados_ger_sec, re.M
        )

        # ============ Frações ============
        fr_unid = _extract_first(_a(r"Fração unidade:\s*([0-9\.,]+)"), rateio_sec, re.I)
        fr_gar = _extract_first(_a(r"Fração garagem:\s*([0-9\.,]+)"), rateio_sec, re.I)
        metragem = _extract_first(r"Metragem total:\s*([0-9\.,]+)", rateio_sec, re.I)
        area_const = _extract_first(_a(r"Área construída:\s*([0-9\.,]+)"), rateio_sec, re.I)

        # ============ Observações ============
        observ = ""
        m_obs = re.search(_a(r"Observações:") + r"[ \t]*([^\n]+)", b, re.I)
        if m_obs:
            observ = m_obs.group(1).strip()

        return {
            "Bloco": bloco,
            "UnidadeCodigo": unidade_cod,
            "UnidadeNome": unidade_nome,
            "CodigoCliente": codigo_cli,
            "AosCuidados": aos_cuidados,
            # Endereço de cobrança (separado)
            "LogradouroCobranca": addr_cobr['logradouro'],
            "EnderecoCobranca": addr_cobr['endereco'],
            "NumeroCobranca": addr_cobr['numero'],
            "ComplementoCobranca": addr_cobr['complemento'],
            "BairroCobranca": addr_cobr['bairro'],
            "CidadeCobranca": addr_cobr['cidade'],
            "EstadoCobranca": addr_cobr['estado'],
            "CEPCobranca": addr_cobr['cep'],
            # Endereço de correspondência (separado)
            "LogradouroCorresp": addr_corr['logradouro'],
            "EnderecoCorresp": addr_corr['endereco'],
            "NumeroCorresp": addr_corr['numero'],
            "ComplementoCorresp": addr_corr['complemento'],
            "BairroCorresp": addr_corr['bairro'],
            "CidadeCorresp": addr_corr['cidade'],
            "EstadoCorresp": addr_corr['estado'],
            "CEPCorresp": addr_corr['cep'],
            # Dados pessoais
            "TipoPessoa": tipo_pessoa,
            "CPF_CNPJ": cpf_cnpj,
            "TipoUnidade": tipo_unidade,
            "Classificacao": classificacao,
            "RecebeCartaCobranca": recebe_carta,
            "FormaEnvioBoleto": forma_boleto,
            "FormaEnvioCartaCobranca": forma_carta,
            # Telefones/e-mails do proprietário
            "TelefoneComercial": owner_phones['comercial'],
            "TelefoneResidencial": owner_phones['residencial'],
            "Celulares": owner_phones['celular'],
            "Emails": owner_phones['emails'],
            # Locatário
            "NomeLocatario": loc_nome,
            "CPF_CNPJ_Locatario": loc_cpf,
            "AosCuidadosLocatario": loc_ac,
            "LogradouroLocatario": loc_addr['logradouro'],
            "EnderecoLocatario": loc_addr['endereco'],
            "NumeroLocatario": loc_addr['numero'],
            "ComplementoLocatario": loc_addr['complemento'],
            "BairroLocatario": loc_addr['bairro'],
            "CidadeLocatario": loc_addr['cidade'],
            "EstadoLocatario": loc_addr['estado'],
            "CEPLocatario": loc_addr['cep'],
            "TipoCorrespLocatario": loc_forma_envio,
            "EmailsLocatario": loc_phones['emails'],
            "TelefoneLocatarioComercial": loc_phones['comercial'],
            "TelefoneLocatarioResidencial": loc_phones['residencial'],
            "TelefoneLocatarioCelular": loc_phones['celular'],
            # Frações
            "FracaoUnidade": fr_unid,
            "FracaoGaragem": fr_gar,
            "MetragemTotal": metragem,
            "AreaConstruida": area_const,
            "Observacoes": observ,
        }

    @staticmethod
    def extract_records(texto: str) -> List[Dict]:
        if not texto:
            return []

        blocks = Extractor._split_unidades(texto)
        if not blocks:
            return []

        registros: List[Dict] = []
        index: Dict[Tuple[str, str, str], int] = {}

        for blk in blocks:
            rec = Extractor._parse_block(blk)
            if not rec.get("UnidadeCodigo"):
                continue
            key = (rec.get("Bloco", ""), rec.get("UnidadeCodigo", ""), rec.get("CodigoCliente", ""))
            if key in index:
                i = index[key]
                registros[i] = _merge_dict(registros[i], rec, prefer_add=True)
            else:
                index[key] = len(registros)
                registros.append(rec)

        return registros
