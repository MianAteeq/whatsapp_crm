<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Tenant
        $tenant = Tenant::create([
            'name' => 'Demo Workspace',
            'plan' => 'pro'
        ]);

        // 2. Create Admin User
        $user = User::create([
            'name' => 'throb User',
            'email' => 'admin@throbtech.com',
            'password' => Hash::make('123456'),
            'tenant_id' => $tenant->id,
            'role' => 'admin'
        ]);

        // 3. Attach owner_id to tenant
        $tenant->update([
            'owner_id' => $user->id
        ]);
    }
}