<?php

use App\Support\AccessControl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        foreach (AccessControl::defaults() as $key => $role) {
            DB::table('roles')->insert([
                'key' => $key,
                'name' => $role['name'],
                'description' => $role['description'],
                'permissions' => json_encode($role['permissions']),
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
