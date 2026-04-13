# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Cleanalyze BBZ** is a hybrid PHP + Python web application for two workflows on Brazilian property management/condominium PDFs:
1. **Structured extraction**: PDF → plugin → XLSX for import (cadastro, inadimplência, prestação de contas)
2. **Assistente IA (RAG, v1.3.0+)**: PDF/image → Mistral OCR → embeddings → SQLite+sqlite-vec → chat with gpt-4o-mini grounded in the uploaded document

Built for BBZ Administração de Condomínio Ltda.

## Architecture

The app has two layers that communicate via shell execution (PHP calls Python CLI):

**PHP layer** (web interface + auth):
- `index.php` — Dashboard with nav cards for all modules
- `login.php` / `auth/bootstrap.php` — Session auth with JSON-based user store, 15-min idle timeout. `bootstrap.php` loads `config/env.php` at the top so `env()` is available everywhere
- `config/env.php` — Loads `.env` via `vlucas/phpdotenv` (fallback: simple native parser if `vendor/` not present yet). Exposes `env($key, $default)` helper
- `config/paths.php` — Auto-detects Windows (XAMPP) vs Docker paths for Python/Poppler/Tesseract
- `includes/navbar.php` — Shared navbar with Bootstrap 5 dropdowns: **Assistente IA** (badge "Novo"), **Cadastro** (Extrair/Comparar), **Prestação de Contas**, **Admin** (admin-only; Usuários). Parent dropdown is marked `.active` when any child matches `$activePage`. Inline `<style>` forces `.dropdown-item` text to `#04193b` with `!important` to override page-level `.navbar a { color:#fff !important }`
- `extrair-form.php` — PDF extraction form; `web/executar_extracao.php` is the orchestrator
- `comparar.php` — XLSX comparison tool with Levenshtein similarity + DOMPDF PDF export
- `prestacao_anual.php` — Prestação de Contas analysis (upload + loading overlay)
- `web/executar_prestacao_anual.php` — Analysis results with PDF export via DOMPDF
- `rag-assistente.php` — Assistente IA page: upload + doc list + chat panel. Has its own full-screen loading overlay (same visual molde as `prestacao_anual.php`, CSS prefixed `.rag-loading-*`)
- `web/rag-upload.php`, `rag-chat-api.php`, `rag-list-docs.php`, `rag-delete-doc.php`, `rag-download.php`, `rag-ocr-text.php` — Assistente IA backend endpoints (detailed in the RAG section below)
- `web/rag_common.php` — Shared helpers for all `rag-*.php`: `rag_require_user()`, `rag_sanitize_email()`, `rag_uuid()`, `rag_is_uuid()`, `rag_run_python()`, `rag_decode_cli_json()`, `rag_list_documents_php()`, `rag_get_chat_history_php()`
- `includes/loading-overlay.php` — Shared CSS/JS for loading overlays (used by extrair, comparar, prestacao). The RAG page has its own inline overlay (prefixed selectors) to avoid CSS collision

**Python layer** (extraction engine):
- `cleanalize_cli.py` — Main CLI orchestrator: PDF → text → plugin → normalize → map → XLSX
- `cleanalize_core/pdf_engine.py` — Text extraction with fallback chain: pdftotext → pdfplumber → OCR (pdf2image + Tesseract)
- `cleanalize_core/registry.py` — Plugin registry (register/get/choices)
- `cleanalize_core/config.py` — Loads `.env` via `python-dotenv` (fallback: simple native parser). Exposes `get_env(key, default, required=False)` and `project_root()`
- `cleanalize_plugins/` — Extraction plugins implementing `matches(texto)` and `extract_records(texto)`
- `analise_anual_cli.py` — Prestação de Contas CLI: slices 1 PDF into months, generates comparisons + anomaly detection
- `compare_prestacao_cli.py` — 1:1 Prestação comparison CLI (deprecated as standalone, functions reused by analise_anual_cli.py)

