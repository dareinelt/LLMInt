<?php

/**
 * logout.php
 *
 * Destroys the current session and redirects back to the chat page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

session_destroy();

// Do not log the user straight back in via proxy SSO (index.php).
session_start();
session_regenerate_id(true);
$_SESSION['sso_attempted'] = true;

header('Location: index.php');
exit;
