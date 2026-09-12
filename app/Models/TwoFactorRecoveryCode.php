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
 * @property Carbon|null $used_at
 */
class TwoFactorRecoveryCode extends Model
{
    /**
     * How many codes an enrolment issues.
     *
     * Eight is enough to survive several lost-authenticator events without the
     * player treating the list as disposable, and few enough that printing or
     * storing them is realistic. Each one is a full bypass of the second factor,
     * so more is not better.
     */
    public const COUNT = 8;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
