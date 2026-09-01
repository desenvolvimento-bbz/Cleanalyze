"""
cleanalize_core/rag/embeddings.py
Wrapper fino do endpoint de embeddings da OpenAI, com batching.
"""
from __future__ import annotations

from typing import List, Sequence

from ..config import get_env

try:
    from openai import OpenAI  # type: ignore
except ImportError as e:  # pragma: no cover
    raise RuntimeError(
        "Pacote 'openai' nao instalado. Rode: pip install openai"
    ) from e


EMBEDDING_DIM = 1536  # text-embedding-3-small


def _client() -> "OpenAI":
    api_key = get_env("OPENAI_API_KEY", required=True)
    return OpenAI(api_key=api_key)


def _model() -> str:
    return get_env("RAG_EMBEDDING_MODEL", "text-embedding-3-small") or "text-embedding-3-small"


def embed_texts(texts: Sequence[str], batch_size: int = 64) -> List[List[float]]:
    """Gera embeddings para uma lista de textos, em lotes."""
    if not texts:
        return []
    client = _client()
    model = _model()
    out: List[List[float]] = []
    for start in range(0, len(texts), batch_size):
        batch = list(texts[start:start + batch_size])
        resp = client.embeddings.create(model=model, input=batch)
        out.extend([item.embedding for item in resp.data])
    return out


def embed_text(text: str) -> List[float]:
    """Gera embedding para um unico texto."""
    vectors = embed_texts([text])
    return vectors[0] if vectors else []
