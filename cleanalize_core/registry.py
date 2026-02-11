# cleanalize_core/registry.py
from typing import Dict, Any

_PLUGINS: Dict[str, Any] = {}

def register(nome: str, plugin_cls: Any):
    """
    Registra um plugin pelo nome (ex.: 'inadimplencia').
    O plugin_cls deve expor os métodos estáticos:
      - matches(texto: str) -> bool
      - extract_records(texto: str) -> list[dict]
    """
    _PLUGINS[nome] = plugin_cls

def get(nome: str):
    """Retorna a classe do plugin registrado pelo nome."""
    return _PLUGINS.get(nome)

def choices():
    """Retorna a lista ordenada de plugins disponíveis."""
    return sorted(_PLUGINS.keys())
