<?php

/**
 * admin/usage.php
 *
 * Nutzungsstatistik - Taegliche Nutzung (Clients, Benutzer, Tasks, fehlgeschlagene Tasks, Suchen) als Diagramm.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'usage';
$adminPageTitle = 'Nutzungsstatistik';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Nutzungsstatistik
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="usage-stats-card">
        <details class="config-panel" id="usage-stats" open>
            <summary>📈 Nutzungsstatistik</summary>

            <div class="usage-range" id="usage-range" role="group" aria-label="Zeitraum">
                <button type="button" data-days="3">3 Tage</button>
                <button type="button" data-days="7">7 Tage</button>
                <button type="button" data-days="14">14 Tage</button>
                <button type="button" data-days="30" class="active">30 Tage</button>
                <button type="button" data-days="90">90 Tage</button>
                <button type="button" data-days="180">180 Tage</button>
                <button type="button" data-days="365">1 Jahr</button>
            </div>

            <div class="usage-chart-wrap">
                <canvas id="usage-chart" role="img"
                        aria-label="Liniendiagramm der Nutzungsstatistik"></canvas>
                <div class="usage-tooltip" id="usage-tooltip"></div>
            </div>

            <div class="usage-legend" id="usage-legend"></div>

            <p class="hint" id="usage-hint">
                Zeigt pro Tag die Spitzenanzahl gleichzeitig verbundener Clients, die Anzahl
                angemeldeter Nutzer, die durchgeführten Tasks und Websuchen sowie die
                fehlgeschlagenen Tasks. Auf die Legende klicken, um einzelne Reihen ein- oder
                auszublenden.
            </p>
        </details>
    </div>

<script>
/* ── Nutzungsstatistik: retina-ready line chart ──────────────────────────────
   Draws the daily usage series (clients, logged-in users, tasks, failed tasks,
   web searches) on a HiDPI-scaled canvas. Data comes from usage_stats.php.  */
