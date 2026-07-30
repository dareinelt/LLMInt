# LLMInt / KHWF KI

LLMInt ist eine PHP/MySQL-Anwendung für den Betrieb einer internen KI-Plattform.
Sie bündelt Chat, Routing, Lastverteilung, Dokument-RAG, Bildgenerierung, Benutzerverwaltung und Monitoring in einer Oberfläche.

---

## Inhaltsverzeichnis

- [Überblick](#überblick)
- [Hauptfunktionen](#hauptfunktionen)
- [Architektur](#architektur)
- [Voraussetzungen](#voraussetzungen)
- [Installation (klassisch)](#installation-klassisch)
- [Installation mit Docker](#installation-mit-docker)
- [Erstkonfiguration im Admin-Bereich](#erstkonfiguration-im-admin-bereich)
- [Authentifizierung und Benutzerverwaltung](#authentifizierung-und-benutzerverwaltung)
- [Routing und Lastverteilung im Detail](#routing-und-lastverteilung-im-detail)
- [Tooling im Chat](#tooling-im-chat)
- [Monitoring und Logging](#monitoring-und-logging)
- [Projektstruktur](#projektstruktur)
- [API-Endpunkte](#api-endpunkte)
- [Datenbankschema](#datenbankschema)
- [Konfigurationswerte (`settings`)](#konfigurationswerte-settings)
- [Sicherheitsempfehlungen](#sicherheitsempfehlungen)
- [Betrieb und Wartung](#betrieb-und-wartung)
- [Troubleshooting](#troubleshooting)
- [Lizenz](#lizenz)

---

## Überblick

LLMInt adressiert typische Anforderungen interner KI-Setups:

- mehrere LLM-Endpunkte parallel betreiben,
- Requests automatisch auf passende Modelle und freie Hosts verteilen,
- ergänzende Tools (Websuche, Bildgenerierung, Dokumentkontext) direkt im Chat nutzen,
- Benutzerzugriffe und Passwörter zentral verwalten,
- Systemlast und Nutzungsdaten transparent überwachen.

Die Anwendung ist framework-frei (klassisches PHP) und läuft mit einem Standard-Stack aus PHP + MySQL/MariaDB.

---

## Hauptfunktionen

### 1) Chat mit Streaming und Session-Verlauf

- SSE-Streaming (`text/event-stream`) für fortlaufende Antwortausgabe
- persistente Gesprächssitzungen pro Nutzer (`conversation_sessions`)
- Sitzungsverwaltung über API (`list`, `load`, `delete`)
- Antwort-Metadaten über `response_details` inkl. `processed_by`

### 2) Zweistufiges Routing

- **Stufe A: semantische Klassifikation** über optionales Decision-Modell
- **Stufe B: Lastverteilung** innerhalb der gewählten Modellgruppe

Routing-Kategorien sind im Admin-Bereich vollständig konfigurierbar
(`routing_categories`, `routing_rules`).

### 3) Lastverteilung über Endpunkt-Pools

- Endpunkte mit gleichem `default_model` bilden eine Gruppe
- Verteilung nach aktueller Last + Fairness (bevorzugt länger ungenutzte Endpunkte)
- Task-Erfassung in `tasks`
- Kapazitätslimit pro Endpunkt (4 parallele Tasks)

### 4) Dokument-RAG und Vision-Analyse

- Upload von PNG/JPG/WEBP/GIF/PDF
- PDF-Text-Extraktion über `pdftotext`
- Chunking in `document_chunks`
- optional globale Freigabe (`is_global_rag`) für teamübergreifenden Kontext
- Bilddateien werden optional über ein Vision-Modell analysiert

### 5) Bildgenerierung

- AUTOMATIC1111 (`generate_image`)
- ComfyUI (`generate_image_comfy`)
- Endpunkte separat konfigurierbar, inkl. Lastverteilung

### 6) Benutzerverwaltung & Enterprise-Auth

- lokale Benutzerkonten
- Selbstregistrierung mit E-Mail-Verifikation
- Token-basiertes Passwort-Reset
- LDAP/Active-Directory-Login
- optionales Windows-SSO via Kerberos/`REMOTE_USER`

### 7) Monitoring und Betriebsdaten

- Live-Statistiken zu Endpunktlast, Tokenverbrauch, Jobs, aktiven Clients
- optionales SSH-basiertes Host-Monitoring (RAM/CPU/Temperatur)
- konfigurierbares Anwendungs-Logging (`app_logs`)

---

## Architektur

### Laufzeitebenen

1. **Frontend** (`index.php`, `login.php`, `register.php`)
2. **API-Schicht** (`api/`)
3. **Admin-Schicht** (`admin/`)
4. **Persistenz** (MySQL/MariaDB)

### Zentrale Komponenten

- `api/chat.php`: Chat-Proxy, Tool-Aufrufe, Routing-Dispatch
- `api/balancer.php`: LLM-Endpunktauswahl + Task-Lifecycle
- `api/upload_document.php`: Upload, Extraktion, Chunking, Vision-Verarbeitung
- `db.php`: DB-Verbindung, Runtime-Schema, Settings-, Routing- und Logging-Helfer
- `setup.php`: idempotente Erstinitialisierung

---

## Voraussetzungen

### Pflicht

| Komponente | Empfehlung |
|---|---|
| PHP | >= 8.0 (empfohlen 8.2) mit `curl`, `pdo_mysql`, `mbstring`, `fileinfo` |
| Datenbank | MySQL/MariaDB (utf8mb4) |
| LLM-Endpunkte | OpenAI-/LM-Studio-kompatible Chat-API |

### Optional

| Komponente | Zweck |
|---|---|
| SearXNG | Websuche (`search_web`) |
| AUTOMATIC1111 | Text-zu-Bild (`generate_image`) |
| ComfyUI | alternative Bildpipeline (`generate_image_comfy`) |
| LDAP/AD | zentrale Authentifizierung |
| Kerberos/GSSAPI | transparentes Windows-SSO |
| `pdftotext` (Poppler) | PDF-Extraktion für Dokument-RAG |
| PHP `ssh2` + `lm-sensors` auf Hosts | Host-Monitoring via SSH |

---

## Installation (klassisch)

### 1. Repository klonen

```bash
git clone https://github.com/dareinelt/LLMInt.git
cd LLMInt
```

### 2. Datenbank erstellen

```sql
CREATE DATABASE llmint CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Umgebungsvariablen setzen

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

Standard-Login nach Erststart:

- Benutzer: `admin`
- Passwort: `admin`

> Danach Passwort sofort ändern und `setup.php` absichern/entfernen.

---

## Installation mit Docker

Docker ist der empfohlene Weg für reproduzierbare Deployments.

### 1. `.env` anlegen

```bash
cp .env.example .env
```

Wichtige Werte in `.env`:

```dotenv
DB_NAME=llmint
DB_USER=llmint
DB_PASS=llmint
DB_ROOT_PASS=changeme
HTTP_PORT=8080
TZ=Europe/Berlin
```

### 2. Container starten

```bash
docker compose up -d --build
```

Der Entrypoint (`docker/entrypoint.sh`) wartet auf die DB und führt automatisch `setup.php` aus.

### 3. Aufruf

- Chat: <http://localhost:8080>
- Admin: <http://localhost:8080/admin/login.php>

### Persistente Volumes

- `db_data` → Datenbank
- `doc_uploads` → Dokumente
- `sd_output` → generierte Bilder

### Häufige Docker-Befehle

```bash
docker compose logs -f web
docker compose restart web
docker compose down
docker compose down -v   # löscht Volumes (Datenverlust)
```

---

## Erstkonfiguration im Admin-Bereich

Empfohlene Reihenfolge:

1. Admin-Passwort ändern
2. LLM-Endpunkte anlegen (`alias`, `base_url`, `timeout`, `default_model`)
3. globales Standardmodell (`default_model`) setzen
4. optional Routing-Decision-Modell konfigurieren
5. optional Vision-Modell setzen
6. optional SearXNG/SMTP/LDAP konfigurieren
7. optional SD- und Comfy-Endpunkte hinzufügen
8. Verbindungstests (Modelle, LDAP, SMTP) durchführen

---

## Authentifizierung und Benutzerverwaltung

### Lokale Benutzer

- Login über `login.php` bzw. `admin/login.php`
- Passwort-Policy:
  - min. 8 Zeichen
  - Groß-/Kleinbuchstaben
  - Ziffer
  - Sonderzeichen

### Registrierung und Verifikation

- `register.php` erzeugt Benutzer + E-Mail-Token
- Verifikationslink über `api/verify_email.php?token=...`
- SMTP-Konfiguration nötig für Mailversand

### Passwort-Reset

- Admin kann Reset-Mail auslösen (`api/admin_user_action.php`, `send_password_reset`)
- Benutzer setzt Passwort über `api/reset_password.php?token=...`

### LDAP / Active Directory

- AD-Authentifizierung bei aktiviertem LDAP
- Just-in-Time-Provisionierung lokaler Benutzer (`auth_source = ldap`)
- Konfliktschutz: bestehende lokale Kontonamen werden nicht überschrieben

### Windows-SSO (optional)

- über Kerberos/GSSAPI (`REMOTE_USER`)
- in Docker per Keytab/`krb5.conf` + Apache-GSSAPI-Konfiguration

---

## Routing und Lastverteilung im Detail

### 1) Semantisches Routing (optional)

Wenn `routing_decision_model` gesetzt ist:

1. letzte Nutzernachricht wird klassifiziert,
2. Prompt wird aus `routing_categories` gebaut,
3. Kategoriename wird gegen `routing_rules` gemappt,
4. bei Treffer wird Zielmodell gesetzt.

Fail-safe: wenn Klassifikation fehlschlägt oder ausgelastet ist, läuft Anfrage mit ursprünglichem Modell weiter.

### 2) Endpunkt-Selektion

`api/balancer.php` wählt aus aktiven Endpunkten der Modellgruppe:

- nur `is_active = 1`
- max. 4 parallele Tasks je Endpunkt
- geringste aktuelle Last bevorzugt
- bei Gleichstand Fairness über letzte Nutzung

### 3) Intelligence-Upgrade-Hinweis

Nach Antworten kann optional ein Upgrade-Hinweis als SSE-Event gesendet werden,
wenn ein stärkeres freies Modell erkannt wurde (Muster `<zahl>b`, z. B. `8b`, `70b`).

---

## Tooling im Chat

### `search_web` (SearXNG)

- wird aktiviert, wenn `searxng_base_url` gesetzt ist
- Requests werden in `search_logs` protokolliert

### `query_documents` (RAG)

- sucht in eigenen und global freigegebenen Dokument-Chunks
- geeignet für internes Wissensmanagement ohne externe Vektor-DB

### `generate_image` (AUTOMATIC1111)

- Tool-Aufruf via Chat
- Bilddateien landen in `sd_output/`

### `generate_image_comfy` (ComfyUI)

- alternative Bildgenerierung mit ComfyUI-Backend
- optional mit Default-Checkpoint

---

## Monitoring und Logging

### Live-Statistiken

`admin/load_stats.php` liefert u. a.:

- LLM-Endpunktlast (running/jobs/tokens)
- SD- und Comfy-Last
- SearXNG-Statistiken
- aktive Clients und Tageswerte (min/max/avg)

### Client-Präsenz

`api/heartbeat.php` aktualisiert tab-basierte Heartbeats in `active_clients`.

### SSH-Systemmetriken (optional)

`admin/refresh_sys_stats.php` pollt über SSH:

- RAM gesamt/benutzt
- CPU-Load (1m/5m)
- CPU-Temperatur

Ergebnisse werden in `endpoint_sys_stats` zwischengespeichert.

### App-Logs

- Tabelle `app_logs`
- Level: `info`, `warning`, `error`
- Aufbewahrung über `log_retention_days`

---

## Projektstruktur

```text
.
├── README.md
├── Demo.md
├── index.php
├── login.php
├── register.php
├── logout.php
├── setup.php
├── db.php
├── config.php
├── api/
│   ├── chat.php
│   ├── balancer.php
│   ├── chat_sessions.php
│   ├── upload_document.php
│   ├── document_status.php
│   ├── models.php
│   ├── heartbeat.php
│   ├── admin_user_action.php
│   ├── test_ldap.php
│   ├── test_smtp.php
│   ├── test_searxng.php
│   ├── reset_password.php
│   ├── verify_email.php
│   ├── sd_*.php
│   └── comfy_*.php
├── admin/
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   ├── load_stats.php
│   └── refresh_sys_stats.php
├── lib/
│   ├── ldap_auth.php
│   ├── mailer.php
│   └── prompt.txt
├── docker/
│   ├── apache.conf
│   ├── php.ini
│   └── entrypoint.sh
├── doc_uploads/
└── sd_output/
```

---

## API-Endpunkte

### Chat / Modell

| Pfad | Methode | Zweck |
|---|---|---|
| `api/chat.php` | POST | Chat-Request inkl. Routing/Tools |
| `api/models.php` | GET | Modelle eines Endpunkts abrufen |
| `api/chat_sessions.php?action=list` | GET | Sitzungen des aktuellen Nutzers |
| `api/chat_sessions.php?action=load` | GET | konkrete Sitzung laden |
| `api/chat_sessions.php?action=delete` | POST/GET | Sitzung löschen |

### Dokumente / RAG

| Pfad | Methode | Zweck |
|---|---|---|
| `api/upload_document.php` | POST (multipart) | Dokument hochladen + verarbeiten |
| `api/document_status.php` | GET | Upload-Statusliste des Nutzers |

### Integrationen / Tests

| Pfad | Methode | Zweck |
|---|---|---|
| `api/test_searxng.php` | GET | SearXNG-Verbindung prüfen |
| `api/test_ldap.php` | POST (JSON) | LDAP-Verbindung testen |
| `api/test_smtp.php` | POST/GET | SMTP-Testmail senden |
| `api/heartbeat.php` | POST (JSON) | Client-Heartbeat |

### Auth / Benutzer

| Pfad | Methode | Zweck |
|---|---|---|
| `api/verify_email.php` | GET | E-Mail-Verifikation |
| `api/reset_password.php` | GET/POST | Passwort per Token zurücksetzen |
| `api/admin_user_action.php` | POST/JSON | Admin-Useraktionen (anlegen, Reset, Modell, Upload-Recht) |

### Bildgenerierung

| Pfad | Methode | Zweck |
|---|---|---|
| `api/sd_generate.php` | POST | AUTOMATIC1111-Generierung |
| `api/sd_checkpoints.php` | GET | SD-Checkpoints |
| `api/comfy_generate.php` | POST | ComfyUI-Generierung |
| `api/comfy_checkpoints.php` | GET | ComfyUI-Checkpoints |

---

## Datenbankschema

| Tabelle | Zweck |
|---|---|
| `settings` | zentrale Konfigurationswerte |
| `users` | lokale/LDAP-Benutzer, Berechtigungen, Reset-/Verifikationsfelder |
| `endpoints` | LLM-Endpunkte inkl. Spezialisierung, Tool-Calling-Flag, SSH-Daten |
| `tasks` | LLM-Task-Lifecycle inkl. Tokenmetriken |
| `routing_categories` | Klassifikationskategorien und Regeln |
| `routing_rules` | Kategorie → Zielmodell |
| `conversation_sessions` | persistente Chat-Sitzungen |
| `document_uploads` | Metadaten und Status zu Uploads |
| `document_chunks` | Text-Chunks für RAG |
| `search_logs` | Websuch-Logs |
| `sd_endpoints`, `sd_tasks` | AUTOMATIC1111-Endpunkte/Tasks |
| `comfy_endpoints`, `comfy_tasks` | ComfyUI-Endpunkte/Tasks |
| `active_clients` | Heartbeat-aktive Browser-Tabs |
| `client_count_log` | zeitliche Client-Count-Samples |
| `endpoint_sys_stats` | gecachte SSH-Systemmetriken |
| `app_logs` | Anwendungs-Logs |

---

## Konfigurationswerte (`settings`)

Wichtige Keys im Überblick:

- Routing/Modelle:
  - `default_model`
  - `new_user_default_model`
  - `vision_model`
  - `routing_decision_model`
  - `intelligence_upgrade_message`
- Integrationen:
  - `searxng_base_url`
- UI/Systemtexte:
  - `login_banner_enabled`
  - `login_banner_text`
- SMTP:
  - `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_auth`
  - `smtp_user`, `smtp_pass`, `smtp_from_email`, `smtp_from_name`
- LDAP/AD:
  - `ldap_enabled`, `ldap_host`, `ldap_port`, `ldap_use_ssl`
  - `ldap_domain`, `ldap_base_dn`, `ldap_bind_dn`, `ldap_bind_password`
  - `ldap_user_attr`, `ldap_email_attr`, `ldap_display_name_attr`
  - `ldap_sspi_enabled`
- Logging:
  - `log_level`
  - `log_retention_days`

---

## Sicherheitsempfehlungen

- `setup.php` nach erfolgreichem Setup absichern/entfernen
- Standardpasswort `admin/admin` sofort ändern
- Admin-Bereich nicht öffentlich exponieren (VPN/Zero-Trust/Allowlist)
- starke DB- und SMTP-Passwörter verwenden
- Keytab/`krb5.conf` niemals committen
- Schreibrechte auf `doc_uploads/` und `sd_output/` restriktiv halten
- nur vertrauenswürdige interne Modell- und Tool-Endpunkte anbinden

---

## Betrieb und Wartung

- Endpunktgruppen (`default_model`) konsistent halten
- Routing-Kategorien bei Modellwechseln anpassen
- Speicherauslastung von `doc_uploads/` und `sd_output/` regelmäßig prüfen
- Log-Retention (`log_retention_days`) an Compliance-Anforderungen anpassen
- LDAP-/SMTP-Verbindungen nach Infrastrukturänderungen testen
- bei Lastspitzen zusätzliche Endpunkte derselben Modellgruppe ergänzen

---

## Troubleshooting

### „Datenbankfehler. Bitte zuerst setup.php ausführen.“

- DB-Umgebungsvariablen prüfen
- DB-Rechte prüfen
- `php setup.php` erneut starten

### Keine Modelle abrufbar

- Endpunkt-URL inkl. `/v1` prüfen
- Netzwerkpfad zwischen Webserver und Endpunkt testen
- Timeout erhöhen

### Routing greift nicht

- `routing_decision_model` muss exakt einem aktiven `default_model` entsprechen
- `routing_rules` für die Kategorie prüfen
- Decision-Modell-Kapazität prüfen (Auslastung)

### Dokument-Upload schlägt fehl

- Nutzerrecht `can_upload_documents` prüfen
- erlaubte Dateitypen/Dateigröße prüfen
- Vision-Modell setzen (für Bildanalyse)
- bei PDFs: `pdftotext`-Verfügbarkeit prüfen

### LDAP-Login funktioniert nicht

- `ldap_enabled`, Host/Port/SSL korrekt setzen
- Bind-DN/Bind-Passwort und Base-DN prüfen
- Verbindung über `api/test_ldap.php` testen

### SMTP-Versand schlägt fehl

- `smtp_from_email` gesetzt?
- Auth/Port/Encryption korrekt?
- Versandtest über `api/test_smtp.php`

### SSH-Systemmetriken fehlen

- PHP-Extension `ssh2` installiert?
- SSH-Credentials je Endpunkt gepflegt?
- `lm-sensors` auf Zielhost verfügbar?

---

## Lizenz

MIT
