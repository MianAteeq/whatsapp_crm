<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tools = app(\App\Services\Ai\AiMarketingAgentTools::class);
$res = $tools->search_contacts(5, ['category_id' => 27, 'has_email' => true]);
echo "Eligible count for Category 27 (Software Agencies): " . $res['eligible_count'] . "\n";
echo "Details: " . json_encode($res) . "\n";
