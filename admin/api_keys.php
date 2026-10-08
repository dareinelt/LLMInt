<?php

/**
 * admin/api_keys.php
 *
 * The API-key management UI now lives inside the admin dashboard
 * (admin/index.php, card #api-keys-card) so that it is rendered in the same tab
 * and with the sidebar navigation visible, like every other admin section.
 * This entry point is kept for backwards compatibility with existing bookmarks
 * and links and simply forwards to that card.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_user'], $_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

header('Location: index.php#api-keys-card', true, 302);
exit;
