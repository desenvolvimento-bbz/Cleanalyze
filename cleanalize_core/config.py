"""
cleanalize_core/config.py
Carrega variáveis do arquivo .env (raiz do projeto) e expõe um helper `get_env`.

Uso:
    from cleanalize_core.config import get_env
    api_key = get_env("OPENAI_API_KEY", required=True)
"""
from __future__ import annotations

import os
from pathlib import Path

try:
    from dotenv import load_dotenv  # type: ignore
    _HAS_DOTENV = True
except ImportError:  # pragma: no cover
    _HAS_DOTENV = False


_PROJECT_ROOT = Path(__file__).resolve().parent.parent
_ENV_FILE = _PROJECT_ROOT / ".env"
_LOADED = False


def _load_env_once() -> None:
    global _LOADED
    if _LOADED:
        return
    _LOADED = True

    if not _ENV_FILE.is_file():
        return

    if _HAS_DOTENV:
        load_dotenv(_ENV_FILE, override=False)
        return

    # Fallback minimalista caso python-dotenv não esteja instalado
    for raw in _ENV_FILE.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = value


def get_env(key: str, default: str | None = None, *, required: bool = False) -> str | None:
    _load_env_once()
    value = os.environ.get(key, default)
    if required and (value is None or value == ""):
        raise RuntimeError(
            f"Variável de ambiente obrigatória ausente: {key}. "
            f"Defina em {_ENV_FILE} ou no ambiente do processo."
        )
    return value


def project_root() -> Path:
    return _PROJECT_ROOT


# Carrega no import para que qualquer os.environ.get posterior já funcione
_load_env_once()
