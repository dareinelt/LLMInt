# LLMInt / KHWF KI

LLMInt ist eine leichtgewichtige PHP-Webanwendung für lokale oder interne KI-Infrastrukturen.  
Sie bündelt Chat, Tool-Aufrufe, intelligentes Modell-Routing, Endpunkt-Verwaltung und Monitoring in einer Oberfläche – ohne Framework-Ballast und ohne Build-Pipeline.

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
  - [Modell-Routing im Detail](#modell-routing-im-detail)
  - [Lastverteilung (LLM)](#lastverteilung-llm)
  - [Task-Tracking](#task-tracking)
  - [Konversationsspeicher](#konversationsspeicher)
  - [Antwort-Metadaten](#antwort-metadaten)
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

### 2) Zweistufiges Modell-Routing

LLMInt kombiniert zwei Routing-Mechanismen, die nacheinander greifen:

**Stufe 1 – Semantisches Kategorie-Routing (optional)**  
Ein dediziertes „Decision-Modell" klassifiziert die Nutzeranfrage in eine Kategorie (z. B. `Programming`, `Math`, `ImageAnalysis`). Jeder Kategorie ist ein Zielmodell zugewiesen. Die Kategorien, ihre Definitionen und Entscheidungsregeln werden vollständig in der Datenbank verwaltet und sind im Admin-Bereich editierbar.

**Stufe 2 – Lastverteilung im Endpunkt-Pool**  
Innerhalb der durch Stufe 1 (oder direkt vom Nutzer) gewählten Modellgruppe wählt der Balancer den Endpunkt mit der geringsten aktuellen Last, bevorzugt dabei länger ungenutzte Endpunkte und hält maximal 4 parallele Tasks je Endpunkt ein.

### 3) Intelligente Modell-Empfehlung

- Optionales `intelligence_upgrade`-Signal nach Antworten
- Hinweis auf stärkeres freies Modell (z. B. 8b → 70b), erkannt anhand des `<Zahl>b`-Musters im Modellnamen
- Kein automatischer Modellwechsel ohne Nutzereinwilligung

### 4) Erweiterbare Tool-Nutzung im Chat

- `search_web` über SearXNG (optional)
- `generate_image` über AUTOMATIC1111 (optional)
- `generate_image_comfy` über ComfyUI (optional)
- `query_documents` für RAG über eigene + global freigegebene Dokumente

### 5) Admin-Oberfläche für Betrieb und Kontrolle

- Login-geschützter Admin-Bereich
- Endpunktverwaltung inkl. Alias, Aktivstatus, Timeout, Sortierung
- Routing-Kategorien und Zielmodelle vollständig im Admin konfigurierbar
- Modellabruf je Endpunkt, Verbindungstests und Laststatistiken
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
4. Modell-Routing konfigurieren (optional, aber empfohlen)
5. Optional SearXNG hinterlegen
6. Optional SD-/Comfy-Endpunkte hinterlegen
7. Modell- und Verbindungstests durchführen

### LLM-Endpunkte

Pflicht-/Option-Felder:

- Base URL (z. B. `http://127.0.0.1:1234/v1`)
- Alias (optional, für lesbare Ausgabe in `processed_by`)
- Timeout (Sekunden)
- `default_model` (relevant für Routing-Gruppe; muss mit dem Modellnamen übereinstimmen, den die API zurückliefert)
- Aktivstatus und Sortierung

> Tipp: Mehrere Endpunkte mit identischem `default_model`-Wert bilden eine Lastverteilungsgruppe. LLMInt wählt automatisch den am wenigsten belasteten Endpunkt.

### Modell-Routing einrichten

Die Routing-Konfiguration befindet sich in der Admin-Karte **„Routing-Entscheidung"**.

**Schritt 1 – Decision-Modell wählen**  
Trage unter „Routing-Entscheidungsmodell" den `default_model`-Wert eines vorhandenen aktiven Endpunkts ein. Dieses Modell wird ausschließlich zur Klassifikation von Nutzeranfragen verwendet.  
Empfehlung: ein schnelles, kleines Modell (z. B. 3b–8b), das im selben Endpunkt-Pool wie normale Anfragen läuft.

**Schritt 2 – Kategorien prüfen und anpassen**  
Die Tabelle zeigt die vorhandenen Routing-Kategorien. Standardmäßig werden `Programming`, `Math`, `Research`, `ImageAnalysis` und `GeneralConversation` angelegt.  
Jede Kategorie kann bearbeitet werden:
- **Name:** eindeutiger Schlüssel, der vom Decision-Modell zurückgegeben wird
- **Definition:** beschreibt, welche Anfragen in diese Kategorie fallen (fließt in den System-Prompt ein)
- **Entscheidungsregel:** konkrete Wenn-Dann-Formulierung für den Routing-Prompt
- **Anzeigereihenfolge (`sort_order`):** Reihenfolge der Kategorie im Prompt
- **Priorität (`decision_priority`):** Reihenfolge der Entscheidungsregeln (1 = höchste Priorität)

**Schritt 3 – Zielmodelle zuweisen**  
In der Spalte „Zielmodell" jeder Kategorie kann der `default_model`-Wert des gewünschten Endpunkts hinterlegt werden.  
Bleibt das Feld leer, wird kein Modellwechsel für diese Kategorie vorgenommen.

**Schritt 4 – Routing-Prompt prüfen**  
Am Ende der Routing-Karte zeigt eine Vorschau den vollständigen System-Prompt, der an das Decision-Modell gesendet wird. So ist der tatsächliche Prompt jederzeit transparent.

**Routing deaktivieren**  
Das Routing-Decision-Modell-Feld leeren und speichern – damit ist Stufe 1 vollständig deaktiviert.

### Bild-Endpunkte

- AUTOMATIC1111: Base URL + Timeout
- ComfyUI: Base URL + Timeout + optionaler Default-Checkpoint

---

## Funktionsweise im Betrieb

### Modell-Routing im Detail

LLMInt implementiert ein zweistufiges Routing-System, das semantische Klassifikation und Lastverteilung kombiniert.

#### Stufe 1 – Semantisches Kategorie-Routing

Wenn in den Admin-Einstellungen ein **Routing-Decision-Modell** (`routing_decision_model`) konfiguriert ist, klassifiziert LLMInt jede eingehende Nutzeranfrage automatisch, bevor sie an das eigentliche Antwortmodell weitergeleitet wird.

**Ablauf:**

1. Die letzte Nutzernachricht wird extrahiert.
2. `buildRoutingPrompt()` (in `db.php`) assembliert einen deterministischen System-Prompt aus den in der Datenbank hinterlegten Routing-Kategorien.
3. Das Decision-Modell wird über den Balancer gebucht (mit reserviertem Slot, damit normale Nutzerlast nicht verdrängt wird) und erhält die Anfrage mit:
   - `temperature: 0.0` (deterministisches Ergebnis)
   - `max_tokens: 20` (nur Kategoriename als Ausgabe)
   - `stream: false`
4. Die zurückgegebene Kategorie wird gegen die Tabelle `routing_rules` geprüft. Ist dort ein Zielmodell hinterlegt, ersetzt es das ursprünglich angefragte Modell (`$payload['model']`).
5. Schlägt die Klassifikation fehl (Netzwerkfehler, Timeout, kein freier Slot), fährt LLMInt ohne Routing fort – das ursprünglich angeforderte Modell bleibt erhalten.

**Routing-Kategorien (`routing_categories`-Tabelle):**

Jede Kategorie besteht aus:

| Feld | Bedeutung |
|---|---|
| `name` | Eindeutiger Bezeichner (z. B. `Programming`) |
| `definition` | Beschreibung, was zu dieser Kategorie gehört |
| `decision_rule` | Entscheidungsregel im System-Prompt (z. B. „Else if …, return Programming.") |
| `sort_order` | Reihenfolge der Kategorie in der Anzeige und im Prompt |
| `decision_priority` | Priorität der Entscheidungsregel (niedrigste Zahl = höchste Priorität) |

Standard-Kategorien nach der Erstinstallation:

| Kategorie | Priorität | Beschreibung |
|---|---|---|
| `ImageAnalysis` | 1 | Anfragen mit Bildeingabe |
| `Programming` | 2 | Code, Debugging, APIs, Algorithmen |
| `Math` | 3 | Berechnungen, Gleichungen, Statistik |
| `Research` | 4 | Faktensuche, Vergleiche, Zusammenfassungen |
| `GeneralConversation` | 5 | Alles Übrige |

Kategorien, Definitionen und Regeln können im Admin-Bereich (Karte „Routing-Entscheidung") vollständig angepasst, ergänzt oder gelöscht werden. Der resultierende Routing-Prompt ist dort live vorschaubar.

**Kategorie–Modell-Zuordnung (`routing_rules`-Tabelle):**

Die Tabelle `routing_rules` speichert die Zuordnung `Kategorie → Modell`.  
Ist für eine erkannte Kategorie kein Eintrag vorhanden, bleibt das vom Nutzer gewählte Modell unverändert.  
Ist der Wert leer, wird ebenfalls kein Routing-Wechsel vorgenommen.

**Beispielkonfiguration:**

```
Decision-Modell:   llama-3.1-8b-instruct
Routing-Regeln:
  Programming      → deepseek-coder-33b-instruct
  Math             → qwen2.5-math-72b
  ImageAnalysis    → llava-13b
  Research         → llama-3.1-70b-instruct
  GeneralConversation → (leer – kein Wechsel)
```

In diesem Setup beantwortet das 8b-Modell ausschließlich die schnelle Routing-Klassifikation; Programmierfragen landen auf dem Coder-Modell, Mathematikfragen auf dem Math-Modell usw.

---

#### Stufe 2 – Lastverteilung im Endpunkt-Pool

Nach der Modellwahl (durch Routing oder direkt vom Nutzer) wählt `api/balancer.php` den besten verfügbaren Endpunkt aus allen aktiven Einträgen in `endpoints`, die denselben `default_model`-Wert tragen.

**Auswahlreihenfolge (innerhalb einer DB-Transaktion):**

1. Nur aktive Endpunkte (`is_active = 1`) mit passendem `default_model`.
2. Nur Endpunkte mit weniger als 4 laufenden Tasks.
3. Bevorzugt den Endpunkt mit der geringsten aktuellen Last.
4. Bei Gleichstand: derjenige, der zuletzt eine Aufgabe erhalten hat, wird benachteiligt (Round-Robin-Effekt). Endpunkte, die noch nie genutzt wurden, haben Vorrang.

Die Task-Buchung erfolgt atomar in einer Datenbanktransaktion, um Doppelbuchungen unter gleichzeitigen Requests zu verhindern.  
Wenn kein Endpunkt mit freier Kapazität gefunden wird, gibt `pickEndpointForModel()` `null` zurück und der Request erhält eine entsprechende Fehlerantwort.

---

#### Intelligence-Upgrade-Hinweis

Nach jeder Antwort prüft LLMInt, ob ein leistungsfähigeres Modell mit freiem Kapazitätsslot verfügbar ist.

- Die Erkennung basiert auf dem `<Zahl>b`-Muster im `default_model`-Feld (z. B. `8b`, `70b`).
- Als Upgrade gilt ein Modell mit einer höheren Parameterzahl, das mindestens einen freien Slot hat.
- Falls mehrere Kandidaten existieren, wird das kleinste verfügbare Upgrade bevorzugt.
- Der Hinweis wird als `intelligence_upgrade`-SSE-Ereignis gesendet; der Nutzer entscheidet selbst, ob er wechselt.
- Enthält der Modellname kein `<Zahl>b`-Muster, wird kein Upgrade-Signal erzeugt.

---

### Lastverteilung (LLM)

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
├── db.php                    # DB-Helper, Runtime-Schema, Sessions, Routing-Logik
├── config.php                # Legacy-Basis-Konfiguration
├── lib/
│   └── prompt.txt            # Legacy-Fallback für Routing-Prompt (wird durch DB ersetzt)
├── admin/
│   ├── login.php             # Admin-Login
│   ├── logout.php            # Admin-Logout
│   ├── index.php             # Admin-Dashboard inkl. Routing-Konfiguration
│   └── load_stats.php        # Live-Statistiken (JSON)
├── api/
│   ├── chat.php              # Chat-Proxy + Tool-Logik + Routing-Dispatch
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
| `settings` | globale Konfiguration (u. a. `default_model`, `searxng_base_url`, `routing_decision_model`) |
| `users` | Admin-Accounts |
| `endpoints` | LLM-Endpunkte inkl. Alias, Modell, Timeout, Aktivstatus |
| `tasks` | LLM-Task-Lebenszyklus und Nutzungswerte |
| `routing_categories` | Routing-Kategorien (Name, Definition, Entscheidungsregel, Reihenfolge, Priorität) |
| `routing_rules` | Zuordnung Kategorie → Zielmodell |
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
- Bei Modelländerungen `default_model`-Gruppen konsistent halten – auch in `routing_rules`-Zuordnungen prüfen
- Bei Lastspitzen zusätzliche Endpunkte pro Modellgruppe ergänzen; für das Decision-Modell gelten dieselben Kapazitätsregeln
- Routing-Kategorien und -Regeln bei Änderungen am Modellportfolio anpassen
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

### Routing funktioniert nicht oder Kategorien werden ignoriert

- Prüfen, ob das `routing_decision_model` exakt mit einem `default_model`-Wert eines aktiven Endpunkts übereinstimmt (Groß-/Kleinschreibung beachten).
- Sicherstellen, dass für die erkannte Kategorie ein Eintrag in `routing_rules` mit einem nicht-leeren Modellnamen vorliegt.
- Den Routing-Prompt in der Admin-Karte auf korrekte Kategorie-Bezeichnungen prüfen.
- Im Admin-Bereich testen: Live-Laststatistik zeigt, ob das Decision-Modell Tasks registriert.
- Wenn das Decision-Modell ausgelastet ist (4 laufende Tasks), wird das Routing übersprungen und das ursprünglich gewählte Modell verwendet – mehr Kapazität durch zusätzliche Endpunkte für das Decision-Modell schaffen.
- Routing-Modell-Feld leeren und speichern deaktiviert das Feature vollständig.

### Routing-Prompt gibt falsche Kategorie zurück

- Kategorie-Definitionen in der Admin-Karte präzisieren.
- `decision_priority` anpassen: niedrigere Werte haben höhere Priorität und werden zuerst geprüft.
- Ein größeres/besseres Modell als Decision-Modell wählen, wenn Klassifikationen unzuverlässig sind.
- Darauf achten, dass `decision_rule`-Formulierungen eindeutig und nicht überlappend sind.

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
- Modellnamen müssen ein erkennbares `<Zahl>b`-Muster enthalten (z. B. `8b`, `70b`)
- Das Upgrade-Modell muss mindestens einen freien Kapazitätsslot haben

---

## Lizenz

MIT
