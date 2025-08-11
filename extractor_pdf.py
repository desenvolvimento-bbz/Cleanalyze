import subprocess
import pandas as pd
import re
import os
import unicodedata
from typing import List, Tuple, Optional
import argparse
import sys
import json
import os
import shutil
import sys
from typing import Optional

def _resolver_pdftotext(caminho_forcado: Optional[str] = None) -> str:
    """
    Retorna o executável `pdftotext` a usar.
    Ordem de resolução:
    1) argumento explícito (caminho_forcado), se existir
    2) variável de ambiente POPPLER_PDFTOTEXT
    3) `pdftotext` no PATH (shutil.which)
    4) locais comuns no Windows
    5) locais comuns em Linux/Mac
    Lança FileNotFoundError se não encontrar.
    """
    candidatos = []

    # 1) parâmetro explícito
    if caminho_forcado:
        candidatos.append(caminho_forcado)

    # 2) env var
    env = os.environ.get("POPPLER_PDFTOTEXT")
    if env:
        candidatos.append(env)

    # 3) no PATH
    which = shutil.which("pdftotext")
    if which:
        candidatos.append(which)

    # 4) caminhos comuns (Windows)
    if sys.platform.startswith("win"):
        comuns_win = [
            r"C:\Program Files\poppler\bin\pdftotext.exe",
            r"C:\Program Files\poppler-24.02.0\Library\bin\pdftotext.exe",
            r"C:\Program Files\poppler-23.11.0\Library\bin\pdftotext.exe",
            r"C:\poppler\Library\bin\pdftotext.exe",
        ]
        candidatos.extend(comuns_win)
    else:
        # 5) caminhos comuns (Linux/Mac)
        comuns_unix = [
            "/usr/bin/pdftotext",
            "/usr/local/bin/pdftotext",
            "/opt/homebrew/bin/pdftotext",  # macOS (Apple Silicon / Homebrew)
            "/usr/local/opt/poppler/bin/pdftotext",  # macOS (Intel / Homebrew)
        ]
        candidatos.extend(comuns_unix)

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
        self.colunas_modelo = []

        # Carrega config.json
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
        if not os.path.exists(pasta_saida):
            os.makedirs(pasta_saida, exist_ok=True)
        self.pasta_saida = pasta_saida

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
            if not valor or valor == ':' or valor == '-':
                return ""
            if split_first_word:
                return valor.split()[0] if valor.split() else ""
            if split_chars:
                for sep in split_chars:
                    if sep in valor:
                        valor = valor.split(sep)[0].strip()
            return valor
        return ""

    def extrair_texto_entre_campos(self, texto: str, campo_inicio: str, campo_fim: str) -> str:
        """Extrai texto entre dois campos específicos"""
        padrao = rf'{re.escape(campo_inicio)}\s*:\s*(.*?)(?={re.escape(campo_fim)}\s*:|$)'
        match = re.search(padrao, texto, re.DOTALL)
        if match:
            valor = match.group(1).strip()
            # Remove quebras de linha e espaços extras
            valor = re.sub(r'\s+', ' ', valor)
            if not valor or valor == ':' or valor == '-':
                return ""
            return valor
        return ""

    def extrair_ac_refinado(self, texto: str) -> str:
        """
        Extrai A/C com critérios rigorosos:
        - Deve estar presente no texto
        - Deve vir antes de "Unidade alugada" (se existir)
        - Deve conter nome e sobrenome (pelo menos duas palavras)
        - Para precisamente no final do valor A/C, sem capturar outros campos
        """
        try:
            # Primeiro, verifica se "A/C:" está presente
            if "A/C:" not in texto:
                return ""
            
            # Encontra as posições de "A/C:" e "Unidade alugada"
            posicao_ac = texto.find("A/C:")
            posicao_unidade_alugada = texto.find("Unidade alugada")
            
            # Se "Unidade alugada" existe e "A/C:" vem depois, retorna vazio
            if posicao_unidade_alugada != -1 and posicao_ac > posicao_unidade_alugada:
                return ""
            
            # Se "Unidade alugada" existe, considera apenas o texto antes dela
            if posicao_unidade_alugada != -1:
                texto_filtrado = texto[:posicao_unidade_alugada]
            else:
                texto_filtrado = texto
            
            # Regex mais específica para capturar apenas o valor de A/C
            # Para em qualquer campo comum que possa aparecer após A/C
            ac_match = re.search(
                r'A/C\s*:\s*([^:\n]+?)(?=\s*(?:Tipo\s+de|Forma\s+de\s+envio|Locatário|Unidade\s+alugada|Endereço|Telefone|E-mail|CPF|CNPJ|Classificação|Fração|\s{2,}|\n\s*\w+\s*:|\n\n|$))', 
                texto_filtrado, 
                re.DOTALL | re.IGNORECASE
            )
            
            if ac_match:
                valor = ac_match.group(1).strip()
                
                # Remove quebras de linha e normaliza espaços
                valor = re.sub(r'\s+', ' ', valor)
                
                # Remove possíveis caracteres residuais no final
                valor = re.sub(r'[:\-\s]+$', '', valor)
                
                # Ignora casos inválidos básicos
                if not valor or valor in [':', '-', '']:
                    return ""
                
                # Ignora se contém apenas números (quebra de página)
                if valor.isdigit():
                    return ""
                
                # Ignora caracteres suspeitos de quebra de página
                if any(char in valor for char in ['Page', 'Página', '\f', '\x0c']):
                    return ""
                
                # Remove palavras que claramente não são nomes (como "Tipo", "de", etc.)
                palavras_filtradas = []
                palavras = valor.split()
                
                for palavra in palavras:
                    palavra_limpa = re.sub(r'[^\w]', '', palavra)
                    # Ignora palavras comuns de campos ou muito curtas
                    if (palavra_limpa and 
                        not palavra_limpa.isdigit() and 
                        len(palavra_limpa) > 1 and
                        palavra_limpa.lower() not in ['tipo', 'de', 'da', 'do', 'para', 'com', 'por', 'em']):
                        palavras_filtradas.append(palavra)
                
                # CRITÉRIO PRINCIPAL: Deve conter pelo menos duas palavras válidas (nome e sobrenome)
                if len(palavras_filtradas) < 2:
                    return ""
                
                # Retorna apenas as duas primeiras palavras válidas (nome e sobrenome)
                return f"{palavras_filtradas[0]} {palavras_filtradas[1]}"
            
            return ""
            
        except Exception as e:
            # Proteção contra erros - retorna vazio em caso de exceção
            print(f"Erro ao extrair A/C: {e}")
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
        linhas = texto.split('\n')
        emails_ordenados = []
        for linha in linhas:
            padrao_email = r'\b[\w\.-]+@[\w\.-]+\.\w+\b'
            encontrados_linha = re.finditer(padrao_email, linha, flags=re.IGNORECASE)
            for match in encontrados_linha:
                email_limpo = match.group().lower().strip()
                if email_limpo not in emails_ordenados:
                    emails_ordenados.append(email_limpo)
        return emails_ordenados

    def extrair_celulares(self, texto: str) -> List[str]:
        padrao_telefone = r'[\(]?\d{2}[\)]?\s*9\s*\d{4}[-\s]?\d{4}|\b9\d{4}[-]?\d{4}\b|\b\d{11}\b|\b\d{2}\s*9\d{4}[-]?\d{4}'
        encontrados = re.findall(padrao_telefone, texto)
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
        """
        Captura prioritariamente CNPJ quando 'Tipo de pessoa: Jurídica' estiver presente.
        Caso contrário, procura CNPJ/CPF no bloco inteiro, sempre evitando pegar o CNPJ
        do condomínio (que está fora do bloco).
        """
        # 1) Caso clássico: linha com "Tipo de pessoa: Jurídica ... CNPJ: ..."
        m = re.search(
            r'Tipo\s+de\s+pessoa\s*:\s*Jur[ií]dica.*?CNPJ\s*:\s*([\d./-]{14,18})',
            bloco, re.IGNORECASE | re.DOTALL
        )
        if m:
            return m.group(1).strip()

        # 2) Procura CNPJ em qualquer lugar do bloco (preferência a CNPJ)
        m = re.search(r'CNPJ\s*:\s*([\d./-]{14,18})', bloco, re.IGNORECASE)
        if m:
            return m.group(1).strip()

        # 3) Como fallback, procura CPF
        m = re.search(r'CPF\s*:\s*([\d.-]{11,14})', bloco, re.IGNORECASE)
        if m:
            return m.group(1).strip()

        return ""

    def merge_dados(self, orig: dict, novo: dict) -> dict:
        for k, v in novo.items():
            if not orig.get(k) and v:
                orig[k] = v
        return orig

    def extrair_dados(self, texto: str) -> List[dict]:
        if not self.colunas_modelo:
            raise ValueError("Modelo não configurado. Use configurar_modelo() primeiro.")

        # Regex para pegar todas as ocorrências de unidade, até o início da próxima
        unidade_regex = r'(Bloco:\s*\w+\s+Unidade:\s*\d+\s*[-–].+?Código do cliente:\s*\d+)'  # pega cabeçalho até "Código do cliente"
        indices = [m.start() for m in re.finditer(unidade_regex, texto, flags=re.DOTALL)]
        indices.append(len(texto))  # último bloco vai até o final

        unidades_dict = {}
        for i in range(len(indices)-1):
            bloco = texto[indices[i]:indices[i+1]]
            bloco = bloco.replace('\x0c', '').replace('\f', '')  # remove quebras de página

            cabecalho = re.search(r'Bloco:\s*(\w+)\s+Unidade:\s*(\d+)\s*[-–]\s*(.+?)\s+Código do cliente:\s*(\d+)', bloco, re.DOTALL)
            if not cabecalho: 
                continue
            bloco_id, unidade_id, nome, cod_cliente = cabecalho.groups()
            chave_unidade = f"{bloco_id}_{unidade_id.zfill(4)}"

            campos = {col: "" for col in self.colunas_modelo}
            campos["Cód. Condomínio"] = "000000"
            campos["Cód. Bloco"] = bloco_id
            campos["Cód. Unidade"] = unidade_id.zfill(4)
            campos["Nome"] = self.limpar_texto(nome)
            if "Código do Cliente" in campos:
                campos["Código do Cliente"] = cod_cliente

            # CPF/CNPJ (primeiro do bloco)
            campos["CPF/CNPJ"] = self.extrair_cpf_cnpj(bloco)

            # E-mails e Telefones (busca tudo, elimina repetidos)
            campos["E-mails"] = ", ".join(sorted(set(self.extrair_emails(bloco))))
            campos["Telefones Celular"] = ", ".join(sorted(set(self.extrair_celulares(bloco))))
            for tipo in ["residencial", "comercial"]:
                padrao = rf'Telefone {tipo}\s*-\s*([\d\s().-]+)'
                encontrados = re.findall(padrao, bloco, re.IGNORECASE)
                if tipo == "residencial":
                    campos["Telefones Residencial"] = ", ".join(sorted(set(encontrados)))
                else:
                    campos["Telefones Comercial"] = ", ".join(sorted(set(encontrados)))

            # CAMPOS PADRÃO
            campos["Tipo Corresp. Cobrança"] = self.extrair_valor_simples(bloco, "Tipo de correspondência", split_first_word=True)
            campos["Cód. Classificação Unidade"] = self.extrair_valor_simples(bloco, "Classificação", split_chars=["-"])
            campos["Cód. Tipo Unidade"] = self.extrair_valor_simples(bloco, "Tipo de unidade", split_first_word=True)
            campos["Aos Cuidados"] = self.extrair_ac_refinado(bloco)

            # Endereço: pegue sempre o último do bloco
            enderecos = re.findall(r'Endereço:\s*([^\n\r\f]+)', bloco)
            if enderecos:
                raw = enderecos[-1].strip()
                tipo_logradouro, nome_rua, numero, bairro, cidade, estado, cep, complemento = self.extrair_campos_endereco(raw)
                campos["Logradouro Cobrança"] = tipo_logradouro
                campos["Endereço Cobrança"] = nome_rua
                campos["Número Cobrança"] = numero
                campos["Bairro Cobrança"] = bairro
                campos["Cidade Cobrança"] = cidade
                campos["Estado Cobrança"] = estado
                campos["CEP Cobrança"] = cep
                campos["Complemento Cobrança"] = complemento

            # Fração unidade: último valor do bloco
            fracao_matches = re.findall(r'Fração unidade:\s*([\d,.]+)', bloco)
            campos["Fração Unidade"] = self.converter_ponto_para_virgula(fracao_matches[-1]) if fracao_matches else ""
            metragem_matches = re.findall(r'Metragem total:\s*([\d,.]+)', bloco)
            campos["Metragem"] = self.converter_ponto_para_virgula(metragem_matches[-1]) if metragem_matches else ""
            area_construida_matches = re.findall(r'Área construída:\s*([\d,.]+)', bloco)
            campos["Área Construída"] = self.converter_ponto_para_virgula(area_construida_matches[-1]) if area_construida_matches else ""

            # Frações extras
            for j in range(1, 11):
                campo_nome = f'Fração Extra {j}'
                matches = re.findall(rf'Fração extra {j}:\s*([\d,.]+)', bloco)
                if campo_nome in campos:
                    campos[campo_nome] = self.converter_ponto_para_virgula(matches[-1]) if matches else ""

            if "Fração Garagem" in self.colunas_modelo:
                fracao_garagem_matches = re.findall(r'Fração garagem:\s*([\d,.]+)', bloco)
                campos["Fração Garagem"] = self.converter_ponto_para_virgula(fracao_garagem_matches[-1]) if fracao_garagem_matches else ""

            # MERGE inteligente para consolidar todos os blocos da mesma unidade (chave = bloco+unidade)
            campos_modelo = {
                col: campos.get(self.MAPEAMENTO.get(col, col), "") if self.MAPEAMENTO.get(col, col) else ""
                for col in self.colunas_modelo
            }
            unidades_dict[chave_unidade] = self.merge_dados(unidades_dict.get(chave_unidade, {}), campos_modelo)


        return list(unidades_dict.values())
    
    def processar_pdf(self, caminho_pdf: str, pdftotext_path: Optional[str] = None) -> Optional[pd.DataFrame]:
        exe = _resolver_pdftotext(pdftotext_path)
        # ... use `exe` no subprocesso, ex:
        # subprocess.run([exe, "-layout", caminho_pdf, "-"], check=True, stdout=PIPE, stderr=PIPE)
        ...
        if not os.path.exists(caminho_pdf):
            raise FileNotFoundError(f"PDF não encontrado: {caminho_pdf}")
        # Nome do .txt igual ao PDF, mas na pasta de output
        nome_txt = os.path.splitext(os.path.basename(caminho_pdf))[0] + ".txt"
        caminho_txt = os.path.join(self.pasta_saida, nome_txt)
        try:
            subprocess.run([exe, '-layout', caminho_pdf, caminho_txt], check=True)
            with open(caminho_txt, "r", encoding="utf-8") as f:
                texto = f.read()
            # NÃO apague o TXT!
            dados = self.extrair_dados(texto)
            if not dados:
                return None

            # --- DIAGNÓSTICO (remova se não quiser prints) ---
            print("Colunas do modelo:", self.colunas_modelo)
            print("Chaves do dict extraído:", list(dados[0].keys()) if dados else [])

            # Cria DataFrame a partir dos dados extraídos
            df = pd.DataFrame(dados)

            # Garante que todas as colunas do modelo existam no DataFrame, mesmo se vazias
            for col in self.colunas_modelo:
                if col not in df.columns:
                    df[col] = ""

            # Reordena as colunas exatamente na ordem do modelo
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
        # Define caminho do config com base no modelo_nome
        config_path = os.path.join("config", f"{args.modelo_nome}.json")
        extrator = ExtractorPDF(config_path=config_path)
        extrator.configurar_modelo(args.modelo)
        extrator.configurar_pasta_saida(args.saida)

        df_resultado = extrator.processar_pdf(args.pdf)
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
