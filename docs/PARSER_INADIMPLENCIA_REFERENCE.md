# Parser de Inadimplência — Referência Técnica Completa

> **Objetivo deste documento:** Colar no Claude Chat para dar contexto completo sobre o parser.
> Arquivo: `cleanalize_plugins/inadimplencia.py`

---

## 1. Visão Geral

Parser que converte PDFs de "Relação Analítica de Pendentes" (relatório de inadimplência condominial brasileiro) em planilhas XLSX. Usa **pdfplumber** para extração de texto (coordenadas), gerando um `.debug.txt` intermediário que é o input real do parser.

### Arquivos do projeto

| Arquivo | Papel | Pode editar? |
|---------|-------|--------------|
| `cleanalize_plugins/inadimplencia.py` | Parser principal (toda a lógica) | SIM |
| `cleanalize_cli.py` | CLI que chama o parser | NÃO |
| `cleanalize_core/pdf_engine.py` | Engine de extração PDF | NÃO |
| `config/inadimplencia.json` | Config de colunas/mapeamento | SIM |

### Schema de saída (cada registro)

```python
{
    "Condomínio": "0673",        # Código 4 dígitos
    "ContaBancaria": "0673",     # = Condomínio
    "Bloco": "01",
    "Unidade": "000022",         # 6 dígitos zero-padded
    "Recibo": "14867547",        # 5-10 dígitos
    "Vencimento": "08/10/2025",  # DD/MM/YYYY
    "Conta": "434",              # 1-8 dígitos
    "Histórico": "REPO.DESP.EXTRAORDINARIA 2/4",  # Truncado a 28 chars no CLI
    "Complemento": "",           # Resto após 28 chars (ou texto completo em M3)
    "Valor": "95,31",            # Formato BR: X.XXX,XX
}
```

---

## 2. Estrutura do Texto de Entrada (.debug.txt)

Exemplo real de bloco típico:
```
Relação Analítica de Pendentes
Período de: 01/01/1901 até 31/10/2025 Posição em: 31/10/2025
Recibo VencimentoEmissão ContaHistórico Valor Total recibo Total
Condomínio: 0673 - CONDOMÍNIO L' ABITARE
Bloco: 01 Unidade: 000022 EDSON LUIZ DA SILVA CPF: 063.542.838-50
12062058 J 08/04/2022217956 9136TX CONDOMINIO AREA COMUM 226,84
2 CONDOMINIO TORRE 392,71 619,55
Legenda: (A) - Acordo (AE) - Acordo Extrajudicial ...
 - Recibo do Inadimplência Zero Emitido em 17/11/2025 10:25:13 - Página 1 de 30
```

### Tipos de linha

| Tipo | Exemplo | Tratamento |
|------|---------|------------|
| **Cabeçalho relatório** | `Relação Analítica de Pendentes` | Ignorar (noise) |
| **Período** | `Período de: 01/01/1901 até...` | Ignorar (noise) |
| **Cabeçalho colunas** | `Recibo VencimentoEmissão Conta...` | Detecta posições de coluna |
| **Condomínio** | `Condomínio: 0673 - L' ABITARE` | Atualiza `condominio_atual` |
| **Bloco/Unidade** | `Bloco: 01 Unidade: 000022 NOME CPF:...` | Atualiza `bloco_atual`, `unidade_atual` |
| **Recibo header** | `14867547 08/10/2025252642 2 TX COND...` | `parse_recibo_header()` → cria registro |
| **Continuação** | `434 REPO.DESP.EXTRAORDINARIA 2/4 95,31 997,05` | Herda Recibo/Vencimento do header acima |
| **Legenda** | `Legenda: (A) - Acordo ...` | Ignorar (noise) |
| **Rodapé** | `Emitido em 17/11/2025 ... Página 1 de 30` | Ignorar (noise) |
| **Total** | `Quantidade de Unidades... Total: 490.075,31` | Ignorar (`_is_total_line`) |
| **Corrompida** | `5Bl0o3c5o5: 0 Unid1a de:1 01/0037...` | Tentar salvar via `_try_salvage_corrupted()` |

