"""
cleanalize_core/rag/chunking.py
Chunking por numero de tokens com sobreposicao. Mantem o numero da pagina de origem.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import List

from .mistral_ocr import OcrPage

try:
    import tiktoken  # type: ignore
    _ENCODER = tiktoken.get_encoding("cl100k_base")
except Exception:  # pragma: no cover
    _ENCODER = None


@dataclass
class Chunk:
    page: int
    text: str


def _count_tokens(text: str) -> int:
    if _ENCODER is not None:
        return len(_ENCODER.encode(text))
    # fallback grosseiro: ~4 chars por token
    return max(1, len(text) // 4)


def _split_tokens(text: str, size: int, overlap: int) -> List[str]:
    if _ENCODER is not None:
        tokens = _ENCODER.encode(text)
        if not tokens:
            return []
        step = max(1, size - overlap)
        pieces: List[str] = []
        for start in range(0, len(tokens), step):
            window = tokens[start:start + size]
            if not window:
                break
            pieces.append(_ENCODER.decode(window))
            if start + size >= len(tokens):
                break
        return pieces

    # Fallback por caracteres (aprox 4 chars = 1 token)
    char_size = size * 4
    char_overlap = overlap * 4
    if not text:
        return []
    step = max(1, char_size - char_overlap)
    pieces: List[str] = []
    for start in range(0, len(text), step):
        pieces.append(text[start:start + char_size])
        if start + char_size >= len(text):
            break
    return pieces


def chunk_pages(
    pages: List[OcrPage],
    chunk_size: int = 800,
    overlap: int = 100,
) -> List[Chunk]:
    """
    Gera chunks preservando o numero da pagina. Paginas grandes sao divididas
    com sobreposicao; paginas pequenas viram um unico chunk.
    """
    result: List[Chunk] = []
    for page in pages:
        text = (page.text or "").strip()
        if not text:
            continue
        if _count_tokens(text) <= chunk_size:
            result.append(Chunk(page=page.page, text=text))
            continue
        for piece in _split_tokens(text, chunk_size, overlap):
            piece = piece.strip()
            if piece:
                result.append(Chunk(page=page.page, text=piece))
    return result
