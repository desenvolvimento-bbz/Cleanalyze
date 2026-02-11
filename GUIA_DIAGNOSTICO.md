# 🔧 Guia de Diagnóstico - Cleanalyze

## ✅ TESTE REALIZADO - Sistema Funcionando!

O script Python foi testado manualmente e **FUNCIONA PERFEITAMENTE**:
- ✅ Extraiu 1.070 registros de inadimplência
- ✅ Gerou arquivo XLSX de 52 KB
- ✅ Todas as bibliotecas funcionando
- ✅ OCR e pdftotext operacionais

---

## 🚀 Como Usar o Sistema

### 1. **Via Interface Web** (Recomendado)

1. Acesse: `http://localhost/Cleanalyze/index.php`
2. Faça login com suas credenciais
3. Selecione o tipo: **inadimplencia** ou **ahreas**
4. Faça upload do PDF
5. Clique em "Processar"
6. Aguarde (pode demorar 1-3 minutos para PDFs grandes)
7. Baixe a planilha XLSX gerada

### 2. **Via Linha de Comando** (Para testes)

```bash
cd c:\xampp\htdocs\Cleanalyze

python cleanalize_cli.py \
  --pdftotext "C:\poppler\Library\bin\pdftotext.exe" \
  --pdf "uploads/SEU_PDF.pdf" \
  --tipo inadimplencia \
  --config "config\inadimplencia.json" \
  --modelo "modelo_planilha_inadimplencia.xlsx" \
  --saida "uploads\Saida_TESTE.xlsx" \
  --ocr \
  --tesseract "C:\Program Files\Tesseract-OCR\tesseract.exe" \
  --ocr-lang "por+eng" \
  --poppler "C:\poppler\Library\bin" \
  --dpi 200 \
  --debug-save-text
```

---

## 🔍 Diagnóstico (Se não estiver funcionando via web)

### Passo 1: Executar Diagnóstico Automático

Acesse: `http://localhost/Cleanalyze/web/diagnostico.php`

Este script irá:
- ✅ Verificar configurações do PHP
- ✅ Testar todos os executáveis
- ✅ Verificar permissões de pastas
- ✅ Executar um teste completo
- ✅ Mostrar onde está o problema

### Passo 2: Verificar Logs

**Log do último comando executado:**
```
c:\xampp\htdocs\Cleanalyze\uploads\ultimo_comando.txt
```

**Log da aplicação:**
```
c:\xampp\htdocs\Cleanalyze\logs\app.log
```

**Texto extraído do PDF (debug):**
```
c:\xampp\htdocs\Cleanalyze\uploads\NOMEDOPDF.pdf.debug.txt
```

### Passo 3: Problemas Comuns

#### ⏱️ Problema: Timeout (Página demora e dá erro)

**Solução:**
1. Edite: `c:\xampp\php\php.ini`
2. Altere:
   ```ini
   max_execution_time = 600
   max_input_time = 600
   memory_limit = 512M
   ```
3. Reinicie o Apache

#### 🔒 Problema: Permissão Negada

**Solução (Windows):**
```cmd
icacls "C:\xampp\htdocs\Cleanalyze\uploads" /grant Users:F
```

#### 📦 Problema: Biblioteca Python faltando

**Solução:**
```cmd
pip install pandas pdf2image pytesseract openpyxl
```

#### 🐍 Problema: Python não encontrado

**Solução:**
1. Verifique o caminho em: `web\executar_extracao.php` linha 16
2. Execute no prompt: `where python`
3. Ajuste o caminho conforme necessário

---

## 📁 Estrutura de Arquivos

```
uploads/
├── 20260121_HHMMSS_nome_arquivo.pdf    # PDF enviado
├── 20260121_HHMMSS_nome_arquivo.pdf.debug.txt  # Texto extraído (debug)
├── Inadimplencia_20260121_HHMMSS.xlsx  # Planilha gerada
└── ultimo_comando.txt                  # Último comando executado
```

---

## 🛠️ Alterações Realizadas

### 1. **executar_extracao.php** - Melhorias
- ✅ Timeout aumentado para 10 minutos
- ✅ Memória aumentada para 512MB
- ✅ Display_errors ativado para debug
- ✅ Log do último comando executado

### 2. **diagnostico.php** - Novo arquivo
- ✅ Testa todas as configurações
- ✅ Verifica executáveis
- ✅ Testa permissões
- ✅ Executa teste real com PDF

---

## 📞 Próximos Passos

1. **Acesse o diagnóstico**: `http://localhost/Cleanalyze/web/diagnostico.php`
2. **Leia os resultados** e veja se há algum erro
3. **Se tudo estiver OK**, teste via web normalmente
4. **Se houver erro**, me envie:
   - Screenshot da página de diagnóstico
   - Conteúdo do arquivo `uploads\ultimo_comando.txt`
   - Mensagem de erro específica

---

## 💡 Dicas

- PDFs escaneados demoram mais (OCR ativo)
- PDFs com texto nativo são mais rápidos
- Use DPI 200 para melhor velocidade/qualidade
- O arquivo .debug.txt mostra o texto extraído

---

## 🎓 Aprendizado

Você está vendo na prática:
- **Backend (Python)**: Processa os dados
- **Frontend (PHP)**: Interface web e orquestração
- **Shell**: Comunicação entre PHP e Python
- **Logs**: Debug e rastreamento de problemas

Continue praticando! 💪
