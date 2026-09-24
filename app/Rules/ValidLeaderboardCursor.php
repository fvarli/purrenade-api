<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\LeaderboardWindow;
use App\Services\Leaderboards\LeaderboardCursor;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A cursor this server minted, for the window being requested (M10).
 *
 * Reported as `cursor_invalid` (`ValidationCodes`), one code for every way a
 * cursor can be unusable — undecodable, tampered, another version, another
 * window — so a client needs one recovery: start again from the first page.
 * When the window itself is invalid, that is the error reported, not this.
 */
final class ValidLeaderboardCursor implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly LeaderboardCursor $cursors) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $window = is_string($this->data['window'] ?? null) ? LeaderboardWindow::tryFrom($this->data['window']) : null;

        if (! $window instanceof LeaderboardWindow) {
            return;
        }

        if (! is_string($value) || $this->cursors->decode($value, $window) === null) {
            $fail('The cursor is not valid for this leaderboard. Start again from the first page.');
        }
    }
}
