# Cleanalyze BBZ

Aplicação web (PHP + Python) da BBZ Administração de Condomínios para extrair dados de
relatórios em PDF e gerar planilhas de importação, comparar prestações de contas e consultar
documentos por meio do Assistente IA.

Detalhes de arquitetura e convenções de código estão no [CLAUDE.md](CLAUDE.md).

---

## ⚠️ Branch de trabalho

O desenvolvimento **não acontece no `main`**.

| Branch | Papel |
|---|---|
| **`codexprimeiro-teste`** | Branch ativa. É o que roda em produção. Todo trabalho novo sai daqui |
| `main` | Congelada na v1.2.0. Mantida apenas como histórico |

O `main` está dezenas de commits atrás e **não deve ser publicado em produção** — subir a
partir dele regride o Assistente IA, a Prestação de Contas v2 e, principalmente, remove os
volumes Docker que persistem usuários e arquivos enviados.

Antes de commitar, confirme em que branch você está:

```bash
git branch --show-current
```

---

## Ambiente de produção

| Item | Valor |
|---|---|
| URL | https://cleanalyze.desenvolvimentobbz.cloud |
| Servidor | VPS Hostinger — host `srv1475070`, Ubuntu 24.04 LTS |
| Caminho do projeto | **`/docker/cleanalyze-new`** |
| Acesso | Terminal web do painel da Hostinger |
| Container | `cleanalyze-new-cleanalyze-1`, porta 8080 |
| Branch publicada | `codexprimeiro-teste` |

### Deploy

**Não há CI/CD.** Enviar para o GitHub não publica nada — o deploy é manual e sempre precisa
reconstruir a imagem, porque o `Dockerfile` copia o código para dentro dela (`COPY . .`) e o
compose de produção não monta o código do disco.

```bash
cd /docker/cleanalyze-new
git pull
docker compose up -d --build
```

O `--build` é obrigatório: sem ele, o Compose sobe a mesma imagem antiga. O build leva por
volta de 5 minutos.

### Dados persistidos

Definidos em `docker-compose.yml`. Sobrevivem ao rebuild — **não apague estes volumes**:

| Volume | Conteúdo |
|---|---|
| `cleanalyze_auth-data` | Usuários e convites (`auth/data/`) |
| `cleanalyze_uploads` | PDFs enviados e planilhas geradas |
| `cleanalyze_rag` | Índice de documentos do Assistente IA |
| `cleanalyze_logs` | Logs da aplicação |

### Configuração

As chaves (Google OAuth, Mistral, OpenAI) vêm de variáveis de ambiente, a partir de um `.env`
no servidor — que é gitignored e **nunca deve ser commitado**. Veja `.env.example` para a
lista de variáveis esperadas.

### Cuidados no servidor

- Nunca rode `git reset --hard` nem `git checkout -- .` sem antes verificar `git status`.
- `.env` e `auth/data/*.json` são ignorados pelo git, mas existem no servidor. Um checkout
  descuidado não os apaga, porém vale conferir antes de qualquer operação destrutiva.

---

## Ambiente local

Não é preciso instalar PHP nem Python: o container faz tudo.

```bash
docker compose -f docker-compose.local.yml up -d --build
```

Acesse em http://localhost:8080/Cleanalyze

Diferente da produção, o compose local **monta o código do disco**, então alterações em
`.php` e `.py` valem no próximo F5, sem rebuild. Só é preciso reconstruir ao mexer no
`Dockerfile`, no `composer.json` ou no `requirements.txt`.

Para conferir a sintaxe de todos os arquivos PHP (só imprime se houver erro):

```bash
docker compose -f docker-compose.local.yml exec cleanalyze sh -c "find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors'"
```

---

## Modelos de extração

Cada modelo é um plugin em `cleanalize_plugins/` com um mapeamento em `config/`.

| Modelo | Relatório de origem | Planilha modelo |
|---|---|---|
| `ahreas` | Relatório de Unidades (Ahreas) | `modelo_planilha_importacao.xlsx` |
| `inadimplencia` | Inadimplência (Ahreas) | `modelo_planilha_inadimplencia.xlsx` |
| `lello` | Relação de Endereçamento (Lello) | `modelo_planilha_importacao.xlsx` |
| `lello_inadimplencia` | Cotas Atrasadas (Lello) | `modelo_planilha_inadimplencia.xlsx` |

Para adicionar um modelo novo, veja a seção correspondente no [CLAUDE.md](CLAUDE.md) — são
quatro pontos de alteração, e esquecer um dos formulários é o erro mais comum.
