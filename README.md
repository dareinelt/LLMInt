# LM Studio Chat – PHP Web-App

Eine schlanke PHP-Webanwendung, die über die [LM Studio REST API](https://lmstudio.ai/docs/developer/rest) mit einem lokal laufenden LM Studio Server kommuniziert.

## Features

- **Modellauswahl** – erkennt automatisch alle am Endpunkt verfügbaren Modelle
- **Chat-Interface** – vollständige Gesprächshistorie mit Streaming-Unterstützung (SSE)
- **System-Prompt** – optionaler System-Prompt konfigurierbar per Klick
- **Aktuelle Websuche** – optionaler SearXNG-Zugriff für Modelle, zentral im Adminbereich konfigurierbar
- **Konfigurierbarer Endpunkt** – API-URL direkt im Browser änderbar (wird im `localStorage` gespeichert)
- **Kein Framework nötig** – reines PHP + Vanilla JavaScript

---

## Voraussetzungen

| Software | Version |
|---|---|
| PHP | ≥ 8.0 (mit `curl`-Extension) |
| LM Studio | aktuell, mit aktiviertem lokalen Server |

---

## Installation & Start

### 1. LM Studio Server starten

1. LM Studio öffnen
2. Ein Modell laden
3. Linke Seitenleiste → **Local Server** → **Start Server**  
   (Standard-Port: `1234`)

### 2. PHP-Server starten

```bash
cd /pfad/zum/projekt
php -S localhost:8080
```

Dann im Browser öffnen: [http://localhost:8080](http://localhost:8080)

### 3. Konfiguration (optional)

Der Standard-Endpunkt ist `http://localhost:1234/v1`.  
Er lässt sich entweder:

- **Im Browser** – über das Endpunkt-Feld im Config-Bereich der App ändern, oder
- **Per Umgebungsvariable** – vor dem Start des PHP-Servers setzen:

```bash
LMSTUDIO_BASE_URL=http://192.168.1.100:1234/v1 php -S localhost:8080
```

---

## Projektstruktur

```
.
├── index.php          # Haupt-UI (HTML + Inline-JavaScript)
├── config.php         # Konfiguration (Basis-URL, Timeout)
└── api/
    ├── models.php     # GET /v1/models – verfügbare Modelle
    └── chat.php       # POST /v1/chat/completions – Chat-Endpunkt
```

---

## API-Endpunkte (intern)

| Endpunkt | Methode | Beschreibung |
|---|---|---|
| `api/models.php?endpoint=…` | GET | Listet verfügbare LM Studio Modelle auf |
| `api/chat.php` | POST | Sendet eine Chat-Anfrage (Streaming per SSE) |

---

## SearXNG-Integration

Im Adminbereich kann optional eine SearXNG-URL hinterlegt werden. Wenn eine URL gesetzt ist, stellt der Chat-Proxy dem Modell automatisch ein Websuch-Tool bereit, damit Antworten mit aktuellen Informationen angereichert werden können. Ein leeres Feld deaktiviert die Suche wieder.

---

## Lizenz

MIT
