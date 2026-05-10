<?php
/**
 * GEDCOM 5.5.1 parser.
 * Returns ['individuals' => [...], 'families' => [...]]
 */
function parse_gedcom(string $filepath): array {

    $fh = fopen($filepath, 'r');
    if ($fh === false) return ['individuals' => [], 'families' => []];

    // Detect charset from GEDCOM header (first 30 lines)
    $charset   = 'UTF-8';
    $firstLine = true;
    for ($i = 0; $i < 30 && ($peek = fgets($fh)) !== false; $i++) {
        if ($firstLine && strncmp($peek, "\xEF\xBB\xBF", 3) === 0) {
            $peek = substr($peek, 3);
        }
        $firstLine = false;
        if (preg_match('/^1\s+CHAR\s+(\S+)/', rtrim($peek), $m)) {
            $cs = strtoupper(trim($m[1]));
            if (in_array($cs, ['ANSI', 'ANSEL', 'WINDOWS-1252', 'CP1252'])) {
                $charset = 'Windows-1252';
            } elseif (in_array($cs, ['ISO-8859-1', 'LATIN1', 'LATIN-1'])) {
                $charset = 'ISO-8859-1';
            }
            break;
        }
    }
    rewind($fh);

    $individuals = [];
    $families    = [];
    $notes       = [];

    $ctx = null;   // 'INDI' | 'FAM' | 'NOTE'
    $id  = null;
    $rec = [];
    $l1  = null;   // current level-1 tag
    $last_text_key = null;
    $firstLine = true;

    $flush = function() use (&$individuals, &$families, &$notes, &$ctx, &$id, &$rec) {
        if (!$ctx || !$id) return;
        if ($ctx === 'INDI') $individuals[$id] = $rec;
        elseif ($ctx === 'FAM')  $families[$id]  = $rec;
        elseif ($ctx === 'NOTE') $notes[$id]     = $rec['_text'] ?? '';
    };

    $ev = fn() => ['date' => '', 'place' => ''];

    while (($raw = fgets($fh)) !== false) {
        $line = rtrim($raw, "\r\n");

        if ($firstLine) {
            if (strncmp($line, "\xEF\xBB\xBF", 3) === 0) {
                $line = substr($line, 3);
            }
            $firstLine = false;
        }

        if ($charset !== 'UTF-8') {
            $line = iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $line);
            if ($line === false) continue;
        }

        if ($line === '') continue;

        if (!preg_match('/^(\d+)\s+(\S+)(?:\s+(.*))?$/', $line, $m)) continue;
        $level = (int)$m[1];
        $tag   = $m[2];
        $value = isset($m[3]) ? rtrim($m[3]) : '';

        // CONT/CONC — append to the last text field we recorded
        if ($tag === 'CONT' || $tag === 'CONC') {
            if ($last_text_key !== null && $ctx === 'NOTE') {
                $sep = $tag === 'CONT' ? "\n" : '';
                $rec['_text'] = ($rec['_text'] ?? '') . $sep . $value;
            } elseif ($last_text_key !== null && isset($rec[$last_text_key])) {
                $sep = $tag === 'CONT' ? "\n" : '';
                $rec[$last_text_key] .= $sep . $value;
            }
            continue;
        }

        // Level-0 record boundary
        if ($level === 0) {
            $flush();
            $ctx = null; $id = null; $rec = []; $l1 = null; $last_text_key = null;

            if (preg_match('/^@(.+)@$/', $tag, $im)) {
                $id  = $im[1];
                $ctx = $value;
                if ($ctx === 'INDI') {
                    $rec = [
                        'id'     => $id,   'name'   => '',
                        'npfx'   => '',    'givn'   => '',
                        'nick'   => '',    'spfx'   => '',
                        'surn'   => '',    'nsfx'   => '',
                        '_given' => '',    '_surn'  => '',
                        'sex'    => 'U',
                        'birth'  => $ev(), 'death'  => $ev(),
                        'burial' => $ev(), 'chr'    => $ev(),
                        'occu'   => '',    'reli'   => '',
                        'resi'   => '',    'note'   => '',
                        'fams'   => [],    'famc'   => [],
                        '_note_refs' => [],
                    ];
                } elseif ($ctx === 'FAM') {
                    $rec = [
                        'id'       => $id,
                        'husb'     => '',   'wife'     => '',
                        'children' => [],
                        'marr'     => $ev(), 'div'     => $ev(),
                        'note'     => '',
                    ];
                } elseif ($ctx === 'NOTE') {
                    $rec = ['_text' => $value];
                    $last_text_key = '_text';
                }
            }
            continue;
        }

        if (!$ctx) continue;

        // Unwrap @pointer@ values
        $ptr = '';
        if (preg_match('/^@(.+)@$/', $value, $pm)) $ptr = $pm[1];

        // ── INDI ─────────────────────────────────────────────────────────
        if ($ctx === 'INDI') {
            if ($level === 1) {
                $l1 = $tag;
                $last_text_key = null;
                switch ($tag) {
                    case 'NAME':
                        if (empty($rec['name'])) {
                            $rec['name'] = trim(str_replace('/', '', $value));
                            // Extract given name and surname from standard /Surname/ notation
                            if (preg_match('/^(.*?)\s*\/([^\/]*)\//u', $value, $nm)) {
                                $rec['_given'] = trim($nm[1]);
                                $rec['_surn']  = trim($nm[2]);
                            }
                        }
                        break;
                    case 'SEX':  $rec['sex']  = $value; break;
                    case 'OCCU': $rec['occu'] = $value; $last_text_key = 'occu'; break;
                    case 'RELI': $rec['reli'] = $value; $last_text_key = 'reli'; break;
                    case 'RESI': $rec['resi'] = $value; $last_text_key = 'resi'; break;
                    case 'FAMS': if ($ptr) $rec['fams'][] = $ptr; break;
                    case 'FAMC': if ($ptr) $rec['famc'][] = $ptr; break;
                    case 'NOTE':
                        if ($ptr) {
                            $rec['_note_refs'][] = $ptr;
                        } else {
                            if ($rec['note'] !== '') $rec['note'] .= "\n";
                            $rec['note'] .= $value;
                            $last_text_key = 'note';
                        }
                        break;
                }
            } elseif ($level === 2) {
                $last_text_key = null;
                switch ($l1) {
                    case 'NAME':
                        switch ($tag) {
                            case 'NPFX': $rec['npfx'] = $value; break;
                            case 'GIVN': $rec['givn'] = $value; break;
                            case 'NICK': $rec['nick'] = $value; break;
                            case 'SPFX': $rec['spfx'] = $value; break;
                            case 'SURN': $rec['surn'] = $value; break;
                            case 'NSFX': $rec['nsfx'] = $value; break;
                        }
                        break;
                    case 'BIRT': case 'DEAT': case 'BURI': case 'CHR':
                    case 'BAPM': case 'CONF': case 'ADOP': case 'GRAD':
                    case 'RETI': case 'EMIG': case 'IMMI':
                        $ekey = ged_event_key($l1);
                        if (!isset($rec[$ekey])) $rec[$ekey] = $ev();
                        if ($tag === 'DATE') { $rec[$ekey]['date']  = $value; }
                        if ($tag === 'PLAC') { $rec[$ekey]['place'] = $value; }
                        if ($tag === 'AGE')  { $rec[$ekey]['age']   = $value; }
                        break;
                    case 'RESI':
                        if ($tag === 'PLAC') { $rec['resi'] = $value; $last_text_key = 'resi'; }
                        if ($tag === 'DATE') $rec['resi_date'] = $value;
                        break;
                }
            }

        // ── FAM ──────────────────────────────────────────────────────────
        } elseif ($ctx === 'FAM') {
            if ($level === 1) {
                $l1 = $tag;
                $last_text_key = null;
                switch ($tag) {
                    case 'HUSB': if ($ptr) $rec['husb'] = $ptr; break;
                    case 'WIFE': if ($ptr) $rec['wife'] = $ptr; break;
                    case 'CHIL': if ($ptr) $rec['children'][] = $ptr; break;
                    case 'NOTE':
                        if (!$ptr) {
                            if ($rec['note'] !== '') $rec['note'] .= "\n";
                            $rec['note'] .= $value;
                            $last_text_key = 'note';
                        }
                        break;
                }
            } elseif ($level === 2) {
                $last_text_key = null;
                if ($l1 === 'MARR' || $l1 === 'DIV') {
                    $ekey = strtolower($l1 === 'MARR' ? 'marr' : 'div');
                    if ($tag === 'DATE') $rec[$ekey]['date']  = $value;
                    if ($tag === 'PLAC') $rec[$ekey]['place'] = $value;
                }
            }
        }
    }
    fclose($fh);
    $flush();

    // Resolve @xref@ note pointers
    foreach ($individuals as &$ind) {
        foreach ($ind['_note_refs'] as $nref) {
            if (isset($notes[$nref])) {
                if ($ind['note'] !== '') $ind['note'] .= "\n";
                $ind['note'] .= $notes[$nref];
            }
        }
        unset($ind['_note_refs']);

        // Build canonical display name from components
        $hasStructure = $ind['givn'] || $ind['surn'] || $ind['_given'] || $ind['_surn'];
        if ($hasStructure) {
            $given = $ind['givn'] ?: $ind['_given'];
            $surn  = $ind['surn'] ?: $ind['_surn'];
            $parts = array_filter([
                $ind['npfx'],
                $given,
                $ind['nick']  ? '"' . $ind['nick'] . '"' : '',
                $ind['spfx'],
                $surn,
                $ind['nsfx'],
            ]);
            $built = trim(implode(' ', $parts));
        } else {
            $built = $ind['name']; // use full cleaned NAME value as-is
        }
        $ind['display_name'] = $built ?: '(Unknown)';
        $ind['name']         = $ind['display_name'];
        unset($ind['_given'], $ind['_surn']);
    }
    unset($ind);

    return ['individuals' => $individuals, 'families' => $families];
}