---

## 3. Fluxo do `extract_records()`

```
Texto bruto
  │
  ├─ PRÉ-PROCESSAMENTO (por linha)
  │   ├─ Cabeçalho de colunas → mantém (define col_positions)
  │   ├─ _is_pure_header_footer() → descarta (se sem valor monetário)
  │   ├─ _is_corrupted_line() → _try_salvage_corrupted() ou descarta
  │   ├─ _normalizar_linha_recibo() → remove marcadores (—, ), J, etc.)
  │   ├─ _normalizar_layout_compacto() → separa data+emissão coladas
  │   └─ _split_multiple_accounts() → separa múltiplas contas na mesma linha
  │
  ├─ LOOP PRINCIPAL (linhas processadas)
  │   ├─ Vazia → skip
  │   ├─ Cabeçalho colunas → define col_positions
  │   ├─ _is_total_line() → skip, reset last_row_index
  │   ├─ _find_condominio() → atualiza condominio_atual
  │   ├─ _find_unit_header() → atualiza bloco_atual, unidade_atual
  │   ├─ is_continuation_line? (^\d{1,8}\s*\S, sem recibo+data)
  │   │   ├─ Extrai conta: ^(\d{1,8})
  │   │   ├─ Extrai valor: _extrair_valor_monetario_final()
  │   │   ├─ Sem valor → concatena ao histórico do registro anterior
  │   │   ├─ Com valor → cria registro, herda Recibo/Venc do anterior
  │   │   └─ Tracking: total_recibo para reconciliação OCR
  │   ├─ parse_recibo_header()? (recibo 5-10 dígitos + data + emissão)
  │   │   ├─ Reconcilia recibo anterior se trocou
  │   │   ├─ Atualiza recibo_atual, vencimento_atual
  │   │   ├─ Extrai conta do resto: ^(\d{1,8})\s*(.+)$
  │   │   ├─ Extrai valor: _extrair_valor_monetario_final()
  │   │   └─ Cria registro
  │   └─ Fallback → tenta extrair data + valor da linha
  │
  └─ PÓS-PROCESSAMENTO
      ├─ _reconciliar_recibo() para último recibo
      └─ _diagnosticar_linhas_nao_parseadas() (se debug=True)
```

---

## 4. Funções Principais

### `parse_recibo_header(line)` — Linha ~682

Regex forte para cabeçalho de recibo:
```python
r"^\s*(?P<recibo>\d{5,10})[^0-9]*?(?P<venc>\d{1,2}/\d{1,2}/\d{2,4})\s*(?P<emissao>\d{3,7})(?:\s+(?P<resto>.*))?"
```
- Valida data (`_is_valid_date`)
- Valida emissão (3-7 dígitos)
- Retorna `{recibo, vencimento, emissao, resto}`
- O `[^0-9]*?` entre recibo e data aceita lixo OCR (=, ~, —, etc.)

### `_extrair_valor_monetario_final(line, conta)` — Linha ~550

Extrai o VALOR DO ITEM (não o Total) da linha.

**Regex monetário central:**
```python
padrao_valor = r'-?\d{1,3}(?:\.\d{3})*,\d{2}(?![\d/])'
```

**Lookahead `(?![\d/])` é CRÍTICO** — rejeita:
- `161,163,165,166` (lista de apartamentos) → `161,16` seguido de `3` (dígito) → rejeitado
- `30,31/12` (data parcial) → `30,31` seguido de `/` → rejeitado

**Prioridade de extração:**
1. **Linha com M3**: Após `M3`/`M³`/`M?`/`M corrompido`:
   - PRIORIDADE 1: OCR inteiro (ex: `11` → `0,11`) — só contas da whitelist
   - PRIORIDADE 2: Primeiro valor formatado após M3
