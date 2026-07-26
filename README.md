# LLMInt / KHWF KI

LLMInt ist eine leichtgewichtige PHP-Webanwendung für lokale oder interne KI-Infrastrukturen.  
Sie bündelt Chat, Tool-Aufrufe, Endpunkt-Verwaltung und Monitoring in einer Oberfläche – ohne Framework-Ballast und ohne Build-Pipeline.

---

## Inhaltsverzeichnis

- [Ziel und Einsatzbereich](#ziel-und-einsatzbereich)
- [Kernfunktionen](#kernfunktionen)
- [Systemüberblick](#systemüberblick)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Schnellstart](#schnellstart)
- [Admin-Ersteinrichtung](#admin-ersteinrichtung)
- [Funktionsweise im Betrieb](#funktionsweise-im-betrieb)
- [Optionale Integrationen](#optionale-integrationen)
- [Projektstruktur](#projektstruktur)
- [Wichtige API-Endpunkte](#wichtige-api-endpunkte)
- [Datenbanktabellen](#datenbanktabellen)
- [Sicherheit](#sicherheit)
- [Betrieb und Wartung](#betrieb-und-wartung)
- [Troubleshooting](#troubleshooting)
- [Lizenz](#lizenz)

---

## Ziel und Einsatzbereich

LLMInt richtet sich an Teams, Labore und interne Plattformen, die:

- mehrere LLM-Endpunkte parallel betreiben,
- Nutzeranfragen stabil und fair verteilen wollen,
- Websuche/Bildgenerierung als Tools im Chat benötigen,
- und eine nachvollziehbare, wartbare Lösung mit klassischem PHP/MySQL-Stack bevorzugen.

Typische Szenarien:

- Campus- oder Unternehmensinterne KI-Assistenz,
- Multi-Host-Betrieb von LM-Studio-kompatiblen Modellen,
- zentrale Oberfläche für Chat + RAG + Bildgenerierung,
- robuste Entwicklungs- und Testumgebungen für lokale KI-Dienste.

---

## Kernfunktionen

### 1) Chat-Proxy mit Streaming

- SSE-Streaming (Server-Sent Events) für unmittelbare Antwortausgabe
- Persistente Konversationen in `conversation_sessions`
- Optionaler System-Prompt pro Sitzung
- Nachvollziehbarkeit über `response_details.processed_by` (Alias oder Base URL)

### 2) Multi-Endpunkt-Routing mit Lastverteilung

- Endpunkte in `endpoints`, Task-Tracking in `tasks`
- Gruppierung über `default_model`
- Least-Loaded-Auswahl mit Fairness-Verhalten bei gleicher Last
- Maximal 4 parallele Tasks je Endpunkt
- Wartelogik bei voller Auslastung

### 3) Intelligente Modell-Empfehlung

- Optionales `intelligence_upgrade`-Signal nach Antworten
- Hinweis auf stärkeres freies Modell (z. B. 8b → 70b)
- Kein automatischer Modellwechsel ohne Nutzereinwilligung

### 4) Erweiterbare Tool-Nutzung im Chat

- `search_web` über SearXNG (optional)
- `generate_image` über AUTOMATIC1111 (optional)
- `generate_image_comfy` über ComfyUI (optional)
- `query_documents` für RAG über eigene + global freigegebene Dokumente

### 5) Admin-Oberfläche für Betrieb und Kontrolle

- Login-geschützter Admin-Bereich
- Endpunktverwaltung inkl. Alias, Aktivstatus, Timeout, Sortierung
- Modellabruf je Endpunkt
- Verbindungstests und Laststatistiken
- Passwortverwaltung für Admin-Konto

---

## Systemüberblick

Die Anwendung gliedert sich in drei Ebenen:

1. **Frontend (`index.php`)**  
   Chat-UI mit Streaming, Modell- und Tool-Interaktion.

2. **API-Schicht (`api/`)**  
   Routing, Tool-Ausführung, externe Integrationen, Antwort-Forwarding.

3. **Administration (`admin/`)**  
   Konfiguration, Überwachung und Pflege der Infrastruktur.

Konfigurationsdaten werden primär in MySQL gespeichert.  
`setup.php` initialisiert die vollständige Struktur, `db.php` ergänzt bei Bedarf fehlende Kern-Tabellen zur Laufzeit.

---

## Voraussetzungen

### Pflicht

| Komponente | Empfehlung |
|---|---|
| PHP | >= 8.0 mit `curl`, `pdo_mysql` |
| MySQL/MariaDB | aktuelle Version |
| LLM-Endpunkt(e) | LM-Studio-kompatibel, im Netzwerk erreichbar |

### Optional

| Komponente | Zweck |
|---|---|
| SearXNG | Websuche im Chat (`search_web`) |
| AUTOMATIC1111 | Stable-Diffusion-Bildgenerierung (`generate_image`) |
| ComfyUI | Alternative Bildpipeline (`generate_image_comfy`) |

---

## Installation

### 1. Repository bereitstellen

```bash
git clone https://github.com/dareinelt/LLMInt.git
cd LLMInt
```

### 2. Datenbank anlegen

```sql
CREATE DATABASE llmint CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. DB-Umgebungsvariablen setzen

| Variable | Standard |
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
export DB_PASS='starkes-passwort'
```

### 4. Setup ausführen

```bash
php setup.php
```

Das Setup ist idempotent und kann bei Bedarf erneut ausgeführt werden.

Standardzugang nach Erstinstallation:

- Benutzername: `admin`
- Passwort: `admin`

> Nach dem ersten Login sofort Passwort ändern und `setup.php` absichern oder entfernen.

---

## Schnellstart

Für lokale Entwicklung:

```bash
php -S localhost:8080
```

Danach erreichbar unter:

- Chat: <http://localhost:8080>
- Admin: <http://localhost:8080/admin/login.php>

Für produktive Nutzung wird ein regulärer Webserver oder Reverse Proxy empfohlen.

---

## Admin-Ersteinrichtung

Empfohlene Reihenfolge:

1. Admin-Passwort ändern
2. LLM-Endpunkte eintragen und aktivieren
3. Standardmodell(e) setzen
4. Optional SearXNG hinterlegen
5. Optional SD-/Comfy-Endpunkte hinterlegen
6. Modell- und Verbindungstests durchführen

### LLM-Endpunkte

Pflicht-/Option-Felder:

- Base URL (z. B. `http://127.0.0.1:1234/v1`)
- Alias (optional, für lesbare Ausgabe in `processed_by`)
- Timeout (Sekunden)
- `default_model` (relevant für Routing-Gruppe)
- Aktivstatus und Sortierung

### Bild-Endpunkte

- AUTOMATIC1111: Base URL + Timeout
- ComfyUI: Base URL + Timeout + optionaler Default-Checkpoint

---

## Funktionsweise im Betrieb

### Routing und Lastverteilung (LLM)

- Alle aktiven Endpunkte einer Modellgruppe (`default_model`) konkurrieren um neue Requests.
- Die Auswahl erfolgt über die geringste aktuelle Last.
- Bei Gleichstand werden länger ungenutzte Endpunkte bevorzugt.
- Pro Endpunkt sind maximal 4 gleichzeitige Tasks zugelassen.
- Bei Vollauslastung wartet der Request, statt hart fehlzuschlagen.

### Task-Tracking

- Jede Anfrage wird in `tasks` erfasst.
- Statuswechsel und Nutzungsdaten (inkl. Token) unterstützen Monitoring und Auswertung.

### Konversationsspeicher

- Sitzungen werden über eine eindeutige `session_id` geführt.
- Nachrichtenverlauf wird serverseitig gespeichert.
- Inaktive Sitzungen verfallen nach 30 Minuten und werden automatisch bereinigt.

### Antwort-Metadaten

SSE kann zusätzlich liefern:

- `response_details` mit `processed_by`
- `intelligence_upgrade` bei verfügbarem stärkerem Modell

---

## Optionale Integrationen

### SearXNG (`search_web`)

Wenn `settings.searxng_base_url` gesetzt ist:

- wird das Tool `search_web` dem Modell bereitgestellt,
- Suchen laufen über SearXNG,
- Treffer werden strukturiert zurückgegeben,
- Suchvorgänge werden in `search_logs` protokolliert.

Wichtig: Nur die Basis-URL eintragen, nicht `/search`.

### AUTOMATIC1111 (`generate_image`)

Bei aktivem SD-Endpunkt:

- Tool-Aufruf über Chat möglich,
- Generierung via `api/sd_generate.php`,
- Ergebnisse als Datei in `sd_output/`.

### ComfyUI (`generate_image_comfy`)

Bei aktivem Comfy-Endpunkt:

- Tool-Aufruf über Chat möglich,
- Generierung via `api/comfy_generate.php`,
- Default-Workflow für txt2img,
- Ergebnisse in `sd_output/`.

### Dokument-RAG (`query_documents`)

- Durchsucht eigene Uploads plus global freigegebene Dokumente,
- liefert Treffer zur Kontextanreicherung im Chat,
- unterstützt Wissensarbeit ohne externes Vektordatenbank-Setup.

---

## Projektstruktur

```text
.
├── README.md
├── Demo.md
├── index.php                 # Chat-UI
├── setup.php                 # Initiales Setup
├── db.php                    # DB-Helper, Runtime-Schema, Sessions
├── config.php                # Legacy-Basis-Konfiguration
├── admin/
│   ├── login.php             # Admin-Login
│   ├── logout.php            # Admin-Logout
│   ├── index.php             # Admin-Dashboard
│   └── load_stats.php        # Live-Statistiken (JSON)
├── api/
│   ├── chat.php              # Chat-Proxy + Tool-Logik
│   ├── balancer.php          # LLM-Balancing + Task-Handling
│   ├── models.php            # Modellabruf
│   ├── test_searxng.php      # SearXNG-Verbindungstest
│   ├── sd_balancer.php       # SD-Balancing
│   ├── sd_generate.php       # SD-Generierung
│   ├── sd_checkpoints.php    # SD-Checkpoint-Liste
│   ├── comfy_balancer.php    # Comfy-Balancing
│   ├── comfy_generate.php    # Comfy-Generierung
│   └── comfy_checkpoints.php # Comfy-Checkpoint-Liste
└── sd_output/                # Generierte Bilder
```

---

## Wichtige API-Endpunkte

### Chat und Modelle

| Pfad | Methode | Zweck |
|---|---|---|
| `api/chat.php` | POST | Chat-Anfrage an passenden LLM-Endpunkt weiterleiten |
| `api/models.php` | GET | Modelle eines Endpunkts abrufen |

Zusätzliche SSE-Ereignisse:

| SSE-Typ | Inhalt |
|---|---|
| `response_details` | `processed_by` (Alias oder Base URL) |
| `intelligence_upgrade` | Hinweis auf leistungsfähigeres Modell |

### Suche

| Pfad | Methode | Zweck |
|---|---|---|
| `api/test_searxng.php` | GET | SearXNG-Konnektivität prüfen |

### Bildgenerierung

| Pfad | Methode | Zweck |
|---|---|---|
| `api/sd_generate.php` | POST | Bild über AUTOMATIC1111 erzeugen |
| `api/sd_checkpoints.php` | GET | SD-Checkpoints abrufen |
| `api/comfy_generate.php` | POST | Bild über ComfyUI erzeugen |
| `api/comfy_checkpoints.php` | GET | Comfy-Checkpoints abrufen |

### Administration

| Pfad | Methode | Zweck |
|---|---|---|
| `admin/login.php` | GET/POST | Anmeldung |
| `admin/logout.php` | GET | Abmeldung |
| `admin/load_stats.php` | GET | Live-Metriken |

---

## Datenbanktabellen

| Tabelle | Zweck |
|---|---|
| `settings` | globale Konfiguration (u. a. `default_model`, `searxng_base_url`) |
| `users` | Admin-Accounts |
| `endpoints` | LLM-Endpunkte inkl. Alias, Modell, Timeout, Aktivstatus |
| `tasks` | LLM-Task-Lebenszyklus und Nutzungswerte |
| `conversation_sessions` | serverseitige Chat-Sitzungen |
| `search_logs` | Protokoll der Websuche |
| `sd_endpoints` | AUTOMATIC1111-Endpunkte |
| `sd_tasks` | SD-Task-Verwaltung |
| `comfy_endpoints` | ComfyUI-Endpunkte |
| `comfy_tasks` | ComfyUI-Task-Verwaltung |

---

## Sicherheit

- `setup.php` nach Ersteinrichtung absichern oder entfernen
- Standardpasswort niemals im Betrieb belassen
- Admin-Bereich nicht offen ins Internet stellen
- Zugriff nach Möglichkeit auf internes Netz/VPN begrenzen
- Nur vertrauenswürdige Endpunkte anbinden
- Schreibrechte für `sd_output/` kontrollieren
- DB-Zugang ausschließlich über Umgebungsvariablen verwalten

---

## Betrieb und Wartung

- Speicherverbrauch in `sd_output/` regelmäßig prüfen
- Timeout-Werte entsprechend Netzwerklatenz anpassen
- Endpunktstatus im Admin überwachen
- Bei Modelländerungen `default_model`-Gruppen konsistent halten
- Bei Lastspitzen zusätzliche Endpunkte pro Modellgruppe ergänzen
- Alte Chat-Sitzungen werden automatisch bereinigt (kein manueller Cron nötig)

---

## Troubleshooting

### „Datenbankfehler. Bitte zuerst setup.php ausführen."

- DB-Variablen prüfen
- Benutzerrechte prüfen
- `php setup.php` erneut ausführen

### Keine Modelle im Admin abrufbar

- Base URL prüfen (inkl. `/v1`, falls nötig)
- Erreichbarkeit vom Webserver prüfen
- Timeout erhöhen
- API-Kompatibilität des Zielsystems prüfen

### „Kein Standardmodell konfiguriert"

- Für mindestens einen aktiven Endpunkt `default_model` setzen
- Aktivstatus der Endpunkte prüfen

### SearXNG liefert keine Ergebnisse

- Nur Basis-URL speichern
- Verbindung über Admin testen
- JSON-Ausgabe sicherstellen (`format=json`)

### Bildgenerierung schlägt fehl

- Endpunkt und Netzwerk prüfen
- SD-API-Verfügbarkeit (`/sdapi/v1/txt2img`) prüfen
- Bei ComfyUI Checkpoint-Verfügbarkeit prüfen
- Dateirechte von `sd_output/` prüfen

### Kein Intelligence-Upgrade-Hinweis

- Nur verfügbar bei stärkerem freien Modell
- Modellnamen müssen ein erkennbares `<Zahl>b`-Muster enthalten

---

## Lizenz

MIT
