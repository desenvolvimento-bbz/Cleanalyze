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

### Docker
```bash
docker compose build
docker compose up -d        # runs on port 8080
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
- **No database**: Auth uses `auth/users.json` and `auth/invites.json`
- **Dual environment**: `config/paths.php` auto-detects Windows vs Docker; Python CLI works in both

## Important Details

- `_abreviar_descricao()` in `cleanalize_cli.py` abbreviates common descriptions to 28 chars max but preserves M3/M³ volume measurements
- The inadimplencia plugin (~80KB) has complex OCR error recovery (`_try_salvage_corrupted`), context propagation (lines inherit vencimento from last recibo header), and specific OCR value conversion rules for certain account codes
- `web/executar_extracao.php` calls `cleanalize_cli.py` via `proc_open()` with `PYTHONIOENCODING=utf-8`
- Upload limits: 128MB file size, 300s timeout (configured in Dockerfile)
- Docker exposes port 8080 with Apache alias `/Cleanalyze`
