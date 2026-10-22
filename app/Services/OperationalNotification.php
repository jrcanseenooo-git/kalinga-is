<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Stores inbox alerts with operational metadata only; never case narratives or client identity. */
class OperationalNotification
{
    public function send(int $userId, string $type, string $title, string $body, ?string $actionUrl = null): void
    {
        DB::table('notifications')->insert([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'action_url' => $actionUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