2. **Linha sem M3**: Pega PRIMEIRO valor formatado (FIRST, not LAST)
3. **FALLBACK 1**: Inteiro OCR no fim (ex: `2531` → `25,31`) — só whitelist
4. **FALLBACK 2**: Formato quebrado `1.12895` → `1.128,95` — só whitelist

### `_reconciliar_recibo(registros, ocr_indices, total_recibo)` — Linha ~934

Quando exatamente 1 item OCR no recibo e total_recibo conhecido:
```
valor_correto = total_recibo - soma(outros_itens)
```
Exemplo: `11` → `0,11` → reconciliado para `1,11` usando `total_recibo=1.322,16`.

### `_normalizar_layout_compacto(line)` — Linha ~322

Separa data+emissão coladas (só nos primeiros 50 chars):
```
"08/04/2022217956" → "08/04/2022 217956"
```

### `_normalizar_linha_recibo(line)` — Linha ~291

Remove marcadores entre recibo e data:
```
"543306 — ) 10/05/2022 10521 ..." → "543306 10/05/2022 10521 ..."
```
Marcadores aceitos: `—`, `-`, `)`, `(`, `J`, `AE`, `AJ`, `=J`, `*`, `#`, `£`, espaços.

### `_try_salvage_corrupted(line)` — Linha ~223

Recupera dados de linhas corrompidas por quebra de página:
- Fase 1: Procura conta isolada + valor monetário
- Fase 2: Extrai só letras, busca keywords (`RESERVA`→49, `BENFEITORIA`→1305, `DOMINIO`→7, etc.)

### `_split_multiple_accounts(line)` — Linha ~356

Separa linhas onde pdfplumber colou múltiplos lançamentos:
```
"671 TAXA LEITURA AGUA 5,37 892 FECH., ENTRADA/GAR 2531"
→ ["671 TAXA LEITURA AGUA 5,37", "892 FECH., ENTRADA/GAR 2531"]
```
Só funciona com contas conhecidas: `{7, 49, 563, 671, 892, 1305}`.

---

## 5. Regras de Negócio

### R1 — Conta 7 = Cabeçalho de recibo (SERENITA)
Linhas com Recibo + Vencimento + Conta 7 são estruturais. Criam registro normal mas historicamente eram cabeçalhos. Contexto (recibo_atual, vencimento_atual) sempre é atualizado.

### R2 — Valor = PRIMEIRO valor monetário
Para linhas sem M3, o PRIMEIRO valor é o item; valores subsequentes são TotalRecibo/Total.
```
"434 REPO.DESP.EXTRAORDINARIA 2/4 95,31 997,05 4.433,21"
  │ valor=95,31    │ total_recibo    │ total_unidade (ignorado)
```

### R3 — Conta grudada (glued)
Conta pode ter 1-8 dígitos e vir colada ao histórico sem espaço:
```
"9136TX CONDOMINIO AREA COMUM 226,84"  → Conta=9136, Hist=TX CONDOMINIO...
"1104COMPL PGTO PISSO TORRE 16,97"     → Conta=1104, Hist=COMPL PGTO...
"9624REFORMA ELEVADORES 14,94"         → Conta=9624, Hist=REFORMA ELEVADORES
```
Regex: `^(\d{1,8})\s*(.+)$` (note: `\s*` permite zero espaços)

### R4 — M3 e OCR
Linhas com medida de volume (`0,133 M3`) têm lógica especial:
- OCR inteiro após M3: `11` → `0,11` (divide por 100, insere vírgula)
- Só converte OCR para contas da whitelist: `{7, 49, 563, 671, 892, 1305}`
- Reconciliação pós-extração ajusta valor usando total_recibo

### R5 — Noise nunca reseta contexto
Linhas de ruído (cabeçalho, rodapé, legenda, paginação) são ignoradas mas NUNCA zeram `recibo_atual`, `vencimento_atual`, `bloco_atual`, `unidade_atual`.

