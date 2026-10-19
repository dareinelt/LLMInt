<?php
declare(strict_types=1);

/**
 * tests/php/test_image_health.php
 *
 * Unit tests for the tolerant ImageInt component/progress normalisation that
 * feeds the download bars in the admin area. ImageInt's current /v1/health
 * reports only `state` and `since_seconds`, so the important guarantee is that
 * the parser degrades to an indeterminate bar instead of failing, and that it
 * starts producing a percentage as soon as an endpoint reports bytes, files or
 * a percentage.
 *
 * Deliberately not covered here (they need a live endpoint): imageIntHealth(),
 * imageIntReady(), imageIntModels() and the HTTP transport.
 *
 * Run from the repository root:  php tests/php/test_image_health.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/image_generation.php';

// ── imageIntNumeric() ─────────────────────────────────────────────────────────

test_case('imageIntNumeric() akzeptiert Zahlen, Komma und Prozentzeichen', function (): void {
    assert_same(42.0, imageIntNumeric(42));
    assert_same(42.5, imageIntNumeric(42.5));
    assert_same(42.5, imageIntNumeric('42,5'));
    assert_same(42.5, imageIntNumeric('42,5 %'));
    assert_same(42.5, imageIntNumeric(' 42.5% '));
    assert_same(1234.5, imageIntNumeric("1 234,5"));
    assert_same(1234.5, imageIntNumeric("1\u{00A0}234,5"));
    assert_same(1234.5, imageIntNumeric("1\u{202F}234,5"));
});

test_case('imageIntNumeric() liefert null für alles Unbrauchbare', function (): void {
    assert_same(null, imageIntNumeric(null));
    assert_same(null, imageIntNumeric(''));
    assert_same(null, imageIntNumeric('gleich fertig'));
    assert_same(null, imageIntNumeric(true));
    assert_same(null, imageIntNumeric([]));
    assert_same(null, imageIntNumeric(NAN));
});

// ── imageIntStateLabel() ──────────────────────────────────────────────────────

test_case('imageIntStateLabel() übersetzt die ImageInt-Zustände', function (): void {
    assert_same('bereit', imageIntStateLabel('ready'));
    assert_same('lädt', imageIntStateLabel('LOADING'));
    assert_same('startet', imageIntStateLabel('starting'));
    assert_same('untätig', imageIntStateLabel('idle'));
    assert_same('Fehler', imageIntStateLabel('error'));
    assert_same('nicht erreichbar', imageIntStateLabel('unreachable'));
    assert_same('nicht konfiguriert', imageIntStateLabel('unconfigured'));
});

test_case('imageIntStateLabel() fällt auf den Servertext oder den Rohwert zurück', function (): void {
    assert_same('Servertext', imageIntStateLabel('mystery', 'Servertext'));
    assert_same('mystery', imageIntStateLabel('mystery'));
    assert_same('unbekannt', imageIntStateLabel(''));
});

// ── imageIntComponentLabel() ──────────────────────────────────────────────────

test_case('imageIntComponentLabel() bevorzugt das Label des Servers', function (): void {
    assert_same('Eigener Name', imageIntComponentLabel('enhancer', ['label' => 'Eigener Name']));
    assert_same('Prompt-Enhancer', imageIntComponentLabel('enhancer'));
    assert_same('Bildmodell', imageIntComponentLabel('image'));
    assert_same('Bildmodell', imageIntComponentLabel('renderer'));
    assert_same('vae', imageIntComponentLabel('vae'));
    assert_same('Komponente', imageIntComponentLabel(''));
});

// ── imageIntComponentProgress() ───────────────────────────────────────────────

test_case('imageIntComponentProgress() bleibt ohne Zahlen unbestimmt', function (): void {
    $progress = imageIntComponentProgress(['state' => 'loading', 'since_seconds' => 12]);

    assert_same(false, $progress['known']);
    assert_same(null, $progress['percent']);
    assert_same(null, $progress['bytes_downloaded']);
    assert_same(null, $progress['bytes_total']);
    assert_same(null, $progress['files_downloaded']);
    assert_same(null, $progress['files_total']);
    assert_same('', $progress['current_file']);
    assert_same(null, $progress['eta_seconds']);
});

test_case('imageIntComponentProgress() setzt eine bereite Komponente auf 100 %', function (): void {
    $ready = imageIntComponentProgress(['state' => 'ready']);
    assert_same(true, $ready['known']);
    assert_same(100.0, $ready['percent']);

    $flagged = imageIntComponentProgress(['ready' => true, 'state' => 'starting']);
    assert_same(100.0, $flagged['percent'], 'ready=true schlägt den Zustand');
});

test_case('imageIntComponentProgress() übernimmt ein geliefertes Prozent', function (): void {
    assert_close(42.5, imageIntComponentProgress(['progress' => ['percent' => '42,5 %']])['percent']);
    assert_close(7.0, imageIntComponentProgress(['percent' => 7])['percent']);
    assert_close(33.0, imageIntComponentProgress(['download_percent' => '33'])['percent']);
});

test_case('imageIntComponentProgress() rechnet Bytes in Prozent um', function (): void {
    $progress = imageIntComponentProgress([
        'progress' => ['bytes_downloaded' => 250, 'bytes_total' => 1000],
    ]);

    assert_same(true, $progress['known']);
    assert_close(25.0, $progress['percent']);
    assert_same(250, $progress['bytes_downloaded']);
    assert_same(1000, $progress['bytes_total']);
});

test_case('imageIntComponentProgress() rechnet Dateien in Prozent um', function (): void {
    $progress = imageIntComponentProgress([
        'download' => ['files_downloaded' => 3, 'files_total' => 4, 'current_file' => 'model-00003.safetensors'],
    ]);

    assert_close(75.0, $progress['percent']);
    assert_same(3, $progress['files_downloaded']);
    assert_same(4, $progress['files_total']);
    assert_same('model-00003.safetensors', $progress['current_file']);
});

test_case('imageIntComponentProgress() liest auch flache Felder und die ETA', function (): void {
    $progress = imageIntComponentProgress([
        'state'            => 'loading',
        'bytes_downloaded' => '1 GB',
        'bytes_total'      => 4 * 1024 * 1024 * 1024,
        'eta_seconds'      => '90',
    ]);

    // "1 GB" ist keine reine Zahl und wird ignoriert, die Bytes bleiben also offen.
    assert_same(false, $progress['known']);
    assert_same(4 * 1024 * 1024 * 1024, $progress['bytes_total']);
    assert_same(90, $progress['eta_seconds']);

    $flat = imageIntComponentProgress(['bytes_downloaded' => 512, 'bytes_total' => 2048]);
    assert_close(25.0, $flat['percent']);
});

test_case('imageIntComponentProgress() begrenzt Prozentwerte auf 0 bis 100', function (): void {
    assert_same(100.0, imageIntComponentProgress(['progress' => ['percent' => 150]])['percent']);
    assert_same(0.0, imageIntComponentProgress(['progress' => ['percent' => -5]])['percent']);
});

test_case('imageIntComponentProgress() ignoriert eine Division durch null', function (): void {
    $progress = imageIntComponentProgress(['bytes_downloaded' => 10, 'bytes_total' => 0]);
    assert_same(false, $progress['known']);
    assert_same(null, $progress['percent']);
});

test_case('imageIntComponentProgress() verschachteltes Prozent hat Vorrang vor Bytes', function (): void {
    $progress = imageIntComponentProgress([
        'percent'  => 99,
        'progress' => ['percent' => 10, 'bytes_downloaded' => 1, 'bytes_total' => 10],
    ]);

    assert_close(10.0, $progress['percent']);
});

// ── imageIntParseComponents() ─────────────────────────────────────────────────

test_case('imageIntParseComponents() normalisiert den components-Block', function (): void {
    $components = imageIntParseComponents([
        'components' => [
            'enhancer' => [
                'label'         => 'Prompt-Enhancer',
                'state'         => 'READY',
                'ready'         => true,
                'model'         => 'Qwen3-4B',
                'since_seconds' => 12,
                'load_seconds'  => '3,5',
                'loaded_at'     => '2024-05-01T10:00:00Z',
            ],
            'image' => [
                'state'     => 'loading',
                'transient' => true,
                'message'   => 'lädt Gewichte',
                'progress'  => ['percent' => 40],
            ],
        ],
    ]);

    assert_count(2, $components);
    assert_keys(['enhancer', 'image'], $components);

    $enhancer = $components['enhancer'];
    assert_same('enhancer', $enhancer['component']);
    assert_same('Prompt-Enhancer', $enhancer['label']);
    assert_same('ready', $enhancer['state'], 'Zustand wird kleingeschrieben');
    assert_same('bereit', $enhancer['state_label']);
    assert_same(true, $enhancer['ok']);
    assert_same(false, $enhancer['transient']);
    assert_same('Qwen3-4B', $enhancer['model']);
    assert_same(12.0, $enhancer['since_seconds']);
    assert_same(3.5, $enhancer['load_seconds']);
    assert_same('2024-05-01T10:00:00Z', $enhancer['loaded_at']);
    assert_same(100.0, $enhancer['progress']['percent'], 'ready wird zu 100 %');

    $image = $components['image'];
    assert_same('Bildmodell', $image['label']);
    assert_same('lädt', $image['state_label']);
    assert_same(false, $image['ok']);
    assert_same(true, $image['transient']);
    assert_same('lädt Gewichte', $image['message']);
    assert_close(40.0, $image['progress']['percent']);
});

test_case('imageIntParseComponents() versteht die flachen enhancer-/renderer-Blöcke', function (): void {
    $components = imageIntParseComponents([
        'enhancer' => ['state' => 'loading', 'since_seconds' => 5],
        'renderer' => ['state' => 'ready', 'ready' => true],
    ]);

    assert_count(2, $components);
    assert_same('Prompt-Enhancer', $components['enhancer']['label']);
    assert_same(true, $components['enhancer']['transient'], 'loading gilt als transient');
    assert_same('Bildmodell', $components['renderer']['label']);
    assert_same('bereit', $components['renderer']['state_label']);
});

test_case('imageIntParseComponents() lässt den ersten Treffer eines Keys gewinnen', function (): void {
    $components = imageIntParseComponents([
        'components' => ['image' => ['state' => 'ready', 'ready' => true]],
        'models'     => ['image' => ['state' => 'error']],
    ]);

    assert_count(1, $components);
    assert_same('bereit', $components['image']['state_label']);
});

test_case('imageIntParseComponents() ignoriert Nicht-Arrays und liefert bei leerer Antwort []', function (): void {
    assert_same([], imageIntParseComponents([]));
    assert_same([], imageIntParseComponents(['components' => 'ready']));

    $components = imageIntParseComponents([
        'components' => ['image' => 'ready', 'enhancer' => ['state' => 'idle']],
    ]);

    assert_count(1, $components);
    assert_same('untätig', $components['enhancer']['state_label']);
});

test_case('imageIntParseComponents() übernimmt ein eigenes state_label', function (): void {
    $components = imageIntParseComponents([
        'components' => ['image' => ['state' => 'downloading', 'state_label' => 'wird geladen']],
    ]);

    assert_same('wird geladen', $components['image']['state_label']);
    assert_same(false, $components['image']['transient'], 'unbekannter Zustand ist nicht transient');
});

exit(test_summary('ImageInt-Health'));
