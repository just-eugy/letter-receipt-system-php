<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin user from environment variables
        User::create([
            'username' => env('APP_BOOTSTRAP_ADMIN_USERNAME', 'admin'),
            'password' => env('APP_BOOTSTRAP_ADMIN_PASSWORD', 'password'),
            'display_name' => env('APP_BOOTSTRAP_ADMIN_DISPLAY_NAME', 'Admin'),
            'role' => 'ADMIN',
            'active' => true,
        ]);
    }
}
