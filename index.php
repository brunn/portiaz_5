<?php
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log',__DIR__ . '/error.log');
function logError($message) {
    file_put_contents(__DIR__ . '/error.log', date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}
$baseDir = __DIR__;
$dbPath = $baseDir . '/ANDMEBAAS_PUU.db';
$projekti_nimetus = 'ANDMEBAAS';
$uploadDir = '';

if (isset($_REQUEST['s']) || isset($_REQUEST['p'])) {
    if (isset($_REQUEST['s'])) {
        $projekti_nimetus = strtoupper($_REQUEST['s']);
        $symbol = 'GATEIO:' . strtoupper($_REQUEST['s'] . 'USDT' ?? 'GATEIO:FOMOUSDT');
    } elseif (isset($_REQUEST['p'])) {
        $symbol = null;
        $projekti_nimetus = preg_replace('/[^a-zA-Z0-9]/', '_', $_REQUEST['p']);
        $projekti_nimetus = strtoupper($projekti_nimetus);
    }
    $projectDir = $baseDir . '/' . $projekti_nimetus;
    $uploadDir = $projectDir . '/uploads';
    $dbPath = $projectDir . '/' . $projekti_nimetus . '.db';
    if (!is_dir($projectDir)) {mkdir($projectDir, 0755, true);}
    if (!is_dir($uploadDir)) {mkdir($uploadDir, 0755, true);}
} else {
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
}
function getDatabase($dbPath) {
    if (!is_writable(dirname($dbPath))) {
        die(json_encode(['status' => 'error', 'message' => 'Database directory not writable']));
    }
    $db = new SQLite3($dbPath);
    $result = $db->querySingle('PRAGMA encoding;');
    $db->exec('CREATE TABLE IF NOT EXISTS puu (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nimi TEXT NOT NULL,
        vanem_id INTEGER,
        on_oks INTEGER NOT NULL,
        FOREIGN KEY (vanem_id) REFERENCES puu(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS postitused (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        puu_id INTEGER NOT NULL,
        tekst TEXT NOT NULL,
        aeg INTEGER NOT NULL,
        manused TEXT,
        FOREIGN KEY (puu_id) REFERENCES puu(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS kommentaarid (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        postitus_id INTEGER NOT NULL,
        vanem_id INTEGER,
        tekst TEXT NOT NULL,
        aeg INTEGER NOT NULL,
        FOREIGN KEY (postitus_id) REFERENCES postitused(id) ON DELETE CASCADE,
        FOREIGN KEY (vanem_id) REFERENCES kommentaarid(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS pildi_kommentaarid (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pilt_path TEXT NOT NULL,
        tekst TEXT NOT NULL,
        aeg INTEGER NOT NULL
    )');
    $result = $db->querySingle('SELECT COUNT(*) FROM puu');
    if ($result == 0) {
        $db->exec("INSERT INTO puu (nimi, vanem_id, on_oks) VALUES ('Juur', NULL, 1)");
    }
    return $db;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    header('Content-Type: application/json');
    $file         = $_FILES['file'];
    $fileName     = time() . '_' . basename($file['name']);
    $targetPath   = $uploadDir . '/' . $fileName;
    $relativePath = '/uploads/' . $fileName;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode([
            'status' => 'uploaded',
            'path' => $relativePath,
            'type' => mime_content_type($targetPath)
        ]);
    } else {
        error_log("Upload failed: " . json_encode($_FILES['file']));
        echo json_encode(['status' => 'error', 'message' => 'Upload failed']);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_post') {
    header('Content-Type: application/json');
    $puu_id = $_POST['puu_id'] ?? 0;
    $tekst = $_POST['tekst'] ?? '';
    $aeg = time();
    $manused = $_POST['manused'] ?? '[]';
    if (json_decode($manused) === null) {
        logError("Invalid manused JSON: " . $manused);
        echo json_encode(['status' => 'error', 'message' => 'Invalid manused JSON']);
        exit;
    }
    $db = getDatabase($dbPath);
    $stmt = $db->prepare('INSERT INTO postitused (puu_id, tekst, aeg, manused) VALUES (:puu_id, :tekst, :aeg, :manused)');
    $stmt->bindValue(':puu_id', $puu_id, SQLITE3_INTEGER);
    $stmt->bindValue(':tekst', $tekst, SQLITE3_TEXT);
    $stmt->bindValue(':aeg', $aeg, SQLITE3_INTEGER);
    $stmt->bindValue(':manused', $manused, SQLITE3_TEXT);
    $stmt->execute();
    $id = $db->lastInsertRowID();
    echo json_encode(['status' => 'added', 'id' => $id]);
    exit;
}
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $db = getDatabase($dbPath);
    switch ($_GET['action']) {
        case 'get_puu':
            $result = $db->query('SELECT * FROM puu');
            $puu = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $puu[] = $row;
            }
            echo json_encode($puu);
            break;
        case 'add_puu':
            $nimi = $_GET['nimi'] ?? '';
            $vanem_id = $_GET['vanem_id'] === 'null' ? null : ($_GET['vanem_id'] ?? null);
            $on_oks = $_GET['on_oks'] ?? 0;
            $stmt = $db->prepare('INSERT INTO puu (nimi, vanem_id, on_oks) VALUES (:nimi, :vanem_id, :on_oks)');
            $stmt->bindValue(':nimi', $nimi, SQLITE3_TEXT);
            $stmt->bindValue(':vanem_id', $vanem_id, $vanem_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmt->bindValue(':on_oks', $on_oks, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['status' => 'added']);
            break;
        case 'delete_puu':
            $id = $_GET['id'] ?? 0;
            $db = getDatabase($dbPath);
            $postitused = [];
            $result = $db->query('SELECT id, manused FROM postitused WHERE puu_id = ' . (int)$id);
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $postitused[] = $row;
            }
            $alamõlmed = [$id];
            $stmt = $db->prepare('SELECT id FROM puu WHERE vanem_id = :vanem_id');
            $i = 0;
            while ($i < count($alamõlmed)) {
                $stmt->bindValue(':vanem_id', $alamõlmed[$i], SQLITE3_INTEGER);
                $result = $stmt->execute();
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $alamõlmed[] = $row['id'];
                    $postStmt = $db->prepare('SELECT id, manused FROM postitused WHERE puu_id = :puu_id');
                    $postStmt->bindValue(':puu_id', $row['id'], SQLITE3_INTEGER);
                    $postResult = $postStmt->execute();
                    while ($postRow = $postResult->fetchArray(SQLITE3_ASSOC)) {
                        $postitused[] = $postRow;
                    }
                }
                $i++;
            }
            $stmt = $db->prepare('DELETE FROM puu WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            foreach ($postitused as $postitus) {
                $manused = json_decode($postitus['manused'] ?? '[]', true);
                foreach ($manused as $manus) {
                    $stmt = $db->prepare('DELETE FROM pildi_kommentaarid WHERE pilt_path = :pilt_path');
                    $stmt->bindValue(':pilt_path', $manus['path'], SQLITE3_TEXT);
                    $stmt->execute();
                    $fullPath = __DIR__ . $manus['path'];
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                }
            }
            echo json_encode(['status' => 'deleted']);
            break;
        case 'update_puu':
            $id = $_GET['id'] ?? 0;
            $nimi = $_GET['nimi'] ?? '';
            $stmt = $db->prepare('UPDATE puu SET nimi = :nimi WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':nimi', $nimi, SQLITE3_TEXT);
            $stmt->execute();
            echo json_encode(['status' => 'updated']);
            break;

        case 'get_postitused':
            $puu_id = $_GET['puu_id'] ?? null;
            if ($puu_id === null) {
                $stmt = $db->prepare('SELECT * FROM postitused ORDER BY aeg DESC');
            } else {
                $stmt = $db->prepare('SELECT * FROM postitused WHERE puu_id = :puu_id ORDER BY aeg DESC');
                $stmt->bindValue(':puu_id', $puu_id, SQLITE3_INTEGER);
            }
            $result = $stmt->execute();
            $postitused = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $komm_stmt = $db->prepare('SELECT * FROM kommentaarid WHERE postitus_id = :postitus_id ORDER BY aeg');
                $komm_stmt->bindValue(':postitus_id', $row['id'], SQLITE3_INTEGER);
                $komm_result = $komm_stmt->execute();
                $kommentaarid = [];
                while ($komm_row = $komm_result->fetchArray(SQLITE3_ASSOC)) {
                    $kommentaarid[] = $komm_row;
                }
                $row['kommentaarid'] = $kommentaarid;
                $postitused[] = $row;
            }
            echo json_encode($postitused);
            break;
        case 'delete_post':
            $id = $_GET['id'] ?? 0;
            $db = getDatabase($dbPath);
            $stmt = $db->prepare('SELECT manused FROM postitused WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $manused = $row ? json_decode($row['manused'] ?? '[]', true) : [];
            $stmt = $db->prepare('DELETE FROM postitused WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            foreach ($manused as $manus) {
                $stmt = $db->prepare('DELETE FROM pildi_kommentaarid WHERE pilt_path = :pilt_path');
                $stmt->bindValue(':pilt_path', $manus['path'], SQLITE3_TEXT);
                $stmt->execute();
                $fullPath = __DIR__ . $manus['path'];
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }
            echo json_encode(['status' => 'deleted']);
            break;
        case 'add_comment':
            $postitus_id = $_GET['postitus_id'] ?? 0;
            $vanem_id = $_GET['vanem_id'] === 'null' ? null : ($_GET['vanem_id'] ?? null);
            $tekst = urldecode($_GET['tekst'] ?? '');
            $aeg = time();
            $stmt = $db->prepare('INSERT INTO kommentaarid (postitus_id, vanem_id, tekst, aeg) VALUES (:postitus_id, :vanem_id, :tekst, :aeg)');
            $stmt->bindValue(':postitus_id', $postitus_id, SQLITE3_INTEGER);
            $stmt->bindValue(':vanem_id', $vanem_id, $vanem_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmt->bindValue(':tekst', $tekst, SQLITE3_TEXT);
            $stmt->bindValue(':aeg', $aeg, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['status' => 'added']);
            break;
        case 'delete_comment':
            $id = $_GET['id'] ?? 0;
            $stmt = $db->prepare('DELETE FROM kommentaarid WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['status' => 'deleted']);
            break;
        case 'add_image_comment':
            $pilt_path = $_GET['pilt_path'] ?? '';
            $tekst = urldecode($_GET['tekst'] ?? '');
            $aeg = time();
            $stmt = $db->prepare('INSERT INTO pildi_kommentaarid (pilt_path, tekst, aeg) VALUES (:pilt_path, :tekst, :aeg)');
            $stmt->bindValue(':pilt_path', $pilt_path, SQLITE3_TEXT);
            $stmt->bindValue(':tekst', $tekst, SQLITE3_TEXT);
            $stmt->bindValue(':aeg', $aeg, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['status' => 'added']);
            break;
        case 'get_image_comments':
            $pilt_path = $_GET['pilt_path'] ?? '';
            $stmt = $db->prepare('SELECT * FROM pildi_kommentaarid WHERE pilt_path = :pilt_path ORDER BY aeg');
            $stmt->bindValue(':pilt_path', $pilt_path, SQLITE3_TEXT);
            $result = $stmt->execute();
            $kommentaarid = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $kommentaarid[] = $row;
            }
            echo json_encode($kommentaarid);
            break;
        case 'delete_image_comment':
            $id = $_GET['id'] ?? 0;
            $stmt = $db->prepare('DELETE FROM pildi_kommentaarid WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['status' => 'deleted']);
            break;
        case 'search':
            $query = urldecode($_GET['query'] ?? '');
            if (!mb_check_encoding($query, 'UTF-8')) {
                $query = mb_convert_encoding($query, 'UTF-8');
            }
            $query = mb_strtolower($query, 'UTF-8');
            $data = [];
            $uniqueIds = [];
            $db = getDatabase($dbPath);
            function getPuuTee($db, $id, &$cache = []) {
                if (isset($cache[$id])) return $cache[$id];
                $tee = [];
                $currentId = $id;
                while ($currentId) {
                    $stmt = $db->prepare('SELECT id, nimi, vanem_id FROM puu WHERE id = :id');
                    $stmt->bindValue(':id', $currentId, SQLITE3_INTEGER);
                    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                    if (!$row) break;
                    $tee[] = $row['nimi'];
                    $currentId = $row['vanem_id'];
                }
                $cache[$id] = implode('->', array_reverse($tee));
                return $cache[$id];
            }
            $puuCache = [];
            $result = $db->query('SELECT * FROM puu');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (mb_stripos($row['nimi'], $query, 0, 'UTF-8') !== false && !in_array($row['id'], $uniqueIds)) {
                    $data[] = [
                        'type' => 'puu',
                        'id' => $row['id'],
                        'nimi' => $row['nimi'],
                        'puu_id' => $row['id'],
                        'tee' => getPuuTee($db, $row['id'], $puuCache)
                    ];
                    $uniqueIds[] = $row['id'];
                }
            }
            $result = $db->query('SELECT * FROM postitused');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $tee = getPuuTee($db, $row['puu_id'], $puuCache);
                if (mb_stripos($row['tekst'], $query, 0, 'UTF-8') !== false && !in_array($row['id'], $uniqueIds)) {
                    $data[] = [
                        'type' => 'postitus',
                        'id' => $row['id'],
                        'tekst' => $row['tekst'],
                        'puu_id' => $row['puu_id'],
                        'tee' => $tee
                    ];
                    $uniqueIds[] = $row['id'];
                }
                $manused = json_decode($row['manused'] ?? '[]', true);
                foreach ($manused as $manus) {
                    $fileName = basename($manus['path']);
                    if (mb_stripos($fileName, $query, 0, 'UTF-8') !== false && !in_array($row['id'], $uniqueIds)) {
                        $data[] = [
                            'type' => $manus['type'] && strpos($manus['type'], 'image/') === 0 ? 'pilt' : 'fail',
                            'id' => $row['id'],
                            'tekst' => $fileName,
                            'puu_id' => $row['puu_id'],
                            'tee' => $tee
                        ];
                        $uniqueIds[] = $row['id'];
                    }
                }
            }
            $result = $db->query('SELECT k.*, p.puu_id FROM kommentaarid k JOIN postitused p ON k.postitus_id = p.id');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (mb_stripos($row['tekst'], $query, 0, 'UTF-8') !== false && !in_array($row['postitus_id'], $uniqueIds)) {
                    $tee = getPuuTee($db, $row['puu_id'], $puuCache);
                    $data[] = [
                        'type' => 'kommentaar',
                        'id' => $row['id'],
                        'tekst' => $row['tekst'],
                        'puu_id' => $row['puu_id'],
                        'tee' => $tee
                    ];
                    $uniqueIds[] = $row['postitus_id'];
                }
            }
            $result = $db->query('SELECT * FROM pildi_kommentaarid');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (mb_stripos($row['tekst'], $query, 0, 'UTF-8') !== false) {
                    $post_stmt = $db->prepare('SELECT * FROM postitused WHERE manused LIKE :path');
                    $post_stmt->bindValue(':path', "%{$row['pilt_path']}%", SQLITE3_TEXT);
                    $post_result = $post_stmt->execute();
                    if ($post_row = $post_result->fetchArray(SQLITE3_ASSOC)) {
                        if (!in_array($post_row['id'], $uniqueIds)) {
                            $tee = getPuuTee($db, $post_row['puu_id'], $puuCache);
                            $data[] = [
                                'type' => 'pildi_kommentaar',
                                'id' => $row['id'],
                                'tekst' => $row['tekst'],
                                'puu_id' => $post_row['puu_id'],
                                'tee' => $tee
                            ];
                            $uniqueIds[] = $post_row['id'];
                        }
                    }
                }
            }
            echo json_encode($data);
            break;
        case 'get_post_titles':
            $result = $db->query('SELECT id, puu_id, tekst FROM postitused ORDER BY aeg DESC');
            $titles = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $titles[] = [
                    'id' => $row['id'],
                    'puu_id' => $row['puu_id'],
                    'tekst' => $row['tekst']
                ];
            }
            echo json_encode($titles);
            break;
        case 'update_post':
            $id = $_GET['id'] ?? 0;
            $tekst = urldecode($_GET['tekst'] ?? '');
            $aeg = time();
            $db = getDatabase($dbPath);
            $stmt = $db->prepare('UPDATE postitused SET tekst = :tekst, aeg = :aeg WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':tekst', $tekst, SQLITE3_TEXT);
            $stmt->bindValue(':aeg', $aeg, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['status' => 'updated']);
            break;
        case 'update_comment':
            $id = $_GET['id'] ?? 0;
            $tekst = urldecode($_GET['tekst'] ?? '');
            $aeg = time();
            $db = getDatabase($dbPath);
            $stmt = $db->prepare('UPDATE kommentaarid SET tekst = :tekst, aeg = :aeg WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':tekst', $tekst, SQLITE3_TEXT);
            $stmt->bindValue(':aeg', $aeg, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['status' => 'updated']);
            break;
        case 'update_image_comment':
            $id = $_GET['id'] ?? 0;
            $tekst = urldecode($_GET['tekst'] ?? '');
            $aeg = time();
            $db = getDatabase($dbPath);
            $stmt = $db->prepare('UPDATE pildi_kommentaarid SET tekst = :tekst, aeg = :aeg WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':tekst', $tekst, SQLITE3_TEXT);
            $stmt->bindValue(':aeg', $aeg, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['status' => 'updated']);
            break;
        case 'get_postitus_by_kommentaar':
            $kommentaar_id = $_POST['kommentaar_id'];
            $stmt = $db->prepare('SELECT p.* FROM postitused p JOIN kommentaarid k ON p.id = k.postitus_id WHERE k.id = ?');
            $stmt->bindValue(1, $kommentaar_id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $postitus = $result->fetchArray(SQLITE3_ASSOC);
            echo json_encode($postitus);
            break;
        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
            break;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="et">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="mrcnet.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="doc/mrcnet.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="doc/mrcnet.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="doc/mrcnet.ico">
    <link rel="icon" type="image/png" sizes="192x192" href="doc/mrcnet.ico">
    <link rel="icon" type="image/png" sizes="512x512" href="doc/mrcnet.ico">
    <link rel="manifest" href="doc/site.webmanifest">
    <title>Dokumentatsioon 2025</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://unpkg.com/vue@2.6.10/dist/vue.min.js"></script>
    <script src="https://s3.tradingview.com/tv.js"></script>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; height: 100vh; display: flex; flex-direction: column; background: #f0f0f0; }
        header { background: #ffffff; color: white; padding: 10px; position: sticky; top: 0; z-index: 10; }
        #chart {
            width: 100%;
            height: 350px;
            margin: auto;
        }
        .tradingview-widget-container {
            width: 100%;
        }
        #otsing { width: 100%; padding: 8px; font-size: 16px; border: none; border-radius: 4px; }
        #otsingu-tulemused { position: absolute; background: white; color: black; max-height: 200px; overflow-y: auto; width: 50%; border: 1px solid #ccc; z-index: 20; }
        .otsingu-tulemus {padding: 5px;cursor: pointer;white-space: nowrap;overflow: hidden;text-overflow: ellipsis;}
        .otsingu-tulemus:hover, .otsingu-tulemus.active { background: #9d9d9d;color: #000000; }
        .konteiner { display: flex; flex: 1; overflow: hidden; }
        #puu-konteiner { width: 275px; background: #fff; overflow-y: auto; padding: 10px; position: relative; }
        .puu-sõlm { padding: 5px; cursor: pointer; }
        .puu-sõlm:hover { background: #9d9d9d; }
        .puu-sõlm.laiendatud > .alamlehed { display: block; }
        .puu-sõlm .alamlehed { display: none; }
        #peasisu { flex: 1; padding: 5px; overflow-y: auto; background: white; }
        .postituse-redaktor { margin-bottom: 20px; }
        .postituse-redaktor textarea {width: 98%;height: 150px;resize: both;padding: 10px;border: 1px solid #ccc;border-radius: 4px;font-size: 16px;box-sizing: border-box;white-space: pre-wrap;overflow-wrap: break-word;background: #fff;}
        .postituse-redaktor textarea:focus {outline: none;border-color: #f0f0f0;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        .postituse-redaktor button {padding: 8px 16px;background:transparent;color: #9d9d9d;border: none;border-radius: 4px;cursor: pointer;}
        .postituse-redaktor button:hover {background: #9d9d9d;color: #000000;}
        .postitus{box-shadow: 0 4px 28px 0 rgb(210, 210, 210), 0 6px 20px 0 rgb(255, 255, 255);border-radius: 8px;margin-bottom: 10px;padding: 15px;background: #fff;}
        .postitus-text {white-space: pre-wrap;overflow-wrap: break-word;margin: 0 0 10px 0;}
        .postitus-timestamp {color: #666;font-size: 12px;}
        .postitus button {background:transparent;color: #9d9d9d;border: none;border-radius: 4px;cursor: pointer;}
        .postitus button:hover {background: #9d9d9d;color: #000000;}
        .galerii { display: list-item; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 10px; }
        .galerii-üksus img { width: 50%;height: auto; border-radius: 4px; cursor: pointer; }
        .kommentaari-paan { background: #f0f0f0; padding: 5px; margin-top: 5px; border-radius: 4px; }
        .pesastatud-kommentaarid { margin-left: 20px; }
        footer { background: #ffffff; color: white; padding: 10px; position: sticky; bottom: 0; }
        .kontekstimenüü { position: absolute; background: white; border: 1px solid #ccc; z-index: 1000; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .kontekstimenüü ul { list-style: none; padding: 0; margin: 0; }
        .kontekstimenüü li { padding: 8px 12px; cursor: pointer; }
        .kontekstimenüü li:hover { color: #000000; }
        .postituse-failid { margin-top: 10px; }
        .postituse-failid a { display: block; color: #6cd7a5; text-decoration: none; }
        .postituse-kommentaarid { margin-top: 15px; }
        .pildi-kommentaar {overflow: hidden;margin: 5px 0;padding: 5px;border-radius: 4px;}
        .vasta-redaktor {margin-left: 20px;margin-top: 5px;}
        .vasta-redaktor {width: 98%;padding-bottom:10px;margin-bottom: 2px;height:50px;resize:both;border:1px solid #ccc;border-radius:4px;margin-top: 7px;font-size: 16px;box-sizing: border-box;white-space: pre-wrap;overflow-wrap: break-word;background: #fff;}
        .vasta-redaktor:focus {outline: none;border-color: #f0f0f0;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        .kommentaar { border-top: 1px solid #ddd; position: relative; }
        .kommentaar small { color: #666; }
        .kommentaar button { margin-left: 10px; }
        .kommentaari-textarea:focus {outline: none;border-color: #f0f0f0;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        .kommentaari-textarea{width: 100%;padding-bottom:10px;margin-bottom: 10px;height:50px;resize:both;border:1px solid #ccc;border-radius: 4px;font-size: 16px;box-sizing: border-box;white-space: pre-wrap;overflow-wrap: break-word;background: #fff;margin-top: 10px;}
        .kommentaari-editor-container {font-family: Arial, sans-serif;height: 100px;border: 1px solid #ccc;border-radius: 4px;width: 100%;box-sizing: border-box;}
        .kommentaari-editor-container:focus {outline: none;border-color: #f0f0f0;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        .kommentaaride-lapsed{margin-left: 41px;}
        #pildi-kommentaar-tekst{width: 100%;height: 67px;}
        #pildi-kommentaar-tekst, #faili-kommentaar-tekst {resize: both;padding: 10px;border: 1px solid #ccc;border-radius: 4px;font-size: 16px;box-sizing: border-box;white-space: pre-wrap;overflow-wrap: break-word;background: #fff;margin-bottom: 5px;}
        #pildi-kommentaar-tekst:focus, #faili-kommentaar-tekst:focus {outline: none;border-color: #f0f0f0;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        .modal-kommentaarid .kommentaar {display: flex;align-items: flex-start;margin: 5px 0;}
        .modal-kommentaarid .kommentaar i {width: 50px;height: 50px;margin-right: 10px;font-size: 30px;line-height: 50px;color: #666;}
        .modal-kommentaarid .kommentaar div {flex: 1;}
        button { padding: 5px 10px; margin: 0 5px; cursor: pointer; background: #fff; color: #9d9d9d; border: none; border-radius: 4px; }
        button:hover { background: #9d9d9d; color: #000000;}
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 100; }
        .modal.active { display: flex; justify-content: center; align-items: center; }
        .modal-content { max-width: 90%; max-height: 90%; background: white; border-radius: 5px; display: flex;padding: 10px; }
        .modal-image { max-width: 70%; max-height: 100%; object-fit: contain; border-radius: 5px 0 0 5px; }
        .modal-kommentaarid { width: 300px; padding: 20px; overflow-y: auto; }
        .ajalugu-kirje {padding: 8px;border-bottom: 1px solid #ddd;cursor: pointer;}
        .ajalugu-kirje:hover {background: #9d9d9d;}
        .ajalugu-kirje a {text-decoration: none;color: #333;display: block;}
        .soovitus-list {background: white;border: 1px solid #ccc;max-height: 200px;overflow-y: auto;z-index: 100;}
        .soovitus-item {padding: 5px;cursor: pointer;}
        .soovitus-item:hover, .soovitus-item.aktiivne {background: #ddd;color: #000000;}
        button{padding: 0;margin: 0;cursor: pointer;background:transparent;color: #9d9d9d;border: none;border-radius: 4px;}
        .faili-kommentaar {overflow: hidden;margin: 5px 0;padding: 5px;border-radius: 4px;}
        .faili-kommentaar i {color: #0d0d0d;}
        #faili-kommentaar-tekst {width: 100%;height: 60px;margin-bottom: 5px;}
        .pildi-kommentaar, .faili-kommentaar {overflow: hidden;margin: 5px 0;padding: 5px;border-radius: 4px;display: flex;align-items: flex-start;}
        .pildi-kommentaar img, .faili-kommentaar i {width: 50px;height: 50px;margin-right: 10px;}
        .faili-kommentaar i {font-size: 30px;line-height: 50px;color: #666;}
        .pildi-kommentaar div, .faili-kommentaar div {flex: 1;overflow: hidden;}
        .pildi-kommentaar p, .faili-kommentaar p {margin: 0;}
        .pildi-kommentaar small, .faili-kommentaar small {color: #666;display: block;}
        .modal-kommentaarid #modal-post-tekst, .modal-kommentaarid #modal-fail-post-tekst {margin-bottom: 15px;padding: 10px;border-radius: 4px;}
        .modal-kommentaarid #modal-post-tekst p, .modal-kommentaarid #modal-fail-post-tekst p {margin: 0;}
        .modal-kommentaarid #modal-post-tekst small, .modal-kommentaarid #modal-fail-post-tekst small {color: #666;display: block;}
        .file-upload-wrapper {display: inline-block;position: relative;}
        .file-upload-btn {padding: 6px 8px;background: #1a73e8;color: white;border: none;border-radius: 4px;cursor: pointer;font-size: 14px;}
        .file-upload-btn:hover {background: #9d9d9d;color: #000000;}
        .hidden { display: none; }
        #ajalugu-vaade { padding: 20px; width: 98%;background-color: #fff;}
        .ajalugu-filtrid { margin-bottom: 15px; }
        .ajalugu-filtrid label { margin-right: 15px; font-size: 14px; }
        .ajalugu-sisu .tegevus {padding: 10px;margin-bottom: 5px;background: #f9f9f9;border-radius: 4px;}
        .ajalugu-sisu .tegevus a { color: #1a73e8; text-decoration: none; }
        .ajalugu-sisu .tegevus a:hover { text-decoration: underline; }
        #ajalugu-sisu {max-height: 80vh;overflow-y: auto;}
        .highlight {background: #8cff015c;transition: background 0.5s;}
        .ajalugu-link{ color: #424242; text-decoration: none;}
        .otsingu-link{ color: #424242; text-decoration: none;}
        .must_tekst{color: #424242;}
        #jooksev-paan {width: 275px;background: #fff;padding: 10px;overflow-y: auto;}
        .jooksev-kirje {cursor: pointer;}
        .jooksev-kirje:hover {background: #9d9d9d;}
        .jooksev-kirje a {text-decoration: none;color: #424242;display: block;}
        .jooksev-kirje small {color: #666;}
        #peasisu {scrollbar-width: thin;scrollbar-color: #888 #f1f1f1;}
        #peasisu::-webkit-scrollbar {width: 8px;}
        #peasisu::-webkit-scrollbar-track {background: #f1f1f1;}
        #peasisu::-webkit-scrollbar-thumb {background: #888;}
        #peasisu::-webkit-scrollbar-thumb:hover {background: #555;}
        .copy-btn {float: right;background: none;border: none;cursor: pointer;padding: 2px 5px;font-size: 12px;color: #666;vertical-align: middle;}
        .copy-btn:hover {color: #000;}
        .copy-btn i {margin: 0;float: right;}
        .parem-paan {width: 300px;position: fixed;right: 0;top: 0;bottom: 0;background: #fff;border-left: 1px solid #ddd;padding: 10px;overflow-y: auto;}
        #failide-nimekiri, #piltide-galerii {margin-bottom: 20px;}
        #failide-sisu a {display: block;text-decoration: none;color: #333;}
        #failide-sisu a:hover {background: #eee;}
        .galerii-sisu {display: flex;flex-wrap: wrap;gap: 10px;}
        .galerii-üksus {width: 80px;text-align: center;}
        .galerii-üksus img {width: 100%;height: 100%;cursor: pointer;}
        .galerii-üksus span {font-size: 12px;word-wrap: break-word;}
        .galerii { display: flex; flex-wrap: wrap; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1px; margin-top: 1px; }
        header {background: #ffffff; padding: 10px; width: 100%; box-sizing: border-box; }
        #otsing { width: 50%;padding: 8px;font-size: 16px;
        border: none; border-radius: 4px;}
        #otsingu-tulemused { position: absolute; background: white; color: black; max-height: 250px; overflow-y: auto;
        width: 50%; border: 1px solid #ccc; z-index: 20; }
        #chart {width: 100%;height: 400px;margin: auto;}
        .tradingview-widget-container {width: 100%;}
        .konteiner { display: flex; width: 100%; }
        #puu-konteiner { width: 275px; background: #fff; padding: 10px; overflow-y: auto; }
        #peasisu { flex: 1; padding: 5px; background: white; overflow-y: auto; }
        footer { background: #ffffff; padding: 10px; width: 100%; box-sizing: border-box; }
        #projekti_nimetus{font-size: 22px;font-weight: bold;position: absolute;float: right;color: black;display: flex;margin-left: 56%;margin-top: -32px;}
        .upload-preview-container {margin-top: 10px;display: flex;flex-wrap: wrap;gap: 10px;}
        .preview-item {position: relative;display: flex;align-items: center;padding: 5px;background: #f5f5f5;border-radius: 4px;}
        .preview-item img {max-width: 100px;max-height: 100px;cursor: pointer;}
        .preview-item span {margin-left: 5px;max-width: 150px;overflow: hidden;text-overflow: ellipsis;}
        .remove-btn {margin-left: 5px;color: #ff4444;cursor: pointer;font-size: 16px;}
        .remove-btn:hover {color: #cc0000;}
        .progress-container {width: 100%;margin-top: 10px;background: #f0f0f0;border-radius: 4px;overflow: hidden;}
        .progress-bar {height: 20px;background:rgb(27, 53, 28);width: 0%;transition: width 0.3s ease-in-out;text-align: center;color: white;line-height: 20px;}
        #searchInput {font-size: 22px;font-weight: bold;color: #646464;padding: 5px;border-bottom: 1px solid #ccc;border-top: none;border-left: none;border-right: none;color: #c4c4c4;}
        #otsing{outline: none;border-color: #f0f0f0;}
        #otsing::placeholder {color: #c4c4c4;}
        #searchInput::placeholder {color: #c4c4c4;}
        #searchInput:focus {outline: none;border-color: #f0f0f0;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        #searchType {font-size: 22px;margin-left: 5px;padding: 5px;border: none;background-color: #fff;appearance: none;color: #c4c4c4;}
        #searchType:focus {outline: none;border-color: #f0f0f0;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        .galerii-üksus-postitus, img{width: 100%;}
</style>
</head>
<body>
    <header>
        <input type="text" id="otsing" placeholder="Otsi oksi, lehti, postitusi...">
        <div id="otsingu-tulemused" class="otsingu-tulemused"></div>

        <div id="projekti_nimetus">
            PROJEKTI NIMETUS
        </div>
        <div style="position: absolute; margin-left: 56%; margin-top: -32px;">
            <div style="display: flex; align-items: center;">
                <input 
                    id="searchInput" 
                    type="text" 
                    placeholder="ANDMEBAAS" 
                    style="font-size: 22px; font-weight: bold; color: #646464; padding: 5px; border-bottom: 1px solid #ccc;  border-top: none;  border-left: none;  border-right: none;color: #c4c4c4;"
                    onkeypress="if(event.key === 'Enter') searchRedirect()"
                >
                <select 
                    id="searchType" 
                    style="font-size: 22px; margin-left: 5px; padding: 5px; border: none;background-color: #fff;"
                    onchange="updatePlaceholder()"
                >
                    <option value="s">Symbol</option>
                    <option value="p">Projekt</option>
                </select>
            </div>
        </div>
        <button id="toggle-chart" onclick="toggleChart()">Peida graafik</button>
        <div id="app" style="display: none;">
            <div class="tradingview-widget-container">
                <div id="chart"></div>
            </div>
        </div>
    </header>
    <div class="konteiner">
        <div id="puu-konteiner"></div>
        <div id="peasisu">
            <div class="postituse-redaktor">
                <textarea placeholder="Loo postitus..."></textarea>
                <div class="file-upload-wrapper">
                    <button class="file-upload-btn">Laadi üles failid</button>
                    <input type="file" multiple id="faili-sisend" style="display: none;">
                </div>
                <div id="upload-preview" class="upload-preview-container"></div>
                <div class="progress-container">
                    <div class="progress-bar" id="upload-progress">0%</div>
                </div>
                <button onclick="PostitusteHaldur.lisaPostitus()">Postita</button>
            </div>
            <div id="postitused"></div>
        </div>
        <div id="ajalugu-vaade" class="hidden">
            <h2>Viimased tegevused</h2>
            <div class="ajalugu-filtrid">
                <label><input type="checkbox" id="filter-failid" checked> Failid</label>
                <label><input type="checkbox" id="filter-pildid" checked> Pildid</label>
                <label><input type="checkbox" id="filter-kommentaarid" checked> Kommentaarid</label>
                <label><input type="checkbox" id="filter-vastused" checked> Vastused</label>
                <label><input type="checkbox" id="filter-postitused" checked> Postitused</label>
            </div>
            <div id="ajalugu-sisu"></div>
        </div>
        <div id="jooksev-paan" class="jooksev-paan">
            <h3>Tegevuste ajalugu</h3>
            <div id="jooksev-sisu"></div>
            <h3>Failid/pildid</h3>
            <div id="failide-sisu"></div>
            <div id="galerii-sisu" class="galerii"></div>
        </div>
    </div>
    <footer>
        <button onclick="PuuHaldur.LISA_UUS_OKS()">Lisa oks</button>
        <button onclick="PuuHaldur.lisaLeht()">Lisa leht</button>
        <button onclick="AjaluguHaldur.kuvaAjalugu()">Ajalugu</button>
    </footer>
    <div id="kontekstimenüü" class="kontekstimenüü" style="display: none;">
        <ul id="kontekstimenüü-sisu"></ul>
    </div>
    <div class="modal" id="galerii-modal">
        <div class="modal-content">
            <img class="modal-image" id="modal-pilt" src="">
            <div class="modal-kommentaarid">
                <div id="modal-post-tekst" style="margin-bottom: 15px; padding: 10px;border-radius: 4px;"></div>
                <div id="pildi-kommentaarid"></div>
                <textarea id="pildi-kommentaar-tekst" placeholder="Lisa kommentaar..."></textarea>
                <button id="pildi-kommentaar-nupp">Kommenteeri</button>
            </div>
        </div>
    </div>
    <div class="modal" id="faili-modal">
        <div class="modal-content" style="flex-direction: column; max-width: 500px;">
            <div style="padding: 10px; background: #f0f0f0; border-bottom: 1px solid #ccc;">
                <a id="modal-fail-link" href="" target="_blank" style="text-decoration: none; color: #1a73e8;"></a>
            </div>
            <div class="modal-kommentaarid" style="width: 91%; padding: 20px;">
                <div id="modal-fail-post-tekst" style="margin-bottom: 15px; padding: 10px;border-radius: 4px;"></div>
                <div id="faili-kommentaarid"></div>
                <textarea id="faili-kommentaar-tekst" placeholder="Lisa kommentaar..."></textarea>
                <button id="faili-kommentaar-nupp">Kommenteeri</button>
            </div>
        </div>
    </div>
    <script>
        function searchRedirect() {
            const inputValue = document.getElementById('searchInput').value;
            const searchType = document.getElementById('searchType').value;
            
            if (inputValue.trim() !== '') {
                window.location.href = `index.php?${searchType}=${encodeURIComponent(inputValue)}`;
            }
        }
        function updatePlaceholder() {
            const searchType = document.getElementById('searchType').value;
            const input = document.getElementById('searchInput');
            input.placeholder = searchType === 's' ? 'Otsi sümbolit...' : 'Otsi projekti...';
        }
        let symboli_poordumine              = <?php echo isset($_REQUEST['s']) ? 'true' : 'false'; ?>;
        function toggleChart() {
            const chartDiv = document.getElementById('app');
            const toggleBtn = document.getElementById('toggle-chart');
            if (chartDiv.style.display === 'none') {
                chartDiv.style.display = 'block';
                toggleBtn.textContent = 'Peida graafik';
            } else {
                chartDiv.style.display = 'none';
                toggleBtn.textContent = 'Näita graafik';
            }
        }
        if (symboli_poordumine) {
            let vue = new Vue({
                el: '#app',
                data: {
                fullscreen: false
                },
                methods: {
                    init() {
                        let symbol = "<?php echo $symbol; ?>";
                        console.log(symbol);
                        new TradingView.widget({
                            "autosize": true,
                            "symbol": symbol,
                            "show_fullscreen": true,
                            "allowfullscreen": true,
                            "interval": "D",
                            "timezone": "Etc/UTC",
                            "theme": "light",
                            "style": "1",
                            "locale": "en",
                            "toolbar_bg": "#f1f3f6",
                            "enable_publishing": false,
                            "hide_side_toolbar": true,
                            "side_toolbar_in_fullscreen_mode": true,
                            "allow_symbol_change": true,
                            "referral_id": "ToddChristensen",
                            "container_id": "chart",
                            "header_fullscreen_button": true,
                            "showSymbolLabels": false
                        });
                    }
                },
                mounted() {
                    this.init();
                }
            });
        }else{
            const toggleBtn = document.getElementById('toggle-chart');
            toggleBtn.style.display = 'none';
        }
    </script>
    <script>
        const PROJEKTI_NIMI = '<?php echo htmlspecialchars($projekti_nimetus); ?>';
        const JUUR_KAUST    = '<?php echo rtrim(dirname($_SERVER['PHP_SELF']), '/'); ?>';
        const BASE_URL      = PROJEKTI_NIMI === "ANDMEBAAS"  ? '<?php echo rtrim(dirname($_SERVER['PHP_SELF']), '/'); ?>'   : '<?php echo rtrim(dirname($_SERVER['PHP_SELF']), '/').'/'.htmlspecialchars($projekti_nimetus); ?>';
        const Andmed        = {puu: [],valitudSõlm: null,laienenudSõlmed: new Set()};
        async function apiKutse(action, params = {}) {
            const url = new URL(window.location.href);
            url.searchParams.set('action', action);
            for (const [key, value] of Object.entries(params)) {
                url.searchParams.set(key, value);
            }
            try {
                const vastus = await fetch(url, { method: 'GET' });
                return await vastus.json();
            } catch (error) {
                console.error('API kutse viga:', error);
                throw error;
            }
        }
        const PuuHaldur = {
            async laadiPuu() {
                try {
                    const puuAndmed = await apiKutse('get_puu');
                    if (!puuAndmed || puuAndmed.status === 'error') {
                        console.error('Puu andmeid ei leitud:', puuAndmed?.message);
                        return;
                    }
                    Andmed.puu = this.ehitaPuuHierarhia(puuAndmed);
                    this.kuvaPuu();
                } catch (e) {
                    console.error('Puu laadimise viga:', e);
                }
            },
            ehitaPuuHierarhia(lamedadAndmed) {
                const puu = [];
                const idKaart = new Map(lamedadAndmed.map(sõlm => [sõlm.id, { ...sõlm, lapsed: [] }]));
                idKaart.forEach(sõlm => {
                    if (sõlm.vanem_id) {
                        const vanem = idKaart.get(sõlm.vanem_id);
                        if (vanem) vanem.lapsed.push(sõlm);
                    } else {
                        puu.push(sõlm);
                    }
                });
                return puu;
            },
            laiendaJaVali(e, sõlm, element) {
                e.stopPropagation();
                if (sõlm.lapsed && sõlm.lapsed.length > 0) {
                    if (Andmed.laienenudSõlmed.has(sõlm.id)) {
                        Andmed.laienenudSõlmed.delete(sõlm.id);
                    } else {
                        Andmed.laienenudSõlmed.add(sõlm.id);
                    }
                }
                Andmed.valitudSõlm = sõlm;
                JooksevHaldur.lisaLink(sõlm.on_oks ? 'oks' : 'leht', sõlm.id, sõlm.id, sõlm.nimi);
                this.kuvaPostitamisLeht();
                this.kuvaPuu();
            },
            kuvaPuu(andmed = Andmed.puu, vanem = document.getElementById('puu-konteiner'), tase = 0) {
                vanem.innerHTML = '';
                if (!andmed || andmed.length === 0) {
                    vanem.innerHTML = '<p>Puu on tühi!</p>';
                    return;
                }
                andmed.forEach(sõlm => {
                    const div = document.createElement('div');
                    div.className = 'puu-sõlm';
                    div.style.paddingLeft = `${tase * 20}px`;
                    const onLapsi = sõlm.lapsed && sõlm.lapsed.length > 0;
                    div.innerHTML = `<i class="fas ${onLapsi ? 'fa-solid fa-plus' : 'fa-solid fa-minus'}"></i> ${sõlm.nimi}`;
                    div.onclick = (e) => this.laiendaJaVali(e, sõlm, div);
                    div.oncontextmenu = (e) => this.näitaKontekstimenüüd(e, sõlm);
                    const alamlehed = document.createElement('div');
                    alamlehed.className = 'alamlehed';
                    div.appendChild(alamlehed);
                    vanem.appendChild(div);
                    if (onLapsi) {
                        if (Andmed.laienenudSõlmed.has(sõlm.id)) {
                            div.classList.add('laiendatud');
                            this.kuvaPuu(sõlm.lapsed, alamlehed, tase + 1);
                        }
                    }
                });
            },
            näitaKontekstimenüüd(e, sõlm) {
                e.preventDefault();
                const valitudSõlm = Andmed.valitudSõlm || sõlm;
                const menüü = document.getElementById('kontekstimenüü');
                const menüüSisu = document.getElementById('kontekstimenüü-sisu');
                menüüSisu.innerHTML = '';
                const opts = [
                    { text: 'Lisa oks', action: () => this.lisaOks() },
                    { text: 'Lisa leht', action: () => this.lisaLeht() }, // Lubame alati lehe lisamist
                    { text: valitudSõlm.on_oks ? 'Kustuta oks' : 'Kustuta leht', action: () => this.kustutaSõlm() },
                    { text: 'Nimeta ümber oks', action: () => this.nimetaOksÜmber(), disabled: valitudSõlm.on_oks !== 1 },
                    { text: 'Nimeta ümber leht', action: () => this.nimetaLehtÜmber(), disabled: valitudSõlm.on_oks !== 0 }
                ];
                opts.forEach(opt => {
                    const li = document.createElement('li');
                    li.textContent = opt.text;
                    if (!opt.disabled) {
                        li.onclick = () => {
                            Andmed.valitudSõlm = valitudSõlm;
                            opt.action();
                            menüü.style.display = 'none'; // Peidame menüü pärast valikut
                        };
                    } else {
                        li.style.color = '#ccc';
                        li.style.cursor = 'not-allowed';
                    }
                    menüüSisu.appendChild(li);
                });
                menüü.style.display = 'block';
                menüü.style.left = `${e.clientX}px`;
                menüü.style.top = `${e.clientY}px`;
                document.onclick = () => menüü.style.display = 'none';
            },
            async nimetaOksÜmber() {
                if (!Andmed.valitudSõlm || !Andmed.valitudSõlm.on_oks) return alert('Vali esmalt oks!');
                const uusNimi = prompt(`Sisesta uus nimi oksale "${Andmed.valitudSõlm.nimi}":`, Andmed.valitudSõlm.nimi);
                if (!uusNimi || uusNimi === Andmed.valitudSõlm.nimi) return;
                try {
                    const vastus = await apiKutse('update_puu', { id: Andmed.valitudSõlm.id, nimi: uusNimi });
                    if (vastus.status === 'updated') {
                        Andmed.valitudSõlm.nimi = uusNimi;
                        await this.laadiPuu();
                        this.kuvaPostitamisLeht();
                    } else {
                        alert('Oksa ümbernimetamine ebaõnnestus!');
                    }
                } catch (e) {
                    console.error('Oksa ümbernimetamise viga:', e);
                    alert('Viga serveriga suhtlemisel!');
                }
            },
            async nimetaLehtÜmber() {
                if (!Andmed.valitudSõlm || Andmed.valitudSõlm.on_oks) return alert('Vali esmalt leht!');
                const uusNimi = prompt(`Sisesta uus nimi lehele "${Andmed.valitudSõlm.nimi}":`, Andmed.valitudSõlm.nimi);
                if (!uusNimi || uusNimi === Andmed.valitudSõlm.nimi) return;
                try {
                    const vastus = await apiKutse('update_puu', { id: Andmed.valitudSõlm.id, nimi: uusNimi });
                    if (vastus.status === 'updated') {
                        Andmed.valitudSõlm.nimi = uusNimi;
                        await this.laadiPuu();
                        this.kuvaPostitamisLeht();
                    } else {
                        alert('Lehe ümbernimetamine ebaõnnestus!');
                    }
                } catch (e) {
                    console.error('Lehe ümbernimetamise viga:', e);
                    alert('Viga serveriga suhtlemisel!');
                }
            },
            async lisaOks() {
                const nimi = prompt('Sisesta oksa nimi:');
                if (!nimi) return;
                await apiKutse('add_puu', { nimi, vanem_id: Andmed.valitudSõlm?.id || null, on_oks: 1 });
                this.laadiPuu();
            },
            async lisaLeht() {
                const nimi = prompt('Sisesta lehe nimi:');
                if (!nimi) return;
                const vastus = await apiKutse('add_puu', { nimi, vanem_id: Andmed.valitudSõlm?.id || null, on_oks: 0 });
                if (vastus.status === 'added') {
                    await this.laadiPuu();
                    this.kuvaPuu();
                } else {
                    alert('Lehe lisamine ebaõnnestus!');
                }
            },
            async kustutaSõlm() {
                if (!Andmed.valitudSõlm) return alert('Vali esmalt sõlm!');
                if (!confirm(`Kas tõesti kustutada "${Andmed.valitudSõlm.nimi}" ja selle sisu?`)) return;
                await apiKutse('delete_puu', { id: Andmed.valitudSõlm.id });
                Andmed.valitudSõlm = null;
                this.laadiPuu();
                document.getElementById('peasisu').innerHTML = '<p>Vali puust sõlm, et siia sisu kuvada.</p>';
            },
            getFullPath(sõlm) {
                const path = [];
                let current = sõlm;
                while (current) {
                    path.unshift(current.nimi);
                    current = this.findNode(Andmed.puu, current.vanem_id);
                }
                return path.join('/');
            },
            kuvaPostitamisLeht() {
                const peasisu = document.getElementById('peasisu');
                const ajaluguVaade = document.getElementById('ajalugu-vaade');
                const fullPath = this.getFullPath(Andmed.valitudSõlm);
                
                peasisu.classList.remove('hidden');
                ajaluguVaade.classList.add('hidden');
                peasisu.innerHTML = `
                    <h2>${fullPath}</h2>
                    <div class="postituse-redaktor">
                        <textarea placeholder="Kirjuta postitus..."></textarea>
                        <div class="file-upload-wrapper">
                            <button class="file-upload-btn">Laadi üles failid</button>
                            <input type="file" multiple id="faili-sisend" style="display: none;">
                        </div>
                        <div id="upload-preview" class="upload-preview-container"></div>
                        <div class="progress-container">
                            <div class="progress-bar" id="upload-progress">0%</div>
                        </div>
                        <button onclick="PostitusteHaldur.lisaPostitus()">Postita</button>
                    </div>
                    <div id="postitused"></div>
                `;

                const fileInput = peasisu.querySelector('#faili-sisend');
                const fileBtn = peasisu.querySelector('.file-upload-btn');
                
                fileBtn.addEventListener('click', () => fileInput.click());
                fileInput.addEventListener('change', (e) => PostitusteHaldur.handleFileSelect(e));
                
                PostitusteHaldur.updatePreview(); // Show any existing selected files
                PostitusteHaldur.lisaSoovitusKuulajad(peasisu);
                PostitusteHaldur.kuvaPostitused();
                FailideJaPiltideHaldur.kuvaFailidJaPildid();
            },
            valiSõlm(puuId, type, id) {
                const sõlm = this.findNode(Andmed.puu, puuId);
                if (sõlm) {
                    Andmed.valitudSõlm = sõlm;
                    JooksevHaldur.lisaLink(sõlm.on_oks ? 'oks' : 'leht', sõlm.id, sõlm.id, sõlm.nimi);
                    this.kuvaPostitamisLeht();
                    document.getElementById('otsingu-tulemused').innerHTML = '';
                    if (type === 'kommentaar' || type === 'pildi_kommentaar') {
                        setTimeout(() => {
                            const element = document.getElementById(`${type === 'kommentaar' ? 'kommentaar' : 'fail'}-${id}`);
                            if (element) {
                                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }, 500);
                    } else if (type === 'postitus') {
                        setTimeout(() => {
                            const element = document.getElementById(`postitus-${id}`);
                            if (element) {
                                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }, 500);
                    }
                }
            },
            findNode(nodes, id) {
                for (const node of nodes) {
                    if (node.id === id) return node;
                    if (node.lapsed && node.lapsed.length > 0) {
                        const found = this.findNode(node.lapsed, id);
                        if (found) return found;
                    }
                }
                return null;
            },
            async LISA_UUS_OKS() {
                const nimi = prompt('Sisesta oksa nimi:');
                if (!nimi) return;
                const vastus = await apiKutse('add_puu', { nimi, vanem_id: null, on_oks: 1 });
                if (vastus.status === 'added') {
                    await this.laadiPuu();
                    this.kuvaPuu();
                } else {
                    alert('Oksa lisamine ebaõnnestus!');
                }
            }
        };
        const AutomaatSoovitus = {
            postTitles: [],
            aktiivneIndeks: -1,
            viimaneSisend: '',
            async laadiPealkirjad() {
                const titles = await apiKutse('get_post_titles');
                if (Array.isArray(titles)) {
                    this.postTitles = titles.map(t => {
                        const rada = this.leiutaRada(t.puu_id);
                        return { ...t, rada: rada || t.tekst };
                    });
                } else {
                    console.error('Pealkirjade laadimine ebaõnnestus:', titles);
                }
            },
            leiutaRada(puuId) {
                const sõlm = PuuHaldur.findNode(Andmed.puu, puuId);
                if (!sõlm) return '';
                const rada = [];
                let praegune = sõlm;
                while (praegune) {
                    rada.unshift(praegune.nimi);
                    praegune = PuuHaldur.findNode(Andmed.puu, praegune.vanem_id);
                }
                return rada.join('/');
            },
            async kuvaSoovitused(tekstVali, konteiner) {
                const sisend = tekstVali.value;
                const viimaneMark = sisend.lastIndexOf('[');
                if (viimaneMark === -1 || sisend.length <= viimaneMark + 1) {
                    this.peidaSoovitused(konteiner);
                    return;
                }
                const otsitav = sisend.substring(viimaneMark + 1).toLowerCase();
                this.viimaneSisend = sisend.substring(0, viimaneMark + 1);
                const postitused = await apiKutse('get_postitused');
                const soovitused = [];
                postitused.forEach(post => {
                    if (post.tekst.toLowerCase().includes(otsitav)) {
                        soovitused.push({
                            tüüp: 'postitus',
                            id: post.id,
                            puuId: post.puu_id,
                            tekst: `Postitus: ${post.tekst.substring(0, 50)}...`
                        });
                    }
                    const manused = JSON.parse(post.manused || '[]');
                    manused.forEach(manus => {
                        const fileName = manus.path.split('/').pop();
                        if (fileName.toLowerCase().includes(otsitav)) {
                            if (manus.type.startsWith('image/')) {
                                soovitused.push({
                                    tüüp: 'pilt',
                                    id: post.id,
                                    puuId: post.puu_id,
                                    tekst: `Pilt: ${fileName}`
                                });
                            } else {
                                soovitused.push({
                                    tüüp: 'fail',
                                    id: post.id,
                                    puuId: post.puu_id,
                                    tekst: `Fail: ${fileName}`
                                });
                            }
                        }
                    });
                    const kommentaarid = PostitusteHaldur.ehitaKommentaarideHierarhia(post.kommentaarid || []);
                    kommentaarid.forEach(komm => {
                        if (komm.tekst.toLowerCase().includes(otsitav) && !komm.vanem_id) {
                            soovitused.push({
                                tüüp: 'kommentaar',
                                id: komm.id,
                                puuId: post.puu_id,
                                tekst: `Kommentaar: ${komm.tekst.substring(0, 50)}...`
                            });
                        }
                        const lisaVastused = (kommentaar) => {
                            kommentaar.lapsed.forEach(laps => {
                                if (laps.tekst.toLowerCase().includes(otsitav)) {
                                    soovitused.push({
                                        tüüp: 'vastus',
                                        id: laps.id,
                                        puuId: post.puu_id,
                                        tekst: `Vastus: ${laps.tekst.substring(0, 50)}...`
                                    });
                                }
                                if (laps.lapsed.length > 0) {lisaVastused(laps);}
                            });
                        };
                        lisaVastused(komm);
                    });
                });
                if (soovitused.length === 0) {
                    this.peidaSoovitused(konteiner);
                    return;
                }
                const soovitusDiv = document.createElement('div');
                soovitusDiv.className = 'soovitus-list';
                soovitusDiv.style.position = 'absolute';
                soovitusDiv.style.left = `${tekstVali.offsetLeft}px`;
                soovitusDiv.style.top = `${tekstVali.offsetTop + tekstVali.offsetHeight}px`;
                soovitusDiv.style.width = `${tekstVali.offsetWidth}px`;
                soovitused.forEach((t, index) => {
                    const item = document.createElement('div');
                    item.className = 'soovitus-item';
                    item.textContent = t.tekst;
                    item.dataset.id = t.id;
                    item.dataset.puuId = t.puuId;
                    item.dataset.tüüp = t.tüüp;
                    item.onclick = () => this.valiSoovitus(t.id, t.tekst.split(': ')[1].slice(0, -3), t.puuId, tekstVali);
                    if (index === this.aktiivneIndeks) {
                        item.classList.add('aktiivne');
                    }
                    soovitusDiv.appendChild(item);
                });
                this.peidaSoovitused(konteiner);
                konteiner.appendChild(soovitusDiv);
            },
            peidaSoovitused(konteiner) {
                const olemasolev = konteiner.querySelector('.soovitus-list');
                if (olemasolev) olemasolev.remove();
                this.aktiivneIndeks = -1;
            },
            valiSoovitus(id, tekst, puuId, tekstVali) {
                const link = `[${tekst}](#postitus-${id})`;
                tekstVali.value = this.viimaneSisend + link;
                this.peidaSoovitused(tekstVali.parentElement);
                tekstVali.focus();
            },
            liiguSoovitustes(suund, tekstVali, konteiner) {
                const soovitusDiv = konteiner.querySelector('.soovitus-list');
                if (!soovitusDiv) return;
                const items = soovitusDiv.querySelectorAll('.soovitus-item');
                if (items.length === 0) return;
                if (this.aktiivneIndeks >= 0) {
                    items[this.aktiivneIndeks].classList.remove('aktiivne');
                }
                if (suund === 'alla') {
                    this.aktiivneIndeks = (this.aktiivneIndeks + 1) % items.length;
                } else if (suund === 'üles') {
                    this.aktiivneIndeks = (this.aktiivneIndeks - 1 + items.length) % items.length;
                }
                items[this.aktiivneIndeks].classList.add('aktiivne');
            },
            kinnitaSoovitus(tekstVali) {
                const soovitusDiv = tekstVali.parentElement.querySelector('.soovitus-list');
                if (!soovitusDiv || this.aktiivneIndeks < 0) return;
                const valitud = soovitusDiv.querySelectorAll('.soovitus-item')[this.aktiivneIndeks];
                const id = valitud.dataset.id;
                const puuId = valitud.dataset.puuId;
                const tekst = valitud.textContent.split(': ')[1].slice(0, -3);
                this.valiSoovitus(id, tekst, puuId, tekstVali);
            }
        };
        const PostitusteHaldur = {
            selectedFiles: [],
            handleFileSelect(event) {
                const newFiles = Array.from(event.target.files);
                this.selectedFiles = [...this.selectedFiles, ...newFiles];
                this.uploadProgress = new Array(this.selectedFiles.length).fill(0); // Initialize progress for each file
                this.updatePreview();
                event.target.value = '';
            },
            updatePreview() {
                const previewContainer = document.getElementById('upload-preview');
                if (!previewContainer) return;
                previewContainer.innerHTML = '';
                
                this.selectedFiles.forEach((file, index) => {
                    const previewItem = document.createElement('div');
                    previewItem.className = 'preview-item';
                    
                    let content = '';
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            content = `
                                <img src="${e.target.result}" onclick="PostitusteHaldur.previewImage('${e.target.result}')">
                                <span>${file.name}</span>
                                <i class="fas fa-times remove-btn" onclick="PostitusteHaldur.removeFile(${index})"></i>
                            `;
                            previewItem.innerHTML = content;
                        };
                        reader.readAsDataURL(file);
                    } else {
                        content = `
                            <i class="fas fa-file"></i>
                            <span>${file.name}</span>
                            <i class="fas fa-times remove-btn" onclick="PostitusteHaldur.removeFile(${index})"></i>
                        `;
                        previewItem.innerHTML = content;
                    }
                    
                    previewContainer.appendChild(previewItem);
                });
            },
            removeFile(index) {
                this.selectedFiles.splice(index, 1);
                this.uploadProgress.splice(index, 1);
                this.updatePreview();
            },
            previewImage(src) {
                const modal = document.getElementById('galerii-modal');
                const modalImg = document.getElementById('modal-pilt');
                const postTekstDiv = document.getElementById('modal-post-tekst');
                
                modalImg.src = src;
                postTekstDiv.innerHTML = '<p>Eelvaade - pole veel postitatud</p>';
                modal.classList.add('active');
                
                modal.onclick = (e) => {
                    if (e.target === modal) modal.classList.remove('active');
                };
            },
            updateProgressBar() {
                const progressBar = document.getElementById('upload-progress');
                if (!progressBar) return;
                
                const totalProgress = this.uploadProgress.length > 0 
                    ? this.uploadProgress.reduce((sum, progress) => sum + progress, 0) / this.uploadProgress.length 
                    : 0;
                
                progressBar.style.width = `${totalProgress}%`;
                progressBar.textContent = `${Math.round(totalProgress)}%`;
            },
            async lisaPostitus() {
                if (!Andmed.valitudSõlm) return alert('Vali esmalt sõlm!');
                const tekst = document.querySelector('.postituse-redaktor textarea').value;
                if (!tekst) return alert('Sisesta postituse tekst!');

                const manused = [];
                this.uploadProgress = new Array(this.selectedFiles.length).fill(0);
                
                for (let i = 0; i < this.selectedFiles.length; i++) {
                    const file = this.selectedFiles[i];
                    const formData = new FormData();
                    formData.append('file', file);

                    const xhr = new XMLHttpRequest();
                    const uploadPromise = new Promise((resolve) => {
                        xhr.upload.onprogress = (event) => {
                            if (event.lengthComputable) {
                                this.uploadProgress[i] = (event.loaded / event.total) * 100;
                                this.updateProgressBar();
                            }
                        };

                        xhr.onload = () => {
                            if (xhr.status === 200) {
                                const data = JSON.parse(xhr.responseText);
                                if (data.status === 'uploaded') {
                                    manused.push({ path: data.path, type: data.type });
                                    this.uploadProgress[i] = 100;
                                    this.updateProgressBar();
                                }
                                resolve();
                            }
                        };

                        xhr.onerror = () => {
                            console.error('Upload failed for file:', file.name);
                            resolve(); // Continue with other uploads even if one fails
                        };
                    });

                    xhr.open('POST', window.location.href, true);
                    xhr.send(formData);
                    await uploadPromise;
                }

                const vastus = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'save_post',
                        puu_id: Andmed.valitudSõlm.id,
                        tekst,
                        manused: JSON.stringify(manused)
                    })
                });
                
                const data = await vastus.json();
                if (data.status === 'added') {
                    document.querySelector('.postituse-redaktor textarea').value = '';
                    this.selectedFiles = [];
                    this.uploadProgress = [];
                    this.updatePreview();
                    this.updateProgressBar(); // Reset to 0%
                    this.kuvaPostitused();
                    AutomaatSoovitus.laadiPealkirjad();
                    FailideJaPiltideHaldur.kuvaFailidJaPildid();
                } else {
                    alert('Postituse lisamine ebaõnnestus!');
                }
            },
            async avaFail(path, fileName) {
                const modal = document.getElementById('faili-modal');
                const link = document.getElementById('modal-fail-link');
                const postTekstDiv = document.getElementById('modal-fail-post-tekst');
                const extension = fileName.split('.').pop().toLowerCase();
                let fullPath;

                if (['txt','md','odt','pdf','ods'].includes(extension)) {
                    fullPath = `${JUUR_KAUST}/view_file.php?file=${encodeURIComponent(path.substring(1))}&projekt=${PROJEKTI_NIMI}`;
                } else {
                    fullPath = `${BASE_URL}${path}`;
                }

                link.href = fullPath;
                link.target = '_blank'; // Avab uues aknas, kui otse klikitakse
                link.innerHTML = `
                    <b class="must_tekst">Vaata või lae alla:</b> ${fileName} 
                    <button class="copy-btn" data-text="${encodeURIComponent(fileName)}" title="Kopeeri">
                        <i class="fas fa-copy"></i>
                    </button>`;
                modal.classList.add('active');

                // Otsime postituse, millega fail on seotud
                const relativePath = path.split(BASE_URL)[1] || path;
                const kõikPostitused = await apiKutse('get_postitused');
                const postitus = kõikPostitused.find(p => {
                    const manused = JSON.parse(p.manused || '[]');
                    return manused.some(m => m.path === relativePath);
                });

                if (postitus) {
                    let tekst = postitus.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                        (match, p1, p2) => {
                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                            const puuId = targetPost ? targetPost.puu_id : null;
                            return `<a href="#postitus-${p2}" onclick="AjaluguHaldur.liiguKirjeni('postitus', ${p2}, ${puuId || 'null'}); return false;">${p1}</a>`;
                        });
                    postTekstDiv.innerHTML = `
                        ${tekst} 
                        <button class="copy-btn" data-text="${encodeURIComponent(postitus.tekst)}" title="Kopeeri">
                            <i class="fas fa-copy"></i>
                        </button> 
                        <small>${new Date(postitus.aeg * 1000).toLocaleString()}</small>`;
                } else {
                    postTekstDiv.innerHTML = '<p>Postitust ei leitud.</p>';
                }

                // Kuva faili kommentaarid
                this.kuvaFailiKommentaarid(relativePath);
                document.getElementById('faili-kommentaar-nupp').onclick = () => this.lisaFailiKommentaar(relativePath);

                // Modal sulgemine
                modal.onclick = (e) => {
                    if (e.target === modal) modal.classList.remove('active');
                };

                // Lisa soovituste kuulajad ja kopeerimise funktsionaalsus
                this.lisaSoovitusKuulajad(modal);
                modal.querySelectorAll('.copy-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const tekst = decodeURIComponent(btn.dataset.text);
                        const tempInput = document.createElement('textarea');
                        tempInput.value = tekst;
                        document.body.appendChild(tempInput);
                        tempInput.select();
                        try {
                            document.execCommand('copy');
                            btn.innerHTML = '<i class="fas fa-check"></i>';
                            setTimeout(() => btn.innerHTML = '<i class="fas fa-copy"></i>', 1000);
                        } catch (err) {
                            console.error('Kopeerimine ebaõnnestus:', err);
                            alert('Kopeerimine ebaõnnestus!');
                        } finally {
                            document.body.removeChild(tempInput);
                        }
                    });
                });
            },
            async kuvaFailiKommentaarid(path) {
                const kommentaarid = await apiKutse('get_image_comments', { pilt_path: path });
                const kõikPostitused = await apiKutse('get_postitused');
                const konteiner = document.getElementById('faili-kommentaarid');
                konteiner.innerHTML = '';
                kommentaarid.forEach(komm => {
                    let tekst = komm.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                        (match, p1, p2) => {
                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                            const puuId = targetPost ? targetPost.puu_id : null;
                            return `<a href="#postitus-${p2}" onclick="AjaluguHaldur.liiguKirjeni('postitus', ${p2}, ${puuId || 'null'}); return false;">${p1}</a>`;
                        });
                    const div = document.createElement('div');
                    div.className = 'kommentaar';
                    div.dataset.id = komm.id;
                    div.innerHTML = `
                        <div style="overflow: hidden;">
                            <p>${tekst} <small>${new Date(komm.aeg * 1000).toLocaleString()}</small>
                            <button onclick="PostitusteHaldur.toggleMuudaFailiKommentaar(${komm.id}, '${path}')">Muuda</button>
                            <button onclick="PostitusteHaldur.kustutaFailiKommentaar(${komm.id}, '${path}')">Kustuta</button></p>
                        </div>
                    `;
                    konteiner.appendChild(div);
                });
            },
            async lisaFailiKommentaar(path) {
                const tekst = document.getElementById('faili-kommentaar-tekst').value;
                if (!tekst) return alert('Sisesta kommentaar!');
                await apiKutse('add_image_comment', { pilt_path: path, tekst: encodeURIComponent(tekst) });
                document.getElementById('faili-kommentaar-tekst').value = '';
                this.kuvaFailiKommentaarid(path);
            },
            async kustutaFailiKommentaar(id, path) {
                if (!confirm('Kas kustutada faili kommentaar?')) return;
                await apiKutse('delete_image_comment', { id });
                this.kuvaFailiKommentaarid(path);
            },
            async toggleMuudaFailiKommentaar(id, path) {
                const konteiner = document.getElementById('faili-kommentaarid');
                const kommDiv = konteiner.querySelector(`[data-id="${id}"]`);
                if (!kommDiv) return;
                const p = kommDiv.querySelector('p');
                const origHtml = p.innerHTML;
                const tekstNode = p.childNodes[0];
                const origTekst = tekstNode && tekstNode.nodeType === Node.TEXT_NODE ? tekstNode.textContent.trim() : '';
                const small = p.querySelector('small');
                const origAeg = small ? small.textContent : '';
                if (p.querySelector('textarea')) return;
                const textarea = document.createElement('textarea');
                textarea.id = `kommentaar-editor-${id}`;
                textarea.className = 'kommentaari-editor-container';
                textarea.value = origTekst;
                p.innerHTML = '';
                p.appendChild(textarea);
                const saveBtn = document.createElement('button');
                saveBtn.textContent = 'Salvesta';
                saveBtn.onclick = () => this.salvestaFailiKommentaarMuudatus(id, path);
                p.appendChild(saveBtn);

                const cancelBtn = document.createElement('button');
                cancelBtn.textContent = 'Tühista';
                cancelBtn.onclick = () => {
                    p.innerHTML = origHtml;
                };
                p.appendChild(cancelBtn);
            },
            async salvestaFailiKommentaarMuudatus(id, path) {
                const konteiner = document.getElementById('faili-kommentaarid');
                const kommDiv = konteiner.querySelector(`[data-id="${id}"]`);
                const textarea = kommDiv.querySelector('textarea');
                const uusTekst = textarea.value;
                await apiKutse('update_image_comment', { id, tekst: encodeURIComponent(uusTekst) });
                this.kuvaFailiKommentaarid(path);
            },
            async kuvaPostitused() {
                const konteiner = document.getElementById('postitused');
                konteiner.innerHTML = '';
                if (!Andmed.valitudSõlm) return;

                const postitused = await apiKutse('get_postitused', { puu_id: Andmed.valitudSõlm.id });
                const kõikPostitused = await apiKutse('get_postitused');

                for (const postitus of postitused) {
                    const div = document.createElement('div');
                    div.className = 'postitus';
                    div.id = `postitus-${postitus.id}`;

                    // Postituse teksti töötlemine linkidega
                    let tekst = postitus.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                        (match, p1, p2) => {
                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                            const puuId = targetPost ? targetPost.puu_id : null;
                            return `<a href="#postitus-${p2}" class="postitus-link" data-id="${p2}" data-puu-id="${puuId || ''}">${p1}</a>`;
                        });

                    // Manuste töötlemine
                    const manused = JSON.parse(postitus.manused || '[]');
                    let failideHtml = '<div class="postituse-failid">';
                    const galerii = document.createElement('div');
                    galerii.className = 'galerii';

                    for (const manus of manused) {
                        const fullPath = `${BASE_URL}${manus.path}`;
                        const fileName = manus.path.split('/').pop();
                        const extension = fileName.split('.').pop().toLowerCase();
                        let linkPath = fullPath;

                        if (['txt','md','odt','pdf','ods'].includes(extension)) {
                            linkPath = `${JUUR_KAUST}/view_file.php?file=${encodeURIComponent(manus.path.substring(1))}&projekt=${PROJEKTI_NIMI}`;
                        }

                        if (manus.type.startsWith('image/')) {
                            const galeriiÜksus = document.createElement('div');
                            galeriiÜksus.className = 'galerii-üksus-postitus';
                            galeriiÜksus.innerHTML = `<img src="${fullPath}" alt="${fileName}" onclick="PostitusteHaldur.avaPilt('${fullPath}')">`;
                            galerii.appendChild(galeriiÜksus);

                            const imgComments = await apiKutse('get_image_comments', { pilt_path: manus.path });
                            if (imgComments.length > 0) {
                                const imgCommDiv = document.createElement('div');
                                imgCommDiv.className = 'pildi-kommentaarid';
                                imgComments.forEach(komm => {
                                    let kommTekst = komm.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                                        (match, p1, p2) => {
                                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                                            const puuId = targetPost ? targetPost.puu_id : null;
                                            return `<a href="#postitus-${p2}" class="postitus-link" data-id="${p2}" data-puu-id="${puuId || ''}">${p1}</a>`;
                                        });
                                    imgCommDiv.innerHTML += `
                                        <div class="kommentaar pildi-kommentaar" data-komm-id="${komm.id}" onclick="PostitusteHaldur.avaPilt('${fullPath}')">
                                            <img src="${fullPath}" style="width: 50px; height: 50px; float: left; margin-right: 10px;">
                                            <div style="overflow: hidden;">
                                                <p>${kommTekst} <button class="copy-btn" data-text="${encodeURIComponent(komm.tekst)}" title="Kopeeri2"><i class="fas fa-copy"></i></button></p>
                                                <small>${new Date(komm.aeg * 1000).toLocaleString()}</small>
                                            </div>
                                        </div>`;
                                });
                                galerii.appendChild(imgCommDiv);
                            }
                        } else {
                            failideHtml += `
                                <a href="${linkPath}" target="_blank" onclick="PostitusteHaldur.avaFail('${manus.path}', '${fileName}'); return false;">${fileName}</a>
                                <button class="copy-btn" data-text="${encodeURIComponent(fileName)}" title="Kopeeri3"><i class="fas fa-copy"></i></button>`;
                            
                            const fileComments = await apiKutse('get_image_comments', { pilt_path: manus.path });
                            if (fileComments.length > 0) {
                                const fileCommDiv = document.createElement('div');
                                fileCommDiv.className = 'faili-kommentaarid';
                                fileComments.forEach(komm => {
                                    let kommTekst = komm.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                                        (match, p1, p2) => {
                                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                                            const puuId = targetPost ? targetPost.puu_id : null;
                                            return `<a href="#postitus-${p2}" class="postitus-link" data-id="${p2}" data-puu-id="${puuId || ''}">${p1}</a>`;
                                        });
                                    fileCommDiv.innerHTML += `
                                        <div class="kommentaar faili-kommentaar" data-komm-id="${komm.id}" onclick="PostitusteHaldur.avaFail('${manus.path}', '${fileName}')">
                                            <i class="fas fa-file" style="margin-top: -7px; width: 50px; height: 50px; float: left; margin-right: -17px; font-size: 30px; line-height: 50px;"></i>
                                            <div style="overflow: hidden;">
                                                <p>${kommTekst}</p>
                                                <small>${new Date(komm.aeg * 1000).toLocaleString()}</small>
                                            </div>
                                        </div>`;
                                });
                                failideHtml += fileCommDiv.outerHTML;
                            }
                        }
                    }

                    failideHtml += '</div>' + (galerii.children.length > 0 ? galerii.outerHTML : '');

                    // Kommentaaride töötlemine
                    const kommentaarid = this.ehitaKommentaarideHierarhia(postitus.kommentaarid || []);
                    let kommentaarideHtml = '<div class="postituse-kommentaarid">';
                    kommentaarideHtml += this.renderKommentaarid(kommentaarid, postitus.id, kõikPostitused);
                    kommentaarideHtml += '</div>';

                    // Postituse HTML
                    div.innerHTML = `
                        <div class="postitus-text">${tekst} <button class="copy-btn" data-text="${encodeURIComponent(postitus.tekst)}" title="Kopeeri5"><i class="fas fa-copy"></i></button></div>
                        <div class="postitus-timestamp">${new Date(postitus.aeg * 1000).toLocaleString()}</div>
                        <button class="edit-btn" data-post-id="${postitus.id}">Muuda</button>
                        <button onclick="PostitusteHaldur.kustutaPostitus(${postitus.id})">Kustuta</button>
                        ${failideHtml}
                        ${kommentaarideHtml}
                        <div class="kommentaari-redaktor" data-postitus-id="${postitus.id}">
                            <textarea class="kommentaari-textarea" placeholder="Lisa kommentaar...."></textarea>
                            <button onclick="PostitusteHaldur.lisaKommentaar(${postitus.id})">Kommenteeri</button>
                        </div>
                    `;
                    konteiner.appendChild(div);

                    // Muutmise nupu sündmus
                    const editBtn = div.querySelector('.edit-btn');
                    editBtn.addEventListener('click', () => this.toggleMuudaPostitus(postitus.id, tekst));
                }

                // Lingi sündmuste lisamine
                const lingid = konteiner.querySelectorAll('.postitus-link');
                lingid.forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        const id = parseInt(link.dataset.id);
                        const puuId = link.dataset.puuId ? parseInt(link.dataset.puuId) : null;
                        const tekst = link.textContent;
                        JooksevHaldur.lisaLink('postitus', id, puuId, tekst);
                        AjaluguHaldur.liiguKirjeni('postitus', id, puuId);
                    });
                });

                // Kopeerimisnuppude sündmused
                document.querySelectorAll('.copy-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const tekst = decodeURIComponent(btn.dataset.text);
                        const tempInput = document.createElement('textarea');
                        tempInput.value = tekst;
                        document.body.appendChild(tempInput);
                        tempInput.select();
                        try {
                            document.execCommand('copy');
                            btn.innerHTML = '<i class="fas fa-check"></i>';
                            setTimeout(() => btn.innerHTML = '<i class="fas fa-copy"></i>', 1000);
                        } catch (err) {
                            console.error('Kopeerimine ebaõnnestus:', err);
                            alert('Kopeerimine ebaõnnestus!');
                        } finally {
                            document.body.removeChild(tempInput);
                        }
                    });
                });

                this.lisaSoovitusKuulajad(konteiner);
                FailideJaPiltideHaldur.kuvaFailidJaPildid();
            },
            ehitaKommentaarideHierarhia(kommentaarid) {
                const idKaart = new Map(kommentaarid.map(k => [k.id, { ...k, lapsed: [] }]));
                const juurKommentaarid = [];
                idKaart.forEach(k => {
                    if (k.vanem_id) {
                        idKaart.get(k.vanem_id)?.lapsed.push(k);
                    } else {
                        juurKommentaarid.push(k);
                    }
                });
                return juurKommentaarid;
            },
            renderKommentaarid(kommentaarid, postitusId, kõikPostitused) {
                let html = '';
                kommentaarid.forEach(komm => {
                    let kommTekst = komm.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                        (match, p1, p2) => {
                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                            const puuId = targetPost ? targetPost.puu_id : null;
                            return `<a href="#postitus-${p2}" class="postitus-link" data-id="${p2}" data-puu-id="${puuId || ''}">${p1}</a>`;
                        });
                    html += `
                        <div class="kommentaar" id="kommentaar-${komm.id}" data-komm-id="${komm.id}">
                            <p>${kommTekst} <button class="copy-btn" data-text="${encodeURIComponent(komm.tekst)}" title="Kopeeri6"><i class="fas fa-copy"></i></button></p>
                            <small>${new Date(komm.aeg * 1000).toLocaleString()}</small>
                            <button onclick="PostitusteHaldur.toggleVasta(${komm.id}, ${postitusId})">Vasta</button>
                            <button onclick="PostitusteHaldur.toggleMuudaKommentaar(${komm.id})">Muuda</button>
                            <button onclick="PostitusteHaldur.kustutaKommentaar(${komm.id})">Kustuta</button>
                            <div id="vasta-${komm.id}" class="vasta-form" style="display: none;">
                                <textarea class="vasta-redaktor" placeholder="Kirjuta vastus..."></textarea>
                                <button onclick="PostitusteHaldur.lisaKommentaar(${postitusId}, ${komm.id})">Saada</button>
                            </div>
                        </div>
                    `;
                    if (komm.lapsed.length > 0) {
                        html += '<div class="kommentaaride-lapsed">';
                        html += this.renderKommentaarid(komm.lapsed, postitusId, kõikPostitused);
                        html += '</div>';
                    }
                });
                return html;
            },
            toggleVasta(kommId, postitusId) {
                const vastaDiv = document.getElementById(`vasta-${kommId}`);
                vastaDiv.style.display = vastaDiv.style.display === 'none' ? 'block' : 'none';
            },
            async lisaKommentaar(postitusId, vanemId = null) {
                let tekst;
                if (vanemId) {
                    tekst = document.querySelector(`#vasta-${vanemId} textarea`).value;
                    document.querySelector(`#vasta-${vanemId}`).style.display = 'none';
                } else {
                    tekst = document.querySelector(`.kommentaari-redaktor[data-postitus-id="${postitusId}"] textarea`).value;
                }
                if (!tekst) return alert('Sisesta kommentaari tekst!');
                await apiKutse('add_comment', { 
                    postitus_id: postitusId, 
                    tekst: encodeURIComponent(tekst), 
                    vanem_id: vanemId 
                });
                if (!vanemId) {
                    document.querySelector(`.kommentaari-redaktor[data-postitus-id="${postitusId}"] textarea`).value = '';
                } else {
                    document.querySelector(`#vasta-${vanemId} textarea`).value = '';
                }
                this.kuvaPostitused();
            },
            async kustutaKommentaar(id) {
                if (!confirm('Kas kustutada kommentaar?')) return;
                await apiKutse('delete_comment', { id });
                this.kuvaPostitused();
            },
            async kustutaPostitus(id) {
                if (!confirm('Kas kustutada postitus ja selle kommentaarid?')) return;
                await apiKutse('delete_post', { id });
                this.kuvaPostitused();
                FailideJaPiltideHaldur.kuvaFailidJaPildid();
            },
            async avaPilt(path) {
                const modal = document.getElementById('galerii-modal');
                const pilt = document.getElementById('modal-pilt');
                const postTekstDiv = document.getElementById('modal-post-tekst');
                pilt.src = path;
                modal.classList.add('active');
                const relativePath = path.split(BASE_URL)[1];
                const kõikPostitused = await apiKutse('get_postitused');
                const postitus = kõikPostitused.find(p => {
                    const manused = JSON.parse(p.manused || '[]');
                    return manused.some(m => m.path === relativePath);
                });
                if (postitus) {
                    let tekst = postitus.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                        (match, p1, p2) => {
                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                            const puuId = targetPost ? targetPost.puu_id : null;
                            return `<a href="#postitus-${p2}" onclick="AjaluguHaldur.liiguKirjeni('postitus', ${p2}, ${puuId || 'null'}); return false;">${p1}</a>`;
                        });
                    postTekstDiv.innerHTML = `${tekst} <button class="copy-btn" data-text="${encodeURIComponent(postitus.tekst)}" title="Kopeeri7"><i class="fas fa-copy"></i></button> <small>${new Date(postitus.aeg * 1000).toLocaleString()}</small>`;
                } else {
                    postTekstDiv.innerHTML = '<p>Postitust ei leitud.</p>';
                }
                this.kuvaPildiKommentaarid(relativePath);
                document.getElementById('pildi-kommentaar-nupp').onclick = () => this.lisaPildiKommentaar(relativePath);
                modal.onclick = (e) => {if (e.target === modal) modal.classList.remove('active');};
                this.lisaSoovitusKuulajad(modal);
                modal.querySelectorAll('.copy-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const tekst = decodeURIComponent(btn.dataset.text);
                        const tempInput = document.createElement('textarea');
                        tempInput.value = tekst;
                        document.body.appendChild(tempInput);
                        tempInput.select();
                        try {
                            document.execCommand('copy');
                            btn.innerHTML = '<i class="fas fa-check"></i>';
                            setTimeout(() => btn.innerHTML = '<i class="fas fa-copy"></i>', 1000);
                        } catch (err) {
                            console.error('Kopeerimine ebaõnnestus:', err);
                            alert('Kopeerimine ebaõnnestus!');
                        } finally {
                            document.body.removeChild(tempInput);
                        }
                    });
                });
            },
            async kuvaPildiKommentaarid(path) {
                const kommentaarid = await apiKutse('get_image_comments', { pilt_path: path });
                const kõikPostitused = await apiKutse('get_postitused');
                const konteiner = document.getElementById('pildi-kommentaarid');
                konteiner.innerHTML = '';
                kommentaarid.forEach(komm => {
                    let tekst = komm.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                        (match, p1, p2) => {
                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                            const puuId = targetPost ? targetPost.puu_id : null;
                            return `<a href="#postitus-${p2}" onclick="AjaluguHaldur.liiguKirjeni('postitus', ${p2}, ${puuId || 'null'}); return false;">${p1}</a>`;
                        });
                    const div = document.createElement('div');
                    div.className = 'kommentaar';
                    div.dataset.id = komm.id;
                    div.innerHTML = `
                        <div style="overflow: hidden;">
                            <p>${tekst} <small>${new Date(komm.aeg * 1000).toLocaleString()}</small>
                            <button onclick="PostitusteHaldur.toggleMuudaPildiKommentaar(${komm.id}, '${path}')">Muuda</button>
                            <button onclick="PostitusteHaldur.kustutaPildiKommentaar(${komm.id}, '${path}')">Kustuta</button></p>
                        </div>`;
                    konteiner.appendChild(div);
                });
            },
            async lisaPildiKommentaar(path) {
                const tekst = document.getElementById('pildi-kommentaar-tekst').value;
                if (!tekst) return alert('Sisesta kommentaar!');
                await apiKutse('add_image_comment', { pilt_path: path, tekst: encodeURIComponent(tekst) });
                document.getElementById('pildi-kommentaar-tekst').value = '';
                this.kuvaPildiKommentaarid(path);
            },
            async kustutaPildiKommentaar(id, path) {
                if (!confirm('Kas kustutada pildi kommentaar?')) return;
                await apiKutse('delete_image_comment', { id });
                this.kuvaPildiKommentaarid(path);
            },
            async toggleMuudaPostitus(id, tekst) {
                const postDiv = document.getElementById(`postitus-${id}`);
                const textDiv = postDiv.querySelector('.postitus-text');
                const timestampDiv = postDiv.querySelector('.postitus-timestamp');
                const existingEditBtn = postDiv.querySelector('.edit-btn'); // Otsime olemasolevat nuppu
                if (textDiv.querySelector('textarea')) return;
                const origTekst = tekst;
                const origTimestamp = timestampDiv.textContent;
                if (existingEditBtn) {
                    existingEditBtn.remove();
                }
                const textarea = document.createElement('textarea');
                textarea.id = `kommentaar-editor-${id}`;
                textarea.className = 'kommentaari-editor-container';
                textarea.value = origTekst;
                textDiv.innerHTML = '';
                textDiv.appendChild(textarea);
                const saveBtn = document.createElement('button');
                saveBtn.textContent = 'Salvesta';
                saveBtn.onclick = () => this.salvestaPostitusMuudatus(id);
                textDiv.appendChild(saveBtn);
                const cancelBtn = document.createElement('button');
                cancelBtn.textContent = 'Tühista';
                cancelBtn.onclick = () => {
                    textDiv.innerHTML = origTekst;
                    timestampDiv.textContent = origTimestamp;
                    const newEditBtn = document.createElement('button');
                    newEditBtn.className = 'edit-btn';
                    newEditBtn.textContent = 'Muuda';
                    newEditBtn.onclick = () => this.toggleMuudaPostitus(id, origTekst.replace(/'/g, "\\'"));
                    postDiv.insertBefore(newEditBtn, timestampDiv.nextSibling);
                };
                textDiv.appendChild(cancelBtn);
            },
            async salvestaPostitusMuudatus(id) {
                const postDiv = document.getElementById(`postitus-${id}`);
                const textarea = postDiv.querySelector('textarea');
                const uusTekst = textarea.value;
                await apiKutse('update_post', { id, tekst: encodeURIComponent(uusTekst) });
                this.kuvaPostitused();
                FailideJaPiltideHaldur.kuvaFailidJaPildid();
            },
            async toggleMuudaKommentaar(id) {
                const kommDiv = document.getElementById(`kommentaar-${id}`);
                if (!kommDiv) return;
                const p = kommDiv.querySelector('p');
                const origHtml = p.innerHTML;
                const small = p.querySelector('small');
                const origAeg = small ? small.textContent : '';
                const origTekst = p.textContent.replace(origAeg, '').trim().replace(/Vasta\s*Muuda\s*Kustuta\s*$/, '');
                if (p.querySelector('textarea')) return;
                const textarea = document.createElement('textarea');
                textarea.id = `kommentaar-editor-${id}`;
                textarea.className = 'kommentaari-editor-container';
                textarea.value = origTekst;
                p.innerHTML = '';
                p.appendChild(textarea);
                const saveBtn = document.createElement('button');
                saveBtn.textContent = 'Salvesta';
                saveBtn.onclick = () => this.salvestaKommentaarMuudatus(id);
                p.appendChild(saveBtn);
                const cancelBtn = document.createElement('button');
                cancelBtn.textContent = 'Tühista';
                cancelBtn.onclick = () => {p.innerHTML = origHtml;};
                p.appendChild(cancelBtn);
            },
            async salvestaKommentaarMuudatus(id) {
                const kommDiv = document.getElementById(`kommentaar-${id}`);
                const textarea = kommDiv.querySelector('textarea');
                const uusTekst = textarea.value;
                await apiKutse('update_comment', { id, tekst: encodeURIComponent(uusTekst) });
                this.kuvaPostitused();
            },
            async toggleMuudaPildiKommentaar(id, path) {
                const konteiner = document.getElementById('pildi-kommentaarid');
                const kommDiv = konteiner.querySelector(`[data-id="${id}"]`);
                if (!kommDiv) return;
                const p = kommDiv.querySelector('p');
                const origHtml = p.innerHTML;
                const tekstNode = p.childNodes[0];
                const origTekst = tekstNode && tekstNode.nodeType === Node.TEXT_NODE ? tekstNode.textContent.trim() : '';
                const small = p.querySelector('small');
                const origAeg = small ? small.textContent : '';
                if (p.querySelector('textarea')) return;
                const textarea = document.createElement('textarea');
                textarea.id = `kommentaar-editor-${id}`;
                textarea.className = 'kommentaari-editor-container';
                textarea.value = origTekst;
                p.innerHTML = '';
                p.appendChild(textarea);
                const saveBtn = document.createElement('button');
                saveBtn.textContent = 'Salvesta';
                saveBtn.onclick = () => this.salvestaPildiKommentaarMuudatus(id, path);
                p.appendChild(saveBtn);
                const cancelBtn = document.createElement('button');
                cancelBtn.textContent = 'Tühista';
                cancelBtn.onclick = () => {
                    p.innerHTML = origHtml;
                };
                p.appendChild(cancelBtn);
            },
            async salvestaPildiKommentaarMuudatus(id, path) {
                const konteiner = document.getElementById('pildi-kommentaarid');
                const kommDiv = konteiner.querySelector(`[data-id="${id}"]`);
                const textarea = kommDiv.querySelector('textarea');
                const uusTekst = textarea.value;
                await apiKutse('update_image_comment', { id, tekst: encodeURIComponent(uusTekst) });
                this.kuvaPildiKommentaarid(path);
            },
            lisaSoovitusKuulajad(konteiner) {
                const tekstValjad = konteiner.querySelectorAll('textarea');
                tekstValjad.forEach(tekstVali => {
                    tekstVali.addEventListener('input', () => {
                        AutomaatSoovitus.kuvaSoovitused(tekstVali, konteiner);
                    });
                    tekstVali.addEventListener('keydown', (e) => {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            AutomaatSoovitus.liiguSoovitustes('alla', tekstVali, konteiner);
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            AutomaatSoovitus.liiguSoovitustes('üles', tekstVali, konteiner);
                        } else if (e.key === 'Enter') {
                            if (AutomaatSoovitus.aktiivneIndeks >= 0) {
                                e.preventDefault();
                                AutomaatSoovitus.kinnitaSoovitus(tekstVali);
                            }
                        } else if (e.key === 'Escape') {
                            AutomaatSoovitus.peidaSoovitused(konteiner);
                        }
                    });
                });
            }
        };
        const FailideJaPiltideHaldur = {
            async kuvaFailidJaPildid() {
                const failideKonteiner = document.getElementById('failide-sisu');
                const piltideKonteiner = document.getElementById('galerii-sisu');
                failideKonteiner.innerHTML = '';
                piltideKonteiner.innerHTML = '';

                if (!Andmed.valitudSõlm) {
                    failideKonteiner.innerHTML = '<p>Vali sõlm!</p>';
                    piltideKonteiner.innerHTML = '<p>Vali sõlm!</p>';
                    return;
                }

                const postitused = await apiKutse('get_postitused', { puu_id: Andmed.valitudSõlm.id });
                const failid = [];
                const pildid = [];

                postitused.forEach(post => {
                    const manused = JSON.parse(post.manused || '[]');
                    manused.forEach(manus => {
                        const fullPath = `${BASE_URL}${manus.path}`; // e.g., /marcmic_2/portiaz_5/uploads/1743007856_1742988091_installer.odt
                        const fileName = manus.path.split('/').pop(); // e.g., 1743007856_1742988091_installer.odt
                        const extension = fileName.split('.').pop().toLowerCase();
                        let linkPath = fullPath;

                        if (['txt', 'md', 'odt', 'pdf', 'ods'].includes(extension)) {
                            // Extract the relative path starting from 'uploads'
                            const relativePath = manus.path.split('/uploads/')[1] || manus.path.substring(1);
                            linkPath = `${JUUR_KAUST}/view_file.php?file=${encodeURIComponent(relativePath)}&projekt=${PROJEKTI_NIMI}`;
                        }

                        if (manus.type.startsWith('image/')) {
                            pildid.push({ path: fullPath, name: fileName, postId: post.id });
                        } else {
                            failid.push({ path: linkPath, name: fileName, postId: post.id });
                        }
                    });
                });

                if (failid.length === 0) {
                    failideKonteiner.innerHTML = '<p></p>';//Faile pole
                } else {
                    failid.forEach(fail => {
                        const a = document.createElement('a');
                        a.href = fail.path;
                        a.target = '_blank';
                        a.textContent = fail.name;
                        a.onclick = (e) => {
                            e.preventDefault();
                            const origPath = fail.path.includes('view_file.php') 
                                ? `/uploads/${fail.name}` // Simplified for non-viewed files
                                : fail.path;
                            PostitusteHaldur.avaFail(origPath, fail.name);
                            JooksevHaldur.lisaLink('fail', fail.postId, Andmed.valitudSõlm.id, fail.name);
                        };
                        failideKonteiner.appendChild(a);
                    });
                }

                if (pildid.length === 0) {
                    piltideKonteiner.innerHTML = '<p></p>';//Pilte pole.
                } else {
                    pildid.forEach(pilt => {
                        const div = document.createElement('div');
                        div.className = 'galerii-üksus';
                        div.innerHTML = `<img src="${pilt.path}" alt="${pilt.name}" title="${pilt.name}">`;
                        div.querySelector('img').onclick = () => {
                            PostitusteHaldur.avaPilt(pilt.path);
                            JooksevHaldur.lisaLink('pilt', pilt.postId, Andmed.valitudSõlm.id, pilt.name);
                        };
                        piltideKonteiner.appendChild(div);
                    });
                }
            }
        };
        const OtsinguHaldur = {
            async otsi(query) {
                const postitused = await apiKutse('get_postitused');
                const tulemused = [];
                postitused.forEach(post => {
                    if (post.tekst.toLowerCase().includes(query.toLowerCase())) {
                        tulemused.push({
                            tüüp: 'postitus',
                            id: post.id,
                            puuId: post.puu_id,
                            tekst: `Postitus: ${post.tekst.substring(0, 50)}...`
                        });
                    }
                    const manused = JSON.parse(post.manused || '[]');
                    manused.forEach(manus => {
                        const fileName = manus.path.split('/').pop();
                        if (fileName.toLowerCase().includes(query.toLowerCase())) {
                            if (manus.type.startsWith('image/')) {
                                tulemused.push({
                                    tüüp: 'pilt',
                                    id: post.id,
                                    puuId: post.puu_id,
                                    tekst: `Pilt: ${fileName}`
                                });
                            } else {
                                tulemused.push({
                                    tüüp: 'fail',
                                    id: post.id,
                                    puuId: post.puu_id,
                                    tekst: `Fail: ${fileName}`
                                });
                            }
                        }
                    });
                    const kommentaarid = PostitusteHaldur.ehitaKommentaarideHierarhia(post.kommentaarid || []);
                    kommentaarid.forEach(komm => {
                        if (komm.tekst.toLowerCase().includes(query.toLowerCase()) && !komm.vanem_id) {
                            tulemused.push({
                                tüüp: 'kommentaar',
                                id: komm.id,
                                puuId: post.puu_id,
                                tekst: `Kommentaar: ${komm.tekst.substring(0, 50)}...`
                            });
                        }
                        const lisaVastused = (kommentaar) => {
                            kommentaar.lapsed.forEach(laps => {
                                if (laps.tekst.toLowerCase().includes(query.toLowerCase())) {
                                    tulemused.push({
                                        tüüp: 'vastus',
                                        id: laps.id,
                                        puuId: post.puu_id,
                                        tekst: `Vastus: ${laps.tekst.substring(0, 50)}...`
                                    });
                                }
                                if (laps.lapsed.length > 0) {
                                    lisaVastused(laps);
                                }
                            });
                        };
                        lisaVastused(komm);
                    });
                });
                // Otsi puu sõlmedest
                const otsiPuuSõlmi = (sõlmed) => {
                    sõlmed.forEach(sõlm => {
                        if (sõlm.nimi.toLowerCase().includes(query.toLowerCase())) {
                            const onLapsi = sõlm.lapsed && sõlm.lapsed.length > 0;
                            tulemused.push({
                                tüüp: onLapsi ? 'oks' : 'leht',
                                id: sõlm.id,
                                puuId: sõlm.id,
                                tekst: `${onLapsi ? 'Oks' : 'Leht'}: ${sõlm.nimi}`
                            });
                        }
                        if (sõlm.lapsed && sõlm.lapsed.length > 0) {
                            otsiPuuSõlmi(sõlm.lapsed);
                        }
                    });
                };
                otsiPuuSõlmi(Andmed.puu);
                return tulemused;
            },
            async kuvaOtsinguTulemused(query) {
                const tulemused = await this.otsi(query);
                const konteiner = document.getElementById('otsingu-tulemused');
                konteiner.innerHTML = '';
                tulemused.forEach(tulemus => {
                    const div = document.createElement('div');
                    div.className = 'otsingu-tulemus';
                    div.innerHTML = `
                        <a href="#" class="otsingu-link" data-tüüp="${tulemus.tüüp}" data-id="${tulemus.id}" data-puu-id="${tulemus.puuId || ''}">
                            ${tulemus.tekst}</a>`;
                    konteiner.appendChild(div);
                });
                const lingid = konteiner.querySelectorAll('.otsingu-link');
                lingid.forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        const tüüp = link.dataset.tüüp;
                        const id = parseInt(link.dataset.id);
                        const puuId = link.dataset.puuId ? parseInt(link.dataset.puuId) : null;
                        const tekst = link.textContent;
                        JooksevHaldur.lisaLink(tüüp, id, puuId, tekst);
                        AjaluguHaldur.liiguKirjeni(tüüp, id, puuId);
                        konteiner.innerHTML = '';
                    });
                });
                this.lisaKlaviatuuriNavigatsioon(konteiner);
            },
            lisaKlaviatuuriNavigatsioon(konteiner) {
                const tulemused = konteiner.querySelectorAll('.otsingu-tulemus');
                if (!tulemused.length) return;
                let aktiivneIndeks = -1;
                const uuendaAktiivne = (uusIndeks) => {
                    if (aktiivneIndeks >= 0) {
                        tulemused[aktiivneIndeks].classList.remove('active');
                    }
                    aktiivneIndeks = (uusIndeks + tulemused.length) % tulemused.length;
                    tulemused[aktiivneIndeks].classList.add('active');
                    tulemused[aktiivneIndeks].scrollIntoView({ block: 'nearest' });
                };
                document.addEventListener('keydown', function handler(e) {
                    if (!konteiner.children.length) {
                        document.removeEventListener('keydown', handler);
                        return;
                    }
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        uuendaAktiivne(aktiivneIndeks + 1);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        uuendaAktiivne(aktiivneIndeks - 1);
                    } else if (e.key === 'Enter' && aktiivneIndeks >= 0) {
                        e.preventDefault();
                        const valitudLink = tulemused[aktiivneIndeks].querySelector('.otsingu-link');
                        const tüüp = valitudLink.dataset.tüüp;
                        const id = parseInt(valitudLink.dataset.id);
                        const puuId = valitudLink.dataset.puuId ? parseInt(valitudLink.dataset.puuId) : null;
                        console.log('Enter vajutatud otsingutulemusel:', { tüüp, id, puuId });
                        AjaluguHaldur.liiguKirjeni(tüüp, id, puuId);
                        konteiner.innerHTML = '';
                        document.getElementById('otsing').value = '';
                    } else if (e.key === 'Escape') {
                        konteiner.innerHTML = '';
                    }
                });
            },
            init() {
                const otsinguVäli = document.getElementById('otsing');
                otsinguVäli.addEventListener('input', async (e) => {
                    const query = e.target.value.trim();
                    if (query.length > 1) {
                        await this.kuvaOtsinguTulemused(query);
                    } else {
                        document.getElementById('otsingu-tulemused').innerHTML = '';
                    }
                });
                otsinguVäli.addEventListener('keypress', async (e) => {
                    if (e.key === 'Enter') {
                        const query = e.target.value.trim();
                        if (query) {
                            const tulemused = await this.otsi(query);
                            if (tulemused.length > 0) {
                                const esimeneTulemus = tulemused[0];
                                console.log('Enter vajutatud otsinguväljal, esimene tulemus:', esimeneTulemus);
                                AjaluguHaldur.liiguKirjeni(esimeneTulemus.tüüp, esimeneTulemus.id, esimeneTulemus.puuId);
                                document.getElementById('otsingu-tulemused').innerHTML = '';
                                otsinguVäli.value = '';
                            }
                        }
                    }
                });
            }
        };
        OtsinguHaldur.init();
        const AjaluguHaldur = {
            async liiguKirjeni(tüüp, id, puuId) {
                console.log('liiguKirjeni kutsutud:', { tüüp, id, puuId });
                if (puuId) {
                    const sõlm = PuuHaldur.findNode(Andmed.puu, puuId);
                    if (sõlm) {
                        Andmed.valitudSõlm = sõlm;
                        PuuHaldur.kuvaPostitamisLeht();
                        await PostitusteHaldur.kuvaPostitused();
                    } else {
                        console.error('Sõlme ei leitud ID-ga:', puuId);
                        return;
                    }
                } else {
                    console.warn('puuId puudub');
                }
                await new Promise(resolve => setTimeout(resolve, 100));
                let targetElement;
                switch (tüüp) {
                    case 'postitus':
                        targetElement = document.getElementById(`postitus-${id}`);
                        break;
                    case 'kommentaar':
                    case 'vastus':
                        targetElement = document.querySelector(`.kommentaar[data-komm-id="${id}"]`);
                        if (!targetElement) {
                            const postitus = await apiKutse('get_postitus_by_kommentaar', { kommentaar_id: id });
                            targetElement = document.getElementById(`postitus-${postitus.id}`);
                        }
                        break;
                    case 'pilt':
                        targetElement = document.getElementById(`postitus-${id}`);
                        const postitused = await apiKutse('get_postitused', { puu_id: puuId });
                        const postitus = postitused.find(p => p.id === id);
                        const manused = JSON.parse(postitus.manused || '[]');
                        const pilt = manused.find(m => m.type.startsWith('image/'));
                        if (pilt) {
                            PostitusteHaldur.avaPilt(`${BASE_URL}${pilt.path}`);
                        }
                        break;
                    case 'fail':
                        targetElement = document.getElementById(`postitus-${id}`);
                        const postitusedFail = await apiKutse('get_postitused', { puu_id: puuId });
                        const postitusFail = postitusedFail.find(p => p.id === id);
                        const manusedFail = JSON.parse(postitusFail.manused || '[]');
                        const fail = manusedFail.find(m => !m.type.startsWith('image/'));
                        if (fail) {
                            PostitusteHaldur.avaFail(`${BASE_URL}${fail.path}`, fail.path.split('/').pop());
                        }
                        break;
                    default:
                        console.error('Tundmatu tüüp:', tüüp);
                        return;
                }
                if (targetElement) {
                    document.querySelectorAll('.highlight')?.forEach(el => el.classList.remove('highlight'));
                    targetElement.scrollIntoView({ behavior: 'smooth' });
                    targetElement.classList.add('highlight');
                    window.location.hash = `postitus-${id}`;
                } else {
                    console.error('Elementi ei leitud:', { tüüp, id });
                }
            },
            async kuvaAjalugu() {
                const peasisu = document.getElementById('peasisu');
                const ajaluguVaade = document.getElementById('ajalugu-vaade');
                peasisu.classList.add('hidden');
                ajaluguVaade.classList.remove('hidden');
                const filters = {
                    failid: document.getElementById('filter-failid'),
                    pildid: document.getElementById('filter-pildid'),
                    kommentaarid: document.getElementById('filter-kommentaarid'),
                    vastused: document.getElementById('filter-vastused'),
                    postitused: document.getElementById('filter-postitused')
                };
                const renderAjalugu = async () => {
                    const konteiner = document.getElementById('ajalugu-sisu');
                    konteiner.innerHTML = '';
                    const postitused = await apiKutse('get_postitused');
                    const tegevused = [];
                    const pildiFormaadid = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/bmp'];
                    postitused.forEach(post => {
                        if (filters.postitused.checked) {
                            tegevused.push({
                                aeg: post.aeg * 1000,
                                tekst: `<b>Postitus</b>: ${post.tekst.substring(0, 120)}...`,
                                tüüp: 'postitus',
                                id: post.id,
                                puuId: post.puu_id
                            });
                        }
                        const manused = JSON.parse(post.manused || '[]');
                        manused.forEach(manus => {
                            const onPilt = pildiFormaadid.includes(manus.type);
                            if (onPilt && filters.pildid.checked) {
                                tegevused.push({
                                    aeg: post.aeg * 1000,
                                    tekst: `<b style="color: #181bd7;">Pilt</b>: ${manus.path.split('/').pop()} - ${post.tekst.substring(0, 50)}...`,
                                    tüüp: 'pilt',
                                    id: post.id,
                                    puuId: post.puu_id
                                });
                            } else if (!onPilt && filters.failid.checked) {
                                tegevused.push({
                                    aeg: post.aeg * 1000,
                                    tekst: `<b style="color: #5abd2c;">Fail</b>: ${manus.path.split('/').pop()} - ${post.tekst.substring(0, 50)}...`,
                                    tüüp: 'fail',
                                    id: post.id,
                                    puuId: post.puu_id
                                });
                            }
                        });
                        const kommentaarid = PostitusteHaldur.ehitaKommentaarideHierarhia(post.kommentaarid || []);
                        kommentaarid.forEach(komm => {
                            if (filters.kommentaarid.checked && !komm.vanem_id) {
                                tegevused.push({
                                    aeg: komm.aeg * 1000,
                                    tekst: `<b>Kommentaar</b>: ${komm.tekst.substring(0, 120)}...`,
                                    tüüp: 'kommentaar',
                                    id: komm.id,
                                    puuId: post.puu_id
                                });
                            }
                            const lisaVastused = (kommentaar) => {
                                kommentaar.lapsed.forEach(laps => {
                                    if (filters.vastused.checked) {
                                        tegevused.push({
                                            aeg: laps.aeg * 1000,
                                            tekst: `<b>Vastus</b>: ${laps.tekst.substring(0, 120)}...`,
                                            tüüp: 'vastus',
                                            id: laps.id,
                                            puuId: post.puu_id
                                        });
                                    }
                                    if (laps.lapsed.length > 0) {
                                        lisaVastused(laps);
                                    }
                                });
                            };
                            lisaVastused(komm);
                        });
                    });
                    tegevused.sort((a, b) => b.aeg - a.aeg);
                    tegevused.forEach(tegevus => {
                        const div = document.createElement('div');
                        div.className = 'tegevus';
                        div.innerHTML = `
                            <a href="#postitus-${tegevus.id}" class="ajalugu-link" data-tüüp="${tegevus.tüüp}" data-id="${tegevus.id}" data-puu-id="${tegevus.puuId || ''}">
                                ${tegevus.tekst}</a>
                            <small>${new Date(tegevus.aeg).toLocaleString()}</small>`;
                        konteiner.appendChild(div);
                    });

                    const lingid = konteiner.querySelectorAll('.ajalugu-link');
                    lingid.forEach(link => {
                        link.removeEventListener('click', this.handleLinkClick);
                        link.addEventListener('click', this.handleLinkClick.bind(this));
                    });
                };
                this.handleLinkClick = (e) => {
                    e.preventDefault();
                    const tüüp = e.target.dataset.tüüp;
                    const id = parseInt(e.target.dataset.id);
                    const puuId = e.target.dataset.puuId ? parseInt(e.target.dataset.puuId) : null;
                    console.log('Ajalugu link klikitud:', { tüüp, id, puuId });
                    const tekst = e.target.textContent.split(' - ')[0];
                    JooksevHaldur.lisaLink(tüüp, id, puuId, tekst);
                    this.liiguKirjeni(tüüp, id, puuId);
                };
                await renderAjalugu();
                Object.values(filters).forEach(filter => {
                    filter.addEventListener('change', renderAjalugu);
                });
            }
        };
        const JooksevHaldur = {
            lingid: [],
            async lisaLink(tüüp, id, puuId, tekst) {
                const aeg = new Date().getTime();
                const lühitekst = tekst.length > 20 ? tekst.substring(0, 17) + '...' : tekst;
                this.lingid.unshift({ tüüp, id, puuId, tekst: lühitekst, aeg });
                if (this.lingid.length > 20) this.lingid.pop();
                console.log('Link lisatud:', { tüüp, id, puuId, tekst: lühitekst });
                this.kuvaJooksev();
            },
            async kuvaJooksev() {
                const konteiner = document.getElementById('jooksev-sisu');
                konteiner.innerHTML = '';
                this.lingid.forEach(link => {
                    const div = document.createElement('div');
                    div.className = 'jooksev-kirje';
                    div.innerHTML = `
                        <a href="#" data-tüüp="${link.tüüp}" data-id="${link.id}" data-puu-id="${link.puuId || ''}">
                            ${link.tekst} <small>${new Date(link.aeg).toLocaleTimeString()}</small>
                        </a>`;
                    konteiner.appendChild(div);
                });
                const lingid = konteiner.querySelectorAll('a');
                lingid.forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        const tüüp = link.dataset.tüüp;
                        const id = parseInt(link.dataset.id);
                        const puuId = link.dataset.puuId ? parseInt(link.dataset.puuId) : null;
                        console.log('Jooksev link klikitud:', { tüüp, id, puuId });
                        AjaluguHaldur.liiguKirjeni(tüüp, id, puuId);
                    });
                });
            }
        };
        document.addEventListener('DOMContentLoaded', () => {
            PuuHaldur.laadiPuu();
            AutomaatSoovitus.laadiPealkirjad();
            AjaluguHaldur.kuvaAjalugu();
            let projekti_nimetus            = <?php echo json_encode(htmlspecialchars($projekti_nimetus)); ?>;
            const projekti_nimetuse_element = document.getElementById('searchInput');
            projekti_nimetuse_element.value = projekti_nimetus;
        });
    </script>
</body>
</html>