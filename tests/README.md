# Tests

Die Tests zerfallen in drei Gruppen. Die **Vertragstests** sichern die Teile ab,
die beim Umbau des Admin-Dashboards zur Nachrichtenflow-Topologie entstanden
sind, und die Aufteilung des Admin-One-Pagers in eine Seite pro
Navigationseintrag. Die **Unit-Suiten** prüfen die reinen Helfer der
Bibliotheken unter `lib/` und der Endpunkte unter `api/` – also genau die
Funktionen, die ohne Datenbank, ohne HTTP und ohne Netzwerk entscheidbar sind.
Die **Integrationstests** unter `db/` prüfen dagegen genau die Zweige, die eine
echte MySQL-Instanz brauchen (Einstellungen, Chat-Sitzungen, Intelligenzgruppen,
Prompt-Sicherheitsregeln, LLM-Healthcheck); sie laufen nur mit `./tests/run.sh db`.

## Vertragstests

| Ebene | Datei | Gegenstand |
|---|---|---|
| PHP | `php/test_topology_helpers.php` | Die reinen Helfer aus `lib/topology.php`: Zustands- und Aktivitätsbewertung, Zahlen-, Alters- und Temperaturformatierung, URL- und Client-Beschriftung, Parametergrößen, Endpunktzustand, JSON-Insel. |
| PHP | `php/test_topology_build.php` | `topology_build()`: Hierarchie (LLMInt → LLM/SpeechInt/ImageInt/SearXNG/Wissensdatenbank/Clients/API), Knoten-/Kantenvertrag für den Renderer, Detailkarten-Felder, Optionen (`include_off`, `slot_limit`), Störungsliste, API-Schlüssel als Wolke mit ihren Zählern, laufende Anfrage am Client-Knoten (`route`). |
| JavaScript | `js/flow-topology.test.js` | Der Canvas-Renderer `assets/js/flow-topology.js` in einer VM-Sandbox: was `apply()` übernimmt und verwirft, Umschalten zwischen Detailkarte und HTML-Panel, Auswahl per Klick, der hervorgehobene Weg zwischen Endpunkt und Aufrufer (in beide Richtungen, bei laufender Anfrage auf deren Zweig eingegrenzt, bei einer Sitzung ohne laufende Anfrage gar kein Weg), das Lauflicht des laufenden Weges, die Platzierung der Detailkarte neben dem Knoten, ein vollständiger Frame, reduzierte Bewegung, ResizeObserver, JSON-Insel und `load()`. |
| PHP | `php/test_admin_payloads.php` | Die beiden Stellen, die den Statistik-Payload für `topology_build()` zusammenbauen (`admin/load_stats.php` für den 15-s-Poll, `admin/index.php` für die serverseitig gerenderte Dashboard-Karte). Der Test liest die Array-Literale aus dem Quelltext und vergleicht ihre Abschnitte: fehlt ein Zweig in einer der beiden Aufbaustellen, bleibt er im Dashboard leer, bis der nächste Poll greift. |
| PHP | `php/test_admin_split.php` | Der Vertrag der aufgeteilten Adminseiten (`admin/partials/` + eine Seite je Navigationseintrag): Seitenleiste und Seitendateien bleiben deckungsgleich, jede Seite hält `bootstrap → layout_head → Karte → layout_foot` ein, jede Karte und jede Element-ID existiert genau einmal, jedes `getElementById` und jedes `label for=` findet sein Element auf derselben Seite, kein Ankersprung zeigt auf eine Karte einer anderen Seite, Formulare senden an ihre eigene Seite mit einem Action-Namen, den der Dispatcher kennt, keine Seite leitet auf eine andere um, jeder **?**-Knopf einer Feldbeschreibung genau einen Text hat (und die Überschrift reiner Text bleibt, weil die Popup sie als Text schreibt), und die Topologie-Deeplinks aus `lib/topology.php` (`topology_admin_link()`) zeigen auf die Seite, die die Zielkarte wirklich enthält. |

## Unit-Suiten der reinen Helfer

