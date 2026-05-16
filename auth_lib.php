<?php
// Auth library: tokens, sessions, rate limiting, SMTP2GO email, access logging.
// PHP 7.4+ compatible.

function auth_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        if (!file_exists($path)) {
            die('config.php not found — copy config.example.php to config.php and fill in your settings.');
        }
        $cfg = require $path;
        auth_ensure_dirs($cfg);
    }
    return $cfg;
}

function auth_ensure_dirs(array $cfg): void {
    $dirs = [
        $cfg['auth_dir'],
        $cfg['auth_dir'] . 'tokens/',
        $cfg['auth_dir'] . 'sessions/',
        $cfg['auth_dir'] . 'rate/',
        $cfg['auth_dir'] . 'logs/',
    ];
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
    }
    $htaccess = $cfg['auth_dir'] . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess,
            "# Block all direct HTTP access to this directory\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
        );
    }
}

function auth_get_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── Rate limiting (sliding window per IP) ─────────────────────────────────

function auth_rate_check(string $ip): bool {
    $cfg  = auth_config();
    $file = $cfg['auth_dir'] . 'rate/' . hash('sha256', $ip) . '.json';
    $now  = time();

    if (!file_exists($file)) return true;

    $data = json_decode(file_get_contents($file), true);
    $reqs = array_filter($data['requests'] ?? [], function ($t) use ($now, $cfg) {
        return $t > $now - $cfg['rate_window'];
    });

    return count($reqs) < $cfg['rate_max_requests'];
}

function auth_rate_record(string $ip): void {
    $cfg  = auth_config();
    $file = $cfg['auth_dir'] . 'rate/' . hash('sha256', $ip) . '.json';
    $now  = time();

    $fp = fopen($file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);

    $content = stream_get_contents($fp);
    $data    = $content ? json_decode($content, true) : null;
    $reqs    = array_filter($data['requests'] ?? [], function ($t) use ($now, $cfg) {
        return $t > $now - $cfg['rate_window'];
    });
    $reqs[]  = $now;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['requests' => array_values($reqs)]));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function auth_rate_retry_after(string $ip): int {
    $cfg  = auth_config();
    $file = $cfg['auth_dir'] . 'rate/' . hash('sha256', $ip) . '.json';
    $now  = time();

    if (!file_exists($file)) return 0;

    $data = json_decode(file_get_contents($file), true);
    $reqs = array_filter($data['requests'] ?? [], function ($t) use ($now, $cfg) {
        return $t > $now - $cfg['rate_window'];
    });
    if (count($reqs) < $cfg['rate_max_requests']) return 0;

    $oldest = min($reqs);
    return max(0, ($oldest + $cfg['rate_window']) - $now);
}

// ── Token generation ──────────────────────────────────────────────────────

function auth_generate_token(): string {
    return bin2hex(random_bytes(32)); // 64-char hex, 256 bits entropy
}

function auth_generate_code(): string {
    return str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
}

// ── Token storage & verification ──────────────────────────────────────────

function auth_save_token(string $rawToken, string $email): string {
    $cfg  = auth_config();
    $code = auth_generate_code();
    $hash = hash('sha256', $rawToken);
    $now  = time();

    $oldUmask = umask(0177); // new files get mode 0600

    // Token file (indexed by sha256 of raw token)
    $tokenFile = $cfg['auth_dir'] . 'tokens/' . $hash . '.json';
    file_put_contents($tokenFile, json_encode([
        'email'   => $email,
        'code'    => $code,
        'expires' => $now + $cfg['magic_token_ttl'],
        'used'    => false,
        'ip'      => auth_get_ip(),
    ]));

    // Code ref file — lets us look up the token file from a 6-digit code
    $refFile = $cfg['auth_dir'] . 'tokens/code_' . $code . '.ref';
    file_put_contents($refFile, $hash);

    umask($oldUmask);

    return $code;
}

// Returns email on success, null on failure. Consumes the token (single-use).
function auth_verify_token(string $rawToken): ?string {
    $cfg  = auth_config();
    $hash = hash('sha256', $rawToken);
    $file = $cfg['auth_dir'] . 'tokens/' . $hash . '.json';

    if (!file_exists($file)) return null;

    $fp = fopen($file, 'r+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);

    $data = json_decode(stream_get_contents($fp), true);

    if (!$data || $data['used'] || time() > $data['expires']) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }

    // Mark used immediately
    $data['used'] = true;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);

    @unlink($cfg['auth_dir'] . 'tokens/code_' . $data['code'] . '.ref');

    return $data['email'];
}

// Returns email on success, null on failure. Consumes the token (single-use).
function auth_verify_code(string $code): ?string {
    $cfg     = auth_config();
    $clean   = preg_replace('/[^0-9]/', '', $code);
    if (strlen($clean) !== 3) return null;

    $refFile = $cfg['auth_dir'] . 'tokens/code_' . $clean . '.ref';
    if (!file_exists($refFile)) return null;

    $hash      = trim(file_get_contents($refFile));
    if (!$hash) return null;

    $tokenFile = $cfg['auth_dir'] . 'tokens/' . $hash . '.json';
    if (!file_exists($tokenFile)) return null;

    $fp = fopen($tokenFile, 'r+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);

    $data = json_decode(stream_get_contents($fp), true);

    if (!$data || $data['used'] || time() > $data['expires']) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }

    // Constant-time comparison prevents timing attacks on the code
    if (!hash_equals($data['code'], $clean)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }

    $data['used'] = true;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);

    @unlink($refFile);

    return $data['email'];
}

// ── Session management ────────────────────────────────────────────────────

