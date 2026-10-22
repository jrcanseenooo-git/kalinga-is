<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        foreach ([['name' => 'Kalinga Social Worker', 'email' => 'worker@kalinga.test', 'role' => 'social_worker'], ['name' => 'Kalinga Supervisor', 'email' => 'supervisor@kalinga.test', 'role' => 'social_worker'], ['name' => 'Kalinga CPU Member', 'email' => 'cpu@kalinga.test', 'role' => 'cpu_member', 'cpu_discipline' => 'social_worker_cpo'], ['name' => 'Kalinga System Admin', 'email' => 'admin@kalinga.test', 'role' => 'system_admin']] as $account) {
            User::updateOrCreate(['email' => $account['email']], $account + ['email_verified_at' => now(), 'password' => Hash::make('password')]);
        }

        $this->call(KalingaDemoSeeder::class);
    }
}