| Ebene | Datei | Gegenstand |
|---|---|---|
| PHP | `php/test_model_naming.php` | Die reinen Modellnamen-Helfer aus `db.php`: kanonische Modellnamen, Intelligenz-Schätzung, Gruppenbeschriftungen und die Präfixe `@@<Gruppe>` / `!!` von Chatnachrichten. Diese Helfer entscheiden, welche Endpunkte als „dasselbe Modell“ gepoolt werden und in welcher Intelligenzgruppe ein Chat läuft – ein Fehler hier routet Chats still auf den falschen Endpunkt. |
| PHP | `php/test_prompt_security.php` | Die reinen Stufen aus `lib/prompt_security.php`: Normalisierung (Stufe 1), Bewertung (Stufe 3), die Zuordnung des KI-Labels (Stufe 4) und die Entscheidungsschwellen (Stufe 5). Stufe 2 (`psMatchRules`) braucht die Regeltabelle aus der Datenbank und wird daher nicht abgedeckt; die Bewertungsfunktionen bekommen synthetische Regelzeilen. |
| PHP | `php/test_dictation_commands.php` | Die deterministischen Diktat-Helfer aus `lib/speech_dictation.php`: Befehlstabelle, lokale Befehlsverarbeitung (greift, wenn das Diktatmodell ausfällt), Ausgabebereinigung, Token-Maskierung, Kontextbegrenzung und Log-Redaktion. |
| PHP | `php/test_balancer_engine.php` | Die reinen Konfigurations- und SQL-Helfer aus `lib/balancer_engine.php`: Standardwerte, Fairness-Sortierung, Circuit-Breaker-Bedingung und Backoff-Berechnung. Der Balancer verteilt Anfragen über die Endpunkte eines Modells, ein Fehler hier betrifft jede geroutete Anfrage. |
| PHP | `php/test_reverse_proxy.php` | Die reinen Helfer aus `lib/reverse_proxy.php`: Parsen der vertrauenswürdigen Proxys, CIDR-Vergleich, Umgang mit Forwarded-Headern, SSO-Header-Namen und die Berechnung der öffentlichen Basis-URL. Unter der CLI-SAPI kehrt `reverseProxyApply()` sofort zurück, deshalb wird hier ein selbst gebautes `$_SERVER` verwendet. |
| PHP | `php/test_openai_api.php` | Die reinen Request-/Response-Helfer aus `lib/openai_api.php`: Fehlerkörper, API-Schlüsselmaterial, Bearer-Token-Extraktion und die Normalisierung eingehender OpenAI-kompatibler Chat-Anfragen. Nur gültige Eingaben werden geprüft – die Normalisierer beantworten ungültige Anfragen mit `openaiSendError()`, das `exit()` aufruft. |
| PHP | `php/test_quickinfo.php` | Die reinen Helfer aus `lib/quickinfo.php`: URL-Normalisierung, 24-h-Minimum/Maximum und das Zusammenführen der drei quickinfo-Antworten (`/status`, `/info`, `/history`) zu einer Metrikzeile für die Admin-Oberfläche. Die HTTP-Schicht braucht eine erreichbare quickinfo-Instanz und wird bewusst nicht abgedeckt; `quickinfoReadHandle()` wird nur für den Zweig „keine Antwort“ geprüft. |
| PHP | `php/test_embedding_helpers.php` | Die reinen Helfer aus `api/embedding.php`: Kosinus-Ähnlichkeit, Dekodieren gespeicherter Embeddings und der leere Rückfall des Rerankers. |
| PHP | `php/test_vector_helpers.php` | Die konfigurationsnahen Helfer aus `api/vector_store.php`: die Override-Schicht für Einstellungen, Auflösung von Modus/Beschriftung/URLs, der Sammlungsname je Embedding-Modell und die beiden Formatierer, die Suchtreffer in LLM-Kontext bzw. Tool-Ergebnisse verwandeln. |
| PHP | `php/test_doc_convert.php` | Die Konfigurations- und Formathelfer aus `api/doc_convert.php`: MIME→Endung, akzeptierte Endungen und Rückfallliste sowie Basis-URL, Token und Timeout aus der Umgebung. |
| PHP | `php/test_image_health.php` | Die tolerante Komponenten-/Fortschrittsnormalisierung aus `lib/image_generation.php`, die die Download-Balken im Adminbereich speist: `imageIntNumeric()` (Komma, Prozentzeichen, Leerzeichen), `imageIntStateLabel()`/`imageIntComponentLabel()`, `imageIntComponentProgress()` (Prozent aus Bytes/Dateien, `ready` → 100 %, Begrenzung, ETA) und `imageIntParseComponents()` (verschachtelte und flache Komponentenblöcke). Wichtig: ImageInt meldet heute keine Bytes – der Parser muss dann unbestimmt bleiben statt zu scheitern. Die HTTP-Schicht (`imageIntReady()`, `imageIntHealth()`, `imageIntModels()`) braucht einen echten Endpunkt und ist nicht abgedeckt. |
| PHP | `php/test_mailer.php` | `lib/mailer.php` gegen den Fake-SMTP-Server `php/support/fake_smtp.php` (Kindprozess): der vollständige Dialog mit und ohne `AUTH LOGIN`, die übertragenen Absender-/Empfängerzeilen, quoted-printable-Kodierung der Umlaute im Text, ASCII-Betreff unverändert, Umlaut-Betreff als RFC-2047-Wort, Sonderzeichen im Anzeigenamen in Anführungszeichen, `multipart/alternative` bei HTML-Text, der Abbruch bei abgelehntem STARTTLS, der fehlgeschlagene Verbindungsaufbau sowie `mailerFromSettings()`/`sendMail()` ohne konfigurierten SMTP-Host. Nicht abgedeckt: `encryption = 'ssl'` und der erfolgreiche STARTTLS-Aufstieg (bräuchten einen TLS-Server mit Zertifikat), der AUTH-PLAIN-Rückfall, Dot-Stuffing (über die öffentliche API nicht erreichbar) und die datenbankgestützten SMTP-Einstellungen (ohne DB liefert `getSetting()` den Standardwert – genau dieser Zustand wird geprüft). |

