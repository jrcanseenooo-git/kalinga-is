<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\KalingaDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Loads the fictional presentation data on an online demo server.
 *
 * Runs only when DEMO_SEED=true. The four demo accounts get the password in
 * DEMO_PASSWORD (never the local "password") and, when DEMO_ACCOUNT_EMAIL is
 * set (for example name@gmail.com), the addresses name+worker@gmail.com,
 * name+supervisor@..., name+cpu@... and name+admin@... so the sign-in codes
 * for every role arrive in one inbox. Runs once: if the demo cases already
 * exist, only the passwords are refreshed.
 *
 * Fictional data only. Turn DEMO_SEED off and use a fresh database before any
 * real case is recorded.
 */
class SeedDemoData extends Command
{
    protected $signature = 'kalinga:seed-demo';

    protected $description = 'Loads fictional demo cases and four demo accounts when DEMO_SEED=true.';

    private const ACCOUNTS = [
        'worker' => ['name' => 'Kalinga Social Worker', 'email' => 'worker@kalinga.test', 'role' => 'social_worker'],
        'supervisor' => ['name' => 'Kalinga Supervisor', 'email' => 'supervisor@kalinga.test', 'role' => 'social_worker'],
        'cpu' => ['name' => 'Kalinga CPU Member', 'email' => 'cpu@kalinga.test', 'role' => 'cpu_member', 'cpu_discipline' => 'social_worker_cpo'],
        'admin' => ['name' => 'Kalinga System Admin', 'email' => 'admin@kalinga.test', 'role' => 'system_admin'],
    ];

    public function handle(): int
    {
        if (!filter_var(env('DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->info('DEMO_SEED is not true; no demo data loaded.');

            return self::SUCCESS;
        }

        $password = (string) env('DEMO_PASSWORD', '');
        if (strlen($password) < 12) {
            $this->error('DEMO_PASSWORD must be at least 12 characters; no demo data loaded.');

            return self::FAILURE;
        }

        $inbox = trim((string) env('DEMO_ACCOUNT_EMAIL', ''));
        if ($inbox !== '' && !filter_var($inbox, FILTER_VALIDATE_EMAIL)) {
            $this->error('DEMO_ACCOUNT_EMAIL is not a valid email address; no demo data loaded.');

            return self::FAILURE;
        }

        $alreadySeeded = DB::table('cases')->where('case_number', 'like', 'DEMO-%')->exists();

        DB::transaction(function () use ($password, $inbox, $alreadySeeded) {
            foreach (self::ACCOUNTS as $key => $account) {
                $target = $this->addressFor($key, $inbox) ?? $account['email'];
                $user = User::where('email', $target)->first() ?? User::where('email', $account['email'])->first();
                if (!$user) {
                    $user = User::create($account + ['email_verified_at' => now(), 'password' => Hash::make($password)]);
                }
                // Always replace the password, so the local "password" never reaches a server.
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            if (!$alreadySeeded) {
                (new KalingaDemoSeeder)->setContainer(app())->setCommand($this)->run();
            }

            // Move the accounts to the presenter's inbox after the seeder has linked them.
            foreach (self::ACCOUNTS as $key => $account) {
                $target = $this->addressFor($key, $inbox);
                if ($target && !User::where('email', $target)->exists()) {
                    User::where('email', $account['email'])->update(['email' => $target]);
                }
            }
        });

        $this->info($alreadySeeded ? 'Demo data already present; demo passwords refreshed.' : 'Fictional demo data loaded.');

        return self::SUCCESS;
    }

    private function addressFor(string $key, string $inbox): ?string
    {
        if ($inbox === '') {
            return null;
        }
        [$local, $domain] = explode('@', $inbox, 2);

        return "{$local}+{$key}@{$domain}";
    }
}
