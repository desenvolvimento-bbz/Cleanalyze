#!/usr/bin/env python3
"""
rag_access.py
CLI de administracao dos Documentos BBZ (globais) — grant/revoke/list/check.

Subcomandos:
  grant           --doc-id <uuid> --email <email>
  revoke          --doc-id <uuid> --email <email>
  check           --doc-id <uuid> --email <email>
  list            --doc-id <uuid>                 (lista emails com acesso)
  list-all                                         (lista todos os docs com suas ACLs)
  accessible-for  --email <email>                 (lista docs globais que este email ve)

Saida (stdout): JSON.
"""
from __future__ import annotations

import argparse
import json
import sys
import traceback

from cleanalize_core.rag.store import RagStore


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="ACL de Documentos BBZ (globais)")
    sub = parser.add_subparsers(dest="cmd", required=True)

    for name in ("grant", "revoke", "check"):
        p = sub.add_parser(name)
        p.add_argument("--doc-id", required=True)
        p.add_argument("--email", required=True)

    p_list = sub.add_parser("list")
    p_list.add_argument("--doc-id", required=True)

    sub.add_parser("list-all")

    p_acc = sub.add_parser("accessible-for")
    p_acc.add_argument("--email", required=True)

    return parser


def main() -> int:
    args = _build_parser().parse_args()
    result: dict = {"ok": False, "cmd": args.cmd}

    try:
        with RagStore.global_store() as store:
            if args.cmd == "grant":
                store.grant_access(args.doc_id, args.email)
                result.update(ok=True, doc_id=args.doc_id, email=args.email.strip().lower())
            elif args.cmd == "revoke":
                store.revoke_access(args.doc_id, args.email)
                result.update(ok=True, doc_id=args.doc_id, email=args.email.strip().lower())
            elif args.cmd == "check":
                has = store.has_access(args.doc_id, args.email)
                result.update(ok=True, doc_id=args.doc_id, email=args.email.strip().lower(), has_access=has)
            elif args.cmd == "list":
                emails = store.list_access(args.doc_id)
                result.update(ok=True, doc_id=args.doc_id, emails=emails)
            elif args.cmd == "list-all":
                docs = store.list_all_docs_with_access()
                result.update(ok=True, documents=docs)
            elif args.cmd == "accessible-for":
                docs = store.list_accessible_docs(args.email)
                result.update(
                    ok=True,
                    email=args.email.strip().lower(),
                    documents=[d.to_dict() for d in docs],
                )
            else:
                raise ValueError(f"Subcomando desconhecido: {args.cmd}")

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