### R6 — Continuações herdam contexto
Linhas de continuação (sem recibo+data próprios) herdam Recibo e Vencimento do registro anterior (`last_row_index_with_record`) ou do contexto global.

### R7 — Histórico = tudo entre Conta e Valor
```python
hist = texto_apos_conta[:texto_apos_conta.find(valor)].strip()
```
Usa `find()` (PRIMEIRO match), não `rfind()`, para não incluir o valor no histórico quando item=total.

---

## 6. Todas as Regex Críticas

### Valor monetário (padrão central)
```python
r'-?\d{1,3}(?:\.\d{3})*,\d{2}(?![\d/])'
```
- Aceita: `95,31`, `1.091,00`, `-234,56`, `0,11`
- Rejeita: `161,163` (apto), `30,31/12` (data), `0,133` (medida M3)

### Linha de recibo header
```python
r"^\s*(?P<recibo>\d{5,10})[^0-9]*?(?P<venc>\d{1,2}/\d{1,2}/\d{2,4})\s*(?P<emissao>\d{3,7})(?:\s+(?P<resto>.*))?"
```

### Layout compacto (data+emissão coladas)
```python
r'(\d{2}/\d{2}/\d{4})(\d{5,7})'  # nos primeiros 50 chars
```

### Detecção de linha principal vs continuação
```python
# Principal: recibo (5+ dígitos) + lixo OCR + data
r"^\s*\d{5,}[^\d]*?[0-3]?\d/[01]?\d/\d{2,4}"

# Continuação: 1-8 dígitos + (zero ou mais espaços) + não-espaço
r"^\s*\d{1,8}\s*\S"
```

### Conta no resto do header / continuação
```python
r"^(\d{1,8})\s*(.+)$"  # \s* = aceita conta grudada
```

### M3 e variantes
```python
r'(\d+,\d+)\s*[Mm]\s*[³3\?]'      # Normal: 0,133 M3 / M³ / M?
r'(\d+,\d+)\s+[Mm](?=\s+\d)'      # Corrompido: 0,146 M  0,68
```

### OCR inteiro após M3
```python
r'\s+(\d{1,6})(?:\s|$)'  # Ex: "  11  1.322,16" → captura "11"
```

### Noise (texto corrompido)
```python
r'[A-Za-z]\d[A-Za-z]|[A-Za-z]\d\d[A-Za-z]'  # ≥3 matches = corrompida
```

### Marcadores normalizados
```python
r'^(\s*\d{5,10})([^\d]+)(\d{1,2}/\d{1,2}/\d{2,4})(.*)'  # grupo 2 = marcadores
```

---

## 7. Tracking de total_recibo para Reconciliação OCR

```python
all_line_vals = re.finditer(padrao_valor, line)

# Caso 1: 2+ valores → último é total_recibo
if len(all_line_vals) >= 2 and all_line_vals[-1] != valor:
    current_recibo_total = all_line_vals[-1]

# Caso 2: valor via OCR (não está na lista) → único valor = total_recibo
elif len(all_line_vals) >= 1 and valor not in all_line_vals:
    current_recibo_total = all_line_vals[-1]
```

---

## 8. Constantes e Configurações

```python
DEBUG_PARSER = True  # Imprime motivo de cada linha ignorada

CONTAS_PERMITIDAS_CONVERSAO_OCR = {'7', '49', '563', '671', '892', '1305'}

CONTAS_CONHECIDAS = {'7', '49', '563', '671', '892', '1305'}  # Para _split_multiple_accounts

NOISE_PATTERNS = [
    "relação analítica de pendentes", "período de:", "posição em:",
    "especializada em condomínios", "legenda:", "emitido em",
    "genesis", "tipo do processo", "qtde de unidades",
    "quantidade de unidades", "página", "p4gina", "pagina",
    "(recibo", "£3- recibo",
]

IGNORE_HINTS = [  # Linhas de total
    "total do recibo", "total:", "total ", "subtotal",
    "acumulado", "saldo", "totais", "total geral"
]

_SALVAGE_KEYWORD_MAP = [  # Para linhas corrompidas
    ('RESERVA', '49', 'FUNDO RESERVA'),
    ('BENFEITORIA', '1305', 'MELHORIAS/BENFEITORIAS'),
    ('READEQUA', '1305', 'READEQUACAO'),
    ('DOMINIO', '7', 'CONDOMINIO'),
    ('CONDOMI', '7', 'CONDOMINIO'),
    ('TAXALEITURA', '671', 'TAXA DA LEITURA'),
    ('CONSUMO', '671', 'CONSUMO DE AGUA'),
]
```

