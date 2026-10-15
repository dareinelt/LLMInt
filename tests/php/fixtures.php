<?php
declare(strict_types=1);

/**
 * tests/php/fixtures.php
 *
 * Snapshot of an `admin/load_stats.php` response, used as the input of
 * topology_build(). It mirrors the real field names and value ranges (tokens,
 * jobs, SSH system stats, image/speech probing, active clients) so the topology
 * tests exercise the same branches the live dashboard takes.
 *
 * The LLM endpoints deliberately cover every code path of topology_build():
 *   - two endpoints that canonicalModelName() folds into one intelligence group
 *   - one endpoint with cached SSH system stats (extra facts)
 *   - one endpoint with SSH configured but no data yet
 *   - one deactivated endpoint (include_off option)
 */

/** @return array<string,mixed> */
function topology_stats_fixture(array $overrides = []): array
{
    $stats = [
        'ts' => 1750000000,

        'endpoints' => [
            [
                'id'                          => 1,
                'alias'                       => 'Gemma 27B #1',
                'base_url'                    => 'http://gemma-a:8080/v1',
                'default_model'               => 'gemma-3-27b-it-Q4_K_M',
                'is_active'                   => 1,
                'running'                     => 4,
                'today_jobs'                  => 812,
                'today_prompt_tokens'         => 1250000,
                'today_completion_tokens'     => 340000,
                'today_tokens'                => 1590000,
                'today_avg_tokens_per_second' => 42.7,
                'ssh_configured'              => true,
                'sys_stats'                   => [
                    'ok'          => true,
                    'ram_total'   => 68719476736,
                    'ram_used'    => 32212254720,
                    'cpu_load_1m' => 1.25,
                    'cpu_load_5m' => 0.9,
                    'cpu_temp'    => 71.5,
                    'fetched_at'  => '2025-06-15 10:00:00',
                ],
            ],
            [
                'id'                          => 2,
                'alias'                       => 'Gemma 27B #2',
                'base_url'                    => 'http://gemma-b:8080/v1',
                'default_model'               => '/models/gemma-3-27b-it-Q5_K_S.gguf',
                'is_active'                   => 1,
                'running'                     => 0,
                'today_jobs'                  => 120,
                'today_prompt_tokens'         => 45000,
                'today_completion_tokens'     => 12000,
                'today_tokens'                => 57000,
                'today_avg_tokens_per_second' => 0.0,
                'ssh_configured'              => false,
                'sys_stats'                   => null,
            ],
            [
                'id'                          => 3,
                'alias'                       => 'Qwen 32B',
                'base_url'                    => 'http://qwen:8080/v1',
                'default_model'               => 'qwen2.5-32b-instruct',
                'is_active'                   => 1,
                'running'                     => 0,
                'today_jobs'                  => 40,
                'today_prompt_tokens'         => 3000,
                'today_completion_tokens'     => 800,
                'today_tokens'                => 3800,
                'today_avg_tokens_per_second' => 12.5,
                'ssh_configured'              => true,
                'sys_stats'                   => ['ok' => false, 'fetched_at' => null],
            ],
            [
                'id'                          => 4,
                'alias'                       => 'Llama 8B (alt)',
                'base_url'                    => 'http://llama-old:8080/v1',
                'default_model'               => 'llama-3-8b',
                'is_active'                   => 0,
                'running'                     => 0,
                'today_jobs'                  => 3,
                'today_prompt_tokens'         => 100,
                'today_completion_tokens'     => 50,
                'today_tokens'                => 150,
                'today_avg_tokens_per_second' => 0.0,
                'ssh_configured'              => false,
                'sys_stats'                   => null,
            ],
        ],

        'searxng' => [
            'enabled'              => true,
            'base_url'             => 'http://searxng:8080',
            'running'              => 1,
            'today_jobs'           => 55,
            'avg_duration_seconds' => 1.4,
        ],

        'vector_store' => [
            'enabled'          => true,
            'mode'             => 'qdrant',
            'label'            => 'Qdrant',
            'base_url'         => 'http://qdrant:6333',
            'online'           => true,
            'documents'        => 1200,
            'vectors'          => 48000,
            'collection'       => 'llmint',
            'today_queries'    => 37,
            'today_errors'     => 0,
            'avg_duration_ms'  => 24.0,
        ],

        'speech_endpoints' => [
            ['id' => 1, 'alias' => 'Whisper large-v3', 'base_url' => 'http://speechint:8000', 'timeout' => 300, 'is_active' => 1],
        ],
        'speech_status' => [
            'configured'  => true,
            'url'         => 'http://speechint:8000',
            'ready'       => true,
            'loading'     => false,
            'reachable'   => true,
            'probed'      => true,
            'status'      => 'ready',
            'source'      => 'SpeechInt',
            'message'     => 'Modell geladen.',
            'retry_after' => null,
        ],

        'image_endpoints' => [
            ['id' => 1, 'alias' => 'ImageInt SDXL', 'base_url' => 'http://imageint:8000', 'timeout' => 120, 'is_active' => 1],
        ],
        'image_status' => [
            'configured'  => true,
            'url'         => 'http://imageint:8000',
            'ready'       => true,
            'loading'     => false,
            'reachable'   => true,
            'probed'      => true,
            'status'      => 'ready',
            'source'      => 'ImageInt',
            'message'     => 'Modell geladen.',
            'retry_after' => null,
        ],

        'clients' => [
            'current'   => 3,
            'today_min' => 0,
            'today_max' => 8,
            'today_avg' => 2.4,
            'list'      => [
                ['ip' => '10.0.0.11', 'hostname' => 'ws-anna', 'tabs' => 2],
                ['ip' => '10.0.0.12', 'hostname' => 'ws-bernd', 'tabs' => 1],
                ['ip' => '10.0.0.13', 'hostname' => 'ws-ein-sehr-langer-rechnername', 'tabs' => 3],
            ],
        ],

        'totals' => [
            'total_running'            => 4,
            'total_done_24h'           => 972,
            'total_done'               => 15234,
            'total_error'              => 3,
            'grand_prompt_tokens'      => 1250000,
            'grand_completion_tokens'  => 340000,
            'grand_tokens'             => 1590000,
        ],
    ];

    return array_replace_recursive($stats, $overrides);
}

