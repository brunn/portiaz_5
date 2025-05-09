<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Luba ristdomeeni päringud (vajadusel)

$dbPath = '/var/www/html/marcmic_2/portiaz_5/ANDMEBAAS_PUU.db';

try {
    $db = new SQLite3($dbPath);
    
    $query = "
        SELECT p.tekst, p.aeg, puu.nimi 
        FROM postitused p 
        JOIN puu ON p.puu_id = puu.id 
        ORDER BY p.aeg DESC
    ";
    
    $result = $db->query($query);
    $posts = [];
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $posts[] = [
            'text' => $row['tekst'],
            'timestamp' => (int)$row['aeg'],
            'tree_name' => $row['nimi']
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => $posts
    ]);
    
    $db->close();
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>