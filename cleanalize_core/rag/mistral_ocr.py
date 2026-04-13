"""
cleanalize_core/rag/mistral_ocr.py
Wrapper fino do Mistral OCR.

Fluxo:
  1. Upload do PDF/imagem para o endpoint /files do Mistral (purpose=ocr).
  2. Obtem signed URL do arquivo enviado.
  3. Chama o endpoint OCR (/ocr) com o modelo configurado.
  4. Retorna lista de paginas: [{"page": int, "text": str}, ...].
"""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import List

from ..config import get_env

try:
    # mistralai v2.x expoe a classe em mistralai.client; v1.x expunha direto
    try:
        from mistralai.client import Mistral  # type: ignore
    except ImportError:
        from mistralai import Mistral  # type: ignore  # fallback v1.x
except ImportError as e:  # pragma: no cover
    raise RuntimeError(
        "Pacote 'mistralai' nao instalado. Rode: pip install mistralai"
    ) from e


@dataclass
class OcrPage:
    page: int  # 1-indexed
    text: str


_DEFAULT_TIMEOUT_MS = 180_000  # 3 minutos — OCR pode ter fila no Mistral
_MAX_ATTEMPTS = 3


def _build_client() -> "Mistral":
    api_key = get_env("MISTRAL_API_KEY", required=True)
    try:
        return Mistral(api_key=api_key, timeout_ms=_DEFAULT_TIMEOUT_MS)
    except TypeError:
        # SDK v1.x nao suporta timeout_ms no construtor
        return Mistral(api_key=api_key)


def _retry(callable_, *, what: str):
    """Retry com backoff para falhas transitorias do Mistral.

    Retenta em:
      - httpx.ReadTimeout / ConnectTimeout / RemoteProtocolError (rede)
      - SDKError com status 429 (rate limit) ou 5xx (erro do servidor Mistral)

    NAO retenta em:
      - SDKError 4xx (erro do cliente — retry nao vai consertar)
    """
    import time as _t

    try:
        import httpx  # type: ignore
        network_exc: tuple = (
            httpx.ReadTimeout,
            httpx.ConnectTimeout,
            httpx.RemoteProtocolError,
        )
    except ImportError:
        network_exc = (Exception,)

    try:
        from mistralai.client.errors import SDKError  # type: ignore
    except ImportError:
        try:
            from mistralai.errors import SDKError  # type: ignore
        except ImportError:
            SDKError = None  # type: ignore

    def _is_retryable_sdk_error(exc: Exception) -> bool:
        if SDKError is None or not isinstance(exc, SDKError):
            return False
        resp = getattr(exc, "raw_response", None)
        status = getattr(resp, "status_code", None)
        if status is None:
            return True  # desconhecido -> trata como transitorio
        return status == 429 or 500 <= int(status) < 600

    last_exc = None
    for attempt in range(1, _MAX_ATTEMPTS + 1):
        try:
            return callable_()
        except network_exc as e:
            last_exc = e
        except Exception as e:
            if _is_retryable_sdk_error(e):
                last_exc = e
            else:
                raise

        if attempt == _MAX_ATTEMPTS:
            break
        _t.sleep(2 ** (attempt - 1))  # 1s, 2s

    status_info = ""
    resp = getattr(last_exc, "raw_response", None)
    if resp is not None and getattr(resp, "status_code", None):
        status_info = f" (HTTP {resp.status_code})"
    raise RuntimeError(
        f"Mistral OCR falhou em {what} apos {_MAX_ATTEMPTS} tentativas"
        f"{status_info}: {type(last_exc).__name__}: {last_exc}"
    ) from last_exc


def ocr_file(file_path: str | Path) -> List[OcrPage]:
    """Roda OCR em um arquivo local (PDF ou imagem) e retorna o texto por pagina."""
    path = Path(file_path)
    if not path.is_file():
        raise FileNotFoundError(f"Arquivo nao encontrado: {path}")

    client = _build_client()
    model = get_env("MISTRAL_OCR_MODEL", "mistral-ocr-latest") or "mistral-ocr-latest"
    file_bytes = path.read_bytes()

    # 1) Upload (com retry)
    uploaded = _retry(
        lambda: client.files.upload(
            file={"file_name": path.name, "content": file_bytes},
            purpose="ocr",
        ),
        what="files.upload",
    )

    # 2) Signed URL (com retry)
    signed = _retry(
        lambda: client.files.get_signed_url(file_id=uploaded.id),
        what="files.get_signed_url",
    )

    # 3) OCR (com retry)
    response = _retry(
        lambda: client.ocr.process(
            model=model,
            document={"type": "document_url", "document_url": signed.url},
            include_image_base64=False,
        ),
        what="ocr.process",
    )

    # 4) Normalizacao
    pages: List[OcrPage] = []
    for idx, page in enumerate(getattr(response, "pages", []) or [], start=1):
        markdown = getattr(page, "markdown", None) or ""
        pages.append(OcrPage(page=idx, text=markdown.strip()))

    return pages


def pages_to_plain_text(pages: List[OcrPage]) -> str:
    """Concatena as paginas em um unico texto com marcador por pagina."""
    parts = []
    for p in pages:
        parts.append(f"[Pagina {p.page}]\n{p.text}")
    return "\n\n".join(parts)
