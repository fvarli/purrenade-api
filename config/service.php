<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public service descriptor
    |--------------------------------------------------------------------------
    |
    | The small set of facts this service is willing to state about itself to an
    | unauthenticated caller — used by the root route and the health endpoint.
    | One source, so the two can never drift apart or contradict each other.
    |
    | APP_NAME is deliberately not reused here: it names the product, and this
    | names the service within it.
    |
    */

    'name' => 'Purrenade API',

    'api_base' => '/api/v1',

    'health_path' => '/api/v1/health',

    /*
    |--------------------------------------------------------------------------
    | Public documentation URL
    |--------------------------------------------------------------------------
    |
    | null until published documentation actually exists. The OpenAPI document
    | in docs/api/ is a draft contract in a private repository, not a public URL,
    | and pointing callers at a link that 404s is worse than admitting there is
    | none yet.
    |
    */

    'documentation_url' => null,

    /*
    |--------------------------------------------------------------------------
    | Released version
    |--------------------------------------------------------------------------
    |
    | Deliberately absent, and absent from the health payload with it.
    |
    | There is no source of truth to read: composer.json carries no `version`,
    | and CHANGELOG.md states the project has no released versions. The only
    | number available would be the framework's, via app()->version() — which is
    | not this service's version and which docs/architecture/observability.md §7
    | forbids disclosing to unauthenticated callers anyway.
    |
    | When releases begin, define it here and add it to the health response and
    | to the OpenAPI contract in the same change.
    |
    */

];
