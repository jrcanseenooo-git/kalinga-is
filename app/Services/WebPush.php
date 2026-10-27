<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Phone notifications through the browser push service (Web Push, VAPID).
 *
 * Pushes are sent WITHOUT a payload: the push service (Google, Mozilla,
 * Apple) only learns that "something is new". The service worker then asks
 * Kalinga for the user's latest notifications over the signed-in session and
 * shows them. A phone that is offline receives the push when it reconnects
 * (the push service keeps it for TTL seconds); the bell also shows every
 * notification on the next page load.
 *
 * Keys: VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY (base64url, raw P-256 keys),
 * created with `php artisan kalinga:vapid-keys`. Without keys, phone
 * notifications are off and in-app notifications still work.
 */
final class WebPush
{
    public const TTL = 86400;

    public static function publicKey(): ?string
    {
        return config('services.webpush.public_key') ?: null;
    }

    public static function enabled(): bool
    {
        return self::publicKey() && config('services.webpush.private_key');
    }

    /** Wakes every registered phone of the user; drops subscriptions the push service says are gone. */
    public static function notify(int $userId): void
    {
        if (!self::enabled()) {
            return;
        }
        foreach (DB::table('push_subscriptions')->where('user_id', $userId)->get(['id', 'endpoint']) as $subscription) {
            try {
                $response = Http::timeout(5)->withHeaders(self::headers($subscription->endpoint) + ['TTL' => (string) self::TTL, 'Urgency' => 'high', 'Content-Length' => '0'])
                    ->withBody('', 'application/octet-stream')->post($subscription->endpoint);
                if (in_array($response->status(), [404, 410], true)) {
                    DB::table('push_subscriptions')->where('id', $subscription->id)->delete();
                } elseif ($response->successful()) {
                    DB::table('push_subscriptions')->where('id', $subscription->id)->update(['last_sent_at' => now()]);
                }
            } catch (Throwable) {
                // A push is best-effort; the notification stays in the bell.
            }
        }
    }

    /** VAPID authorization for one push endpoint (RFC 8292). */
    public static function headers(string $endpoint): array
    {
        $parts = parse_url($endpoint);
        $claims = ['aud' => $parts['scheme'] . '://' . $parts['host'], 'exp' => time() + 12 * 3600, 'sub' => config('services.webpush.subject', 'mailto:no-reply@kalinga.local')];
        $input = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . self::b64(json_encode($claims));
        openssl_sign($input, $der, self::privateKeyPem(), OPENSSL_ALGO_SHA256);
        $jwt = $input . '.' . self::b64(self::derToRaw($der));

        return ['Authorization' => 'vapid t=' . $jwt . ', k=' . self::publicKey()];
    }

    /** New key pair, base64url encoded (public: 65-byte uncompressed point; private: 32-byte scalar). */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key)['ec'];
        $pad = fn (string $value) => str_pad($value, 32, "\0", STR_PAD_LEFT);

        return ['public' => self::b64("\x04" . $pad($details['x']) . $pad($details['y'])), 'private' => self::b64($pad($details['d']))];
    }

    private static function privateKeyPem(): string
    {
        $d = self::unb64((string) config('services.webpush.private_key'));
        $public = self::unb64((string) self::publicKey());
        // SEC1 ECPrivateKey for prime256v1.
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $d . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00" . $public;

        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /** ECDSA signature: DER sequence of two integers to the 64-byte r||s form JWS uses. */
    private static function derToRaw(string $der): string
    {
        $offset = 2;
        $read = function () use ($der, &$offset) {
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;

            return str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
        };

        return $read() . $read();
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function unb64(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
