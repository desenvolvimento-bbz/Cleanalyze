"""
cleanalize_core.__init__
Expose componentes do núcleo para facilitar os imports:
  - PdfEngine: conversão PDF -> texto (usando Poppler)
  - registry: pequenas utilidades para registrar/recuperar plugins
"""

from .pdf_engine import PdfEngine
from .registry import register, get, choices

__all__ = ["PdfEngine", "register", "get", "choices"]
