<?php
/**
 * api/test_vector_store.php
 *
 * Admin-only endpoint that probes a vector-store backend (docvecwizard API or
 * local Milvus) with the values currently entered in the admin form – the
 * settings do not have to be saved first.
 *
 * Request (POST, JSON or form):
 *   mode = remote | local
 *   remote: docvec_api_url, docvec_api_username, docvec_api_password,
 *           docvec_api_timeout, docvec_api_verify_tls
 *   local:  milvus_url, milvus_metrics_url, milvus_token, milvus_timeout,
 *           milvus_collection
 *   optional: query – run a real search with the given text
 *
 * Response: { ok, message, status: {...}, hits?: [...] }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/vector_store.php';
requireAdminOrJson403();

$input = $_POST;
if ($input === []) {
    $raw = file_get_contents('php://input');
    $dec = json_decode((string) $raw, true);
    if (is_array($dec)) {
        $input = $dec;
    }
}

$mode = trim((string) ($input['mode'] ?? ''));
if (!in_array($mode, ['remote', 'local'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Ungültiger Modus.']);
    exit;
}

$allowed = $mode === 'remote'
    ? ['docvec_api_url', 'docvec_api_username', 'docvec_api_password', 'docvec_api_timeout', 'docvec_api_verify_tls']
    : ['milvus_url', 'milvus_metrics_url', 'milvus_token', 'milvus_timeout', 'milvus_collection'];

$overrides = ['vector_store_mode' => $mode];
foreach ($allowed as $key) {
    if (array_key_exists($key, $input)) {
        $value = trim((string) $input[$key]);
        // An empty password in the form means "keep the stored one".
        if ($key === 'docvec_api_password' && $value === '') {
            continue;
        }
        $overrides[$key] = $value;
    }
}
vectorSettingOverrides($overrides);

$status = $mode === 'remote' ? docvecStatus() : localVectorStatus();
$status['mode']  = $mode;
$status['label'] = vectorStoreLabel($mode);

$response = ['ok' => (bool) $status['online'], 'status' => $status];

if (!$status['online']) {
    $response['message'] = vectorStoreLabel($mode) . ': ' . ($status['detail'] ?: 'nicht erreichbar');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$parts = [vectorStoreLabel($mode) . ' erreichbar.'];
if ($status['documents'] !== null) {
    $parts[] = $status['documents'] . ' Dokument' . ($status['documents'] !== 1 ? 'e' : '');
}
if ($status['vectors'] !== null) {
    $parts[] = number_format((int) $status['vectors'], 0, ',', '.') . ' Vektoren';
}
if (!empty($status['collections'])) {
    $parts[] = 'Collections: ' . implode(', ', $status['collections']);
}
if (!empty($status['detail']) && $status['detail'] !== 'Verbunden') {
    $parts[] = $status['detail'];
}

$query = trim((string) ($input['query'] ?? ''));
if ($query !== '') {
    $search = $mode === 'remote' ? docvecSearch($query, 3) : localVectorSearch($query, 3);
    if ($search['ok']) {
        $parts[] = 'Testsuche: ' . count($search['hits']) . ' Treffer';
        $response['hits'] = array_map(static fn(array $h): array => [
            'filename' => $h['filename'],
            'score'    => round((float) $h['score'], 3),
            'preview'  => mb_substr((string) $h['text'], 0, 200),
        ], $search['hits']);
    } else {
        $response['ok'] = false;
        $parts[]        = 'Testsuche fehlgeschlagen: ' . $search['message'];
    }
}

$response['message'] = implode(' · ', $parts);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
