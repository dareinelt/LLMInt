"""LLMInt document conversion service.

A small FastAPI application that turns Office documents and text files into
plain text plus structure-aware, overlapping chunks ready for the LLMInt
embedding/RAG pipeline.

Endpoints
---------
``GET  /health``       liveness probe + supported formats
``GET  /formats``      supported extensions and MIME types
``POST /convert``      multipart upload -> text + chunks
``GET  /cache/stats``  statistics of the temporary conversion cache
``DELETE /cache``      clear the temporary conversion cache

Configuration (environment variables)
-------------------------------------
``DOCCONVERT_TOKEN``        optional shared secret, checked as ``X-Auth-Token``
``DOCCONVERT_MAX_BYTES``    maximum upload size (default 20 MiB)
``DOCCONVERT_MAX_CHARS``    default chunk size (default 1800)
``DOCCONVERT_OVERLAP``      default chunk overlap (default 250)
``DOCCONVERT_CACHE_DIR``    cache directory (default: temp dir)
``DOCCONVERT_CACHE_TTL``    cache TTL in seconds, 0 disables (default 3600)
``DOCCONVERT_CACHE_MAX``    maximum cache entries (default 500)
``DOCCONVERT_TEXT_LIMIT``   maximum characters of full text returned (default 4000000)
"""

from __future__ import annotations

import hmac
import logging
import os
import time

from fastapi import FastAPI, File, Form, Header, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from .cache import ConversionCache
from .chunking import DEFAULT_MAX_CHARS, DEFAULT_OVERLAP, build_chunks
from .converters import (
    SUPPORTED_FORMATS,
    ConversionError,
    UnsupportedFormatError,
    convert,
)

logging.basicConfig(
    level=os.getenv("DOCCONVERT_LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s %(levelname)s %(message)s",
)
log = logging.getLogger("docconvert")

AUTH_TOKEN = os.getenv("DOCCONVERT_TOKEN", "").strip()
MAX_BYTES = int(os.getenv("DOCCONVERT_MAX_BYTES", str(20 * 1024 * 1024)))
DEFAULT_CHUNK_CHARS = int(os.getenv("DOCCONVERT_MAX_CHARS", str(DEFAULT_MAX_CHARS)))
DEFAULT_CHUNK_OVERLAP = int(os.getenv("DOCCONVERT_OVERLAP", str(DEFAULT_OVERLAP)))
TEXT_LIMIT = int(os.getenv("DOCCONVERT_TEXT_LIMIT", str(4_000_000)))

cache = ConversionCache(
    directory=os.getenv("DOCCONVERT_CACHE_DIR") or None,
    ttl_seconds=int(os.getenv("DOCCONVERT_CACHE_TTL", "3600")),
    max_entries=int(os.getenv("DOCCONVERT_CACHE_MAX", "500")),
)

app = FastAPI(
    title="LLMInt Document Converter",
    version="1.0.0",
    description="Konvertiert Office- und Textdokumente in RAG-taugliche Chunks.",
)


def _check_auth(token: str | None) -> None:
    if not AUTH_TOKEN:
        return
    if not token or not hmac.compare_digest(token, AUTH_TOKEN):
        raise HTTPException(status_code=401, detail="Ungültiger oder fehlender Token.")


def _formats() -> list:
    return [
        {"extension": ext, "label": label, "mime_types": mimes}
        for ext, (label, mimes) in sorted(SUPPORTED_FORMATS.items())
    ]


@app.get("/health")
def health() -> dict:
    return {
        "ok": True,
        "service": "docconvert",
        "version": app.version,
        "extensions": sorted(SUPPORTED_FORMATS.keys()),
        "max_bytes": MAX_BYTES,
        "auth_required": bool(AUTH_TOKEN),
    }


@app.get("/formats")
def formats() -> dict:
    return {"ok": True, "formats": _formats()}


@app.get("/cache/stats")
def cache_stats(x_auth_token: str | None = Header(default=None)) -> dict:
    _check_auth(x_auth_token)
    return {"ok": True, "cache": cache.stats()}


@app.delete("/cache")
def cache_clear(x_auth_token: str | None = Header(default=None)) -> dict:
    _check_auth(x_auth_token)
    return {"ok": True, "removed": cache.clear()}


@app.post("/convert")
async def convert_document(
    file: UploadFile = File(...),
    max_chars: int = Form(default=0),
    overlap: int = Form(default=-1),
    mime_type: str = Form(default=""),
    x_auth_token: str | None = Header(default=None),
):
    """Convert an uploaded document into plain text and RAG chunks."""

    _check_auth(x_auth_token)

    content = await file.read()
    if not content:
        raise HTTPException(status_code=400, detail="Leere Datei empfangen.")
    if len(content) > MAX_BYTES:
        raise HTTPException(
            status_code=413,
            detail=f"Datei zu groß ({len(content)} Bytes, erlaubt sind {MAX_BYTES}).",
        )

    chunk_chars = DEFAULT_CHUNK_CHARS if max_chars <= 0 else max_chars
    chunk_overlap = DEFAULT_CHUNK_OVERLAP if overlap < 0 else overlap
    filename = file.filename or "dokument"
    declared_mime = mime_type or (file.content_type or "")

    cache_key = ConversionCache.key(content, filename, chunk_chars, chunk_overlap)
    cached = cache.get(cache_key)
    if cached is not None:
        cached["cached"] = True
        log.info("Cache-Treffer für %s (%d Chunks)", filename, cached.get("chunk_count", 0))
        return JSONResponse(cached)

    started = time.monotonic()
    try:
        document, ext = convert(content, filename, declared_mime)
    except UnsupportedFormatError as exc:
        raise HTTPException(status_code=415, detail=str(exc)) from exc
    except ConversionError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    except Exception as exc:  # noqa: BLE001 - never leak a traceback to the caller
        log.exception("Konvertierung von %s fehlgeschlagen", filename)
        raise HTTPException(
            status_code=500, detail=f"Interner Konvertierungsfehler: {exc}"
        ) from exc

    chunks = build_chunks(document, chunk_chars, chunk_overlap)
    text = document.text
    truncated = len(text) > TEXT_LIMIT
    if truncated:
        text = text[:TEXT_LIMIT]

    payload = {
        "ok": True,
        "filename": filename,
        "format": ext.lstrip("."),
        "label": document.meta.get("label", "Dokument"),
        "text": text,
        "text_truncated": truncated,
        "chunks": chunks,
        "chunk_count": len(chunks),
        "chunk_chars": chunk_chars,
        "chunk_overlap": chunk_overlap,
        "meta": document.meta,
        "duration_ms": int((time.monotonic() - started) * 1000),
        "cached": False,
    }

    cache.set(cache_key, payload)
    log.info(
        "%s konvertiert (%s, %d Zeichen, %d Chunks, %d ms)",
        filename, ext, len(text), len(chunks), payload["duration_ms"],
    )
    return JSONResponse(payload)


@app.exception_handler(HTTPException)
async def http_exception_handler(_request, exc: HTTPException):
    """Return errors in the same ``{ok, message}`` shape the PHP client expects."""

    return JSONResponse(
        status_code=exc.status_code,
        content={"ok": False, "message": str(exc.detail)},
    )
