<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('price');
            $table->json('limits');
            $table->timestamps();
        });

        // Seed default plans
        DB::table('plans')->insert([
            [
                'key' => 'free',
                'name' => 'Free Tier',
                'price' => '$0/month',
                'limits' => json_encode([
                    'contacts' => '100',
                    'messages' => '1,000/month',
                    'campaigns' => 'Disabled',
                    'ai_replies' => 'Disabled'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'pro',
                'name' => 'Pro Tier',
                'price' => '$49/month',
                'limits' => json_encode([
                    'contacts' => '5,000',
                    'messages' => '50,000/month',
                    'campaigns' => 'Unlimited',
                    'ai_replies' => 'Enabled (Limit 500)'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'enterprise',
                'name' => 'Enterprise Tier',
                'price' => 'Custom Pricing',
                'limits' => json_encode([
                    'contacts' => 'Unlimited',
                    'messages' => 'Unlimited',
                    'campaigns' => 'Unlimited',
                    'ai_replies' => 'Unlimited'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
