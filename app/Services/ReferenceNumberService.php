<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Reserves human-readable casework numbers without relying on a row count.
 *
 * Call this inside the transaction that creates the corresponding record. The
 * sequence row is locked while a number is reserved, so simultaneous field
 * submissions cannot be assigned the same reference.
 */
class ReferenceNumberService
{
    public function nextReport(): string
    {
        return $this->next('RPT', 'reports', 'reference_no');
    }

    public function nextCase(): string
    {
        return $this->next('KLG', 'cases', 'case_number');
    }

    private function next(string $prefix, string $table, string $column): string
    {
        $year = (int) now()->year;

        DB::table('reference_sequences')->insertOrIgnore([
            'prefix' => $prefix,
            'year' => $year,
            'last_number' => 0,
            'initialized_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('reference_sequences')
            ->where(['prefix' => $prefix, 'year' => $year])
            ->lockForUpdate()
            ->first();

        if (is_null($sequence->initialized_at)) {
            $pattern = '/^' . preg_quote("{$prefix}-{$year}-", '/') . '(\d+)$/';
            $existingMaximum = DB::table($table)
                ->where($column, 'like', "{$prefix}-{$year}-%")
                ->pluck($column)
                ->map(function (string $value) use ($pattern): int {
                    preg_match($pattern, $value, $matches);

                    return isset($matches[1]) ? (int) $matches[1] : 0;
                })
                ->max() ?? 0;

            DB::table('reference_sequences')->where('id', $sequence->id)->update([
                'last_number' => $existingMaximum,
                'initialized_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence->last_number = $existingMaximum;
        }

        $next = (int) $sequence->last_number + 1;
        DB::table('reference_sequences')->where('id', $sequence->id)->update([
            'last_number' => $next,
            'updated_at' => now(),
        ]);

        return sprintf('%s-%d-%05d', $prefix, $year, $next);
    }
}
