"""
cleanalize_core/rag/store.py
Abstracao sobre SQLite + sqlite-vec para o vector store do RAG.

Um arquivo `.db` por usuario, em uploads/rag/<email_sanitizado>/index.db.
Tabelas: documents, chunks, chat_history + virtual table chunks_vec (sqlite-vec).
"""
from __future__ import annotations

import json
import re
import sqlite3
import struct
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, List, Optional, Sequence

try:
    import sqlite_vec  # type: ignore
    _HAS_VEC = True
except ImportError:  # pragma: no cover
    _HAS_VEC = False

from ..config import project_root
from .embeddings import EMBEDDING_DIM


# ---------- Utilidades de caminho ----------

def sanitize_email(email: str) -> str:
    """Converte um email em um nome de diretorio seguro."""
    if not email:
        raise ValueError("email vazio")
    normalized = email.strip().lower()
    safe = re.sub(r"[^a-z0-9]+", "_", normalized).strip("_")
    if not safe:
        raise ValueError(f"email invalido: {email!r}")
    return safe


def user_rag_dir(email: str, base: Optional[Path] = None) -> Path:
    """Diretorio raiz do RAG de um usuario."""
    root = base or (project_root() / "uploads" / "rag")
    return root / sanitize_email(email)


def user_db_path(email: str, base: Optional[Path] = None) -> Path:
    return user_rag_dir(email, base) / "index.db"


def user_files_dir(email: str, base: Optional[Path] = None) -> Path:
    return user_rag_dir(email, base) / "files"


# ---------- Serializacao de vetores ----------

def _vec_to_blob(vec: Sequence[float]) -> bytes:
    return struct.pack(f"{len(vec)}f", *vec)


# ---------- Modelos ----------

@dataclass
class Document:
    doc_id: str
    filename: str
    pages: int
    status: str            # 'processing' | 'ready' | 'error'
    error_message: Optional[str]
    created_at: int

    def to_dict(self) -> dict:
        return {
            "doc_id": self.doc_id,
            "filename": self.filename,
            "pages": self.pages,
            "status": self.status,
            "error_message": self.error_message,
            "created_at": self.created_at,
        }


@dataclass
class ChunkHit:
    chunk_id: int
    doc_id: str
    page: int
    text: str
    distance: float


# ---------- Store principal ----------