/**
 * Builds the slim index payload (names, links, birth/death dates only).
 * This is what the browser loads on startup — no notes, places, or extra events.
 */
function build_slim_index(array $data): array {
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
    return ['individuals' => $out_inds, 'families' => $out_fams];
}

/**
 * Returns the slim index from its own small cache file.
 * On a cache hit this reads/decodes only the slim file — never the full one.
 */
function get_slim_index(string $filepath): array {
    $cache_dir  = __DIR__ . '/cache/';
    $cache_key  = md5(realpath($filepath) ?: $filepath);
    $index_file = $cache_dir . $cache_key . '_index.json';

    if (is_file($index_file) && filemtime($index_file) >= filemtime($filepath)) {
        $json = file_get_contents($index_file);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data)) return $data;
        }
    }

    // Cache miss — parse and write both files, then return slim index
    get_cached_gedcom($filepath);
    $json = file_get_contents($index_file);
    return $json ? (json_decode($json, true) ?: []) : [];
}

/**
 * Returns full parsed GEDCOM data, using a file-based cache keyed on the .ged mtime.
 * Also writes the slim index cache so get_slim_index() stays fast.
 * The cache/ directory and its .htaccess are created automatically on first use.
 */
function get_cached_gedcom(string $filepath): array {
    $cache_dir  = __DIR__ . '/cache/';
    $cache_key  = md5(realpath($filepath) ?: $filepath);
    $cache_file = $cache_dir . $cache_key . '.json';
    $index_file = $cache_dir . $cache_key . '_index.json';

    if (is_file($cache_file) && filemtime($cache_file) >= filemtime($filepath)) {
        $json = file_get_contents($cache_file);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data)) return $data;
        }
    }

    $data = parse_gedcom($filepath);

    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
        file_put_contents($cache_dir . '.htaccess',
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
    file_put_contents($cache_file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    file_put_contents($index_file, json_encode(build_slim_index($data), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE));

    return $data;
}

function ged_event_key(string $tag): string {
    static $map = [
        'BIRT' => 'birth', 'DEAT' => 'death', 'BURI' => 'burial',
        'CHR'  => 'chr',   'BAPM' => 'chr',   'CONF' => 'conf',
        'ADOP' => 'adop',  'GRAD' => 'grad',  'RETI' => 'reti',
        'EMIG' => 'emig',  'IMMI' => 'immi',
    ];
    return $map[$tag] ?? strtolower($tag);
}
