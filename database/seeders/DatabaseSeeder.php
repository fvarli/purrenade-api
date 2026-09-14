<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\DisplayName;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Local and test convenience. **Never run this in production.**
 *
 * It creates one account whose password is a constant in
 * `Database\Factories\UserFactory`, already marked verified. That is a fine
 * trade on a developer's machine, where the alternative is registering by hand
 * before every experiment. On a real database it is a published credential on a
 * verified account, and nothing in the framework would stop it: `db:seed`
 * prompts for confirmation in production and that prompt is the only guard.
 *
 * So the production bootstrap never seeds. An administrator is created by
 * registering through the public flow and then running
 * `purrenade:admin:promote` — see `docs/architecture/operations.md`. No
 * operator procedure in this repository invokes a seeder, deliberately.
 *
 * The column written below is `display_name`. It was `name` until the auth
 * migration renamed it, and this file was not updated — factories build the
 * model inside `Model::unguarded()`, so the stale key was not blocked by
 * `$guarded` but carried into the INSERT, where PostgreSQL rejected it as an
 * undefined column. The seeder could not run at all. Corrected at M7.1, which
 * is also why `DatabaseSeederTest` now holds it to the schema.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // `TestUser`, not `Test User`: `DisplayName::PATTERN` permits letters,
        // digits, `_`, `.` and `-` and no spaces, so the spaced form would be a
        // display name the API itself would refuse to create — the same trap
        // `UserFactory` documents for Faker's names.
        $displayName = 'TestUser';

        User::factory()->create([
            'display_name' => $displayName,
            'display_name_normalized' => DisplayName::normalize($displayName),
            'email' => 'test@example.com',
        ]);
    }
}
