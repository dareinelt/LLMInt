<?php
declare(strict_types=1);

/**
 * tests/php/test_reverse_proxy.php
 *
 * Unit tests for the pure helpers of lib/reverse_proxy.php: trusted-proxy
 * parsing, CIDR matching, forwarded-header handling, SSO header naming and the
 * public base-URL computation.
 *
 * The file is loaded after lib/reverse_proxy.php ran reverseProxyApply() once;
 * under the CLI SAPI that call returns immediately, so the tests below drive the
 * functions with a hand-built $_SERVER instead. Environment variables are set
 * and restored around each case.
 *
 * Run from the repository root:  php tests/php/test_reverse_proxy.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/reverse_proxy.php';

$repoRoot = str_replace('\\', '/', (string) realpath(__DIR__ . '/../..'));

/**
 * Run $fn with an environment variable set to $value and restore it afterwards.
 *
 * @param mixed $fn
 * @return mixed
 */
function withEnvVar(string $name, ?string $value, $fn)
{
    $previous = getenv($name);
    if ($value === null) {
        putenv($name);
    } else {
        putenv($name . '=' . $value);
    }
    try {
        return $fn();
    } finally {
        if ($previous === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $previous);
        }
    }
}

/**
 * Run $fn with the given $_SERVER entries and restore the previous values.
 *
 * @param array<string,string> $overrides
 * @param mixed $fn
 * @return mixed
 */
function withServer(array $overrides, $fn)
{
    $backup = $_SERVER;
    $_SERVER = array_merge($backup, $overrides);
    try {
        return $fn();
    } finally {
        $_SERVER = $backup;
    }
}

// ── reverseProxyTrustedEntries() ──────────────────────────────────────────────

test_case('reverseProxyTrustedEntries() liefert ohne TRUSTED_PROXIES eine leere Liste', function (): void {
    withEnvVar('TRUSTED_PROXIES', null, function (): void {
        assert_same([], reverseProxyTrustedEntries());
    });
    withEnvVar('TRUSTED_PROXIES', '', function (): void {
        assert_same([], reverseProxyTrustedEntries());
    });
});

test_case('reverseProxyTrustedEntries() trennt Einträge an Komma, Semikolon und Leerraum', function (): void {
    withEnvVar('TRUSTED_PROXIES', '10.0.0.0/8, 192.168.0.0/16;172.16.0.1', function (): void {
        assert_same(['10.0.0.0/8', '192.168.0.0/16', '172.16.0.1'], reverseProxyTrustedEntries());
    });
    withEnvVar("TRUSTED_PROXIES", "10.0.0.1\n10.0.0.2   10.0.0.3", function (): void {
        assert_same(['10.0.0.1', '10.0.0.2', '10.0.0.3'], reverseProxyTrustedEntries());
    });
});

test_case('reverseProxyTrustedEntries() verwirft leere Segmente', function (): void {
    withEnvVar('TRUSTED_PROXIES', ' 10.0.0.1 ,, ; ', function (): void {
        assert_same(['10.0.0.1'], reverseProxyTrustedEntries());
    });
});

// ── reverseProxyIpInRange() ───────────────────────────────────────────────────

test_case('reverseProxyIpInRange() prüft IPv4-Bereiche', function (): void {
    assert_true(reverseProxyIpInRange('10.0.0.5', '10.0.0.0/8'));
    assert_false(reverseProxyIpInRange('11.0.0.5', '10.0.0.0/8'));
    assert_true(reverseProxyIpInRange('192.168.1.130', '192.168.1.128/25'));
    assert_false(reverseProxyIpInRange('192.168.1.127', '192.168.1.128/25'));
    assert_true(reverseProxyIpInRange('10.0.0.5', '10.0.0.0/0'));
});

test_case('reverseProxyIpInRange() prüft einzelne Adressen ohne Präfixlänge', function (): void {
    assert_true(reverseProxyIpInRange('10.1.2.3', '10.1.2.3'));
    assert_false(reverseProxyIpInRange('10.1.2.4', '10.1.2.3'));
    assert_false(reverseProxyIpInRange('10.0.0.5', '10.0.0.0/32'));
});

test_case('reverseProxyIpInRange() prüft IPv6-Bereiche', function (): void {
    assert_true(reverseProxyIpInRange('fd00::1', 'fd00::/8'));
    assert_false(reverseProxyIpInRange('fe80::1', 'fd00::/8'));
    assert_true(reverseProxyIpInRange('::1', '::1'));
});

