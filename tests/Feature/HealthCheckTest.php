<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /up is what a load balancer or uptime monitor polls, so a check that passes
 * while the application cannot serve is worse than no check at all -- traffic
 * keeps arriving at an instance that fails every request.
 *
 * Laravel's stock health route returns 200 as soon as the framework boots.
 * These tests pin down that ours actually touches the database.
 *
 * No RefreshDatabase here on purpose: `select 1` needs a connection, not
 * schema, and the second test deliberately breaks the connection.
 */
class HealthCheckTest extends TestCase
{
    public function test_it_reports_healthy_when_the_database_answers(): void
    {
        $this->get('/up')->assertStatus(200);
    }

    public function test_it_fails_when_the_database_is_unreachable(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            // Nothing listens on port 1, so this refuses immediately rather
            // than hanging on a connect timeout.
            'database.connections.mysql.port' => 1,
        ]);
        DB::purge('mysql');

        $this->get('/up')->assertStatus(500);
    }
}
