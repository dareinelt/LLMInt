<?php

/**
 * admin/partials/nav.php
 *
 * Left navigation of the admin area. Derived from the sidebar of the former
 * one-pager: every entry links to its own page now instead of a #anchor, so
 * the navigation doubles as the page index. The active entry is marked with
 * the "active" class.
 */

/** Renders the sidebar. $active is the navigation slug of the current page. */
function admin_nav(string $active): void
{
    ?>
<aside class="sidebar">
    <span class="sidebar-label">Übersicht</span>
    <a href="index.php"<?= $active === 'dashboard' ? ' class="active"' : '' ?>">🚀 Dashboard</a>
    <a href="usage.php"<?= $active === 'usage' ? ' class="active"' : '' ?>">📈 Nutzungsstatistik</a>

    <span class="sidebar-label">Konfiguration</span>
    <a href="smtp.php"<?= $active === 'smtp' ? ' class="active"' : '' ?>">📧 E-Mail (SMTP)</a>
    <a href="ldap.php"<?= $active === 'ldap' ? ' class="active"' : '' ?>">🏢 Active Directory</a>
    <a href="speech.php"<?= $active === 'speech' ? ' class="active"' : '' ?>">🎙️ Spracherkennung</a>
    <a href="image.php"<?= $active === 'image' ? ' class="active"' : '' ?>">🖼️ Bildgenerierung</a>
    <a href="search.php"<?= $active === 'search' ? ' class="active"' : '' ?>">🔎 Websuche</a>
    <a href="endpoints.php"<?= $active === 'endpoints' ? ' class="active"' : '' ?>">🔗 Endpunkte</a>
    <a href="request_handling.php"<?= $active === 'request_handling' ? ' class="active"' : '' ?>">📨 Anfragenhandling</a>
    <a href="system_prompt.php"<?= $active === 'system_prompt' ? ' class="active"' : '' ?>">🧠 Systemprompt</a>
    <a href="balancer.php"<?= $active === 'balancer' ? ' class="active"' : '' ?>">⚖️ Balancer &amp; Routing</a>
    <a href="routing.php"<?= $active === 'routing' ? ' class="active"' : '' ?>">🧭 Modellrouting</a>
    <a href="decision.php"<?= $active === 'decision' ? ' class="active"' : '' ?>">🗂️ Entscheidungsfindung</a>

    <span class="sidebar-label">Hybrid-RAG</span>
    <a href="vector_store.php"<?= $active === 'vector_store' ? ' class="active"' : '' ?>">🧠 Wissensdatenbank</a>
    <a href="embedding.php"<?= $active === 'embedding' ? ' class="active"' : '' ?>">🧬 Embedding-Endpunkte</a>
    <a href="hybrid_search.php"<?= $active === 'hybrid_search' ? ' class="active"' : '' ?>">🔀 Hybrid-Suche</a>
    <a href="reranker.php"<?= $active === 'reranker' ? ' class="active"' : '' ?>">🏆 Reranker</a>
    <a href="rag_stats.php"<?= $active === 'rag_stats' ? ' class="active"' : '' ?>">📊 RAG-Statistik</a>

    <span class="sidebar-label">Systemmeldungen</span>
    <a href="system_messages.php"<?= $active === 'system_messages' ? ' class="active"' : '' ?>">💬 Systemmeldungen</a>

    <span class="sidebar-label">Protokollierung</span>
    <a href="log_config.php"<?= $active === 'log_config' ? ' class="active"' : '' ?>">⚙️ Konfiguration</a>
    <a href="log_viewer.php"<?= $active === 'log_viewer' ? ' class="active"' : '' ?>">📋 Log</a>

    <span class="sidebar-label">Verwaltung</span>
    <a href="users.php"<?= $active === 'users' ? ' class="active"' : '' ?>">👤 Benutzerkonten</a>
    <a href="openai_api.php"<?= $active === 'openai_api' ? ' class="active"' : '' ?>">🔌 OpenAI-API</a>
    <a href="api_keys.php"<?= $active === 'api_keys' ? ' class="active"' : '' ?>">🗝️ API-Keys</a>
    <a href="password.php"<?= $active === 'password' ? ' class="active"' : '' ?>">🔑 Passwort ändern</a>
    <a href="endpoint_tech.php">🛠️ Endpunkte technische Verwaltung</a>
</aside>
    <?php
}