test_case('reverseProxyIpInRange() lehnt ungültige Eingaben ab', function (): void {
    assert_false(reverseProxyIpInRange('10.0.0.5', '10.0.0.0/33'), 'Präfixlänge über der Bitbreite');
    assert_false(reverseProxyIpInRange('10.0.0.5', '10.0.0.0/abc'), 'Nicht-numerische Präfixlänge');
    assert_false(reverseProxyIpInRange('10.0.0.5', 'fd00::/8'), 'IPv4-Adresse gegen IPv6-Bereich');
    assert_false(reverseProxyIpInRange('bogus', '10.0.0.0/8'), 'Ungültige Adresse');
    assert_false(reverseProxyIpInRange('10.0.0.5', 'bogus'), 'Ungültiger Bereich');
});

// ── reverseProxyIsTrusted() ───────────────────────────────────────────────────

test_case('reverseProxyIsTrusted() prüft gegen die konfigurierten Einträge', function (): void {
    withEnvVar('TRUSTED_PROXIES', '10.0.0.0/8, 172.16.5.5', function (): void {
        assert_true(reverseProxyIsTrusted('10.9.9.9'));
        assert_true(reverseProxyIsTrusted('172.16.5.5'));
        assert_false(reverseProxyIsTrusted('172.16.5.6'));
        assert_false(reverseProxyIsTrusted('8.8.8.8'));
    });
});

test_case('reverseProxyIsTrusted() vertraut ohne Konfiguration niemandem', function (): void {
    withEnvVar('TRUSTED_PROXIES', null, function (): void {
        assert_false(reverseProxyIsTrusted('10.0.0.1'));
        assert_false(reverseProxyIsTrusted('127.0.0.1'));
    });
});

test_case('reverseProxyIsTrusted() lehnt ungültige Adressen ab', function (): void {
    withEnvVar('TRUSTED_PROXIES', '10.0.0.0/8', function (): void {
        assert_false(reverseProxyIsTrusted('keine-ip'));
        assert_false(reverseProxyIsTrusted(''));
    });
});

// ── reverseProxyFirstHeaderValue() ────────────────────────────────────────────

test_case('reverseProxyFirstHeaderValue() nimmt den ersten Wert einer Weiterleitungskette', function (): void {
    withServer(['HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.1, 10.0.0.2'], function (): void {
        assert_same('203.0.113.9', reverseProxyFirstHeaderValue('HTTP_X_FORWARDED_FOR'));
    });
    withServer(['HTTP_X_FORWARDED_PROTO' => '  https  '], function (): void {
        assert_same('https', reverseProxyFirstHeaderValue('HTTP_X_FORWARDED_PROTO'));
    });
});

test_case('reverseProxyFirstHeaderValue() liefert einen leeren String für fehlende Header', function (): void {
    withServer(['HTTP_X_FORWARDED_FOR' => ''], function (): void {
        assert_same('', reverseProxyFirstHeaderValue('HTTP_X_FORWARDED_FOR'));
        assert_same('', reverseProxyFirstHeaderValue('HTTP_X_FORWARDED_PREFIX'));
    });
});

// ── reverseProxySsoServerKey() / reverseProxySsoEnabled() ─────────────────────

test_case('reverseProxySsoServerKey() leitet den $_SERVER-Schlüssel aus PROXY_SSO_HEADER ab', function (): void {
    withEnvVar('PROXY_SSO_HEADER', 'X-Remote-User', function (): void {
        assert_same('HTTP_X_REMOTE_USER', reverseProxySsoServerKey());
    });
    withEnvVar('PROXY_SSO_HEADER', 'x-remote-user', function (): void {
        assert_same('HTTP_X_REMOTE_USER', reverseProxySsoServerKey());
    });
});

test_case('reverseProxySsoServerKey() lehnt unbrauchbare Header-Namen ab', function (): void {
    foreach ([null, '', '   ', 'X Remote User', 'X-Remote-User:', 'X_Remote_User'] as $value) {
        withEnvVar('PROXY_SSO_HEADER', $value, function () use ($value): void {
            assert_same('', reverseProxySsoServerKey(), 'Wert: ' . var_export($value, true));
        });
    }
});

