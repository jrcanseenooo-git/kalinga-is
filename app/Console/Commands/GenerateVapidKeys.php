<?php

namespace App\Console\Commands;

use App\Services\WebPush;
use Illuminate\Console\Command;

/** Prints a new VAPID key pair for phone notifications; the owner puts them in the environment. */
class GenerateVapidKeys extends Command
{
    protected $signature = 'kalinga:vapid-keys';

    protected $description = 'Create the key pair for phone (Web Push) notifications';

    public function handle(): int
    {
        $keys = WebPush::generateKeys();
        $this->line('Add these to the environment (Render > Environment), then redeploy:');
        $this->line('VAPID_PUBLIC_KEY=' . $keys['public']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['private']);
        $this->warn('Keep the private key secret. Changing the keys turns off notifications on every phone until users turn them on again.');

        return self::SUCCESS;
    }
}
