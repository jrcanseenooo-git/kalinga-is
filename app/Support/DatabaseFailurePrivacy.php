<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

final class DatabaseFailurePrivacy
{
    private const MESSAGE = 'Your request could not be completed. Refresh the records before retrying.';

    /** Never pass SQL, bindings, connection details or a Throwable to a logger. */
    public static function report(\PDOException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;
        if (!is_string($state) || !preg_match('/^[A-Z0-9]{5}$/i', $state)) {
            $state = 'unknown';
        }
        $operation = 'connection_or_query';
        if ($exception instanceof QueryException && preg_match('/^\s*(select|insert|update|delete|create|alter|drop)\b/i', $exception->getSql(), $match)) {
            $operation = strtolower($match[1]);
        }
        try {
            app(LoggerInterface::class)->error('Database request failed.', ['sql_state' => $state, 'operation' => $operation]);
        } catch (\Throwable) {
            // Logging failure must not fall back to serializing the original
            // exception or disclosing its bindings through another exception.
        }

        return false;
    }

    public static function response(Request $request): Response
    {
        $headers = ['Cache-Control' => 'private, no-store, max-age=0'];
        if ($request->expectsJson() || $request->headers->has('X-Inertia')) {
            return response()->json(['message' => self::MESSAGE], 500, $headers);
        }

        return response(self::MESSAGE, 500, $headers)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
