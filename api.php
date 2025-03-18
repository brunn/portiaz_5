<?php
header('Content-Type: application/json');

$db = new SQLite3(__DIR__ . '/ANDMEBAAS_PUU.db');
$db->exec('CREATE TABLE IF NOT EXISTS puu (id INTEGER PRIMARY KEY, nimi TEXT, vanem_id INTEGER, on_oks INTEGER)');
$db->exec('CREATE TABLE IF NOT EXISTS postitused (id INTEGER PRIMARY KEY, puu_id INTEGER, tekst TEXT, aeg TEXT)');
$db->exec('CREATE TABLE IF NOT EXISTS kommentaarid (id INTEGER PRIMARY KEY, postitus_id INTEGER, vanem_id INTEGER, tekst TEXT, aeg TEXT)');
$db->exec('CREATE TABLE IF NOT EXISTS failid (id INTEGER PRIMARY KEY, postitus_id INTEGER, kommentaar_id INTEGER, nimi TEXT, andmed BLOB)');
$result = $db->querySingle('SELECT COUNT(*) FROM puu');

if ($result == 0) {
    $db->exec("INSERT INTO puu (nimi, vanem_id, on_oks) VALUES ('Juur', NULL, 1)");
    $juur_id = $db->lastInsertRowID();
    $db->exec("INSERT INTO puu (nimi, vanem_id, on_oks) VALUES ('Oks', $juur_id, 1)");
    $db->exec("INSERT INTO puu (nimi, vanem_id, on_oks) VALUES ('Leht', $juur_id, 0)");
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_puu':
        $result = $db->query('SELECT * FROM puu');
        $puu = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $puu[] = $row;
        }
        echo json_encode($puu);
        break;

    case 'add_node':
        $nimi = $_POST['nimi'];
        $vanem_id = $_POST['vanem_id'] ?: null;
        $on_oks = $_POST['on_oks'];
        $stmt = $db->prepare('INSERT INTO puu (nimi, vanem_id, on_oks) VALUES (:nimi, :vanem_id, :on_oks)');
        $stmt->bindValue(':nimi', $nimi, SQLITE3_TEXT);
        $stmt->bindValue(':vanem_id', $vanem_id, SQLITE3_INTEGER);
        $stmt->bindValue(':on_oks', $on_oks, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['id' => $db->lastInsertRowID()]);
        break;

    case 'rename_node':
        $id = $_POST['id'];
        $nimi = $_POST['nimi'];
        $stmt = $db->prepare('UPDATE puu SET nimi = :nimi WHERE id = :id');
        $stmt->bindValue(':nimi', $nimi, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['success' => true]);
        break;

    case 'delete_node':
        $id = $_POST['id'];
        $stmt = $db->prepare('DELETE FROM puu WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        $db->exec("DELETE FROM puu WHERE vanem_id = $id"); // Kustutab ka lapsed
        echo json_encode(['success' => true]);
        break;

    case 'get_history':
        $limit = $_GET['limit'] ?? 10;
        $history = [];
        
        $result = $db->query("SELECT id, puu_id, tekst, aeg, manused, 'postitus' as type FROM postitused ORDER BY aeg DESC LIMIT $limit");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $history[] = $row;
        }
        
        $result = $db->query("SELECT k.id, k.postitus_id as puu_id, k.tekst, k.aeg, 'kommentaar' as type 
                                FROM kommentaarid k ORDER BY k.aeg DESC LIMIT $limit");
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

    default:
        echo json_encode(['error' => 'Tundmatu tegevus']);
}
$db->close();
?>