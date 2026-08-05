<?php
/**
 * admin/prompt_security.php
 *
 * Admin interface for the Prompt Security module.
 *
 * Sub-pages (controlled via ?tab=…):
 *   dashboard  – attack overview, top rules, top users, daily trend
 *   rules      – manage signature rules (add / edit / toggle / delete / test / import / export)
 *   logs       – filterable security event log
 *   settings   – module configuration
 */

session_start();

require_once __DIR__ . '/../db.php';
requireAdminOrRedirect('login.php');

require_once __DIR__ . '/../lib/prompt_security.php';

$db        = getDb();
$tab       = in_array($_GET['tab'] ?? '', ['dashboard', 'rules', 'logs', 'settings'], true)
    ? ($_GET['tab'] ?? 'dashboard') : 'dashboard';
$flashOk   = '';
$flashError = '';

// ── CSRF ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $flashError = 'Ungültiger CSRF-Token. Bitte die Seite neu laden.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Save settings ─────────────────────────────────────────────────────
        if ($action === 'save_ps_settings') {
            $keys = [
                'prompt_security_enabled', 'prompt_security_mode',
                'prompt_security_score_limit', 'prompt_security_warn_limit',
                'prompt_security_log', 'prompt_security_log_input',
                'prompt_security_log_retention_days', 'prompt_security_fail_open',
                'prompt_security_ai_enabled', 'prompt_security_ai_endpoint',
                'prompt_security_ai_model', 'prompt_security_block_message',
            ];
            foreach ($keys as $k) {
                if (isset($_POST[$k])) {
                    setSetting($k, trim((string) $_POST[$k]));
                } elseif (in_array($k, ['prompt_security_enabled', 'prompt_security_log',
                                        'prompt_security_fail_open', 'prompt_security_ai_enabled'], true)) {
                    // Unchecked checkbox → '0'
                    setSetting($k, '0');
                }
            }
            // Checkbox fields (boolean 1/0)
            setSetting('prompt_security_enabled',   isset($_POST['prompt_security_enabled'])   ? '1' : '0');
            setSetting('prompt_security_log',       isset($_POST['prompt_security_log'])       ? '1' : '0');
            setSetting('prompt_security_fail_open', isset($_POST['prompt_security_fail_open']) ? '1' : '0');
            setSetting('prompt_security_ai_enabled',isset($_POST['prompt_security_ai_enabled'])? '1' : '0');
            $flashOk = 'Einstellungen gespeichert.';
            $tab = 'settings';

        // ── Add rule ─────────────────────────────────────────────────────────
        } elseif ($action === 'add_ps_rule') {
            $category = trim($_POST['rule_category'] ?? '');
            $name     = trim($_POST['rule_name']     ?? '');
            $pattern  = trim($_POST['rule_pattern']  ?? '');
            $isRegex  = isset($_POST['rule_is_regex']) ? 1 : 0;
            $severity = max(0, min(100, (int) ($_POST['rule_severity'] ?? 50)));
            $desc     = trim($_POST['rule_description'] ?? '');

            if ($pattern === '') {
                $flashError = 'Muster darf nicht leer sein.';
            } elseif ($isRegex && @preg_match('/' . addcslashes($pattern, '/') . '/iu', '') === false) {
                $flashError = 'Ungültiger regulärer Ausdruck.';
            } else {
                $db->prepare(
                    'INSERT INTO prompt_security_rules (category, name, pattern, is_regex, severity, is_active, description)
                     VALUES (?, ?, ?, ?, ?, 1, ?)'
                )->execute([$category, $name, $pattern, $isRegex, $severity, $desc ?: null]);
                $flashOk = 'Regel hinzugefügt.';
            }
            $tab = 'rules';

        // ── Update rule ───────────────────────────────────────────────────────
        } elseif ($action === 'update_ps_rule') {
            $ruleId   = (int) ($_POST['rule_id']       ?? 0);
            $category = trim($_POST['rule_category']   ?? '');
            $name     = trim($_POST['rule_name']       ?? '');
            $pattern  = trim($_POST['rule_pattern']    ?? '');
            $isRegex  = isset($_POST['rule_is_regex'])  ? 1 : 0;
            $severity = max(0, min(100, (int) ($_POST['rule_severity'] ?? 50)));
            $isActive = isset($_POST['rule_is_active']) ? 1 : 0;
            $desc     = trim($_POST['rule_description'] ?? '');

            if ($ruleId <= 0) {
                $flashError = 'Ungültige Regel-ID.';
            } elseif ($pattern === '') {
                $flashError = 'Muster darf nicht leer sein.';
            } elseif ($isRegex && @preg_match('/' . addcslashes($pattern, '/') . '/iu', '') === false) {
                $flashError = 'Ungültiger regulärer Ausdruck.';
            } else {
                $db->prepare(
                    'UPDATE prompt_security_rules
                        SET category = ?, name = ?, pattern = ?, is_regex = ?, severity = ?, is_active = ?, description = ?
                      WHERE id = ?'
                )->execute([$category, $name, $pattern, $isRegex, $severity, $isActive, $desc ?: null, $ruleId]);
                $flashOk = 'Regel gespeichert.';
            }
            $tab = 'rules';

        // ── Toggle rule ───────────────────────────────────────────────────────
        } elseif ($action === 'toggle_ps_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            if ($ruleId > 0) {
                $db->prepare(
                    'UPDATE prompt_security_rules SET is_active = 1 - is_active WHERE id = ?'
                )->execute([$ruleId]);
                $flashOk = 'Regelstatus geändert.';
            }
            $tab = 'rules';

        // ── Delete rule ───────────────────────────────────────────────────────
        } elseif ($action === 'delete_ps_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            if ($ruleId > 0) {
                $db->prepare('DELETE FROM prompt_security_rules WHERE id = ?')->execute([$ruleId]);
                $flashOk = 'Regel gelöscht.';
            }
            $tab = 'rules';

        // ── Test pattern ──────────────────────────────────────────────────────
        } elseif ($action === 'test_ps_pattern') {
            $pattern = trim($_POST['test_pattern'] ?? '');
            $input   = trim($_POST['test_input']   ?? '');
            $isRegex = isset($_POST['test_is_regex']);
            $testResult = null;

            if ($pattern !== '' && $input !== '') {
                ['normalised' => $normalised] = psNormalise($input);
                if ($isRegex) {
                    $regex = '/' . addcslashes($pattern, '/') . '/iu';
                    $testResult = @preg_match($regex, $normalised, $m) === 1
                        ? ['hit' => true,  'excerpt' => $m[0]]
                        : ['hit' => false, 'excerpt' => ''];
                } else {
                    $lp = mb_strtolower($pattern, 'UTF-8');
                    $testResult = mb_strpos($normalised, $lp) !== false
                        ? ['hit' => true,  'excerpt' => $pattern]
                        : ['hit' => false, 'excerpt' => ''];
                }
            }
            $tab = 'rules';

        // ── Export rules ──────────────────────────────────────────────────────
        } elseif ($action === 'export_ps_rules') {
            $rows = $db->query(
                'SELECT category, name, pattern, is_regex, severity, is_active, description
                   FROM prompt_security_rules ORDER BY id ASC'
            )->fetchAll();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="ps_rules_' . date('Ymd_His') . '.json"');
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        // ── Import rules ──────────────────────────────────────────────────────
        } elseif ($action === 'import_ps_rules') {
            $jsonRaw = isset($_FILES['rules_file']) && is_uploaded_file($_FILES['rules_file']['tmp_name'])
                ? file_get_contents($_FILES['rules_file']['tmp_name']) : '';
            $imported = json_decode((string) $jsonRaw, true);
            if (!is_array($imported)) {
                $flashError = 'Ungültige JSON-Datei.';
            } else {
                $stmt = $db->prepare(
                    'INSERT IGNORE INTO prompt_security_rules (category, name, pattern, is_regex, severity, is_active, description)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $count = 0;
                foreach ($imported as $r) {
                    if (!is_array($r) || empty($r['pattern'])) {
                        continue;
                    }
                    $stmt->execute([
                        substr((string) ($r['category']    ?? ''), 0, 60),
                        substr((string) ($r['name']        ?? ''), 0, 120),
                        substr((string) ($r['pattern']     ?? ''), 0, 500),
                        (int) ($r['is_regex']  ?? 0) ? 1 : 0,
                        max(0, min(100, (int) ($r['severity'] ?? 50))),
                        (int) ($r['is_active'] ?? 1) ? 1 : 0,
                        substr((string) ($r['description'] ?? ''), 0, 1000) ?: null,
                    ]);
                    $count++;
                }
                $flashOk = $count . ' Regeln importiert.';
            }
            $tab = 'rules';

        // ── Clear logs ────────────────────────────────────────────────────────
        } elseif ($action === 'clear_ps_logs') {
            $db->exec('DELETE FROM prompt_security_logs');
            $flashOk = 'Sicherheitsprotokolle gelöscht.';
            $tab = 'logs';
        }
    }
}

