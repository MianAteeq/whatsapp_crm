<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$targets = \App\Models\SocialPostTarget::where('status', 'published')->get();
echo "Published targets count: " . $targets->count() . PHP_EOL;
foreach ($targets as $t) {
    echo "Target ID: {$t->id} | Platform: {$t->platform} | Post ID: {$t->platform_post_id}" . PHP_EOL;
    echo "  Metrics: " . json_encode($t->metrics) . PHP_EOL;
}

echo PHP_EOL . "Comments in database:" . PHP_EOL;
$comments = \App\Models\SocialComment::all();
echo "Total comments: " . $comments->count() . PHP_EOL;
foreach ($comments as $c) {
    echo "Comment ID: {$c->id} | Post ID: {$c->post_id} | Platform: {$c->platform} | Msg: {$c->message}" . PHP_EOL;
}

