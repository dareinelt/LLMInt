"""Tests for the LLMInt document converter.

Run with ``python -m pytest docconvert/tests -q`` from the repository root or
``python -m pytest tests -q`` from inside ``docconvert/``.
"""

from __future__ import annotations

import io
import json
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.chunking import Document, build_chunks, normalize_text  # noqa: E402
from app.cache import ConversionCache  # noqa: E402
from app.converters import (  # noqa: E402
    ConversionError,
    UnsupportedFormatError,
    convert,
    detect_extension,
)


# ── Chunking ──────────────────────────────────────────────────────────────────


def test_normalize_text_collapses_whitespace():
    assert normalize_text("a  \t b\r\n\r\n\r\n c") == "a b\n\nc"


def test_build_chunks_merges_small_blocks_of_same_group():
    document = Document()
    document.add("Erster Absatz.", "Kapitel 1", "Kapitel 1")
    document.add("Zweiter Absatz.", "Kapitel 1", "Kapitel 1")

    chunks = build_chunks(document, max_chars=500, overlap=50)

    assert len(chunks) == 1
    assert "Erster Absatz." in chunks[0]["text"]
    assert "Zweiter Absatz." in chunks[0]["text"]
    assert chunks[0]["text"].startswith("[Kapitel 1]")


def test_build_chunks_splits_groups():
    document = Document()
    document.add("A", "Kapitel 1", "Kapitel 1")
    document.add("B", "Kapitel 2", "Kapitel 2")

    chunks = build_chunks(document, max_chars=500, overlap=50)

    assert [chunk["source_ref"] for chunk in chunks] == ["Kapitel 1", "Kapitel 2"]


def test_build_chunks_splits_oversized_block_with_overlap():
    document = Document()
    document.add("Satz. " * 400, "Lang", "Lang")

    chunks = build_chunks(document, max_chars=400, overlap=100)

    assert len(chunks) > 1
    assert all(len(chunk["text"]) <= 400 + len("[Lang]\n") for chunk in chunks)
    assert all(chunk["source_ref"] == "Lang" for chunk in chunks)


def test_build_chunks_terminates_without_overlap():
    document = Document()
    document.add("x" * 5000, "Block", "Block")

    chunks = build_chunks(document, max_chars=1000, overlap=0)

    assert len(chunks) == 5


# ── Cache ─────────────────────────────────────────────────────────────────────


def test_cache_round_trip(tmp_path):
    cache = ConversionCache(tmp_path, ttl_seconds=60)
    key = ConversionCache.key(b"content", "a.docx", 1800, 250)

    assert cache.get(key) is None
    cache.set(key, {"ok": True, "chunk_count": 3})
    assert cache.get(key)["chunk_count"] == 3
    assert cache.stats()["entries"] == 1
    assert cache.clear() == 1


def test_cache_key_depends_on_parameters():
    a = ConversionCache.key(b"content", "a.docx", 1800, 250)
    b = ConversionCache.key(b"content", "a.docx", 900, 250)
    assert a != b


def test_cache_disabled_with_zero_ttl(tmp_path):
    cache = ConversionCache(tmp_path, ttl_seconds=0)
    key = ConversionCache.key(b"content", "a.txt", 1800, 250)
    cache.set(key, {"ok": True})
    assert cache.get(key) is None


def test_cache_enforces_max_entries(tmp_path):
    cache = ConversionCache(tmp_path, ttl_seconds=600, max_entries=2)
    for index in range(4):
        cache.set(ConversionCache.key(f"c{index}".encode(), "a.txt", 1800, 250), {"i": index})
    assert cache.stats()["entries"] <= 2


# ── Format detection ──────────────────────────────────────────────────────────


def test_detect_extension_by_name():
    assert detect_extension("report.DOCX") == ".docx"


def test_detect_extension_by_mime():
    assert detect_extension("blob", "text/csv") == ".csv"