function auth_create_session(string $email, string $ip, string $ua): string {
    $cfg   = auth_config();
    $token = auth_generate_token();
    $hash  = hash('sha256', $token);
    $now   = time();

    $oldUmask = umask(0177);
    file_put_contents($cfg['auth_dir'] . 'sessions/' . $hash . '.json', json_encode([
        'email'   => $email,
        'created' => $now,
        'expires' => $now + $cfg['session_ttl'],
        'ip'      => $ip,
        'ua'      => substr($ua, 0, 200),
    ]));
    umask($oldUmask);

    auth_log($email, $ip, $ua);

    return $token;
}

// Returns email string on success, null if invalid or expired.
function auth_validate_session(string $token): ?string {
    if (!$token || strlen($token) < 32) return null;

    $cfg  = auth_config();
    $hash = hash('sha256', $token);
    $file = $cfg['auth_dir'] . 'sessions/' . $hash . '.json';

    if (!file_exists($file)) return null;

    $data = json_decode(file_get_contents($file), true);
    if (!$data || time() > $data['expires']) return null;

    return $data['email'] ?? null;
}

function auth_destroy_session(string $token): void {
    $cfg  = auth_config();
    $hash = hash('sha256', $token);
    @unlink($cfg['auth_dir'] . 'sessions/' . $hash . '.json');
}

// ── Access log ────────────────────────────────────────────────────────────

function auth_log(string $email, string $ip, string $ua): void {
    $cfg  = auth_config();
    $line = implode("\t", [
        date('Y-m-d H:i:s'),
        $email,
        $ip,
        substr(str_replace(["\r", "\n", "\t"], ' ', $ua), 0, 200),
    ]) . "\n";
    $oldUmask = umask(0177);
    file_put_contents($cfg['log_file'], $line, FILE_APPEND | LOCK_EX);
    umask($oldUmask);
}

// ── Cleanup (called probabilistically) ───────────────────────────────────

function auth_cleanup(): void {
    $cfg = auth_config();
    $now = time();

    foreach (glob($cfg['auth_dir'] . 'tokens/*.json') ?: [] as $f) {
        $data = json_decode(file_get_contents($f), true);
        if (!$data) { @unlink($f); continue; }
        if ($now > $data['expires']) {
            if (!$data['used'] && isset($data['code'])) {
                @unlink($cfg['auth_dir'] . 'tokens/code_' . $data['code'] . '.ref');
            }
            @unlink($f);
        }
    }

    foreach (glob($cfg['auth_dir'] . 'sessions/*.json') ?: [] as $f) {
        $data = json_decode(file_get_contents($f), true);
        if (!$data || $now > $data['expires']) @unlink($f);
    }

    foreach (glob($cfg['auth_dir'] . 'rate/*.json') ?: [] as $f) {
        $data = json_decode(file_get_contents($f), true);
        $live = array_filter($data['requests'] ?? [], function ($t) use ($now, $cfg) {
            return $t > $now - $cfg['rate_window'];
        });
        if (empty($live)) @unlink($f);
    }
}

// ── Email (SMTP2GO API v3) ────────────────────────────────────────────────

function auth_send_email(string $to, string $rawToken, string $code): bool {
    $cfg  = auth_config();
    $ssl  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = ($ssl ? 'https' : 'http') . '://' . $host;
    $link = $base . '/auth.php?action=verify&token=' . urlencode($rawToken);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:32px 16px;background:#f5f0eb;font-family:system-ui,sans-serif">
  <div style="max-width:460px;margin:0 auto;background:#ffffff;border-radius:10px;border:1px solid #d4c9bc;padding:40px 32px">
    <h1 style="margin:0 0 6px;font-size:20px;color:#5c3d2e">Family Tree</h1>
    <p style="margin:0 0 28px;font-size:14px;color:#7a6555">Sign-in request</p>
    <p style="margin:0 0 24px;font-size:15px;color:#2d1f14">Click the button below to sign in, or enter your 6-digit code on the site.</p>
    <a href="{$link}" style="display:inline-block;padding:12px 28px;background:#5c3d2e;color:#fff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600">Sign in to Family Tree</a>
    <div style="margin:28px 0 0;padding-top:24px;border-top:1px solid #d4c9bc">
      <p style="margin:0 0 8px;font-size:13px;color:#7a6555">Or enter this 3-digit code on the site:</p>
      <div style="font-size:36px;font-weight:700;letter-spacing:.18em;color:#5c3d2e;font-family:monospace">{$code}</div>
      <p style="margin:8px 0 0;font-size:12px;color:#7a6555">Expires in 30 minutes.</p>
    </div>
    <p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #d4c9bc;font-size:11px;color:#7a6555">If you didn't request this, you can safely ignore this email.</p>
  </div>
</body>
</html>
HTML;

    $text = "Sign in to Family Tree\n\n"
          . "Click this link to sign in:\n{$link}\n\n"
          . "Or enter this 3-digit code on the site: {$code}\n\n"
          . "Expires in 30 minutes.\n\n"
          . "If you didn't request this, ignore this email.";

    $payload = json_encode([
        'api_key'   => $cfg['smtp2go_api_key'],
        'to'        => [$to],
        'sender'    => $cfg['from_name'] . ' <' . $cfg['from_email'] . '>',
        'subject'   => $cfg['email_subject'],
        'text_body' => $text,
        'html_body' => $html,
    ]);

    $ch = curl_init('https://api.smtp2go.com/v3/email/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status >= 200 && $status < 300;
}

// ── Utilities ─────────────────────────────────────────────────────────────

function auth_get_bearer(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        $headers = getallheaders();
        $h = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!$h) $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) return $m[1];
    return '';
}

function auth_json(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body);
    exit;
}

function auth_set_session_cookie(string $token): void {
    $cfg = auth_config();
    $ssl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('ft_session', $token, [
        'expires'  => time() + $cfg['session_ttl'],
        'path'     => '/',
        'secure'   => $ssl,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