test_case('reverseProxySsoEnabled() verlangt Header und vertrauenswürdige Proxies', function (): void {
    withEnvVar('PROXY_SSO_HEADER', 'X-Remote-User', function (): void {
        withEnvVar('TRUSTED_PROXIES', '10.0.0.0/8', function (): void {
            assert_true(reverseProxySsoEnabled());
        });
        withEnvVar('TRUSTED_PROXIES', null, function (): void {
            assert_false(reverseProxySsoEnabled(), 'Ohne TRUSTED_PROXIES kein SSO');
        });
    });
    withEnvVar('PROXY_SSO_HEADER', null, function (): void {
        withEnvVar('TRUSTED_PROXIES', '10.0.0.0/8', function (): void {
            assert_false(reverseProxySsoEnabled(), 'Ohne Header kein SSO');
        });
    });
});

// ── reverseProxyActive() / reverseProxyPublicPrefix() ─────────────────────────

test_case('reverseProxyActive() hängt an LLMINT_BEHIND_PROXY', function (): void {
    withServer(['LLMINT_BEHIND_PROXY' => '1'], function (): void {
        assert_true(reverseProxyActive());
    });
    withServer(['LLMINT_BEHIND_PROXY' => '0'], function (): void {
        assert_false(reverseProxyActive());
    });
    withServer([], function (): void {
        unset($_SERVER['LLMINT_BEHIND_PROXY']);
        assert_false(reverseProxyActive());
    });
});

test_case('reverseProxyPublicPrefix() übernimmt ein gültiges X-Forwarded-Prefix', function (): void {
    withServer(['LLMINT_BEHIND_PROXY' => '1', 'HTTP_X_FORWARDED_PREFIX' => '/ki'], function (): void {
        assert_same('/ki', reverseProxyPublicPrefix());
    });
    withServer(['LLMINT_BEHIND_PROXY' => '1', 'HTTP_X_FORWARDED_PREFIX' => '/ki/'], function (): void {
        assert_same('/ki', reverseProxyPublicPrefix(), 'Abschließender Schrägstrich wird entfernt');
    });
    withServer(['LLMINT_BEHIND_PROXY' => '1', 'HTTP_X_FORWARDED_PREFIX' => '/'], function (): void {
        assert_same('', reverseProxyPublicPrefix(), 'Die Wurzel ist kein Präfix');
    });
});

test_case('reverseProxyPublicPrefix() ignoriert das Präfix ohne Proxy-Betrieb', function (): void {
    withServer(['HTTP_X_FORWARDED_PREFIX' => '/ki'], function (): void {
        unset($_SERVER['LLMINT_BEHIND_PROXY']);
        assert_same('', reverseProxyPublicPrefix());
    });
});

test_case('reverseProxyPublicPrefix() wehrt sich gegen Pfadmanipulation', function (): void {
    $bad = ['/a/../b', '/ki?x=1', '/ki#frag', 'ki', '', '/ki space', '/<script>'];
    foreach ($bad as $prefix) {
        withServer(['LLMINT_BEHIND_PROXY' => '1', 'HTTP_X_FORWARDED_PREFIX' => $prefix], function () use ($prefix): void {
            assert_same('', reverseProxyPublicPrefix(), 'Präfix abgelehnt: ' . var_export($prefix, true));
        });
    }
});

// ── appRootScriptPath() ───────────────────────────────────────────────────────

test_case('appRootScriptPath() leitet den Wurzelpfad aus dem Skriptpfad ab', function () use ($repoRoot): void {
    withServer([
        'SCRIPT_NAME'     => '/llmint/admin/index.php',
        'SCRIPT_FILENAME' => $repoRoot . '/admin/index.php',
    ], function (): void {
        assert_same('/llmint', appRootScriptPath());
    });
});

test_case('appRootScriptPath() liefert einen leeren String für Skripte im Wurzelverzeichnis', function () use ($repoRoot): void {
    withServer([
        'SCRIPT_NAME'     => '/index.php',
        'SCRIPT_FILENAME' => $repoRoot . '/index.php',
    ], function (): void {
        assert_same('', appRootScriptPath());
    });
});

test_case('appRootScriptPath() liefert einen leeren String für tiefer liegende Skripte', function (): void {
    // Dieser Test läuft selbst aus tests/php, also zwei Ebenen unter der Wurzel.
    assert_same('', appRootScriptPath());
});

test_case('appRootScriptPath() fällt ohne bekannte Datei auf SCRIPT_NAME zurück', function (): void {
    withServer([
        'SCRIPT_NAME'     => '/llmint/admin/index.php',
        'SCRIPT_FILENAME' => '/pfad/gibt/es/nicht.php',
    ], function (): void {
        assert_same('/llmint/admin', appRootScriptPath());
    });
});

