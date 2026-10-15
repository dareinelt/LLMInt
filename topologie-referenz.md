# Topologie-Referenz

Diese Referenz beschreibt die Nachrichtenfluss-Topologie des Admin-Dashboards
vollständig: **was** sie zeigt, **wie** sie technisch entsteht und **wie** sie
angepasst werden kann. Sie ist als Arbeitsgrundlage für Änderungen gedacht und
ergänzt [`README.md`](README.md), [`description.md`](description.md),
[`docs/architecture.md`](docs/architecture.md) und
[`docs/functions.md`](docs/functions.md).

Inhalt

1. [Funktion](#1-funktion)
2. [Datenfluss](#2-datenfluss)
3. [Hierarchie und Knotenarten](#3-hierarchie-und-knotenarten)
4. [Der Payload](#4-der-payload)
5. [Code-Referenz: `lib/topology.php`](#5-code-referenz-libtopologyphp)
6. [Code-Referenz: `assets/js/flow-topology.js`](#6-code-referenz-assetsjsflow-topologyjs)
7. [Code-Referenz: Erfassung und Zähler](#7-code-referenz-erfassung-und-zähler)
8. [Interaktion](#8-interaktion)
9. [Anpassbarkeit](#9-anpassbarkeit)
10. [Tests und Dokumentationspflichten](#10-tests-und-dokumentationspflichten)
11. [Schnellreferenz](#11-schnellreferenz)

---

## 1. Funktion

Die Topologie ersetzt die frühere Kachel-Grafik des Admin-Dashboards. Sie zeigt
denselben Informationsgehalt – Endpunkte, Auslastung, Token, Temperaturen,
Clients, Dienste –, aber als **Nachrichtenfluss**: LLMInt steht im Zentrum, alle
Bausteine liegen als Ringe um das Zentrum, und Kanten zeigen, wer mit wem
spricht. Jede Kante trägt Partikel, deren Dichte und Geschwindigkeit von der
Aktivität (`activity`) des Zielknotens abhängen.

Sie beantwortet drei Fragen:

- **Was läuft?** Zustände (`ok`, `warn`, `error`, `off`), Parallel-Slots,
  laufende Anfragen und Auffälligkeiten (`incidents`).
- **Wer nutzt das System?** Zwei Aufrufer-Zweige: die **Clients**-Wolke (offene
  Browser-Sitzungen aus den Heartbeats) und die **API**-Wolke (Aufrufe über die
  OpenAI-kompatible Reverse-Proxy-Schnittstelle, je API-Schlüssel ein Eintrag).
- **Wohin läuft eine Anfrage?** Ein Klick hebt den kompletten Weg zwischen
  Aufrufer und Endpunkt hervor; läuft auf einem Client gerade eine Anfrage, ist
  der Weg auf deren Zweig eingegrenzt und wird als Lauflicht gezeichnet.

Zwei Darstellungsorte nutzen denselben Payload und denselben Renderer:

| Ort | Datei | Besonderheit |
|---|---|---|
| Dashboard-Karte | `admin/index.php` (`#dashboard-card`) | eingebettet in die Admin-Oberfläche, Panels und Legende im Kartenrahmen |
| Vollbildansicht | `admin/topology.php` | eigene Hülle ohne Admin-Sidebar, gleiche Bedienelemente |

---

## 2. Datenfluss

```
Heartbeats ─┐
API-Aufrufe ─┤
Endpunkte   ─┼─▶ admin/load_stats.php ──stats JSON──▶ topology_build()   (lib/topology.php)
SSH/quickinfo┘   (Sammelpunkt aller              │      reine Sicht: keine DB,
                 Livedaten)                      │      keine HTTP-Aufrufe
                                                 │  node/edge payload
                                                 ▼
                            admin/index.php  (#dashboard-card)  ─┐
                            admin/topology.php (Vollbild)        ─┴─▶ flow-topology.js (Canvas)
```

Die Dashboard-Karte in `admin/index.php` baut denselben Payload aus denselben
Datenquellen selbst zusammen, weil sie schon beim ersten Rendern fertig sein
muss; der Poll liefert danach `load_stats.php?topology=1`. Beide Aufbaustellen
müssen deshalb **denselben Abschnittssatz** an `topology_build()` übergeben –
`tests/php/test_admin_payloads.php` hält das fest.

1. **Erfassung** – die Laufzeit schreibt ihre Zahlen selbst weg: Heartbeats der
   Browser (`api/heartbeat.php` → `active_clients`), der Route-Feinschliff aus
   `api/chat.php` (`recordClientRoute()`), die API-Zähler aus
   `api/openai_common/chat_completions.php` (`recordApiCallUsage()`), dazu
   Endpunkt-, Token- und Systemmetriken aus den bestehenden Tabellen.
2. **Sammeln** – `admin/load_stats.php` liest alles in **ein** JSON. Der
   Abschnitt `api` entsteht dabei aus `listApiKeyUsage()`; fehlt die Tabelle
   (alte Installation), bleibt ein leerer Standardwert stehen.
3. **Übersetzen** – `topology_build($stats, $options)` macht daraus Knoten,
   Kanten und Auffälligkeiten. Diese Schicht kennt keine Datenbank: derselbe
   Payload entsteht aus einem Cache-Schnappschuss wie aus einer frischen Abfrage.
4. **Einbetten** – `topology_initial_script()` legt den Payload als
   `<script type="application/json" data-flow-initial>` in die Seite, damit der
   erste Aufbau ohne zusätzlichen Abruf gelingt.
5. **Zeichnen** – `flow-topology.js` liest den Insel-Payload beim Start
   (`parseInitial()`) und pollt danach `data-refresh-url`
   (`load_stats.php?topology=1`) im Intervall aus `data-refresh-interval`
   (Standard 15 s, Untergrenze 5 s). Jeder Poll läuft durch `apply()`.

---

## 3. Hierarchie und Knotenarten

Jede Kante zeigt vom Elternteil zum Kind. Daraus leitet der Renderer den
Ringbaum ab (`hierarchy()` in `flow-topology.js`).

```
hub (LLMInt, immer im Zentrum)
  ├─ llm      → model (Intelligenzgruppe) → endpoint (LLM-Endpunkt)
  ├─ speech   → endpoint (SpeechInt)
  ├─ image    → endpoint (ImageInt)
  ├─ search   (SearXNG)
  ├─ vector   (Wissensdatenbank)
  ├─ clients  → client    (eine Browser-Sitzung)
  └─ api      → api-key   (ein Aufrufer über die API; zusätzlich „ohne Key")
```

| `kind` | Rolle | Knotenschlüssel | Besonderheit |
|---|---|---|---|
| `hub` | LLMInt selbst | `hub` | genau einer, immer Zentrum |
| `llm` | Dienstgruppe LLM | `llm` | `group: true`, hält die Modelle |
| `model` | Intelligenzgruppe | `model:<canonicalModelName()>` | `group: true` |
| `endpoint` | LLM-Endpunkt | `ep:<id>` | `facts` + `slots` |
| `speech` / `image` | Dienstgruppe SpeechInt/ImageInt | `speech` / `image` | nur wenn Endpunkte existieren |
| `endpoint` (Speech/Image) | Dienstendpunkt | `speech-ep:<id>` / `image-ep:<id>` | |
| `search` | SearXNG | `search` | Punktknoten, weiter außen |
| `vector` | Wissensdatenbank | `vector` | |
| `clients` | Wolke der Browser-Sitzungen | `clients` | `cloud`, `group: true` |
| `client` | eine Sitzung | `client:<md5(host\|ip)>` | trägt ggf. `route` |
| `api` | Wolke der API-Aufrufe | `api` | `cloud`, `group: true` |
| `api-key` | ein API-Schlüssel | `api-key:<id>` bzw. `api-key:anon` | `facts` mit Zählern |

Gruppenknoten (`group: true`: `llm`, `model`, `speech`, `image`, `clients`,
`api`) bleiben aus `incidents` heraus – Störungen werden immer am konkreten
Endpunkt gemeldet, nie an der Gruppe.

**Wolken:** `clients` und `api` sind keine einzelnen Blasen, sondern Schwärme aus
ihren konkreten Aufrufern. Die Kindknoten werden als volumetrischer Schwarm auf
einer Kugel um den Gruppenknoten verteilt (`cloudAround()`, Fibonacci-Verteilung).
Beide Gruppen melden über `isCloudNode()`, dass sie eine Wolke sind.

---

## 4. Der Payload

Vertrag (ausführlich im Dateikopf von `lib/topology.php`):

```php
[
  'generated' => <unix ts>,
  'overall'   => ['state','label','message','errors','warnings','nodes'],
  'nodes'     => [ key => [
      'key','kind','title','subtitle','state','badge','message',
      'activity'   => int 0..12,          // Partikeldichte der Kanten
      'counters'   => ['current'=>int,'peak'=>int] | null,
      'facts'      => [['icon','label','value','tone'], …],
      'slots'      => ['used'=>int,'max'=>int] | null,
      'cloud'      => ['title','empty','items'=>[…]],
      'members'    => ['title','empty','items'=>[…]],
      'neighbours' => [key, …],
      'url'        => interner Link oder '',
      'synthetic'  => true für abgeleitete Gruppenknoten,
      'x','y','z'  => Layout-Hinweis (optional, über Refresh erhalten),
  ] ],
  'edges'     => [ ['from','to','state','synthetic','label'], … ],
  'incidents' => [ ['key','title','state','message','url'], … ],
]
```

Faustregeln:

- `facts` beschreiben die **Detailkarte** („icon label: value", Farbe aus
  `tone`), `slots` zeichnet den Auslastungsbalken.
- `cloud`/`members` sind **Listen** und landen im HTML-Seitenpanel. Ein Knoten
  hat entweder das eine oder das andere, nie beides (siehe `usesPanel()` und
  `usesDetailCard()` im Renderer).
- `url` macht den Knoten anklickbar bis „In der Konfiguration öffnen →"
  (z. B. `index.php#api-keys-card`).
- `route` am Client-Knoten ist die einzige Ausnahme von der reinen Sicht: sie
  beschreibt die gerade laufende Anfrage.

---

## 5. Code-Referenz: `lib/topology.php`

Reine Übersetzungsschicht, keine Datenbank, keine HTTP-Aufrufe. Reihenfolge im
Aufbau von `topology_build()`: Clients-Wolke → **API-Wolke** → LLM-Zweig →
SearXNG → Wissensdatenbank → ImageInt → SpeechInt → Zentrum und Hub-Kanten →
`overall` → `incidents`.

### Konstante

| Konstante | Wert | Bedeutung |
|---|---|---|
| `TOPOLOGY_API_KEY_PRESENCE_SECONDS` | `90` | Fenster, in dem ein Schlüssel nach seinem letzten Aufruf als „gerade aktiv" (grün) gilt; spiegelt das Präsenzfenster der Browser-Sitzungen (`listActiveClients()`) |

### Zustände und Hilfswerte

| Funktion | Zweck |
|---|---|
| `topology_kinds()` | alle Knotenarten, die das Frontend kennt (muss zu `KIND_LABELS` passen) |
| `topology_state_labels()` / `topology_state_label()` | Zustand → deutsches Label |
| `topology_state_rank()` / `topology_aggregate_state()` | Schweregrad und Aggregation; `off` rangiert unter `warn`, damit ein deaktivierter Endpunkt die Topologie nicht gelb färbt |
| `topology_activity()` | begrenzt die Flussintensität auf 0..12 |
| `topology_format_num()` | Kompaktzähler (`3512` → `3.5K`) |
| `topology_fact()` | eine Zeile der Detailkarte (`icon`, `label`, `value`, `tone`) |
| `topology_temp_tone()` | Farbton einer CPU-Temperatur |
| `topology_short_url()` | `https://host:8000/v1` → `host:8000` |
| `topology_param_size_label()` | Parameterangabe aus dem Modellnamen (`gemma-3-27b-it` → `27b`) |
| `topology_client_label()` | Hostname, sonst IP, sonst `unbekannt N` |
| `topology_age_label()` | Alter in Sekunden als Kurzform (`gerade eben`, `vor 12 s`, `vor 5 min`, `vor 1 h`, `vor 3 d`); `null` → `–` |
| `topology_route_label()` | deutsches Label einer laufenden Anfrageart |
| `topology_route_targets()` | löst die Route eines Clients in die Knotenschlüssel des Weges auf |
| `topology_node()` | füllt die gemeinsamen Knotenvorgaben auf |
| `topology_endpoint_state()` | Zustand eines LLM-Endpunkts aus `aktiv` + belegten Slots |

### `topology_build(array $stats, array $options = []): array`

Optionen:

| Option | Standard | Wirkung |
|---|---|---|
| `base_url` | `''` | Präfix für interne Links (`''` = relativ zu `admin/`) |
| `slot_limit` | `4` | Parallel-Slots je LLM-Endpunkt für `slots` und `topology_endpoint_state()` |
| `include_off` | `true` | deaktivierte Endpunkte als Knoten behalten |

Eingelesene `$stats`-Abschnitte: `endpoints`, `image_endpoints`,
`speech_endpoints`, `searxng`, `clients`, `vector_store`, `speech_status`,
`image_status`, `totals` und **`api`**. Fehlt ein Abschnitt, wird der
entsprechende Zweig einfach nicht gebaut (kein Fehler).

#### API-Zweig im Detail

```php
$apiUsage = is_array($stats['api'] ?? null) ? $stats['api'] : null;
$apiKeys  = is_array($apiUsage['keys'] ?? null) ? $apiUsage['keys'] : [];
$apiAnon  = is_array($apiUsage['anonymous'] ?? null) ? $apiUsage['anonymous'] : null;
```

- **Je Schlüssel ein Knoten** `api-key:<id>`: Zustand `ok`, wenn der Schlüssel
  nutzbar ist **und** `last_used_age <= TOPOLOGY_API_KEY_PRESENCE_SECONDS`
  (Meldung „Mit diesem Schlüssel läuft gerade ein Aufruf."), sonst `off`
  („Zurzeit keine Aufrufe mit diesem Schlüssel.", bei deaktiviert/abgelaufen mit
  Begründung). `badge` ist `N heute` bzw. `inaktiv`, `activity` 4 bei Präsenz
  sonst 1, `url` zeigt auf `index.php#api-keys-card`. Fakten in dieser
  Reihenfolge: Aufrufe, Aufrufe heute, Letztes Modell, Zuletzt benutzt, Präfix,
  Besitzer, ggf. Festes Modell, ggf. Gültig noch.
- **Sammeleintrag** `api-key:anon` (Titel „ohne Key", Untertitel „Aufrufe ohne
  gültigen Schlüssel"), nur wenn `anonymous.calls > 0` – Aufrufe ohne gültigen
  Schlüssel; er trägt dieselben vier Grundfakten.
- **Sortierung** nach Titel (`strcasecmp`), damit die Wolke stabil bleibt.
- **Gruppenknoten** `api` (kind `api`, Titel „API", Untertitel
  „Reverse Proxy · N Schlüssel") mit `cloud.title` „Aufrufe über die API" und
  den Fakten Aufrufe heute, Aufrufe gesamt, Gerade aktiv, Schlüssel, ggf. Ohne
  Schlüssel. `url` zeigt auf `index.php#api-keys-card`.
- **Kanten** `api → api-key:*` (synthetisch) und – weiter unten im Zentrum –
  `hub → api`.

### `topology_initial_script(array $topology): string`

Bettet den Payload als JSON-Insel ein (`<script type="application/json"
data-flow-initial>`); leerer String, wenn es keine Knoten gibt.

---

## 6. Code-Referenz: `assets/js/flow-topology.js`

IIFE mit genau einem öffentlichen Export: `window.FlowTopology` mit `mount`,
`version`, `colors` und `labels`. `mount(root, options)` bindet die Topologie an
ein DOM-Element und liefert ein Handle:

| Handle-Methode | Wirkung |
|---|---|
| `apply(payload)` | Payload anwenden (wie ein Poll) |
| `load(manual)` | sofortiger Abruf von `data-refresh-url`; `manual` löst `opts.onBusy` aus |
| `resize()` / `fit()` / `resetView()` | Canvas neu messen, einpassen, Ansicht zurücksetzen |
| `select(key)` | Knoten auswählen (`null` schließt die Karte/das Panel) |
| `state()` / `card()` / `route()` | Momentaufnahme von Knoten/Kanten, Geometrie der offenen Karte, aktuell hervorgehobener Weg |

Optionen: `onData(payload)`, `onBusy()`, `onError(text)` – Rückrufe für den
Poll-Zyklus. Der Renderer läuft vollständig auf einem 2D-Canvas mit eigener
3D-Projektion (`FOCAL`, `CAM_YAW`, `CAM_PITCH`).

### Konfigurationsblöcke (Kopf der Datei)

| Block | Inhalt |
|---|---|
| `STATE_COLORS` / `STATE_RGB` / `STATE_LABELS` | Farben und Labels der Zustände `ok`, `warn`, `error`, `off` |
| `KIND_COLORS` | Farbe je Knotenart (u. a. `api: '#f472b6'`, `'api-key': '#f9a8d4'`) |
| `KIND_LABELS` | deutscher Name je Knotenart – **Quelle der Legende** und des Kind-Filters |
| `KIND_RADIUS` / `DEFAULT_KIND_RADIUS` | Blasenradius je Knotenart (`api: 20`, `'api-key': 11`) |
| `FACT_TONES` | Farbe je `tone` einer Detailkarten-Zeile; unbekannte Tones fallen auf `plain` |
| `CARD` | Geometrie der Detailkarte (Breite, Zeilenhöhe, Balken …) |
| `FOCAL`, `CAM_YAW`, `CAM_PITCH` | Kamera der 3D-Projektion |
| `MAX_PARTICLES_PER_EDGE`, `STARS` | Partikel- und Sternenhimmel-Grenzen |
| `IDLE_BEFORE_ROTATE`, `MIN_REFRESH_INTERVAL`, `REFRESH_TIMEOUT`, `LOG_LIMIT` | Zeitverhalten |

### Layout

| Funktion | Zweck |
|---|---|
| `DEPTH_RADIUS` | Ringradius je Tiefe (`[0, 330, 520, 680, 820]`) |
| `CLOUD_RADIUS` / `CLOUD_RADIUS_DEFAULT` | Radius der Aufrufer-Schwärme (`clients: 150`, `api: 115`, sonst 120) |
| `CLOUD_SPREAD`, `CLOUD_MARGIN` | Streuung im Schwarm und Freiraum zur nächsten Branche |
| `SERVICE_RING_OFFSET` | zusätzlicher Ringabstand je Dienstzweig, die den Hub mit den Wolken teilen: `{ search: 190, speech: 190, image: 190 }` |
| `isCloudNode(node)` | ist der Knoten eine Wolke (`clients`/`api`)? |
| `cloudRadius(node)` | Radius der Wolke mit Rückfallwert |
| `branchPadding()` / `subtreeWeight()` | Winkelanteil einer Branche; eine Wolke zählt als **eine** Einheit statt proportional zu ihren Mitgliedern |
| `cloudAround(list, parent, radius, seed)` | verteilt die Mitglieder per Fibonacci-Kugel um den Gruppenknoten |
| `layout()` | radialer Baum in der Horizontalebene: LLMInt besitzt den Vollkreis und gibt Winkelanteile nach unten weiter – Geschwister können sich dadurch nie überlappen |

### Wege-Hervorhebung

| Funktion | Zweck |
|---|---|
| `isCallerSide(node)` | gehört der Knoten zur Aufruferseite (`client`, `clients`, `api`, `api-key`)? |
| `isBelow(ancestor, key, parents)` | hängt `key` im Baum unter `ancestor`? |
| `buildRoute(key)` | sammelt Knoten und Kanten des hervorzuhebenden Weges |
| `refreshRoute()` / `nodeAlpha()` / `edgeAlpha()` | wendet den Weg an; alles andere tritt zurück |

Regeln in `buildRoute()`:

1. `climb(key)` läuft vom gewählten Knoten über die Elternkanten bis zum Zentrum.
2. **Laufende Anfrage:** Hat der gewählte Client eine `route` mit Zielknoten
   (`busy = true`), werden nur diese Ziele mit hochgeklommen – der Weg ist auf
   den Zweig der laufenden Anfrage eingegrenzt und wird als Lauflicht gezeichnet.
3. **Sonst** entscheidet die Art des gewählten Knotens:
   - einzelner Aufrufer (`client`, `api-key`) → alle Knoten **außerhalb** der
     Aufruferseite, d. h. bis in die Endpunkt-Zweige;
   - Wolkenknoten (`clients`, `api`) → nur der eigene Zweig (`isBelow`);
   - Endpunkt-/Zweigknoten → alle Aufrufer-Knoten, also Clients **und**
     API-Schlüssel.
4. Weil das Netz ein Baum ist, gehört eine Kante zum Weg, sobald beide Enden
   dazugehören.

### Zeichnen, Panel und Karte

| Funktion | Zweck |
|---|---|
| `drawEdges()` / `drawParticles()` | Kanten mit Bézierbögen und Partikeln |
| `drawGlyph(node, x, y, size, color)` | Symbol je Knotenart (`api` = geschweifte Klammern, `api-key` = Schlüssel) |
| `drawNodes()` / `drawEffects()` / `drawVignette()` | Blasen, Zustandswechsel-Effekte, Vignette |
| `renderPanel(node)` / `renderList()` | HTML-Seitenpanel für Knoten mit `cloud`/`members` |
| `usesDetailCard()` / `usesPanel()` | entscheidet Karte gegen Panel (nie beides) |
| `placeCard()` / `cardOverlap()` | sucht eine freie Stelle **neben** dem Knoten; die Karte überlagert nichts |
| `drawDetailCard()` | zeichnet die Karte in den Canvas |
| `hitTest()` / `showTooltip()` / `endPointer()` | Maus, Tippen, Tooltip, Klickauswahl |
| `apply(payload)` / `diff()` / `load()` / `schedule()` | Poll-Zyklus, Zustandswechsel-Effekte und Protokoll |
| `buildKindLegend()` | baut die Legende aus `KIND_LABELS`; jedes Element ist zugleich Filter (`kindFilter`) |

Vom Server gesteuerte DOM-Haken im Wurzelelement:

| Haken | Bedeutung |
|---|---|
| `data-topo-canvas` | das Zeichenfläche-Canvas (Pflicht) |
| `data-topo-field="…"` | Textfeld, das der Renderer füllt (u. a. `generated`, `overall-label`, `overall-message`) |
| `data-topo-tooltip`, `data-topo-panel`, `data-topo-hint` | Tooltip, HTML-Seitenpanel, Hinweistext |
| `data-topo-kinds` | Behälter der Legende, wird von `buildKindLegend()` gefüllt |
| `data-topo-log-list`, `data-topo-log` | Protokollliste und deren Auf-/Zuklappen |
| `data-topo-incidents-list`, `data-topo-incidents-empty` | Auffälligkeitsliste und deren Leerzustand |
| `data-topo-action="…"` | Schaltflächen: `refresh`, `fullscreen`, `mode-3d`, `mode-2d`, `rotate`, `particles`, `labels`, `focus-problems`, `zoom-in`, `zoom-out`, `fit`, `reset`, `close-panel`, `toggle-log` |
| `data-topo-kind-filter` | Checkbox je Knotenart (aus der Legende) |
| `data-refresh-url`, `data-refresh-interval` | Poll-Ziel und Intervall in Sekunden |
| `data-flow-initial` | JSON-Insel mit dem ersten Payload |

Das Aussehen liefert [`assets/css/flow-topology.css`](assets/css/flow-topology.css).

---

## 7. Code-Referenz: Erfassung und Zähler

### API-Aufrufe (`api_keys` und `api_anonymous_usage`)

| Stelle | Aufgabe |
|---|---|
| `api/openai_common/chat_completions.php` | ruft nach `openaiBeginAnonymousApiRequest()` einmal `recordApiCallUsage($keyId, $model)` auf – der einzige Zählpunkt; `models.php` zählt bewusst nicht |
| `db.php` → `recordApiCallUsage(?int $keyId, string $model = '')` | `UPDATE api_keys` bzw. `INSERT … ON DUPLICATE KEY UPDATE` auf `api_anonymous_usage`; erhöht `call_count`, setzt `calls_today` per `IF(counter_date = CURDATE(), calls_today + 1, 1)` (Tageswechsel ohne Cronjob), schreibt `last_used_at = NOW()` und `last_model`. Best-effort in `try/catch` – ein Zählfehler darf die API-Anfrage nie brechen |
| `db.php` → `listApiKeyUsage(?PDO $pdo = null)` | liest `keys`, `anonymous`, `total_calls`, `calls_today`; Alter (`last_used_age`) und Restlaufzeit (`expires_in`) kommen per `TIMESTAMPDIFF` aus SQL, `usable` = aktiv und nicht abgelaufen, Besitzer per `LEFT JOIN users`. Der anonyme Eintrag erscheint erst ab `calls > 0` |
| `admin/load_stats.php` | Abschnitt `api` (in `try/catch`, mit leerem Standardwert) |
| `admin/index.php` | derselbe Abschnitt `api` aus `listApiKeyUsage()` für die serverseitig gerenderte Dashboard-Karte |
| `lib/openai_api.php` | `openaiAuthenticateApiRequest()` prüft nur noch (Bearer → SHA-256-Hash → `is_active` + `expires_at`) und schreibt **nicht** mehr selbst in `last_used_at` |

**Zeitzonen-Regel:** `last_used_at`, `expires_at` und `route_at` sind
Wanduhrzeit in der Zeitzone der Datenbank (`Europe/Berlin`), PHP rechnet in UTC.
Alter deshalb **immer** per `TIMESTAMPDIFF` in SQL bestimmen, nie durch Vergleich
mit `time()` in PHP.

### Laufende Anfrage eines Clients

| Stelle | Aufgabe |
|---|---|
| `index.php` | meldet im Heartbeat `route:{kind, model, endpoint_id}` – beim Start der Anfrage und nach der Antwort wieder `route: null` |
| `api/heartbeat.php` | nimmt die Route an und reicht sie weiter |
| `db.php` → `recordClientRoute()` | schreibt sie in die `route_*`-Spalten von `active_clients` |
| `api/chat.php` | verfeinert die Meldung mit dem tatsächlich gewählten Endpunkt |
| `lib/topology.php` → `topology_route_targets()` | übersetzt die Route in Knotenschlüssel und hängt sie als `route` an den Client-Knoten |

### Schema

| Tabelle | Felder für die Topologie |
|---|---|
| `api_keys` | `call_count`, `calls_today`, `counter_date`, `last_model`, `last_used_at` |
| `api_anonymous_usage` | eine Zeile (`id = 1`) mit `call_count`, `calls_today`, `counter_date`, `last_used_at`, `last_model` |
| `active_clients` | `route_kind`, `route_model`, `route_endpoint_id`, `route_at` |

Angelegt werden sie idempotent: Erstinstallation in `setup.php`, Migrationen in
`ensureRuntimeSchema()` in `db.php` (`ALTER TABLE … ADD COLUMN` in `try/catch`).

---

## 8. Interaktion

| Aktion | Ergebnis |
|---|---|
| Hover | Tooltip mit Titel, Zustand und Meldung |
| Klick auf einen Endpunkt/Zweig | Detailkarte im Canvas **neben** dem Knoten; der Weg bis zu allen Aufrufern (Clients **und** API-Schlüssel) wird hervorgehoben |
| Klick auf einen Client | Weg über die Clients-Wolke und LLMInt hinunter in die Endpunkt-Zweige; die Detailkarte eines Endpunkts öffnet sich **nicht**. Läuft auf dem Client gerade eine Anfrage, ist der Weg auf deren Zweig eingegrenzt und wird als Lauflicht gezeichnet |
| Klick auf einen API-Schlüssel | Weg in die Endpunkt-Zweige; die Clients bleiben zurückgesetzt |
| Klick auf eine Wolke (`clients`, `api`) | nur der eigene Zweig wird hervorgehoben; die Mitgliederliste erscheint im HTML-Seitenpanel |
| Legenden-Checkbox | blendet eine Knotenart aus (`kindFilter`), das Layout wird neu berechnet |
| „Nur Auffälligkeiten" | reduziert die Ansicht auf Knoten mit `warn`/`error` |
| 3D/2D, Drehen, Partikel, Labels, Zoom, Einpassen, Ansicht | reine Ansichtsoptionen, ohne Einfluss auf den Payload |
| „Aktualisieren" | sofortiger Poll; danach läuft das Intervall weiter |

Zustandswechsel zwischen zwei Polls werden als Effekt und als Protokollzeile
gemeldet (`diff()` + `log()`).

---

## 9. Anpassbarkeit

### Neue Knotenart ergänzen

1. `topology_kinds()` in `lib/topology.php` erweitern.
2. Knoten in `topology_build()` bauen (mit `topology_node()`).
3. Im Renderer `KIND_COLORS`, `KIND_LABELS` und `KIND_RADIUS` ergänzen;
   Legende und Kind-Filter ziehen automatisch nach (`buildKindLegend()`).
4. Bei Bedarf `drawGlyph()` um einen `case` erweitern (sonst wird ein Kreis
   gezeichnet) und – wenn der Knoten zur Aufruferseite gehört – `isCallerSide()`.
5. Tests: `tests/php/test_topology_helpers.php` (Liste der Arten),
   `tests/php/test_topology_build.php` (Knoten/Kanten), ggf.
   `tests/js/flow-topology.test.js` (Weg-Hervorhebung).

### Neue Wolke ergänzen

1. Knoten mit `'cloud' => ['title','empty','items']` ausstatten und als
   Gruppenknoten markieren (`'group' => true`, damit er aus `incidents` bleibt).
2. Im Renderer `isCloudNode()` um die neue Art erweitern und einen Wert in
   `CLOUD_RADIUS` setzen (Rückfall: `CLOUD_RADIUS_DEFAULT`).
3. Wenn die Wolke mehr Platz braucht: `SERVICE_RING_OFFSET` für die
   Nachbarbranchen erhöhen.
4. `usesPanel()` greift automatisch, weil der Knoten `cloud` trägt.

### Optik ändern

| Ziel | Stelle |
|---|---|
| Farbe einer Knotenart | `KIND_COLORS` |
| Name in Legende/Filter | `KIND_LABELS` |
| Blasengröße | `KIND_RADIUS`, `DEFAULT_KIND_RADIUS` |
| Farbe einer Detailkarten-Zeile | `FACT_TONES` (Tone kommt vom Server aus `topology_fact()`) |
| Kartengröße/-layout | `CARD` |
| Perspektive | `FOCAL`, `CAM_YAW`, `CAM_PITCH` |
| Ringabstände | `DEPTH_RADIUS`, `SERVICE_RING_OFFSET` |
| Wolkenradius/-streuung | `CLOUD_RADIUS`, `CLOUD_SPREAD`, `CLOUD_MARGIN` |
| Partikelmenge | `MAX_PARTICLES_PER_EDGE`, `topology_activity()` serverseitig |
| Farben der Zustände | `STATE_COLORS`/`STATE_RGB` und `topology_state_labels()` |

Wer `prefers-reduced-motion: reduce` gesetzt hat, bekommt Drehung und Partikel
standardmäßig ausgeschaltet (`reducedMotion` am Dateikopf); die Schaltflächen
bleiben nutzbar.

### Schwellwerte

| Schwellwert | Stelle | Bedeutung |
|---|---|---|
| 90 s Präsenz eines API-Schlüssels | `TOPOLOGY_API_KEY_PRESENCE_SECONDS` | „gerade aktiv" in der API-Wolke |
| 90 s Präsenz eines Clients | `listActiveClients()` (`db.php`) | Clients-Wolke; bewusst derselbe Wert |
| 4 Parallel-Slots je Endpunkt | Option `slot_limit` von `topology_build()` | Auslastungsbalken und `warn`-Zustand |
| 5 s Mindest-, 15 s Standardintervall | `MIN_REFRESH_INTERVAL`, `data-refresh-interval` | Poll-Frequenz |
| 4 s Ruhe vor automatischer Drehung | `IDLE_BEFORE_ROTATE` | Kameraverhalten |

### Optionen von `topology_build()`

`base_url`, `slot_limit`, `include_off` – siehe
[Abschnitt 5](#topology_buildarray-stats-array-options--array). Wer den Payload
aus einem Cache statt live erzeugt, übergibt dieselben `$stats` und erhält
denselben Payload; die Schicht bleibt frei von Nebenwirkungen.

### Hierarchie erweitern

Neue Zweige sind normale Kinder des `hub`: Knoten bauen, `$addEdge($hubKey,
$newKey, 'ok', true)` ergänzen und – falls der Zweig eine Aufruferseite ist –
`isCallerSide()` im Renderer mitziehen. Bestehende Kindknoten bleiben
unberührt, weil der Renderer die Hierarchie ausschließlich aus den Kanten
ableitet.

---

## 10. Tests und Dokumentationspflichten

| Suite | Datei | Prüft |
|---|---|---|
| PHP-Aufbau | `tests/php/test_topology_build.php` | Knoten, Kanten, Hub-Kinder, Zähler, Zustände, API-Wolke inkl. anonymer Eintrag, Minimalzustände |
| PHP-Helfer | `tests/php/test_topology_helpers.php` | `topology_kinds()`, Labels, Aggregation, Formatierung, `topology_age_label()` |
| PHP-Payloads | `tests/php/test_admin_payloads.php` | Dass `admin/load_stats.php` **und** `admin/index.php` dieselben Abschnitte an `topology_build()` übergeben |
| JS | `tests/js/flow-topology.test.js` | Ingest, Layout, Legende, Panels, Detailkarten, Weg-Hervorhebung (inkl. API-Schlüssel und Wolkenklick) |
| Fixtures | `tests/php/fixtures.php` | `topology_stats_fixture()` (mit API-Block) und `topology_stats_without_api()` |

Aufruf (Details in [`tests/README.md`](tests/README.md)):

```sh
./tests/run.sh        # PHP- und JavaScript-Tests
./tests/run.sh php    # nur tests/php/test_*.php
./tests/run.sh js     # nur tests/js/*.test.js
```

Bei Änderungen mitzuziehen:

1. neue/entfernte Knotenarten → `topology_kinds()`, `KIND_LABELS`,
   `tests/php/test_topology_helpers.php`;
2. Änderungen an Knoten- oder Kantenzahl → die Zählwerte in
   `tests/php/test_topology_build.php` und die Erwartungen in
   `tests/js/flow-topology.test.js`;
3. neue Zähler oder Zustände → Fixtures in `tests/php/fixtures.php`;
4. neuer Abschnitt in `$stats` → ihn in `ADMIN_PAYLOAD_SECTIONS` in
   `tests/php/test_admin_payloads.php` aufnehmen und in **beiden** Aufbaustellen
   (`admin/load_stats.php` und `admin/index.php`) füllen;
5. Doku: `README.md` (Abschnitt Admin-Dashboard), `description.md`,
   `docs/architecture.md`, `docs/functions.md`, `docs/agent_index.md` und diese
   Referenz.

---

## 11. Schnellreferenz

| Frage | Antwort |
|---|---|
| Wo entsteht der Payload? | `topology_build()` in `lib/topology.php` |
| Wer liefert die Daten? | `admin/load_stats.php` (Abschnitt `api` aus `listApiKeyUsage()`) |
| Wer zeichnet? | `mount()` in `assets/js/flow-topology.js` |
| Wo wird eingebettet? | `admin/index.php` (`#dashboard-card`) und `admin/topology.php` |
| Wie oft aktualisiert? | `data-refresh-interval`, Standard 15 s, Untergrenze 5 s |
| Wo zählen API-Aufrufe? | `recordApiCallUsage()` in `db.php`, aufgerufen aus `api/openai_common/chat_completions.php` |
| Wann gilt ein Schlüssel als aktiv? | `last_used_age <= TOPOLOGY_API_KEY_PRESENCE_SECONDS` (90 s) und Schlüssel nutzbar |
| Wie heißt der Eintrag ohne Key? | Knoten `api-key:anon`, Titel „ohne Key" |
| Welche Knoten haben eine Wolke? | `clients`, `api` (`isCloudNode()`, `CLOUD_RADIUS`) |
| Karte oder Panel? | `facts` → Karte, `cloud`/`members` → Panel, nie beides |
