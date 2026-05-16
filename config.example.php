<?php
// Copy this file to config.php and fill in your values.
// config.php is excluded from git — never commit it.

return [
    // SMTP2GO API key (from app.smtp2go.com → API Keys)
    'smtp2go_api_key' => 'api-your-key-here',

    // Verified sender address & name (must be verified in SMTP2GO)
    'from_email'      => 'noreply@yourdomain.com',
    'from_name'       => 'Family Tree',

    'email_subject'   => 'Your Family Tree Sign-in Link',

    // Email whitelist — leave empty to allow any email address:
    // 'allowed_emails' => ['alice@example.com', 'bob@example.com'],
    'allowed_emails'  => [],

    // Token lifetimes (seconds)
    'magic_token_ttl' => 1800,    // 30 minutes for the magic link / code
    'session_ttl'     => 2592000, // 30 days for the session

    // Rate limiting: max requests per IP within the window
    'rate_window'        => 600,  // 10-minute sliding window
    'rate_max_requests'  => 5,

    // Storage paths (auto-created on first use — no manual setup needed)
    'auth_dir'  => __DIR__ . '/auth/',
    'log_file'  => __DIR__ . '/auth/logs/access.log',
];
