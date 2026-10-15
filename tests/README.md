# Tests

Die Tests zerfallen in zwei Gruppen. Die **Vertragstests** sichern die Teile ab,
die beim Umbau des Admin-Dashboards zur Nachrichtenflow-Topologie entstanden
sind, und die Aufteilung des Admin-One-Pagers in eine Seite pro
Navigationseintrag. Die **Unit-Suiten** prüfen die reinen Helfer der
Bibliotheken unter `lib/` und der Endpunkte unter `api/` – also genau die
Funktionen, die ohne Datenbank, ohne HTTP und ohne Netzwerk entscheidbar sind.

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
Die Tests sind bewusst **abhängigkeitsfrei**: kein Composer, kein PHPUnit, kein
npm-Paket, kein Browser. `php/bootstrap.php` ist ein Mini-Harness,
`js/support/sandbox.js` eine DOM-/Canvas-Attrappe, die den Renderer in
`node:vm` lädt.

## Alles auf einmal

```sh
./tests/run.sh        # PHP und JavaScript
./tests/run.sh php    # nur tests/php/test_*.php
./tests/run.sh js     # nur tests/js/*.test.js
```

`run.sh` benutzt ein lokal installiertes `php` bzw. `node` und fällt sonst auf
die offiziellen Docker-Images `php:8.2-cli-alpine` und `node:22-alpine` zurück.
Es braucht eine POSIX-Shell (Linux, macOS, WSL oder Git Bash).

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
```

Ohne lokales PHP per Docker (funktioniert auch in PowerShell):

```powershell
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli-alpine php tests/php/test_topology_build.php
```

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
│   └── test_doc_convert.php      # 13 Tests
└── js/
    ├── support/sandbox.js        # DOM-/Canvas-Attrappe + node:vm-Lader
    └── flow-topology.test.js     # 50 Tests
```

Zusammen 388 Tests: 338 in PHP, 50 in JavaScript.

## Grenzen der Testumgebung

Die Suiten laufen bewusst ohne Datenbank, ohne HTTP und ohne Browser. Daraus
folgt, was **nicht** abgedeckt ist – diese Lücken sind bekannt und in den
Docblocks der jeweiligen Datei benannt:

* **Datenbankgestützte Pfade.** Ohne MySQL-Treiber wirft `getDb()` eine
  `PDOException`; `getSetting()` fängt sie und liefert den Standardwert. Genau
  dieser Zustand wird in den Unit-Suiten geprüft (Balancer-, Diktat- und
  Prompt-Regeln in ihrer Standardbelegung). Nicht geprüft sind alle Zweige, die
  eine echte Datenbank brauchen – etwa `psMatchRules()`, `setSetting()`, die
  Chat-/Sitzungslogik und alles unter `admin/`.
* **Netzwerk- und HTTP-Schichten.** Aufrufe an Milvus, docvecwizard, quickinfo,
  Embedding- und Rerank-Dienste, SMTP, LDAP, SearXNG sowie die Speech- und
  Bild-Endpunkte werden nicht ausgeführt.
* **`api/upload_document.php` ist nicht einbindbar.** Die Datei startet beim
  Laden eine Session, schreibt bei fehlender Berechtigung sofort JSON und
  beendet sich; ihre Funktionen sind zudem mit `function_exists()`-Wächtern
  versehen und damit nicht neu deklarierbar. Sie bleibt daher außen vor – eine
  Anpassung nur zum Testen wäre eine Änderung an der Fachlogik und wurde
  bewusst unterlassen.
* **`openaiSendError()` ruft `exit()` auf.** Deshalb werden in
  `test_openai_api.php` nur gültige Eingaben durch die Normalisierer geschickt.
* **Kein Linter, kein Type-Checker.** Das Projekt hat weder Composer noch
  statische Analyse; die Tests sind die einzige automatische Prüfung.

## Abdeckung messen (optional, nicht Teil der CI)

Die CI fährt mit `coverage: none`. Für eine Momentaufnahme genügt `pcov` in
einem Wegwerf-Container, ohne das Repository zu verändern:

```sh
docker run --rm -v "$PWD:/app" -w /app php:8.2-cli-alpine sh -c '
  apk add --no-cache $PHPIZE_DEPS >/dev/null 2>&1
  pecl install pcov >/dev/null 2>&1
  docker-php-ext-enable pcov >/dev/null 2>&1
  for f in tests/php/test_*.php; do
    php -d pcov.enabled=1 -d pcov.directory=/app \
        -d auto_prepend_file=/pfad/zu/prepend.php "$f"
  done'
```

`prepend.php` startet `pcov\start()` und schreibt `pcov\collect()` beim
Herunterfahren als JSON. Wichtig: `pcov` zählt nur Dateien, die tatsächlich
geladen werden – nicht geladene Dateien (der Großteil von `admin/` und viele
`api/`-Endpunkte) erscheinen in der Auswertung gar nicht.

## Empfohlener Ablauf vor und nach einer Änderung

1. Vor dem Eingriff: `./tests/run.sh` – der Stand muss grün sein.
2. Ändern.
3. `./tests/run.sh` erneut. Wird eine Suite rot, zuerst prüfen, ob die
   Erwartung im Test oder der Code falsch ist; eine bestehende Erwartung nicht
   aufweichen, um grün zu werden.
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
mit. Ein Docblock am Dateikopf soll festhalten, was die Suite prüft und was sie
bewusst auslässt; Fehlermeldungen und Testnamen sind deutsch.

JavaScript: `test('Beschreibung', () => { ... })` aus `node:test` verwenden.
`createEnvironment()` aus `support/sandbox.js` liefert `env` (mit `pump()`,
`click()`, `field()`, `emit()`, `consoleErrors`) und `env.api`; die Sandbox lädt
`assets/js/flow-topology.js` unverändert aus dem Repository. Werte, die aus der
VM zurückkommen, mit `same()` statt `assert.deepStrictEqual` vergleichen – sie
tragen einen fremden Prototyp, den `deepStrictEqual` ablehnt.
