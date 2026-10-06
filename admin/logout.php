<?php

/**
 * admin/logout.php
 *
 * Destroys the admin session and redirects to the login page.
 */

session_start();
session_unset();
session_destroy();

// Do not log the user straight back in via proxy SSO (login.php).
session_start();
session_regenerate_id(true);
$_SESSION['sso_attempted'] = true;

header('Location: login.php');
exit;
