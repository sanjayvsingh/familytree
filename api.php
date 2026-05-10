<?php
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

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
