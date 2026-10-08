<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = Illuminate\Support\Facades\DB::select('SHOW TABLES');
echo "=== TABLES IN DATABASE ===\n";
foreach ($tables as $t) {
    echo array_values((array)$t)[0] . "\n";
}

echo "\n=== CONTACTS COLUMNS ===\n";
$cols = Illuminate\Support\Facades\Schema::getColumnListing('contacts');
echo implode(', ', $cols) . "\n";

echo "\n=== SCRAPED BUSINESSES COLUMNS ===\n";
$cols = Illuminate\Support\Facades\Schema::getColumnListing('scraped_businesses');
echo implode(', ', $cols) . "\n";

echo "\n=== SCRAPER SEARCHES COLUMNS ===\n";
$cols = Illuminate\Support\Facades\Schema::getColumnListing('scraper_searches');
echo implode(', ', $cols) . "\n";

echo "\n=== EMAIL CAMPAIGN RECIPIENTS COLUMNS ===\n";
$cols = Illuminate\Support\Facades\Schema::getColumnListing('email_campaign_recipients');
echo implode(', ', $cols) . "\n";
