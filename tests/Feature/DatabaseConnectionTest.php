<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// The single most valuable thing to prove at bootstrap: that Laravel reaches
// the native PostgreSQL server, as the least-privilege application role, with
// the schema its migrations created.
//
// Local development runs PostgreSQL 16.x while the project target is 18.x —
// an intentional, documented gap (docs/architecture/versions-and-runtime.md).
// This test asserts the *driver and connection*, not a server version, so it
// stays correct across that upgrade.

it('connects to PostgreSQL as the application role', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $row = DB::selectOne('select current_user, current_database(), version()');

    expect($row->current_user)->not->toBe('postgres')  // never the superuser
        ->and($row->version)->toContain('PostgreSQL');
});

it('has applied the framework migrations', function (): void {
    // Proves migrations ran against this connection, without asserting any
    // product-domain schema — none exists yet, and none belongs to M1.
    expect(DB::getSchemaBuilder()->hasTable('migrations'))->toBeTrue();
});