## Integrationstests gegen MySQL (`db/`)

Diese Suiten brauchen eine echte Datenbank und werden deshalb **nicht** von
`./tests/run.sh` (Voreinstellung) gestartet. Sie prüfen genau die Zweige, die
ohne DB nicht entscheidbar sind; der Wegwerf-Container von `./tests/run.sh db`
stellt eine eigene Testdatenbank bereit.

| Ebene | Datei | Gegenstand |
|---|---|---|
| DB | `db/test_settings_repository.php` | `getSetting()`/`setSetting()` aus `db.php`: Standardwerte bei fehlendem Schlüssel, die Unterscheidung zwischen „Schlüssel fehlt“ und „Schlüssel ist leer“, der echte Upsert über `INSERT … ON DUPLICATE KEY UPDATE` (eindeutiger Schlüssel statt Duplikate) und die Schema-Anlage beim ersten Verbindungsaufbau. Nicht geprüft: der Zustand „keine Datenbank“ – den deckt `tests/php/` ab. |
| DB | `db/test_conversation_sessions.php` | Die Chat-Session-Persistenz aus `db.php`: `saveConversationSession()`/`loadConversationSession()`/`loadUserConversationSession()`, die Titelableitung aus der ersten Nutzernachricht inklusive Kürzung, leerer Bezeichner als Nichts-Tun, `NULL` bei fehlender oder unlesbarer Sitzung, das Upgrade-Fenster von 20 Minuten (`setSessionUpgradeModel()`/`getActiveSessionUpgradeModel()`) und `purgeExpiredConversationSessions()` (nur anonyme Sitzungen älter als 30 Minuten). Nicht geprüft: zwei gleichzeitige Saves für dieselbe Sitzung (nicht deterministisch). |
| DB | `db/test_intelligence_groups.php` | Die Intelligenzgruppen: `listIntelligenceGroups()` leitet sie aus den Modellen der aktiven Endpunkte ab und sortiert sie absteigend, `resolveIntelligenceGroupModel()` löst eine Beschriftung auf ein Modell auf, `setSessionIntelligenceGroup()`/`getSessionIntelligenceGroup()`/`clearSessionIntelligenceGroup()` pflegen die Sitzungszeile. Nicht geprüft: `modelIntelligenceScore()`/`intelligenceGroupLabel()` als reine Namensparser – das macht `tests/php/test_model_naming.php`. |
| DB | `db/test_prompt_security_rules.php` | `lib/prompt_security.php` gegen die Regel- und Protokolltabellen: `psLoadRules()` (Laden, Sortieren, inaktive Regeln überspringen), die vollständige Pipeline `psEvaluate()` mit ihrer Protokollzeile und die Aufbewahrungsfrist von `psPurgeLogs()`. Wichtig: `psLoadRules()` cacht pro Prozess, deshalb werden alle Regel-Attrappen vor dem ersten Lesevorgang angelegt. Nicht geprüft: die optionale KI-Bewertung `psAiEvaluate()` – sie bräuchte einen echten Klassifizierer. |
| DB | `db/test_llm_healthcheck.php` | `probeLlmEndpoints()` und der Settings-Cache aus `lib/healthcheck.php`: ein leeres Ergebnis ohne Endpunkte, nur aktive Endpunkte, die Felder `id`/`alias`/`ok` mit ihren Typen, die Reihenfolge mehrerer Endpunkte, der Erfolgsfall gegen `php/support/fake_http.php`, der 503-Fall, `isAnyLlmEndpointHealthy()` in beiden Ausgängen sowie der Cache-Eintrag mit `ts`/`available`/`endpoints`, seine Gültigkeit genau bis zum Ablauf der TTL und das Ignorieren unbrauchbarer Werte. Den Erstaufbau des Laufzeitschemas prüft `db/support/seed_probe.php` in einem eigenen Prozess. Die Suite leert die `endpoints`-Tabelle und erwartet deshalb eine Datenbank, die ausschließlich den Tests dient. |
| DB | `db/bootstrap.php` | Verbindungsaufbau mit Wiederholungsversuchen, Präfixe für alle Attrappen (`test-db-*`), `test_db_insert_endpoint()` und die bereinigende Hilfsfunktion. |
| DB | `db/support/seed_probe.php` | Hilfsprozess für den Erstaufbau des Laufzeitschemas (`ensureRuntimeSchema()` greift pro Prozess nur einmal). |
| DB | `db/php-with-mysql.Dockerfile` | `php:8.2-cli-alpine` plus `pdo_mysql`, damit `run.sh` die DB-Suiten auch ohne lokales PHP im Container fahren kann. |