// ── Load current settings ─────────────────────────────────────────────────────
$psEnabled          = getSetting('prompt_security_enabled',          '0') === '1';
$psMode             = getSetting('prompt_security_mode',             'active');
$psScoreLimit       = (int) getSetting('prompt_security_score_limit', '81');
$psWarnLimit        = (int) getSetting('prompt_security_warn_limit',  '51');
$psLog              = getSetting('prompt_security_log',              '1') === '1';
$psLogInput         = getSetting('prompt_security_log_input',        'full');
$psLogRetention     = (int) getSetting('prompt_security_log_retention_days', '30');
$psFailOpen         = getSetting('prompt_security_fail_open',        '1') === '1';
$psAiEnabled        = getSetting('prompt_security_ai_enabled',       '0') === '1';
$psAiEndpoint       = getSetting('prompt_security_ai_endpoint',      '');
$psAiModel          = getSetting('prompt_security_ai_model',         '');
$psBlockMessage     = getSetting('prompt_security_block_message',    '');

// ── Load rules ────────────────────────────────────────────────────────────────
$editRule   = null;
$testResult = $testResult ?? null;
$allRules   = [];
try {
    $allRules = $db->query(
        'SELECT * FROM prompt_security_rules ORDER BY category ASC, severity DESC, id ASC'
    )->fetchAll();
} catch (Throwable $e) { /* Table may not exist yet */ }

