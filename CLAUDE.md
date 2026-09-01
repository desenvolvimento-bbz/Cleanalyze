# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Cleanalyze BBZ** is a hybrid PHP + Python web application for extracting structured data from PDF reports (Brazilian property management/condominium documents) and exporting to XLSX spreadsheets. Built for BBZ Administração de Condomínio Ltda.

## Architecture

The app has two layers that communicate via shell execution (PHP calls Python CLI):

**PHP layer** (web interface + auth):
- `index.php` — Dashboard with extract/compare navigation
- `login.php` / `auth/bootstrap.php` — Session auth with JSON-based user store, 15-min idle timeout
- `extrair.php` — Legacy extraction page; `web/executar_extracao.php` is the main orchestrator
- `comparar.php` — XLSX comparison tool with Levenshtein similarity + DOMPDF PDF export
- `config/paths.php` — Auto-detects Windows (XAMPP) vs Docker paths for Python/Poppler/Tesseract

**Python layer** (extraction engine):
- `cleanalize_cli.py` — Main CLI orchestrator: PDF → text → plugin → normalize → map → XLSX
- `cleanalize_core/pdf_engine.py` — Text extraction with fallback chain: pdftotext → pdfplumber → OCR (pdf2image + Tesseract)
- `cleanalize_core/registry.py` — Plugin registry (register/get/choices)
- `cleanalize_plugins/` — Extraction plugins implementing `matches(texto)` and `extract_records(texto)`

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
- **lello plugin** uses `PdfEngine.to_text_layout()` (pdfplumber `extract_text(layout=True)`), which preserves each word's column. The report is a two-column form whose PDF draw order differs from reading order, so `pdftotext -layout` scrambles the label/value pairs. `to_text_pdfplumber()` does not work either: it collapses the gap between columns to a single space
- **lello output is one row per unit**: a unit can have more than one condômino (co-owners), and the second one inherits the Bloco/Unidade header instead of repeating it. Personal fields of co-owners are merged into one cell with `" | "`; unit-level fields (including the billing address) come from the first condômino listed
- **lello_inadimplencia plugin** reads the Lello "Cotas Atrasadas" report, also via `to_text_layout()`. It emits **one row per accounting account**, not per charge: each charge breaks down into accounts whose values sum exactly to the charge's Valor Original (verified across all 123 charges of the source PDF). `Cód. Condomínio` comes from the report's own "Referência" field, and `Percentual Multa` is computed per charge as multa/valor original. The report has no bloco and no bank account, so those columns stay empty
- **Brand (BBZ identity)**: the official palette and typography live in `assets/css/bbz.css`, loaded by `includes/head.php` and linked directly by the pages that carry their own `<head>` (everything under `web/`, plus `login.php`, `extrair.php`, `auth/convite.php`). **Never redefine brand colors in a page** — use the `--bbz-*` variables. Palette: Azul Escuro `#04193b` (dark backgrounds, main text), Azul `#0664e4` (primary action, links, focus), Azul Médio `#60a5fa`, Azul Claro `#b0d4ff`, Roxo `#8578ef`, and greys `#8c8c9c`/`#b8b8c4`/`#efeff4`. No colors outside this palette; `#1e448c` (Azul Legacy) is forbidden in new work. Font is Manrope — light weight (200-300) for large headings, bold (600-700) for KPIs. Logos in `assets/img/`: negativa (white) on dark backgrounds, positiva on light ones; never recolor or redraw them
- **Two deliberate exceptions to the palette**: the Google sign-in SVG (Google's own brand colors, required) and the semantic diff colors in `web/executar_prestacao.php` (nova/ausente/alterada) plus `.ok`/`.erro` in `web/diagnostico.php`, where red/green carries meaning. Everything else must come from the palette
- **No database**: Auth uses `auth/users.json` and `auth/invites.json`
- **Dual environment**: `config/paths.php` auto-detects Windows vs Docker; Python CLI works in both
- **Docker compose files**: `docker-compose.yml` is for Hostinger production (Traefik, external network, HTTPS). `docker-compose.local.yml` is for local development (port 8080 exposed, volumes for output/uploads). `Dockerfile` is shared by both — never needs environment-specific changes
- **Changelog**: `changelog.json` in project root feeds the collapsible "Novidades" card on `index.php`. Keep entries user-facing only — no technical details (no library names, Docker internals, OCR engine names). Focus on what changed for the user (e.g., "Novo modulo de extracao: Inadimplencia", not "Added pdfplumber coordinate extraction"). Update this file whenever a user-visible change is made
- **Claude/ folder**: Local-only reference folder for files shared with Claude Code (debug outputs, commit history, etc.). Listed in `.gitignore` — never pushed to GitHub
- **Regex evolution**: When fixing extraction regex, prefer adding fallback patterns over modifying existing ones, to avoid breaking PDFs that already work. Test with both old and new PDFs after changes

## Important Details

- `_abreviar_descricao()` in `cleanalize_cli.py` abbreviates common descriptions to 28 chars max but preserves M3/M³ volume measurements
- The inadimplencia plugin (~80KB) has complex OCR error recovery (`_try_salvage_corrupted`), context propagation (lines inherit vencimento from last recibo header), and specific OCR value conversion rules for certain account codes
- `web/executar_extracao.php` calls `cleanalize_cli.py` via `proc_open()` with `PYTHONIOENCODING=utf-8`
- Upload limits: 128MB file size, 300s timeout (configured in Dockerfile)
- Docker exposes port 8080 with Apache alias `/Cleanalyze`
- The lello plugin normalizes its output to UPPERCASE without accents, and abbreviates the street type (AVENIDA -> AV, ALAMEDA -> AL, PRACA -> PC, RODOVIA -> ROD) to match what the Ahreas PDFs already deliver. RUA is not abbreviated, same as in Ahreas. Punctuation is stripped only from the name fields (`Nome`, `Aos Cuidados`) — CPF, CEP, phone and e-mail keep their masks, and the `" | "` separator survives because names are cleaned before being joined
- The lello report has no locatário, fração or metragem data, so those model columns stay empty. Its single address is written to the *Cobrança* columns only
- The ahreas plugin `UnidadeCodigo` regex accepts alphanumeric codes (`[A-Za-z0-9]{1,10}`) — not just digits. Changed in v1.2.0 to support codes like LOJA01, CONSTR, C00118
- Bootstrap JS (`bootstrap.bundle.min.js`) is loaded in `index.php` for the changelog collapse feature
