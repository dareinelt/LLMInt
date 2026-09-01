# LLMInt Document Converter (`docconvert`)

Python-Microservice, der Office- und Textdokumente in reinen Text sowie
strukturbewusste, überlappende Chunks für die RAG-Pipeline von LLMInt umwandelt.

Der Dienst wird ausschließlich intern von `api/upload_document.php` über das
Docker-Netzwerk angesprochen und veröffentlicht standardmäßig keinen Host-Port.

## Unterstützte Formate

| Gruppe | Formate | Bibliothek |
|---|---|---|
| Word | `.docx` | python-docx |
| Excel | `.xlsx`, `.xlsm`, `.xls` | openpyxl, xlrd |
| PowerPoint | `.pptx` | python-pptx |
| OpenDocument | `.odt`, `.ods`, `.odp` | odfpy, pandas |
| Tabellen | `.csv`, `.tsv` | csv (Delimiter-Erkennung) |
| Text | `.txt`, `.md`, `.log`, `.ini`, `.conf`, `.yaml`, `.yml` | Standardbibliothek |
| Markup | `.html`, `.htm`, `.xml`, `.json`, `.rtf` | BeautifulSoup/lxml, striprtf |

PDF und Bilder werden weiterhin in PHP verarbeitet (`pdftotext` bzw. Vision-Modell).

## Chunking

Die Konverter erzeugen logische Blöcke mit Quellenangabe – z. B.
`Kapitel › Unterkapitel`, `Blatt "Umsatz", Zeilen 1–40` oder `Folie 3`. Das
Chunking führt Blöcke derselben Gruppe zusammen, splittet zu große Blöcke mit
Überlappung an Satz-/Zeilengrenzen und stellt jedem Chunk seine Quellenangabe als
Kopfzeile voran. Dadurch bleibt der Kontext im Embedding und später in der
LLM-Antwort erhalten.

## Zwischenspeicher

Konvertierungsergebnisse werden per SHA-256 über Dateiinhalt und Chunk-Parameter
temporär auf Platte gecacht (`DOCCONVERT_CACHE_TTL`, Standard 1 Stunde). Der
Cache wird bei jedem Schreibvorgang aufgeräumt und kann über `DELETE /cache`
geleert werden.

## API

| Route | Methode | Beschreibung |
|---|---|---|
| `/health` | GET | Liveness + unterstützte Endungen |
| `/formats` | GET | Formate inkl. MIME-Typen |
| `/convert` | POST | Multipart (`file`, optional `max_chars`, `overlap`, `mime_type`) |
| `/cache/stats` | GET | Cache-Statistik |
| `/cache` | DELETE | Cache leeren |

Antwort von `/convert`:

```json
{
  "ok": true,
  "filename": "bericht.docx",
  "format": "docx",
  "label": "Word-Dokument",
  "text": "…",
  "chunks": [{ "index": 0, "text": "[Kapitel 1]\n…", "source_ref": "Kapitel 1" }],
  "chunk_count": 12,
  "meta": { "paragraphs": 210, "tables": 3 },
  "cached": false
}
```

Fehler werden als `{"ok": false, "message": "…"}` mit HTTP 415 (Format), 422
(Parserfehler), 413 (zu groß) oder 500 zurückgegeben.

## Konfiguration

| Variable | Standard | Bedeutung |
|---|---|---|
| `DOCCONVERT_TOKEN` | – | Optionales Shared Secret (Header `X-Auth-Token`) |
| `DOCCONVERT_MAX_BYTES` | `20971520` | Maximale Uploadgröße |
| `DOCCONVERT_MAX_CHARS` | `1800` | Standard-Chunkgröße |
| `DOCCONVERT_OVERLAP` | `250` | Standard-Überlappung |
| `DOCCONVERT_CACHE_DIR` | `/var/cache/docconvert` | Cache-Verzeichnis |
| `DOCCONVERT_CACHE_TTL` | `3600` | Cache-TTL in Sekunden (`0` = aus) |
| `DOCCONVERT_CACHE_MAX` | `500` | Maximale Cache-Einträge |
| `DOCCONVERT_TEXT_LIMIT` | `4000000` | Maximale Zeichen im Volltext |

## Lokal starten

```bash
cd docconvert
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8000
python -m pytest tests -q
```
