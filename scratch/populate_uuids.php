<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$tables = ['servers', 'applications'];
foreach ($tables as $t) {
    $rows = DB::table($t)->whereNull('uuid')->get();
    foreach ($rows as $row) {
        DB::table($t)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
    }
    echo "Table {$t}: Populated " . count($rows) . " UUIDs.\n";
}
