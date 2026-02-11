# cleanalize_core/pdf_engine.py
"""
PdfEngine: extrai texto de PDF.
- 1º tenta pdftotext (Poppler).
- Se vier vazio e OCR estiver habilitado, usa pdf2image + Tesseract.
- Suporta intervalo de páginas (first_page/last_page) e logs de diagnóstico.
- Para inadimplência: usa pdfplumber com extração por coordenadas para evitar quebra de palavras.

Requisitos OCR:
  pip install pdf2image pillow pytesseract

Requisitos pdfplumber:
  pip install pdfplumber

  Instalar Tesseract (Windows): C:/Program Files/Tesseract-OCR/tesseract.exe
"""

import os
import re
import shutil
import subprocess
from typing import Optional, List

class PdfEngine:
    def __init__(
        self,
        pdftotext_path: Optional[str] = None,
        ocr: bool = False,
        tesseract_path: Optional[str] = None,
        ocr_lang: str = "por",
        poppler_path: Optional[str] = None,
        dpi: int = 300,
        first_page: Optional[int] = None,
        last_page: Optional[int] = None,
        verbose: bool = True,  # ativa logs de diagnóstico
    ):
        self.pdftotext_path = pdftotext_path
        self.ocr = bool(ocr)
        self.tesseract_path = tesseract_path
        self.ocr_lang = ocr_lang
        self.poppler_path = poppler_path
        self.dpi = dpi
        self.first_page = first_page
        self.last_page = last_page
        self.verbose = verbose

    # --------------------------- util log ---------------------------

    def _log(self, msg: str):
        if self.verbose:
            print(msg)

    # --------------------------- pdftotext ---------------------------

    def _which_pdftotext(self) -> Optional[str]:
        if self.pdftotext_path and os.path.exists(self.pdftotext_path):
            return self.pdftotext_path
        return shutil.which("pdftotext")

    def _pdftotext(self, pdf_path: str) -> str:
        exe = self._which_pdftotext()
        if not exe:
            self._log("[WARN] pdftotext não encontrado (PATH vazio).")
            return ""
        try:
            cmd = [exe, "-layout", pdf_path, "-"]
            self._log(f"[DEBUG] Executando: {cmd}")
            res = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                encoding="utf-8",
                errors="replace",
                check=False,
            )
            if res.stderr:
                self._log(f"[DEBUG] pdftotext stderr:\n{res.stderr.strip()}")
            out = (res.stdout or "").strip()
            self._log(f"[DEBUG] pdftotext chars: {len(out)}")
            return out
        except Exception as e:
            self._log(f"[ERROR] Falha ao rodar pdftotext: {e}")
            return ""

    # ----------------------------- OCR ------------------------------

    def _ocr_images(self, pdf_path: str) -> List[str]:
        """
        Converte PDF -> imagens (páginas) usando pdf2image.
        Suporta first_page/last_page; retorna a lista de caminhos PNG.
        """
        try:
            from pdf2image import convert_from_path  # pip install pdf2image
        except Exception as e:
            self._log(f"[ERROR] pdf2image não disponível: {e}")
            return []

        kwargs = {
            "dpi": self.dpi,
            "poppler_path": self.poppler_path
        }
        if self.first_page is not None:
            kwargs["first_page"] = int(self.first_page)
        if self.last_page is not None:
            kwargs["last_page"] = int(self.last_page)

        try:
            self._log(f"[DEBUG] convert_from_path kwargs={kwargs}")
            images = convert_from_path(pdf_path, **kwargs)
        except Exception as e:
            self._log(f"[ERROR] convert_from_path falhou: {e}")
            return []

        img_paths = []
        base, _ = os.path.splitext(pdf_path)
        out_dir = base + ".ocr_pages"
        os.makedirs(out_dir, exist_ok=True)
        start_index = kwargs.get("first_page", 1)
        for i, img in enumerate(images, start=start_index):
            p = os.path.join(out_dir, f"page_{i:04d}.png")
            try:
                img.save(p, "PNG")
                img_paths.append(p)
            except Exception as e:
                self._log(f"[ERROR] Falha ao salvar imagem da página {i}: {e}")
        self._log(f"[DEBUG] Imagens geradas para OCR: {len(img_paths)}")
        return img_paths

    def _tesseract_text(self, image_paths: List[str]) -> str:
        """
        Roda Tesseract nas imagens e concatena o texto.
        """
        try:
            import pytesseract  # pip install pytesseract
        except Exception as e:
            self._log(f"[ERROR] pytesseract não disponível: {e}")
            return ""

        if self.tesseract_path:
            pytesseract.pytesseract.tesseract_cmd = self.tesseract_path
        self._log(f"[DEBUG] Tesseract: {self.tesseract_path or 'tesseract (PATH)'} | lang={self.ocr_lang}")

        texts = []
        for p in image_paths:
            try:
                txt = pytesseract.image_to_string(p, lang=self.ocr_lang) or ""
                texts.append(txt)
            except Exception as e:
                self._log(f"[ERROR] Tesseract falhou em {p}: {e}")
                texts.append("")
        full = "\n".join(texts).strip()
        self._log(f"[DEBUG] Tamanho texto OCR: {len(full)}")
        return full

    # ---------------------------- Público ---------------------------

    def to_text(self, pdf_path: str) -> str:
        self._log("[INFO] Tentando pdftotext...")
        text = self._pdftotext(pdf_path)
        if text:
            self._log("[INFO] Texto obtido via pdftotext.")
            return text

        if not self.ocr:
            self._log("[WARN] Sem texto e OCR desativado.")
            return ""  # sem OCR, encerra

        self._log("[INFO] Ativando OCR (pdf2image + Tesseract)...")
        pages = self._ocr_images(pdf_path)
        if not pages:
            self._log("[ERROR] Nenhuma imagem gerada para OCR.")
            return ""

        text = self._tesseract_text(pages)
        if text:
            self._log("[INFO] Texto obtido via OCR.")
        else:
            self._log("[ERROR] OCR não retornou texto.")
        return text

    # ----------------------- pdfplumber (coordenadas) -----------------------

    def to_text_pdfplumber(self, pdf_path: str, tolerance_y: float = 3.0) -> str:
        """
        Extrai texto usando pdfplumber com agrupamento por coordenadas.
        Agrupa palavras pela posição vertical (top) e reconstrói linhas corretamente.
        Isso evita a quebra de palavras que ocorre com pdftotext em alguns PDFs.

        Args:
            pdf_path: Caminho do PDF
            tolerance_y: Tolerância vertical para considerar palavras na mesma linha (default 3.0)

        Returns:
            Texto extraído com linhas reconstruídas corretamente
        """
        try:
            import pdfplumber
        except ImportError as e:
            self._log(f"[ERROR] pdfplumber não disponível: {e}")
            self._log("[INFO] Instale com: pip install pdfplumber")
            return ""

        self._log(f"[INFO] Extraindo texto com pdfplumber (tolerance_y={tolerance_y})...")

        try:
            all_lines = []
            with pdfplumber.open(pdf_path) as pdf:
                total_pages = len(pdf.pages)
                self._log(f"[DEBUG] PDF tem {total_pages} páginas")

                for page_num, page in enumerate(pdf.pages, start=1):
                    # Extrai palavras com coordenadas
                    words = page.extract_words(
                        keep_blank_chars=True,
                        x_tolerance=3,
                        y_tolerance=3,
                    )

                    if not words:
                        continue

                    # Agrupa palavras por linha (usando coordenada top)
                    lines_dict = {}
                    for w in words:
                        top = w.get("top", 0)
                        text = w.get("text", "")
                        x0 = w.get("x0", 0)

                        # Encontra linha existente com top similar
                        found_line = None
                        for line_top in lines_dict.keys():
                            if abs(line_top - top) <= tolerance_y:
                                found_line = line_top
                                break

                        if found_line is not None:
                            lines_dict[found_line].append((x0, text))
                        else:
                            lines_dict[top] = [(x0, text)]

                    # Ordena linhas por posição vertical (top)
                    sorted_tops = sorted(lines_dict.keys())

                    for line_top in sorted_tops:
                        # Ordena palavras da linha por posição horizontal (x0)
                        words_in_line = sorted(lines_dict[line_top], key=lambda x: x[0])

                        # Reconstrói a linha com espaçamento inteligente
                        line_text = self._reconstruct_line(words_in_line)

                        # Normaliza M? -> M3 (erro comum de OCR/extração)
                        line_text = self._normalize_m3(line_text)

                        all_lines.append(line_text)

            result = "\n".join(all_lines)
            self._log(f"[DEBUG] pdfplumber extraiu {len(result)} caracteres, {len(all_lines)} linhas")
            return result

        except Exception as e:
            self._log(f"[ERROR] Falha na extração com pdfplumber: {e}")
            return ""

    def _reconstruct_line(self, words_with_pos: List[tuple]) -> str:
        """
        Reconstrói uma linha a partir das palavras com posições x0.
        Adiciona espaços baseado na distância entre palavras.
        """
        if not words_with_pos:
            return ""

        parts = []
        prev_end_x = None

        for i, (x0, text) in enumerate(words_with_pos):
            if i == 0:
                parts.append(text)
            else:
                # Calcula distância entre palavra anterior e atual
                # Se a distância for grande, adiciona espaço
                gap = x0 - prev_end_x if prev_end_x else 0

                # Threshold: se gap > ~5 pixels, adiciona espaço
                if gap > 5:
                    parts.append(" ")
                parts.append(text)

            # Estima posição final da palavra (aproximação)
            prev_end_x = x0 + len(text) * 5  # ~5 pixels por caractere

        return "".join(parts)

    def _normalize_m3(self, text: str) -> str:
        """
        Normaliza variações de M³ (metro cúbico) que podem vir mal extraídas.
        M? -> M3, m? -> m3, etc.
        Também corrige decimais quebrados como "17, 39" -> "17,39"
        """
        # M? -> M3 (erro comum onde ³ vira ?)
        # Usa lookbehind/lookahead para evitar problemas com word boundary
        text = re.sub(r'(?<=\d\s)M\?(?=\s)', 'M3', text)  # "0,052 M? " -> "0,052 M3 "
        text = re.sub(r'(?<=\d\s)m\?(?=\s)', 'm3', text)
        text = re.sub(r'(?<=\d)M\?(?=\s)', 'M3', text)    # "0,052M? " -> "0,052M3 "
        text = re.sub(r'(?<=\d)m\?(?=\s)', 'm3', text)

        # Também normaliza M³ -> M3 para consistência
        text = text.replace('M³', 'M3').replace('m³', 'm3')

        # Corrige decimais quebrados: "17, 39" -> "17,39"
        text = re.sub(r'(\d),\s+(\d)', r'\1,\2', text)

        return text
