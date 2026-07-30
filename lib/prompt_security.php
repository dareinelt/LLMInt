<?php

/**
 * lib/prompt_security.php
 *
 * Multi-layer Prompt Security Engine for LLMInt.
 *
 * Pipeline:
 *   Stage 1 – Normalisation   : Unicode, zero-width chars, HTML, URL-decode, Base64 heuristic, whitespace
 *   Stage 2 – Rule matching   : Configurable signature DB (prompt_security_rules)
 *   Stage 3 – Risk scoring    : Aggregate score from matched rules; multiple hits accumulate
 *   Stage 4 – AI evaluation   : Optional secondary LLM classifier (prompt_security_ai_enabled)
 *   Stage 5 – Decision        : allow / warn / block based on score thresholds
 *
 * All operations are best-effort: if the module fails, behaviour is controlled by
 * the prompt_security_fail_open setting (default 1 = fail-open, i.e. allow the request).
 *
 * Settings used (all in the `settings` DB table):
 *   prompt_security_enabled          1 / 0
 *   prompt_security_mode             active | passive  (passive = log only, never block)
 *   prompt_security_score_limit      0–100  (score at which to block; default 81)
 *   prompt_security_warn_limit       0–100  (score at which to emit a warning; default 51)
 *   prompt_security_log              1 / 0  (whether to write to prompt_security_logs)
 *   prompt_security_log_input        full | anon | none  (what to store from user input)
 *   prompt_security_fail_open        1 / 0  (1 = allow on module error; 0 = block on error)
 *   prompt_security_ai_enabled       1 / 0
 *   prompt_security_ai_endpoint      URL of OpenAI-compatible API
 *   prompt_security_ai_model         model name for the AI classifier
 *   prompt_security_block_message    custom block reply text
 */

require_once __DIR__ . '/../db.php';

// ── In-request rule cache ─────────────────────────────────────────────────────

/**
 * Load active security rules from the DB, cached for the lifetime of this request.
 *
 * @return array<int,array{id:int,category:string,name:string,pattern:string,is_regex:int,severity:int}>
 */
