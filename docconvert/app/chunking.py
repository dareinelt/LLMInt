"""Chunking helpers for the LLMInt document converter.

The converters produce a list of :class:`Block` objects (a piece of text plus a
human readable source reference such as ``Blatt "Umsatz"`` or ``Folie 3``).
:func:`build_chunks` turns those blocks into RAG-sized chunks, keeping the
source reference as a header line so the information survives into the
embedding and later into the LLM context.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field

DEFAULT_MAX_CHARS = 1800
DEFAULT_OVERLAP = 250


@dataclass
class Block:
    """A logically coherent piece of a document."""

    text: str
    source_ref: str = ""
    # Blocks sharing a group are merged into one chunk as long as they fit.
    group: str = ""


@dataclass
class Document:
    """Conversion result of a single file."""

    blocks: list = field(default_factory=list)
    meta: dict = field(default_factory=dict)

    def add(self, text: str, source_ref: str = "", group: str = "") -> None:
        text = (text or "").strip()
        if text:
            self.blocks.append(Block(text=text, source_ref=source_ref, group=group or source_ref))

    @property
    def text(self) -> str:
        parts = []
        last_ref = None
        for block in self.blocks:
            if block.source_ref and block.source_ref != last_ref:
                parts.append(f"[{block.source_ref}]")
                last_ref = block.source_ref
            parts.append(block.text)
        return normalize_text("\n\n".join(parts))


def normalize_text(text: str) -> str:
    """Normalise line endings and collapse redundant whitespace."""

    text = (text or "").replace("\r\n", "\n").replace("\r", "\n")
    text = text.replace("\u00a0", " ").replace("\x00", "")
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r" *\n *", "\n", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def _split_oversized(text: str, max_chars: int, overlap: int) -> list:
    """Split a single oversized text into overlapping windows."""

    pieces = []
    start = 0
    length = len(text)

    while start < length:
        end = min(length, start + max_chars)
        if end < length:
            # Prefer a break at a sentence/line boundary inside the last 20 %.
            window_start = max(start + int(max_chars * 0.8), start + 1)
            boundary = max(
                text.rfind("\n", window_start, end),
                text.rfind(". ", window_start, end),
            )
            if boundary > start:
                end = boundary + 1

        piece = text[start:end].strip()
        if piece:
            pieces.append(piece)

        if end >= length:
            break

        next_start = end - overlap
        if next_start <= start:
            next_start = end
        start = next_start

    return pieces


def build_chunks(document: Document, max_chars: int = DEFAULT_MAX_CHARS,
                 overlap: int = DEFAULT_OVERLAP) -> list:
    """Turn a :class:`Document` into structure aware, overlapping chunks."""

    max_chars = max(200, int(max_chars))
    overlap = max(0, min(int(overlap), max_chars // 2))

    collected = []
    buffer = ""
    buffer_ref = ""
    buffer_group = None

    def flush():
        nonlocal buffer, buffer_ref, buffer_group
        text = buffer.strip()
        if text:
            collected.append({"text": text, "source_ref": buffer_ref})
        buffer = ""
        buffer_ref = ""
        buffer_group = None

    for block in document.blocks:
        text = normalize_text(block.text)
        if not text:
            continue

        if buffer_group is not None and block.group != buffer_group:
            flush()

        candidate = f"{buffer}\n\n{text}" if buffer else text
        if len(candidate) <= max_chars:
            buffer = candidate
            buffer_ref = block.source_ref or buffer_ref
            buffer_group = block.group
            continue

        flush()

        if len(text) <= max_chars:
            buffer = text
            buffer_ref = block.source_ref
            buffer_group = block.group
            continue

        for piece in _split_oversized(text, max_chars, overlap):
            collected.append({"text": piece, "source_ref": block.source_ref})

    flush()

    result = []
    for index, chunk in enumerate(collected):
        text = chunk["text"]
        ref = chunk["source_ref"]
        # Prefix the source reference so retrieved chunks stay self-describing.
        if ref and not text.startswith(f"[{ref}]"):
            text = f"[{ref}]\n{text}"
        result.append({"index": index, "text": text, "source_ref": ref})
    return result
