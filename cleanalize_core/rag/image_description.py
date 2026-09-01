"""
cleanalize_core/rag/image_description.py
Chama o modelo de visao da OpenAI para descrever imagens extraidas pelo
Mistral OCR, focado em contexto de manuais/processos (botoes, campos, acoes).
"""
from __future__ import annotations

from typing import Optional

from ..config import get_env

try:
    from openai import OpenAI  # type: ignore
except ImportError as e:  # pragma: no cover
    raise RuntimeError(
        "Pacote 'openai' nao instalado. Rode: pip install openai"
    ) from e


_SYSTEM_PROMPT = (
    "Voce analisa imagens extraidas de manuais e documentos de processos "
    "internos de uma empresa. Descreva o que a imagem mostra em portugues "
    "do Brasil, de forma objetiva e curta (1-3 frases). Foque em:\n"
    "- Elementos visiveis na interface (botoes, campos, menus, abas, icones)\n"
    "- Texto legivel em rotulos, titulos e mensagens\n"
    "- Acao ou passo demonstrado (onde clicar, o que preencher)\n"
    "Nao comente cores, tipografia ou layout. Se a imagem for um simbolo "
    "generico (logo, icone decorativo), responda apenas 'imagem decorativa'."
)


def _client() -> "OpenAI":
    api_key = get_env("OPENAI_API_KEY", required=True)
    return OpenAI(api_key=api_key)


def _model() -> str:
    return get_env("RAG_VISION_MODEL", "gpt-4o-mini") or "gpt-4o-mini"


def describe_image(image_base64: str, context: Optional[str] = None) -> str:
    """
    Recebe bytes base64 de uma imagem (sem prefixo data: ou com) e retorna
    uma descricao textual curta em PT-BR.
    """
    if not image_base64:
        return ""

    # Mistral OCR pode retornar com ou sem prefixo data:
    if not image_base64.startswith("data:"):
        # Mistral devolve JPEG por padrao; o modelo aceita mesmo que o
        # header exato nao bata, porque o decode e baseado no conteudo
        data_url = f"data:image/jpeg;base64,{image_base64}"
    else:
        data_url = image_base64

    user_text = "Descreva esta imagem."
    if context:
        # Passa um pedaco do texto proximo a imagem como contexto
        snippet = context.strip().replace("\n", " ")[:300]
        if snippet:
            user_text = (
                "Descreva esta imagem do manual. "
                f"Contexto textual proximo: \"{snippet}\""
            )

    client = _client()
    resp = client.chat.completions.create(
        model=_model(),
        messages=[
            {"role": "system", "content": _SYSTEM_PROMPT},
            {
                "role": "user",
                "content": [
                    {"type": "text", "text": user_text},
                    {
                        "type": "image_url",
                        "image_url": {"url": data_url, "detail": "low"},
                    },
                ],
            },
        ],
        temperature=0.0,
        max_tokens=200,
    )
    return (resp.choices[0].message.content or "").strip()
