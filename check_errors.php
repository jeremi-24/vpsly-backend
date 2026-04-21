<?php
$logFile = __DIR__ . '/storage/logs/laravel.log';
$content = file_get_contents($logFile);

preg_match_all('/GitHub Callback Error: (.*?)(\n|$)/', $content, $matches);
if (isset($matches[1]) && count($matches[1]) > 0) {
    echo "ERRORS FOUND:\n";
    foreach(array_unique($matches[1]) as $error) {
        echo "- " . $error . "\n";
    }
} else {
    echo "NO CALLBACK ERRORS IN LOG.";
}
