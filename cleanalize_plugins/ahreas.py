# cleanalize_plugins/ahreas.py
"""
Extractor para "Relatório de Unidades - Completo" (AHREAS)

O parser:
- Limpa cabeçalhos/rodapés (ex.: "Relatório de Unidades - Completo", "Condomínio:", "Emitido em ... Página X de Y").
- Divide o texto em blocos de unidade (de "Bloco:" até o próximo "Bloco:").
- Extrai campos principais com regex tolerantes a variações comuns e OCR.
- Faz deduplicação por (Bloco, UnidadeCodigo, CodigoCliente) e completa lacunas quando encontra repetição.

Campos gerados (origem) — você pode mapear no ahreas.json:
  Bloco
  UnidadeCodigo
  UnidadeNome
  CodigoCliente
  EnderecoCobranca
  TipoPessoa
  CPF_CNPJ
  TipoUnidade
  Classificacao
  RecebeCartaCobranca
  FormaEnvioBoleto
  FormaEnvioCartaCobranca
  TelefoneComercial
  Celulares           (string com múltiplos, separados por " | ")
  Emails              (string com múltiplos, separados por " | ")
  FracaoUnidade
  FracaoGaragem
  Observacoes

Observação: o relatório real pode ter campos adicionais; você pode expandir o mapeamento no JSON.
"""

import re
from typing import List, Dict, Tuple

NOME = "ahreas"

# ----------------- Normalizações auxiliares -----------------

def _normalize_text(s: str) -> str:
    """Normalização leve para textos (útil pós-OCR também)."""
    if not s:
        return s
    s = s.replace("\u00A0", " ")  # NBSP -> espaço
    s = s.replace("\u2013", "-").replace("\u2014", "-")  # en/em dash -> hífen
    # normaliza múltiplos espaços
    s = re.sub(r"[ \t]+", " ", s)
    # remove espaços antes de pontuação
    s = re.sub(r"\s+([,:;])", r"\1", s)
    return s.strip()

def _clean_head_foot(s: str) -> str:
    """Remove cabeçalhos e rodapés que se repetem."""
    lines = s.splitlines()
    out = []
    for ln in lines:
        l = ln.strip()
        if not l:
            out.append(ln)
            continue
        # filtra "Relatório de Unidades - Completo"
        if l.startswith("Relatório de Unidades"):
            continue
        # filtra "Condomínio: ... CNPJ: ..."
        if l.startswith("Condomínio:"):
            continue
        # filtra "Endereço: ..." da capa (endereço do condomínio, não de cobrança do cliente)
        if l.startswith("Endereço:") and "CEP:" in l and " - " in l and " - " in l:
            # Este "Endereço:" específico é o do cabeçalho do condomínio; ele repete toda página.
            continue
        # filtra rodapé "Emitido em ... Página X de Y"
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
    """Completa lacunas em base com dados de add. Se prefer_add, substitui também se add tiver valor 'melhor'."""
    for k, v in add.items():
        if not v:
            continue
        if k not in base or not base[k] or prefer_add:
            if prefer_add or not base.get(k):
                base[k] = v
    return base

