#!/usr/bin/env python3
"""
rag_ingest.py
CLI que executa o pipeline de ingestao de um documento para o RAG:
  OCR (Mistral) -> chunking -> embeddings (OpenAI) -> SQLite+sqlite-vec.

Uso:
  python rag_ingest.py --pdf <caminho> --user <email> --doc-id <uuid> [--filename <nome>]

Saida (stdout): JSON com o resultado da ingestao. Em caso de erro, retorna
JSON com 'ok': false e atualiza o status do documento como 'error'.
"""
from __future__ import annotations

import argparse
import json
import sys
import traceback
from pathlib import Path

from cleanalize_core.config import get_env
from cleanalize_core.rag.chunking import chunk_pages
from cleanalize_core.rag.embeddings import embed_texts
from cleanalize_core.rag.mistral_ocr import ocr_file, pages_to_plain_text
from cleanalize_core.rag.store import RagStore, user_files_dir


def _int_env(name: str, default: int) -> int:
    raw = get_env(name, str(default)) or str(default)
    try:
        return int(raw)
    except ValueError:
        return default


def main() -> int:
    parser = argparse.ArgumentParser(description="Ingest de documento para o RAG")
    parser.add_argument("--pdf", required=True, help="Caminho do PDF/imagem a ingerir")
    parser.add_argument("--user", required=True, help="Email do usuario dono do documento")
    parser.add_argument("--doc-id", required=True, help="UUID do documento (gerado pelo PHP)")
    parser.add_argument("--filename", default=None, help="Nome original do arquivo")
    args = parser.parse_args()

    pdf_path = Path(args.pdf)
    filename = args.filename or pdf_path.name

    result = {"ok": False, "doc_id": args.doc_id, "user": args.user}

    try:
        if not pdf_path.is_file():
            raise FileNotFoundError(f"Arquivo nao encontrado: {pdf_path}")

        with RagStore(args.user) as store:
            # 1) Registra o documento como 'processing'
            store.create_document(args.doc_id, filename)

            try:
                # 2) Copia o PDF para o diretorio do usuario (se ainda nao estiver la)
                dest_dir = user_files_dir(args.user) / args.doc_id
                dest_dir.mkdir(parents=True, exist_ok=True)
                dest_pdf = dest_dir / "original.pdf"
                if pdf_path.resolve() != dest_pdf.resolve():
                    dest_pdf.write_bytes(pdf_path.read_bytes())

                # 3) OCR
                pages = ocr_file(dest_pdf)
                if not pages:
                    raise RuntimeError("OCR retornou zero paginas")

                # Persiste texto bruto para auditoria
                (dest_dir / "ocr.txt").write_text(
                    pages_to_plain_text(pages), encoding="utf-8"
                )

                # 4) Chunking
                chunk_size = _int_env("RAG_CHUNK_SIZE", 800)
                overlap = _int_env("RAG_CHUNK_OVERLAP", 100)
                chunks = chunk_pages(pages, chunk_size=chunk_size, overlap=overlap)
                if not chunks:
                    raise RuntimeError("Nenhum chunk gerado a partir do OCR")

                # 5) Embeddings
                vectors = embed_texts([c.text for c in chunks])

                # 6) Persistencia
                store.insert_chunks(
                    doc_id=args.doc_id,
                    chunks=[(c.page, c.text) for c in chunks],
                    embeddings=vectors,
                )
                store.mark_ready(args.doc_id, pages=len(pages))

                result.update(
                    ok=True,
                    filename=filename,
                    pages=len(pages),
                    chunks=len(chunks),
                )
            except Exception as inner:
                store.mark_error(args.doc_id, f"{type(inner).__name__}: {inner}")
                raise

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
