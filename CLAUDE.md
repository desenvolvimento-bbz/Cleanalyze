# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Cleanalyze BBZ** is a hybrid PHP + Python web application for extracting structured data from PDF reports (Brazilian property management/condominium documents) and exporting to XLSX spreadsheets. Built for BBZ Administração de Condomínio Ltda.

## Architecture

The app has two layers that communicate via shell execution (PHP calls Python CLI):

**PHP layer** (web interface + auth):
- `index.php` — Dashboard with extract/compare/prestacao navigation
- `login.php` / `auth/bootstrap.php` — Session auth with JSON-based user store, 15-min idle timeout
- `extrair-form.php` — PDF extraction form; `web/executar_extracao.php` is the orchestrator
- `comparar.php` — XLSX comparison tool with Levenshtein similarity + DOMPDF PDF export
- `prestacao_anual.php` — Annual Prestação de Contas analysis (upload + loading overlay)
- `web/executar_prestacao_anual.php` — Annual analysis results with PDF export via DOMPDF
- `config/paths.php` — Auto-detects Windows (XAMPP) vs Docker paths for Python/Poppler/Tesseract
- `includes/loading-overlay.php` — Shared CSS/JS for loading overlays (used by extrair, comparar, prestacao)

**Python layer** (extraction engine):
- `cleanalize_cli.py` — Main CLI orchestrator: PDF → text → plugin → normalize → map → XLSX
- `cleanalize_core/pdf_engine.py` — Text extraction with fallback chain: pdftotext → pdfplumber → OCR (pdf2image + Tesseract)
- `cleanalize_core/registry.py` — Plugin registry (register/get/choices)
- `cleanalize_plugins/` — Extraction plugins implementing `matches(texto)` and `extract_records(texto)`
- `analise_anual_cli.py` — Annual Prestação de Contas CLI: slices 1 PDF into months, generates comparisons + anomaly detection
- `compare_prestacao_cli.py` — 1:1 Prestação comparison CLI (deprecated as standalone, functions reused by analise_anual_cli.py)

**Plugin system**: Each plugin in `cleanalize_plugins/` must expose:
- `NOME` constant (e.g., `"ahreas"`, `"inadimplencia"`)
- `Extractor` class with static methods `matches(texto: str) -> bool` and `extract_records(texto: str) -> list[dict]`
- Plugins are registered in `cleanalize_cli.py` at import time

**Config JSONs** (`config/*.json`): Define `mapeamento` (column renaming from plugin fields to XLSX columns) and `normalizacao` (type conversions: `decimal_br`, `data_br`, `data_br_formatada`).

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
docker compose -f docker-compose.local.yml up -d --build   # build + start on port 8080
docker compose -f docker-compose.local.yml logs -f          # view logs
docker compose -f docker-compose.local.yml down             # stop
# Access: http://localhost:8080/Cleanalyze
```

### Docker (Production — Hostinger with Traefik)
```bash
docker compose build
docker compose up -d        # uses docker-compose.yml (Traefik, HTTPS, external network)
```

### Dependencies
```bash
composer install             # PHP deps (PHPSpreadsheet, DOMPDF)
pip install pandas openpyxl pdfplumber pdf2image pytesseract  # Python deps
```

## Key Conventions

- **Language**: All code comments, variable names, and UI are in Brazilian Portuguese
- **Brazilian data formats**: Dates as DD/MM/YYYY, decimals with comma separator (1.234,56), address parsing for Brazilian street types
- **Encoding**: Must handle cp1252 ↔ UTF-8 (OCR output and Windows legacy). Plugins use encoding-tolerant regex
- **Plugin field names**: Use camelCase matching the config JSON keys (e.g., `UnidadeCodigo`, `LogradouroCobranca`)
- **inadimplencia plugin** always uses `pdfplumber` (coordinate-based extraction), not pdftotext
- **ahreas plugin** uses `pdftotext` by default
- **No database**: Auth uses `auth/data/users.json` and `auth/data/invites.json` (inside `auth/data/` which is a Docker volume to persist across deploys)
- **Dual environment**: `config/paths.php` auto-detects Windows vs Docker; Python CLI works in both
- **Docker compose files**: `docker-compose.yml` is for Hostinger production. `docker-compose.local.yml` is for local development (port 8080 exposed). Both mount `auth/data/`, `uploads/`, and `logs/` as volumes to persist data across deploys. `Dockerfile` is shared by both — never needs environment-specific changes
- **Changelog**: `changelog.json` in project root feeds the collapsible "Novidades" card on `index.php`. Keep entries user-facing only — no technical details (no library names, Docker internals, OCR engine names, loading animations, tutorials). Focus on what changed for the user. **Always ask the user for approval before updating** — present version number and content for confirmation
- **Branding**: Product name is "Cleanalyze IA" (name first, then IA). Institutional colors: primary blue `#0664e4`, purple `#8578ef`, dark `#04193b`. IA disclaimer text: "Analise gerada por Inteligencia Artificial. Os resultados sao indicativos e devem ser validados pelo usuario."
- **Claude/ folder**: Local-only reference folder for files shared with Claude Code (debug outputs, commit history, etc.). Listed in `.gitignore` — never pushed to GitHub
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
