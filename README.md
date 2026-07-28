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
- [Docker-Installation](#docker-installation)
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

## Docker-Installation

Docker ist der empfohlene Weg für eine reproduzierbare, isolierte Bereitstellung von LLMInt.  
Das mitgelieferte `docker-compose.yml` startet MySQL und die PHP/Apache-Anwendung mit einem einzigen Befehl.

### Voraussetzungen

| Komponente | Mindestversion |
|---|---|
| Docker | 24.x |
| Docker Compose | 2.x (Plugin oder `docker-compose` CLI) |

### 1. Repository klonen

```bash
git clone https://github.com/dareinelt/LLMInt.git
cd LLMInt
```

### 2. Umgebungsvariablen konfigurieren

Kopiere die Beispieldatei und passe die Werte an:

```bash
cp .env.example .env
```

Die `.env`-Datei enthält folgende Einstellungen:

```dotenv
# ── Datenbank ──────────────────────────────────────────────────────────────────
DB_NAME=llmint
DB_USER=llmint
DB_PASS=llmint          # ← sicheres Passwort setzen!
DB_ROOT_PASS=changeme   # ← sicheres Root-Passwort setzen!

# ── Web-Server ────────────────────────────────────────────────────────────────
HTTP_PORT=8080          # Host-Port, unter dem die App erreichbar ist

# ── Zeitzone ──────────────────────────────────────────────────────────────────
TZ=Europe/Berlin
```

> **Wichtig:** Ändere `DB_PASS` und `DB_ROOT_PASS` vor dem ersten Start auf starke Passwörter.  
> Die `.env`-Datei ist im `.dockerignore` aufgeführt und wird **nicht** in das Image gebacken.

### 3. Container bauen und starten

```bash
docker compose up -d --build
```

Beim ersten Start führt der Entrypoint automatisch `setup.php` aus, sobald MySQL bereit ist.  
Das vollständige Datenbankschema und der Standard-Admin-Account werden dabei angelegt.

Den Startvorgang verfolgen:

```bash
docker compose logs -f web
```

Die Ausgabe sieht in etwa so aus:

```
web-1  | [entrypoint] Waiting for database at db:3306…
web-1  | [entrypoint] Database is ready.
web-1  | [entrypoint] Running setup.php…
web-1  | [entrypoint] Setup complete.
web-1  | [entrypoint] Starting Apache…
```

### 4. Anwendung aufrufen

| Adresse | Zweck |
|---|---|
| `http://localhost:8080` | Chat-Oberfläche |
| `http://localhost:8080/admin/login.php` | Admin-Bereich |

Standardzugang:

- Benutzername: `admin`
- Passwort: `admin`

> Nach dem ersten Login sofort das Passwort ändern.

### Docker-Verzeichnisstruktur

```text
.
├── Dockerfile              # PHP 8.2 + Apache + Extensions
├── docker-compose.yml      # MySQL + Web-Service
├── .env.example            # Vorlage für Umgebungsvariablen
├── .dockerignore           # Aus dem Build-Kontext ausgeschlossene Dateien
└── docker/
    ├── apache.conf         # Apache-VirtualHost-Konfiguration
    ├── php.ini             # PHP-Einstellungen (Limits, Session, Zeitzone)
    └── entrypoint.sh       # Wartet auf DB, führt setup.php aus, startet Apache
```

### PHP-Einstellungen (docker/php.ini)

| Einstellung | Wert | Erläuterung |
|---|---|---|
| `upload_max_filesize` | 100 MB | Maximale Dateigröße für Dokument-Uploads |
| `post_max_size` | 100 MB | Maximale POST-Body-Größe |
| `memory_limit` | 1024 MB | Arbeitsspeicherlimit für PHP |
| `max_execution_time` | 3000 s | Timeout für LLM-Streaming-Requests |
| `max_input_time` | 3000 s | Timeout beim Einlesen großer Requests |
| `session.cookie_httponly` | 1 | Schützt Sitzungscookies vor JavaScript-Zugriff |
| `session.use_strict_mode` | 1 | Verhindert Session-Fixation-Angriffe |

Diese Werte können über die `.env`-Variable `TZ` (Zeitzone) oder durch Mounten einer eigenen `php.ini` überschrieben werden.

### Persistenz (Named Volumes)

Docker Compose verwendet drei benannte Volumes, damit Daten Container-Neustarts überleben:

| Volume | Pfad im Container | Inhalt |
|---|---|---|
| `db_data` | `/var/lib/mysql` | MySQL-Datenbankdateien |
| `doc_uploads` | `/var/www/html/doc_uploads` | Hochgeladene Dokumente (RAG) |
| `sd_output` | `/var/www/html/sd_output` | Generierte Bilder (SD / ComfyUI) |

Volumes auflisten:

```bash
docker volume ls | grep llmint
```

### Container-Lebenszyklus

| Befehl | Wirkung |
|---|---|
| `docker compose up -d` | Container im Hintergrund starten |
| `docker compose up -d --build` | Image neu bauen und starten |
| `docker compose stop` | Container anhalten (Volumes bleiben erhalten) |
| `docker compose down` | Container entfernen (Volumes bleiben erhalten) |
| `docker compose down -v` | Container **und** Volumes entfernen (Datenverlust!) |
| `docker compose restart web` | Nur den Web-Container neu starten |
| `docker compose logs -f web` | Logs des Web-Containers live verfolgen |
| `docker compose exec web bash` | Shell im laufenden Web-Container öffnen |

### Aktualisieren auf eine neue Version

```bash
git pull
docker compose up -d --build
```

Das Entrypoint-Skript führt `setup.php` bei jedem Start aus.  
Da das Setup idempotent ist, werden fehlende Tabellen ergänzt, ohne bestehende Daten zu verändern.

### Optionale Konfiguration

#### Anderen Host-Port verwenden

In der `.env` anpassen:

```dotenv
HTTP_PORT=8090
```

Dann Container neu starten:

```bash
docker compose up -d
```

#### Windows-SSO / Kerberos (GSSAPI)

Das Image enthält bereits `mod_auth_gssapi` und `krb5-user`.  
Um transparentes Windows-Single-Sign-On zu aktivieren:

1. Keytab und `krb5.conf` erstellen und im Projektverzeichnis ablegen.
2. In `docker-compose.yml` die auskommentierten Volume-Mounts aktivieren:

   ```yaml
   - ./krb5.keytab:/etc/krb5.keytab:ro
   - ./krb5.conf:/etc/krb5.conf:ro
   ```

3. Den GSSAPI-Block in `docker/apache.conf` einkommentieren:

   ```apache
   AuthType GSSAPI
   AuthName "LLMInt – Windows SSO"
   GssapiCredStore keytab:/etc/krb5.keytab
   GssapiLocalName On
   Require valid-user
   ```

4. Image neu bauen und starten:

   ```bash
   docker compose up -d --build
   ```

> Keytab-Dateien sind Zugangsdaten. Niemals in das Image einbauen oder in Git committen.  
> Sie sind im `.dockerignore` explizit ausgeschlossen.

#### Reverse Proxy (nginx / Traefik)

Für HTTPS-Betrieb hinter einem Reverse Proxy den internen Port nicht mehr öffentlich freigeben:

```yaml
# docker-compose.yml – web service
ports: []          # keinen Host-Port mehr binden
```

Dann den Reverse Proxy auf den internen Container-Port 80 leiten.

### Troubleshooting (Docker)

#### Container startet nicht – Datenbankfehler

```bash
docker compose logs db
docker compose logs web
```

Häufige Ursachen:

- `DB_PASS` in `.env` stimmt nicht mit dem bereits initialisierten Volume überein → Volume löschen und neu starten.
- MySQL braucht beim allerersten Start länger. Der Entrypoint wartet automatisch, bis die DB antwortet.

#### Volume mit falschen Zugangsdaten initialisiert

```bash
docker compose down -v   # Achtung: löscht alle Volumes!
docker compose up -d --build
```

#### PHP-Logs einsehen

```bash
docker compose exec web tail -f /var/log/apache2/error.log
```

#### setup.php manuell erneut ausführen

```bash
docker compose exec web php /var/www/html/setup.php
```

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
