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
      

        // 4. Create Super Admin User (Global SaaS Administrator)
        User::updateOrCreate(
            ['email' => 'superadmin@talkinexx.com'],
            [
                'name' => 'SaaS Global Admin',
                'password' => Hash::make('Ateeq181995!@'),
                'role' => 'superadmin'
            ]
        );
    }
}