/**
 * Fixture where two sessions report a running request (see recordClientRoute()
 * in db.php): ws-anna waits for the first LLM endpoint, the long-named machine
 * searches the web. ws-bernd stays idle.
 */
function topology_stats_with_running_requests(): array
{
    $stats = topology_stats_fixture();

    $stats['clients']['list'][0]['route_kind']        = 'llm';
    $stats['clients']['list'][0]['route_model']       = 'gemma-3-27b-it-Q4_K_M';
    $stats['clients']['list'][0]['route_endpoint_id'] = 1;
    $stats['clients']['list'][0]['route_seconds']     = 7;

    $stats['clients']['list'][2]['route_kind']        = 'search';
    $stats['clients']['list'][2]['route_model']       = '';
    $stats['clients']['list'][2]['route_endpoint_id'] = 0;
    $stats['clients']['list'][2]['route_seconds']     = 2;

    return $stats;
}

/** Fixture without the ImageInt endpoints (feature not configured). */
function topology_stats_without_image(): array
{
    $stats = topology_stats_fixture();
    $stats['image_endpoints'] = [];
    $stats['image_status'] = null;
    return $stats;
}

/** Fixture without the SpeechInt endpoints (feature not configured). */
function topology_stats_without_speech(): array
{
    $stats = topology_stats_fixture();
    $stats['speech_endpoints'] = [];
    $stats['speech_status'] = null;
    return $stats;
}

/** Fixture where every LLM endpoint is deactivated. */
function topology_stats_all_endpoints_off(): array
{
    $stats = topology_stats_fixture();
    foreach ($stats['endpoints'] as $index => $endpoint) {
        $stats['endpoints'][$index]['is_active'] = 0;
    }
    return $stats;
}

/** Fixture with an unreachable image endpoint, so the topology reports an error. */
function topology_stats_with_image_error(): array
{
    $stats = topology_stats_fixture();
    $stats['image_status']['ready']     = false;
    $stats['image_status']['reachable'] = false;
    $stats['image_status']['status']    = 'error';
    $stats['image_status']['message']   = 'Endpunkt nicht erreichbar.';
    return $stats;
}
