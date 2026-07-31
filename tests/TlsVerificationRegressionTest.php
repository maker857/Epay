<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    if (strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) continue;
    if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) continue;
    $files[] = $path;
}

$violations = [];
foreach ($files as $path) {
    $contents = file_get_contents($path);
    if (preg_match('/CURLOPT_SSL_VERIFYPEER\s*,\s*false/i', $contents)) {
        $violations[] = $path . ': CURLOPT_SSL_VERIFYPEER=false';
    }
    if (preg_match('/CURLOPT_SSL_VERIFYHOST\s*,\s*(false|0|1)\b/i', $contents)) {
        $violations[] = $path . ': CURLOPT_SSL_VERIFYHOST is not strict';
    }
}

if ($violations) {
    fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
    exit(1);
}

echo "TLS verification regression checks passed." . PHP_EOL;
