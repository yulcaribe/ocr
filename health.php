<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
echo json_encode([
    'ok' => true,
    'service' => 'ocr',
    'php' => PHP_VERSION
], JSON_UNESCAPED_SLASHES);