---

## 9. Baselines de Teste (validados)

| PDF | Registros | Soma Valores | Sem Conta | OCR |
|-----|-----------|-------------|-----------|-----|
| SERENITA (01604) | 334 | - | 0 | `11`→`1,11` reconciliado |
| L'ABITARE (01541) | 1771 | R$ 490.075,31 exato | 0 | - |

---

## 10. Armadilhas Conhecidas (Pitfalls)

1. **`(?![\d/])` é obrigatório** no regex monetário. Sem ele, `161,163` (apartamentos) casa como `161,16` e `30,31/12` (data) casa como `30,31`.

2. **`\s*` (zero spaces)** no regex de conta. Se usar `\s+`, contas grudadas tipo `9136TX...` falham no match.

3. **`find()` não `rfind()`** para cortar histórico antes do valor. Com `rfind`, quando valor=total_recibo (ambos `1.091,00`), o primeiro valor vaza para dentro do histórico.

4. **Tracking OCR total_recibo**: Quando o valor foi extraído via OCR (inteiro sem vírgula), a linha pode ter apenas 1 valor monetário (o total_recibo). A condição `len >= 2` original não cobre esse caso.

5. **Layout compacto**: `_normalizar_layout_compacto` só opera nos primeiros 50 chars. Se expandir, quebrará valores monetários no corpo da linha.

6. **`[^\d]*?` (não char class fixa)** no regex de `is_main_record_line`. Lixo OCR entre recibo e data pode ser qualquer caractere (=, ~, —, espaço, etc.).

7. **`_formatar_historico`** trunca a 28 chars no campo `Histórico` e coloca o resto em `Complemento`. Exceção: linhas M3 preservam texto completo.

8. **Noise nunca reseta contexto**. Se resetar `recibo_atual`/`vencimento_atual` ao encontrar noise, as continuações perdem referência.

9. **`last_row_index_with_record = None`** deve ser setado quando um recibo header não gera registro (ex: apenas atualiza contexto). Caso contrário, continuações se associam ao registro errado.

10. **Windows console**: Evitar Unicode (→, ³) em output de teste. Usar `->` e `M3`.

---

## 11. Como Adicionar Suporte a Novo Layout

1. Obter o `.debug.txt` do PDF
2. Identificar quais linhas o parser ignora (rodar com `debug=True`)
3. Verificar se o problema é:
   - Regex de header não casa → ajustar `parse_recibo_header`
   - Conta grudada → garantir `\s*` nos regex de conta
   - Valor confundido → verificar lookahead `(?![\d/])`
   - Novo tipo de noise → adicionar a `NOISE_PATTERNS`
   - Linha corrompida → adicionar keyword a `_SALVAGE_KEYWORD_MAP`
4. Rodar os testes baseline (SERENITA=334, L'ABITARE=1771) para garantir zero regressão
5. Comparar `soma_valores` com o total do PDF (última linha: `Total: XXX.XXX,XX`)

---

## 12. Arquivos NÃO modificáveis

- `cleanalize_cli.py` — CLI, não alterar
- `cleanalize_core/pdf_engine.py` — Engine PDF, não alterar

Qualquer correção de parsing deve ser feita APENAS em `cleanalize_plugins/inadimplencia.py`.
