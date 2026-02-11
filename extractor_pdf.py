"""
Cleanalize - Extractor Integrado (com OCR opcional)

Uso (PowerShell):
  python extractor_pdf.py `
    --pdftotext "C:/poppler/Library/bin/pdftotext.exe" `
    --pdf "C:/xampp/htdocs/Cleanalyze/uploads/SEU_PDF_UNIDADES.pdf" `
    --tipo ahreas `
    --config "C:/xampp/htdocs/Cleanalyze/config/ahreas.json" `
    --modelo "C:/xampp/htdocs/Cleanalyze/modelo_planilha_importacao.xlsx" `
    --saida "C:/xampp/htdocs/Cleanalyze/uploads/saida_ahreas.xlsx" `
    --ocr `
    --tesseract "C:/Program Files/Tesseract-OCR/tesseract.exe" `
    --ocr-lang "por+eng" `
    --poppler "C:/poppler/Library/bin" `
    --dpi 300 `
    --debug-save-text
"""

import os
import json
import argparse
from datetime import datetime
from typing import List, Dict, Optional

import pandas as pd

from cleanalize_core.pdf_engine import PdfEngine
from cleanalize_core.registry import register, get, choices

# Plugins
from cleanalize_plugins.inadimplencia import Extractor as Inad, NOME as NOME_INAD
register(NOME_INAD, Inad)

from cleanalize_plugins.ahreas import Extractor as Ahreas, NOME as NOME_AHREAS
register(NOME_AHREAS, Ahreas)

# -------------------- Normalização/Mapeamento --------------------

def _to_decimal_br(v):
    if v is None or (isinstance(v, float) and pd.isna(v)): return v
    s = str(v).strip()
    if not s: return None
    s = s.replace(".", "").replace(",", ".")
    try: return float(s)
    except Exception: return None

def _to_date_br(v):
    if v is None or (isinstance(v, float) and pd.isna(v)): return v
    s = str(v).strip()
    if not s: return None
    for fmt in ("%d/%m/%Y", "%d/%m/%y"):
        try: return datetime.strptime(s, fmt)
        except Exception: pass
    return None

def aplicar_normalizacao(df: pd.DataFrame, cfg: dict) -> pd.DataFrame:
    norm = cfg.get("normalizacao", {})
    for src_col, regra in norm.items():
        if src_col in df.columns:
            tipo = regra.get("tipo")
            if tipo == "decimal_br":
                df[src_col] = df[src_col].map(_to_decimal_br)
            elif tipo == "data_br":
                df[src_col] = df[src_col].map(_to_date_br)
    return df

def aplicar_mapeamento(registros: List[Dict], config_path: str, modelo_xlsx: Optional[str] = None) -> pd.DataFrame:
    with open(config_path, "r", encoding="utf-8") as f:
        cfg = json.load(f)

    mapeamento = cfg.get("mapeamento", {})
    cols_origem = list(mapeamento.keys())

    df = pd.DataFrame(registros) if registros else pd.DataFrame(columns=cols_origem)
    df = aplicar_normalizacao(df, cfg)
    df = df.rename(columns=mapeamento)

    if modelo_xlsx and os.path.exists(modelo_xlsx):
        try:
            df_modelo = pd.read_excel(modelo_xlsx, nrows=0)
            dest_cols = list(df_modelo.columns)
            for col in dest_cols:
                if col not in df.columns:
                    df[col] = None
            df = df[dest_cols]
        except Exception:
            pass

    return df

def autodetect(texto: str) -> Optional[str]:
    for nome in choices():
        plugin = get(nome)
        if plugin and plugin.matches(texto):
            return nome
    return None

# ------------------------ MAIN ------------------------