// ── appPublicBaseUrl() ────────────────────────────────────────────────────────

test_case('appPublicBaseUrl() setzt sich aus Protokoll, Host, Präfix und Wurzelpfad zusammen', function () use ($repoRoot): void {
    withServer([
        'LLMINT_BEHIND_PROXY'     => '1',
        'HTTP_X_FORWARDED_PROTO'  => 'https',
        'HTTP_X_FORWARDED_HOST'   => 'intranet.example',
        'HTTP_X_FORWARDED_PREFIX' => '/ki',
        'SCRIPT_NAME'             => '/llmint/admin/index.php',
        'SCRIPT_FILENAME'         => $repoRoot . '/admin/index.php',
    ], function (): void {
        assert_same('https://intranet.example/ki/llmint', appPublicBaseUrl());
    });
});

test_case('appPublicBaseUrl() nutzt ohne Proxy den eigenen Host und HTTP', function (): void {
    withServer([
        'HTTP_HOST'       => 'llmint.local',
        'SCRIPT_NAME'     => '/index.php',
        'SCRIPT_FILENAME' => '/index.php',
    ], function (): void {
        unset($_SERVER['LLMINT_BEHIND_PROXY'], $_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        assert_same('http://llmint.local', appPublicBaseUrl());
    });
});

test_case('appPublicBaseUrl() berücksichtigt HTTPS ohne Proxy', function (): void {
    withServer([
        'HTTP_HOST'       => 'llmint.local',
        'HTTPS'           => 'on',
        'SCRIPT_NAME'     => '/index.php',
        'SCRIPT_FILENAME' => '/index.php',
    ], function (): void {
        unset($_SERVER['LLMINT_BEHIND_PROXY']);
        assert_same('https://llmint.local', appPublicBaseUrl());
    });
});

test_case('appPublicBaseUrl() verwirft unsinnige Weiterleitungs-Header', function (): void {
    withServer([
        'LLMINT_BEHIND_PROXY'    => '1',
        'HTTP_X_FORWARDED_PROTO' => 'ftp',
        'HTTP_X_FORWARDED_HOST'  => 'public.example',
        'HTTP_HOST'              => 'intern.local',
        'SCRIPT_NAME'            => '/index.php',
        'SCRIPT_FILENAME'        => '/index.php',
    ], function (): void {
        unset($_SERVER['HTTPS']);
        // Ein unbekanntes Protokoll fällt auf http zurück, der weitergeleitete
        // Host bleibt gültig.
        assert_same('http://public.example', appPublicBaseUrl());
    });
});

test_case('appPublicBaseUrl() ignoriert Weiterleitungs-Header ohne vertrauenswürdigen Proxy', function (): void {
    withServer([
        'HTTP_X_FORWARDED_HOST' => 'angreifer.example',
        'HTTP_HOST'             => 'intern.local',
        'SCRIPT_NAME'           => '/index.php',
        'SCRIPT_FILENAME'       => '/index.php',
    ], function (): void {
        unset($_SERVER['LLMINT_BEHIND_PROXY'], $_SERVER['HTTPS']);
        withEnvVar('TRUSTED_PROXIES', '10.0.0.0/8', function (): void {
            assert_same('http://intern.local', appPublicBaseUrl());
        });
    });
});

test_case('appPublicBaseUrl() ehrt Weiterleitungs-Header im Legacy-Modus', function () use ($repoRoot): void {
    withServer([
        'HTTP_X_FORWARDED_PROTO'  => 'https',
        'HTTP_X_FORWARDED_HOST'   => 'public.example',
        'HTTP_X_FORWARDED_PREFIX' => '/ki',
        'HTTP_HOST'               => 'intern.local',
        'SCRIPT_NAME'             => '/index.php',
        'SCRIPT_FILENAME'         => $repoRoot . '/index.php',
    ], function (): void {
        unset($_SERVER['LLMINT_BEHIND_PROXY'], $_SERVER['HTTPS']);
        withEnvVar('TRUSTED_PROXIES', null, function (): void {
            // Legacy: die API-Seite durfte die Header auch ohne TRUSTED_PROXIES nutzen.
            assert_same('https://public.example', appPublicBaseUrl(true));
            assert_same('http://intern.local', appPublicBaseUrl(false));
        });
    });
});

exit(test_summary('test_reverse_proxy'));
