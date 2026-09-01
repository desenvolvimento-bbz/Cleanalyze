# cleanalize_plugins/lello.py
"""
Extractor para "Relação Endereçamento" (LELLO)

Diferente do relatório da Ahreas, este PDF é um FORMULÁRIO: cada campo é um
par rótulo/valor posicionado em coluna fixa, e a ordem de desenho do PDF não
é a ordem de leitura. Por isso o texto precisa vir de
`PdfEngine.to_text_layout()` (pdfplumber em grade monoespaçada) — com
`pdftotext -layout` os pares rótulo/valor saem embaralhados e ilegíveis.

O parser:
- Varre o texto linearmente como uma máquina de estados, sem depender de
  distância fixa em linhas — assim um registro partido por quebra de página
  continua sendo lido corretamente.
- Cada linha traz até dois campos: rótulo/valor à esquerda e rótulo/valor à
  direita. O rótulo da direita é localizado pela última ocorrência na linha,
  o que dispensa cortar por coluna e sobrevive a variações de espaçamento.
- Uma unidade pode ter MAIS DE UM condômino (co-proprietários). O segundo
  condômino não repete o cabeçalho "Bloco/Unidade": herda o do primeiro.
  Os registros são consolidados em UMA linha por unidade, com os dados
  pessoais unidos por " | ".
- O endereço vem em campo único ("AVENIDA X, 02 APTO 13") com bairro, cidade,
  UF e CEP já separados pelo relatório; só o logradouro precisa ser quebrado.
- A saída é padronizada em MAIÚSCULAS e sem acentos, e o tipo de logradouro é
  abreviado (AVENIDA -> AV, ALAMEDA -> AL), como já chega nos PDFs da Ahreas.
  A limpeza de pontuação é aplicada só aos campos de nome — CPF, CEP, telefone
  e e-mail preservam suas máscaras.
"""

import re
import unicodedata
from typing import List, Dict, Optional, Tuple

NOME = "lello"

# Separador usado quando uma unidade tem mais de um condômino.
SEPARADOR = " | "

# Tipos de logradouro reconhecidos (ordenados por tamanho desc na hora de usar)
TIPOS_LOGRADOURO = [
    "Alameda", "Avenida", "Estrada", "Passagem", "Rodovia", "Travessa",
    "Calçada", "Caminho", "Chácara", "Fazenda", "Ladeira", "Parque",
    "Viaduto", "Viela", "Praça", "Largo", "Vila", "Beco", "Rua",
    "ALAME", "ESTR", "EST", "ROD", "AV", "AL", "PÇ", "TR",
]
_TIPOS_SORTED = sorted(TIPOS_LOGRADOURO, key=len, reverse=True)

# A Lello escreve o tipo de logradouro por extenso; a Ahreas já entrega abreviado.
# Esta tabela alinha a saída dos dois modelos. Tipos fora dela ficam por extenso
# (é o caso de RUA, que a Ahreas também não abrevia).
ABREV_LOGRADOURO = {
    "AVENIDA": "AV",
    "ALAMEDA": "AL",
    "PRACA": "PC",
    "RODOVIA": "ROD",
    "ESTRADA": "EST",
    "TRAVESSA": "TR",
}

# Caracteres acentuados para tornar regex tolerante a encoding quebrado
_ACCENTED = frozenset("áàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇñÑ")


def _rx(label: str) -> str:
    """Monta regex a partir de um rótulo, tolerante a encoding.

    Cada caractere acentuado vira '.', de modo que o padrão case tanto com o
    texto correto quanto com um texto lido como cp1252/U+FFFD. Os demais
    caracteres são escapados (o '?' de "Enviar para o Dataflex?", por exemplo).
    """
    return "".join("." if c in _ACCENTED else re.escape(c) for c in label)


# ========================= Estrutura do relatório =========================

