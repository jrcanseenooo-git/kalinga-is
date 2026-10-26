<?php

namespace App\Support;

/**
 * How a child's name appears in shared views and forms. Same rules as
 * resources/js/utils/clientName.ts.
 *
 * masked(): "John Santos Dela Cruz" -> "J**S***D**UZ".
 */
final class ClientName
{
    public static function masked(?string $first, ?string $middle, ?string $last): string
    {
        $letters = fn (?string $value) => preg_replace('/[^\p{L}]/u', '', (string) $value) ?? '';
        [$first, $middle, $last] = [$letters($first), $letters($middle), $letters($last)];
        $name = ($first !== '' ? mb_substr($first, 0, 1) . '**' : '')
            . ($middle !== '' ? mb_substr($middle, 0, 1) . '***' : '')
            . ($last !== '' ? mb_substr($last, 0, 1) . '**' . (mb_strlen($last) > 2 ? mb_substr($last, -2) : '') : '');

        return $name === '' ? 'Not recorded' : mb_strtoupper($name);
    }

    /** Alias when one is recorded, otherwise the masked name. Never the full name. */
    public static function secured(object $client): string
    {
        $alias = trim((string) ($client->alias ?? ''));

        return $alias !== '' ? $alias : self::masked($client->first_name ?? null, $client->middle_name ?? null, $client->last_name ?? null);
    }
}
