<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SplFileObject;

class ValidateLegacyCsv extends Command
{
    protected $signature = 'kalinga:validate-legacy-csv {source : Directory containing exported clients.csv, reports.csv, and cases.csv}';

    protected $description = 'Dry-run validation for legacy Kalinga CSV exports; this command never writes to the database.';

    private const REQUIRED = [
        'clients.csv' => ['first_name', 'last_name'],
        'reports.csv' => ['reference_no', 'narrative', 'priority'],
        'cases.csv' => ['case_number', 'status', 'priority'],
    ];

    public function handle(): int
    {
        $source = rtrim((string) $this->argument('source'), DIRECTORY_SEPARATOR);
        if (!is_dir($source)) {
            $this->error('Source directory does not exist: ' . $source);

            return self::FAILURE;
        }
        $valid = true;
        foreach (self::REQUIRED as $filename => $columns) {
            $path = $source . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) {
                $this->error("Missing required export: {$filename}");
                $valid = false;

                continue;
            }
            $csv = new SplFileObject($path, 'r');
            $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
            $headers = array_map(fn ($value) => strtolower(trim((string) $value)), $csv->fgetcsv() ?: []);
            $missing = array_values(array_diff($columns, $headers));
            if ($missing) {
                $this->error("{$filename}: missing " . implode(', ', $missing));
                $valid = false;

                continue;
            }
            $rows = 0;
            while (!$csv->eof()) {
                $row = $csv->fgetcsv();
                if ($row && $row !== [null]) {
                    $rows++;
                }
            }
            $this->info("{$filename}: {$rows} data row(s), required columns present.");
        }
        if (!$valid) {
            $this->warn('Validation failed. Correct the export/mapping before any import is attempted.');

            return self::FAILURE;
        }
        $this->info('Validation passed. No records were read into or written to kalinga_is.');

        return self::SUCCESS;
    }
}
