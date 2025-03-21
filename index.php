<?php
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log',__DIR__ . '/error.log');

function logError($message) {
    file_put_contents(__DIR__ . '/error.log', date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

function getDatabase() {
    $dbPath = __DIR__ . '/ANDMEBAAS_PUU.db';
    if (!is_writable(dirname($dbPath))) {
        logError("Database directory not writable: " . dirname($dbPath));
        die(json_encode(['status' => 'error', 'message' => 'Database directory not writable']));
    }
    $db = new SQLite3($dbPath);

    $result = $db->querySingle('PRAGMA encoding;');
    //logError("Database encoding: " . $result);

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

    // Lisa vaikimisi juur
    $result = $db->querySingle('SELECT COUNT(*) FROM puu');
    if ($result == 0) {
        $db->exec("INSERT INTO puu (nimi, vanem_id, on_oks) VALUES ('Juur', NULL, 1)");
    }
    return $db;
}

// Faili üleslaadimine suhtelise teega
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    header('Content-Type: application/json');
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $file = $_FILES['file'];
    $fileName = time() . '_' . basename($file['name']);
    $targetPath = $uploadDir . $fileName;
    $relativePath = '/uploads/' . $fileName; // Veebitee

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


// Postituse salvestamine
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

    $db = getDatabase();
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

// API toimingud
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $db = getDatabase();

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
            $db = getDatabase();
        
            // Hangi kõik postitused, mis on seotud selle puu sõlmega või selle alamõlmedega
            $postitused = [];
            $result = $db->query('SELECT id, manused FROM postitused WHERE puu_id = ' . (int)$id);
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $postitused[] = $row;
            }
            // Kontrolli alamõlmi rekursiivselt
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
        
            // Kustuta puu sõlm (postitused ja kommentaarid kustuvad CASCADE abil)
            $stmt = $db->prepare('DELETE FROM puu WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
        
            // Kustuta failid ja pildi/faili kommentaarid
            foreach ($postitused as $postitus) {
                $manused = json_decode($postitus['manused'] ?? '[]', true);
                foreach ($manused as $manus) {
                    $stmt = $db->prepare('DELETE FROM pildi_kommentaarid WHERE pilt_path = :pilt_path');
                    $stmt->bindValue(':pilt_path', $manus['path'], SQLITE3_TEXT);
                    $stmt->execute();
        
                    // Kustuta füüsiline fail uploads kaustast
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
            $puu_id = $_GET['puu_id'] ?? null; // Võib olla null
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
            $db = getDatabase();
        
            // Hangi postituse manused enne kustutamist
            $stmt = $db->prepare('SELECT manused FROM postitused WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $manused = $row ? json_decode($row['manused'] ?? '[]', true) : [];
        
            // Kustuta postitus (kommentaarid kustuvad CASCADE abil)
            $stmt = $db->prepare('DELETE FROM postitused WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
        
            // Kustuta pildi/faili kommentaarid, mis on seotud manustega
            foreach ($manused as $manus) {
                $stmt = $db->prepare('DELETE FROM pildi_kommentaarid WHERE pilt_path = :pilt_path');
                $stmt->bindValue(':pilt_path', $manus['path'], SQLITE3_TEXT);
                $stmt->execute();
        
                // Kustuta füüsiline fail uploads kaustast
                $fullPath = __DIR__ . $manus['path']; // Näiteks /uploads/time_filename
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
            $db = getDatabase();
        
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
        
            // Otsi puust
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
        
            // Otsi postitustest
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
        
            // Otsi kommentaaridest
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
        
            // Otsi pildi/faili kommentaaridest
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
        case 'get_history':
            $limit = $_GET['limit'] ?? 10;
            $history = [];
            
            $result = $db->query("SELECT id, puu_id, tekst, aeg, manused, 'postitus' as type FROM postitused ORDER BY aeg DESC LIMIT $limit");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $history[] = $row;
            }
            
            // Lisa puu_id kommentaaridele, mis viitab postituse puu_id-le
            $result = $db->query("SELECT k.id, p.puu_id, k.tekst, k.aeg, 'kommentaar' as type 
                                    FROM kommentaarid k 
                                    JOIN postitused p ON k.postitus_id = p.id 
                                    ORDER BY k.aeg DESC LIMIT $limit");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $history[] = $row;
            }
            
            $result = $db->query("SELECT id, pilt_path as puu_id, tekst, aeg, 'fail' as type 
                                    FROM pildi_kommentaarid ORDER BY aeg DESC LIMIT $limit");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $history[] = $row;
            }
            
            usort($history, function($a, $b) { return $b['aeg'] - $a['aeg']; });
            $history = array_slice($history, 0, $limit);
            
            echo json_encode($history ?: []);
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
            $db = getDatabase();
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
            $db = getDatabase();
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
            $db = getDatabase();
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
    <title>Dokumentatsioon 2025</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { margin: 0; font-family: Arial, sans-serif; height: 100vh; display: flex; flex-direction: column; background: #f0f0f0; }
        header { background: #1a1a1a; color: white; padding: 10px; position: sticky; top: 0; z-index: 10; }
        #otsing { width: 100%; padding: 8px; font-size: 16px; border: none; border-radius: 4px; }
        #otsingu-tulemused { position: absolute; background: white; color: black; max-height: 200px; overflow-y: auto; width: 50%; border: 1px solid #ccc; z-index: 20; }
        .otsingu-tulemus {padding: 5px;cursor: pointer;white-space: nowrap;overflow: hidden;text-overflow: ellipsis;}
        .otsingu-tulemus:hover, .otsingu-tulemus.active { background: #ddd; }
        .konteiner { display: flex; flex: 1; overflow: hidden; }
        #puu-konteiner { width: 300px; background: #f4f4f4; overflow-y: auto; padding: 10px; position: relative; }
        .puu-sõlm { padding: 5px; cursor: pointer; }
        .puu-sõlm:hover { background: #ddd; }
        .puu-sõlm.laiendatud > .alamlehed { display: block; }
        .puu-sõlm .alamlehed { display: none; }
        #peasisu { flex: 1; padding: 20px; overflow-y: auto; background: white; }
        .postituse-redaktor { margin-bottom: 20px; }
        .postituse-redaktor textarea {width: 100%;height: 150px;resize: vertical;padding: 10px;border: 1px solid #ccc;border-radius: 4px;font-size: 16px;box-sizing: border-box;white-space: pre-wrap;overflow-wrap: break-word;background: #fff;}
        .postituse-redaktor textarea:focus {outline: none;border-color: #f0f0f0;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        .postituse-redaktor button {padding: 8px 16px;background:transparent;color: #9d9d9d;border: none;border-radius: 4px;cursor: pointer;}
        .postituse-redaktor button:hover {background: #6cd7a5;}
        .postitus{box-shadow: 0 4px 28px 0 rgb(210, 210, 210), 0 6px 20px 0 rgb(255, 255, 255);border-radius: 8px;margin-bottom: 10px;padding: 15px;background: #fff;}
        .postitus-text {white-space: pre-wrap;overflow-wrap: break-word;margin: 0 0 10px 0;}
        .postitus-timestamp {color: #666;font-size: 12px;}
        .postitus button {padding: 5px 10px;background:transparent;color: #9d9d9d;border: none;border-radius: 4px;cursor: pointer;}
        .postitus button:hover {background: #6cd7a5;}
        .galerii { display: list-item; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 10px; }
        .galerii-üksus img { width: 50%;height: auto; border-radius: 4px; cursor: pointer; }
        .kommentaari-paan { background: #f0f0f0; padding: 5px; margin-top: 5px; border-radius: 4px; }
        .pesastatud-kommentaarid { margin-left: 20px; }
        footer { background: #1a1a1a; color: white; padding: 10px; position: sticky; bottom: 0; }
        .kontekstimenüü { position: absolute; background: white; border: 1px solid #ccc; z-index: 1000; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .kontekstimenüü ul { list-style: none; padding: 0; margin: 0; }
        .kontekstimenüü li { padding: 8px 12px; cursor: pointer; }
        .kontekstimenüü li:hover { background: #e0e0e0; }
        .postituse-failid { margin-top: 10px; }
        .postituse-failid a { display: block; margin: 5px 0; color: #6cd7a5; text-decoration: none; }
        .postituse-kommentaarid { margin-top: 15px; }
        .pildi-kommentaar {overflow: hidden;margin: 5px 0;padding: 5px;border-radius: 4px;}
        .vasta-redaktor {margin-left: 20px;margin-top: 5px;}
        .vasta-redaktor textarea {width: 94%;height: 60px;margin-bottom: 5px;}
        .kommentaar { border-top: 1px solid #ddd; position: relative; }
        .kommentaar small { color: #666; }
        .kommentaar button { margin-left: 10px; }
        .kommentaari-textarea:focus {outline: none;border-color: #f0f0f0;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        .kommentaari-textarea{width: 100%;padding-bottom:10px;margin-bottom: 10px;height:50px;resize:vertical;border:1px solid #ccc;border-radius: 4px;font-size: 16px;box-sizing: border-box;white-space: pre-wrap;overflow-wrap: break-word;background: #fff;}
        .kommentaari-editor-container {height: 100px;border: 1px solid #ccc;border-radius: 4px;width: 100%;box-sizing: border-box;}
        .kommentaaride-lapsed{margin-left: 41px;}
        #pildi-kommentaar-tekst{width: 100%;height: 10%;}
        .modal-kommentaarid .kommentaar {display: flex;align-items: flex-start;margin: 5px 0;}
        .modal-kommentaarid .kommentaar i {width: 50px;height: 50px;margin-right: 10px;font-size: 30px;line-height: 50px;color: #666;}
        .modal-kommentaarid .kommentaar div {flex: 1;}
        button { padding: 5px 10px; margin: 0 5px; cursor: pointer; background: #fff; color: #9d9d9d; border: none; border-radius: 4px; }
        button:hover { background: #6cd7a5; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 100; }
        .modal.active { display: flex; justify-content: center; align-items: center; }
        .modal-content { max-width: 90%; max-height: 90%; background: white; border-radius: 5px; display: flex;padding: 10px; }
        .modal-image { max-width: 70%; max-height: 100%; object-fit: fill; border-radius: 5px 0 0 5px; }
        .modal-kommentaarid { width: 300px; padding: 20px; overflow-y: auto; }
        .ajalugu-kirje {padding: 8px;border-bottom: 1px solid #ddd;cursor: pointer;}
        .ajalugu-kirje:hover {background: #f0f0f0;}
        .ajalugu-kirje a {text-decoration: none;color: #333;display: block;}
        .soovitus-list {background: white;border: 1px solid #ccc;max-height: 200px;overflow-y: auto;z-index: 100;}
        .soovitus-item {padding: 5px;cursor: pointer;}
        .soovitus-item:hover, .soovitus-item.aktiivne {background: #ddd;}
        button{padding: 0;margin: 0;cursor: pointer;background: #1a73e8;color: white;border: none;border-radius: 4px;}
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
        #pildi-kommentaar-tekst, #faili-kommentaar-tekst {resize: vertical;padding: 10px;border: 1px solid #ccc;border-radius: 4px;font-size: 16px;box-sizing: border-box;white-space: pre-wrap;overflow-wrap: break-word;background: #fff;margin-bottom: 5px;}
        #pildi-kommentaar-tekst:focus, #faili-kommentaar-tekst:focus {outline: none;border-color: #1a73e8;box-shadow: 0 0 5px rgba(26, 115, 232, 0.3);}
        .file-upload-wrapper {display: inline-block;position: relative;}
        .file-upload-btn {padding: 6px 8px;background: #1a73e8;color: white;border: none;border-radius: 4px;cursor: pointer;font-size: 14px;}
        .file-upload-btn:hover {background: #1557b0;}
        .hidden { display: none; }
        #ajalugu-vaade { padding: 20px; }
        .ajalugu-filtrid { margin-bottom: 15px; }
        .ajalugu-filtrid label { margin-right: 15px; font-size: 14px; }
        .ajalugu-sisu .tegevus {padding: 10px;margin-bottom: 5px;background: #f9f9f9;border-radius: 4px;}
        .ajalugu-sisu .tegevus a { color: #1a73e8; text-decoration: none; }
        .ajalugu-sisu .tegevus a:hover { text-decoration: underline; }
        #ajalugu-sisu {max-height: 80vh;overflow-y: auto;}
        .highlight {background: #8cff015c;transition: background 0.5s;}
        .ajalugu-link{ color: #424242; text-decoration: none;}
        .otsingu-link{ color: #424242; text-decoration: none;}
        .must_tekst{color: #424242; }
</style>
</head>
<body>
    <header>
        <input type="text" id="otsing" placeholder="Otsi oksi, lehti, postitusi...">
        <div id="otsingu-tulemused" class="otsingu-tulemused"></div>
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
                <label><input type="checkbox" id="filter-vastused" checked> Kommentaaride vastused</label>
                <label><input type="checkbox" id="filter-postitused" checked> Postitused</label>
            </div>
            <div id="ajalugu-sisu"></div>
        </div>

    </div>
    <footer>
        <button onclick="PuuHaldur.LISA_UUS_OKS()">Lisa oks</button>
        <button onclick="PuuHaldur.lisaLeht()">Lisa leht</button>
        <button onclick="VikiHaldur.lisaVikiLeht()">Lisa viki leht</button>
        <button onclick="VikiHaldur.lisaSektsioon()">Lisa sektsioon</button>
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
         const BASE_URL = '<?php echo rtrim(dirname($_SERVER['PHP_SELF']), '/'); ?>';

        // Andmestruktuur
        const Andmed = {
            puu: [],
            valitudSõlm: null,
            laienenudSõlmed: new Set() // Jälgime laienenud sõlmi
        };

        // Üldine API kutse funktsioon
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

        // Puu haldamise moodul
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
                this.kuvaPostitamisLeht();
                this.kuvaPuu(); // Värskenda puu, säilitades oleku
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
                    div.innerHTML = `<i class="fas ${sõlm.on_oks ? 'fa-folder' : 'fa-file'}"></i> ${sõlm.nimi}`;
                    div.onclick = (e) => this.laiendaJaVali(e, sõlm, div);
                    div.oncontextmenu = (e) => this.näitaKontekstimenüüd(e, sõlm);

                    const alamlehed = document.createElement('div');
                    alamlehed.className = 'alamlehed';
                    div.appendChild(alamlehed);

                    vanem.appendChild(div);
                    if (sõlm.lapsed && sõlm.lapsed.length > 0) {
                        // Säilita laienemise olek
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
                    await this.laadiPuu(); // Laadi puu uuesti
                    this.kuvaPuu(); // Värskenda kuvamist
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

            async nimetaÜmber() {
                if (!Andmed.valitudSõlm) return alert('Vali esmalt sõlm!');
                const tyyp = Andmed.valitudSõlm.on_oks ? 'oks' : 'leht';
                const uusNimi = prompt(`Sisesta uus nimi ${tyyp} "${Andmed.valitudSõlm.nimi}" jaoks:`, Andmed.valitudSõlm.nimi);
                if (!uusNimi || uusNimi === Andmed.valitudSõlm.nimi) return;
                try {
                    const vastus = await apiKutse('update_puu', { id: Andmed.valitudSõlm.id, nimi: uusNimi });
                    if (vastus.status === 'updated') {
                        Andmed.valitudSõlm.nimi = uusNimi; // Uuenda kohalikku andmestruktuuri
                        await this.laadiPuu(); // Laadi puu uuesti
                        this.kuvaPostitamisLeht(); // Värskenda sisu
                    } else {
                        alert('Ümbernimetamine ebaõnnestus!');
                    }
                } catch (e) {
                    console.error('Ümbernimetamise viga:', e);
                    alert('Viga serveriga suhtlemisel!');
                }
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
                console.log('Kuva postitamise leht:', fullPath);

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
                        <button onclick="PostitusteHaldur.lisaPostitus()">Postita</button>
                    </div>
                    <div id="postitused"></div>
                `;
                PostitusteHaldur.lisaSoovitusKuulajad(peasisu);
                PostitusteHaldur.kuvaPostitused();

                const fileBtn = peasisu.querySelector('.file-upload-btn');
                const fileInput = peasisu.querySelector('#faili-sisend');
                fileBtn.addEventListener('click', () => fileInput.click());
            },

            //Sõlme valimine
            valiSõlm(puuId, type, id) {
                const sõlm = this.findNode(Andmed.puu, puuId);
                if (sõlm) {
                    Andmed.valitudSõlm = sõlm;
                    this.kuvaPostitamisLeht();
                    document.getElementById('otsingu-tulemused').innerHTML = '';
                    // Kui on kommentaar või pildi_kommentaar, liigu konkreetse kirje juurde
                    if (type === 'kommentaar' || type === 'pildi_kommentaar') {
                        setTimeout(() => {
                            const element = document.getElementById(`${type === 'kommentaar' ? 'kommentaar' : 'fail'}-${id}`);
                            if (element) {
                                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }, 500); // Ootame, kuni postitused on laetud
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

        // Vikilehtede haldur
        const VikiHaldur = {
            async lisaVikiLeht() {
                if (!Andmed.valitudSõlm) return alert('Vali esmalt sõlm!');
                const nimi = prompt('Sisesta viki lehe nimi:');
                if (!nimi) return;
                await apiKutse('add_puu', { nimi, vanem_id: Andmed.valitudSõlm.id, on_oks: 0 });
                PuuHaldur.laadiPuu();
            },

            async lisaSektsioon() {
                if (!Andmed.valitudSõlm) return alert('Vali esmalt sõlm!');
                const nimi = prompt('Sisesta sektsiooni nimi:');
                if (!nimi) return;
                await apiKutse('add_puu', { nimi, vanem_id: Andmed.valitudSõlm.id, on_oks: 1 });
                PuuHaldur.laadiPuu();
            }
        };

        // Automaatsoovituse moodul
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

            kuvaSoovitused(tekstVali, konteiner) {
                const sisend = tekstVali.value;
                const viimaneMark = sisend.lastIndexOf('[');
                if (viimaneMark === -1 || sisend.length <= viimaneMark + 1) {
                    this.peidaSoovitused(konteiner);
                    return;
                }

                const otsitav = sisend.substring(viimaneMark + 1).toLowerCase();
                this.viimaneSisend = sisend.substring(0, viimaneMark + 1);
                const soovitused = this.postTitles.filter(t => t.rada.toLowerCase().includes(otsitav));

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
                    item.textContent = t.rada;
                    item.dataset.id = t.id;
                    item.dataset.puuId = t.puu_id;
                    item.onclick = () => this.valiSoovitus(t.id, t.tekst, t.puu_id, tekstVali);
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
                const tekst = valitud.textContent.split('/').pop();
                this.valiSoovitus(id, tekst, puuId, tekstVali);
            }
        };

        // Postituste haldamise moodul
        const PostitusteHaldur = {
            async lisaPostitus() {
                if (!Andmed.valitudSõlm) return alert('Vali esmalt sõlm!');
                const tekst = document.querySelector('.postituse-redaktor textarea').value;
                if (!tekst) return alert('Sisesta postituse tekst!');

                const failid = document.getElementById('faili-sisend').files;
                const manused = [];
                for (const fail of failid) {
                    const formData = new FormData();
                    formData.append('file', fail);
                    const vastus = await fetch(window.location.href, { method: 'POST', body: formData });
                    const data = await vastus.json();
                    if (data.status === 'uploaded') {
                        manused.push({ path: data.path, type: data.type });
                    }
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
                    document.getElementById('faili-sisend').value = '';
                    this.kuvaPostitused();
                    AutomaatSoovitus.laadiPealkirjad();
                } else {
                    alert('Postituse lisamine ebaõnnestus!');
                }
            },

            async avaFail(path, fileName) {
                const modal = document.getElementById('faili-modal');
                const link = document.getElementById('modal-fail-link');
                const postTekstDiv = document.getElementById('modal-fail-post-tekst');
                link.href = path;
                link.innerHTML = `<b class="must_tekst">Vaata või lae alla:</b> ${fileName}`;
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
                    postTekstDiv.innerHTML = `${tekst} <small>${new Date(postitus.aeg * 1000).toLocaleString()}</small>`;
                } else {
                    postTekstDiv.innerHTML = '<p>Postitust ei leitud.</p>';
                }

                this.kuvaFailiKommentaarid(relativePath);
                document.getElementById('faili-kommentaar-nupp').onclick = () => this.lisaFailiKommentaar(relativePath);
                modal.onclick = (e) => {
                    if (e.target === modal) modal.classList.remove('active');
                };
                this.lisaSoovitusKuulajad(modal); // Add listeners to modal textareas
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
                const origTekst = p.childNodes[0].textContent.trim(); // Get only the text node before <small>
                const origAeg = p.querySelector('small').textContent;
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
                    p.innerHTML = `${origTekst} <small>${origAeg}</small>
                        <button onclick="PostitusteHaldur.toggleMuudaFailiKommentaar(${id}, '${path}')">Muuda</button>
                        <button onclick="PostitusteHaldur.kustutaFailiKommentaar(${id}, '${path}')">Kustuta</button>`;
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

                    let tekst = postitus.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                        (match, p1, p2) => {
                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                            const puuId = targetPost ? targetPost.puu_id : null;
                            return `<a href="#postitus-${p2}" class="postitus-link" data-id="${p2}" data-puu-id="${puuId || ''}">${p1}</a>`;
                        });

                    const manused = JSON.parse(postitus.manused || '[]');
                    let failideHtml = '<div class="postituse-failid">';
                    const galerii = document.createElement('div');
                    galerii.className = 'galerii';

                    for (const manus of manused) {
                        const fullPath = `${BASE_URL}${manus.path}`;
                        const fileName = manus.path.split('/').pop();

                        if (manus.type.startsWith('image/')) {
                            const galeriiÜksus = document.createElement('div');
                            galeriiÜksus.className = 'galerii-üksus';
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
                                                <p>${kommTekst}</p>
                                                <small>${new Date(komm.aeg * 1000).toLocaleString()}</small>
                                            </div>
                                        </div>`;
                                });
                                galerii.appendChild(imgCommDiv);
                            }
                        } else {
                            failideHtml += `<a href="#" onclick="PostitusteHaldur.avaFail('${fullPath}', '${fileName}'); return false;">${fileName}</a>`;
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
                                    <div class="kommentaar faili-kommentaar" data-komm-id="${komm.id}" onclick="PostitusteHaldur.avaFail('${fullPath}', '${fileName}')">
                                        <i class="fas fa-file" style="margin-top: -7px;width: 50px; height: 50px; float: left; margin-right: -17px; font-size: 30px; line-height: 50px;"></i>
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

                    const kommentaarid = this.ehitaKommentaarideHierarhia(postitus.kommentaarid || []);
                    let kommentaarideHtml = '<div class="postituse-kommentaarid">';
                    kommentaarideHtml += this.renderKommentaarid(kommentaarid, postitus.id, kõikPostitused);
                    kommentaarideHtml += '</div>';

                    div.innerHTML = `
                        <div class="postitus-text">${tekst}</div>
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

                    const editBtn = div.querySelector('.edit-btn');
                    editBtn.addEventListener('click', () => this.toggleMuudaPostitus(postitus.id, tekst));
                }

                const lingid = konteiner.querySelectorAll('.postitus-link');
                lingid.forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        const id = parseInt(link.dataset.id);
                        const puuId = link.dataset.puuId ? parseInt(link.dataset.puuId) : null;
                        console.log('Postituse link klikitud:', { id, puuId });
                        AjaluguHaldur.liiguKirjeni('postitus', id, puuId);
                    });
                });

                this.lisaSoovitusKuulajad(konteiner);
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

            renderKommentaarid_vastuste_nuputa(kommentaarid, postitusId, kõikPostitused) {
                let html = '';
                kommentaarid.forEach(komm => {
                    let kommTekst = komm.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                        (match, p1, p2) => {
                            const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                            const puuId = targetPost ? targetPost.puu_id : null;
                            return `<a href="#postitus-${p2}" class="postitus-link" data-id="${p2}" data-puu-id="${puuId || ''}">${p1}</a>`;
                        });
                    html += `
                        <div class="kommentaar" data-komm-id="${komm.id}">
                            <p>${kommTekst}</p>
                            <small>${new Date(komm.aeg * 1000).toLocaleString()}</small>
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
                            <p>${kommTekst}</p>
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
                    postTekstDiv.innerHTML = `${tekst} <small>${new Date(postitus.aeg * 1000).toLocaleString()}</small>`;
                } else {
                    postTekstDiv.innerHTML = '<p>Postitust ei leitud.</p>';
                }

                this.kuvaPildiKommentaarid(relativePath);
                document.getElementById('pildi-kommentaar-nupp').onclick = () => this.lisaPildiKommentaar(relativePath);
                modal.onclick = (e) => {
                    if (e.target === modal) modal.classList.remove('active');
                };
                this.lisaSoovitusKuulajad(modal); // Add listeners to modal textareas
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
                        </div>
                    `;
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

                if (textDiv.querySelector('textarea')) return; // Kui juba muudetakse, ära tee midagi

                const origTekst = tekst; // Algne tekst on juba korrektselt edastatud
                const origTimestamp = timestampDiv.textContent;

                // Eemalda olemasolev "Muuda" nupp, et vältida dubleerimist
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
            },

            async toggleMuudaKommentaar(id) {
                const kommDiv = document.getElementById(`kommentaar-${id}`);
                const p = kommDiv.querySelector('p');
                const origTekst = p.childNodes[0].textContent.trim(); // Original text
                const origAeg = p.querySelector('small').textContent; // Original timestamp
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
                cancelBtn.onclick = () => {
                    p.innerHTML = `${origTekst} <small>${origAeg}</small>
                        <button onclick="PostitusteHaldur.toggleMuudaKommentaar(${id})">Muuda</button>
                        <button onclick="PostitusteHaldur.toggleVasta(${id}, ${kommDiv.closest('.postitus').id.split('-')[1]})">Vasta</button>
                        <button onclick="PostitusteHaldur.kustutaKommentaar(${id})">Kustuta</button>`;
                };
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
                const origTekst = p.childNodes[0].textContent.trim(); // Get only the text node before <small>
                const origAeg = p.querySelector('small').textContent;
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
                    p.innerHTML = `${origTekst} <small>${origAeg}</small>
                        <button onclick="PostitusteHaldur.toggleMuudaPildiKommentaar(${id}, '${path}')">Muuda</button>
                        <button onclick="PostitusteHaldur.kustutaPildiKommentaar(${id}, '${path}')">Kustuta</button>`;
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
                            // Allow Enter to create a new line if no suggestion is active
                        } else if (e.key === 'Escape') {
                            AutomaatSoovitus.peidaSoovitused(konteiner);
                        }
                    });
                });
            }
        };

        // Failide sisendi kuulamine
        document.addEventListener('DOMContentLoaded', () => {
            const fileBtn = document.querySelector('.file-upload-btn');
            const fileInput = document.getElementById('faili-sisend');
            fileBtn.addEventListener('click', () => fileInput.click());
        });

        // Otsingu haldamine
        document.getElementById('otsing').addEventListener('input', async (e) => {
            const query = e.target.value;
            const tulemused = document.getElementById('otsingu-tulemused');
            if (!query) {
                tulemused.innerHTML = '';
                return;
            }
            const data = await apiKutse('search', { query: encodeURIComponent(query) });
            tulemused.innerHTML = data.map(t => {
                let kuvatavTekst = '';
                switch (t.type) {
                    case 'puu':
                        kuvatavTekst = `${t.tee} (${t.nimi})`;
                        break;
                    case 'postitus':
                        kuvatavTekst = `${t.tee}->Postituse tekst->${t.tekst}`;
                        break;
                    case 'pilt':
                        kuvatavTekst = `${t.tee}->Postituse pilt->${t.tekst}`;
                        break;
                    case 'fail':
                        kuvatavTekst = `${t.tee}->Postituse fail->${t.tekst}`;
                        break;
                    case 'kommentaar':
                        kuvatavTekst = `${t.tee}->Postituse kommentaar->${t.tekst}`;
                        break;
                    case 'pildi_kommentaar':
                        kuvatavTekst = `${t.tee}->Postituse faili/pildi kommentaar->${t.tekst}`;
                        break;
                }
                return `
                    <div class="otsingu-tulemus" data-type="${t.type}" data-id="${t.id}" data-puu-id="${t.puu_id}" onclick="PuuHaldur.valiSõlm(${t.puu_id}, '${t.type}', ${t.id})">
                        ${kuvatavTekst}
                    </div>
                `;
            }).join('');
        });

        //Klahvi navigatsioon
        document.addEventListener('keydown', (e) => {
            const tulemused = document.querySelectorAll('.otsingu-tulemus');
            if (!tulemused.length) return;
            let aktiivne = document.querySelector('.otsingu-tulemus.active');
            let indeks = aktiivne ? Array.from(tulemused).indexOf(aktiivne) : -1;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                indeks = (indeks + 1) % tulemused.length;
                if (aktiivne) aktiivne.classList.remove('active');
                tulemused[indeks].classList.add('active');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                indeks = (indeks - 1 + tulemused.length) % tulemused.length;
                if (aktiivne) aktiivne.classList.remove('active');
                tulemused[indeks].classList.add('active');
            } else if (e.key === 'Enter' && indeks >= 0) {
                e.preventDefault();
                const valitud = tulemused[indeks];
                PuuHaldur.valiSõlm(parseInt(valitud.dataset.puuId), valitud.dataset.type, parseInt(valitud.dataset.id));
            } else if (e.key === 'Escape') {
                document.getElementById('otsingu-tulemused').innerHTML = '';
            }
        });

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
                            ${tulemus.tekst}
                        </a>
                    `;
                    konteiner.appendChild(div);
                });

                // Lisa klikisündmused ja klaviatuurinavigatsioon
                const lingid = konteiner.querySelectorAll('.otsingu-link');
                lingid.forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        const tüüp = link.dataset.tüüp;
                        const id = parseInt(link.dataset.id);
                        const puuId = link.dataset.puuId ? parseInt(link.dataset.puuId) : null;
                        console.log('Otsingu link klikitud:', { tüüp, id, puuId });
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
                        document.removeEventListener('keydown', handler); // Eemalda kuulaja, kui tulemused kaovad
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

        // Käivita OtsinguHaldur
        OtsinguHaldur.init();

        // Ajalugu haldamine
        const AjaluguHaldur = {
            async liiguKirjeni(tüüp, id, puuId) {
                console.log('liiguKirjeni kutsutud:', { tüüp, id, puuId });

                // Vali puu sõlm ja kuva postitamise leht, kui puuId on olemas
                if (puuId) {
                    const sõlm = PuuHaldur.findNode(Andmed.puu, puuId);
                    if (sõlm) {
                        Andmed.valitudSõlm = sõlm;
                        PuuHaldur.kuvaPostitamisLeht();
                        await PostitusteHaldur.kuvaPostitused(); // Oota, kuni postitused on renderdatud
                    } else {
                        console.error('Sõlme ei leitud ID-ga:', puuId);
                        return;
                    }
                } else {
                    console.warn('puuId puudub');
                }

                // Oota veidi, et DOM uuenduks
                await new Promise(resolve => setTimeout(resolve, 100));

                // Keri vastava elemendi juurde ja ava modaal vajadusel
                let targetElement;
                switch (tüüp) {
                    case 'postitus':
                        targetElement = document.getElementById(`postitus-${id}`);
                        break;
                    case 'kommentaar':
                    case 'vastus':
                        targetElement = document.querySelector(`.kommentaar[data-komm-id="${id}"]`);
                        if (!targetElement) {
                            // Kui kommentaari pole DOM-is, keri postituse juurde
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

                    postitused.forEach(post => {
                        if (filters.postitused.checked) {
                            tegevused.push({
                                aeg: post.aeg * 1000,
                                tekst: `Postitus: ${post.tekst.substring(0, 50)}...`,
                                tüüp: 'postitus',
                                id: post.id,
                                puuId: post.puu_id
                            });
                        }

                        const manused = JSON.parse(post.manused || '[]');
                        manused.forEach(manus => {
                            if (manus.type.startsWith('image/') && filters.pildid.checked) {
                                tegevused.push({
                                    aeg: post.aeg * 1000,
                                    tekst: `Pilt lisatud: ${manus.path.split('/').pop()}`,
                                    tüüp: 'pilt',
                                    id: post.id,
                                    puuId: post.puu_id
                                });
                            } else if (filters.failid.checked) {
                                tegevused.push({
                                    aeg: post.aeg * 1000,
                                    tekst: `Fail lisatud: ${manus.path.split('/').pop()}`,
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
                                    tekst: `Kommentaar: ${komm.tekst.substring(0, 50)}...`,
                                    tüüp: 'kommentaar',
                                    id: komm.id, // Kasuta kommentaari ID-d
                                    puuId: post.puu_id
                                });
                            }
                            const lisaVastused = (kommentaar) => {
                                kommentaar.lapsed.forEach(laps => {
                                    if (filters.vastused.checked) {
                                        tegevused.push({
                                            aeg: laps.aeg * 1000,
                                            tekst: `Vastus: ${laps.tekst.substring(0, 50)}...`,
                                            tüüp: 'vastus',
                                            id: laps.id, // Kasuta vastuse ID-d
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
                                ${tegevus.tekst}
                            </a>
                            <small>${new Date(tegevus.aeg).toLocaleString()}</small>
                        `;
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
                    this.liiguKirjeni(tüüp, id, puuId);
                };

                await renderAjalugu();
                Object.values(filters).forEach(filter => {
                    filter.addEventListener('change', renderAjalugu);
                });
            }


        };

        // Alglaadimine
        document.addEventListener('DOMContentLoaded', () => {
            PuuHaldur.laadiPuu();
            AutomaatSoovitus.laadiPealkirjad();
            AjaluguHaldur.kuvaAjalugu();
            OtsinguHaldur.init();
        });
    </script>

</body>
</html>