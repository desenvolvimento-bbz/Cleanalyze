import subprocess
import pandas as pd
import re
import os
import unicodedata
from typing import List, Tuple, Optional
import argparse
import sys
import json
import shutil

# Caso queira alterar o texto que vai para a coluna "Cód. Tipo Unidade" nas vagas:
DEFAULT_TIPO_VAGA = "VAGA"

def _resolver_pdftotext(caminho_forcado: Optional[str] = None) -> str:
    """
    Resolve o executável `pdftotext`.
    Ordem:
      1) caminho_forcado (arg)
      2) env POPPLER_PDFTOTEXT
      3) PATH (shutil.which)
      4) caminhos comuns Win
      5) caminhos comuns Unix/Mac
    """
    candidatos = []
    if caminho_forcado:
        candidatos.append(caminho_forcado)

    env = os.environ.get("POPPLER_PDFTOTEXT")
    if env:
        candidatos.append(env)

    which = shutil.which("pdftotext")
    if which:
        candidatos.append(which)

    if sys.platform.startswith("win"):
        candidatos.extend([
            r"C:\Program Files\poppler\bin\pdftotext.exe",
            r"C:\Program Files\poppler-24.02.0\Library\bin\pdftotext.exe",
            r"C:\Program Files\poppler-23.11.0\Library\bin\pdftotext.exe",
            r"C:\poppler\Library\bin\pdftotext.exe",
        ])
    else:
        candidatos.extend([
            "/usr/bin/pdftotext",
            "/usr/local/bin/pdftotext",
            "/opt/homebrew/bin/pdftotext",              # macOS ARM
            "/usr/local/opt/poppler/bin/pdftotext",     # macOS Intel
        ])

    for c in candidatos:
        if c and os.path.isfile(c):
            return c

    raise FileNotFoundError(
        "Não encontrei o executável 'pdftotext'. "
        "Defina POPPLER_PDFTOTEXT com o caminho completo ou instale o Poppler e "
        "garanta que 'pdftotext' esteja no PATH."
    )