Beide Fake-Server unter `php/support/` sind Hilfsprozesse, die der jeweilige
Test selbst startet und wieder beendet: `fake_smtp.php` spielt einen
SMTP-Server, `fake_http.php` einen HTTP-Server (Antwortformat
`<Statuscode> [Körper]`, `MAX_REQUESTS` begrenzt die Lebensdauer). Sie lauschen
nur auf `127.0.0.1`, es gehen keine Anfragen nach außen.

Die Tests sind bewusst **abhängigkeitsfrei**: kein Composer, kein PHPUnit, kein
npm-Paket, kein Browser. `php/bootstrap.php` ist ein Mini-Harness,
`js/support/sandbox.js` eine DOM-/Canvas-Attrappe, die den Renderer in
`node:vm` lädt.

## Alles auf einmal

```sh
./tests/run.sh        # PHP und JavaScript
./tests/run.sh php    # nur tests/php/test_*.php
./tests/run.sh js     # nur tests/js/*.test.js
./tests/run.sh db     # nur tests/db/test_*.php (braucht MySQL, siehe unten)
```

`run.sh` benutzt ein lokal installiertes `php` bzw. `node` und fällt sonst auf
die offiziellen Docker-Images `php:8.2-cli-alpine` und `node:22-alpine` zurück.
Es braucht eine POSIX-Shell (Linux, macOS, WSL oder Git Bash).

Die Voreinstellung `all` startet **nur** PHP und JavaScript. Die DB-Suiten sind
absichtlich nicht dabei: sie schreiben und löschen in der Datenbank, die sie
bekommen. `./tests/run.sh db` startet deshalb einen Wegwerf-MySQL-Container
(`mysql:8.0`, Netzwerk `llmint-test-net`, Datenbank `llmint_test`) und räumt ihn
am Ende wieder ab; für die PHP-Seite baut es sich bei Bedarf das Image
`llmint-test-php:8.2` aus `tests/db/php-with-mysql.Dockerfile` (PHP 8.2 mit
`pdo_mysql`).

Soll gegen eine schon laufende Datenbank getestet werden, die `DB_*`-Variablen
setzen – dann wird **kein** Container gestartet:

```sh
DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=llmint_test \
DB_USER=root DB_PASS=llmint-test ./tests/run.sh db
```

Ist kein lokales `php` vorhanden, laufen die Suiten trotzdem im Container; die
Verbindungsdaten werden dorthin durchgereicht. `DB_HOST` muss dann allerdings
**aus dem Container heraus** erreichbar sein: mit Docker Desktop ist das
`host.docker.internal` (nicht `127.0.0.1`), unter Linux braucht es
`--add-host` bzw. ein gemeinsames Docker-Netz.

**Achtung:** Diese Datenbank wird verändert. `test_llm_healthcheck.php` leert die
`endpoints`-Tabelle (sonst ließe sich der Fall „keine Endpunkte“ nicht prüfen)
und `ensureRuntimeSchema()` legt beim ersten Aufbau einen Vorgabe-Endpunkt an.
Deshalb niemals eine Entwicklungs- oder Produktionsdatenbank angeben.

