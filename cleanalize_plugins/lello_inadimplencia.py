# cleanalize_plugins/lello_inadimplencia.py
"""
Extractor para "Cotas Atrasadas" (LELLO) — inadimplência.

Layout do relatório: cada cobrança é um bloco que repete o cabeçalho inteiro
(Empresa / Referência / Unidade / cabeçalho de colunas), seguido de UMA linha
de cobrança e de N linhas de detalhamento por conta contábil.

    Empresa      Lello Condomínios
    Referência   2318        SAINT GOTHARD FLAT SERVICE
    Unidade      000011      ALEX DOS SANTOS SILVA
    Código    Vencimento   Valor Original  Valor Multa  Correção/Juros   Total
    49921152  05/01/2025       446,97         8,94         135,19       591,10
              Conta      Histórico                      Correção/Juros
              1002       VLR.REMANESCENTE R.                 420,15
              13002      VLR.REMANESCENTE R.                  26,82

Decisões de mapeamento (definidas com o usuário):
- **Uma linha por conta contábil**, não por cobrança. O rateio das contas soma
  exatamente o Valor Original — conferido nas 123 cobranças do PDF de origem —
  então cada linha leva o valor da sua conta.
- **Cód. Condomínio vem da "Referência"** do próprio relatório (2318), não do
  nome do arquivo.
- **Percentual Multa** é calculado como multa/valor original (2% em todo o PDF
  de origem, mas o cálculo é feito por cobrança em vez de fixado).
- A coluna "Correção/Juros" da cobrança não tem destino no modelo e fica de fora.
- O relatório não traz bloco nem conta bancária: essas colunas saem vazias.

Como no plugin `lello`, o texto precisa vir de `PdfEngine.to_text_layout()`.
"""

import re
import unicodedata
from typing import List, Dict, Optional

NOME = "lello_inadimplencia"

# Caracteres acentuados para tornar regex tolerante a encoding quebrado
_ACCENTED = frozenset("áàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇñÑ")


def _rx(label: str) -> str:
    """Regex de um rótulo, tolerante a encoding (acento vira '.')."""
    return "".join("." if c in _ACCENTED else re.escape(c) for c in label)


def _sem_acento(v: str) -> str:
    """Remove acentos e reduz o texto a ASCII."""
    if not v:
        return ""
    return unicodedata.normalize("NFKD", v).encode("ascii", "ignore").decode("ascii")


def _padronizar(v: str) -> str:
    """Saída em MAIÚSCULAS, sem acentos e com espaços normalizados."""
    if not v:
        return ""
    return re.sub(r"\s+", " ", _sem_acento(v)).strip().upper()


# ========================= Linhas do relatório =========================

_VAL = r"-?[\d.]+,\d{2}"

RE_REFERENCIA = re.compile(r"^" + _rx("Referência") + r"\s+(\S+)\s+(.*)$")
RE_UNIDADE = re.compile(r"^Unidade\s+(\S+)\s*(.*)$")

# 49921152  05/01/2025  446,97  8,94  135,19  591,10
RE_COBRANCA = re.compile(
    r"^(\d+)\s+(\d{2}/\d{2}/\d{4})"
    r"\s+(" + _VAL + r")\s+(" + _VAL + r")\s+(" + _VAL + r")\s+(" + _VAL + r")$"
)

# 1002  VLR.REMANESCENTE R.  420,15
RE_DETALHE = re.compile(r"^(\d{3,6})\s+(.+?)\s+(" + _VAL + r")$")

# Linhas de cabeçalho/rodapé que se repetem e devem ser puladas
RE_IGNORAR = re.compile(
    r"^(Empresa\b|" + _rx("Código") + r"\s+Vencimento\b|Conta\s+" + _rx("Histórico") +
    r"\b|" + _rx("Período") + r"\b|" + _rx("Pág") + r"\.|Cotas Atrasadas|RESUMO\b|VALOR TOTAL\b)"
)


def _decimal(v: str) -> Optional[float]:
    try:
        return float(v.replace(".", "").replace(",", "."))
    except (ValueError, AttributeError):
        return None


def _percentual_multa(multa: str, original: str) -> str:
    """Multa sobre o valor original, no formato brasileiro ("2,00")."""
    m, o = _decimal(multa), _decimal(original)
    if not m or not o:
        return ""
    return f"{round(m / o * 100, 2):.2f}".replace(".", ",")


# ========================= Interface do plugin =========================

class Extractor:
    @staticmethod
    def matches(texto: str) -> bool:
        if not texto:
            return False
        amostra = texto[:40000]
        marcas = [
            _rx("Cotas Atrasadas"),
            _rx("Lello Condomínios"),
            _rx("Correção/Juros"),
            _rx("Valor Original"),
        ]
        return sum(1 for p in marcas if re.search(p, amostra)) >= 3

    @staticmethod
    def extract_records(texto: str) -> List[Dict[str, str]]:
        registros: List[Dict[str, str]] = []
        condominio = ""
        unidade = ""
        cobranca: Optional[Dict[str, str]] = None

        for bruta in (texto or "").splitlines():
            linha = " ".join(bruta.split())
            if not linha or RE_IGNORAR.match(linha):
                continue

            m = RE_REFERENCIA.match(linha)
            if m:
                condominio = _padronizar(m.group(1))
                continue

            m = RE_UNIDADE.match(linha)
            if m:
                unidade = _padronizar(m.group(1))
                continue

            m = RE_COBRANCA.match(linha)
            if m:
                recibo, vencimento, original, multa = m.group(1), m.group(2), m.group(3), m.group(4)
                cobranca = {
                    "Recibo": _padronizar(recibo),
                    "Vencimento": vencimento,
                    "PercentualMulta": _percentual_multa(multa, original),
                }
                continue

            # Detalhamento por conta contábil — só vale dentro de uma cobrança.
            m = RE_DETALHE.match(linha)
            if m and cobranca is not None:
                registros.append({
                    "Condominio": condominio,
                    "Bloco": "",
                    "Unidade": unidade,
                    "Vencimento": cobranca["Vencimento"],
                    "ContaBancaria": "",
                    "Conta": _padronizar(m.group(1)),
                    "Historico": _padronizar(m.group(2)),
                    "Complemento": "",
                    "Valor": m.group(3),
                    "PercentualMulta": cobranca["PercentualMulta"],
                    "Recibo": cobranca["Recibo"],
                })

        return registros
