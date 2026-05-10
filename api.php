<?php
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

$data = get_cached_gedcom($path);

switch ($action) {

    case 'index':
        // Slim record — just what the tree, people panel, and upcoming dates need.
        // Full vital details are fetched per-person via action=person.
        $out_inds = [];
        foreach ($data['individuals'] as $id => $ind) {
            $out_inds[$id] = [
                'id'   => $id,
                'name' => $ind['name'],
                'surn' => $ind['surn'] ?? '',
                'sex'  => $ind['sex'],
                'fams' => $ind['fams'],
                'famc' => $ind['famc'],
                'birth' => ['date' => $ind['birth']['date'] ?? ''],
                'death' => ['date' => $ind['death']['date'] ?? ''],
            ];
        }
        $out_fams = [];
        foreach ($data['families'] as $id => $fam) {
            $out_fams[$id] = [
                'id'       => $id,
                'husb'     => $fam['husb'],
                'wife'     => $fam['wife'],
                'children' => $fam['children'],
                'marr'     => ['date' => $fam['marr']['date'] ?? ''],
            ];
        }
        echo json_encode(
            ['individuals' => $out_inds, 'families' => $out_fams],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
        break;

    case 'person':
        $id = $_GET['id'] ?? '';
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