Unter Windows ohne Git Bash (WSL mit Docker-Desktop-Interop oder PowerShell):

```powershell
docker network create llmint-test-net
docker run -d --name llmint-test-db --network llmint-test-net `
  -e MYSQL_ROOT_PASSWORD=llmint-test -e MYSQL_DATABASE=llmint_test mysql:8.0
docker build -t llmint-test-php:8.2 -f tests/db/php-with-mysql.Dockerfile .
docker run --rm --network llmint-test-net -e DB_HOST=llmint-test-db `
  -e DB_PORT=3306 -e DB_NAME=llmint_test -e DB_USER=root -e DB_PASS=llmint-test `
  -v "${PWD}:/app" -w /app llmint-test-php:8.2 php tests/db/test_settings_repository.php
```

Alle `-e`/`-v`/`-w`-Schalter müssen **vor** dem Image-Namen stehen; was danach
kommt, ist das Kommando im Container.

## Einzelne Suiten

Jede Datei `tests/php/test_*.php` läuft eigenständig und endet mit
`PASS <Name>: n/m Tests bestanden`; `./tests/run.sh php` startet alle.

PHP (benötigt PHP ≥ 8.1 mit `mbstring`, weil `db.php` eingebunden wird;
`curl` ist optional – `test_quickinfo.php` prüft den curl-Zweig nur, wenn die
Erweiterung geladen ist):

```sh
php tests/php/test_topology_helpers.php
php tests/php/test_topology_build.php
php tests/php/test_admin_payloads.php
php tests/php/test_admin_split.php
php tests/php/test_model_naming.php
php tests/php/test_balancer_engine.php
php tests/php/test_reverse_proxy.php
php tests/php/test_openai_api.php
php tests/php/test_quickinfo.php
php tests/php/test_prompt_security.php
php tests/php/test_dictation_commands.php
php tests/php/test_embedding_helpers.php
php tests/php/test_vector_helpers.php
php tests/php/test_doc_convert.php
php tests/php/test_image_health.php
php tests/php/test_mailer.php
```

Ohne lokales PHP per Docker (funktioniert auch in PowerShell):

```powershell
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli-alpine php tests/php/test_topology_build.php
```

Die DB-Suiten laufen nur mit Datenbank – am einfachsten über `./tests/run.sh db`
(siehe oben), einzeln per Docker:

```powershell
docker run --rm --network llmint-test-net -e DB_HOST=llmint-test-db -e DB_PORT=3306 `
  -e DB_NAME=llmint_test -e DB_USER=root -e DB_PASS=llmint-test `
  -v "${PWD}:/app" -w /app llmint-test-php:8.2 php tests/db/test_conversation_sessions.php
