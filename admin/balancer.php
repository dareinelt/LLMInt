<?php

/**
 * admin/balancer.php
 *
 * Balancer & Routing - Lastverteilung: Nebenlaeufigkeit, Circuit-Breaker und Backoff.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'balancer';
$adminPageTitle = 'Balancer & Routing';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <div class="card" id="config-balancer-card">
       <details class="config-panel" id="config-balancer">
           <summary>⚖️ Balancer &amp; Routing</summary>

           <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="action" value="save_balancer_settings">

              <div class="form-group">
                  <label for="balancer-max-concurrent">Maximale gleichzeitige Tasks je Endpunkt</label>
                  <input type="number" id="balancer-max-concurrent" name="balancer_max_concurrent"
                         min="1" max="100" value="<?= (int) $balancerMaxConcurrent ?>">
                  <p class="hint">Ersetzt das früher fest codierte Limit von 4 gleichzeitig laufenden Tasks pro Endpunkt.</p>
              </div>

              <div class="form-group">
                  <label for="balancer-fairness-window">Fairness-Fenster (Sekunden)</label>
                  <input type="number" id="balancer-fairness-window" name="balancer_fairness_window_seconds"
                         min="60" max="86400" value="<?= (int) $balancerFairnessWindowSecs ?>">
                  <p class="hint">Zeitfenster, über das die bereits zugeteilten Aufgaben je Endpunkt gezählt werden. Endpunkte mit weniger Aufgaben in diesem Fenster werden bevorzugt, damit gleichwertige Endpunkte denselben Anteil erhalten. Die gemessene Latenz fließt bewusst nicht in die Routing-Entscheidung ein (alle Endpunkte liegen im selben Subnetz) und dient nur der Statistik.</p>
              </div>

              <div class="form-group">
                  <label for="balancer-circuit-fail-threshold">Circuit-Breaker: Fehler bis zur Sperrung</label>
                  <input type="number" id="balancer-circuit-fail-threshold" name="balancer_circuit_fail_threshold"
                         min="1" max="100" value="<?= (int) $balancerCircuitFailThreshold ?>">
                  <p class="hint">Anzahl aufeinanderfolgender Fehlschläge, nach denen ein Endpunkt vorübergehend aus dem Routing genommen wird.</p>
              </div>

              <div class="form-group">
                  <label for="balancer-circuit-cooldown">Circuit-Breaker: Cooldown (Sekunden)</label>
                  <input type="number" id="balancer-circuit-cooldown" name="balancer_circuit_cooldown_seconds"
                         min="1" max="3600" value="<?= (int) $balancerCircuitCooldownSecs ?>">
                  <p class="hint">Wartezeit, bevor ein gesperrter Endpunkt automatisch mit einer Testanfrage (Half-Open) reaktiviert wird.</p>
              </div>

              <div class="form-group">
                  <label for="balancer-orphan-timeout">Bereinigung verwaister Tasks nach (Sekunden)</label>
                  <input type="number" id="balancer-orphan-timeout" name="balancer_orphan_timeout_seconds"
                         min="10" max="86400" value="<?= (int) $balancerOrphanTimeoutSecs ?>">
                  <p class="hint">Tasks, die länger als diese Zeit im Status „running“ verharren (z. B. durch einen Absturz), werden automatisch als Fehler markiert und geben den Slot wieder frei.</p>
              </div>

              <div class="form-group">
                  <label for="balancer-backoff-base">Backoff-Basiswert (ms)</label>
                  <input type="number" id="balancer-backoff-base" name="balancer_backoff_base_ms"
                         min="1" max="60000" value="<?= (int) $balancerBackoffBaseMs ?>">
              </div>

              <div class="form-group">
                  <label for="balancer-backoff-max">Backoff-Maximalwert (ms)</label>
                  <input type="number" id="balancer-backoff-max" name="balancer_backoff_max_ms"
                         min="1" max="600000" value="<?= (int) $balancerBackoffMaxMs ?>">
                  <p class="hint">Exponentielles Backoff zwischen Wiederholungsversuchen auf einen anderen Endpunkt, begrenzt auf diesen Maximalwert.</p>
              </div>

              <div class="form-group">
                  <label>
                      <input type="checkbox" name="balancer_backoff_jitter" <?= $balancerBackoffJitter ? 'checked' : '' ?>>
                      Zufälligen Jitter zum Backoff hinzufügen
                  </label>
                  <p class="hint">Vermeidet synchronisierte Wiederholungsversuche mehrerer gleichzeitiger Anfragen ("Retry-Storm").</p>
              </div>

              <div class="form-group">
                  <label for="balancer-fallback-chains">Konfigurierbare Fallback-Ketten (JSON)</label>
                  <textarea id="balancer-fallback-chains" name="balancer_fallback_chains" rows="4"
                            style="width:100%;font-family:monospace"><?= htmlspecialchars($balancerFallbackChainsJson) ?></textarea>
                  <p class="hint">
                      Ordnet einem angefragten Modell eine geordnete Liste von Ausweichmodellen zu, z. B.
                      <code>{"big-model:70b": ["big-model:34b", "big-model:8b"]}</code>. Wird verwendet, bevor ein Endpunkt
                      endgültig als nicht verfügbar gilt.
                  </p>
              </div>

              <div class="action-row">
                  <button type="submit" class="btn btn-primary">💾 Speichern</button>
              </div>
           </form>
       </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
