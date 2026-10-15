<?php

/**
 * admin/log_viewer.php
 *
 * Log - Log-Ansicht mit Filter, Sortierung und Seitenblaettern.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'log_viewer';
$adminPageTitle = 'Log';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Logging – Log viewer
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="log-viewer-card">
        <details class="config-panel" id="log-viewer" open>
            <summary>📋 Protokollierung – Log</summary>

            <!-- Filter form -->
            <form method="GET" action="#log-viewer-card" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:20px">
                <div class="form-group" style="margin:0;flex:0 0 150px">
                    <label for="lf-from" style="font-size:.8rem">Datum von</label>
                    <input type="date" id="lf-from" name="log_from"
                           value="<?= htmlspecialchars($logFilterDateFrom) ?>"
                           style="padding:6px 8px;font-size:.85rem">
                </div>
                <div class="form-group" style="margin:0;flex:0 0 150px">
                    <label for="lf-to" style="font-size:.8rem">Datum bis</label>
                    <input type="date" id="lf-to" name="log_to"
                           value="<?= htmlspecialchars($logFilterDateTo) ?>"
                           style="padding:6px 8px;font-size:.85rem">
                </div>
                <div class="form-group" style="margin:0;flex:1 1 200px">
                    <label for="lf-kw" style="font-size:.8rem">Stichwort</label>
                    <input type="text" id="lf-kw" name="log_keyword"
                           value="<?= htmlspecialchars($logFilterKeyword) ?>"
                           placeholder="z.&thinsp;B. Websuche"
                           style="padding:6px 8px;font-size:.85rem">
                </div>
                <div class="form-group" style="margin:0;flex:0 0 130px">
                    <label for="lf-lv" style="font-size:.8rem">Level</label>
                    <select id="lf-lv" name="log_lv" style="padding:6px 8px;font-size:.85rem">
                        <option value=""        <?= $logFilterLevel === ''        ? 'selected' : '' ?>>Alle</option>
                        <option value="info"    <?= $logFilterLevel === 'info'    ? 'selected' : '' ?>>Info</option>
                        <option value="warning" <?= $logFilterLevel === 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="error"   <?= $logFilterLevel === 'error'   ? 'selected' : '' ?>>Error</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;flex:0 0 130px">
                    <label for="lf-sort" style="font-size:.8rem">Sortierung</label>
                    <select id="lf-sort" name="log_sort" style="padding:6px 8px;font-size:.85rem">
                        <option value="desc" <?= $logSortDir === 'desc' ? 'selected' : '' ?>>Neueste zuerst</option>
                        <option value="asc"  <?= $logSortDir === 'asc'  ? 'selected' : '' ?>>Älteste zuerst</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px;align-items:center;padding-bottom:2px">
                    <button type="submit" class="btn btn-primary" style="padding:7px 16px;font-size:.85rem">🔍 Filtern</button>
                    <a href="log_viewer.php" class="btn" style="padding:7px 12px;font-size:.85rem;text-decoration:none">✕ Zurücksetzen</a>
                </div>
            </form>

            <!-- Result count -->
            <p style="font-size:.82rem;color:var(--muted);margin-bottom:12px">
                <?= number_format($logTotal) ?> Einträge gefunden
                <?php if ($logTotal > $logPerPage): ?>
                    &nbsp;·&nbsp; Seite <?= $logPage ?> von <?= $logTotalPages ?>
                <?php endif; ?>
            </p>

            <?php if (empty($logRows)): ?>
                <p style="color:var(--muted);font-size:.9rem">Keine Log-Einträge gefunden.</p>
            <?php else: ?>
            <div style="overflow-x:auto">
                <table class="data-table" style="font-size:.83rem">
                    <thead>
                        <tr>
                            <th style="white-space:nowrap;width:160px">Zeitstempel</th>
                            <th style="width:80px">Level</th>
                            <th>Meldung</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logRows as $lr): ?>
                        <?php
                            $lvColor = match ($lr['level']) {
                                'warning' => 'var(--warning,#f59e0b)',
                                'error'   => 'var(--danger,#ef4444)',
                                default   => 'var(--success,#22c55e)',
                            };
                        ?>
                        <tr>
                            <td style="white-space:nowrap;color:var(--muted)"><?= htmlspecialchars($lr['created_at']) ?></td>
                            <td style="font-weight:600;color:<?= $lvColor ?>"><?= htmlspecialchars(strtoupper($lr['level'])) ?></td>
                            <td style="word-break:break-word;max-width:700px"><?php
                                $lrMessage = (string) $lr['message'];
                                if (preg_match('/^(\[API(?: · [^\]]*)?\])\s*(.*)$/su', $lrMessage, $lrApi)):
                            ?><span title="Zugriff über die OpenAI-kompatible API" style="display:inline-block;margin-right:6px;padding:1px 7px;border-radius:999px;background:rgba(108,99,255,.18);border:1px solid rgba(108,99,255,.45);color:#b8b3ff;font-size:.75rem;font-weight:600;white-space:nowrap"><?= htmlspecialchars(trim($lrApi[1], '[]')) ?></span><?= htmlspecialchars($lrApi[2]) ?><?php
                                else:
                            ?><?= htmlspecialchars($lrMessage) ?><?php
                                endif;
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($logTotalPages > 1): ?>
                <!-- Pagination -->
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:16px;align-items:center;font-size:.83rem">
                    <?php
                        $baseLogUrl = '?' . http_build_query(array_filter([
                            'log_from'    => $logFilterDateFrom,
                            'log_to'      => $logFilterDateTo,
                            'log_keyword' => $logFilterKeyword,
                            'log_lv'      => $logFilterLevel,
                            'log_sort'    => $logSortDir,
                        ]));
                        $pageFrom = max(1, $logPage - 3);
                        $pageTo   = min($logTotalPages, $logPage + 3);
                    ?>
                    <?php if ($logPage > 1): ?>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=1') ?>#log-viewer-card" class="btn" style="padding:4px 10px">«</a>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=' . ($logPage - 1)) ?>#log-viewer-card" class="btn" style="padding:4px 10px">‹</a>
                    <?php endif; ?>
                    <?php for ($p = $pageFrom; $p <= $pageTo; $p++): ?>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=' . $p) ?>#log-viewer-card"
                           class="btn<?= $p === $logPage ? ' btn-primary' : '' ?>"
                           style="padding:4px 10px"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($logPage < $logTotalPages): ?>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=' . ($logPage + 1)) ?>#log-viewer-card" class="btn" style="padding:4px 10px">›</a>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=' . $logTotalPages) ?>#log-viewer-card" class="btn" style="padding:4px 10px">»</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>

        </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
