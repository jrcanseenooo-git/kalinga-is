<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class DatabaseFailurePrivacyTest extends TestCase
{
    private function logger(): AbstractLogger
    {
        $logger = new class extends AbstractLogger
        {
            public array $entries = [];

            public function log($level, $message, array $context = []): void
            {
                $this->entries[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $this->app->instance(LoggerInterface::class, $logger);

        return $logger;
    }

    public function test_database_diagnostics_never_serialize_query_bindings_or_exception_objects(): void
    {
        $logger = $this->logger();
        $secret = 'SYNTHETIC-DATABASE-PRIVATE-MARKER';
        $previous = new \PDOException('Synthetic storage failure ' . $secret);
        $previous->errorInfo = ['23000', 19, 'Synthetic detail ' . $secret];
        report(new QueryException('sqlite', 'insert into case_closure_records (summary) values (?)', [$secret], $previous));
        $this->assertCount(1, $logger->entries);
        $entry = $logger->entries[0];
        $this->assertStringNotContainsString($secret, $entry['message']);
        $this->assertArrayNotHasKey('exception', $entry['context']);
        $this->assertSame('23000', $entry['context']['sql_state'] ?? null);
        $this->assertSame('insert', $entry['context']['operation'] ?? null);
    }

    public function test_logging_failure_does_not_fall_back_to_raw_exception_reporting(): void
    {
        $logger = new class extends AbstractLogger
        {
            public int $calls = 0;

            public function log($level, $message, array $context = []): void
            {
                $this->calls++;
                throw new \RuntimeException('Synthetic logger unavailable.');
            }
        };
        $this->app->instance(LoggerInterface::class, $logger);
        report(new QueryException('synthetic-private-connection', 'insert into case_closure_records (summary) values (?)', ['SYNTHETIC-PRIVATE-BINDING'], new \PDOException('Synthetic storage failure.')));
        $this->assertSame(1, $logger->calls);
    }

    public static function responseModes(): array
    {
        return [[true, true], [true, false], [false, true], [false, false]];
    }

    #[DataProvider('responseModes')]
    public function test_database_failure_response_is_generic_private_and_safe_even_with_debug_enabled(bool $debug, bool $json): void
    {
        config(['app.debug' => $debug]);
        $this->logger();
        Route::middleware('web')->get('/synthetic-database-error', fn () => throw new \PDOException('SYNTHETIC-RESPONSE-PRIVATE-MARKER'));
        $response = $json ? $this->getJson('/synthetic-database-error') : $this->get('/synthetic-database-error');
        $response->assertStatus(500)->assertDontSee('SYNTHETIC-RESPONSE-PRIVATE-MARKER')->assertDontSee('PDOException');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        if ($json) {
            $response->assertJsonPath('message', 'Your request could not be completed. Refresh the records before retrying.');
        }
    }
}
