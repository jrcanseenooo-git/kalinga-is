<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyCsvValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_validator_accepts_expected_export_shape_without_writing_database(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kalinga-csv-' . uniqid();
        mkdir($directory);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'clients.csv', "first_name,last_name,birthdate\nAna,Cruz,2015-01-01\n");
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'reports.csv', "reference_no,narrative,priority\nRPT-1,Concern,high\n");
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'cases.csv', "case_number,status,priority\nKLG-1,OPEN,high\n");

        $before = \DB::table('clients')->count();
        $this->artisan('kalinga:validate-legacy-csv', ['source' => $directory])->assertSuccessful();
        $this->assertSame($before, \DB::table('clients')->count());

        foreach (['clients.csv', 'reports.csv', 'cases.csv'] as $file) {
            unlink($directory . DIRECTORY_SEPARATOR . $file);
        }
        rmdir($directory);
    }
}
