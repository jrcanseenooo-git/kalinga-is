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
 * DEMO_GOOGLE_EMAIL (optional) gives the case-worker account that exact
 * address, so "Continue with Google" can sign in to it.
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

        $google = trim((string) env('DEMO_GOOGLE_EMAIL', ''));
        if ($google !== '' && !filter_var($google, FILTER_VALIDATE_EMAIL)) {
            $this->error('DEMO_GOOGLE_EMAIL is not a valid email address; no demo data loaded.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($password, $inbox, $google, $alreadySeeded) {
            foreach (self::ACCOUNTS as $key => $account) {
                $user = $this->findAccount($key, $account['email'], $inbox, $google)
                    ?? User::create($account + ['email_verified_at' => now(), 'password' => Hash::make($password)]);
                // Always replace the password, so the local "password" never reaches a server.
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            if (!$alreadySeeded) {
                (new KalingaDemoSeeder)->setContainer(app())->setCommand($this)->run();
            }

            // Move the accounts to their final addresses after the seeder has linked them.
            foreach (self::ACCOUNTS as $key => $account) {
                $target = $this->targetFor($key, $account['email'], $inbox, $google);
                $user = $this->findAccount($key, $account['email'], $inbox, $google);
                if ($user && $user->email !== $target && !User::where('email', $target)->exists()) {
                    $user->forceFill(['email' => $target])->save();
                }
            }
        });

        $this->info($alreadySeeded ? 'Demo data already present; demo passwords refreshed.' : 'Fictional demo data loaded.');

        return self::SUCCESS;
    }

    /** The address an account should end up with: the Google address for the case worker when set. */
    private function targetFor(string $key, string $original, string $inbox, string $google): string
    {
        if ($key === 'worker' && $google !== '') {
            return $google;
        }

        return $this->addressFor($key, $inbox) ?? $original;
    }

    private function findAccount(string $key, string $original, string $inbox, string $google): ?User
    {
        foreach (array_filter([$this->targetFor($key, $original, $inbox, $google), $this->addressFor($key, $inbox), $original]) as $email) {
            if ($user = User::where('email', $email)->where('role', self::ACCOUNTS[$key]['role'])->first()) {
                return $user;
            }
        }

        return null;
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