# (rótulo à esquerda, rótulo à direita, chave da esquerda, chave da direita)
CAMPOS: List[Tuple[str, Optional[str], str, Optional[str]]] = [
    ("Nome",                       "Tipo de pessoa:",   "Nome",                   "TipoPessoa"),
    ("CPF",                        "RG",                "CPF",                    "RG"),
    ("CNPJ",                       "% de participação", "CNPJ",                   "Participacao"),
    ("Entrega de correspondência", "E-mail",            "EntregaCorrespondencia", "Email"),
    ("Enviar para o Dataflex?",    "E-mail boleto",     "EnviarDataflex",         "EmailBoleto"),
    ("Entrega de boletos",         "Entrega de Atas e", "EntregaBoletos",         "EntregaAtasEditais"),
    ("Entrega de Demonstrativos",  "Entrega de",        "EntregaDemonstrativos",  "EntregaComunicados"),
    ("Endereço",                   None,                "Endereco",               None),
    ("Bairro",                     "Cidade",            "Bairro",                 "Cidade"),
    ("Estado",                     "País",              "Estado",                 "Pais"),
    ("CEP",                        "Telefone",          "CEP",                    "Telefone"),
    ("Aos cuidados de",            None,                "AosCuidados",            None),
    ("Telefone A/C",               "Celular A/C",       "TelefoneAC",             "CelularAC"),
]

# Rótulos mais longos primeiro: "Entrega de Demonstrativos" precisa ser testado
# antes de qualquer rótulo que seja prefixo dele.
_CAMPOS_ORD = sorted(CAMPOS, key=lambda c: len(c[0]), reverse=True)
_CAMPOS_RX = [(re.compile(r"^\s*" + _rx(le) + r"(?!\S)"), ld, ke, kd)
              for le, ld, ke, kd in _CAMPOS_ORD]

# Cabeçalho que abre cada unidade: "        Bloco            Unidade"
RE_MARCADOR = re.compile(r"^(\s+)(Bloco)\s{4,}Unidade\s*$")
RE_CONDOMINO = re.compile(r"^\s*" + _rx("Condômino") + r"\s*$")
RE_TIPO_COBRANCA = re.compile(r"^\s*" + _rx("Tipo Cobrança:") + r"(.*)$")
RE_REFERENCIA = re.compile(r"^\s*" + _rx("Referência") + r"\s+(\S+)")

# Chaves que descrevem a PESSOA — unidas por " | " quando há co-titulares.
CAMPOS_PESSOA = [
    "Nome", "TipoPessoa", "CPF_CNPJ", "RG", "Participacao", "AosCuidados",
    "Emails", "TelefoneComercial", "TelefoneResidencial", "Celulares",
]


# ========================= Helpers =========================

def _limpar(v: str) -> str:
    """Normaliza espaços internos de um valor extraído da grade de layout."""
    if not v:
        return ""
    return re.sub(r"\s+", " ", v.replace(" ", " ")).strip()


def _sem_acento(v: str) -> str:
    """Remove acentos e reduz o texto a ASCII (Ç -> C, Ã -> A, É -> E)."""
    if not v:
        return ""
    return unicodedata.normalize("NFKD", v).encode("ascii", "ignore").decode("ascii")


def _padronizar(v: str) -> str:
    """Padrão de saída: MAIÚSCULAS, sem acentos, espaços normalizados.

    Não mexe em pontuação — CPF, CEP, telefone e e-mail mantêm a máscara.
    """
    return _sem_acento(_limpar(v)).upper()


def _padronizar_nome(v: str) -> str:
    """Como _padronizar, mas também remove pontuação — só para campos de nome.

    Os caracteres especiais viram espaço em vez de sumir, para não colar
    palavras: "CARLOS HENRIQUE (DECOB)" -> "CARLOS HENRIQUE DECOB".
    """
    return re.sub(r"\s+", " ", re.sub(r"[^A-Z0-9 ]+", " ", _padronizar(v))).strip()


def _abreviar_logradouro(tipo: str) -> str:
    """AVENIDA -> AV, ALAMEDA -> AL. Tipos sem abreviação passam direto."""
    chave = _padronizar(tipo)
    return ABREV_LOGRADOURO.get(chave, chave)


def _juntar(valores: List[str]) -> str:
    """Une valores distintos com " | ", preservando a ordem e ignorando vazios."""
    saida: List[str] = []
    vistos = set()
    for v in valores:
        v = _limpar(v)
        if not v:
            continue
        chave = v.upper()
        if chave in vistos:
            continue
        vistos.add(chave)
        saida.append(v)
    return SEPARADOR.join(saida)


