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
    $db->exec('CREATE TABLE IF NOT EXISTS puu (f
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

    // Lisa vaikimisi juur, kui puu on tühi
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
            $stmt = $db->prepare('DELETE FROM puu WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
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
            $stmt = $db->prepare('DELETE FROM postitused WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
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
            $query = strtolower($_GET['query'] ?? '');
            $data = [];
            $uniqueIds = [];
        

            // Otsi puust
            $result = $db->query('SELECT * FROM puu');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (stripos($row['nimi'], $query) !== false && !in_array($row['id'], $uniqueIds)) {
                    $data[] = ['type' => 'puu', 'id' => $row['id'], 'nimi' => $row['nimi'], 'puu_id' => $row['id']];
                    $uniqueIds[] = $row['id'];
                }
            }
        
            // Otsi postitustest
            $result = $db->query('SELECT * FROM postitused');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (stripos($row['tekst'], $query) !== false && !in_array($row['id'], $uniqueIds)) {
                    $data[] = ['type' => 'postitus', 'id' => $row['id'], 'tekst' => $row['tekst'], 'puu_id' => $row['puu_id']];
                    $uniqueIds[] = $row['id'];
                }
                $manused = json_decode($row['manused'] ?? '[]', true);
                foreach ($manused as $manus) {
                    if (stripos($manus['path'], $query) !== false && !in_array($row['id'], $uniqueIds)) {
                        $data[] = ['type' => 'postitus', 'id' => $row['id'], 'tekst' => $row['tekst'], 'puu_id' => $row['puu_id']];
                        $uniqueIds[] = $row['id'];
                    }
                }
            }
        
            // Otsi kommentaaridest
            $result = $db->query('SELECT k.*, p.puu_id FROM kommentaarid k JOIN postitused p ON k.postitus_id = p.id');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (stripos($row['tekst'], $query) !== false && !in_array($row['postitus_id'], $uniqueIds)) {
                    $data[] = ['type' => 'kommentaar', 'id' => $row['id'], 'tekst' => $row['tekst'], 'puu_id' => $row['puu_id']];
                    $uniqueIds[] = $row['postitus_id'];
                }
            }
        
            // Otsi pildi kommentaaridest
            $result = $db->query('SELECT * FROM pildi_kommentaarid');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (stripos($row['tekst'], $query) !== false) {
                    $post_stmt = $db->prepare('SELECT * FROM postitused WHERE manused LIKE :path');
                    $post_stmt->bindValue(':path', "%{$row['pilt_path']}%", SQLITE3_TEXT);
                    $post_result = $post_stmt->execute();
                    if ($post_row = $post_result->fetchArray(SQLITE3_ASSOC)) {
                        if (!in_array($post_row['id'], $uniqueIds)) {
                            $data[] = ['type' => 'pildi_kommentaar', 'id' => $row['id'], 'tekst' => $row['tekst'], 'puu_id' => $post_row['puu_id']];
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
            $result = $db->query('SELECT id, tekst FROM postitused ORDER BY aeg DESC');
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
        .otsingu-tulemus { padding: 5px; cursor: pointer; }
        .otsingu-tulemus:hover, .otsingu-tulemus.active { background: #ddd; }
        .konteiner { display: flex; flex: 1; overflow: hidden; }
        #puu-konteiner { width: 300px; background: #f4f4f4; overflow-y: auto; padding: 10px; position: relative; }
        .puu-sõlm { padding: 5px; cursor: pointer; }
        .puu-sõlm:hover { background: #ddd; }
        .puu-sõlm.laiendatud > .alamlehed { display: block; }
        .puu-sõlm .alamlehed { display: none; }
        #peasisu { flex: 1; padding: 20px; overflow-y: auto; background: white; }
        .postituse-redaktor { margin-bottom: 20px; }
        .postituse-redaktor textarea { width: 100%; height: 100px; resize: vertical; padding: 10px; border: 1px solid #ccc; border-radius: 4px;}
        /*display: list-item; ja display: grid;*/
        .galerii { display: list-item; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 10px; }
        /*width: 100%;*/
        .galerii-üksus img { width: auto;height: auto; border-radius: 4px; cursor: pointer; }
        .kommentaari-paan { background: #f0f0f0; padding: 5px; margin-top: 5px; border-radius: 4px; }
        .pesastatud-kommentaarid { margin-left: 20px; }
        footer { background: #1a1a1a; color: white; padding: 10px; position: sticky; bottom: 0; }
        .kontekstimenüü { position: absolute; background: white; border: 1px solid #ccc; z-index: 1000; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .kontekstimenüü ul { list-style: none; padding: 0; margin: 0; }
        .kontekstimenüü li { padding: 8px 12px; cursor: pointer; }
        .kontekstimenüü li:hover { background: #e0e0e0; }
        .postituse-failid { margin-top: 10px; }
        .postituse-failid a { display: block; margin: 5px 0; color: #1a73e8; text-decoration: none; }
        .postituse-kommentaarid { margin-top: 15px; }
        .pildi-kommentaar {overflow: hidden;margin: 5px 0;background: #f9f9f9;padding: 5px;border-radius: 4px;}
        .vasta-redaktor {margin-left: 20px;margin-top: 5px;}
        .vasta-redaktor textarea {width: 100%;height: 60px;margin-bottom: 5px;}
        .kommentaar { border-top: 1px solid #ddd; position: relative; }
        .kommentaar small { color: #666; }
        .kommentaar button { margin-left: 10px; }
        .kommentaari-textarea{width: 100%;  padding-bottom: 10px;margin-bottom: 10px;}
        #pildi-kommentaar-tekst{width: 100%;height: 10%;}
        button { padding: 5px 10px; margin: 0 5px; cursor: pointer; background: #1a73e8; color: white; border: none; border-radius: 4px; }
        button:hover { background: #1557b0; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 100; }
        .modal.active { display: flex; justify-content: center; align-items: center; }
        .modal-content { max-width: 90%; max-height: 90%; background: white; border-radius: 5px; display: flex; }
        .modal-image { max-width: 70%; max-height: 100%; object-fit: contain; border-radius: 5px 0 0 5px; }
        .modal-kommentaarid { width: 300px; padding: 20px; overflow-y: auto; }
        .ajalugu-kirje {padding: 8px;border-bottom: 1px solid #ddd;cursor: pointer;}
        .ajalugu-kirje:hover {background: #f0f0f0;}
        .ajalugu-kirje a {text-decoration: none;color: #333;display: block;}
        .soovitus-list {background: white;border: 1px solid #ccc;max-height: 200px;overflow-y: auto;z-index: 100;}
        .soovitus-item {padding: 5px;cursor: pointer;}
        .soovitus-item:hover, .soovitus-item.aktiivne {background: #ddd;}
        button{padding: 0;
  margin: 0;
  cursor: pointer;
  background: #1a73e8;
  color: white;
  border: none;
  border-radius: 4px;}
    </style>
</head>
<body>
    <header>
        <input type="text" id="otsing" placeholder="Otsi oksi, lehti, postitusi...">
        <div id="otsingu-tulemused"></div>
    </header>
    <div class="konteiner">
        <div id="puu-konteiner"></div>
        <div id="peasisu">
            <div class="postituse-redaktor">
                <textarea placeholder="Loo postitus..."></textarea>
                <input type="file" multiple id="faili-sisend">
                <button onclick="PostitusteHaldur.lisaPostitus()">Postita</button>
            </div>
            <div id="postitused"></div>
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
                <h3>Pildi kommentaarid</h3>
                <div id="pildi-kommentaarid"></div>
                <textarea id="pildi-kommentaar-tekst" placeholder="Lisa kommentaar..."></textarea>
                <button id="pildi-kommentaar-nupp">Kommenteeri</button>
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
        const fullPath = this.getFullPath(Andmed.valitudSõlm);
        peasisu.innerHTML = `
            <h2>${fullPath}</h2>
            <div class="postituse-redaktor">
                <textarea placeholder="Kirjuta postitus..."></textarea>
                <input type="file" id="faili-sisend" multiple>
                <button onclick="PostitusteHaldur.lisaPostitus()">Postita</button>
            </div>
            <div id="postitused"></div>
        `;
        PostitusteHaldur.lisaSoovitusKuulajad(peasisu);
        PostitusteHaldur.kuvaPostitused();
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
            const vastus = await fetch('', { method: 'POST', body: formData });
            const data = await vastus.json();
            if (data.status === 'uploaded') {
                manused.push({ path: data.path, type: data.type });
            }
        }

        const vastus = await fetch('', {
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
        }
    },

    async kuvaPostitused() {
        const konteiner = document.getElementById('postitused');
        konteiner.innerHTML = '';
        if (!Andmed.valitudSõlm) return;

        const postitused = await apiKutse('get_postitused', { puu_id: Andmed.valitudSõlm.id });
        const kõikPostitused = await apiKutse('get_postitused'); // Fetch all posts for link resolution
        for (const postitus of postitused) {
            const div = document.createElement('div');
            div.className = 'postitus';
            div.id = `postitus-${postitus.id}`;

            let tekst = postitus.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                (match, p1, p2) => {
                    const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                    const puuId = targetPost ? targetPost.puu_id : null;
                    return `<a href="#postitus-${p2}" onclick="AjaluguHaldur.liiguKirjeni('postitus', ${p2}, ${puuId || 'null'}); return false;">${p1}</a>`;
                });

            const manused = JSON.parse(postitus.manused || '[]');
            let failideHtml = '';
            if (manused.length > 0) {
            failideHtml = '<div class="postituse-failid">';
            const galerii = document.createElement('div');
            galerii.className = 'galerii';
            for (const manus of manused) {
                const fullPath = `${BASE_URL}${manus.path}`; // Lisa baas-URL
                if (manus.type.startsWith('image/')) {
                    const galeriiÜksus = document.createElement('div');
                    galeriiÜksus.className = 'galerii-üksus';
                    galeriiÜksus.innerHTML = `<img src="${fullPath}" alt="${manus.path.split('/').pop()}" onclick="PostitusteHaldur.avaPilt('${fullPath}')">`;
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
                                    return `<a href="#postitus-${p2}" onclick="AjaluguHaldur.liiguKirjeni('postitus', ${p2}, ${puuId || 'null'}); return false;">${p1}</a>`;
                                });
                            imgCommDiv.innerHTML += `
                                <div class="kommentaar pildi-kommentaar" id="fail-${komm.id}">
                                    <img src="${fullPath}" style="width: 50px; height: 50px; float: left; margin-right: 10px;">
                                    <p>${kommTekst} <small>${new Date(komm.aeg * 1000).toLocaleString()}</small></p>
                                </div>
                            `;
                        });
                        galerii.appendChild(imgCommDiv);
                    }
                } else {
                    failideHtml += `<a href="${fullPath}" target="_blank">${manus.path.split('/').pop()}</a>`;
                }
            }
            failideHtml += '</div>' + galerii.outerHTML;
        }

            const kommentaarid = this.ehitaKommentaarideHierarhia(postitus.kommentaarid || []);
            let kommentaarideHtml = '<div class="postituse-kommentaarid">';
            kommentaarideHtml += this.renderKommentaarid(kommentaarid, postitus.id, kõikPostitused);
            kommentaarideHtml += '</div>';

            div.innerHTML = `
                <p>${tekst} <small>${new Date(postitus.aeg * 1000).toLocaleString()}</small>
                <button onclick="PostitusteHaldur.toggleMuudaPostitus(${postitus.id}, '${tekst.replace(/'/g, "\\'")}')">Muuda</button></p>
                ${failideHtml}
                ${kommentaarideHtml}
                <div class="kommentaari-redaktor" data-postitus-id="${postitus.id}">
                    <textarea class="kommentaari-textarea" placeholder="Lisa kommentaar...."></textarea>
                    <button onclick="PostitusteHaldur.lisaKommentaar(${postitus.id})">Kommenteeri</button>
                    <button onclick="PostitusteHaldur.kustutaPostitus(${postitus.id})">Kustuta</button>
                </div>
            `;
            konteiner.appendChild(div);
        }
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

    renderKommentaarid(kommentaarid, postitusId, kõikPostitused, tase = 0) {
        let html = '';
        kommentaarid.forEach(komm => {
            let tekst = komm.tekst.replace(/\[([^\]]+)\]\(#postitus-(\d+)\)/g, 
                (match, p1, p2) => {
                    const targetPost = kõikPostitused.find(p => p.id === parseInt(p2));
                    const puuId = targetPost ? targetPost.puu_id : null;
                    return `<a href="#postitus-${p2}" onclick="AjaluguHaldur.liiguKirjeni('postitus', ${p2}, ${puuId || 'null'}); return false;">${p1}</a>`;
                });
                html += `
                    <div class="kommentaar" id="kommentaar-${komm.id}" style="margin-left: ${tase * 20}px">
                        <p>${tekst} <small>${new Date(komm.aeg * 1000).toLocaleString()}</small>
                        <button onclick="PostitusteHaldur.toggleMuudaKommentaar(${komm.id})">Muuda</button>
                        <button onclick="PostitusteHaldur.toggleVasta(${komm.id}, ${postitusId})">Vasta</button>
                        <button onclick="PostitusteHaldur.kustutaKommentaar(${komm.id})">Kustuta</button></p>
                        <div class="vasta-redaktor" id="vasta-${komm.id}" style="display: none;">
                            <textarea placeholder="Vasta kommentaarile..."></textarea>
                            <button onclick="PostitusteHaldur.lisaKommentaar(${postitusId}, ${komm.id})">Saada vastus</button>
                        </div>
                    </div>
                `;
            if (komm.lapsed.length > 0) {
                html += this.renderKommentaarid(komm.lapsed, postitusId, kõikPostitused, tase + 1);
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

    avaPilt(path) {
        const modal = document.getElementById('galerii-modal');
        const pilt = document.getElementById('modal-pilt');
        pilt.src = path; // Path on juba täielik URL
        modal.classList.add('active');
        this.kuvaPildiKommentaarid(path.split(BASE_URL)[1]); // Saada serverile suhteline tee

        document.getElementById('pildi-kommentaar-nupp').onclick = () => this.lisaPildiKommentaar(path.split(BASE_URL)[1]);
        modal.onclick = (e) => {
            if (e.target === modal) modal.classList.remove('active');
        };
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
            div.dataset.id = komm.id; // Add data-id for easier targeting
            div.innerHTML = `
                <p>${tekst} <small>${new Date(komm.aeg * 1000).toLocaleString()}</small>
                <button onclick="PostitusteHaldur.toggleMuudaPildiKommentaar(${komm.id}, '${path}')">Muuda</button>
                <button onclick="PostitusteHaldur.kustutaPildiKommentaar(${komm.id}, '${path}')">Kustuta</button></p>
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
        const p = postDiv.querySelector('p');
        if (p.querySelector('textarea')) return;

        const textarea = document.createElement('textarea');
        textarea.value = tekst.split(' <small>')[0]; // Remove timestamp
        p.innerHTML = '';
        p.appendChild(textarea);
        const saveBtn = document.createElement('button');
        saveBtn.textContent = 'Salvesta';
        saveBtn.onclick = () => this.salvestaPostitusMuudatus(id);
        p.appendChild(saveBtn);
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
        const origTekst = p.childNodes[0].textContent.trim(); // Get only the text before <small> and buttons
        if (p.querySelector('textarea')) return;

        const textarea = document.createElement('textarea');
        textarea.value = origTekst;
        p.innerHTML = '';
        p.appendChild(textarea);
        const saveBtn = document.createElement('button');
        saveBtn.textContent = 'Salvesta';
        saveBtn.onclick = () => this.salvestaKommentaarMuudatus(id);
        p.appendChild(saveBtn);
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
        const origTekst = p.childNodes[0].textContent.trim(); // Get only the text before <small> and buttons
        if (p.querySelector('textarea')) return;

        const textarea = document.createElement('textarea');
        textarea.value = origTekst;
        p.innerHTML = '';
        p.appendChild(textarea);
        const saveBtn = document.createElement('button');
        saveBtn.textContent = 'Salvesta';
        saveBtn.onclick = () => this.salvestaPildiKommentaarMuudatus(id, path);
        p.appendChild(saveBtn);
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
                    e.preventDefault();
                    if (AutomaatSoovitus.aktiivneIndeks >= 0) {
                        AutomaatSoovitus.kinnitaSoovitus(tekstVali);
                    }
                } else if (e.key === 'Escape') {
                    AutomaatSoovitus.peidaSoovitused(konteiner);
                }
            });
        });
    }
};

// Otsingu haldamine
document.getElementById('otsing').addEventListener('input', async (e) => {
    const query = e.target.value.trim();
    const tulemused = document.getElementById('otsingu-tulemused');
    if (!query) {
        tulemused.innerHTML = '';
        return;
    }
    const data = await apiKutse('search', { query: encodeURIComponent(query) });
    tulemused.innerHTML = data.map(t => `
        <div class="otsingu-tulemus" data-type="${t.type}" data-id="${t.id}" data-puu-id="${t.puu_id}" onclick="PuuHaldur.valiSõlm(${t.puu_id}, '${t.type}', ${t.id})">${t.nimi || t.tekst}</div>
    `).join('');
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

// Ajalugu haldamine
const AjaluguHaldur = {
    async kuvaAjalugu() {
        const peasisu = document.getElementById('peasisu');
        peasisu.innerHTML = '<h2>Viimased tegevused</h2><div id="ajalugu-list"></div>';
        
        try {
            const history = await apiKutse('get_history', { limit: 100 });
            if (!Array.isArray(history)) {
                console.error('Ajalugu ei ole massiiv:', history);
                peasisu.innerHTML += '<p>Viga: Ajalugu ei saadud kätte.</p>';
                return;
            }
            
            const list = document.getElementById('ajalugu-list');
            if (history.length === 0) {
                list.innerHTML = '<p>Ajalugu on tühi.</p>';
                return;
            }
            
            history.forEach(item => {
                const kirje = document.createElement('div');
                kirje.className = 'ajalugu-kirje';
                const type = item.type || 'tundmatu';
                const tekst = (item.tekst || 'Sisu puudub').substring(0, 250) + (item.tekst && item.tekst.length > 50 ? '...' : '');
                const aeg = item.aeg ? new Date(item.aeg * 1000).toLocaleString('et-EE') : 'Aeg puudub';
                let ankur;
                
                if (type === 'postitus') {
                    ankur = `#postitus-${item.id}`;
                } else if (type === 'kommentaar') {
                    ankur = `#kommentaar-${item.id}`;
                } else {
                    ankur = `#fail-${item.id}`;
                }
                
                const puuId = type === 'fail' ? `'${item.puu_id}'` : (item.puu_id || item.postitus_id);
                kirje.innerHTML = `
                    <a href="${ankur}" onclick="AjaluguHaldur.liiguKirjeni('${type}', ${item.id}, ${puuId}); return false;">
                        [${type}] ${tekst} <small>${aeg}</small>
                    </a>
                `;
                list.appendChild(kirje);
            });
        } catch (error) {
            console.error('Viga ajaloo laadimisel:', error);
            peasisu.innerHTML += '<p>Viga ajaloo laadimisel.</p>';
        }
    },

    async liiguKirjeni(type, id, puuId) {
        let sõlm;
        if (type === 'fail') {
            const postitused = await apiKutse('get_postitused');
            const postitus = postitused.find(p => {
                const manused = JSON.parse(p.manused || '[]');
                return manused.some(m => m.path === puuId);
            });
            if (postitus) {
                sõlm = PuuHaldur.findNode(Andmed.puu, postitus.puu_id);
            }
        } else {
            sõlm = PuuHaldur.findNode(Andmed.puu, puuId);
        }

        if (!sõlm) {
            console.warn('Sõlme ei leitud:', type, puuId);
            return;
        }

        Andmed.valitudSõlm = sõlm;
        await PuuHaldur.kuvaPostitamisLeht();

        const waitForElement = (selector, callback, maxAttempts = 10, interval = 100) => {
            let attempts = 0;
            const intervalId = setInterval(() => {
                const element = document.querySelector(selector);
                if (element) {
                    clearInterval(intervalId);
                    callback(element);
                } else if (attempts >= maxAttempts) {
                    clearInterval(intervalId);
                    console.warn('Elementi ei leitud:', selector);
                }
                attempts++;
            }, interval);
        };

        waitForElement(`#${type === 'postitus' ? 'postitus' : type === 'kommentaar' ? 'kommentaar' : 'fail'}-${id}`, (element) => {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }
};

// Alglaadimine
document.addEventListener('DOMContentLoaded', () => {
    PuuHaldur.laadiPuu();
    AutomaatSoovitus.laadiPealkirjad();
    AjaluguHaldur.kuvaAjalugu();
});
    </script>

</body>
</html>