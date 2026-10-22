<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseApiLockdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_lockdown_is_a_no_op_outside_postgresql(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('Covered by the PostgreSQL test below.');
        }

        $this->artisan('kalinga:lock-down-database-api')->expectsOutputToContain('Not PostgreSQL')->assertSuccessful();
    }

    public function test_every_table_gets_row_level_security_and_the_application_still_reads_and_writes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row level security exists only on PostgreSQL.');
        }

        $this->artisan('kalinga:lock-down-database-api')->assertSuccessful();

        $open = DB::select("select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'r' and not c.relrowsecurity");
        $this->assertSame([], $open, 'Tables without row level security: ' . implode(', ', array_column($open, 'relname')));

        // The application connects as the table owner, which row level security does not restrict.
        DB::table('reference_data')->insert(['set_name' => 'lockdown_check', 'code' => 'ok', 'label' => 'OK', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, DB::table('reference_data')->where('set_name', 'lockdown_check')->count());

        // Running it again (every deployment) is safe.
        $this->artisan('kalinga:lock-down-database-api')->assertSuccessful();
    }
}