# ----------------- Núcleo -----------------

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
        # Limpa cabeçalhos/rodapés antes de splitar
        texto = _clean_head_foot(_normalize_text(texto))

        # Marcador de início de unidade: linha que contenha "Bloco:" e "Unidade:" na mesma linha
        # Aceita espaçamentos variados.
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
        """
        Extrai campos de um bloco de unidade.
        """
        b = _normalize_text(block)

        # -------- Cabeçalho da linha principal --------
        # Ex.: Bloco: 0  Unidade: 000011 - GHR - GESTAO E COM. DE BENS LTDA  Código do cliente: 215139
        # Aceita variações de espaços.
        cabec = re.search(
            r"Bloco:\s*([A-Za-z0-9]+)\s+Unidade:\s*([0-9]{1,6})\s*-\s*(.+?)\s+Código do cliente:\s*([0-9]{1,10})",
            b
        )
        if not cabec:
            # fallback: tenta sem "Código do cliente" (alguns trechos podem estar quebrados)
            cabec = re.search(
                r"Bloco:\s*([A-Za-z0-9]+)\s+Unidade:\s*([0-9]{1,6})\s*-\s*(.+)$",
                b, flags=re.M
            )
            bloco = cabec.group(1).strip() if cabec else ""
            unidade_cod = cabec.group(2).strip() if cabec else ""
            unidade_nome = cabec.group(3).strip() if cabec else ""
            codigo_cli = _extract_first(r"Código do cliente:\s*([0-9]{1,10})", b)
        else:
            bloco = cabec.group(1).strip()
            unidade_cod = cabec.group(2).strip()
            unidade_nome = cabec.group(3).strip()
            codigo_cli = cabec.group(4).strip()

        # -------- Endereço de cobrança --------
        # Procuramos a linha "Endereço de cobrança" e logo abaixo a linha "Endereço: ...".
        end_cobr = ""
        m_end_sec = re.search(r"Endereço de cobrança\s*(?:\r?\n)+\s*Endereço:\s*(.+)", b, flags=re.I)
        if m_end_sec:
            # até o fim da linha
            end_cobr = m_end_sec.group(1).strip()
            # se tiver quebras até outro título, corta na quebra dupla
            end_cobr = end_cobr.split("  ")[0].strip()

        # -------- Dados pessoais / gerais --------
        # Tipo de pessoa + CPF/CNPJ podem aparecer em diferentes seções; tentamos ambos.
        tipo_pessoa = _extract_first(r"Tipo de pessoa:\s*(Jurídica|Fisica|Física)", b, flags=re.I)
        if tipo_pessoa.lower().startswith("fis"):
            tipo_pessoa = "Física"
        elif tipo_pessoa.lower().startswith("jur"):
            tipo_pessoa = "Jurídica"

        cpf = _extract_first(r"\bCPF:\s*([0-9\.\-]{11,14})", b)
        cnpj = _extract_first(r"\bCNPJ:\s*([0-9\./\-]{14,18})", b)
        cpf_cnpj = cnpj or cpf

        tipo_unidade = _extract_first(r"Tipo de unidade:\s*([A-Za-zÀ-ÿ ]+)", b)
        classificacao = _extract_first(r"Classificação:\s*([0-9]{1,3}\s*-\s*[A-Za-zÀ-ÿ ]+)", b)

        recebe_carta = _extract_first(r"Recebe carta de cobrança:\s*(Sim|Não)", b, flags=re.I)
        forma_boleto = _extract_first(r"Forma de envio boleto:\s*([A-Za-z\-_/ ]+)", b)
        forma_carta  = _extract_first(r"Forma de envio da carta de cobrança:\s*([A-Za-z\-_/ ]+)", b)

        # -------- Telefones e e-mails --------
        # Telefones podem aparecer em várias linhas "Telefone comercial - ..." ou "Celular - ...".
        tels_com = _all(r"Telefone comercial\s*-\s*([0-9\(\) \-\.]+)", b, flags=re.I)
        cel_list = _all(r"Celular\s*-\s*([0-9\(\) \-\. ]+)", b, flags=re.I)
        emails   = _all(r"E-mail\s*-\s*([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})", b, flags=re.I)

        telefone_comercial = " | ".join(sorted(set([_normalize_text(x) for x in tels_com if x.strip()]))) if tels_com else ""
        celulares = " | ".join(sorted(set([_normalize_text(x) for x in cel_list if x.strip()]))) if cel_list else ""
        emails_s  = " | ".join(sorted(set([x.strip() for x in emails if x.strip()]))) if emails else ""

        # -------- Frações --------
        fr_unid = _extract_first(r"Fraç(?:a|ã)o unidade:\s*([0-9\.,]+)", b, flags=re.I)
        fr_gar  = _extract_first(r"Fraç(?:a|ã)o garagem:\s*([0-9\.,]+)", b, flags=re.I)
        metragem = _extract_first(r"Metragem total:\s*([0-9\.,]+)", b, flags=re.I)
        area_const = _extract_first(r"Área construída:\s*([0-9\.,]+)", b, flags=re.I)

        # -------- Observações --------
        # Em muitos relatórios vem "Observações:" e nada abaixo; se houver conteúdo depois do título, captura.
        observ = ""
        m_obs = re.search(r"Observa(?:c|ç)ões:\s*(.+)", b, flags=re.I)
        if m_obs:
            observ = m_obs.group(1).strip()

        return {
            "Bloco": bloco,
            "UnidadeCodigo": unidade_cod,
            "UnidadeNome": unidade_nome,
            "CodigoCliente": codigo_cli,
            "EnderecoCobranca": end_cobr,
            "TipoPessoa": tipo_pessoa,
            "CPF_CNPJ": cpf_cnpj,
            "TipoUnidade": tipo_unidade,
            "Classificacao": classificacao,
            "RecebeCartaCobranca": recebe_carta,
            "FormaEnvioBoleto": forma_boleto,
            "FormaEnvioCartaCobranca": forma_carta,
            "TelefoneComercial": telefone_comercial,
            "Celulares": celulares,
            "Emails": emails_s,
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
        index: Dict[Tuple[str, str, str], int] = {}  # (Bloco, UnidadeCodigo, CodigoCliente) -> idx em registros

        for blk in blocks:
            rec = Extractor._parse_block(blk)

            # Se não tem unidade e código do cliente, ignora
            if not rec.get("UnidadeCodigo"):
                continue

            key = (rec.get("Bloco", ""), rec.get("UnidadeCodigo", ""), rec.get("CodigoCliente", ""))

            if key in index:
                # já vimos — completa lacunas
                i = index[key]
                registros[i] = _merge_dict(registros[i], rec, prefer_add=True)
            else:
                index[key] = len(registros)
                registros.append(rec)

        return registros