```

`tests/db/bootstrap.php` baut die Verbindung aus `DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER` und `DB_PASS` auf und versucht es 15-mal im Sekundenabstand,
damit ein gerade startender Container nicht sofort rot wird. Fehlt die
Datenbank, bricht die Suite mit Exit-Code 1 und klarer Meldung ab – ein nicht
gelaufener Test darf nie als bestanden gelten. Ohne gesetzte Variablen gelten die
Werte aus `docker-compose.yml` (`127.0.0.1:3306`, `llmint`/`llmint`); wer die
Voreinstellung nutzt, sollte sicherstellen, dass das wirklich eine Testdatenbank
ist.

JavaScript (benötigt Node ≥ 18):

```sh
node --test tests/js/*.test.js
```

Ohne lokales Node per Docker:

```powershell
docker run --rm -v "${PWD}:/app" -w /app node:22-alpine node --test tests/js/flow-topology.test.js
```

Alle PHP-Testdateien geben `PASS <Name>: n/m Tests bestanden` aus und beenden sich
bei einem Fehlschlag mit Exit-Code 1; `node --test` ebenso. Damit sind sie
direkt in CI oder einem Pre-Commit-Hook verwendbar.

## Aufbau

```
tests/
├── run.sh                        # Runner für alle Suiten
├── php/
│   ├── bootstrap.php             # Mini-Harness (test_case, Assertions, Summary)
│   ├── fixtures.php              # realistische load_stats-Antworten als Vorlagen
│   ├── support/
│   │   ├── fake_smtp.php         # Fake-SMTP-Server als Kindprozess (test_mailer.php)
│   │   └── fake_http.php         # Fake-HTTP-Server als Kindprozess (DB-Healthcheck)
│   ├── test_topology_helpers.php # 31 Tests
│   ├── test_topology_build.php   # 51 Tests
│   ├── test_admin_payloads.php   # 4 Tests
│   ├── test_admin_split.php      # 23 Tests
│   ├── test_model_naming.php     # 22 Tests
│   ├── test_balancer_engine.php  # 18 Tests
│   ├── test_reverse_proxy.php    # 29 Tests
│   ├── test_openai_api.php       # 21 Tests
│   ├── test_quickinfo.php        # 27 Tests
│   ├── test_prompt_security.php  # 22 Tests
│   ├── test_dictation_commands.php # 35 Tests
│   ├── test_embedding_helpers.php  # 13 Tests
│   ├── test_vector_helpers.php   # 29 Tests
│   ├── test_doc_convert.php      # 13 Tests
│   └── test_mailer.php           # 12 Tests
├── db/                           # Integrationstests, nur mit MySQL (./tests/run.sh db)
│   ├── bootstrap.php             # Verbindung, Präfixe, Endpunkt-Attrappen
│   ├── php-with-mysql.Dockerfile # php:8.2-cli-alpine + pdo_mysql
│   ├── support/seed_probe.php    # Erstaufbau des Laufzeitschemas (eigener Prozess)
│   ├── test_settings_repository.php    # 12 Tests
│   ├── test_conversation_sessions.php  # 23 Tests
│   ├── test_intelligence_groups.php    # 14 Tests
│   ├── test_prompt_security_rules.php  # 17 Tests
│   └── test_llm_healthcheck.php        # 16 Tests
└── js/
    ├── support/sandbox.js        # DOM-/Canvas-Attrappe + node:vm-Lader
    └── flow-topology.test.js     # 50 Tests
```

Zusammen 482 Tests: 350 in PHP, 50 in JavaScript und 82 datenbankgestützt in
`tests/db/`.

## Continuous Integration

`.github/workflows/tests.yml` hat zwei Jobs:

* **`tests`** richtet PHP 8.2 (`mbstring`, `curl`) und Node 22 ein und startet
  `./tests/run.sh` – also alle Unit- und Vertragstests.
* **`db-tests`** startet einen `mysql:8.0`-Service (Datenbank `llmint`, Benutzer
  `root`/`llmint`, Port 3306) und übergibt die Zugangsdaten über die
  `DB_*`-Variablen an `./tests/run.sh db`. Läuft die Datenbank schon, startet
  `run.sh` keinen eigenen Container mehr, sondern nutzt die angegebene.

## Grenzen der Testumgebung

Die Unit-Suiten laufen bewusst ohne Datenbank, ohne HTTP und ohne Browser, die
DB-Suiten ohne HTTP und ohne Browser. Daraus folgt, was **nicht** abgedeckt ist –
diese Lücken sind bekannt und in den Docblocks der jeweiligen Datei benannt:

* **Datenbankgestützte Pfade.** Abgedeckt sind inzwischen die Einstellungen,
  die Chat-Sitzungen, die Intelligenzgruppen, die Prompt-Security-Regeln und der
  LLM-Healthcheck (`tests/db/`). **Nicht** abgedeckt bleiben alle übrigen
  Datenbankpfade, insbesondere die Seiten unter `admin/`, die Endpunkte unter
  `api/` (Vektor-, Embedding- und Dokumentverwaltung) sowie die
  Datenbankzugriffe in `lib/balancer_engine.php` und
  `lib/speech_dictation.php`. Ohne MySQL-Treiber wirft `getDb()` eine
  `PDOException`; `getSetting()` fängt sie und liefert den Standardwert – genau
  dieser Zustand wird in den Unit-Suiten geprüft (Balancer-, Diktat- und
  Prompt-Regeln in ihrer Standardbelegung).
* **Die DB-Suiten verändern ihre Datenbank.** `test_llm_healthcheck.php` leert
  die `endpoints`-Tabelle und `ensureRuntimeSchema()` legt beim ersten Aufbau
  einen Vorgabe-Endpunkt an. Deshalb ausschließlich eine eigene Testdatenbank
  angeben (der Wegwerf-Container von `./tests/run.sh db` ist genau das).
* **Netzwerk- und HTTP-Schichten.** Aufrufe an Milvus, docvecwizard, quickinfo,
  Embedding- und Rerank-Dienste, LDAP, SearXNG sowie die Speech- und
  Bild-Endpunkte werden nicht ausgeführt. Zwei Ausnahmen sind über lokale
  Fake-Server abgedeckt: der SMTP-Dialog (`php/support/fake_smtp.php`) und die
  Erreichbarkeitsprüfung der LLM-Endpunkte (`php/support/fake_http.php`).
* **Verschlüsselte Verbindungen.** Weder `encryption = 'ssl'` noch der
  erfolgreiche STARTTLS-Aufstieg des Mailers noch TLS-Endpunkte (`https://`) des
  Healthchecks werden geprüft – dafür bräuchte es einen Server mit Zertifikat.
  Vom Mailer wird nur der Abbruch bei abgelehntem STARTTLS geprüft.
* **`api/upload_document.php` ist nicht einbindbar.** Die Datei startet beim
  Laden eine Session, schreibt bei fehlender Berechtigung sofort JSON und
  beendet sich; ihre Funktionen sind zudem mit `function_exists()`-Wächtern
  versehen und damit nicht neu deklarierbar. Sie bleibt daher außen vor – eine
  Anpassung nur zum Testen wäre eine Änderung an der Fachlogik und wurde
  bewusst unterlassen.
* **`openaiSendError()` ruft `exit()` auf.** Deshalb werden in
  `test_openai_api.php` nur gültige Eingaben durch die Normalisierer geschickt.
* **Kein Browser, kein End-to-End-Test.** Die JavaScript-Suite prüft den
  Renderer in `node:vm` gegen eine DOM-Attrappe. Das echte Zusammenspiel von
  Browser, HTTP-Endpunkten und Datenbank (Anmelden, Chatten, Admin-Seiten
  bedienen) wird nicht getestet.
* **Kein Linter, kein Type-Checker.** Das Projekt hat weder Composer noch
  statische Analyse; die Tests sind die einzige automatische Prüfung.

## Abdeckung messen (optional, nicht Teil der CI)

Die CI fährt mit `coverage: none`. Für eine Momentaufnahme genügt `pcov` in
einem Wegwerf-Container, ohne das Repository zu verändern. Zwei kleine Helfer
sind dabei **nicht** Teil des Repos, sondern werden ad hoc in den Container
gelegt:

`prepend.php` (wird per `auto_prepend_file` vor jede Testdatei gehängt und
schreibt die Treffer pro Prozess als JSON):

```php
<?php
pcov\start();
register_shutdown_function(static function (): void {
    $out = getenv('PCOV_OUT');
    if ($out !== false && $out !== '') {
        file_put_contents($out, json_encode(pcov\collect()));
    }
});
```

Ein Auswerte-Skript (`aggregate.php`) führt die JSON-Dateien zusammen und zählt
die ausführbaren Zeilen mit `token_get_all()` über `lib/`, `api/`, `admin/` und
die Wurzel.

```sh
docker run --rm --network llmint-test-net -v "$PWD:/app" -w /app \
  -e DB_HOST=llmint-test-db -e DB_NAME=llmint_test -e DB_USER=root -e DB_PASS=llmint-test \
  -v /pfad/zu/prepend.php:/tmp/prepend.php -v /pfad/zu/out:/tmp/out \
  llmint-test-php:8.2 sh -c '
    for f in tests/php/test_*.php tests/db/test_*.php; do
      PCOV_OUT="/tmp/out/$(basename "$f").json" \
        php -d pcov.enabled=1 -d pcov.directory=/app \
            -d auto_prepend_file=/tmp/prepend.php "$f"
    done'
php /pfad/zu/aggregate.php "$PWD" /pfad/zu/out
```

Zwei Stolperfallen:

* `-d pcov.directory=/app` ist **zwingend**. Ohne die Angabe erbt `pcov` das
  Arbeitsverzeichnis und instrumentiert nichts unter `/app` – das Ergebnis ist
  dann 0 %.
* `pcov` zählt nur Dateien, die tatsächlich geladen werden. Nicht geladene
  Dateien (der Großteil von `admin/` und viele `api/`-Endpunkte) erscheinen in
  der Auswertung mit 0 % und sind gar nicht instrumentiert.

Momentaufnahme vom 2026-10-15 (alle 20 Suiten grün, `php:8.2` mit `pdo_mysql`
und `pcov`):

| Bereich | abgedeckt | Zeilen | Anteil |
|---|---:|---:|---:|
| `lib/` gesamt | 2305 | 3984 | 57,9 % |
| davon `lib/topology.php` | 836 | 935 | 89,4 % |
| davon `lib/reverse_proxy.php` | 124 | 140 | 88,6 % |
| davon `lib/healthcheck.php` | 49 | 58 | 84,5 % |
| davon `lib/openai_api.php` | 96 | 116 | 82,8 % |
| davon `lib/quickinfo.php` | 182 | 223 | 81,6 % |
| davon `lib/balancer_engine.php` | 160 | 201 | 79,6 % |
| davon `lib/prompt_security.php` | 175 | 223 | 78,5 % |
| davon `lib/speech_dictation.php` | 537 | 700 | 76,7 % |
| davon `lib/mailer.php` | 146 | 200 | 73,0 % |
| `api/` gesamt | 757 | 5352 | 14,1 % |
| davon `api/embedding.php` | 227 | 255 | 89,0 % |
| davon `api/vector_store.php` | 425 | 508 | 83,7 % |
| davon `api/doc_convert.php` | 105 | 148 | 70,9 % |
| `db.php` | 711 | 999 | 71,2 % |
| `admin/` | 0 | 2540 | 0 % |
| **Gesamt** | **3773** | **13351** | **28,3 %** |

Die 0 % bei `admin/` sind kein Qualitätsurteil, sondern die Folge davon, dass
keine Admin-Seite im Test geladen wird. Genau dort liegt der größte
unabgedeckte Block.

## Empfohlener Ablauf vor und nach einer Änderung

1. Vor dem Eingriff: `./tests/run.sh` – der Stand muss grün sein. Wer eine
   Datenbank zur Hand hat, zusätzlich `./tests/run.sh db`.
2. Ändern.
3. `./tests/run.sh` erneut (und `./tests/run.sh db`, wenn Datenbankpfade
   betroffen sind). Wird eine Suite rot, zuerst prüfen, ob die Erwartung im
   Test oder der Code falsch ist; eine bestehende Erwartung nicht aufweichen,
   um grün zu werden.
4. Neue reine Helfer in `lib/` oder `api/` direkt mit einer Unit-Suite abdecken
   (siehe unten). Ist eine Funktion ohne Datenbank oder Netzwerk nicht
   entscheidbar, im Docblock der Suite festhalten, warum.
5. Erst danach committen.

## Neue Tests ergänzen

PHP: In der Testdatei `test_case('Beschreibung', function () { ... });`
registrieren und `exit(test_summary('Name'));` am Dateiende beibehalten.
Verfügbare Assertions: `assert_same`, `assert_not_same`, `assert_true`,
`assert_false`, `assert_count`, `assert_key`, `assert_keys`,
`assert_contains`, `assert_not_contains`, `assert_close`, `assert_all`.

Neue Dateien `tests/php/test_*.php` nimmt `run.sh` (und damit die CI) automatisch
mit, neue Dateien `tests/db/test_*.php` der Zweig `./tests/run.sh db`. Ein
Docblock am Dateikopf soll festhalten, was die Suite prüft und was sie bewusst
auslässt; Fehlermeldungen und Testnamen sind deutsch.

Datenbankgestützte Suiten: `require __DIR__ . '/bootstrap.php';` einbinden,
Attrappen mit den `TEST_DB_*`-Präfixen anlegen (`test_db_setting_key()`,
`test_db_session_id()`, `test_db_insert_endpoint()`) und am Dateikopf die
erreichbare MySQL-Instanz voraussetzen. Zeilen, die eine Suite bewusst anfasst –
etwa das Leeren von `endpoints` – gehören ausdrücklich in den Docblock.

Braucht eine Suite einen Server (SMTP, HTTP), gehört der Fake nach
`tests/php/support/` und wird als Kindprozess gestartet; er darf nur auf
`127.0.0.1` lauschen. Ein `flush()`/`sleep()`-Rennen gegen den startenden
Prozess ist zu vermeiden, indem der Fake seinen Port erst nach dem Binden
ausgibt (siehe `fake_http.php`).

JavaScript: `test('Beschreibung', () => { ... })` aus `node:test` verwenden.
`createEnvironment()` aus `support/sandbox.js` liefert `env` (mit `pump()`,
`click()`, `field()`, `emit()`, `consoleErrors`) und `env.api`; die Sandbox lädt
`assets/js/flow-topology.js` unverändert aus dem Repository. Werte, die aus der
VM zurückkommen, mit `same()` statt `assert.deepStrictEqual` vergleichen – sie
tragen einen fremden Prototyp, den `deepStrictEqual` ablehnt.
