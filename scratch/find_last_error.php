<?php
$logPath = 'storage/logs/laravel.log';
if (!file_exists($logPath)) {
    echo "Log file not found.\n";
    exit;
}

$lines = file($logPath);
$errorLine = null;
for ($i = count($lines) - 1; $i >= 0; $i--) {
    if (str_contains($lines[$i], 'ERROR')) {
        $errorLine = $lines[$i];
        break;
    }
}

if ($errorLine) {
    echo "Last Error: " . $errorLine . "\n";
} else {
    echo "No ERROR found in log.\n";
}
