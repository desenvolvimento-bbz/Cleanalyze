# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Cleanalyze BBZ** is a hybrid PHP + Python web application for two workflows on Brazilian property management/condominium PDFs:
1. **Structured extraction**: PDF → plugin → XLSX for import (cadastro, inadimplência, prestação de contas)
2. **Assistente IA (RAG, v1.3.0+)**: PDF/image → Mistral OCR → embeddings → SQLite+sqlite-vec → chat with gpt-4o-mini grounded in the uploaded document. Two document scopes coexist:
   - **Meus Documentos** (per-user): user uploads privately, only they can chat, download and delete
   - **Documentos BBZ** (global/shared, v1.3.2+): admin uploads official BBZ manuals, grants access per email, users chat with the same UI but cannot delete

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
- `rag-assistente.php` — Assistente IA page: upload + two doc lists (Meus Documentos + Documentos BBZ) + chat panel. Has its own full-screen loading overlay (same visual molde as `prestacao_anual.php`, CSS prefixed `.rag-loading-*`)
- `rag-admin-docs.php` — Admin-only page for managing Documentos BBZ: upload, list all with ACLs, grant/revoke access per email, delete. Requires `auth_require_admin()` at the top
- `web/rag-upload.php`, `rag-chat-api.php`, `rag-list-docs.php`, `rag-delete-doc.php`, `rag-download.php`, `rag-ocr-text.php` — Assistente IA user endpoints. All three of `rag-chat-api`, `rag-download` and `rag-ocr-text` resolve `doc_id` first against the user's own docs, then fall back to Documentos BBZ with ACL check
- `web/rag-global-list.php` — Lists Documentos BBZ accessible to the current user (read-only)
- `web/rag-admin-global-upload.php`, `rag-admin-global-delete.php`, `rag-admin-global-list-all.php`, `rag-admin-global-access.php` — Admin-only endpoints for Documentos BBZ CRUD + ACL (all gated by `rag_require_admin()`)
- `web/rag-admin-list-users.php` — Admin-only endpoint that lists all emails from `users.json` (email, role, is_self) for populating the view-as dropdown in the Assistente IA UI
- `web/rag_common.php` — Shared helpers for all `rag-*.php`: `rag_require_user()`, `rag_require_admin()`, `rag_sanitize_email()`, `rag_uuid()`, `rag_is_uuid()`, `rag_run_python()`, `rag_decode_cli_json()`, `rag_list_documents_php()`, `rag_get_chat_history_php()`, plus the `_global` variants: `rag_global_dir()`, `rag_global_files_dir()`, `rag_global_db()`, `rag_list_global_docs_for()`, `rag_get_global_doc()`, `rag_user_has_global_access()`, plus the view-as helpers: `rag_extract_as_user()`, `rag_effective_user()`
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
- `cleanalize_core/rag/mistral_ocr.py` — Wraps `mistralai.client.Mistral` (v2 SDK). `ocr_file(path)` returns `[OcrPage(page, text)]`. Has `_retry()` helper that retries on `httpx.ReadTimeout`/`ConnectTimeout` **and** `SDKError` with status `429` or `5xx` (NOT 4xx — those are our bug). Client built with `timeout_ms=180_000` (3min) when supported by SDK version. Calls `client.ocr.process(..., include_image_base64=True)` by default and `_replace_image_placeholders()` walks the page's `images` list, calls `image_description.describe_image()` on each base64 payload, and substitutes `![img-X](img-X)` markdown placeholders with `[Imagem: <descricao>]` before the chunker sees the text. Set `RAG_IMAGE_DESCRIPTIONS=0` to skip vision calls and keep raw markdown
- `cleanalize_core/rag/image_description.py` — Wraps `openai.OpenAI` chat completions with vision. `describe_image(image_base64, context=None)` calls `RAG_VISION_MODEL` (default `gpt-4o-mini`) with `detail=low` and a pt-BR system prompt focused on UI elements (buttons, fields, menus, actions). `max_tokens=200`, `temperature=0.0`. Failures are swallowed in `mistral_ocr._replace_image_placeholders()` so a bad image never breaks ingestion
- `cleanalize_core/rag/chunking.py` — `chunk_pages(pages, chunk_size=800, overlap=100)` using `tiktoken` `cl100k_base` (fallback: char-based ≈ 4 chars/token). Preserves `page` number per chunk
- `cleanalize_core/rag/embeddings.py` — Wraps `openai.OpenAI` embeddings in batches of 64. `EMBEDDING_DIM = 1536` (text-embedding-3-small)
- `cleanalize_core/rag/store.py` — SQLite + `sqlite-vec` abstraction, two scopes:
  - `RagStore(email, scope='user')` (default) — one `.db` per user at `uploads/rag/<sanitized_email>/index.db`. Tables: `documents`, `chunks`, `chunks_vec`, `chat_history` (no FK, to allow history about global docs)
  - `RagStore.global_store()` — shared `.db` at `uploads/rag/_global/index.db`. Same schema plus `document_access (doc_id, email, created_at)` with FK to `documents`. ACL methods: `grant_access`, `revoke_access`, `has_access`, `list_access`, `list_accessible_docs`, `list_all_docs_with_access` — all raise if called on a non-global scope via `_require_global()` guard
  - **Migration on `_init_schema`**: if a user DB still has the old FK on `chat_history.doc_id → documents.doc_id`, rebuilds the table without the FK (needed so a user can keep chat history about a global doc that doesn't exist in their own `documents` table). Runs automatically on first open
- `cleanalize_core/rag/chat.py` — Builds prompt with top-K retrieved chunks + last 6 history turns + system prompt, calls `gpt-4o-mini` with `temperature=0.0` (deterministic). `SYSTEM_PROMPT` enforces (a) identity "Assistente de IA Cleanalyze, criado pela equipe de Desenvolvimento da BBZ", (b) **binary answer policy** (info is in chunks → state as fact / info is missing → respond exactly "Nao encontrei essa informacao no documento."), (c) **hedge word blacklist** — forbids "parece", "talvez", "provavelmente", "aparentemente", "possivelmente", "deve ser", "acredito", "creio", "pode ser que", "e possivel que", "ao que tudo indica", (d) pt-BR, (e) cite pages verbatim, (f) treats `[Imagem: ...]` blocks in retrieved chunks as legitimate document content coming from OCR image descriptions
- `rag_ingest.py` (root CLI) — Ingests a file into the user or global store. Flag `--global` switches to `RagStore.global_store()` and uses `global_files_dir()` for artifacts; otherwise `--user` is required
- `rag_query.py` (root CLI) — Opens the user store, tries `get_document(doc_id)` locally, **falls back to `RagStore.global_store()` + `has_access()` check** if the doc isn't in the user's DB. Chat history is **always** written to the user's DB (never to `_global`), even when the conversation is about a global doc — privacy per user. Uses `contextlib.ExitStack` to manage both stores when needed. Catches `sqlite3.IntegrityError` on history insert (doc deleted mid-query) for a friendly error. Returns `is_global` flag in the JSON so the caller can tell scopes apart
- `rag_delete.py` (root CLI) — Idempotent delete with `--global` flag for Documentos BBZ. On global delete, also wipes `_global/files/<doc_id>/` directory. **Always use this CLI** for deletes — do NOT generate temp scripts in `/tmp/`, because Python prepends the script's directory to `sys.path` (not cwd), breaking `from cleanalize_core...` imports
- `rag_access.py` (root CLI, admin) — Subcommands `grant`, `revoke`, `check`, `list`, `list-all`, `accessible-for` for managing ACL of Documentos BBZ. All operate on `RagStore.global_store()`. Called by the `web/rag-admin-global-access.php` endpoint

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
├── _global/                      ← Documentos BBZ (shared, admin-managed)
│   ├── index.db                  ← documents, chunks, chunks_vec, document_access (ACL)
│   └── files/
│       └── <doc_id>/
│           ├── original.pdf
│           └── ocr.txt
└── <sanitized_email>/            ← regex [^a-z0-9]+ → _, from email lowercased
    ├── index.db                  ← documents, chunks, chunks_vec, chat_history (no FK)
    └── files/
        └── <doc_id>/
            ├── original.pdf
            └── ocr.txt           ← Mistral OCR markdown, used by "copiar texto"
```
Email sanitization is **identical** in Python (`store.sanitize_email()`) and PHP (`rag_sanitize_email()`) — both produce `ti_bbz_com_br` from `ti@bbz.com.br`. Do not diverge.

**Documentos BBZ (global scope) — cross-cutting rules**:
- Admin is the single manager. There is no "author" or "editor" role — `auth_is_admin()` from `auth/bootstrap.php` is the only gate (via `rag_require_admin()` in `rag_common.php`)
- ACL is per-email (not per-role). No "all users @bbz.com.br" shortcut in v1.3.2. Emails are stored lowercased and compared case-insensitively (`strtolower` on both sides)
- **Chat history lives in the user's DB**, never in `_global/index.db`. This is privacy-preserving: user A's conversation with "Manual de Cadastro" is not visible to user B, even if both have access to the same global doc. The `chat_history` table has **no FK on `doc_id`** specifically to allow rows pointing to global doc IDs
- Users with access can: **chat, download the PDF, copy the OCR text**. They **cannot** delete (delete button is hidden in the global doc card, and `rag-admin-global-delete.php` requires admin)
- The resolution order in `rag-chat-api.php`, `rag-download.php` and `rag-ocr-text.php` is: (1) user's own documents, (2) global documents with ACL check. Same for `rag_query.py`. If a doc_id exists in both a user's DB and in global (should never happen because UUIDs are v4), the user's local copy wins
- When a global doc is deleted, orphan rows may remain in user `chat_history` tables (they point to a doc_id that no longer exists). This is intentional and harmless: users can't re-select a doc that isn't in either list, so they'll never see the orphaned history. A future `rag_cleanup_orphans.py` can purge them if disk becomes a concern
- Admin UI at `rag-admin-docs.php`: upload form (with optional comma-separated "initial access emails" field), table of all globals with inline ACL management (pill per email + `x` to revoke + input to add)

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
- `RAG_VISION_MODEL` (default `gpt-4o-mini`) — used by `image_description.py` to describe screenshots in manuals
- `RAG_IMAGE_DESCRIPTIONS` (default `1`) — set to `0` to disable vision-based image descriptions during OCR ingestion (saves cost if your docs are text-only)
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
- **Documentos BBZ — schema migration**: The v1.3.2 release drops the FK on `chat_history.doc_id → documents.doc_id` to allow per-user history about global docs. Existing user DBs are auto-migrated on first `_init_schema()` call via `PRAGMA foreign_key_list` check + table rebuild. Do not re-introduce the FK in future schemas
- **Documentos BBZ — access check duplication**: There is deliberate duplication of access checks in PHP (`rag_user_has_global_access` before running Python) and in Python (`RagStore.has_access` inside `rag_query.py`). PHP check gives a fast 404 + audit log for unauthorized attempts; Python check is defense in depth in case someone calls the CLI directly. Keep both — removing either weakens the guard
- **Documentos BBZ — admin endpoints**: All `web/rag-admin-global-*.php` endpoints call `rag_require_admin()` at the top. This function returns 403 JSON for non-admins and redirects to login for unauthenticated. Never rely only on UI hiding (the admin dropdown in navbar is hidden for non-admins, but that's UX — enforcement is server-side)
- **Assistente IA — cache busting**: `rag-assistente.php` appends `?v=<filemtime>` to `assets/css/rag-chat.css` and `assets/js/rag-chat.js`. This invalidates the browser cache automatically whenever those files change (important because the JS reads `window.ragIsAdmin` from server-rendered HTML — a stale JS could leak admin-only UI to a non-admin who had the old version cached). Keep the pattern when adding new JS/CSS to RAG pages
- **Assistente IA — admin-gated UI elements**: `rag-assistente.php` renders `window.ragIsAdmin = true/false` based on `auth_is_admin()`. The JS uses this flag to hide the "copiar texto OCR" button in the Documentos BBZ card for non-admins. `rag-ocr-text.php` backend still serves the text to any user with ACL — the admin gate is currently UI-only. If you need defense-in-depth, add an admin check inside `rag-ocr-text.php` specifically for global docs
- **Assistente IA — View-as (admin)**: admins can "visualizar como" any other user via a dropdown in the admin bar at the top of `rag-assistente.php`. When active, `state.viewAsUser` holds the target email (persisted in `sessionStorage`), and every read-only request from the JS is wrapped by `withViewAs(url)` which appends `?as_user=<email>`. Server side, `rag_effective_user($acting, $readOnly, $auditLabel, $jsonBody)` in `rag_common.php` is the single entry point:
  - If no `as_user` is present → returns the acting user (normal flow)
  - If `as_user` is present but the endpoint is **not** read-only (`$readOnly = false`) → responds 403 "Operacoes de escrita nao sao permitidas em modo view-as". Endpoints in this category: `rag-upload.php`, `rag-chat-api.php`, `rag-delete-doc.php`. This enforces the read-only contract server-side — don't remove it even if the UI already blocks writes
  - If `as_user` is present, caller is admin, and target exists in `users.json` → logs `rag.admin.view_as.<auditLabel>` to `app.log` with `{admin, target}` and returns the target email. Read-only endpoints in this category: `rag-list-docs.php`, `rag-download.php`, `rag-ocr-text.php`
  - Target email lookup is case-insensitive but preserves the canonical casing from `users.json` when returning
  - The chat history, doc list and vector search all run against the **target user's DB** automatically, because `rag_effective_user()` just returns the email string that is then passed to `rag_list_documents_php()`, `rag_user_files_dir()`, Python CLIs, etc. No special code paths needed per-endpoint
  - **Never** extend view-as to write operations — the cost (tokens, history pollution, user confusion) is always higher than the debugging benefit. If you need to test chat behavior as a user, log in as them directly
- **Docker volume persistence**: user data lives entirely in named Docker volumes — `cleanalyze_rag` (vector stores + chat history + Documentos BBZ + ACLs), `cleanalyze_auth-data` (users.json, invites.json), `cleanalyze_uploads` (extraction uploads), `cleanalyze_logs` (app.log). These survive `docker compose build`, `up -d`, `down` and server reboots. **Never rename a volume** in `docker-compose.yml` without an explicit migration plan — Docker creates a fresh empty volume under the new name and the old one gets orphaned. **Never run** `docker compose down -v` or `docker volume rm cleanalyze_*` in production — both destroy data irreversibly. Schema migrations inside SQLite (like the FK drop on `chat_history` in v1.3.2) happen in-place on first `_init_schema()` call and preserve existing rows
- **Assistente IA — UI filename**: the JS strips `.pdf/.png/.jpg/.jpeg/.webp` from `filename` via `stripExt()` for display in the doc list and chat title, but keeps the real filename in state for download + backend calls. Admin page (`rag-admin-docs.php`) intentionally shows the full filename so admins can tell file types apart
