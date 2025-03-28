<?php
/*
    Arvutist saadetud ekraani pildile postituse tegemine F10 andmebaasi, oksale FAILID.
*/
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_error.log');
function logMessage($message) {
    file_put_contents(__DIR__ . '/api_error.log', date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}
logMessage("API request started");
$baseDir = '/var/www/html';
$projekti_nimetus = 'ANDMEBAAS';
$uploadDir = $baseDir . '/uploads';
$dbPath = $baseDir . '/ANDMEBAAS_PUU.db';
if (isset($_POST['project'])) {
    $projekti_nimetus = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $_POST['project']));
    $projectDir = $baseDir . '/marcmic_2/portiaz_5';
    $uploadDir = $projectDir . '/' . $projekti_nimetus . '/uploads';
    $dbPath = $projectDir . '/' . $projekti_nimetus . '/' . $projekti_nimetus . '.db';
    if (!is_dir($projectDir)) mkdir($projectDir, 0755, true);
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
}
function getDatabase($dbPath) {
    $db = new SQLite3($dbPath);
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
    if ($db->querySingle('SELECT COUNT(*) FROM puu') == 0) {
        $db->exec("INSERT INTO puu (nimi, vanem_id, on_oks) VALUES ('Juur', NULL, 1)");
    }
    return $db;
}
function getOrCreateFailidOks($db) {
    $stmt = $db->prepare('SELECT id, on_oks FROM puu WHERE nimi = :nimi AND (vanem_id IS NULL OR vanem_id = "")');
    $stmt->bindValue(':nimi', 'FAILID', SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        if ($row['on_oks'] != 1) {
            $stmt = $db->prepare('UPDATE puu SET on_oks = 1 WHERE id = :id');
            $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $stmt->execute();
        }
        return $row['id'];
    } else {
        $stmt = $db->prepare('INSERT INTO puu (nimi, vanem_id, on_oks) VALUES (:nimi, NULL, :on_oks)');
        $stmt->bindValue(':nimi', 'FAILID', SQLITE3_TEXT);
        $stmt->bindValue(':on_oks', 1, SQLITE3_INTEGER);
        $stmt->execute();
        return $db->lastInsertRowID();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fileName = $_POST['file_name'] ?? '';
    $filePath = $_POST['file_path'] ?? '';
    $tekst = $_POST['tekst'] ?? "Pilt: $fileName";
    if (empty($fileName) || empty($filePath)) {
        logMessage("Missing file_name or file_path");
        echo json_encode(['status' => 'error', 'message' => 'File name or path missing']);
        exit;
    }
    $fullPath = $baseDir . '/marcmic_2/portiaz_5' . $filePath;
    if (!file_exists($fullPath)) {
        logMessage("File not found at: $fullPath");
        echo json_encode(['status' => 'error', 'message' => 'File not found on server']);
        exit;
    }
    $db = getDatabase($dbPath);
    $puu_id = getOrCreateFailidOks($db);
    $manused = json_encode([['path' => "/uploads/" . $fileName, 'type' => mime_content_type($fullPath)]]);
    $stmt = $db->prepare('INSERT INTO postitused (puu_id, tekst, aeg, manused) VALUES (:puu_id, :tekst, :aeg, :manused)');
    $stmt->bindValue(':puu_id', $puu_id, SQLITE3_INTEGER);
    $stmt->bindValue(':tekst', $tekst, SQLITE3_TEXT);
    $stmt->bindValue(':aeg', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':manused', $manused, SQLITE3_TEXT);
    $stmt->execute();
    $id = $db->lastInsertRowID();
    $stmt = $db->prepare('SELECT on_oks FROM puu WHERE id = :id');
    $stmt->bindValue(':id', $puu_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    logMessage("Verified 'FAILID' on_oks after post: " . $row['on_oks']);
    echo json_encode(['status' => 'success', 'post_id' => $id]);
    exit;
}
echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
?>