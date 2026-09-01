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


# --- Documentos globais BBZ ---

GLOBAL_SCOPE_DIRNAME = "_global"


def global_rag_dir(base: Optional[Path] = None) -> Path:
    """Diretorio raiz dos documentos globais (Documentos BBZ)."""
    root = base or (project_root() / "uploads" / "rag")
    return root / GLOBAL_SCOPE_DIRNAME


def global_db_path(base: Optional[Path] = None) -> Path:
    return global_rag_dir(base) / "index.db"


def global_files_dir(base: Optional[Path] = None) -> Path:
    return global_rag_dir(base) / "files"


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
    """Vector store por escopo. Dois escopos suportados:
      - 'user': um .db por usuario (uploads/rag/<email_safe>/index.db)
      - 'global': um .db unico compartilhado (uploads/rag/_global/index.db)
    """

    def __init__(
        self,
        email: Optional[str] = None,
        base: Optional[Path] = None,
        *,
        scope: str = "user",
    ):
        if scope == "global":
            self.scope = "global"
            self.email = None
            self.db_path = global_db_path(base)
            self.files_dir = global_files_dir(base)
        else:
            if not email:
                raise ValueError("email e obrigatorio para scope='user'")
            self.scope = "user"
            self.email = email
            self.db_path = user_db_path(email, base)
            self.files_dir = user_files_dir(email, base)
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self.files_dir.mkdir(parents=True, exist_ok=True)
        self._conn: Optional[sqlite3.Connection] = None

    @classmethod
    def global_store(cls, base: Optional[Path] = None) -> "RagStore":
        """Construtor alternativo para o store de Documentos BBZ (globais)."""
        return cls(email=None, base=base, scope="global")

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
            """
        )

        if self.scope == "user":
            # chat_history (sem FK para doc_id — um usuario pode ter historico
            # com docs globais que nao estao neste DB local).
            c.executescript(
                """
                CREATE TABLE IF NOT EXISTS chat_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    doc_id TEXT NOT NULL,
                    role TEXT NOT NULL,
                    content TEXT NOT NULL,
                    sources_json TEXT,
                    created_at INTEGER NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_history_doc ON chat_history(doc_id, created_at);
                """
            )
            # Migracao: se o chat_history foi criado antes com FK para documents,
            # recria sem FK (necessario para suportar historico de docs globais).
            fks = c.execute("PRAGMA foreign_key_list(chat_history)").fetchall()
            if fks:
                c.executescript(
                    """
                    PRAGMA foreign_keys = OFF;
                    BEGIN;
                    CREATE TABLE chat_history_new (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        doc_id TEXT NOT NULL,
                        role TEXT NOT NULL,
                        content TEXT NOT NULL,
                        sources_json TEXT,
                        created_at INTEGER NOT NULL
                    );
                    INSERT INTO chat_history_new (id, doc_id, role, content, sources_json, created_at)
                      SELECT id, doc_id, role, content, sources_json, created_at FROM chat_history;
                    DROP TABLE chat_history;
                    ALTER TABLE chat_history_new RENAME TO chat_history;
                    CREATE INDEX IF NOT EXISTS idx_history_doc ON chat_history(doc_id, created_at);
                    COMMIT;
                    PRAGMA foreign_keys = ON;
                    """
                )

        if self.scope == "global":
            # Tabela de ACL: quem pode ler este doc global.
            c.executescript(
                """
                CREATE TABLE IF NOT EXISTS document_access (
                    doc_id TEXT NOT NULL REFERENCES documents(doc_id) ON DELETE CASCADE,
                    email TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    PRIMARY KEY (doc_id, email)
                );
                CREATE INDEX IF NOT EXISTS idx_access_email ON document_access(email);
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
        if self.scope == "user":
            c.execute("DELETE FROM chat_history WHERE doc_id=?", (doc_id,))
        if self.scope == "global":
            c.execute("DELETE FROM document_access WHERE doc_id=?", (doc_id,))
        c.execute("DELETE FROM documents WHERE doc_id=?", (doc_id,))
        c.commit()

    # --- ACL (apenas scope='global') ---

    def _require_global(self, op: str) -> None:
        if self.scope != "global":
            raise RuntimeError(f"{op} so e permitido no scope='global'")

    def grant_access(self, doc_id: str, email: str) -> None:
        self._require_global("grant_access")
        self.conn.execute(
            "INSERT OR IGNORE INTO document_access (doc_id, email, created_at) "
            "VALUES (?, ?, ?)",
            (doc_id, email.strip().lower(), int(time.time())),
        )
        self.conn.commit()

    def revoke_access(self, doc_id: str, email: str) -> None:
        self._require_global("revoke_access")
        self.conn.execute(
            "DELETE FROM document_access WHERE doc_id=? AND email=?",
            (doc_id, email.strip().lower()),
        )
        self.conn.commit()

    def has_access(self, doc_id: str, email: str) -> bool:
        self._require_global("has_access")
        row = self.conn.execute(
            "SELECT 1 FROM document_access WHERE doc_id=? AND email=? LIMIT 1",
            (doc_id, email.strip().lower()),
        ).fetchone()
        return row is not None

    def list_access(self, doc_id: str) -> List[str]:
        self._require_global("list_access")
        rows = self.conn.execute(
            "SELECT email FROM document_access WHERE doc_id=? ORDER BY email",
            (doc_id,),
        ).fetchall()
        return [r["email"] for r in rows]

    def list_accessible_docs(self, email: str) -> List[Document]:
        self._require_global("list_accessible_docs")
        rows = self.conn.execute(
            "SELECT d.* FROM documents d "
            "INNER JOIN document_access a ON a.doc_id = d.doc_id "
            "WHERE a.email = ? ORDER BY d.created_at DESC",
            (email.strip().lower(),),
        ).fetchall()
        return [_row_to_doc(r) for r in rows]

    def list_all_docs_with_access(self) -> List[dict]:
        self._require_global("list_all_docs_with_access")
        docs = self.list_documents()
        out = []
        for d in docs:
            rec = d.to_dict()
            rec["access"] = self.list_access(d.doc_id)
            out.append(rec)
        return out

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