def test_detect_extension_unknown_raises():
    with pytest.raises(UnsupportedFormatError):
        detect_extension("archive.zip", "application/zip")


def test_convert_rejects_fake_office_file():
    with pytest.raises(ConversionError):
        convert(b"not a zip", "fake.docx")


def test_convert_rejects_empty_file():
    with pytest.raises(ConversionError):
        convert(b"", "empty.txt")


# ── Text-based converters ─────────────────────────────────────────────────────


def test_convert_plain_text():
    document, ext = convert("Absatz eins.\n\nAbsatz zwei.".encode(), "notiz.txt")
    assert ext == ".txt"
    assert len(document.blocks) == 2


def test_convert_markdown_tracks_headings():
    content = "# Titel\n\nText A\n\n## Unterkapitel\n\nText B".encode()
    document, _ext = convert(content, "doku.md")
    refs = {block.source_ref for block in document.blocks}
    assert "Titel" in refs
    assert "Titel › Unterkapitel" in refs


def test_convert_csv_builds_markdown_table():
    content = "Name;Umsatz\nAlpha;100\nBeta;200\n".encode()
    document, _ext = convert(content, "zahlen.csv")
    text = document.text
    assert "| Name | Umsatz |" in text
    assert "| Beta | 200 |" in text
    assert document.meta["delimiter"] == ";"


def test_convert_json_object_splits_by_key():
    content = json.dumps({"a": 1, "b": [1, 2]}).encode()
    document, _ext = convert(content, "daten.json")
    assert len(document.blocks) == 2


def test_convert_html_strips_scripts():
    content = b"<html><head><title>T</title><script>bad()</script></head><body><h1>H</h1><p>Inhalt</p></body></html>"
    document, _ext = convert(content, "seite.html")
    text = document.text
    assert "bad()" not in text
    assert "Inhalt" in text


# ── Binary Office converters ──────────────────────────────────────────────────


def test_convert_docx_with_headings_and_table():
    docx = pytest.importorskip("docx")

    buffer = io.BytesIO()
    source = docx.Document()
    source.add_heading("Kapitel 1", level=1)
    source.add_paragraph("Ein Absatz im Kapitel.")
    table = source.add_table(rows=2, cols=2)
    table.cell(0, 0).text = "Name"
    table.cell(0, 1).text = "Wert"
    table.cell(1, 0).text = "Alpha"
    table.cell(1, 1).text = "42"
    source.save(buffer)

    document, ext = convert(buffer.getvalue(), "bericht.docx")

    assert ext == ".docx"
    text = document.text
    assert "Kapitel 1" in text
    assert "| Alpha | 42 |" in text
    assert any(block.source_ref.startswith("Kapitel 1") for block in document.blocks)


def test_convert_xlsx_keeps_sheet_names():
    openpyxl = pytest.importorskip("openpyxl")

    workbook = openpyxl.Workbook()
    sheet = workbook.active
    sheet.title = "Umsatz"
    sheet.append(["Monat", "Betrag"])
    sheet.append(["Januar", 100])
    buffer = io.BytesIO()
    workbook.save(buffer)

    document, ext = convert(buffer.getvalue(), "zahlen.xlsx")

    assert ext == ".xlsx"
    assert document.meta["sheets"] == ["Umsatz"]
    assert 'Blatt "Umsatz"' in document.text
    assert "| Januar | 100 |" in document.text


def test_convert_pptx_uses_slide_refs():
    pptx = pytest.importorskip("pptx")

    presentation = pptx.Presentation()
    slide = presentation.slides.add_slide(presentation.slide_layouts[5])
    slide.shapes.title.text = "Agenda"
    buffer = io.BytesIO()
    presentation.save(buffer)

    document, ext = convert(buffer.getvalue(), "praesentation.pptx")

    assert ext == ".pptx"
    assert document.blocks[0].source_ref == "Folie 1"
    assert "Agenda" in document.text
