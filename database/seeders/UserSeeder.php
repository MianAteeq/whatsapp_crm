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
        $tenant = Tenant::firstOrCreate(
            ['name' => 'Demo Workspace'],
            ['plan' => 'pro']
        );

        // 2. Create Admin User
        $user = User::updateOrCreate(
            ['email' => 'admin@throbtech.com'],
            [
                'name' => 'throb User',
                'password' => Hash::make('123456'),
                'tenant_id' => $tenant->id,
                'role' => 'admin'
            ]
        );

        // 3. Attach owner_id to tenant
        $tenant->update([
            'owner_id' => $user->id
        ]);

        // 4. Create Super Admin User (Global SaaS Administrator)
        User::updateOrCreate(
            ['email' => 'superadmin@throbtech.com'],
            [
                'name' => 'SaaS Global Admin',
                'password' => Hash::make('123456'),
                'role' => 'superadmin'
            ]
        );
    }
}