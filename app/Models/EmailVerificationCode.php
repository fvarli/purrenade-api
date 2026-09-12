<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property string $email
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class EmailVerificationCode extends Model
{
    /**
     * The number of wrong guesses a single code tolerates before it is destroyed.
     *
     * Six digits is a keyspace of one million, so an unlimited-attempt code is
     * guessable in minutes. Five is generous for a human mistyping a code and
     * useless to an attacker: it caps the chance of guessing one code at
     * 5 in 1,000,000, and exhausting it forces a resend, which is separately
     * rate-limited and cooldown-gated.
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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS;
    }

    /**
     * Is this challenge still capable of verifying the given address?
     *
     * The address is part of the check because a code is issued *for* an address.
     * If the account's email changed after the code was sent, the old code must
     * not verify the new address — otherwise a player could have a code
     * delivered to a mailbox they control and then point the account elsewhere.
     */
    public function isUsableFor(string $email): bool
    {
        return ! $this->isExpired()
            && $this->hasAttemptsLeft()
            && hash_equals($this->email, $email);
    }
}
