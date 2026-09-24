<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\LeaderboardWindow;
use App\Rules\ValidLeaderboardCursor;
use App\Services\Leaderboards\LeaderboardCursor;
use App\Services\Leaderboards\LeaderboardPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /leaderboards — query shape (M10).
 *
 * - `window`: required, `weekly` or `all_time`.
 * - `limit`: optional, a plain decimal integer 1–100 (default 25).
 * - `cursor`: optional, at most 512 characters of `[A-Za-z0-9_-]`, and one
 *   this server minted for the same window — else `cursor_invalid`.
 *
 * Every rule bails at its first failure, so each field reports one code.
 * Unknown query members are ignored.
 */
final class LeaderboardRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('leaderboards.max_limit');

        return [
            'window' => ['bail', 'required', 'string', Rule::in(array_column(LeaderboardWindow::cases(), 'value'))],
            'limit' => ['bail', 'nullable', 'integer', "between:1,{$max}", 'regex:/^[1-9][0-9]*\z/'],
            'cursor' => [
                'bail', 'nullable', 'string', 'max:'.LeaderboardCursor::MAX_LENGTH, 'regex:/^[A-Za-z0-9_-]+\z/',
                app(ValidLeaderboardCursor::class),
            ],
        ];
    }

    public function window(): LeaderboardWindow
    {
        return LeaderboardWindow::from((string) $this->validated('window'));
    }

    public function limit(): int
    {
        $limit = $this->validated('limit');

        return $limit === null ? (int) config('leaderboards.default_limit') : (int) $limit;
    }

    /** The validated cursor's position, or null on the first page. */
    public function cursorPosition(): ?LeaderboardPosition
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) ? app(LeaderboardCursor::class)->decode($cursor, $this->window()) : null;
    }
}
