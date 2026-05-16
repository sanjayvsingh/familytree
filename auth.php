<?php
require_once __DIR__ . '/auth_lib.php';

// Probabilistic cleanup — runs on ~5% of requests
if (random_int(1, 20) === 1) {
    auth_cleanup();
}

$action = $_GET['action'] ?? '';

// ── GET: magic link click ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'verify') {
    $rawToken = $_GET['token'] ?? '';
    $email    = $rawToken ? auth_verify_token($rawToken) : null;

    if (!$email) {
        // Expired or already used — show a friendly error page
        http_response_code(400);
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign-in link expired</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="login-mode">
<div id="login-wrap">
  <div id="login-card">
    <h1>Family Tree</h1>
    <p class="login-sub">This sign-in link has expired or already been used.</p>
    <a href="./" style="display:block;text-align:center;margin-top:20px;color:var(--primary)">← Back to sign-in</a>
  </div>
</div>
</body>
</html>
HTML;
        exit;
    }

    $ip      = auth_get_ip();
    $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $session = auth_create_session($email, $ip, $ua);
    auth_set_session_cookie($session);

    // Write session to localStorage then redirect — token never appears in URL
    $sessionJson = json_encode($session);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Signing in…</title>
</head>
<body>
<script>
try { localStorage.setItem('familytree:session', {$sessionJson}); } catch(e) {}
window.location.replace('./');
</script>
<noscript><meta http-equiv="refresh" content="0;url=./"></noscript>
</body>
</html>
HTML;
    exit;
}

// ── POST endpoints ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_json(405, ['error' => 'method_not_allowed']);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

// ── POST request: send magic link ─────────────────────────────────────────
if ($action === 'request') {
    // Rate-check first so invalid/disallowed emails still count
    $ip = auth_get_ip();
    if (!auth_rate_check($ip)) {
        $retry = auth_rate_retry_after($ip);
        header('Retry-After: ' . $retry);
        auth_json(429, ['error' => 'rate_limited', 'retry_after' => $retry]);
    }
    auth_rate_record($ip);

    $email = trim($body['email'] ?? '');

    // Invalid email format or disallowed — silently return ok to prevent enumeration
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        auth_json(200, ['ok' => true]);
    }

    $cfg     = auth_config();
    $allowed = $cfg['allowed_emails'] ?? [];
    if ($allowed && !in_array(strtolower($email), array_map('strtolower', $allowed), true)) {
        auth_json(200, ['ok' => true]);
    }

    $rawToken = auth_generate_token();
    $code     = auth_save_token($rawToken, $email);
    auth_send_email($email, $rawToken, $code); // fire-and-forget; don't reveal delivery status

    auth_json(200, ['ok' => true]);
}

// ── POST verify: code entry ────────────────────────────────────────────────
if ($action === 'verify') {
    $ip = auth_get_ip();
    if (!auth_rate_check($ip)) {
        $retry = auth_rate_retry_after($ip);
        header('Retry-After: ' . $retry);
        auth_json(429, ['error' => 'rate_limited', 'retry_after' => $retry]);
    }
    auth_rate_record($ip);

    $code  = trim($body['code'] ?? '');
    $email = $code ? auth_verify_code($code) : null;

    if (!$email) {
        auth_json(401, ['error' => 'invalid_code']);
    }

    $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $session = auth_create_session($email, $ip, $ua);
    auth_set_session_cookie($session);

    auth_json(200, ['ok' => true, 'session' => $session]);
}

// ── POST check: validate an existing session ───────────────────────────────
if ($action === 'check') {
    $token = auth_get_bearer();
    $email = $token ? auth_validate_session($token) : null;

    if (!$email) {
        auth_json(401, ['error' => 'invalid_session']);
    }

    auth_json(200, ['ok' => true, 'email' => $email]);
}

// ── POST logout ────────────────────────────────────────────────────────────
if ($action === 'logout') {
    $token = auth_get_bearer();
    if ($token) auth_destroy_session($token);

    // Clear the cookie
    $ssl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('ft_session', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $ssl,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    auth_json(200, ['ok' => true]);
}

auth_json(400, ['error' => 'unknown_action']);
