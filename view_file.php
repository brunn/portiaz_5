<?PHP
if (!isset($_GET['file']) || !isset($_GET['projekt'])) {
    http_response_code(400);
    echo "Faili või projekti parameeter puudub!";
    exit;
}
$baseDir = __DIR__;
if($_GET['projekt'] === "ANDMEBAAS"){
    $filePath = $baseDir.'/'.$_GET['file'];
}else{
    $filePath = $baseDir.'/'.$_GET['projekt'].'/'.$_GET['file'];
}
if (!file_exists($filePath)) {
    http_response_code(404);
    echo "Faili ei leitud!";
    var_dump($_GET);
    var_dump($filePath);
    exit;
}
$extension = trim(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)));
$fileName = basename($filePath);
$allowedExtensions = ['txt', 'md', 'odt', 'pdf', 'ods'];

if (!in_array($extension, $allowedExtensions)) {
    http_response_code(404);
    echo "See failivorming pole toetatud!";
    exit;
}
if ($extension === 'md') {
    require_once __DIR__ . '/vendor/autoload.php';
    $parsedown = new Parsedown();
    $content = file_get_contents($filePath);
    $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content));
    $htmlContent = $parsedown->text($content);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="et">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($fileName); ?></title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; margin: 20px; max-width: 800px; margin-left: auto; margin-right: auto; }
            h1, h2, h3, h4, h5, h6 { color: #333; }
            pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
            code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; }
            a { color: #0066cc; }
            img { max-width: 100%; height: auto; }
            table { border-collapse: collapse; width: 100%; margin: 20px 0; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
        </style>
    </head>
    <body>
        <h1><?php echo htmlspecialchars($fileName); ?></h1>
        <?php echo $htmlContent; ?>
    </body>
    </html>
    <?php
} elseif ($extension === 'txt') {
    $content = file_get_contents($filePath);
    $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content));
    header('Content-Type: text/plain; charset=UTF-8');
    echo $content;
} elseif ($extension === 'odt') {
    $tempDir = sys_get_temp_dir() . '/' . uniqid('odt_', true);
    mkdir($tempDir, 0755, true);
    $zip = new ZipArchive();
    if ($zip->open($filePath) === true) {
        $zip->extractTo($tempDir);
        $zip->close();
    } else {
        http_response_code(500);
        echo "Viga ODT faili avamisel!";
        rmdir($tempDir);
        exit;
    }
    $tempHtml = $tempDir . '/output.html';
    $command = "pandoc -f odt -t html " . escapeshellarg($filePath) . " -o " . escapeshellarg($tempHtml);
    exec($command, $output, $returnVar);
    if ($returnVar !== 0 || !file_exists($tempHtml)) {
        http_response_code(500);
        echo "Viga ODT faili teisendamisel! Veakood: $returnVar";
        array_map('unlink', glob("$tempDir/*"));
        rmdir($tempDir);
        exit;
    }
    $htmlContent = file_get_contents($tempHtml);
    $projectDir = isset($_GET['projekt']) ? $_GET['projekt'] : '';
    $uploadDir = $projectDir ? $_SERVER['DOCUMENT_ROOT'] . "/$projectDir/uploads" : $_SERVER['DOCUMENT_ROOT'] . '/uploads';
    $relativeBase = $projectDir ? "/$projectDir/uploads/" : "/uploads/";
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $htmlContent = preg_replace_callback(
        '/<img src="Pictures\/([^"]+)"/',
        function ($matches) use ($tempDir, $uploadDir, $relativeBase) {
            $imageName = $matches[1];
            $imagePath = $tempDir . '/Pictures/' . $imageName;
            if (file_exists($imagePath)) {
                $newImagePath = $uploadDir . '/' . $imageName;
                if (!file_exists($newImagePath)) {
                    copy($imagePath, $newImagePath);
                }
                return '<img src="' . $relativeBase . $imageName . '"';
            }
            return $matches[0];
        },
        $htmlContent
    );
    array_map('unlink', glob("$tempDir/*"));
    rmdir($tempDir);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="et">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($fileName); ?></title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; margin: 20px; max-width: 800px; margin-left: auto; margin-right: auto; }
            h1, h2, h3, h4, h5, h6 { color: #333; }
            pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
            code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; }
            a { color: #0066cc; }
            img { max-width: 100%; height: auto; }
            table { border-collapse: collapse; width: 100%; margin: 20px 0; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
        </style>
    </head>
    <body>
        <h1><?php echo htmlspecialchars($fileName); ?></h1>
        <?php echo $htmlContent; ?>
    </body>
    </html>
    <?php
} elseif ($extension === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
} elseif ($extension === 'ods_a') {
    $tempDir = $baseDir . '/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    $homeDir = $tempDir . '/home';
    if (!is_dir($homeDir)) {
        mkdir($homeDir, 0755, true);
    }
    putenv("HOME=" . $homeDir);
    $tempHtml = $tempDir . '/' . uniqid('ods_', true) . '.html'; // Unikaalne nimi, et vältida konflikte
    $command = "/usr/bin/unoconv -f html -o " . escapeshellarg($tempHtml) . " " . escapeshellarg($filePath) . " 2>&1";
    exec($command, $output, $returnVar);
    if ($returnVar !== 0 || !file_exists($tempHtml)) {
        http_response_code(500);
        echo "Viga ODS faili teisendamisel unoconv abil! Veakood: $returnVar<br>";
        echo "Väljund: <pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
        array_map('unlink', glob("$tempDir/*")); // Puhasta ainult failid, mitte kaust
        exit;
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) === true) {
        $zip->extractTo($tempDir);
        $zip->close();
    } else {
        http_response_code(500);
        echo "Viga ODS faili avamisel piltide ekstraktimiseks!";
        array_map('unlink', glob("$tempDir/*"));
        exit;
    }
    $htmlContent = file_get_contents($tempHtml);
    $projectDir = $_GET['projekt'];
    $relativeBase = "/$projectDir/uploads/";
    $htmlContent = preg_replace_callback(
        '/<img src="([^"]+)"/',
        function ($matches) use ($tempDir, $filePath, $relativeBase) {
            $imageSrc = $matches[1];
            if (strpos($imageSrc, 'Pictures/') === 0) {
                $imageName = basename($imageSrc);
                $imagePath = $tempDir . '/' . $imageSrc;
                if (file_exists($imagePath)) {
                    $newImagePath = dirname($filePath) . '/' . $imageName;
                    if (!file_exists($newImagePath)) {
                        copy($imagePath, $newImagePath);
                    }
                    return '<img src="' . $relativeBase . $imageName . '"';
                }
            }
            return $matches[0];
        },
        $htmlContent
    );
    unlink($tempHtml);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="et">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($fileName); ?></title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; margin: 20px; max-width: 800px; margin-left: auto; margin-right: auto; }
            h1, h2, h3, h4, h5, h6 { color: #333; }
            pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
            code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; }
            a { color: #0066cc; }
            img { max-width: 100%; height: auto; }
            table { border-collapse: collapse; width: 100%; margin: 20px 0; }
            th, td { padding: 8px; text-align: left; }
        </style>
    </head>
    <body>
        <h1><?php echo htmlspecialchars($fileName); ?></h1>
        <?php echo $htmlContent; ?>
    </body>
    </html>
    <?php
} elseif ($extension === 'ods_b') {
    $tempDir = $baseDir . '/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    putenv("HOME=" . $tempDir);
    chown($tempDir, 'www-data');
    chgrp($tempDir, 'www-data');
    chmod($tempDir, 0755);
    $tempHtml = $tempDir . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.html';
    $command = "libreoffice --headless --convert-to html " . escapeshellarg($filePath) . " --outdir " . escapeshellarg($tempDir) . " 2>&1";
    exec($command, $output, $returnVar);
    if ($returnVar !== 0 || !file_exists($tempHtml)) {
        http_response_code(500);
        echo "Viga ODS faili teisendamisel LibreOffice abil! Veakood: $returnVar<br>";
        echo "Väljund: <pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
        array_map('unlink', glob("$tempDir/*"));
        exit;
    }
    $htmlContent = file_get_contents($tempHtml);
    unlink($tempHtml);
    $zip = new ZipArchive();
    if ($zip->open($filePath) === true) {
        $zip->extractTo($tempDir);
        $zip->close();
    } else {
        http_response_code(500);
        echo "Viga ODS faili avamisel piltide ekstraktimiseks!";
        array_map('unlink', glob("$tempDir/*"));
        exit;
    }
    $projectDir = $_GET['projekt'];
    $relativeBase = "/$projectDir/uploads/";
    $htmlContent = preg_replace_callback(
        '/<img src="([^"]+)"/',
        function ($matches) use ($tempDir, $filePath, $relativeBase) {
            $imageSrc = $matches[1];
            if (strpos($imageSrc, 'Pictures/') === 0) {
                $imageName = basename($imageSrc);
                $imagePath = $tempDir . '/' . $imageSrc;
                if (file_exists($imagePath)) {
                    $newImagePath = dirname($filePath) . '/' . $imageName;
                    if (!file_exists($newImagePath)) {
                        copy($imagePath, $newImagePath);
                    }
                    return '<img src="' . $relativeBase . $imageName . '"';
                }
            }
            return $matches[0];
        },
        $htmlContent
    );
    array_map('unlink', glob("$tempDir/*"));
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="et">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($fileName); ?></title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                line-height: 1.4; 
                margin: 10px; 
                max-width: 100%; 
            }
            h1, h2, h3, h4, h5, h6 { color: #333; }
            pre { background: #f4f4f4; padding: 5px; border-radius: 5px; overflow-x: auto; }
            code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; }
            a { color: #0066cc; }
            img { max-width: 100%; height: auto; }
            table { 
                border-collapse: collapse; 
                width: auto; 
                margin: 10px 0; 
            }
            th, td { 
                border: 0.1px solid #ccc; 
                padding: 2px 4px; 
                text-align: left; 
                vertical-align: top; 
            }
            th { 
                background-color: #f2f2f2; 
                font-weight: bold; 
            }
        </style>
    </head>
    <body>
        <h1><?php echo htmlspecialchars($fileName); ?></h1>
        <?php echo $htmlContent; ?>
    </body>
    </html>
    <?php
}elseif ($extension === 'ods_c') {
    $tempDir = $baseDir . '/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    putenv("HOME=" . $tempDir);
    chown($tempDir, 'www-data');
    chgrp($tempDir, 'www-data');
    chmod($tempDir, 0755);
    
    $tempHtml = $tempDir . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.html';
    $command = "libreoffice --headless --convert-to html " . escapeshellarg($filePath) . " --outdir " . escapeshellarg($tempDir) . " 2>&1";
    exec($command, $output, $returnVar);
    
    if ($returnVar !== 0 || !file_exists($tempHtml)) {
        http_response_code(500);
        echo "Viga ODS faili teisendamisel LibreOffice abil! Veakood: $returnVar<br>";
        echo "Väljund: <pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
        array_map('unlink', glob("$tempDir/*"));
        exit;
    }
    
    $htmlContent = file_get_contents($tempHtml);
    unlink($tempHtml);
    
    // Kasutame DOMDocument-i HTML parsimiseks ja puhastamiseks
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    // Leiame kõik tabelid ja kohandame nende paigutust
    foreach ($dom->getElementsByTagName('table') as $table) {
        $table->setAttribute('style', 'border-collapse: collapse; width: 100%;');
        
        foreach ($table->getElementsByTagName('tr') as $tr) {
            foreach ($tr->getElementsByTagName('td') as $td) {
                $td->setAttribute('style', 'border: 1px solid #ccc; padding: 4px; vertical-align: top;');
            }
        }
    }
    
    // Piltide teekondade parandamine
    $projectDir = $_GET['projekt'];
    $relativeBase = "/$projectDir/uploads/";
    
    foreach ($dom->getElementsByTagName('img') as $img) {
        $imageSrc = $img->getAttribute('src');
        if (strpos($imageSrc, 'Pictures/') === 0) {
            $imageName = basename($imageSrc);
            $imagePath = $tempDir . '/' . $imageSrc;
            if (file_exists($imagePath)) {
                $newImagePath = dirname($filePath) . '/' . $imageName;
                if (!file_exists($newImagePath)) {
                    copy($imagePath, $newImagePath);
                }
                $img->setAttribute('src', $relativeBase . $imageName);
            }
        }
    }
    
    // HTML lõplik renderdamine
    $cleanHtml = $dom->saveHTML();
    array_map('unlink', glob("$tempDir/*"));
    
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="et">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($fileName); ?></title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.4; margin: 10px; max-width: 100%; }
            table { border-collapse: collapse; width: 100%; margin: 10px 0; }
            th, td { border: 1px solid #ccc; padding: 4px; text-align: left; vertical-align: top; }
            th { background-color: #f2f2f2; font-weight: bold; }
            img { max-width: 100%; height: auto; }
        </style>
    </head>
    <body>
        <h1><?php echo htmlspecialchars($fileName); ?></h1>
        <?php echo $cleanHtml; ?>
    </body>
    </html>
    <?php
}elseif ($extension === 'ods') {
    $tempDir = $baseDir . '/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    putenv("HOME=" . $tempDir);
    chown($tempDir, 'www-data');
    chgrp($tempDir, 'www-data');
    chmod($tempDir, 0755);
    $tempHtml = $tempDir . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.html';
    $command = "libreoffice --headless --convert-to html " . escapeshellarg($filePath) . " --outdir " . escapeshellarg($tempDir) . " 2>&1";
    exec($command, $output, $returnVar);
    if ($returnVar !== 0 || !file_exists($tempHtml)) {
        http_response_code(500);
        echo "Viga ODS faili teisendamisel LibreOffice abil! Veakood: $returnVar<br>";
        echo "Väljund: <pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
        array_map('unlink', glob("$tempDir/*"));
        exit;
    }
    $htmlContent = file_get_contents($tempHtml);
    unlink($tempHtml);
    $zip = new ZipArchive();
    if ($zip->open($filePath) === true) {
        $zip->extractTo($tempDir);
        $zip->close();
    } else {
        http_response_code(500);
        echo "Viga ODS faili avamisel piltide ekstraktimiseks!";
        array_map('unlink', glob("$tempDir/*"));
        exit;
    }
    $projectDir = $_GET['projekt'];
    $relativeBase = "/$projectDir/uploads/";
    $htmlContent = preg_replace_callback(
        '/<img src="([^"]+)"/',
        function ($matches) use ($tempDir, $filePath, $relativeBase) {
            $imageSrc = $matches[1];
            if (strpos($imageSrc, 'Pictures/') === 0) {
                $imageName = basename($imageSrc);
                $imagePath = $tempDir . '/' . $imageSrc;
                if (file_exists($imagePath)) {
                    $newImagePath = dirname($filePath) . '/' . $imageName;
                    if (!file_exists($newImagePath)) {
                        copy($imagePath, $newImagePath);
                    }
                    return '<img src="' . $relativeBase . $imageName . '"';
                }
            }
            return $matches[0];
        },
        $htmlContent
    );
    array_map('unlink', glob("$tempDir/*"));
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="et">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($fileName); ?></title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                line-height: 1.4; 
                margin: 10px; 
                max-width: 100%; 
            }
            h1, h2, h3, h4, h5, h6 { color: #333; }
            pre { background: #f4f4f4; padding: 5px; border-radius: 5px; overflow-x: auto; }
            code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; }
            a { color: #0066cc; }
            img { max-width: 100%; height: auto; }
            table { 
                border-collapse: collapse; 
                width: auto; 
                margin: 10px 0; 
            }
            th, td { 
                border: 0.1px solid #ccc; 
                padding: 2px 4px; 
                text-align: inherit; 
                vertical-align: top; 
            }
            th { 
                background-color: #f2f2f2; 
                font-weight: bold; 
            }
        </style>
    </head>
    <body>
        <h1><?php echo htmlspecialchars($fileName); ?></h1>
        <?php echo $htmlContent; ?>
    </body>
    </html>
    <?php
}

