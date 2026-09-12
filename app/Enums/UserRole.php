<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two roles in the approved surface.
 *
 * A backed enum and one column, not a role/permission package. There are two
 * roles, neither is created at runtime, and no permission is assigned
 * independently of a role — so a generalised RBAC schema would add three tables
 * and a cache layer to express a boolean. `docs/security/authorization-and-roles.md`
 * §2 already says as much.
 *
 * What does the real work is not this enum but the policy layer: nearly every
 * authorization question in this product is "does this resource belong to this
 * caller", which no role model answers.
 */
enum UserRole: string
{
    case Player = 'player';
    case Admin = 'admin';

    /**
     * Is two-factor authentication mandatory for this role?
     *
     * Asked here, in one place, so that the answer cannot diverge between the
     * middleware that enforces it and the endpoint that refuses to disable it.
     */
    public function requiresTwoFactor(): bool
    {
        return $this === self::Admin;
    }
}
