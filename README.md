# LLMInt / KHWF KI

Eine PHP-Webanwendung für lokale oder interne KI-Infrastrukturen.  
Die Anwendung kombiniert:

- einen Chat-Proxy für **LM Studio**-kompatible LLM-Endpunkte,
- eine Admin-Oberfläche für **mehrere Endpunkte mit Load-Balancing**,
- optionale **Websuche über SearXNG**,
- optionale **Bildgenerierung über AUTOMATIC1111**,
- optionale **Bildgenerierung über ComfyUI**,
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

- Chat-Oberfläche mit Streaming-Antworten per SSE
- optionaler System-Prompt pro Unterhaltung
- Markdown-Rendering für Assistentenantworten
- zentrale Auswahl eines Standardmodells über den Admin-Bereich
- Weiterleitung von Chat-Anfragen an den jeweils passendsten LLM-Endpunkt
- Erfassung von Tasks und Token-Nutzung für Statistikzwecke

### Administration

- Login-geschützter Admin-Bereich
- Verwaltung mehrerer LM-Studio-kompatibler Endpunkte
- Aktivieren/Deaktivieren einzelner Endpunkte
- Laden verfügbarer Modelle direkt vom konfigurierten Endpunkt
- Verwaltung von AUTOMATIC1111- und ComfyUI-Endpunkten
- Testen externer Verbindungen aus der Oberfläche heraus
- Passwortänderung für den Admin-Benutzer
- Live-Ansicht der Lastverteilung

### Erweiterungen

- optionales Tool `search_web` über SearXNG
- optionales Tool `generate_image` für AUTOMATIC1111
- optionales Tool `generate_image_comfy` für ComfyUI

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

Projekt in ein Webverzeichnis oder lokales Arbeitsverzeichnis legen.

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
php /home/runner/work/LLMInt/LLMInt/setup.php
```

Das Setup:

- erstellt die benötigten Tabellen,
- initialisiert Standardwerte,
- legt bei Bedarf einen ersten LLM-Endpunkt an,
- und erzeugt einen Standard-Admin-Benutzer.

**Standard-Zugang nach dem ersten Setup:**

- Benutzername: `admin`
- Passwort: `admin`

> Dieses Passwort sofort nach dem ersten Login ändern.

---

## Starten der Anwendung

Für lokale Entwicklung reicht der eingebaute PHP-Server:

```bash
cd /home/runner/work/LLMInt/LLMInt
php -S localhost:8080
```

Danach:

- Chat: <http://localhost:8080>
- Admin: <http://localhost:8080/admin/login.php>

Für produktive Nutzung sollte die Anwendung hinter einem regulären Webserver oder Reverse Proxy betrieben werden.

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

- Base URL, z. B. `http://127.0.0.1:1234/v1`
- Timeout
- Standardmodell
- Aktiv/Inaktiv-Status

Über **„Modelle laden“** kann die Anwendung die verfügbaren Modelle eines Endpunkts direkt abrufen.

### Bildendpunkte

Zusätzlich können verwaltet werden:

- **AUTOMATIC1111**-Instanzen mit Base URL und Timeout
- **ComfyUI**-Instanzen mit Base URL, Timeout und optionalem Default-Checkpoint

---

## Load-Balancing und Routing

### LLM-Endpunkte

LLM-Endpunkte werden in der Tabelle `endpoints` gespeichert.

- Endpunkte mit demselben `default_model` bilden eine **Gruppe**
- pro Gruppe wird der **am wenigsten belastete Endpunkt** gewählt
- pro Endpunkt sind maximal **4 parallele Tasks** vorgesehen
- sind alle 4 Slots aller passenden Endpunkte belegt, wartet der Chat-Request automatisch und blendet im Chat einen Hinweis ein, bis wieder Kapazität frei wird
- jeder Chat-Request wird in `tasks` protokolliert
- Token-Werte aus Antworten werden gespeichert und für Statistiken genutzt

Das Routing erfolgt über:

- `api/balancer.php`
- `api/chat.php`

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

## Optionale Integrationen

### SearXNG

Wenn im Admin-Bereich eine SearXNG-Basis-URL hinterlegt ist:

- stellt `api/chat.php` dem Modell automatisch das Tool `search_web` bereit,
- Suchanfragen werden über SearXNG ausgeführt,
- und Suchläufe werden in `search_logs` protokolliert.

Wichtig:

- nur die **Basis-URL** eintragen, nicht `/search`
- der Pfad wird intern ergänzt

Beispiel:

```text
https://search.example.org
```

### AUTOMATIC1111

