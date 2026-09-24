<?php

declare(strict_types=1);

namespace App\Services\Leaderboards;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The weekly leaderboard calendar (LB-1, APPROVED): weeks begin **Monday 00:00
 * Europe/Istanbul**, and a run belongs to the week containing its
 * server-recorded `started_at`.
 *
 * A week is identified by its local Monday as a `Y-m-d` date — the
 * `leaderboard_weekly.week_start` key. Instants are UTC everywhere else.
 *
 * The zone is applied as an **IANA zone with its full rule history**, never as
 * a fixed `+03:00`: Istanbul observed daylight saving time until 2016, and any
 * future rule change arrives through tzdata, not through this code. Calendar
 * arithmetic is done in the local zone, so a week that contains a transition is
 * as long as the wall clock makes it, not a fixed 7 × 86 400 seconds.
 *
 * PostgreSQL computes the same key in the migrations as
 * `date_trunc('week', (started_at AT TIME ZONE 'UTC') AT TIME ZONE <zone>)::date`;
 * `LeaderboardWeekTest` proves the two agree.
 *
 * Monday is passed explicitly and never taken from the Carbon locale, whose
 * notion of the first weekday varies.
 */
final class LeaderboardWeek
{
    private readonly DateTimeZone $zone;

    public function __construct(string $timezone)
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public static function fromConfig(): self
    {
        return new self((string) config('leaderboards.week_timezone'));
    }

    public function timezone(): string
    {
        return $this->zone->getName();
    }

    /** The `week_start` key of the week containing this instant. */
    public function weekStartFor(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)
            ->setTimezone($this->zone)
            ->startOfWeek(CarbonInterface::MONDAY)
            ->toDateString();
    }

    /**
     * The UTC bounds of a week, `[starts_at, ends_at)`: local Monday 00:00 and
     * the next local Monday 00:00, each converted to UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodFor(string $weekStart): array
    {
        $start = $this->localMonday($weekStart);

        return [$start->utc(), $start->addWeek()->utc()];
    }

    /**
     * Is this a valid week key — a real `Y-m-d` date that falls on a Monday?
     */
    public function isWeekStart(string $weekStart): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart) !== 1) {
            return false;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $weekStart, $this->zone);

        return $date instanceof CarbonImmutable
            && $date->toDateString() === $weekStart
            && $date->dayOfWeekIso === 1;
    }

    private function localMonday(string $weekStart): CarbonImmutable
    {
        if (! $this->isWeekStart($weekStart)) {
            throw new InvalidArgumentException('Not a leaderboard week start.');
        }

        /** @var CarbonImmutable $date */
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $weekStart, $this->zone);

        return $date->startOfDay();
    }
}
