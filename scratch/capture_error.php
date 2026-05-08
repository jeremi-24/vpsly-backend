<?php
$logPath = 'storage/logs/laravel.log';
$lines = file($logPath);
$errorEntry = "";
$found = false;
for ($i = count($lines) - 1; $i >= 0; $i--) {
    if (str_contains($lines[$i], 'ERROR')) {
        $found = true;
        $errorEntry = $lines[$i];
        // Capture up to 5 lines after the error to get the message if it's on the next line
        for ($j = 1; $j <= 5 && ($i + $j) < count($lines); $j++) {
            $errorEntry .= $lines[$i + $j];
        }
        break;
    }
}

file_put_contents('scratch/last_error_debug.txt', $found ? $errorEntry : "No error found");