def _split_campo(linha: str, rx_esq, rotulo_dir: Optional[str]):
    """Separa uma linha em (valor_esquerdo, valor_direito).

    Retorna None se a linha não começar pelo rótulo esperado.
    """
    m = rx_esq.match(linha)
    if not m:
        return None
    resto = linha[m.end():]
    if rotulo_dir is None:
        return (_limpar(resto), "")
    # A última ocorrência é a do rótulo da coluna da direita: o valor da
    # esquerda pode conter o mesmo texto (ex.: "Apenas E-mail" antes de "E-mail").
    m_dir = None
    for m_dir in re.finditer(_rx(rotulo_dir), resto):
        pass
    if m_dir is None:
        return (_limpar(resto), "")
    return (_limpar(resto[:m_dir.start()]), _limpar(resto[m_dir.end():]))


def _parse_endereco(valor: str) -> Dict[str, str]:
    """Separa "AVENIDA MARECHAL MARIO GUEDES, 02 APTO 13" em componentes.

    Bairro, cidade, UF e CEP já vêm em campos próprios do relatório, então
    aqui só é preciso quebrar logradouro / nome / número / complemento.
    """
    r = {"logradouro": "", "endereco": "", "numero": "", "complemento": ""}
    s = _limpar(valor)
    if not s:
        return r

    antes, _, depois = s.partition(",")
    antes = antes.strip()

    for tipo in _TIPOS_SORTED:
        if re.match(r"^" + _rx(tipo) + r"(?!\S)", antes, flags=re.IGNORECASE):
            r["logradouro"] = _abreviar_logradouro(antes[:len(tipo)])
            r["endereco"] = _padronizar(antes[len(tipo):])
            break
    else:
        r["endereco"] = _padronizar(antes)

    depois = depois.strip()
    if depois:
        partes = depois.split(None, 1)
        r["numero"] = _padronizar(partes[0])
        if len(partes) > 1:
            r["complemento"] = _padronizar(partes[1])
    return r


# ========================= Varredura =========================

def _codigo_condominio(linhas: List[str]) -> str:
    """Lê a referência do condomínio ("5675-82" -> "5675") no cabeçalho."""
    for ln in linhas[:40]:
        m = RE_REFERENCIA.match(ln)
        if m:
            return m.group(1).split("-")[0].strip()
    return ""


def _varrer(linhas: List[str]) -> List[Dict[str, str]]:
    """Percorre o texto e devolve UM registro por condômino."""
    registros: List[Dict[str, str]] = []
    atual: Optional[Dict[str, str]] = None
    bloco = unidade = tipo_cobranca = ""
    col_corte: Optional[int] = None  # coluna do rótulo "Bloco" no marcador

    for linha in linhas:
        if not linha.strip():
            continue

        m = RE_MARCADOR.match(linha)
        if m:
            col_corte = m.start(2)
            continue

        # Linha seguinte ao marcador: bloco à esquerda, código da unidade à direita.
        if col_corte is not None:
            bloco = _limpar(linha[:col_corte])
            unidade = _limpar(linha[col_corte:])
            if not unidade:  # marcador com espaçamento atípico
                partes = re.split(r"\s{2,}", linha.strip())
                bloco, unidade = (partes[0], partes[-1]) if len(partes) > 1 else ("", partes[0])
            col_corte = None
            continue

        m = RE_TIPO_COBRANCA.match(linha)
        if m:
            tipo_cobranca = _limpar(m.group(1))
            continue

        if RE_CONDOMINO.match(linha):
            atual = {"Bloco": bloco, "UnidadeCodigo": unidade, "TipoCobranca": tipo_cobranca}
            registros.append(atual)
            continue

        if atual is None:
            continue  # ainda no cabeçalho do condomínio

        for rx_esq, rotulo_dir, chave_esq, chave_dir in _CAMPOS_RX:
            par = _split_campo(linha, rx_esq, rotulo_dir)
            if par is None:
                continue
            valor_esq, valor_dir = par
            # Não sobrescreve valor já preenchido: rótulos de continuação
            # ("Editais", "Comunicados") reaparecem em linha própria.
            if valor_esq and not atual.get(chave_esq):
                atual[chave_esq] = valor_esq
            if chave_dir and valor_dir and not atual.get(chave_dir):
                atual[chave_dir] = valor_dir
            break

    return registros


