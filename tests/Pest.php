<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the application; unit tests deliberately do not, so that
| logic which does not need the framework is proven not to need it.
|
*/

pest()->extend(TestCase::class)->in('Feature');