function psLoadRules(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $cache = getDb()->query(
            "SELECT id, category, name, pattern, is_regex, severity
               FROM prompt_security_rules
              WHERE is_active = 1
              ORDER BY severity DESC, id ASC"
        )->fetchAll();
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

// ── Stage 1 – Normalisation ───────────────────────────────────────────────────

/**
 * Normalise a raw user input string before analysis.
 *
 * Steps:
 *   1. Remove zero-width / invisible Unicode characters.
 *   2. Unicode NFC normalisation (if intl extension available).
 *   3. Strip HTML tags.
 *   4. URL-decode (single pass).
 *   5. Detect and decode Base64 chunks (heuristic: token ≥ 20 chars matching [A-Za-z0-9+/=]).
 *   6. Collapse runs of whitespace to a single space.
 *   7. Lowercase for matching (returned separately – originals are kept for logging).
 *
 * Returns an array:
 *   'original'   – raw input (for logging)
 *   'normalised' – lowercase, de-obfuscated text (for matching)
 */
function psNormalise(string $input): array
{
    $original = $input;

    // 1. Zero-width / invisible characters (U+200B … U+200F, U+FEFF, U+2060, etc.)
    $input = preg_replace('/[\x{200B}-\x{200F}\x{FEFF}\x{2060}\x{00AD}]/u', '', $input) ?? $input;

    // 2. Unicode NFC normalisation
    if (function_exists('normalizer_normalize')) {
        $nfc = normalizer_normalize($input, Normalizer::FORM_C);
        if ($nfc !== false && $nfc !== null) {
            $input = $nfc;
        }
    }

    // 3. Strip HTML
    $input = strip_tags($input);

    // 4. URL-decode
    $input = rawurldecode($input);

    // 5. Base64 heuristic: replace standalone Base64-looking tokens ≥ 20 chars
    $input = preg_replace_callback(
        '/\b([A-Za-z0-9+\/]{20,}={0,2})\b/',
        static function (array $m): string {
            $decoded = base64_decode($m[1], true);
            // Only substitute when the decoded result is printable ASCII.
            if ($decoded !== false && preg_match('/^[\x20-\x7E]+$/', $decoded)) {
                return ' ' . $decoded . ' ';
            }
            return $m[0];
        },
        $input
    ) ?? $input;

    // 6. Collapse whitespace
    $input = trim(preg_replace('/\s+/u', ' ', $input) ?? $input);

    // 7. Lowercase for matching
    $normalised = mb_strtolower($input, 'UTF-8');

    return ['original' => $original, 'normalised' => $normalised];
}

// ── Stage 2 – Rule-based matching ────────────────────────────────────────────

/**
 * Match normalised input against all active rules.
 *
 * Returns an array of matched rule rows (each row as returned from the DB plus
 * an extra 'match_excerpt' key with the matched portion for logging).
 */
function psMatchRules(string $normalised): array
{
    $rules   = psLoadRules();
    $matched = [];

    foreach ($rules as $rule) {
        $pattern = (string) ($rule['pattern'] ?? '');
        if ($pattern === '') {
            continue;
        }

        $hit = false;
        $excerpt = '';

        if ((int) ($rule['is_regex'] ?? 0) === 1) {
            // Regex pattern – wrap in delimiters if not already wrapped.
            $regex = $pattern;
            if (!preg_match('/^[^a-zA-Z0-9\\\\ ]/', $pattern)) {
                // Not already delimited – add delimiters and flags.
                $regex = '/' . addcslashes($pattern, '/') . '/iu';
            }
            if (@preg_match($regex, $normalised, $m) === 1) {
                $hit     = true;
                $excerpt = $m[0];
            }
        } else {
            // Plain substring match (case-insensitive via lowercased input).
            $lp = mb_strtolower($pattern, 'UTF-8');
            if (mb_strpos($normalised, $lp) !== false) {
                $hit     = true;
                $excerpt = $pattern;
            }
        }

        if ($hit) {
            $matched[] = array_merge($rule, ['match_excerpt' => $excerpt]);
        }
    }

    return $matched;
}

// ── Stage 3 – Risk scoring ────────────────────────────────────────────────────

/**
 * Compute an aggregate risk score (0–100) from matched rules.
 *
 * Scoring model:
 *   - Start at 0.
 *   - For each match, add severity × 0.8 (first hit weighted fully, subsequent discounted).
 *   - Multiple hits in the same category add only 50 % of their marginal severity.
 *   - Cap at 100.
 */
function psComputeScore(array $matchedRules): int
{
    if (empty($matchedRules)) {
        return 0;
    }

    $score      = 0.0;
    $catHits    = [];

    foreach ($matchedRules as $rule) {
        $severity = max(0, min(100, (int) ($rule['severity'] ?? 50)));
        $cat      = (string) ($rule['category'] ?? '');

        if (!isset($catHits[$cat])) {
            // First match in this category: full weight.
            $score += $severity * 0.8;
            $catHits[$cat] = 1;
        } else {
            // Subsequent match in same category: 50 % marginal weight.
            $score += $severity * 0.4;
            $catHits[$cat]++;
        }
    }

    return min(100, (int) ceil($score));
}

// ── Stage 4 – AI-based evaluation (optional) ─────────────────────────────────

/**
 * Ask a small classifier LLM to evaluate the input.
 *
 * Returns one of: 'harmless' | 'prompt_injection' | 'jailbreak' | 'data_exfiltration' | 'unknown'
 * Returns null on any error or when AI evaluation is disabled.
 */
function psAiEvaluate(string $normalised): ?string
{
    if (getSetting('prompt_security_ai_enabled', '0') !== '1') {
        return null;
    }

    $endpoint = trim(getSetting('prompt_security_ai_endpoint', ''));
    $model    = trim(getSetting('prompt_security_ai_model', ''));

    if ($endpoint === '' || $model === '') {
        return null;
    }

    $systemPrompt = <<<'SYS'
You are a security classifier for a chat application. Analyse the user message below and respond with exactly ONE of the following labels:
harmless
prompt_injection
jailbreak
data_exfiltration
unknown

Respond with only the label, no explanation, no punctuation.
SYS;

    $aiPayload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $normalised],
        ],
        'stream'      => false,
        'temperature' => 0.0,
        'max_tokens'  => 10,
    ];

    try {
        $ch = curl_init(rtrim($endpoint, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($aiPayload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body    = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '' || $code !== 200) {
            return null;
        }

        $data  = json_decode((string) $body, true);
        $label = trim(strtolower((string) ($data['choices'][0]['message']['content'] ?? '')));

        $valid = ['harmless', 'prompt_injection', 'jailbreak', 'data_exfiltration', 'unknown'];
        return in_array($label, $valid, true) ? $label : 'unknown';
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Convert an AI label to an additional score boost.
 */
function psAiLabelToScore(string $label): int
{
    return match ($label) {
        'prompt_injection'  => 70,
        'jailbreak'         => 75,
        'data_exfiltration' => 80,
        'unknown'           => 20,
        default             => 0,  // 'harmless'
    };
}

// ── Stage 5 – Decision ────────────────────────────────────────────────────────

/**
 * Determine the security decision based on the aggregate score.
 *
 * @param int $score 0–100
 * @return string 'allow' | 'warn' | 'block'
 */
function psDecide(int $score): string
{
    $blockLimit = max(0, min(100, (int) getSetting('prompt_security_score_limit', '81')));
    $warnLimit  = max(0, min(100, (int) getSetting('prompt_security_warn_limit',  '51')));

    if ($score >= $blockLimit) {
        return 'block';
    }
    if ($score >= $warnLimit) {
        return 'warn';
    }
    return 'allow';
}

// ── Logging ───────────────────────────────────────────────────────────────────

/**
 * Persist a security event to prompt_security_logs.
 *
 * Respects prompt_security_log_input:
 *   full – store raw input
 *   anon – store only length and detected categories
 *   none – store no input at all
 */
function psLog(
    string $originalInput,
    int    $score,
    string $decision,
    array  $matchedRules,
    string $aiModel,
    ?int   $userId,
    string $sessionId,
    string $ipAddress
): void {
    if (getSetting('prompt_security_log', '1') !== '1') {
        return;
    }

    $logMode = getSetting('prompt_security_log_input', 'full');

    $storedInput = match ($logMode) {
        'none' => null,
        'anon' => '[' . mb_strlen($originalInput) . ' chars]',
        default => mb_substr($originalInput, 0, 4000),
    };

    $firstRule   = $matchedRules[0] ?? null;
    $matchedRuleId  = $firstRule ? (int) ($firstRule['id'] ?? 0) : null;
    $matchedCat  = $firstRule ? (string) ($firstRule['category'] ?? '') : '';

    try {
        getDb()->prepare(
            'INSERT INTO prompt_security_logs
             (user_id, session_id, ip_address, input_text, matched_rule, matched_cat, score, decision, ai_model)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId ?: null,
            $sessionId,
            $ipAddress,
            $storedInput,
            $matchedRuleId ?: null,
            $matchedCat,
            $score,
            $decision,
            $aiModel,
        ]);
    } catch (Throwable $e) {
        // Best-effort – never interrupt the request.
    }
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Evaluate a user message through the full security pipeline.
 *
 * Returns an associative array:
 *   'decision'  => 'allow' | 'warn' | 'block'
 *   'score'     => int 0–100
 *   'matched'   => array of matched rule rows
 *   'ai_label'  => string|null
 *   'message'   => string  (block/warn message for the user, empty when allowed)
 *
 * The function itself never throws; errors are silently swallowed and the result
 * defaults to 'allow' (fail-open) or 'block' (fail-closed) depending on the
 * prompt_security_fail_open setting.
 */
function psEvaluate(
    string $rawInput,
    ?int   $userId    = null,
    string $sessionId = '',
    string $ipAddress = ''
): array {
    $defaultResult = [
        'decision' => 'allow',
        'score'    => 0,
        'matched'  => [],
        'ai_label' => null,
        'message'  => '',
    ];

    // Check if the module is enabled.
    if (getSetting('prompt_security_enabled', '0') !== '1') {
        return $defaultResult;
    }

    try {
        // Stage 1 – Normalise.
        ['original' => $original, 'normalised' => $normalised] = psNormalise($rawInput);

        // Stage 2 – Rule matching.
        $matched = psMatchRules($normalised);

        // Stage 3 – Scoring.
        $score = psComputeScore($matched);

        // Stage 4 – Optional AI evaluation.
        $aiLabel  = null;
        $aiModel  = '';
        if (getSetting('prompt_security_ai_enabled', '0') === '1') {
            $aiLabel = psAiEvaluate($normalised);
            $aiModel = trim(getSetting('prompt_security_ai_model', ''));
            if ($aiLabel !== null && $aiLabel !== 'harmless') {
                // Blend AI score: if AI is alarmed, boost the score.
                $aiBoost = psAiLabelToScore($aiLabel);
                $score   = min(100, (int) ceil(($score * 0.7) + ($aiBoost * 0.3)));
            }
        }

        // Stage 5 – Decision.
        $mode     = getSetting('prompt_security_mode', 'active');
        $decision = psDecide($score);

        // In passive mode, never actually block – just log.
        if ($mode === 'passive' && $decision === 'block') {
            $decision = 'warn';
        }

        // Log.
        psLog($original, $score, $decision, $matched, $aiModel, $userId, $sessionId, $ipAddress);

        // Build response message.
        $message = '';
        if ($decision === 'block') {
            $message = trim(getSetting('prompt_security_block_message', ''));
            if ($message === '') {
                $message = 'Ihre Anfrage wurde aus Sicherheitsgründen abgelehnt.';
            }
        } elseif ($decision === 'warn') {
            $message = 'Hinweis: Ihre Anfrage enthält möglicherweise sicherheitsrelevante Muster.';
        }

        return [
            'decision' => $decision,
            'score'    => $score,
            'matched'  => $matched,
            'ai_label' => $aiLabel,
            'message'  => $message,
        ];
    } catch (Throwable $e) {
        // Log the module error to app_logs and apply fail-open/closed policy.
        try {
            writeLog('error', 'Prompt-Security-Modul Fehler: ' . $e->getMessage());
        } catch (Throwable $_) {}

        $failOpen = getSetting('prompt_security_fail_open', '1') === '1';
        if ($failOpen) {
            return $defaultResult;
        }

        // Fail-closed: block the request.
        return [
            'decision' => 'block',
            'score'    => 100,
            'matched'  => [],
            'ai_label' => null,
            'message'  => trim(getSetting('prompt_security_block_message', ''))
                ?: 'Ihre Anfrage wurde aus Sicherheitsgründen abgelehnt.',
        ];
    }
}

/**
 * Purge prompt_security_logs entries older than the configured retention period.
 * Called opportunistically (~1 % of requests).
 */
function psPurgeLogs(): void
{
    try {
        $days = max(1, (int) getSetting('prompt_security_log_retention_days', '30'));
        getDb()->prepare(
            'DELETE FROM prompt_security_logs WHERE created_at < NOW() - INTERVAL ? DAY'
        )->execute([$days]);
    } catch (Throwable $e) {
        // Best-effort
    }
}
