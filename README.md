# LLMInt / KHWF KI

Eine PHP-Webanwendung für lokale oder interne KI-Infrastrukturen.  
Die Anwendung kombiniert:

- einen Chat-Proxy für **LM Studio**-kompatible LLM-Endpunkte,
- eine Admin-Oberfläche für **mehrere Endpunkte mit Load-Balancing**,
- persistente **Gesprächssitzungen** mit automatischer Verfallszeit,
- optionale **Websuche über SearXNG**,
- optionale **Bildgenerierung über AUTOMATIC1111**,
- optionale **Bildgenerierung über ComfyUI**,
- dokumentbasierte **RAG-Suche** über hochgeladene Inhalte,
- sowie eine einfache Statistik- und Monitoring-Ansicht.

Die Oberfläche ist bewusst schlank gehalten: kein Framework, kein Build-Schritt, kein Node-Setup – nur PHP, MySQL und die angebundenen KI-Dienste.

---

## Inhaltsverzeichnis

- [Funktionsumfang](#funktionsumfang)
- [Architektur im Überblick](#architektur-im-überblick)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Starten der Anwendung](#starten-der-anwendung)
- [Ersteinrichtung im Admin-Bereich](#ersteinrichtung-im-admin-bereich)
- [Load-Balancing und Routing](#load-balancing-und-routing)
- [Intelligence Upgrade](#intelligence-upgrade)
- [Gesprächssitzungen](#gesprächssitzungen)
- [Optionale Integrationen](#optionale-integrationen)
- [Projektstruktur](#projektstruktur)
- [Wichtige API-Endpunkte](#wichtige-api-endpunkte)
- [Datenbanktabellen](#datenbanktabellen)
- [Sicherheitshinweise](#sicherheitshinweise)
- [Betriebshinweise](#betriebshinweise)
- [Troubleshooting](#troubleshooting)
- [Lizenz](#lizenz)

---

## Funktionsumfang

### Chat / LLM

- Chat-Oberfläche mit Streaming-Antworten per SSE (Server-Sent Events)
- optionaler System-Prompt pro Unterhaltung
- Markdown-Rendering für Assistentenantworten
- persistente Gesprächssitzungen – Nachrichtenverlauf wird serverseitig gespeichert und verfällt automatisch nach 30 Minuten Inaktivität
- zentrale Auswahl eines Standardmodells über den Admin-Bereich
- Weiterleitung von Chat-Anfragen an den jeweils passendsten LLM-Endpunkt
- optionaler Hinweis auf ein leistungsfähigeres Modell (Intelligence Upgrade), wenn freie Kapazität vorhanden ist
- Anzeige des verarbeitenden Endpunkts (`processed_by`) in der Chat-Antwort
- Erfassung von Tasks und Token-Nutzung für Statistikzwecke

### Administration

- Login-geschützter Admin-Bereich
- Verwaltung mehrerer LM-Studio-kompatibler Endpunkte inkl. optionalem **Alias**
- Aktivieren/Deaktivieren einzelner Endpunkte
- Laden verfügbarer Modelle direkt vom konfigurierten Endpunkt
- Verwaltung von AUTOMATIC1111- und ComfyUI-Endpunkten
- Testen externer Verbindungen aus der Oberfläche heraus
- Passwortänderung für den Admin-Benutzer
- Live-Ansicht der Lastverteilung

### Erweiterungen / Tools

- optionales Tool `search_web` über SearXNG – Websuche im Chat
- optionales Tool `generate_image` für AUTOMATIC1111 – Bildgenerierung mit Stable Diffusion
- optionales Tool `generate_image_comfy` für ComfyUI – alternative Bildgenerierung
- optionales Tool `query_documents` – chunk-basierte RAG-Suche über eigene und global freigegebene Dokumente

---

## Architektur im Überblick

Die Anwendung besteht aus drei zentralen Bereichen:

1. **Frontend (`index.php`)**  
   Stellt die Chat-Oberfläche bereit und sendet Anfragen an den PHP-Proxy.

2. **Proxy- und Integrations-APIs (`api/`)**  
   Regeln die Kommunikation mit LLM-, Websuche- und Bildgenerierungs-Endpunkten.

3. **Admin-Bereich (`admin/`)**  
   Dient zur Konfiguration, Überwachung und Pflege der angebundenen Dienste.

Die Konfiguration liegt überwiegend in der MySQL-Datenbank. Beim Start stellt `db.php` fehlende Kern-Tabellen automatisch bereit; `setup.php` richtet die vollständige Struktur inklusive Default-Admin ein.

---

## Voraussetzungen

### Pflicht

| Komponente | Empfohlen |
|---|---|
| PHP | >= 8.0 mit `curl` und `pdo_mysql` |
| MySQL / MariaDB | aktuelle Version |
| LM Studio oder kompatibler OpenAI-/REST-Endpunkt | erreichbar im Netzwerk |

### Optional

| Komponente | Zweck |
|---|---|
| SearXNG | aktuelle Websuche im Chat |
| AUTOMATIC1111 | Bildgenerierung über Stable Diffusion |
| ComfyUI | alternative Bildgenerierung |

---

## Installation

### 1. Repository bereitstellen

Projekt in ein Webverzeichnis oder lokales Arbeitsverzeichnis legen:

```bash
git clone https://github.com/dareinelt/LLMInt.git
cd LLMInt
```

### 2. Datenbank anlegen

Eine Datenbank anlegen, z. B.:

```sql
CREATE DATABASE llmint CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Datenbankzugang per Umgebungsvariablen setzen

Die Anwendung liest die Verbindung aus folgenden Variablen:

| Variable | Standardwert |
|---|---|
| `DB_HOST` | `localhost` |
| `DB_PORT` | `3306` |
| `DB_NAME` | `llmint` |
| `DB_USER` | `root` |
| `DB_PASS` | leer |

Beispiel:

```bash
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=llmint
export DB_USER=llmint
export DB_PASS='mein-passwort'
```

### 4. Setup ausführen

```bash
php setup.php
```

Das Setup:

- erstellt alle benötigten Tabellen (idempotent – kann gefahrlos mehrfach aufgerufen werden),
- initialisiert Standardwerte in der `settings`-Tabelle,
- legt bei Bedarf einen ersten LLM-Endpunkt an,
- und erzeugt einen Standard-Admin-Benutzer.

**Standard-Zugang nach dem ersten Setup:**

- Benutzername: `admin`
- Passwort: `admin`

> Dieses Passwort sofort nach dem ersten Login ändern.

> **Hinweis:** `setup.php` nach der Ersteinrichtung schützen oder aus dem Webverzeichnis entfernen.

---

## Starten der Anwendung

Für lokale Entwicklung reicht der eingebaute PHP-Server:

```bash
cd /pfad/zum/projekt
php -S localhost:8080
```

Danach:

- Chat: <http://localhost:8080>
- Admin: <http://localhost:8080/admin/login.php>

Für produktive Nutzung sollte die Anwendung hinter einem regulären Webserver (Apache, nginx) oder Reverse Proxy betrieben werden.

---

## Ersteinrichtung im Admin-Bereich

Nach dem Login unter `/admin/login.php` sollten typischerweise diese Schritte erfolgen:

1. **Admin-Passwort ändern**
2. **LLM-Endpunkte eintragen**
3. optional **SearXNG** aktivieren
4. optional **AUTOMATIC1111-Endpunkte** eintragen
5. optional **ComfyUI-Endpunkte** eintragen
6. gewünschte **Standardmodelle** pro Endpunkt setzen

### LLM-Endpunkte

Für jeden LLM-Endpunkt werden gepflegt:

- **Base URL**, z. B. `http://127.0.0.1:1234/v1`
- **Alias** (optional) – ein lesbarer Anzeigename, der im Chat als `processed_by` zurückgegeben wird; fehlt er, wird die Base URL verwendet
- **Timeout** (Sekunden)
- **Standardmodell** – legt die Load-Balancing-Gruppe fest
- **Aktiv/Inaktiv-Status**
- **Sortierreihenfolge**

Über **„Modelle laden"** kann die Anwendung die verfügbaren Modelle eines Endpunkts direkt abrufen.

### Bildendpunkte

Zusätzlich können verwaltet werden:

- **AUTOMATIC1111**-Instanzen mit Base URL und Timeout
- **ComfyUI**-Instanzen mit Base URL, Timeout und optionalem Default-Checkpoint

---

## Load-Balancing und Routing

### LLM-Endpunkte

LLM-Endpunkte werden in der Tabelle `endpoints` gespeichert.

- Endpunkte mit demselben `default_model` bilden eine **Gruppe**
- pro Gruppe wird der **am wenigsten belastete Endpunkt** gewählt (Least-Loaded-First)
- bei gleicher Last bevorzugt der Balancer den Endpunkt, der zuletzt am längsten nicht genutzt wurde (Round-Robin-Effekt)
- Endpunkte, die noch nie eine Aufgabe erhalten haben, werden bevorzugt
- pro Endpunkt sind maximal **4 parallele Tasks** vorgesehen
- sind alle 4 Slots aller passenden Endpunkte belegt, wartet der Chat-Request automatisch und blendet im Chat einen Hinweis ein, bis wieder Kapazität frei wird
- jede Task-Zuteilung wird innerhalb einer DB-Transaktion atomisch registriert, um Doppelbuchungen unter Last zu verhindern
- jeder Chat-Request wird in `tasks` protokolliert
- Token-Werte aus Antworten werden gespeichert und für Statistiken genutzt

Das Routing erfolgt über:

- `api/balancer.php` – Endpunkt-Auswahl und Task-Registrierung
- `api/chat.php` – Chat-Proxy inkl. Tool-Ausführung und SSE-Forwarding

### AUTOMATIC1111

- Endpunkte: `sd_endpoints`
- Task-Tracking: `sd_tasks`
- Routing: `api/sd_balancer.php`
- Ausgabeordner: `sd_output/`

### ComfyUI

- Endpunkte: `comfy_endpoints`
- Task-Tracking: `comfy_tasks`
- Routing: `api/comfy_balancer.php`
- Ausgabeordner: `sd_output/`

---

## Intelligence Upgrade

Nach Abschluss einer Chat-Antwort prüft das System, ob ein **leistungsfähigeres Modell** (d. h. mit höherem Parameteranzahl im Modellnamen, z. B. `11b` statt `4b`) mit freier Kapazität verfügbar ist.

- Ist ein solches Modell verfügbar, sendet `api/chat.php` ein SSE-Ereignis vom Typ `intelligence_upgrade` an den Client.
- Die Nutzeroberfläche kann diesen Hinweis anzeigen und dem Nutzer anbieten, die Anfrage mit dem stärkeren Modell zu wiederholen.
- Das Upgrade ist optional und erfordert eine explizite Nutzerinteraktion – die ursprüngliche Antwort bleibt vollständig erhalten.
- Die Erkennung basiert auf dem Muster `<Zahl>b` im Modellnamen (z. B. `llama-3-8b`, `llama-3.3-70b`).

---

## Gesprächssitzungen

Chat-Verläufe werden serverseitig in der Tabelle `conversation_sessions` gespeichert.

- Jede Sitzung ist über eine eindeutige `session_id` (64-stelliger Hex-Token) identifiziert.
- Bei jedem neuen Beitrag wird der vollständige Nachrichtenverlauf (Role/Content-Paare) aktualisiert.
- Sitzungen verfallen automatisch nach **30 Minuten Inaktivität** – abgelaufene Einträge werden periodisch bereinigt.
- Fällt ein Endpunkt während einer laufenden Konversation aus, kann der Verlauf nahtlos an einen anderen Endpunkt übergeben werden.
- Die Sitzungspersistenz ermöglicht mehrstufige Tool-Dialoge (z. B. Websuche → Folgeantwort) ohne Datenverlust bei einem Verbindungsabbruch.

---

## Optionale Integrationen

### SearXNG

Wenn im Admin-Bereich eine SearXNG-Basis-URL hinterlegt ist:

- stellt `api/chat.php` dem Modell automatisch das Tool `search_web` bereit,
- Suchanfragen werden über SearXNG ausgeführt,
- Ergebnisse (bis zu 5 Treffer mit Titel, URL, Snippet und Quellenangabe) werden dem Modell zurückgegeben,
- und Suchläufe werden in `search_logs` protokolliert.

Wichtig:

- nur die **Basis-URL** eintragen, nicht `/search`
- der Pfad `/search` wird intern automatisch ergänzt

Beispiel:

```text
https://search.example.org
```

### AUTOMATIC1111

Wenn mindestens ein aktiver SD-Endpunkt konfiguriert ist, kann das Chat-System das Tool `generate_image` bereitstellen.

Parameter des Tools:

| Parameter | Typ | Beschreibung |
|---|---|---|
| `prompt` | string (Pflicht) | Englischer Text-Prompt |
| `negative_prompt` | string | Elemente, die im Bild vermieden werden sollen |
| `width` | integer | Bildbreite in Pixeln (64–2048, Standard: 512) |
| `height` | integer | Bildhöhe in Pixeln (64–2048, Standard: 512) |

Die eigentliche Bildgenerierung läuft über:

- `api/sd_generate.php`
- API-Pfade `/sdapi/v1/txt2img` und `/sdapi/v1/img2img`

Gespeicherte Bilder landen unter `sd_output/`. Das Modell erhält einen Markdown-Image-Link zur direkten Einbettung in die Antwort.

### ComfyUI

Wenn mindestens ein aktiver ComfyUI-Endpunkt konfiguriert ist, kann das Chat-System das Tool `generate_image_comfy` bereitstellen.

Die Anwendung verwendet standardmäßig einen einfachen txt2img-Workflow mit KSampler. Falls kein Checkpoint am Endpunkt hinterlegt ist, versucht die Anwendung automatisch den ersten verfügbaren Checkpoint des Servers zu verwenden.

Gespeicherte Bilder landen unter `sd_output/`.

---

## Projektstruktur

```text
.
├── README.md
├── index.php                 # Chat-UI
├── setup.php                 # Initiales Setup für DB und Admin
├── db.php                    # DB-Verbindung, Settings-Helper, Runtime-Schema, Sitzungsverwaltung
├── config.php                # Legacy-Kompatibilität für Basis-Konfiguration
├── admin/
│   ├── login.php             # Admin-Login
│   ├── logout.php            # Admin-Logout
│   ├── index.php             # Admin-Dashboard (Endpunkte, Einstellungen, Statistiken)
│   └── load_stats.php        # Live-Statistiken als JSON
├── api/
│   ├── balancer.php          # LLM-Load-Balancing und Intelligence-Upgrade-Logik
│   ├── chat.php              # Chat-Proxy inkl. Tool-Ausführung und SSE-Streaming
│   ├── models.php            # Modellliste eines LLM-Endpunkts
│   ├── test_searxng.php      # Verbindungstest für SearXNG
│   ├── sd_balancer.php       # Load-Balancing für AUTOMATIC1111
│   ├── sd_generate.php       # Bildgenerierung über AUTOMATIC1111
│   ├── sd_checkpoints.php    # Checkpoint-Abfrage für AUTOMATIC1111
│   ├── comfy_balancer.php    # Load-Balancing für ComfyUI
│   ├── comfy_generate.php    # Bildgenerierung über ComfyUI
│   └── comfy_checkpoints.php # Checkpoint-Abfrage für ComfyUI
└── sd_output/                # gespeicherte generierte Bilder
```

---

## Wichtige API-Endpunkte

### Chat und Modelle

| Pfad | Methode | Zweck |
|---|---|---|
| `api/chat.php` | POST | Chat-Request an den passenden LLM-Endpunkt weiterleiten |
| `api/models.php` | GET | verfügbare Modelle eines Endpunkts abrufen |

`api/chat.php` gibt im SSE-Stream zusätzliche Ereignistypen zurück:

| SSE-Typ | Inhalt |
|---|---|
| `response_details` | `processed_by` – Alias oder Base URL des gewählten Endpunkts |
| `intelligence_upgrade` | Informationen zu einem leistungsfähigeren Modell mit freier Kapazität |

### Suche

| Pfad | Methode | Zweck |
|---|---|---|
| `api/test_searxng.php` | GET | SearXNG-Verbindung aus dem Admin testen |

### Bildgenerierung

| Pfad | Methode | Zweck |
|---|---|---|
| `api/sd_generate.php` | POST | Bild über AUTOMATIC1111 erzeugen |
| `api/sd_checkpoints.php` | GET | AUTOMATIC1111-Checkpoints abrufen |
| `api/comfy_generate.php` | POST | Bild über ComfyUI erzeugen |
| `api/comfy_checkpoints.php` | GET | ComfyUI-Checkpoints abrufen |

### Administration / Monitoring

| Pfad | Methode | Zweck |
|---|---|---|
| `admin/login.php` | GET/POST | Anmeldung |
| `admin/logout.php` | GET | Abmeldung |
| `admin/load_stats.php` | GET | Live-Metriken für das Dashboard |

---

## Datenbanktabellen

| Tabelle | Zweck |
|---|---|
| `settings` | allgemeine Konfiguration (z. B. `searxng_base_url`, `default_model`) |
| `users` | Admin-Benutzer |
| `endpoints` | LLM-Endpunkte inkl. Alias, Modell, Timeout und Sortierung |
| `tasks` | LLM-Aufgaben inkl. Status und Tokenverbrauch |
| `conversation_sessions` | persistierter Chat-Verlauf; verfällt nach 30 Min. Inaktivität |
| `search_logs` | Websuchen über SearXNG |
| `sd_endpoints` | AUTOMATIC1111-Endpunkte |
| `sd_tasks` | Bildgenerierungsaufgaben für AUTOMATIC1111 |
| `comfy_endpoints` | ComfyUI-Endpunkte inkl. Default-Checkpoint |
| `comfy_tasks` | Bildgenerierungsaufgaben für ComfyUI |

> **Hinweis:** Die Tabellen `conversation_sessions`, `sd_endpoints`, `sd_tasks`, `comfy_endpoints` und `comfy_tasks` werden von `db.php` beim ersten Verbindungsaufbau automatisch angelegt, sofern sie fehlen. Ein erneuter Lauf von `setup.php` ist nicht erforderlich.

---

## Sicherheitshinweise

- `setup.php` nur für die Ersteinrichtung verwenden und **danach schützen oder aus dem Webverzeichnis entfernen**
- das Standardpasswort `admin / admin` niemals im Betrieb beibehalten
- den Admin-Bereich nicht ungeschützt öffentlich exponieren
- Zugriffe möglichst über Reverse Proxy, VPN oder internes Netzwerk begrenzen
- nur vertrauenswürdige interne KI-Endpunkte anbinden
- prüfen, ob Schreibrechte für `sd_output/` korrekt gesetzt sind (empfohlen: `755`)
- Datenbankzugangsdaten ausschließlich per Umgebungsvariablen übergeben – niemals im Quellcode hinterlegen

---

## Betriebshinweise

- Die Anwendung speichert generierte Bilder lokal unter `sd_output/` – auf ausreichend Speicherplatz achten
- Der Chat verwendet das im Admin gesetzte Standardmodell; fehlt es, greift die Anwendung auf das erste aktive Endpunkt-Modell zurück
- Fällt ein Endpunkt aus oder ist ausgelastet, greift das Routing auf andere passende aktive Endpunkte zurück
- Abgelaufene Gesprächssitzungen werden automatisch bereinigt (probabilistisch bei jeder Anfrage)
- `config.php` dient primär der Rückwärtskompatibilität älterer Einbindungen
- Das Runtime-Schema in `db.php` hilft älteren Installationen, neue Kern-Tabellen automatisch nachzuziehen – Datenbankmigrationen müssen daher nicht manuell eingespielt werden

---

## Troubleshooting

### „Datenbankfehler. Bitte zuerst setup.php ausführen."

- prüfen, ob die DB-Umgebungsvariablen korrekt gesetzt sind
- prüfen, ob die Datenbank existiert und der Benutzer ausreichende Rechte hat
- `php setup.php` erneut ausführen

### Keine Modelle im Admin ladbar

- prüfen, ob die LLM-Base-URL korrekt ist (z. B. `http://127.0.0.1:1234/v1`)
- prüfen, ob der Endpunkt den Pfad `/models` bereitstellt
- prüfen, ob das Zielsystem vom Webserver aus netzwerkseitig erreichbar ist
- Timeout-Wert im Admin ggf. erhöhen

### Chat meldet „Kein Standardmodell konfiguriert"

- im Admin-Bereich für mindestens einen aktiven LLM-Endpunkt ein `default_model` setzen
- sicherstellen, dass der Endpunkt als aktiv markiert ist

### SearXNG funktioniert nicht

- nur die Basis-URL speichern, nicht `/search` (Beispiel: `https://search.example.org`)
- Verbindung über den Test-Button im Admin prüfen
- sicherstellen, dass die Instanz JSON-Antworten liefert (`format=json`)

### Bildgenerierung funktioniert nicht

- Base URL und Netzwerkzugriff prüfen
- bei AUTOMATIC1111 sicherstellen, dass die API erreichbar ist (`/sdapi/v1/txt2img`)
- bei ComfyUI prüfen, ob ein Checkpoint verfügbar ist; ggf. Default-Checkpoint im Admin eintragen
- Schreibrechte für `sd_output/` prüfen

### Intelligence-Upgrade-Hinweis erscheint nie

- der Hinweis wird nur angezeigt, wenn ein Modell mit einem höheren `Xb`-Marker im Namen verfügbar und der zugehörige Endpunkt aktiv ist
- sicherstellen, dass Modellnamen das Muster `<Zahl>b` enthalten (z. B. `llama-3-8b`, `qwen2.5-72b`)

---

## Lizenz

MIT
