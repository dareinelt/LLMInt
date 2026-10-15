# Tests

Diese Tests sichern die beiden Teile ab, die beim Umbau des Admin-Dashboards
zur Nachrichtenflow-Topologie entstanden sind:

| Ebene | Datei | Gegenstand |
|---|---|---|
| PHP | `php/test_topology_helpers.php` | Die reinen Helfer aus `lib/topology.php`: Zustands- und Aktivitätsbewertung, Zahlen-, Alters- und Temperaturformatierung, URL- und Client-Beschriftung, Parametergrößen, Endpunktzustand, JSON-Insel. |
| PHP | `php/test_topology_build.php` | `topology_build()`: Hierarchie (LLMInt → LLM/SpeechInt/ImageInt/SearXNG/Wissensdatenbank/Clients/API), Knoten-/Kantenvertrag für den Renderer, Detailkarten-Felder, Optionen (`include_off`, `slot_limit`), Störungsliste, API-Schlüssel als Wolke mit ihren Zählern, laufende Anfrage am Client-Knoten (`route`). |
| JavaScript | `js/flow-topology.test.js` | Der Canvas-Renderer `assets/js/flow-topology.js` in einer VM-Sandbox: was `apply()` übernimmt und verwirft, Umschalten zwischen Detailkarte und HTML-Panel, Auswahl per Klick, der hervorgehobene Weg zwischen Endpunkt und Aufrufer (in beide Richtungen, bei laufender Anfrage auf deren Zweig eingegrenzt), das Lauflicht des laufenden Weges, die Platzierung der Detailkarte neben dem Knoten, ein vollständiger Frame, reduzierte Bewegung, ResizeObserver, JSON-Insel und `load()`. |
| PHP | `php/test_admin_payloads.php` | Die beiden Stellen, die den Statistik-Payload für `topology_build()` zusammenbauen (`admin/load_stats.php` für den 15-s-Poll, `admin/index.php` für die serverseitig gerenderte Dashboard-Karte). Der Test liest die Array-Literale aus dem Quelltext und vergleicht ihre Abschnitte: fehlt ein Zweig in einer der beiden Aufbaustellen, bleibt er im Dashboard leer, bis der nächste Poll greift. |

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

PHP (benötigt PHP ≥ 8.1 mit `mbstring`, weil `db.php` eingebunden wird):

```sh
php tests/php/test_topology_helpers.php
php tests/php/test_topology_build.php
php tests/php/test_admin_payloads.php
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

Beide PHP-Dateien geben `PASS <Name>: n/m Tests bestanden` aus und beenden sich
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
│   └── test_admin_payloads.php   # 4 Tests
└── js/
    ├── support/sandbox.js        # DOM-/Canvas-Attrappe + node:vm-Lader
    └── flow-topology.test.js     # 50 Tests
```

Zusammen 136 Tests: 86 in PHP, 50 in JavaScript.

## Neue Tests ergänzen

PHP: In der Testdatei `test_case('Beschreibung', function () { ... });`
registrieren und `exit(test_summary('Name'));` am Dateiende beibehalten.
Verfügbare Assertions: `assert_same`, `assert_not_same`, `assert_true`,
`assert_false`, `assert_count`, `assert_key`, `assert_keys`,
`assert_contains`, `assert_not_contains`, `assert_close`, `assert_all`.

JavaScript: `test('Beschreibung', () => { ... })` aus `node:test` verwenden.
`createEnvironment()` aus `support/sandbox.js` liefert `env` (mit `pump()`,
`click()`, `field()`, `emit()`, `consoleErrors`) und `env.api`; die Sandbox lädt
`assets/js/flow-topology.js` unverändert aus dem Repository. Werte, die aus der
VM zurückkommen, mit `same()` statt `assert.deepStrictEqual` vergleichen – sie
tragen einen fremden Prototyp, den `deepStrictEqual` ablehnt.