def main_integrado():
    ap = argparse.ArgumentParser(description="Cleanalize - Extração Integrada (com OCR opcional)")
    ap.add_argument("--pdftotext", help="Caminho do pdftotext.exe (Poppler)")
    ap.add_argument("--pdf", required=True, help="PDF de entrada")
    ap.add_argument("--tipo", choices=choices(), help="Tipo (inadimplencia, ahreas)")
    ap.add_argument("--config", required=True, help="JSON de mapeamento")
    ap.add_argument("--modelo", help="Planilha modelo (XLSX)")
    ap.add_argument("--saida", default="saida.xlsx", help="Arquivo XLSX de saída")
    ap.add_argument("--debug-save-text", action="store_true", help="Salvar .debug.txt")

    # OCR
    ap.add_argument("--ocr", action="store_true", help="Ativar OCR se pdftotext vier vazio")
    ap.add_argument("--tesseract", help="Caminho do tesseract.exe")
    ap.add_argument("--ocr-lang", default="por", help="Idiomas do OCR (ex.: 'por' ou 'por+eng')")
    ap.add_argument("--poppler", help="Pasta do Poppler (para pdf2image), ex.: C:/poppler/Library/bin")
    ap.add_argument("--dpi", type=int, default=300, help="DPI das imagens para OCR (padrão 300)")
    ap.add_argument("--use-pdfplumber", action="store_true",
                    help="Usar pdfplumber (extração por coordenadas) em vez de pdftotext. "
                         "Automático para inadimplência.")

    args, _unk = ap.parse_known_args()

    engine = PdfEngine(
        pdftotext_path=args.pdftotext,
        ocr=args.ocr,
        tesseract_path=args.tesseract,
        ocr_lang=args.ocr_lang,
        poppler_path=args.poppler,
        dpi=args.dpi,
    )

    # Determina se deve usar pdfplumber (extração por coordenadas)
    # Automático para inadimplência, ou explícito com --use-pdfplumber
    use_pdfplumber = args.use_pdfplumber
    if args.tipo == "inadimplencia":
        use_pdfplumber = True
        print("[INFO] Tipo inadimplência detectado: usando pdfplumber (extração por coordenadas)")

    if use_pdfplumber:
        texto = engine.to_text_pdfplumber(args.pdf)
        extractor_method = "pdfplumber"
    else:
        texto = engine.to_text(args.pdf)
        extractor_method = args.pdftotext or 'pdftotext (PATH)'

    print(f"[INFO] Método de extração: {extractor_method}")
    print(f"[INFO] PDF: {args.pdf}")
    print(f"[INFO] Tamanho do texto extraído: {len(texto or '')} caracteres")

    if args.debug_save_text:
        try:
            with open(args.pdf + ".debug.txt", "w", encoding="utf-8") as f:
                f.write(texto or "")
            print(f"[INFO] Texto salvo em: {args.pdf}.debug.txt")
        except Exception:
            pass

    if not texto or not texto.strip():
        raise SystemExit(
            "Não foi possível extrair texto do PDF.\n"
            "Possíveis causas:\n"
            "  • O PDF é escaneado (só imagem) — pdftotext não enxerga texto;\n"
            "  • O arquivo está corrompido ou protegido.\n\n"
            "Verificações sugeridas:\n"
            "  1) Confirme o Poppler: \"C:/poppler/Library/bin/pdftotext.exe\" -v\n"
            "  2) Teste direto: \"C:/poppler/Library/bin/pdftotext.exe\" -layout \"CAMINHO/arquivo.pdf\" -\n"
            "     (deve imprimir texto no console).\n"
            "  3) Para PDFs escaneados, ative o OCR com --ocr e informe --tesseract."
        )

    tipo = args.tipo or autodetect(texto)
    if not tipo:
        raise SystemExit(f"Não foi possível detectar o tipo do relatório. Informe --tipo. Opções: {choices()}")

    plugin_cls = get(tipo)
    if not plugin_cls:
        raise SystemExit(f"Tipo '{tipo}' não suportado. Opções: {choices()}")

    registros = plugin_cls.extract_records(texto)
    print(f"[INFO] Registros extraídos: {len(registros)}")

    df = aplicar_mapeamento(registros, args.config, args.modelo)
    df.to_excel(args.saida, index=False)
    print(f"[OK] Linhas exportadas: {len(df)} -> {args.saida}")

if __name__ == "__main__":
    main_integrado()