Wenn mindestens ein aktiver SD-Endpunkt konfiguriert ist, kann das Chat-System das Tool `generate_image` bereitstellen.

Die eigentliche Bildgenerierung läuft über:

- `api/sd_generate.php`
- API-Pfade `/sdapi/v1/txt2img` und `/sdapi/v1/img2img`

Gespeicherte Bilder landen unter `sd_output/`.

### ComfyUI

Wenn mindestens ein aktiver ComfyUI-Endpunkt konfiguriert ist, kann das Chat-System das Tool `generate_image_comfy` bereitstellen.

Die Anwendung verwendet standardmäßig einen einfachen txt2img-Workflow mit KSampler. Falls kein Checkpoint hinterlegt ist, versucht die Anwendung automatisch den ersten verfügbaren Checkpoint des Servers zu verwenden.

---

## Projektstruktur

```text
/home/runner/work/LLMInt/LLMInt
├── README.md
├── index.php                 # Chat-UI
├── setup.php                 # Initiales Setup für DB und Admin
├── db.php                    # DB-Verbindung, Settings-Helper, Runtime-Schema
├── config.php                # Legacy-Kompatibilität für Basis-Konfiguration
├── admin/
│   ├── login.php             # Admin-Login
│   ├── logout.php            # Admin-Logout
│   ├── index.php             # Admin-Dashboard
│   └── load_stats.php        # Live-Statistiken als JSON
├── api/
│   ├── balancer.php          # LLM-Load-Balancing
│   ├── chat.php              # Chat-Proxy inkl. Tool-Ausführung
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
| `settings` | allgemeine Konfiguration, z. B. `searxng_base_url` |
| `users` | Admin-Benutzer |
| `endpoints` | LLM-Endpunkte |
| `tasks` | LLM-Aufgaben inkl. Status und Tokenverbrauch |
| `search_logs` | Websuchen über SearXNG |
| `sd_endpoints` | AUTOMATIC1111-Endpunkte |
| `sd_tasks` | Bildgenerierungsaufgaben für AUTOMATIC1111 |
| `comfy_endpoints` | ComfyUI-Endpunkte |
| `comfy_tasks` | Bildgenerierungsaufgaben für ComfyUI |

---

## Sicherheitshinweise

- `setup.php` nur für die Ersteinrichtung verwenden und danach schützen oder entfernen
- das Standardpasswort `admin / admin` niemals im Betrieb beibehalten
- den Admin-Bereich nicht ungeschützt öffentlich exponieren
- Zugriffe möglichst über Reverse Proxy, VPN oder internes Netzwerk begrenzen
- nur vertrauenswürdige interne KI-Endpunkte anbinden
- prüfen, ob Schreibrechte für `sd_output/` korrekt gesetzt sind

---

## Betriebshinweise

- Die Anwendung speichert generierte Bilder lokal unter `sd_output/`
- Der Chat verwendet das im Admin gesetzte Standardmodell
- Fällt ein Endpunkt aus oder ist ausgelastet, greift das Routing auf andere passende aktive Endpunkte zurück
- `config.php` dient primär der Rückwärtskompatibilität älterer Einbindungen
- Das Runtime-Schema in `db.php` hilft älteren Installationen, neue Kern-Tabellen automatisch nachzuziehen

---

## Troubleshooting

### „Datenbankfehler. Bitte zuerst setup.php ausführen.“

- prüfen, ob die DB-Umgebungsvariablen korrekt gesetzt sind
- prüfen, ob die Datenbank existiert
- `php /home/runner/work/LLMInt/LLMInt/setup.php` erneut ausführen

### Keine Modelle im Admin ladbar

- prüfen, ob die LLM-Base-URL korrekt ist
- prüfen, ob der Endpunkt `/models` bereitstellt
- prüfen, ob das Zielsystem vom Webserver aus erreichbar ist

### Chat meldet „Kein Standardmodell konfiguriert“

- im Admin-Bereich für mindestens einen aktiven LLM-Endpunkt ein `default_model` setzen

### SearXNG funktioniert nicht

- nur die Basis-URL speichern, nicht `/search`
- Verbindung über den Test-Button im Admin prüfen
- sicherstellen, dass die Instanz JSON-Antworten liefert

### Bildgenerierung funktioniert nicht

- Base URL und Netzwerkzugriff prüfen
- bei AUTOMATIC1111 sicherstellen, dass die API erreichbar ist
- bei ComfyUI prüfen, ob ein Checkpoint verfügbar ist
- Schreibrechte für `sd_output/` prüfen

---

## Lizenz

MIT
