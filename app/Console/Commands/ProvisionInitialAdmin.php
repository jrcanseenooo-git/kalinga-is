<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ProvisionInitialAdmin extends Command
{
    protected $signature = 'kalinga:provision-initial-admin';

    protected $description = 'Creates the initial production system administrator from PROVISION_ADMIN_* environment variables only if absent.';

    public function handle(): int
    {
        $email = (string) env('PROVISION_ADMIN_EMAIL');
        $name = (string) env('PROVISION_ADMIN_NAME', 'Kalinga System Administrator');
        $password = (string) env('PROVISION_ADMIN_PASSWORD');
        if ($email === '' || $password === '') {
            $this->warn('Initial administrator not provisioned: PROVISION_ADMIN_EMAIL and PROVISION_ADMIN_PASSWORD are not set.');

            return self::SUCCESS;
        }
        if (User::where('email', $email)->exists()) {
            $this->info('Initial administrator already exists; password was not changed.');

            return self::SUCCESS;
        }
        User::create(['name' => $name, 'email' => $email, 'email_verified_at' => now(), 'password' => Hash::make($password), 'role' => 'system_admin']);
        $this->info('Initial administrator created. Remove PROVISION_ADMIN_PASSWORD after confirming access.');

        return self::SUCCESS;
    }
}
