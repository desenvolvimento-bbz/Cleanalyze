# cleanalize_cli.py
"""
CLI do Cleanalize (com OCR opcional, DPI e intervalo de páginas)
- Converte PDF em texto (pdftotext; se vazio e --ocr, usa pdf2image+Tesseract)
- Seleciona plugin (--tipo ou autodetecta) e extrai registros
- Aplica normalização e mapeamento (JSON)
- Exporta XLSX (seguindo o modelo, se fornecido)
- Mostra diagnóstico de mapeamento (colunas que faltam/sobram + amostra da 1ª linha)

Uso típico (Inadimplência):
  python cleanalize_cli.py ^
    --pdftotext "C:/poppler/Library/bin/pdftotext.exe" ^
    --pdf "C:/.../INADIMPLENCIA SISTEMA.pdf" ^
    --tipo inadimplencia ^
    --config "C:/.../config/inadimplencia.json" ^
    --modelo "C:/.../modelo_planilha_inadimplencia.xlsx" ^
    --saida "C:/.../saida_inadimplencia.xlsx" ^
    --ocr --tesseract "C:/Program Files/Tesseract-OCR/tesseract.exe" ^
    --ocr-lang por+eng --poppler "C:/poppler/Library/bin" ^
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

def _to_date_br_formatada(v):
    """Converte data para formato DD/MM/YYYY (string formatada)"""
    if v is None or (isinstance(v, float) and pd.isna(v)): return None
    s = str(v).strip()
    if not s: return None
    # Tenta converter para datetime primeiro
    dt = None
    for fmt in ("%d/%m/%Y", "%d/%m/%y"):
        try:
            dt = datetime.strptime(s, fmt)
            break
        except Exception:
            pass
    # Se conseguiu converter, retorna formatado como DD/MM/YYYY
    if dt:
        return dt.strftime("%d/%m/%Y")
    return None

def _abreviar_descricao(texto: str, max_chars: int = 28) -> str:
    """
    Abrevia descrições comuns e trunca para max_chars caracteres.
    Exemplos:
    - "Fundo de reserva" → "Fdo Reserva"
    - "fundo de pintura" → "Fd Pintura"
    - "Vaga De Garagem" → "Vg Garagem"

    IMPORTANTE: Não trunca se contém M3/M³ (medida de volume).
    """
    if not texto or pd.isna(texto):
        return texto

    texto_str = str(texto).strip()
    if not texto_str:
        return texto_str

    import re

    # Dicionário de abreviações (case-insensitive)
    abreviacoes = {
        r"\bfundo\s+de\s+reserva\b": "Fdo Reserva",
        r"\bfundo\s+de\s+pintura\b": "Fd Pintura",
        r"\bfundo\s+pintura\b": "Fd Pintura",
        r"\bvaga\s+de\s+garagem\b": "Vg Garagem",
        r"\bcondominio\b": "Cond",
        r"\bcondomínio\b": "Cond",
        r"\bconsumo\s+de\s+agua\b": "Cons. Agua",
        r"\bconsumo\s+agua\b": "Cons. Agua",
        r"\bconsumo\s+de\s+água\b": "Cons. Agua",
        r"\bconsumo\s+água\b": "Cons. Agua",
    }

    texto_abreviado = texto_str
    for pattern, abrev in abreviacoes.items():
        texto_abreviado = re.sub(pattern, abrev, texto_abreviado, flags=re.IGNORECASE)

    # NÃO truncar se contém medida de volume (M3, M³, m3)
    # Isso preserva informações importantes de consumo
    tem_medida_volume = bool(re.search(r'[Mm]\s*[³3]', texto_abreviado))

    # Truncar para max_chars se necessário e não tem medida de volume
    if len(texto_abreviado) > max_chars and not tem_medida_volume:
        texto_abreviado = texto_abreviado[:max_chars].rstrip()

    return texto_abreviado

def aplicar_normalizacao(df: pd.DataFrame, cfg: dict) -> pd.DataFrame:
    norm = cfg.get("normalizacao", {})
    for src_col, regra in norm.items():
        if src_col in df.columns:
            tipo = regra.get("tipo")
            if tipo == "decimal_br":
                df[src_col] = df[src_col].map(_to_decimal_br)
            elif tipo == "data_br":
                df[src_col] = df[src_col].map(_to_date_br)
            elif tipo == "data_br_formatada":
                df[src_col] = df[src_col].map(_to_date_br_formatada)
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

# ----------------------------- MAIN ------------------------------

def main():
    ap = argparse.ArgumentParser(description="Cleanalize CLI (OCR opcional, DPI e intervalo de páginas)")
    ap.add_argument("--pdftotext", help="Caminho do pdftotext.exe (Poppler)")
    ap.add_argument("--pdf", required=True, help="PDF de entrada")
    ap.add_argument("--tipo", choices=choices(), help="Tipo (inadimplencia, ahreas)")
    ap.add_argument("--config", required=True, help="JSON de mapeamento")
    ap.add_argument("--modelo", help="Planilha modelo (XLSX)")
    ap.add_argument("--saida", default="saida.xlsx", help="Arquivo XLSX de saída")
    ap.add_argument("--debug-save-text", action="store_true", help="Salvar .debug.txt")

    # OCR & performance
    ap.add_argument("--ocr", action="store_true", help="Ativar OCR se pdftotext vier vazio")
    ap.add_argument("--tesseract", help="Caminho do tesseract.exe")
    ap.add_argument("--ocr-lang", default="por", help="Idiomas do OCR (ex.: 'por' ou 'por+eng')")
    ap.add_argument("--poppler", help="Pasta do Poppler (para pdf2image)")
    ap.add_argument("--dpi", type=int, default=200, help="DPI das imagens para OCR (padrão 200)")
    ap.add_argument("--first-page", type=int, help="Primeira página para OCR (1 = primeira)")
    ap.add_argument("--last-page", type=int, help="Última página para OCR")
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
        first_page=args.first_page,
        last_page=args.last_page,
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
        # OCR só como fallback: se pdfplumber retornou texto > 0, NÃO roda OCR
        if (not texto or not texto.strip()) and args.ocr:
            print("[INFO] pdfplumber retornou vazio, ativando OCR como fallback...")
            texto = engine.to_text(args.pdf)
            extractor_method = "pdfplumber -> OCR fallback"
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
            "Se o PDF for escaneado, rode com --ocr (e ajuste --dpi / --first-page / --last-page)."
        )

    tipo = args.tipo or autodetect(texto)
    if not tipo:
        raise SystemExit(f"Não foi possível detectar o tipo do relatório. Informe --tipo. Opções: {choices()}")

    plugin_cls = get(tipo)
    if not plugin_cls:
        raise SystemExit(f"Tipo '{tipo}' não suportado. Opções: {choices()}")

    # Passar debug=True se --debug-save-text estiver ativo
    if hasattr(plugin_cls.extract_records, '__code__') and 'debug' in plugin_cls.extract_records.__code__.co_varnames:
        registros = plugin_cls.extract_records(texto, debug=args.debug_save_text)
    else:
        registros = plugin_cls.extract_records(texto)
    print(f"[INFO] Registros extraídos: {len(registros)}")

    df = aplicar_mapeamento(registros, args.config, args.modelo)

    # Manter ordem original do PDF (sem reordenar)
    if tipo == "inadimplencia" and not df.empty:
        if "Cód. Bloco" in df.columns and "Cód. Unidade" in df.columns:
            # Converter para numérico (sem reordenar - preserva ordem do PDF)
            df["Cód. Bloco"] = pd.to_numeric(df["Cód. Bloco"], errors="coerce")
            df["Cód. Unidade"] = pd.to_numeric(df["Cód. Unidade"], errors="coerce")
            print("[INFO] Ordem original do PDF preservada")

        # Abreviar e truncar descrição para 28 caracteres
        if "Descrição" in df.columns:
            df["Descrição"] = df["Descrição"].apply(_abreviar_descricao)
            print("[INFO] Descrições abreviadas e limitadas a 28 caracteres")

    # === Diagnóstico de mapeamento/colunas para ajudar nos ajustes ===
    try:
        with open(args.config, "r", encoding="utf-8") as _f:
            _cfg_dbg = json.load(_f)
        _map_dbg = _cfg_dbg.get("mapeamento", {})
        _orig_cols = list(_map_dbg.keys())
        _dest_cols = list(_map_dbg.values())

        print("[DEBUG] Colunas de origem esperadas no JSON:", len(_orig_cols))
        print("[DEBUG] Colunas de destino (modelo):", len(_dest_cols))

        if isinstance(registros, list) and len(registros) > 0:
            _got_cols = set(registros[0].keys())
        else:
            _got_cols = set()

        _faltam = [c for c in _orig_cols if c not in _got_cols]
        _sobram = [c for c in _got_cols if c not in _orig_cols]

        if _faltam:
            print("[WARN] Faltam colunas de ORIGEM (plugin não gerou):", len(_faltam))
            print("       Ex.:", ", ".join(_faltam[:10]))
        if _sobram:
            print("[WARN] O plugin gerou colunas não mapeadas no JSON:", len(_sobram))
            print("       Ex.:", ", ".join(_sobram[:10]))

        if not df.empty:
            _sample = df.iloc[0].to_dict()
            _sample_trim = {k: (str(v)[:60] if v is not None else "") for k, v in list(_sample.items())[:10]}
            print("[DEBUG] Amostra (1ª linha após mapeamento, 10 colunas):", _sample_trim)
    except Exception as _e:
        print("[DEBUG] Diagnóstico de mapeamento falhou:", _e)
    # === fim do diagnóstico ===

    df.to_excel(args.saida, index=False)
    print(f"[OK] Linhas exportadas: {len(df)} -> {args.saida}")

if __name__ == "__main__":
    main()