class ExtractorPDF:
    def __init__(self, config_path: str = "config.json"):
        self.modelo_path = None
        self.pasta_saida = None
        self.colunas_modelo: List[str] = []

        with open(config_path, 'r', encoding='utf-8') as f:
            config = json.load(f)

        self.MAPEAMENTO = config.get("mapeamento", {})
        self.tipos_logradouro = config.get("tipos_logradouro", [])

    def configurar_modelo(self, modelo_path: str):
        if not os.path.exists(modelo_path):
            raise FileNotFoundError(f"Modelo não encontrado: {modelo_path}")
        self.modelo_path = modelo_path
        self.colunas_modelo = pd.read_excel(modelo_path).columns.tolist()

    def configurar_pasta_saida(self, pasta_saida: str):
        os.makedirs(pasta_saida, exist_ok=True)
        self.pasta_saida = pasta_saida

    # ----------------- utilidades -----------------

    def limpar_texto(self, texto: str, maiusculo: bool = True, remover_pontuacao: bool = True) -> str:
        if not texto:
            return ""
        texto = unicodedata.normalize('NFKD', texto).encode('ASCII', 'ignore').decode('utf-8')
        if remover_pontuacao:
            texto = re.sub(r'[^\w\s/-]', '', texto)
        return texto.upper() if maiusculo else texto.lower()

    def converter_ponto_para_virgula(self, valor: str) -> str:
        if not valor:
            return ""
        return str(valor).replace(".", ",")

    def extrair_valor_simples(self, texto: str, chave: str, split_chars: Optional[list] = None, split_first_word: bool = False) -> str:
        padrao = rf'{re.escape(chave)}\s*:\s*(.*?)\s*(?:\n|$)'
        match = re.search(padrao, texto)
        if match:
            valor = match.group(1).strip()
            if not valor or valor in (':', '-'):
                return ""
            if split_first_word:
                partes = valor.split()
                return partes[0] if partes else ""
            if split_chars:
                for sep in split_chars:
                    if sep in valor:
                        valor = valor.split(sep)[0].strip()
            return valor
        return ""

    def extrair_texto_entre_campos(self, texto: str, campo_inicio: str, campo_fim: str) -> str:
        padrao = rf'{re.escape(campo_inicio)}\s*:\s*(.*?)(?={re.escape(campo_fim)}\s*:|$)'
        match = re.search(padrao, texto, re.DOTALL | re.IGNORECASE)
        if match:
            valor = re.sub(r'\s+', ' ', match.group(1)).strip()
            if not valor or valor in (':', '-'):
                return ""
            return valor
        return ""

    def extrair_ac_refinado(self, texto: str) -> str:
        try:
            if "A/C:" not in texto:
                return ""
            posicao_ac = texto.find("A/C:")
            posicao_unidade_alugada = texto.find("Unidade alugada")
            if posicao_unidade_alugada != -1 and posicao_ac > posicao_unidade_alugada:
                return ""
            texto_filtrado = texto if posicao_unidade_alugada == -1 else texto[:posicao_unidade_alugada]
            ac_match = re.search(
                r'A/C\s*:\s*([^:\n]+?)(?=\s*(?:Tipo\s+de|Forma\s+de\s+envio|Locatário|Unidade\s+alugada|Endereço|Telefone|E-mail|CPF|CNPJ|Classificação|Fração|\s{2,}|\n\s*\w+\s*:|\n\n|$))',
                texto_filtrado, re.DOTALL | re.IGNORECASE
            )
            if not ac_match:
                return ""
            valor = re.sub(r'\s+', ' ', ac_match.group(1)).strip()
            valor = re.sub(r'[:\-\s]+$', '', valor)
            if not valor or valor.isdigit() or any(c in valor for c in ['Page', 'Página', '\f', '\x0c']):
                return ""
            palavras = []
            for p in valor.split():
                pl = re.sub(r'[^\w]', '', p)
                if pl and not pl.isdigit() and len(pl) > 1 and pl.lower() not in ['tipo','de','da','do','para','com','por','em']:
                    palavras.append(p)
            if len(palavras) < 2:
                return ""
            return f"{palavras[0]} {palavras[1]}"
        except Exception:
            return ""

    def extrair_endereco_refinado(self, logradouro_completo: str) -> Tuple[str, str, str, str]:
        if not logradouro_completo:
            return '', '', '', ''
        tipo_logradouro = ''
        resto_endereco = logradouro_completo
        for tipo in self.tipos_logradouro:
            padrao = re.compile(rf'^\s*{tipo}\b\s*(.*)', re.IGNORECASE)
            if padrao.match(logradouro_completo):
                match = padrao.search(logradouro_completo)
                tipo_logradouro = tipo
                resto_endereco = match.group(1).strip()
                break
        match_numero = re.search(r'\b(\d+)\b', resto_endereco)
        if match_numero:
            numero = match_numero.group(1)
            posicao_numero = match_numero.start()
            nome_rua = resto_endereco[:posicao_numero].strip()
            complemento = resto_endereco[match_numero.end():].strip()
            return tipo_logradouro, nome_rua, numero, complemento
        else:
            return tipo_logradouro, resto_endereco, '', ''

    def extrair_campos_endereco(self, endereco_completo: str) -> Tuple[str, str, str, str, str, str, str, str]:
        if not endereco_completo:
            return "", "", "", "", "", "", "", ""
        partes = endereco_completo.split(" - ")
        logradouro_completo = partes[0].strip() if len(partes) > 0 else ""
        bairro = partes[1].strip() if len(partes) > 1 else ""
        cidade = partes[2].strip() if len(partes) > 2 else ""
        estado = partes[3].strip() if len(partes) > 3 else ""
        cep_match = re.search(r'\d{5}-\d{3}', endereco_completo)
        cep = cep_match.group(0) if cep_match else ""
        tipo_logradouro, nome_rua, numero, complemento = self.extrair_endereco_refinado(logradouro_completo)
        return tipo_logradouro, nome_rua, numero, bairro, cidade, estado, cep, complemento

    def extrair_emails(self, texto: str) -> List[str]:
        if not texto:
            return []
        emails_ordenados = []
        for linha in texto.split('\n'):
            for m in re.finditer(r'\b[\w\.-]+@[\w\.-]+\.\w+\b', linha, flags=re.IGNORECASE):
                e = m.group().lower().strip()
                if e not in emails_ordenados:
                    emails_ordenados.append(e)
        return emails_ordenados

    def extrair_celulares(self, texto: str) -> List[str]:
        padrao = r'[\(]?\d{2}[\)]?\s*9\s*\d{4}[-\s]?\d{4}|\b9\d{4}[-]?\d{4}\b|\b\d{11}\b|\b\d{2}\s*9\d{4}[-]?\d{4}'
        encontrados = re.findall(padrao, texto)
        celulares = []
        for numero in encontrados:
            digitos = re.sub(r'\D', '', numero)
            if len(digitos) == 11:
                celulares.append(digitos)
            elif len(digitos) == 9:
                celulares.append("11" + digitos)
            elif len(digitos) == 10 and digitos[2] == "9":
                celulares.append(digitos)
        return list(set(celulares))

    def extrair_cpf_cnpj(self, bloco: str) -> str:
        """Prioriza CPF/CNPJ do morador/pagador e evita o CNPJ do condomínio do cabeçalho."""
        def pega_primeiro(chunk: str) -> Optional[str]:
            m = re.search(r'(?:CPF|CNPJ)\s*:\s*([\d./-]+)', chunk)
            return m.group(1).strip() if m else None

        limites = r'(?:Telefone/e-mail do cliente|Dados gerais|Observações|Rateio/frações|Endereço de cobrança)'
        m = re.search(r'Dados pessoais(.*?){limites}'.format(limites=limites), bloco, re.DOTALL|re.IGNORECASE)
        if m:
            v = pega_primeiro(m.group(1))
            if v:
                return v

        m = re.search(r'Dados do pagador(.*?){limites}'.format(limites=limites), bloco, re.DOTALL|re.IGNORECASE)
        if m:
            v = pega_primeiro(m.group(1))
            if v:
                return v

        v = pega_primeiro(bloco)
        return v or ""

    def merge_dados(self, orig: dict, novo: dict) -> dict:
        for k, v in novo.items():
            if not orig.get(k) and v:
                orig[k] = v
        return orig

    # ----------------- núcleo -----------------

    def extrair_dados(self, texto: str) -> List[dict]:
        if not self.colunas_modelo:
            raise ValueError("Modelo não configurado. Use configurar_modelo() primeiro.")

        # [AJUSTE] aceitar unidade alfa-numérica (ex.: VG0196), e não apenas dígitos
        unidade_regex = r'(Bloco:\s*\w+\s+Unidade:\s*\S+\s*[-–].+?Código do cliente:\s*\d+)'
        indices = [m.start() for m in re.finditer(unidade_regex, texto, flags=re.DOTALL | re.IGNORECASE)]
        indices.append(len(texto))

        unidades_dict = {}
        for i in range(len(indices) - 1):
            bloco = texto[indices[i]:indices[i + 1]]
            bloco = bloco.replace('\x0c', '').replace('\f', '')

            # [AJUSTE] unidade alfa-numérica
            cabecalho = re.search(
                r'Bloco:\s*(\w+)\s+Unidade:\s*(\S+)\s*[-–]\s*(.+?)\s+Código do cliente:\s*(\d+)',
                bloco, re.DOTALL | re.IGNORECASE
            )
            if not cabecalho:
                continue

            bloco_id, unidade_raw, nome, cod_cliente = cabecalho.groups()
            unidade_raw = unidade_raw.strip()
            # Zero-pad só se for número puro
            if unidade_raw.isdigit():
                unidade_fmt = unidade_raw.zfill(4)
            else:
                unidade_fmt = unidade_raw.upper()

            chave_unidade = f"{bloco_id}_{unidade_fmt}"

            campos = {col: "" for col in self.colunas_modelo}
            campos["Cód. Condomínio"] = "000000"
            campos["Cód. Bloco"] = bloco_id
            campos["Cód. Unidade"] = unidade_fmt
            campos["Nome"] = self.limpar_texto(nome)
            if "Código do Cliente" in campos:
                campos["Código do Cliente"] = cod_cliente

            # CPF/CNPJ
            campos["CPF/CNPJ"] = self.extrair_cpf_cnpj(bloco)

            # Emails / Telefones
            campos["E-mails"] = ", ".join(sorted(set(self.extrair_emails(bloco))))
            campos["Telefones Celular"] = ", ".join(sorted(set(self.extrair_celulares(bloco))))
            for tipo in ["residencial", "comercial"]:
                padrao = rf'Telefone {tipo}\s*-\s*([\d\s().-]+)'
                encontrados = re.findall(padrao, bloco, re.IGNORECASE)
                if tipo == "residencial":
                    campos["Telefones Residencial"] = ", ".join(sorted(set(encontrados)))
                else:
                    campos["Telefones Comercial"] = ", ".join(sorted(set(encontrados)))

            # Campos padrão
            tipo_unidade = self.extrair_texto_entre_campos(bloco, "Tipo de unidade", "Dias de prazo")
            tipo_unidade = tipo_unidade.strip() if tipo_unidade else ""
            # sem fallback para "VAGA": se vier vazio, fica vazio mesmo
            campos["Cód. Tipo Unidade"] = tipo_unidade


            campos["Tipo Corresp. Cobrança"] = self.extrair_valor_simples(bloco, "Tipo de correspondência", split_first_word=True)
            campos["Cód. Classificação Unidade"] = self.extrair_valor_simples(bloco, "Classificação", split_chars=["-"])
            campos["Aos Cuidados"] = self.extrair_ac_refinado(bloco)

            # Endereço (último do bloco)
            enderecos = re.findall(r'Endereço:\s*([^\n\r\f]+)', bloco)
            if enderecos:
                raw = enderecos[-1].strip()
                tipo_log, nome_rua, numero, bairro, cidade, estado, cep, compl = self.extrair_campos_endereco(raw)
                campos["Logradouro Cobrança"] = tipo_log
                campos["Endereço Cobrança"] = nome_rua
                campos["Número Cobrança"] = numero
                campos["Bairro Cobrança"] = bairro
                campos["Cidade Cobrança"] = cidade
                campos["Estado Cobrança"] = estado
                campos["CEP Cobrança"] = cep
                campos["Complemento Cobrança"] = compl

            # Frações/áreas
            fx = re.findall(r'Fração unidade:\s*([\d,.]+)', bloco)
            campos["Fração Unidade"] = self.converter_ponto_para_virgula(fx[-1]) if fx else ""
            mt = re.findall(r'Metragem total:\s*([\d,.]+)', bloco)
            campos["Metragem"] = self.converter_ponto_para_virgula(mt[-1]) if mt else ""
            ac = re.findall(r'Área construída:\s*([\d,.]+)', bloco)
            campos["Área Construída"] = self.converter_ponto_para_virgula(ac[-1]) if ac else ""

            # Frações extras
            for j in range(1, 10 + 1):
                campo_nome = f'Fração Extra {j}'
                matches = re.findall(rf'Fração extra {j}:\s*([\d,.]+)', bloco, flags=re.IGNORECASE)
                if campo_nome in campos:
                    campos[campo_nome] = self.converter_ponto_para_virgula(matches[-1]) if matches else ""

            if "Fração Garagem" in self.colunas_modelo:
                fg = re.findall(r'Fração garagem:\s*([\d,.]+)', bloco, flags=re.IGNORECASE)
                campos["Fração Garagem"] = self.converter_ponto_para_virgula(fg[-1]) if fg else ""

            # Mapeamento p/ modelo final
            campos_modelo = {
                col: campos.get(self.MAPEAMENTO.get(col, col), "") if self.MAPEAMENTO.get(col, col) else ""
                for col in self.colunas_modelo
            }
            unidades_dict[chave_unidade] = self.merge_dados(unidades_dict.get(chave_unidade, {}), campos_modelo)

        return list(unidades_dict.values())

    def processar_pdf(self, caminho_pdf: str, pdftotext_path: Optional[str] = None) -> Optional[pd.DataFrame]:
        if not os.path.exists(caminho_pdf):
            raise FileNotFoundError(f"PDF não encontrado: {caminho_pdf}")

        exe = _resolver_pdftotext(pdftotext_path)

        nome_txt = os.path.splitext(os.path.basename(caminho_pdf))[0] + ".txt"
        caminho_txt = os.path.join(self.pasta_saida, nome_txt)

        try:
            # usa o executável resolvido
            subprocess.run([exe, '-layout', caminho_pdf, caminho_txt], check=True)
            with open(caminho_txt, "r", encoding="utf-8") as f:
                texto = f.read()

            dados = self.extrair_dados(texto)
            if not dados:
                return None

            df = pd.DataFrame(dados)

            # Garante todas as colunas do modelo
            for col in self.colunas_modelo:
                if col not in df.columns:
                    df[col] = ""

            df = df[self.colunas_modelo]
            return df

        except subprocess.CalledProcessError as e:
            raise RuntimeError(f"Erro ao extrair texto do PDF: {e}")
        except Exception as e:
            raise RuntimeError(f"Erro ao processar PDF: {e}")

    def salvar_excel(self, df: pd.DataFrame, nome_arquivo: str = "relatorio_unidades_final.xlsx") -> str:
        if not self.pasta_saida:
            raise ValueError("Pasta de saída não configurada. Use configurar_pasta_saida() primeiro.")
        caminho_completo = os.path.join(self.pasta_saida, nome_arquivo)
        try:
            df.to_excel(caminho_completo, index=False)
            return caminho_completo
        except Exception as e:
            raise RuntimeError(f"Erro ao salvar Excel: {e}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Extrai dados de PDF e exporta para XLSX.')
    parser.add_argument('--pdf', required=True, help='Caminho do PDF de entrada')
    parser.add_argument('--modelo', required=True, help='Caminho do modelo XLSX (estrutura da planilha de destino)')
    parser.add_argument('--saida', required=True, help='Pasta de saída para o arquivo XLSX gerado')
    parser.add_argument('--modelo_nome', required=True, help='Nome do modelo de extração (ex: ahreas)')
    parser.add_argument("--pdftotext", help="Caminho do executável pdftotext (opcional).")

    args = parser.parse_args()

    try:
        config_path = os.path.join("config", f"{args.modelo_nome}.json")
        extrator = ExtractorPDF(config_path=config_path)
        extrator.configurar_modelo(args.modelo)
        extrator.configurar_pasta_saida(args.saida)

        df_resultado = extrator.processar_pdf(args.pdf, pdftotext_path=args.pdftotext)
        if df_resultado is not None:
            caminho_excel = extrator.salvar_excel(df_resultado, "relatorio_unidades_extraido.xlsx")
            print(f"OK: Dados extraídos e salvos em: {caminho_excel}")
            sys.exit(0)
        else:
            print("ERRO: Nenhum dado foi extraído do PDF.")
            sys.exit(1)
    except Exception as e:
        print(f"ERRO: {str(e)}")
        sys.exit(1)
