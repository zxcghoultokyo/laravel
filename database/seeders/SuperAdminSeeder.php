<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder for creating Super Admin user.
 * 
 * Run: php artisan db:seed --class=SuperAdminSeeder
 */
class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Super Admin account - has access to all tenants
        $superAdmin = User::updateOrCreate(
            ['email' => 'stovburtm@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Sataz123'),
                'role' => User::ROLE_SUPER_ADMIN,
                'tenant_id' => null, // Super admin is not bound to any tenant
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("✅ Super Admin created/updated:");
        $this->command->info("   Email: {$superAdmin->email}");
        $this->command->info("   Role: {$superAdmin->role}");
        $this->command->info("   ID: {$superAdmin->id}");
    }
}
