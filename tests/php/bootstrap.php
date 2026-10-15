<?php
declare(strict_types=1);

/**
 * tests/php/bootstrap.php
 *
 * Minimal test harness for the plain-PHP parts of LLMInt.
 *
 * Deliberately dependency free: no Composer, no PHPUnit. Every test file
 * requires this bootstrap, registers its cases with test_case() and ends with
 * exit(test_summary(...)).
 *
 * Usage (from the repository root):
 *   php tests/php/test_topology_helpers.php
 */

$GLOBALS['__llmint_tests'] = ['passed' => 0, 'failed' => 0, 'failures' => []];

/** Thrown by the assertion helpers below to mark a case as failed. */
final class TestFailure extends RuntimeException
{
}

function test_fail(string $message): void
{
    throw new TestFailure($message);
}

/** One-line rendering of any value, used in failure messages. */
function test_describe($value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_string($value)) {
        $text = strlen($value) > 160 ? substr($value, 0, 160) . '...' : $value;
        return "'" . $text . "'";
    }
    if (is_scalar($value)) {
        return (string) $value;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return gettype($value);
    }
    return strlen($json) > 400 ? substr($json, 0, 400) . '...' : $json;
}

function test_case(string $name, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        $GLOBALS['__llmint_tests']['failed']++;
        $GLOBALS['__llmint_tests']['failures'][] = $name;
        echo "not ok - {$name}\n";
        $detail = get_class($e) === TestFailure::class
            ? $e->getMessage()
            : get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        echo '         ' . str_replace("\n", "\n         ", $detail) . "\n";
        return;
    }
    $GLOBALS['__llmint_tests']['passed']++;
    echo "ok - {$name}\n";
}

function test_summary(string $label = ''): int
{
    $tests = $GLOBALS['__llmint_tests'];
    $total = $tests['passed'] + $tests['failed'];
    $prefix = $label === '' ? '' : $label . ': ';

    echo "\n";
    echo ($tests['failed'] === 0 ? 'PASS' : 'FAIL') . " {$prefix}{$tests['passed']}/{$total} Tests bestanden\n";
    if ($tests['failures'] !== []) {
        echo "Fehlgeschlagen:\n";
        foreach ($tests['failures'] as $name) {
            echo "  - {$name}\n";
        }
    }

    return $tests['failed'] === 0 ? 0 : 1;
}

// ── Assertions ────────────────────────────────────────────────────────────────

function assert_true($condition, string $message = 'expected true'): void
{
    if ($condition !== true) {
        test_fail($message . ' (got ' . test_describe($condition) . ')');
    }
}

function assert_false($condition, string $message = 'expected false'): void
{
    if ($condition !== false) {
        test_fail($message . ' (got ' . test_describe($condition) . ')');
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        test_fail(($message !== '' ? $message . ': ' : '')
            . 'expected ' . test_describe($expected) . ', got ' . test_describe($actual));
    }
}

function assert_not_same($unexpected, $actual, string $message = ''): void
{
    if ($unexpected === $actual) {
        test_fail(($message !== '' ? $message . ': ' : '')
            . 'did not expect ' . test_describe($actual));
    }
}

function assert_close(float $expected, $actual, float $epsilon = 0.001, string $message = ''): void
{
    if (!is_numeric($actual) || abs((float) $actual - $expected) > $epsilon) {
        test_fail(($message !== '' ? $message . ': ' : '')
            . 'expected ' . $expected . ' +/- ' . $epsilon . ', got ' . test_describe($actual));
    }
}

function assert_count(int $expected, $actual, string $message = ''): void
{
    if (!is_array($actual) && !($actual instanceof Countable)) {
        test_fail(($message !== '' ? $message . ': ' : '') . 'expected a countable value, got ' . test_describe($actual));
    }
    if (count($actual) !== $expected) {
        test_fail(($message !== '' ? $message . ': ' : '')
            . 'expected ' . $expected . ' entries, got ' . count($actual) . ' (' . test_describe($actual) . ')');
    }
}

function assert_key(string $key, array $array, string $message = ''): void
{
    if (!array_key_exists($key, $array)) {
        test_fail(($message !== '' ? $message . ': ' : '')
            . 'missing key ' . test_describe($key) . ' in ' . test_describe(array_keys($array)));
    }
}

function assert_keys(array $keys, array $array, string $message = ''): void
{
    foreach ($keys as $key) {
        assert_key((string) $key, $array, $message);
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) === false) {
        test_fail(($message !== '' ? $message . ': ' : '')
            . 'expected to find ' . test_describe($needle) . ' in ' . test_describe($haystack));
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) !== false) {
        test_fail(($message !== '' ? $message . ': ' : '')
            . 'did not expect to find ' . test_describe($needle) . ' in ' . test_describe($haystack));
    }
}

/**
 * Asserts that every entry of $items satisfies $check($item, $key).
 * The original array keys are passed through, so map keys stay usable.
 */
function assert_all(array $items, callable $check, string $message = 'entry failed'): void
{
    foreach ($items as $key => $item) {
        $result = $check($item, $key);
        if ($result !== true) {
            test_fail($message . ' at key ' . test_describe($key) . ': ' . test_describe($item)
                . ($result === false ? '' : ' (' . test_describe($result) . ')'));
        }
    }
}
