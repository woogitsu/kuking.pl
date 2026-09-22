<?php
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($root.'/public'.$path)) { return false; }
foreach ($_FILES as $field => $upload) {
    foreach ((array) $upload['tmp_name'] as $index => $tmp) {
        if (!is_uploaded_file($tmp)) { continue; }
        $dimensions = @getimagesize($tmp);
        file_put_contents(__DIR__.'/phone-measurements.jsonl', json_encode([
            'time_utc' => gmdate('c'), 'mime_detected' => mime_content_type($tmp),
            'bytes' => filesize($tmp), 'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
        ])."\n", FILE_APPEND | LOCK_EX);
    }
}
require $root.'/public/index.php';