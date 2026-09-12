<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Ensure the test schema exists before anything tries to migrate into it.
     *
     * The suite runs in its own PostgreSQL schema (`DB_SEARCH_PATH` in
     * phpunit.xml) so that `RefreshDatabase` cannot destroy the local
     * development database — including the account used for manual acceptance.
     *
     * A schema rather than a second database because the application role owns
     * its database and may create schemas in it, but deliberately has no
     * CREATEDB privilege. So the isolation costs nothing: no superuser, no sudo,
     * and no setup step a new developer has to be told about.
     *
     * Hooked into `setUpTraits()` rather than `setUp()`: the framework's
     * `setUp()` builds the application and *then* calls this, which is the only
     * window where configuration is available and `RefreshDatabase` has not yet
     * tried to migrate. Running it before `parent::setUp()` would run it with no
     * facade root bound at all.
     */
    protected function setUpTraits(): array
    {
        $this->ensureTestSchemaExists();

        return parent::setUpTraits();
    }

    private function ensureTestSchemaExists(): void
    {
        static $created = false;

        if ($created) {
            return;
        }

        $searchPath = (string) Config::string('database.connections.pgsql.search_path', 'public');

        if ($searchPath === '' || $searchPath === 'public') {
            $created = true;

            return;
        }

        // A bootstrap connection on `public`, used once per process purely to
        // create the schema the real connection will then use.
        Config::set('database.connections.pgsql_bootstrap', [
            ...Config::array('database.connections.pgsql'),
            'search_path' => 'public',
        ]);

        DB::connection('pgsql_bootstrap')->statement(
            'CREATE SCHEMA IF NOT EXISTS '.$this->quoteIdentifier($searchPath)
        );

        DB::purge('pgsql_bootstrap');

        $created = true;
    }

    /**
     * Quote a schema name for interpolation.
     *
     * The value comes from phpunit.xml rather than from a request, so this is
     * belt and braces — but a schema name cannot be a bound parameter in DDL,
     * and an unquoted identifier concatenated into a DDL statement has the shape
     * of an injection whether or not this particular input is trusted.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