def _montar_pessoa(reg: Dict[str, str]) -> Dict[str, str]:
    """Converte um registro bruto nos campos finais de um condômino."""
    cpf = _padronizar(reg.get("CPF", ""))
    cnpj = _padronizar(reg.get("CNPJ", ""))
    return {
        # Nome e "aos cuidados de" são os únicos campos com limpeza de pontuação.
        "Nome": _padronizar_nome(reg.get("Nome", "")),
        "AosCuidados": _padronizar_nome(reg.get("AosCuidados", "")),
        "TipoPessoa": _padronizar(reg.get("TipoPessoa", "")),
        "CPF_CNPJ": cpf or cnpj,
        "RG": _padronizar(reg.get("RG", "")),
        "Participacao": _padronizar(reg.get("Participacao", "")),
        "Emails": _juntar([_padronizar(reg.get("Email", "")),
                           _padronizar(reg.get("EmailBoleto", ""))]),
        "TelefoneComercial": _padronizar(reg.get("TelefoneAC", "")),
        "TelefoneResidencial": _padronizar(reg.get("Telefone", "")),
        "Celulares": _padronizar(reg.get("CelularAC", "")),
    }


def _consolidar(brutos: List[Dict[str, str]], cod_condominio: str) -> List[Dict[str, str]]:
    """Agrupa os condôminos em UMA linha por unidade.

    Dados pessoais dos co-titulares são unidos por " | ". Dados da unidade —
    inclusive o endereço de cobrança — vêm do primeiro condômino listado.
    """
    ordem: List[Tuple[str, str]] = []
    grupos: Dict[Tuple[str, str], List[Dict[str, str]]] = {}
    for reg in brutos:
        chave = (reg.get("Bloco", ""), reg.get("UnidadeCodigo", ""))
        if chave not in grupos:
            grupos[chave] = []
            ordem.append(chave)
        grupos[chave].append(reg)

    saida: List[Dict[str, str]] = []
    for chave in ordem:
        itens = grupos[chave]
        primeiro = itens[0]
        pessoas = [_montar_pessoa(r) for r in itens]
        end = _parse_endereco(primeiro.get("Endereco", ""))

        registro = {
            "CodigoCondominio": _padronizar(cod_condominio),
            "Bloco": _padronizar(primeiro.get("Bloco", "")),
            "UnidadeCodigo": _padronizar(primeiro.get("UnidadeCodigo", "")),
            "LogradouroCobranca": end["logradouro"],
            "EnderecoCobranca": end["endereco"],
            "NumeroCobranca": end["numero"],
            "ComplementoCobranca": end["complemento"],
            "BairroCobranca": _padronizar(primeiro.get("Bairro", "")),
            "CidadeCobranca": _padronizar(primeiro.get("Cidade", "")),
            "EstadoCobranca": _padronizar(primeiro.get("Estado", "")),
            "CEPCobranca": _padronizar(primeiro.get("CEP", "")),
            "TipoCobranca": _padronizar(primeiro.get("TipoCobranca", "")),
            "EntregaCorrespondencia": _padronizar(primeiro.get("EntregaCorrespondencia", "")),
            "EntregaBoletos": _padronizar(primeiro.get("EntregaBoletos", "")),
            "EntregaAtasEditais": _padronizar(primeiro.get("EntregaAtasEditais", "")),
            "EntregaComunicados": _padronizar(primeiro.get("EntregaComunicados", "")),
            "EnviarDataflex": _padronizar(primeiro.get("EnviarDataflex", "")),
        }
        for campo in CAMPOS_PESSOA:
            registro[campo] = _juntar([p[campo] for p in pessoas])
        saida.append(registro)

    return saida


# ========================= Interface do plugin =========================

class Extractor:
    @staticmethod
    def matches(texto: str) -> bool:
        if not texto:
            return False
        amostra = texto[:40000]
        marcas = [
            _rx("Dados do condomínio"),
            _rx("Condômino"),
            _rx("Enviar para o Dataflex?"),
            _rx("Entrega de correspondência"),
        ]
        encontrados = sum(1 for p in marcas if re.search(p, amostra))
        return encontrados >= 3

    @staticmethod
    def extract_records(texto: str) -> List[Dict[str, str]]:
        linhas = (texto or "").splitlines()
        brutos = _varrer(linhas)
        return _consolidar(brutos, _codigo_condominio(linhas))
