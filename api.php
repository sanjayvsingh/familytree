<?php
require_once __DIR__ . '/auth_lib.php';
$_bearer = auth_get_bearer();
// Some Apache configs strip the Authorization header before PHP sees it;
// the ft_session cookie carries the same raw token as a reliable fallback.
if (!$_bearer) {
    $_bearer = $_COOKIE['ft_session'] ?? '';
}
if (!$_bearer || !auth_validate_session($_bearer)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

ini_set('memory_limit', '512M');
set_time_limit(0);

require_once 'gedcom_parser.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');


$ged_dir = __DIR__ . '/gedcom/';
$action  = $_GET['action'] ?? '';
$file    = basename($_GET['file'] ?? '');

if (!$file || pathinfo($file, PATHINFO_EXTENSION) !== 'ged') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$path = realpath($ged_dir . $file);
$base = realpath($ged_dir);
if ($path === false || $base === false || strpos($path, $base) !== 0 || !is_file($path)) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found']);
    exit;
}

switch ($action) {

    case 'index':
        // Reads only the pre-built slim cache — never loads the full dataset.
        echo json_encode(get_slim_index($path), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        break;

    case 'person':
        $id   = $_GET['id'] ?? '';
        $data = get_cached_gedcom($path);
        if (!isset($data['individuals'][$id])) {
            http_response_code(404);
            echo json_encode(['error' => 'Person not found']);
            exit;
        }
        echo json_encode($data['individuals'][$id], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        break;

    case 'gemini_summary':
        $cfg    = require __DIR__ . '/config.php';
        $apiKey = $cfg['gemini_key'] ?? '';
        if (!$apiKey) {
            error_log('Gemini: gemini_key missing from config');
            echo json_encode(['summary' => '', 'error' => 'API key not configured']);
            break;
        }
        $input  = json_decode(file_get_contents('php://input'), true);
        $prompt = trim($input['prompt'] ?? '');
        if (!$prompt) { echo json_encode(['summary' => '']); break; }

        $models  = ['gemini-2.0-flash-lite', 'gemini-3.0-flash'];
        $payload = json_encode([
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => 80, 'temperature' => 0.4],
        ]);

        $text = '';
        foreach ($models as $model) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                 . $model . ':generateContent?key=' . urlencode($apiKey);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
            ]);
            $resp     = curl_exec($ch);
            $curlErr  = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErr) {
                error_log("Gemini ($model): cURL error: $curlErr");
                continue;
            }
            $gemini = json_decode($resp, true);
            if ($httpCode !== 200 || isset($gemini['error'])) {
                $msg = $gemini['error']['message'] ?? "HTTP $httpCode";
                error_log("Gemini ($model): API error ($httpCode): $msg");
                continue;
            }
            $text = $gemini['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if ($text === '') {
                error_log("Gemini ($model): empty text in response: $resp");
                continue;
            }
            break; // success
        }

        if ($text === '') {
            echo json_encode(['summary' => '', 'error' => 'All Gemini models failed']);
            break;
        }
        echo json_encode(['summary' => trim($text)], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