**Python layer — Assistente IA (RAG)**:
- `cleanalize_core/rag/mistral_ocr.py` — Wraps `mistralai.client.Mistral` (v2 SDK). `ocr_file(path)` returns `[OcrPage(page, text)]`. Has `_retry()` helper that retries on `httpx.ReadTimeout`/`ConnectTimeout` **and** `SDKError` with status `429` or `5xx` (NOT 4xx — those are our bug). Client built with `timeout_ms=180_000` (3min) when supported by SDK version
- `cleanalize_core/rag/chunking.py` — `chunk_pages(pages, chunk_size=800, overlap=100)` using `tiktoken` `cl100k_base` (fallback: char-based ≈ 4 chars/token). Preserves `page` number per chunk
- `cleanalize_core/rag/embeddings.py` — Wraps `openai.OpenAI` embeddings in batches of 64. `EMBEDDING_DIM = 1536` (text-embedding-3-small)
- `cleanalize_core/rag/store.py` — SQLite + `sqlite-vec` abstraction. One `.db` per user at `uploads/rag/<sanitized_email>/index.db`. Tables: `documents` (doc_id, filename, pages, status, error_message, created_at), `chunks` (chunk_id, doc_id, page, text), `chunks_vec` (virtual `vec0` table with `FLOAT[1536]`), `chat_history` (id, doc_id, role, content, sources_json, created_at). `delete_document()` also removes matching rows from `chunks_vec` by rowid (virtual tables don't cascade)
- `cleanalize_core/rag/chat.py` — Builds prompt with top-K retrieved chunks + last 6 history turns + system prompt, calls `gpt-4o-mini` with `temperature=0.2`. `SYSTEM_PROMPT` constrains the model to (a) identify as "Assistente de IA Cleanalyze, criado pela equipe de Desenvolvimento da BBZ", (b) answer only from provided chunks, (c) respond in pt-BR, (d) cite pages that appear verbatim in the chunks
- `rag_ingest.py` (root CLI) — Orchestrates ingestion: OCR → chunk → embed → insert into SQLite. Uses `store.mark_ready()`/`mark_error()` for status tracking
- `rag_query.py` (root CLI) — Embeds question → `store.search(doc_id, vec, top_k=5)` → `answer_question()` → persists both user+assistant turns in `chat_history`. Catches `sqlite3.IntegrityError` on chat_history insert to return a friendly "Documento foi removido durante a consulta" when the doc was deleted mid-query
- `rag_delete.py` (root CLI) — Idempotent delete: removes all tables + `files/<doc_id>/` directory. **Always use this CLI** for deletes — do NOT generate temp scripts in `/tmp/`, because Python prepends the script's directory to `sys.path` (not cwd), breaking `from cleanalize_core...` imports

**Plugin system**: Each plugin in `cleanalize_plugins/` must expose:
- `NOME` constant (e.g., `"ahreas"`, `"inadimplencia"`)
- `Extractor` class with static methods `matches(texto: str) -> bool` and `extract_records(texto: str) -> list[dict]`
- Plugins are registered in `cleanalize_cli.py` at import time

**Config JSONs** (`config/*.json`): Define `mapeamento` (column renaming from plugin fields to XLSX columns) and `normalizacao` (type conversions: `decimal_br`, `data_br`, `data_br_formatada`).

## Assistente IA (RAG) — data flow

**Ingestion** (`web/rag-upload.php`):
1. PHP validates ext (`pdf|png|jpg|jpeg|webp`) **and** magic bytes (`%PDF`, `\x89PNG`, `\xFF\xD8\xFF`, `RIFF...WEBP`) — rejects mismatches before touching Mistral
2. Generates server-side UUID v4 (`rag_uuid()`) — **never** accept `doc_id` from client
3. Moves upload to `uploads/rag/<user>/files/<doc_id>/original.pdf`
4. Calls `rag_ingest.py` via `proc_open` (helper `rag_run_python`)
5. On Python error, translates to user-friendly message (`Status 429` → "sobrecarregado", `Status 5xx`/`Internal Server Error` → "falha temporaria", `ReadTimeout` → "demorou demais", `zero paginas` → "nao foi possivel extrair texto"). Raw error still logged via `app_log('rag.ingest.error', ...)`

**Query** (`web/rag-chat-api.php`):
1. POST `{doc_id, question}` as JSON or form
2. Validates `doc_id` is UUID, `question` non-empty and ≤2000 chars
3. Calls `rag_list_documents_php()` to verify ownership and `status=='ready'`
4. Calls `rag_query.py --stdin` (question via stdin to avoid shell-arg length limits)
5. Returns `{ok, answer, sources}`. Logs `rag.query.ok`/`rag.query.error`

**Delete** (`web/rag-delete-doc.php`): ownership check → `rag_delete.py` CLI → idempotent (returns `ok:true, noop:true` if doc already gone — handles double-click / multi-tab)

**Download** (`web/rag-download.php`): ownership check → `realpath()` + prefix validation against `rag_user_files_dir($email)` → `readfile()` with `Content-Disposition: attachment; filename*=UTF-8''...` (RFC 5987) for accent preservation

**OCR text copy** (`web/rag-ocr-text.php`): returns the contents of `files/<doc_id>/ocr.txt` as JSON for client-side `navigator.clipboard.writeText()`

**Storage layout**:
```
uploads/rag/                      ← Docker volume `cleanalyze_rag`
└── <sanitized_email>/            ← regex [^a-z0-9]+ → _, from email lowercased
    ├── index.db                  ← SQLite + sqlite-vec
    └── files/
        └── <doc_id>/
            ├── original.pdf
            └── ocr.txt           ← Mistral OCR markdown, used by "copiar texto"
```
Email sanitization is **identical** in Python (`store.sanitize_email()`) and PHP (`rag_sanitize_email()`) — both produce `ti_bbz_com_br` from `ti@bbz.com.br`. Do not diverge.

## Common Commands

### Local Development (Windows/XAMPP)
```bash
# Run extraction CLI
py -3 cleanalize_cli.py --pdf "input.pdf" --tipo inadimplencia --config config/inadimplencia.json --modelo modelo_planilha_inadimplencia.xlsx --saida output/saida.xlsx

# With OCR fallback
py -3 cleanalize_cli.py --pdf "input.pdf" --tipo ahreas --config config/ahreas.json --ocr --tesseract "C:/Program Files/Tesseract-OCR/tesseract.exe" --poppler "C:/poppler/Library/bin" --debug-save-text

# PHP local server (or use XAMPP)
php -S localhost:8080
```

### Docker (Local Development — preferred, no PHP/Python install needed)
```bash
cp .env.example .env         # fill GOOGLE_CLIENT_ID, MISTRAL_API_KEY, OPENAI_API_KEY
docker compose -f docker-compose.local.yml up -d --build   # build + start on port 8080
docker compose -f docker-compose.local.yml logs -f          # view logs
docker compose -f docker-compose.local.yml down             # stop
# Access: http://localhost:8080/Cleanalyze
```

### Docker (Production — Hostinger, web terminal, no SSH)
Hostinger VPS uses its own Nginx reverse proxy in front of the container (no Traefik, no external network). Deploy via the web terminal in the Hostinger panel:
```bash
cd /docker/cleanalyze-new
# One-time only: create .env on the server (never committed)
nano .env  # paste GOOGLE_CLIENT_ID, MISTRAL_API_KEY, OPENAI_API_KEY
# Every deploy after that:
git pull origin <branch> && docker compose build && docker compose up -d
```
`docker-compose.yml` uses `${VAR}` substitution (no `env_file:` and no `.env` volume mount), so it works whether vars come from a local `.env` (auto-loaded by compose from cwd) or from shell env vars. Both PHP (`config/env.php`) and Python (`cleanalize_core/config.py`) fall back to `getenv()`/`os.environ` when no physical `.env` file is present inside the container.

### Dependencies
```bash
composer install             # PHP deps: PHPSpreadsheet, DOMPDF, vlucas/phpdotenv
pip install -r requirements.txt   # Python deps: pdfplumber, pdf2image, pytesseract, mistralai, openai, tiktoken, sqlite-vec, python-dotenv, httpx
```
`vendor/` is **intentionally tracked in Git** despite being listed in `.gitignore` (Hostinger runs `docker compose build` which uses the committed vendor). When adding a new PHP package: run `composer update <package>` locally (or inside a temp `composer:2` container) to regenerate `composer.lock`, then `git add -f vendor/` to force-add the new vendor files.

### Environment variables (`.env`)
Never hardcode secrets. `.env` is in `.gitignore`. `.env.example` is a committed template with all keys blank. Full list:
- `GOOGLE_CLIENT_ID` — OAuth Client ID (public; can be safely shared)
- `MISTRAL_API_KEY` — Mistral OCR API key
- `MISTRAL_OCR_MODEL` (default `mistral-ocr-latest`)
- `OPENAI_API_KEY` — OpenAI key for embeddings + chat
- `RAG_EMBEDDING_MODEL` (default `text-embedding-3-small`, 1536 dim — hardcoded in `EMBEDDING_DIM`)
- `RAG_CHAT_MODEL` (default `gpt-4o-mini`)
- `RAG_CHUNK_SIZE` (default 800 tokens), `RAG_CHUNK_OVERLAP` (default 100), `RAG_TOP_K` (default 5)

## Key Conventions

- **Language**: All code comments, variable names, and UI are in Brazilian Portuguese
- **Brazilian data formats**: Dates as DD/MM/YYYY, decimals with comma separator (1.234,56), address parsing for Brazilian street types
- **Encoding**: Must handle cp1252 ↔ UTF-8 (OCR output and Windows legacy). Plugins use encoding-tolerant regex
- **Plugin field names**: Use camelCase matching the config JSON keys (e.g., `UnidadeCodigo`, `LogradouroCobranca`)
- **inadimplencia plugin** always uses `pdfplumber` (coordinate-based extraction), not pdftotext
- **ahreas plugin** uses `pdftotext` by default
- **No database**: Auth uses `auth/data/users.json` and `auth/data/invites.json` (inside `auth/data/` which is a Docker volume to persist across deploys)
- **Dual environment**: `config/paths.php` auto-detects Windows vs Docker; Python CLI works in both
- **Docker compose files**: `docker-compose.yml` is for Hostinger production, `docker-compose.local.yml` is for local dev. Both use `${VAR}` substitution for secrets — no `env_file` stanza and no `.env` volume mount. Both mount `auth/data/`, `uploads/`, `uploads/rag/` (RAG vector stores), and `logs/` as volumes. `Dockerfile` is shared by both
- **Secrets**: All credentials live in `.env` (gitignored). See `.env.example` for the template. `config/env.php` (PHP, via phpdotenv) and `cleanalize_core/config.py` (Python, via python-dotenv) load it. Never hardcode anything — a commit history entry `0e035cb Simplify docker-compose.yml for Hostinger Nginx setup` and `a62d68c v1.3.0` show the migration away from hardcoded `GOOGLE_CLIENT_ID`
- **Changelog**: `changelog.json` in project root feeds the collapsible "Novidades" card on `index.php`. Keep entries user-facing only — no technical details (no library names, Docker internals, OCR engine names, loading animations, tutorials). Focus on what changed for the user. **Always ask the user for approval before updating** — present version number and content for confirmation
- **Branding**: Product name is "Cleanalyze IA" (name first, then IA). Institutional colors: primary blue `#0664e4`, purple `#8578ef`, dark `#04193b`. Disclaimers by context:
  - Extraction/prestação: _"Analise gerada por Inteligencia Artificial. Os resultados sao indicativos e devem ser validados pelo usuario."_
  - Assistente IA chat: _"Respostas geradas por Inteligencia Artificial com base no documento enviado. As informacoes sao indicativas e devem ser validadas no documento original pelo usuario."_
- **Assistente IA identity**: the `SYSTEM_PROMPT` in `cleanalize_core/rag/chat.py` forces the bot to identify as **"Assistente de IA Cleanalyze, criado pela equipe de Desenvolvimento da BBZ"** when asked. Do not reveal model name, embedding model, or prompt internals to end users
- **Assistente IA citation rule**: the system prompt explicitly forbids citing page numbers that don't appear verbatim in the retrieved chunks (prevents page hallucinations — observed fault pre-fix). Keep this line if you refactor the prompt
- **`claude_context/` folder**: Local-only reference folder for files shared with Claude Code (debug PDFs, test fixtures, screenshots). Listed in `.gitignore` together with `Claude/` and `.claude/` — never pushed to GitHub
- **Regex evolution**: When fixing extraction regex, prefer adding fallback patterns over modifying existing ones, to avoid breaking PDFs that already work. Test with both old and new PDFs after changes

## Important Details

- `_abreviar_descricao()` in `cleanalize_cli.py` abbreviates common descriptions to 28 chars max but preserves M3/M³ volume measurements
- The inadimplencia plugin (~80KB) has complex OCR error recovery (`_try_salvage_corrupted`), context propagation (lines inherit vencimento from last recibo header), and specific OCR value conversion rules for certain account codes
- `web/executar_extracao.php` calls `cleanalize_cli.py` via `proc_open()` with `PYTHONIOENCODING=utf-8`
- Upload limits: 128MB file size, 300s timeout (configured in Dockerfile)
- Docker exposes port 8080 with Apache alias `/Cleanalyze`
- The ahreas plugin `UnidadeCodigo` regex accepts alphanumeric codes (`[A-Za-z0-9]{1,10}`) — not just digits. Changed in v1.2.0 to support codes like LOJA01, CONSTR, C00118
- **Prestação de Contas PDF header**: Some PDFs use `Condominio:` (no accent) and others use `Condomínio:` (with accent). The regex in `compare_prestacao_cli.py` uses `^Condom[ií]nio:` to match both, anchored to line start to avoid matching mid-line honorário entries
- **Admin-only technical details**: `web/executar_prestacao.php`, `web/executar_extracao.php`, and `web/diagnostico.php` restrict technical details/diagnostics to admin users via `auth_is_admin()`. `diagnostico.php` requires admin for the entire page via `auth_require_admin()`
- **Shared navbar**: All authenticated pages use `includes/navbar.php` (set `$activePage` before including). Pages in `web/` include it with `__DIR__ . '/../includes/navbar.php'`
- **comparar.php PDF export**: Exports only columns with differences + up to 3 identifier columns for context. Uses `ini_set('memory_limit', '1G')` during export to handle large spreadsheets
- Bootstrap JS (`bootstrap.bundle.min.js`) is loaded in `index.php` for the changelog collapse feature
- **Prestação de Contas Anual** (`analise_anual_cli.py`): Slices expenses by month using individual transaction dates. Validates period: <12 months = error, >13 months = auto-trim to last 13. Compares same-month-year-ago + previous-month. Detects new/absent accounts and subcategories. Fundo de Reserva alert only fires for debits in the LAST month
- **document.write() pattern**: `prestacao_anual.php` and `extrair-form.php` use `fetch()` + `document.write()` to show loading overlays. This means result page HTML renders with the browser URL of the upload page (root), not `web/`. All links in result pages must work from root context (`web/download.php`, `prestacao_anual.php`, etc.)
- **Deprecated pages**: `prestacao.php`, `prestacao_v2.php` → 301 redirect to `prestacao_anual.php`. `web/executar_prestacao.php`, `web/executar_prestacao_v2.php` → GET redirect, POST kept temporarily for active sessions
- **Security: file path validation**: Any PHP endpoint that accepts a file path from POST (e.g., `json_path` for PDF export) must validate with `realpath()` + prefix check against `$UPLOADS_DIR`. Never pass user-supplied paths directly to `file_get_contents()` or shell commands
- **Security: authentication**: All pages in `web/` must include `auth/bootstrap.php` and call `auth_require_login()`. `download.php` was added in v1.2.2
- **Security: shell commands**: Always use `escapeshellarg()` for all arguments in shell commands (see `executar_prestacao_anual.php` as reference pattern). Never use string interpolation with double quotes for shell args
- **Security: commit messages**: Never mention specific vulnerability types (path traversal, injection, etc.) in commit messages — describe what was added, not what was exploitable
- **Assistente IA — PHP calls Python**: All `web/rag-*.php` endpoints that spawn Python use `rag_run_python('<script>.py', [args])` from `rag_common.php`. Scripts must live in the project root so Python's auto-added `sys.path[0]` includes the repo (enables `from cleanalize_core.rag...` imports). Never write temp scripts into `/tmp/` — imports will fail with `ModuleNotFoundError` because Python prepends the script's directory (`/tmp`), not the `cwd`, to `sys.path`
- **Assistente IA — Mistral SDK version**: `mistralai` v2.x exposes the client at `mistralai.client.Mistral` (not `mistralai.Mistral` like v1.x). `mistral_ocr.py` tries the v2 path first and falls back. v2's `SDKError` lives at `mistralai.client.errors.SDKError` and exposes `raw_response.status_code` for retry decisions
- **Assistente IA — sqlite-vec virtual table**: `chunks_vec` is a `USING vec0(embedding FLOAT[1536])` virtual table. It does **not** respect `ON DELETE CASCADE` — `store.delete_document()` must manually `DELETE FROM chunks_vec WHERE rowid IN (...)` before dropping rows from `chunks`. `chunks.chunk_id` must equal `chunks_vec.rowid` for this to work
- **Assistente IA — concurrency**: current SQLite connection is opened per-request without `PRAGMA journal_mode=WAL`. Fine for current low-concurrency load (verified with 2 parallel queries against the same `.db`). If scaling to dozens of concurrent users per account, enable WAL in `store._connect()`
- **Assistente IA — retry policy**: `mistral_ocr._retry` retries `httpx.ReadTimeout`/`ConnectTimeout`/`RemoteProtocolError` and `SDKError` with status `429` or `5xx` (3 attempts, 1s→2s backoff). Do NOT retry 4xx — those are client errors (bad file, bad request) that retry won't fix
- **Assistente IA — file validation**: `rag-upload.php` checks both extension whitelist AND magic bytes (`%PDF`, `\x89PNG\r\n\x1a\n`, `\xFF\xD8\xFF`, `RIFF...WEBP`). This prevents wasted Mistral OCR credits on files that lie about their extension
- **Assistente IA — doc_id integrity**: Always generate UUID v4 server-side via `rag_uuid()`. Never accept a client-supplied `doc_id`. Validate all incoming `doc_id` params with `rag_is_uuid()` before any DB/file access
