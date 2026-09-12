<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $device_label
 * @property int $attempts
 * @property Carbon $expires_at
 */
class TwoFactorChallenge extends Model
{
    /**
     * Minutes a pending challenge survives.
     *
     * Short on purpose: this record is the only thing standing between a correct
     * password and a session, so it should not outlive the login attempt that
     * created it. Five minutes covers finding a phone and reading a code.
     */
    public const TTL_MINUTES = 5;

    /**
     * Wrong codes tolerated on one challenge before it is destroyed.
     *
     * A TOTP code is six digits, so the same reasoning as email verification
     * applies. Exhausting this forces the player back through the password step,
     * which is itself rate-limited — so an attacker who has the password cannot
     * grind the second factor.
     */
    public const MAX_ATTEMPTS = 5;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->expires_at->isFuture()
            && $this->attempts < self::MAX_ATTEMPTS;
    }
}
