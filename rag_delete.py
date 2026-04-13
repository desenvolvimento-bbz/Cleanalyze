#!/usr/bin/env python3
"""
rag_delete.py
CLI que remove um documento do RAG:
  - Apaga linhas de documents/chunks/chunks_vec/chat_history no SQLite do usuario
  - Remove o diretorio files/<doc_id>/ (PDF original + ocr.txt)

Uso:
  python rag_delete.py --user <email> --doc-id <uuid>

Saida (stdout): JSON {ok, doc_id, files_removed}.
"""
from __future__ import annotations

import argparse
import json
import shutil
import sys
import traceback

from cleanalize_core.rag.store import RagStore, global_files_dir, user_files_dir


def main() -> int:
    parser = argparse.ArgumentParser(description="Delete de documento do RAG")
    parser.add_argument("--user", default=None)
    parser.add_argument("--doc-id", required=True)
    parser.add_argument("--global", dest="global_", action="store_true", help="Deletar do store global (Documentos BBZ)")
    args = parser.parse_args()

    result = {"ok": False, "doc_id": args.doc_id, "scope": "global" if args.global_ else "user"}

    try:
        if not args.global_ and not args.user:
            raise ValueError("--user e obrigatorio quando --global nao e usado")

        store_cm = RagStore.global_store() if args.global_ else RagStore(args.user)
        with store_cm as store:
            doc = store.get_document(args.doc_id)
            store.delete_document(args.doc_id)
            result["existed"] = doc is not None

        if args.global_:
            files_dir = global_files_dir() / args.doc_id
        else:
            files_dir = user_files_dir(args.user) / args.doc_id
        files_removed = False
        if files_dir.exists():
            shutil.rmtree(files_dir, ignore_errors=True)
            files_removed = True

        result.update(ok=True, files_removed=files_removed)

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
