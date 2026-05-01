<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

echo "# VPSly Database Architecture\n\n";

foreach ($tables as $t) {
    $tableName = $t->name;
    if ($tableName === 'migrations') continue;

    echo "### Table: `{$tableName}`\n";
    
    // Columns
    $columns = DB::select("PRAGMA table_info({$tableName})");
    echo "| Column | Type | PK | Nullable |\n";
    echo "|--------|------|----|----------|\n";
    foreach ($columns as $c) {
        $pk = $c->pk ? '✅' : '';
        $notnull = $c->notnull ? '❌' : '✅';
        echo "| {$c->name} | {$c->type} | {$pk} | {$notnull} |\n";
    }

    // Foreign Keys
    $fks = DB::select("PRAGMA foreign_key_list({$tableName})");
    if (!empty($fks)) {
        echo "\n**Foreign Keys:**\n";
        foreach ($fks as $fk) {
            echo "- `{$fk->from}` → `{$fk->table}({$fk->to})`\n";
        }
    }
    echo "\n---\n\n";
}
