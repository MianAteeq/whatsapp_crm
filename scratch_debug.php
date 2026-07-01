<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$tenantId = 1;
$workflow = App\Models\AutomationWorkflow::with(['nodes', 'connections'])
    ->where('tenant_id', $tenantId)
    ->find(2);
echo "Workflow 2: " . json_encode($workflow) . "\n";