if (isset($_GET['edit_rule']) && (int) $_GET['edit_rule'] > 0) {
    foreach ($allRules as $r) {
        if ((int) $r['id'] === (int) $_GET['edit_rule']) {
            $editRule = $r;
            $tab = 'rules';
            break;
        }
    }
}

// ── Dashboard data ────────────────────────────────────────────────────────────
$dashToday       = 0;
$dashBlocked     = 0;
$dashTopRules    = [];
$dashTopUsers    = [];
$dashTrend       = [];

if ($tab === 'dashboard') {
    try {
        $dashToday   = (int) $db->query("SELECT COUNT(*) FROM prompt_security_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
        $dashBlocked = (int) $db->query("SELECT COUNT(*) FROM prompt_security_logs WHERE decision = 'block' AND DATE(created_at) = CURDATE()")->fetchColumn();

        $dashTopRules = $db->query(
            "SELECT r.name, r.category, COUNT(*) AS cnt
               FROM prompt_security_logs l
               JOIN prompt_security_rules r ON r.id = l.matched_rule
              WHERE l.created_at >= NOW() - INTERVAL 7 DAY
              GROUP BY r.id, r.name, r.category
              ORDER BY cnt DESC
              LIMIT 10"
        )->fetchAll();

        $dashTopUsers = $db->query(
            "SELECT COALESCE(u.username, CONCAT('Anonym (', l.ip_address, ')')) AS label,
                    COUNT(*) AS cnt
               FROM prompt_security_logs l
               LEFT JOIN users u ON u.id = l.user_id
              WHERE l.decision IN ('warn','block')
                AND l.created_at >= NOW() - INTERVAL 7 DAY
              GROUP BY l.user_id, l.ip_address
              ORDER BY cnt DESC
              LIMIT 10"
        )->fetchAll();

        $dashTrend = $db->query(
            "SELECT DATE(created_at) AS day,
                    COUNT(*) AS total,
                    SUM(decision = 'block') AS blocked,
                    SUM(decision = 'warn')  AS warned
               FROM prompt_security_logs
              WHERE created_at >= NOW() - INTERVAL 14 DAY
              GROUP BY DATE(created_at)
              ORDER BY day ASC"
        )->fetchAll();
    } catch (Throwable $e) { /* Tables may not exist yet */ }
}

// ── Logs data ─────────────────────────────────────────────────────────────────
$logRows       = [];
$logTotal      = 0;
$logPerPage    = 50;
$logPage       = max(1, (int) ($_GET['log_page'] ?? 1));
$logOffset     = ($logPage - 1) * $logPerPage;
$logFilterUser = trim($_GET['log_user']     ?? '');
$logFilterCat  = trim($_GET['log_cat']      ?? '');
$logFilterDec  = trim($_GET['log_dec']      ?? '');
$logFilterFrom = trim($_GET['log_from']     ?? '');
$logFilterTo   = trim($_GET['log_to']       ?? '');
$logScoreMin   = $_GET['log_score_min'] !== '' ? (int) ($_GET['log_score_min'] ?? 0) : null;

if ($tab === 'logs') {
    try {
        $logWhere  = ['1=1'];
        $logParams = [];

        if ($logFilterUser !== '') {
            $logWhere[]  = '(u.username LIKE ? OR l.ip_address LIKE ?)';
            $logParams[] = '%' . $logFilterUser . '%';
            $logParams[] = '%' . $logFilterUser . '%';
        }
        if ($logFilterCat !== '') {
            $logWhere[]  = 'l.matched_cat = ?';
            $logParams[] = $logFilterCat;
        }
        if ($logFilterDec !== '' && in_array($logFilterDec, ['allow','warn','block'], true)) {
            $logWhere[]  = 'l.decision = ?';
            $logParams[] = $logFilterDec;
        }
        if ($logFilterFrom !== '') {
            $logWhere[]  = 'DATE(l.created_at) >= ?';
            $logParams[] = $logFilterFrom;
        }
        if ($logFilterTo !== '') {
            $logWhere[]  = 'DATE(l.created_at) <= ?';
            $logParams[] = $logFilterTo;
        }
        if ($logScoreMin !== null) {
            $logWhere[]  = 'l.score >= ?';
            $logParams[] = $logScoreMin;
        }

        $whereClause = implode(' AND ', $logWhere);
        $baseQuery   = "FROM prompt_security_logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$whereClause}";

        $cntStmt = $db->prepare("SELECT COUNT(*) {$baseQuery}");
        $cntStmt->execute($logParams);
        $logTotal = (int) $cntStmt->fetchColumn();

        $dataStmt = $db->prepare(
            "SELECT l.*, COALESCE(u.username, '') AS username
               {$baseQuery}
               ORDER BY l.created_at DESC
               LIMIT {$logPerPage} OFFSET {$logOffset}"
        );
        $dataStmt->execute($logParams);
        $logRows = $dataStmt->fetchAll();
    } catch (Throwable $e) { /* Tables may not exist */ }
}

$logTotalPages = $logTotal > 0 ? (int) ceil($logTotal / $logPerPage) : 1;

// Unique categories for filter dropdown
$logCategories = [];
try {
    $logCategories = $db->query(
        "SELECT DISTINCT matched_cat FROM prompt_security_logs WHERE matched_cat != '' ORDER BY matched_cat"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// ── HTML helpers ──────────────────────────────────────────────────────────────
function psH(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function psDecisionBadge(string $dec): string {
    return match ($dec) {
        'block' => '<span class="badge bg-danger">blockiert</span>',
        'warn'  => '<span class="badge bg-warning text-dark">Warnung</span>',
        default => '<span class="badge bg-success">erlaubt</span>',
    };
}
function psCategoryBadge(string $cat): string {
    $colors = [
        'prompt_injection'  => 'danger',
        'jailbreak'         => 'danger',
        'prompt_leakage'    => 'warning',
        'tool_abuse'        => 'warning',
        'rag_attack'        => 'info',
        'role_switch'       => 'secondary',
        'data_exfiltration' => 'dark',
    ];
    $color = $colors[$cat] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . psH($cat) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prompt Security – LLMInt Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
<style>
  body { background: #f8f9fa; }
  .sidebar { min-height: 100vh; background: #212529; }
  .sidebar .nav-link { color: #adb5bd; }
  .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: #343a40; border-radius: 4px; }
  .badge-score { font-size: .75rem; }
  pre.input-preview { max-height: 80px; overflow: hidden; font-size: .75rem; background:#f1f1f1; padding:4px 6px; border-radius:4px; }
</style>
</head>
<body>
<div class="container-fluid">
<div class="row">
  <!-- Sidebar -->
  <nav class="col-md-2 sidebar d-flex flex-column py-3 ps-3 pe-2">
    <a class="navbar-brand text-white fw-bold mb-4" href="index.php">← Admin</a>
    <ul class="nav flex-column gap-1">
      <li class="nav-item"><a class="nav-link<?= $tab === 'dashboard' ? ' active' : '' ?>" href="prompt_security.php?tab=dashboard">Dashboard</a></li>
      <li class="nav-item"><a class="nav-link<?= $tab === 'rules'     ? ' active' : '' ?>" href="prompt_security.php?tab=rules">Regeln</a></li>
      <li class="nav-item"><a class="nav-link<?= $tab === 'logs'      ? ' active' : '' ?>" href="prompt_security.php?tab=logs">Protokoll</a></li>
      <li class="nav-item"><a class="nav-link<?= $tab === 'settings'  ? ' active' : '' ?>" href="prompt_security.php?tab=settings">Einstellungen</a></li>
    </ul>
  </nav>

  <!-- Main content -->
  <main class="col-md-10 py-4 px-4">
    <h2 class="mb-1">Prompt Security</h2>
    <p class="text-muted mb-3">Mehrstufige Sicherheitsprüfung für Chat-Eingaben</p>

    <?php if ($flashOk !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= psH($flashOk) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= psH($flashError) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if (!$psEnabled): ?>
    <div class="alert alert-warning">
      Das Prompt-Security-Modul ist aktuell <strong>deaktiviert</strong>.
      <a href="prompt_security.php?tab=settings" class="alert-link">Einstellungen öffnen</a>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <?php if ($tab === 'dashboard'): ?>
    <!-- DASHBOARD -->
    <div class="row g-3 mb-4">
      <div class="col-sm-3">
        <div class="card text-center"><div class="card-body">
          <div class="display-5 fw-bold"><?= $dashToday ?></div>
          <div class="text-muted small">Überprüfungen heute</div>
        </div></div>
      </div>
      <div class="col-sm-3">
        <div class="card text-center border-danger"><div class="card-body">
          <div class="display-5 fw-bold text-danger"><?= $dashBlocked ?></div>
          <div class="text-muted small">Blockiert heute</div>
        </div></div>
      </div>
      <div class="col-sm-3">
        <div class="card text-center"><div class="card-body">
          <div class="display-5 fw-bold"><?= count($allRules) ?></div>
          <div class="text-muted small">Aktive Regeln</div>
        </div></div>
      </div>
      <div class="col-sm-3">
        <div class="card text-center border-<?= $psEnabled ? 'success' : 'secondary' ?>"><div class="card-body">
          <div class="display-5 fw-bold text-<?= $psEnabled ? 'success' : 'secondary' ?>"><?= $psEnabled ? 'AN' : 'AUS' ?></div>
          <div class="text-muted small">Modul-Status</div>
        </div></div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header fw-semibold">Häufigste Regeln (7 Tage)</div>
          <div class="card-body p-0">
            <?php if (empty($dashTopRules)): ?>
            <p class="p-3 text-muted mb-0">Keine Treffer.</p>
            <?php else: ?>
            <table class="table table-sm mb-0">
              <thead><tr><th>Regelname</th><th>Kategorie</th><th class="text-end">Treffer</th></tr></thead>
              <tbody>
              <?php foreach ($dashTopRules as $tr): ?>
              <tr>
                <td><?= psH($tr['name']) ?></td>
                <td><?= psCategoryBadge($tr['category']) ?></td>
                <td class="text-end"><?= (int) $tr['cnt'] ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header fw-semibold">Auffälligste Benutzer (7 Tage, warn+block)</div>
          <div class="card-body p-0">
            <?php if (empty($dashTopUsers)): ?>
            <p class="p-3 text-muted mb-0">Keine Treffer.</p>
            <?php else: ?>
            <table class="table table-sm mb-0">
              <thead><tr><th>Benutzer/IP</th><th class="text-end">Ereignisse</th></tr></thead>
              <tbody>
              <?php foreach ($dashTopUsers as $tu): ?>
              <tr><td><?= psH($tu['label']) ?></td><td class="text-end"><?= (int) $tu['cnt'] ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if (!empty($dashTrend)): ?>
    <div class="card mt-3">
      <div class="card-header fw-semibold">Trend (14 Tage)</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Tag</th><th class="text-end">Gesamt</th><th class="text-end text-danger">Blockiert</th><th class="text-end text-warning">Gewarnt</th></tr></thead>
          <tbody>
          <?php foreach ($dashTrend as $trow): ?>
          <tr>
            <td><?= psH($trow['day']) ?></td>
            <td class="text-end"><?= (int) $trow['total'] ?></td>
            <td class="text-end text-danger"><?= (int) $trow['blocked'] ?></td>
            <td class="text-end text-warning"><?= (int) $trow['warned'] ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($tab === 'rules'): ?>
    <!-- RULES -->

    <!-- Pattern tester -->
    <div class="card mb-3">
      <div class="card-header fw-semibold">Regex-/Muster-Tester</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= psH($csrfToken) ?>">
          <input type="hidden" name="action"     value="test_ps_pattern">
          <div class="row g-2">
            <div class="col-md-5">
              <input type="text" name="test_pattern" class="form-control" placeholder="Muster / Regex" value="<?= psH((string)($_POST['test_pattern'] ?? '')) ?>">
            </div>
            <div class="col-md-5">
              <input type="text" name="test_input" class="form-control" placeholder="Testeingabe" value="<?= psH((string)($_POST['test_input'] ?? '')) ?>">
            </div>
            <div class="col-md-1 d-flex align-items-center">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="test_is_regex" id="testIsRegex" <?= isset($_POST['test_is_regex']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="testIsRegex">Regex</label>
              </div>
            </div>
            <div class="col-md-1">
              <button class="btn btn-outline-primary w-100" type="submit">Testen</button>
            </div>
          </div>
        </form>
        <?php if ($testResult !== null): ?>
        <div class="mt-2 alert <?= $testResult['hit'] ? 'alert-success' : 'alert-secondary' ?> py-1 mb-0">
          <?php if ($testResult['hit']): ?>
          ✅ Treffer: <code><?= psH($testResult['excerpt']) ?></code>
          <?php else: ?>
          ❌ Kein Treffer
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Import/Export -->
    <div class="d-flex gap-2 mb-3">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= psH($csrfToken) ?>">
        <input type="hidden" name="action" value="export_ps_rules">
        <button class="btn btn-sm btn-outline-secondary" type="submit">📥 Export (JSON)</button>
      </form>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= psH($csrfToken) ?>">
        <input type="hidden" name="action" value="import_ps_rules">
        <div class="input-group input-group-sm">
          <input type="file" name="rules_file" class="form-control form-control-sm" accept=".json">
          <button class="btn btn-outline-secondary" type="submit">📤 Import</button>
        </div>
      </form>
    </div>

    <!-- Add / Edit form -->
    <div class="card mb-3">
      <div class="card-header fw-semibold"><?= $editRule ? 'Regel bearbeiten' : 'Neue Regel' ?></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= psH($csrfToken) ?>">
          <input type="hidden" name="action" value="<?= $editRule ? 'update_ps_rule' : 'add_ps_rule' ?>">
          <?php if ($editRule): ?>
          <input type="hidden" name="rule_id" value="<?= (int) $editRule['id'] ?>">
          <?php endif; ?>
          <div class="row g-2">
            <div class="col-md-2">
              <label class="form-label small">Kategorie</label>
              <select name="rule_category" class="form-select form-select-sm">
                <?php foreach (['prompt_injection','jailbreak','prompt_leakage','tool_abuse','rag_attack','role_switch','data_exfiltration','other'] as $cat): ?>
                <option value="<?= psH($cat) ?>" <?= ($editRule['category'] ?? '') === $cat ? 'selected' : '' ?>><?= psH($cat) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small">Name</label>
              <input type="text" name="rule_name" class="form-control form-control-sm" value="<?= psH($editRule['name'] ?? '') ?>" maxlength="120">
            </div>
            <div class="col-md-4">
              <label class="form-label small">Muster *</label>
              <input type="text" name="rule_pattern" class="form-control form-control-sm" value="<?= psH($editRule['pattern'] ?? '') ?>" maxlength="500" required>
            </div>
            <div class="col-md-1">
              <label class="form-label small">Schweregrad</label>
              <input type="number" name="rule_severity" class="form-control form-control-sm" value="<?= (int) ($editRule['severity'] ?? 50) ?>" min="0" max="100">
            </div>
            <div class="col-md-2 d-flex flex-column justify-content-end gap-1">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="rule_is_regex" id="ruleIsRegex" <?= ($editRule['is_regex'] ?? 0) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="ruleIsRegex">Regex</label>
              </div>
              <?php if ($editRule): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="rule_is_active" id="ruleActive" <?= ($editRule['is_active'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="ruleActive">Aktiv</label>
              </div>
              <?php endif; ?>
            </div>
            <div class="col-12">
              <label class="form-label small">Beschreibung</label>
              <input type="text" name="rule_description" class="form-control form-control-sm" value="<?= psH($editRule['description'] ?? '') ?>" maxlength="500">
            </div>
          </div>
          <div class="mt-2 d-flex gap-2">
            <button class="btn btn-primary btn-sm" type="submit"><?= $editRule ? 'Speichern' : 'Hinzufügen' ?></button>
            <?php if ($editRule): ?>
            <a href="prompt_security.php?tab=rules" class="btn btn-secondary btn-sm">Abbrechen</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Rules table -->
    <div class="card">
      <div class="card-header fw-semibold">Alle Regeln (<?= count($allRules) ?>)</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr>
            <th>ID</th><th>Kategorie</th><th>Name</th><th>Muster</th>
            <th>Typ</th><th>Schweregrad</th><th>Status</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($allRules as $rule): ?>
          <tr class="<?= !(int)$rule['is_active'] ? 'table-secondary text-muted' : '' ?>">
            <td><?= (int) $rule['id'] ?></td>
            <td><?= psCategoryBadge($rule['category']) ?></td>
            <td><?= psH($rule['name']) ?></td>
            <td><code style="font-size:.75rem"><?= psH(mb_substr($rule['pattern'], 0, 60)) ?><?= mb_strlen($rule['pattern']) > 60 ? '…' : '' ?></code></td>
            <td><?= $rule['is_regex'] ? 'Regex' : 'Text' ?></td>
            <td><?= (int) $rule['severity'] ?></td>
            <td><?= $rule['is_active'] ? '<span class="badge bg-success">Aktiv</span>' : '<span class="badge bg-secondary">Inaktiv</span>' ?></td>
            <td class="text-end text-nowrap">
              <a href="prompt_security.php?tab=rules&edit_rule=<?= (int) $rule['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm py-0">Bearbeiten</a>
              <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= psH($csrfToken) ?>">
                <input type="hidden" name="action"  value="toggle_ps_rule">
                <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                <button class="btn btn-sm btn-outline-warning py-0" type="submit"><?= $rule['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Regel löschen?')">
                <input type="hidden" name="csrf_token" value="<?= psH($csrfToken) ?>">
                <input type="hidden" name="action"  value="delete_ps_rule">
                <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0" type="submit">Löschen</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($allRules)): ?>
          <tr><td colspan="8" class="text-center text-muted py-3">Keine Regeln vorhanden.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($tab === 'logs'): ?>
    <!-- LOGS -->

    <form method="get" class="row g-2 mb-3">
      <input type="hidden" name="tab" value="logs">
      <div class="col-md-2">
        <input type="text" name="log_user" class="form-control form-control-sm" placeholder="Benutzer / IP" value="<?= psH($logFilterUser) ?>">
      </div>
      <div class="col-md-2">
        <select name="log_cat" class="form-select form-select-sm">
          <option value="">Alle Kategorien</option>
          <?php foreach ($logCategories as $lc): ?>
          <option value="<?= psH($lc) ?>" <?= $logFilterCat === $lc ? 'selected' : '' ?>><?= psH($lc) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="log_dec" class="form-select form-select-sm">
          <option value="">Alle Entscheidungen</option>
          <option value="allow" <?= $logFilterDec === 'allow' ? 'selected' : '' ?>>erlaubt</option>
          <option value="warn"  <?= $logFilterDec === 'warn'  ? 'selected' : '' ?>>Warnung</option>
          <option value="block" <?= $logFilterDec === 'block' ? 'selected' : '' ?>>blockiert</option>
        </select>
      </div>
      <div class="col-md-2">
        <input type="date" name="log_from" class="form-control form-control-sm" value="<?= psH($logFilterFrom) ?>" placeholder="Von">
      </div>
      <div class="col-md-2">
        <input type="date" name="log_to" class="form-control form-control-sm" value="<?= psH($logFilterTo) ?>" placeholder="Bis">
      </div>
      <div class="col-md-1">
        <input type="number" name="log_score_min" class="form-control form-control-sm" placeholder="Score ≥" value="<?= $logScoreMin !== null ? $logScoreMin : '' ?>" min="0" max="100">
      </div>
      <div class="col-md-1 d-flex gap-1">
        <button class="btn btn-sm btn-primary flex-fill" type="submit">Filter</button>
        <a href="prompt_security.php?tab=logs" class="btn btn-sm btn-outline-secondary">✕</a>
      </div>
    </form>

    <div class="d-flex justify-content-between align-items-center mb-2">
      <span class="text-muted small"><?= $logTotal ?> Einträge</span>
      <form method="post" onsubmit="return confirm('Alle Protokolle löschen?')">
        <input type="hidden" name="csrf_token" value="<?= psH($csrfToken) ?>">
        <input type="hidden" name="action" value="clear_ps_logs">
        <button class="btn btn-sm btn-outline-danger">Protokoll leeren</button>
      </form>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr>
            <th>Zeit</th><th>Benutzer</th><th>IP</th><th>Kategorie</th>
            <th>Score</th><th>Entscheidung</th><th>Eingabe</th>
          </tr></thead>
          <tbody>
          <?php foreach ($logRows as $lr): ?>
          <tr>
            <td class="text-nowrap"><?= psH(substr((string)$lr['created_at'], 0, 19)) ?></td>
            <td><?= psH($lr['username'] ?: '–') ?></td>
            <td><?= psH($lr['ip_address']) ?></td>
            <td><?= $lr['matched_cat'] ? psCategoryBadge($lr['matched_cat']) : '–' ?></td>
            <td><span class="badge bg-<?= $lr['score'] >= 81 ? 'danger' : ($lr['score'] >= 51 ? 'warning text-dark' : 'success') ?>"><?= (int) $lr['score'] ?></span></td>
            <td><?= psDecisionBadge($lr['decision']) ?></td>
            <td><?php if ($lr['input_text'] !== null): ?><pre class="input-preview mb-0"><?= psH(mb_substr((string)$lr['input_text'], 0, 200)) ?></pre><?php else: ?><span class="text-muted">–</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($logRows)): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">Keine Einträge.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pagination -->
    <?php if ($logTotalPages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php for ($p = 1; $p <= $logTotalPages; $p++): ?>
        <li class="page-item<?= $p === $logPage ? ' active' : '' ?>">
          <a class="page-link" href="?tab=logs&log_page=<?= $p ?>&log_user=<?= urlencode($logFilterUser) ?>&log_cat=<?= urlencode($logFilterCat) ?>&log_dec=<?= urlencode($logFilterDec) ?>&log_from=<?= urlencode($logFilterFrom) ?>&log_to=<?= urlencode($logFilterTo) ?>&log_score_min=<?= $logScoreMin ?? '' ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
      </ul>
    </nav>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($tab === 'settings'): ?>
    <!-- SETTINGS -->
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= psH($csrfToken) ?>">
      <input type="hidden" name="action" value="save_ps_settings">

      <div class="card mb-3">
        <div class="card-header fw-semibold">Allgemein</div>
        <div class="card-body">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="prompt_security_enabled" id="psEnabled" <?= $psEnabled ? 'checked' : '' ?>>
            <label class="form-check-label" for="psEnabled">Prompt-Security aktivieren</label>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Modus</label>
              <select name="prompt_security_mode" class="form-select form-select-sm">
                <option value="active"  <?= $psMode === 'active'  ? 'selected' : '' ?>>Aktiv (Blockierung möglich)</option>
                <option value="passive" <?= $psMode === 'passive' ? 'selected' : '' ?>>Passiv (nur Protokollierung)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Blockschwelle (Score ≥)</label>
              <input type="number" name="prompt_security_score_limit" class="form-control form-control-sm" value="<?= $psScoreLimit ?>" min="0" max="100">
            </div>
            <div class="col-md-4">
              <label class="form-label">Warnschwelle (Score ≥)</label>
              <input type="number" name="prompt_security_warn_limit" class="form-control form-control-sm" value="<?= $psWarnLimit ?>" min="0" max="100">
            </div>
            <div class="col-md-6">
              <label class="form-label">Blocknachricht (leer = Standard)</label>
              <input type="text" name="prompt_security_block_message" class="form-control form-control-sm" value="<?= psH($psBlockMessage) ?>" maxlength="300">
            </div>
            <div class="col-md-3 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="prompt_security_fail_open" id="psFailOpen" <?= $psFailOpen ? 'checked' : '' ?>>
                <label class="form-check-label" for="psFailOpen">Fail-Open (bei Modul-Fehler erlauben)</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header fw-semibold">Protokollierung</div>
        <div class="card-body">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="prompt_security_log" id="psLog" <?= $psLog ? 'checked' : '' ?>>
            <label class="form-check-label" for="psLog">Sicherheitsereignisse protokollieren</label>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Eingabe speichern</label>
              <select name="prompt_security_log_input" class="form-select form-select-sm">
                <option value="full" <?= $psLogInput === 'full' ? 'selected' : '' ?>>Vollständig</option>
                <option value="anon" <?= $psLogInput === 'anon' ? 'selected' : '' ?>>Anonymisiert (nur Länge)</option>
                <option value="none" <?= $psLogInput === 'none' ? 'selected' : '' ?>>Nicht speichern</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Aufbewahrung (Tage)</label>
              <input type="number" name="prompt_security_log_retention_days" class="form-control form-control-sm" value="<?= $psLogRetention ?>" min="1" max="365">
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header fw-semibold">KI-Bewertung (optional)</div>
        <div class="card-body">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="prompt_security_ai_enabled" id="psAiEnabled" <?= $psAiEnabled ? 'checked' : '' ?>>
            <label class="form-check-label" for="psAiEnabled">KI-Klassifikator aktivieren</label>
          </div>
          <div class="row g-3">
            <div class="col-md-5">
              <label class="form-label">Endpunkt (OpenAI-kompatibel)</label>
              <input type="url" name="prompt_security_ai_endpoint" class="form-control form-control-sm" value="<?= psH($psAiEndpoint) ?>" placeholder="http://localhost:1234/v1">
            </div>
            <div class="col-md-4">
              <label class="form-label">Modell</label>
              <input type="text" name="prompt_security_ai_model" class="form-control form-control-sm" value="<?= psH($psAiModel) ?>">
            </div>
          </div>
        </div>
      </div>

      <button class="btn btn-primary" type="submit">Einstellungen speichern</button>
    </form>
    <?php endif; ?>

  </main>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