(function () {
    const canvas = document.getElementById('usage-chart');
    if (!canvas || !canvas.getContext) { return; }

    const ctx        = canvas.getContext('2d');
    const rangeBox   = document.getElementById('usage-range');
    const legendBox  = document.getElementById('usage-legend');
    const tooltipEl  = document.getElementById('usage-tooltip');
    const wrap       = canvas.parentElement;

    const SERIES = [
        { key: 'clients',      label: 'Clients',            color: '#6c63ff' },
        { key: 'users',        label: 'Angemeldete Nutzer', color: '#22c55e' },
        { key: 'tasks',        label: 'Tasks',              color: '#38bdf8' },
        { key: 'searches',     label: 'Websuchen',          color: '#f59e0b' },
        { key: 'tasks_failed', label: 'Fehlgeschl. Tasks',  color: '#ef4444' }
    ];

    const hidden  = new Set();
    let days      = 30;
    let rows      = [];
    let hoverIdx  = -1;
    let plot      = null;   // { x0, y0, x1, y1, max }

    /* ── Legend ─────────────────────────────────────────────────────────── */
    SERIES.forEach(function (s) {
        const item = document.createElement('span');
        item.className = 'legend-item on';
        item.innerHTML = '<span class="legend-swatch" style="background:' + s.color + '"></span>';
        item.appendChild(document.createTextNode(s.label));
        item.addEventListener('click', function () {
            if (hidden.has(s.key)) { hidden.delete(s.key); } else { hidden.add(s.key); }
            item.classList.toggle('on', !hidden.has(s.key));
            draw();
        });
        legendBox.appendChild(item);
    });

    /* ── Helpers ────────────────────────────────────────────────────────── */
    function fmtDate(iso) {
        const p = String(iso).split('-');
        return p.length === 3 ? p[2] + '.' + p[1] + '.' : iso;
    }

    function niceMax(value) {
        if (value <= 5) { return 5; }
        const exp  = Math.pow(10, Math.floor(Math.log10(value)));
        const norm = value / exp;
        const step = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10;
        return step * exp;
    }

    /* ── Rendering ──────────────────────────────────────────────────────── */
    function draw() {
        const dpr    = window.devicePixelRatio || 1;
        const cssW   = canvas.clientWidth  || 600;
        const cssH   = canvas.clientHeight || 320;
        const pxW    = Math.round(cssW * dpr);
        const pxH    = Math.round(cssH * dpr);

        if (canvas.width !== pxW || canvas.height !== pxH) {
            canvas.width  = pxW;
            canvas.height = pxH;
        }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cssW, cssH);

        const styles     = getComputedStyle(document.documentElement);
        const textMuted  = (styles.getPropertyValue('--text-muted') || '#8e8ea0').trim();
        const gridColor  = 'rgba(255,255,255,.08)';

        if (!rows.length) {
            ctx.fillStyle = textMuted;
            ctx.font      = '13px ' + (styles.getPropertyValue('--font') || 'sans-serif').trim();
            ctx.textAlign = 'center';
            ctx.fillText('Keine Daten für diesen Zeitraum.', cssW / 2, cssH / 2);
            plot = null;
            return;
        }

        const padL = 48, padR = 12, padT = 12, padB = 28;
        const x0 = padL, x1 = cssW - padR, y0 = padT, y1 = cssH - padB;
        const w  = Math.max(1, x1 - x0);
        const h  = Math.max(1, y1 - y0);

        let peak = 0;
        rows.forEach(function (r) {
            SERIES.forEach(function (s) {
                if (!hidden.has(s.key)) { peak = Math.max(peak, Number(r[s.key]) || 0); }
            });
        });
        const max = niceMax(peak);

        const xFor = function (i) {
            return rows.length === 1 ? x0 + w / 2 : x0 + (w * i) / (rows.length - 1);
        };
        const yFor = function (v) { return y1 - (h * (Number(v) || 0)) / max; };

        ctx.font      = '11px ' + (styles.getPropertyValue('--font') || 'sans-serif').trim();
        ctx.lineWidth = 1;

        // Horizontal grid + y axis labels
        const ticks = 4;
        ctx.textAlign    = 'right';
        ctx.textBaseline = 'middle';
        for (let t = 0; t <= ticks; t++) {
            const val = (max / ticks) * t;
            const y   = Math.round(yFor(val)) + 0.5;
            ctx.strokeStyle = gridColor;
            ctx.beginPath();
            ctx.moveTo(x0, y);
            ctx.lineTo(x1, y);
            ctx.stroke();
            ctx.fillStyle = textMuted;
            ctx.fillText(String(Math.round(val)), x0 - 8, y);
        }

        // X axis labels (thinned out so they never overlap)
        const maxLabels = Math.max(2, Math.floor(w / 62));
        const stepLabel = Math.max(1, Math.ceil(rows.length / maxLabels));
        ctx.textAlign    = 'center';
        ctx.textBaseline = 'top';
        ctx.fillStyle    = textMuted;
        const lastIdx = rows.length - 1;
        for (let i = 0; i < rows.length; i++) {
            if (i % stepLabel !== 0 && i !== lastIdx) { continue; }
            // Drop labels that would collide with the always-drawn last one
            if (i !== lastIdx && lastIdx - i < stepLabel * 0.75) { continue; }
            ctx.textAlign = i === lastIdx ? 'right' : (i === 0 ? 'left' : 'center');
            ctx.fillText(fmtDate(rows[i].date), xFor(i), y1 + 8);
        }
        ctx.textAlign = 'center';

        // Hover crosshair
        if (hoverIdx >= 0 && hoverIdx < rows.length) {
            const hx = Math.round(xFor(hoverIdx)) + 0.5;
            ctx.strokeStyle = 'rgba(255,255,255,.25)';
            ctx.beginPath();
            ctx.moveTo(hx, y0);
            ctx.lineTo(hx, y1);
            ctx.stroke();
        }

        // Series lines
        ctx.lineWidth   = 2;
        ctx.lineJoin    = 'round';
        ctx.lineCap     = 'round';
        SERIES.forEach(function (s) {
            if (hidden.has(s.key)) { return; }
            ctx.strokeStyle = s.color;
            ctx.beginPath();
            rows.forEach(function (r, i) {
                const px = xFor(i), py = yFor(r[s.key]);
                if (i === 0) { ctx.moveTo(px, py); } else { ctx.lineTo(px, py); }
            });
            ctx.stroke();

            // Dots only when the points are far enough apart to stay readable
            if (rows.length <= 31) {
                ctx.fillStyle = s.color;
                rows.forEach(function (r, i) {
                    ctx.beginPath();
                    ctx.arc(xFor(i), yFor(r[s.key]), 2.5, 0, Math.PI * 2);
                    ctx.fill();
                });
            }
            if (hoverIdx >= 0 && hoverIdx < rows.length) {
                ctx.fillStyle = s.color;
                ctx.beginPath();
                ctx.arc(xFor(hoverIdx), yFor(rows[hoverIdx][s.key]), 3.5, 0, Math.PI * 2);
                ctx.fill();
            }
        });

        plot = { x0: x0, x1: x1, y0: y0, y1: y1, max: max };
    }

    /* ── Tooltip ────────────────────────────────────────────────────────── */
    function indexFromEvent(ev) {
        if (!plot || !rows.length) { return -1; }
        const rect = canvas.getBoundingClientRect();
        const x    = ev.clientX - rect.left;
        if (x < plot.x0 - 10 || x > plot.x1 + 10) { return -1; }
        if (rows.length === 1) { return 0; }
        const ratio = (x - plot.x0) / (plot.x1 - plot.x0);
        return Math.max(0, Math.min(rows.length - 1, Math.round(ratio * (rows.length - 1))));
    }

    function showTooltip(idx, ev) {
        const r = rows[idx];
        let html = '<strong>' + fmtDate(r.date) + '</strong>';
        SERIES.forEach(function (s) {
            if (hidden.has(s.key)) { return; }
            html += '<br><span style="color:' + s.color + '">●</span> '
                  + s.label + ': <strong>' + (Number(r[s.key]) || 0) + '</strong>';
        });
        tooltipEl.innerHTML     = html;
        tooltipEl.style.display = 'block';

        const rect = canvas.getBoundingClientRect();
        let left   = ev.clientX - rect.left + 14;
        if (left + tooltipEl.offsetWidth > canvas.clientWidth) {
            left = Math.max(0, ev.clientX - rect.left - tooltipEl.offsetWidth - 14);
        }
        tooltipEl.style.left = left + 'px';
        tooltipEl.style.top  = Math.max(0, ev.clientY - rect.top - tooltipEl.offsetHeight - 12) + 'px';
    }

    canvas.addEventListener('mousemove', function (ev) {
        const idx = indexFromEvent(ev);
        if (idx < 0) {
            if (hoverIdx !== -1) { hoverIdx = -1; draw(); }
            tooltipEl.style.display = 'none';
            return;
        }
        if (idx !== hoverIdx) { hoverIdx = idx; draw(); }
        showTooltip(idx, ev);
    });

    canvas.addEventListener('mouseleave', function () {
        hoverIdx = -1;
        tooltipEl.style.display = 'none';
        draw();
    });

    /* ── Data loading ───────────────────────────────────────────────────── */
    async function load() {
        try {
            const res = await fetch('usage_stats.php?days=' + days, { cache: 'no-store' });
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            const data = await res.json();
            if (!data || data.ok !== true) { throw new Error('Antwort ungültig'); }
            rows     = Array.isArray(data.series) ? data.series : [];
            hoverIdx = -1;
            draw();
        } catch (err) {
            rows = [];
            draw();
        }
    }

    rangeBox.addEventListener('click', function (ev) {
        const btn = ev.target.closest('button[data-days]');
        if (!btn) { return; }
        days = parseInt(btn.dataset.days, 10) || 30;
        rangeBox.querySelectorAll('button').forEach(function (b) {
            b.classList.toggle('active', b === btn);
        });
        load();
    });

    if (window.ResizeObserver) {
        new ResizeObserver(function () { draw(); }).observe(wrap);
    } else {
        window.addEventListener('resize', draw);
    }
    // Re-render when the display changes (e.g. window moved to another screen)
    if (window.matchMedia) {
        const mq = window.matchMedia('(resolution: 1dppx)');
        if (mq.addEventListener) { mq.addEventListener('change', draw); }
    }

    load();
    setInterval(function () { if (!document.hidden) { load(); } }, 60000);
}());
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
