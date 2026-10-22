<?php

namespace App\Services;

/** Keeps the login UI and OAuth controller aligned on one safe configuration check. */
final class GoogleOAuthConfiguration
{
    public function enabled(): bool
    {
        $clientId = trim((string) config('services.google.client_id'));
        $clientSecret = trim((string) config('services.google.client_secret'));
        $redirect = $this->redirectUri();
        $parts = parse_url($redirect);

        if ($clientId === '' || $clientSecret === '' || !filter_var($redirect, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';

        // Plain HTTP is acceptable only for explicit local development hosts.
        return (($scheme === 'https') || in_array($host, ['localhost', '127.0.0.1'], true))
            && ($parts['path'] ?? '') === '/auth/google/callback';
    }

    public function redirectUri(): string
    {
        return trim((string) config('services.google.redirect'));
    }
}
