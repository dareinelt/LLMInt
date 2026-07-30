<?php

/**
 * api/embedding.php
 *
 * Embedding helper functions for the Hybrid-RAG pipeline.
 *
 * Provides:
 *  - Endpoint selection for embedding servers (OpenAI-compatible).
 *  - Calling the /v1/embeddings endpoint to obtain vector embeddings.
 *  - Cosine-similarity computation between two float vectors.
 *  - Optional in-database caching of query embeddings.
 *  - Logging of embedding requests for monitoring.
 *
 * Compatible with: OpenAI, LM Studio, Ollama (via /v1/embeddings),
 * and any other OpenAI-compatible embedding API.
 *
 * Configuration (read via getSetting()):
 *   embedding_enabled       – '0' or '1'
 *   embedding_cache_enabled – '0' (disabled) | '1' (database cache)
 */

require_once __DIR__ . '/../db.php';

// ── Endpoint management ───────────────────────────────────────────────────────

/**
 * Pick a random active embedding endpoint from the database.
 *
 * @return array|null Endpoint row or null when none is available.
 */
function pickEmbeddingEndpoint(): ?array
{
    try {
        $stmt = getDb()->query(
            'SELECT id, alias, base_url, model, api_key, timeout
               FROM embedding_endpoints
              WHERE is_active = 1
              ORDER BY RAND()
              LIMIT 1'
        );
        $row = $stmt->fetch();
        return ($row !== false) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Returns true when at least one active embedding endpoint is configured.
 */
function hasActiveEmbeddingEndpoint(): bool
{
    try {
        return (int) getDb()->query(
            'SELECT COUNT(*) FROM embedding_endpoints WHERE is_active = 1'
        )->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// ── Embedding generation ──────────────────────────────────────────────────────

/**
 * Call an OpenAI-compatible /v1/embeddings endpoint and return the embedding
 * vector as an array of floats, or null on any error.
 *
 * @param string $text     Text to embed.
 * @param array  $endpoint Endpoint row from embedding_endpoints.
 *
 * @return float[]|null
 */
function generateEmbedding(string $text, array $endpoint): ?array
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $baseUrl = rtrim((string) ($endpoint['base_url'] ?? ''), '/');
    $model   = (string) ($endpoint['model'] ?? '');
    $apiKey  = (string) ($endpoint['api_key'] ?? '');
    $timeout = max(5, (int) ($endpoint['timeout'] ?? 60));

    if ($baseUrl === '' || $model === '') {
        return null;
    }

    $payload = json_encode(['model' => $model, 'input' => $text]);

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $startMs = (int) round(microtime(true) * 1000);
    $ch = curl_init($baseUrl . '/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $body    = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $durationMs = (int) round(microtime(true) * 1000) - $startMs;

    if ($curlErr !== '' || $code !== 200 || $body === false) {
        logEmbeddingRequest('chunk', $model, $durationMs, null, false, 'error');
        return null;
    }

    $data = json_decode((string) $body, true);
    $embedding = $data['data'][0]['embedding'] ?? null;

    if (!is_array($embedding) || empty($embedding)) {
        logEmbeddingRequest('chunk', $model, $durationMs, null, false, 'error');
        return null;
    }

    // Ensure all values are floats.
    $embedding = array_map('floatval', $embedding);
    logEmbeddingRequest('chunk', $model, $durationMs, null, false, 'ok');

    return $embedding;
}

/**
 * Convenience wrapper: pick an endpoint automatically, generate an embedding,
 * and return the vector. Returns null when no endpoint is available or on error.
 *
 * @param string $text Text to embed.
 * @param string $type Log type: 'chunk' | 'query'.
 *
 * @return float[]|null
 */
function generateEmbeddingAuto(string $text, string $type = 'query'): ?array
{
    $endpoint = pickEmbeddingEndpoint();
    if ($endpoint === null) {
        return null;
    }

    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $baseUrl = rtrim((string) ($endpoint['base_url'] ?? ''), '/');
    $model   = (string) ($endpoint['model'] ?? '');
    $apiKey  = (string) ($endpoint['api_key'] ?? '');
    $timeout = max(5, (int) ($endpoint['timeout'] ?? 60));

    $payload = json_encode(['model' => $model, 'input' => $text]);

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $startMs = (int) round(microtime(true) * 1000);
    $ch = curl_init($baseUrl . '/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $body    = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $durationMs = (int) round(microtime(true) * 1000) - $startMs;

    if ($curlErr !== '' || $code !== 200 || $body === false) {
        logEmbeddingRequest($type, $model, $durationMs, null, false, 'error');
        writeLog('warning', 'Embedding-Anfrage fehlgeschlagen: ' . ($curlErr !== '' ? $curlErr : 'HTTP ' . $code));
        return null;
    }

    $data = json_decode((string) $body, true);
    $embedding = $data['data'][0]['embedding'] ?? null;

    if (!is_array($embedding) || empty($embedding)) {
        logEmbeddingRequest($type, $model, $durationMs, null, false, 'error');
        return null;
    }

    $embedding = array_map('floatval', $embedding);
    logEmbeddingRequest($type, $model, $durationMs, null, false, 'ok');

    return $embedding;
}

// ── Similarity ────────────────────────────────────────────────────────────────

/**
 * Compute cosine similarity between two float vectors.
 * Returns a value between -1.0 and 1.0 (1.0 = identical direction).
 * Returns 0.0 when either vector is the zero vector.
 *
 * @param float[] $a
 * @param float[] $b
 */
function cosineSimilarity(array $a, array $b): float
{
    $len = min(count($a), count($b));
    if ($len === 0) {
        return 0.0;
    }

    $dot  = 0.0;
    $magA = 0.0;
    $magB = 0.0;

    for ($i = 0; $i < $len; $i++) {
        $ai   = (float) $a[$i];
        $bi   = (float) $b[$i];
        $dot  += $ai * $bi;
        $magA += $ai * $ai;
        $magB += $bi * $bi;
    }

    $denom = sqrt($magA) * sqrt($magB);
    if ($denom < 1e-10) {
        return 0.0;
    }

    return (float) max(-1.0, min(1.0, $dot / $denom));
}

/**
 * Decode a stored JSON embedding string into a float array.
 * Returns null when the input is empty or not valid JSON.
 *
 * @param string|null $json
 * @return float[]|null
 */
function embeddingFromJson(?string $json): ?array
{
    if ($json === null || $json === '') {
        return null;
    }
    $arr = json_decode($json, true);
    if (!is_array($arr) || empty($arr)) {
        return null;
    }
    return array_map('floatval', $arr);
}

// ── Query embedding cache ─────────────────────────────────────────────────────

/**
 * Look up a cached embedding for the given query text and model.
 * Returns the embedding array on cache hit, or null on miss.
 *
 * @return float[]|null
 */
function getCachedQueryEmbedding(string $query, string $model): ?array
{
    if (getSetting('embedding_cache_enabled', '0') !== '1') {
        return null;
    }

    $hash = hash('sha256', $model . '||' . $query);
    try {
        $stmt = getDb()->prepare(
            'SELECT embedding FROM embedding_cache WHERE query_hash = ? AND model = ? LIMIT 1'
        );
        $stmt->execute([$hash, $model]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return embeddingFromJson((string) ($row['embedding'] ?? ''));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Persist an embedding in the cache for future reuse.
 *
 * @param float[] $embedding
 */
function setCachedQueryEmbedding(string $query, string $model, array $embedding): void
{
    if (getSetting('embedding_cache_enabled', '0') !== '1') {
        return;
    }

    $hash = hash('sha256', $model . '||' . $query);
    try {
        getDb()->prepare(
            'INSERT INTO embedding_cache (query_hash, model, embedding)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), created_at = NOW(3)'
        )->execute([$hash, $model, json_encode($embedding)]);

        // Purge old cache entries opportunistically (~5 % of requests).
        if (mt_rand(1, 20) === 1) {
            $ttlDays = max(1, (int) getSetting('embedding_cache_ttl_days', '7'));
            getDb()->prepare(
                'DELETE FROM embedding_cache WHERE created_at < NOW() - INTERVAL ? DAY'
            )->execute([$ttlDays]);
        }
    } catch (Throwable $e) {
        // Best-effort – caching must never break the request flow.
    }
}

// ── Monitoring ────────────────────────────────────────────────────────────────

/**
 * Persist one embedding-request metric row in embedding_logs.
 * Best-effort: never throws.
 *
 * @param string      $type       'chunk' | 'query' | 'rerank'
 * @param string      $model      Model used.
 * @param int         $durationMs Wall-clock time of the API call.
 * @param float|null  $similarity Cosine similarity of the best result (for 'query').
 * @param bool        $cacheHit   Whether this request was served from cache.
 * @param string      $status     'ok' | 'error'
 */
function logEmbeddingRequest(
    string $type,
    string $model,
    int    $durationMs,
    ?float $similarity,
    bool   $cacheHit,
    string $status
): void {
    try {
        getDb()->prepare(
            'INSERT INTO embedding_logs (type, model, duration_ms, similarity, cache_hit, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$type, $model, $durationMs, $similarity, $cacheHit ? 1 : 0, $status]);
    } catch (Throwable $e) {
        // Best-effort
    }
}

// ── Reranking ─────────────────────────────────────────────────────────────────

/**
 * Rerank candidate document chunks using an OpenAI-compatible reranker API.
 *
 * The reranker endpoint is expected to accept:
 *   POST /v1/rerank
 *   { "model": "...", "query": "...", "documents": ["text1", "text2", ...] }
 *
 * And respond with:
 *   { "results": [{ "index": 0, "relevance_score": 0.95 }, ...] }
 *
 * Falls back gracefully: returns the original array unchanged when the
 * reranker is unavailable, misconfigured, or returns an error.
 *
 * @param string  $query      The user's search query.
 * @param array[] $candidates Array of result rows, each with 'content' and other keys.
 * @param int     $topK       Maximum number of results to return after reranking.
 *
 * @return array[] Reranked (and possibly truncated) candidates.
 */
function rerankDocuments(string $query, array $candidates, int $topK = 5): array
{
    if (empty($candidates)) {
        return $candidates;
    }

    $endpoint = trim(getSetting('reranker_endpoint', ''));
    $model    = trim(getSetting('reranker_model', ''));
    $timeout  = max(5, (int) getSetting('reranker_timeout', '30'));

    if ($endpoint === '' || $model === '') {
        // No reranker configured – return the top K as-is.
        return array_slice($candidates, 0, $topK);
    }

    $documents = array_map(static fn(array $c): string => (string) ($c['content'] ?? ''), $candidates);

    $payload = json_encode([
        'model'     => $model,
        'query'     => $query,
        'documents' => $documents,
    ]);

    $startMs = (int) round(microtime(true) * 1000);
    $ch = curl_init(rtrim($endpoint, '/') . '/v1/rerank');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $body    = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $durationMs = (int) round(microtime(true) * 1000) - $startMs;

    if ($curlErr !== '' || $code !== 200 || $body === false) {
        logEmbeddingRequest('rerank', $model, $durationMs, null, false, 'error');
        writeLog('warning', 'Reranker nicht erreichbar oder Fehler (HTTP ' . $code . '): ' . $curlErr);
        // Graceful fallback: return unchanged top K.
        return array_slice($candidates, 0, $topK);
    }

    $data = json_decode((string) $body, true);
    $results = $data['results'] ?? null;

    if (!is_array($results) || empty($results)) {
        logEmbeddingRequest('rerank', $model, $durationMs, null, false, 'error');
        return array_slice($candidates, 0, $topK);
    }

    // Sort by relevance_score descending.
    usort($results, static function (array $a, array $b): int {
        return ($b['relevance_score'] ?? 0.0) <=> ($a['relevance_score'] ?? 0.0);
    });

    $reranked = [];
    foreach (array_slice($results, 0, $topK) as $result) {
        $idx = (int) ($result['index'] ?? -1);
        if (isset($candidates[$idx])) {
            $row = $candidates[$idx];
            $row['rerank_score'] = (float) ($result['relevance_score'] ?? 0.0);
            $reranked[] = $row;
        }
    }

    logEmbeddingRequest('rerank', $model, $durationMs, null, false, 'ok');
    writeLog('info', 'Reranking abgeschlossen (' . count($reranked) . ' Ergebnisse, ' . $durationMs . ' ms).');

    return $reranked ?: array_slice($candidates, 0, $topK);
}

// ── Chunk embedding persistence ───────────────────────────────────────────────

/**
 * Generate and store embeddings for all chunks of a document upload.
 *
 * Called from upload_document.php after chunking.
 * Errors are silently ignored so that upload never fails because of
 * an unavailable embedding server.
 *
 * @param PDO $db       Database connection.
 * @param int $uploadId document_uploads.id
 */
function generateAndStoreChunkEmbeddings(PDO $db, int $uploadId): void
{
    // Skip if embedding is globally disabled.
    if (getSetting('embedding_enabled', '0') !== '1') {
        $db->prepare(
            "UPDATE document_uploads SET embedding_status = 'skipped' WHERE id = ?"
        )->execute([$uploadId]);
        return;
    }

    $endpoint = pickEmbeddingEndpoint();
    if ($endpoint === null) {
        writeLog('warning', 'Embedding-Erzeugung für Upload ' . $uploadId . ' übersprungen: kein aktiver Endpunkt.');
        $db->prepare(
            "UPDATE document_uploads SET embedding_status = 'error' WHERE id = ?"
        )->execute([$uploadId]);
        return;
    }

    // Mark as processing.
    $db->prepare(
        "UPDATE document_uploads SET embedding_status = 'processing' WHERE id = ?"
    )->execute([$uploadId]);

    $model = (string) ($endpoint['model'] ?? '');

    // Load all chunks for this upload.
    $stmt = $db->prepare(
        'SELECT id, chunk_text FROM document_chunks WHERE document_upload_id = ? ORDER BY chunk_index ASC'
    );
    $stmt->execute([$uploadId]);
    $chunks = $stmt->fetchAll();

    if (empty($chunks)) {
        $db->prepare(
            "UPDATE document_uploads SET embedding_status = 'skipped' WHERE id = ?"
        )->execute([$uploadId]);
        return;
    }

    $updateChunk = $db->prepare(
        'UPDATE document_chunks
            SET embedding = ?, embedding_dimension = ?, embedding_model = ?, embedding_created_at = NOW(3)
          WHERE id = ?'
    );

    $errors  = 0;
    $success = 0;

    foreach ($chunks as $chunk) {
        $chunkId   = (int) $chunk['id'];
        $chunkText = (string) ($chunk['chunk_text'] ?? '');

        $embedding = generateEmbedding($chunkText, $endpoint);
        if ($embedding === null) {
            $errors++;
            continue;
        }

        $dim = count($embedding);
        $updateChunk->execute([
            json_encode($embedding),
            $dim,
            $model,
            $chunkId,
        ]);
        $success++;
    }

    // Update embedding_status on the upload.
    $status = ($errors === 0) ? 'done' : (($success === 0) ? 'error' : 'done');
    $db->prepare(
        "UPDATE document_uploads SET embedding_status = ? WHERE id = ?"
    )->execute([$status, $uploadId]);

    if ($errors > 0) {
        writeLog('warning', 'Embedding-Erzeugung für Upload ' . $uploadId . ': ' . $success . ' erfolgreich, ' . $errors . ' Fehler.');
    } else {
        writeLog('info', 'Embedding-Erzeugung für Upload ' . $uploadId . ' abgeschlossen (' . $success . ' Chunks).');
    }
}