class RagStore:
    def __init__(self, email: str, base: Optional[Path] = None):
        self.email = email
        self.db_path = user_db_path(email, base)
        self.files_dir = user_files_dir(email, base)
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self.files_dir.mkdir(parents=True, exist_ok=True)
        self._conn: Optional[sqlite3.Connection] = None

    # --- conexao ---

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(str(self.db_path))
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA foreign_keys = ON;")
        if _HAS_VEC:
            try:
                conn.enable_load_extension(True)
                sqlite_vec.load(conn)
                conn.enable_load_extension(False)
            except Exception as e:  # pragma: no cover
                raise RuntimeError(f"Falha ao carregar sqlite-vec: {e}") from e
        return conn

    def __enter__(self) -> "RagStore":
        self._conn = self._connect()
        self._init_schema()
        return self

    def __exit__(self, exc_type, exc, tb) -> None:
        if self._conn is not None:
            self._conn.close()
            self._conn = None

    @property
    def conn(self) -> sqlite3.Connection:
        if self._conn is None:
            raise RuntimeError("RagStore nao aberto. Use com 'with RagStore(...) as store:'")
        return self._conn

    # --- schema ---

    def _init_schema(self) -> None:
        c = self.conn
        c.executescript(
            """
            CREATE TABLE IF NOT EXISTS documents (
                doc_id TEXT PRIMARY KEY,
                filename TEXT NOT NULL,
                pages INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                error_message TEXT,
                created_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS chunks (
                chunk_id INTEGER PRIMARY KEY AUTOINCREMENT,
                doc_id TEXT NOT NULL REFERENCES documents(doc_id) ON DELETE CASCADE,
                page INTEGER NOT NULL DEFAULT 0,
                text TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_chunks_doc ON chunks(doc_id);

            CREATE TABLE IF NOT EXISTS chat_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                doc_id TEXT NOT NULL REFERENCES documents(doc_id) ON DELETE CASCADE,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                sources_json TEXT,
                created_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_history_doc ON chat_history(doc_id, created_at);
            """
        )
        if _HAS_VEC:
            c.execute(
                f"CREATE VIRTUAL TABLE IF NOT EXISTS chunks_vec USING vec0("
                f"embedding FLOAT[{EMBEDDING_DIM}])"
            )
        c.commit()

    # --- documents CRUD ---

    def create_document(self, doc_id: str, filename: str) -> None:
        self.conn.execute(
            "INSERT INTO documents (doc_id, filename, pages, status, created_at) "
            "VALUES (?, ?, 0, 'processing', ?)",
            (doc_id, filename, int(time.time())),
        )
        self.conn.commit()

    def mark_ready(self, doc_id: str, pages: int) -> None:
        self.conn.execute(
            "UPDATE documents SET status='ready', pages=?, error_message=NULL WHERE doc_id=?",
            (pages, doc_id),
        )
        self.conn.commit()

    def mark_error(self, doc_id: str, message: str) -> None:
        self.conn.execute(
            "UPDATE documents SET status='error', error_message=? WHERE doc_id=?",
            (message[:2000], doc_id),
        )
        self.conn.commit()

    def get_document(self, doc_id: str) -> Optional[Document]:
        row = self.conn.execute(
            "SELECT * FROM documents WHERE doc_id=?", (doc_id,)
        ).fetchone()
        return _row_to_doc(row) if row else None

    def list_documents(self) -> List[Document]:
        rows = self.conn.execute(
            "SELECT * FROM documents ORDER BY created_at DESC"
        ).fetchall()
        return [_row_to_doc(r) for r in rows]

    def delete_document(self, doc_id: str) -> None:
        c = self.conn
        # Remove vetores associados (ON DELETE CASCADE nao se aplica a virtual table)
        if _HAS_VEC:
            ids = [
                r["chunk_id"]
                for r in c.execute("SELECT chunk_id FROM chunks WHERE doc_id=?", (doc_id,))
            ]
            if ids:
                placeholders = ",".join("?" * len(ids))
                c.execute(f"DELETE FROM chunks_vec WHERE rowid IN ({placeholders})", ids)
        c.execute("DELETE FROM chunks WHERE doc_id=?", (doc_id,))
        c.execute("DELETE FROM chat_history WHERE doc_id=?", (doc_id,))
        c.execute("DELETE FROM documents WHERE doc_id=?", (doc_id,))
        c.commit()

    # --- chunks + vetores ---

    def insert_chunks(
        self,
        doc_id: str,
        chunks: Iterable[tuple[int, str]],   # (page, text)
        embeddings: Sequence[Sequence[float]],
    ) -> int:
        c = self.conn
        chunk_list = list(chunks)
        if len(chunk_list) != len(embeddings):
            raise ValueError("chunks e embeddings com tamanhos diferentes")
        inserted = 0
        for (page, text), vec in zip(chunk_list, embeddings):
            cur = c.execute(
                "INSERT INTO chunks (doc_id, page, text) VALUES (?, ?, ?)",
                (doc_id, page, text),
            )
            chunk_id = cur.lastrowid
            if _HAS_VEC:
                c.execute(
                    "INSERT INTO chunks_vec(rowid, embedding) VALUES (?, ?)",
                    (chunk_id, _vec_to_blob(vec)),
                )
            inserted += 1
        c.commit()
        return inserted

    def search(
        self,
        doc_id: str,
        query_embedding: Sequence[float],
        top_k: int = 5,
    ) -> List[ChunkHit]:
        if not _HAS_VEC:
            raise RuntimeError(
                "sqlite-vec nao disponivel; instale o pacote 'sqlite-vec' para busca vetorial."
            )
        c = self.conn
        rows = c.execute(
            """
            SELECT ch.chunk_id, ch.doc_id, ch.page, ch.text, v.distance
            FROM chunks_vec v
            JOIN chunks ch ON ch.chunk_id = v.rowid
            WHERE v.embedding MATCH ?
              AND ch.doc_id = ?
              AND k = ?
            ORDER BY v.distance
            """,
            (_vec_to_blob(query_embedding), doc_id, top_k),
        ).fetchall()
        return [
            ChunkHit(
                chunk_id=r["chunk_id"],
                doc_id=r["doc_id"],
                page=r["page"],
                text=r["text"],
                distance=float(r["distance"]),
            )
            for r in rows
        ]

    # --- chat history ---

    def append_chat(
        self,
        doc_id: str,
        role: str,
        content: str,
        sources: Optional[List[dict]] = None,
    ) -> None:
        self.conn.execute(
            "INSERT INTO chat_history (doc_id, role, content, sources_json, created_at) "
            "VALUES (?, ?, ?, ?, ?)",
            (
                doc_id,
                role,
                content,
                json.dumps(sources, ensure_ascii=False) if sources else None,
                int(time.time()),
            ),
        )
        self.conn.commit()

    def get_chat_history(self, doc_id: str, limit: int = 50) -> List[dict]:
        rows = self.conn.execute(
            "SELECT role, content, sources_json, created_at FROM chat_history "
            "WHERE doc_id=? ORDER BY created_at ASC LIMIT ?",
            (doc_id, limit),
        ).fetchall()
        out = []
        for r in rows:
            out.append(
                {
                    "role": r["role"],
                    "content": r["content"],
                    "sources": json.loads(r["sources_json"]) if r["sources_json"] else None,
                    "created_at": r["created_at"],
                }
            )
        return out


def _row_to_doc(row: sqlite3.Row) -> Document:
    return Document(
        doc_id=row["doc_id"],
        filename=row["filename"],
        pages=row["pages"],
        status=row["status"],
        error_message=row["error_message"],
        created_at=row["created_at"],
    )
