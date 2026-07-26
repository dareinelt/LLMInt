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
header('Location: index.php');
exit;
