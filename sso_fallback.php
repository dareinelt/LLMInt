<?php

/**
 * sso_fallback.php
 *
 * ErrorDocument of the reverse proxy for sso.php (lanpa auth container):
 * shown with status 401/500 when the browser did not perform a Windows login.
 * Marks the SSO attempt as done and leads back to the target page. A meta
 * refresh is used because the proxy keeps the error status, so a Location
 * header would not be followed.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$targets = ['index.php' => 'index.php', 'admin' => 'admin/login.php'];
$target  = $targets[$_SESSION['sso_return'] ?? 'index.php'] ?? 'index.php';
unset($_SESSION['sso_return']);
$_SESSION['sso_attempted'] = true;

// The proxy serves this page as body of the Negotiate challenge; only a 401
// lets domain browsers continue with Kerberos/NTLM.
http_response_code(401);
header('Cache-Control: no-store');
$href = htmlspecialchars($target, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0; url=<?= $href ?>">
    <title>Weiterleitung – KHWF KI</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
               background: #212121; color: #ececf1; display: flex; align-items: center;
               justify-content: center; min-height: 100vh; margin: 0; }
        a { color: #6c63ff; }
    </style>
</head>
<body>
    <p>Keine Windows-Anmeldung erkannt. <a href="<?= $href ?>">Weiter</a></p>
</body>
</html>
