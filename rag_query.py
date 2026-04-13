#!/usr/bin/env python3
"""
rag_query.py
CLI que responde uma pergunta sobre um documento ja ingerido.

Uso:
  python rag_query.py --user <email> --doc-id <uuid> --question "texto da pergunta"

Tambem aceita a pergunta via stdin, para evitar limites de tamanho de argumento:
  echo "pergunta" | python rag_query.py --user ... --doc-id ... --stdin

Saida (stdout): JSON {ok, answer, sources, history_len}.
"""
from __future__ import annotations

import argparse
import json
import sqlite3
import sys
import traceback

from cleanalize_core.config import get_env
from cleanalize_core.rag.chat import answer_question
from cleanalize_core.rag.embeddings import embed_text
from cleanalize_core.rag.store import RagStore


def _int_env(name: str, default: int) -> int:
    raw = get_env(name, str(default)) or str(default)
    try:
        return int(raw)
    except ValueError:
        return default


def main() -> int:
    parser = argparse.ArgumentParser(description="Query RAG de um documento")
    parser.add_argument("--user", required=True)
    parser.add_argument("--doc-id", required=True)
    parser.add_argument("--question", default=None, help="Pergunta do usuario")
    parser.add_argument("--stdin", action="store_true", help="Ler pergunta do stdin")
    args = parser.parse_args()

    if args.stdin:
        question = sys.stdin.read().strip()
    else:
        question = (args.question or "").strip()

    result = {"ok": False, "doc_id": args.doc_id}

    try:
        if not question:
            raise ValueError("Pergunta vazia")

        top_k = _int_env("RAG_TOP_K", 5)

        with RagStore(args.user) as store:
            doc = store.get_document(args.doc_id)
            if doc is None:
                raise LookupError(f"Documento {args.doc_id} nao encontrado")
            if doc.status != "ready":
                raise RuntimeError(
                    f"Documento ainda nao esta pronto (status={doc.status})"
                )

            history = store.get_chat_history(args.doc_id, limit=20)

            query_vec = embed_text(question)
            hits = store.search(args.doc_id, query_vec, top_k=top_k)

            chat_result = answer_question(question, hits, history=history)

            # O documento pode ter sido deletado em outra requisicao enquanto
            # essa query estava em voo. Nesse caso, o INSERT no chat_history
            # falha com FOREIGN KEY. Capturamos e retornamos erro amigavel.
            try:
                store.append_chat(args.doc_id, "user", question)
                store.append_chat(
                    args.doc_id,
                    "assistant",
                    chat_result.answer,
                    sources=chat_result.sources,
                )
            except sqlite3.IntegrityError:
                raise RuntimeError(
                    "Documento foi removido durante a consulta"
                )

            result.update(
                ok=True,
                answer=chat_result.answer,
                sources=chat_result.sources,
                history_len=len(history) + 2,
            )

    except Exception as e:
        result.update(
            ok=False,
            error=f"{type(e).__name__}: {e}",
            trace=traceback.format_exc(limit=3),
        )
        print(json.dumps(result, ensure_ascii=False))
        return 1

    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
