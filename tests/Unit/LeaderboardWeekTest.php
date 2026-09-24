<?php

declare(strict_types=1);

use App\Services\Leaderboards\LeaderboardWeek;
use Carbon\CarbonImmutable;

/**
 * The weekly calendar contract (LB-1): Monday 00:00 **Europe/Istanbul**,
 * applied with the IANA zone's rule history — never a fixed `+03:00`.
 *
 * Every expected instant below was read from the tzdata transition table
 * while the fixtures were written (PHP and PostgreSQL agree on each), not
 * derived from the code under test:
 *
 * - since 2016-09-07 Istanbul is `+03` all year;
 * - 2015-01: `EET`, UTC+2, so the week starts Sunday 22:00Z;
 * - 2015-03-29 01:00Z: `EEST` begins, so the week of 2015-03-23 starts at
 *   +2 and ends at +3 — 167 hours long;
 * - 2015-11-08 01:00Z: `EET` returns (delayed that year for an election), so
 *   the week of 2015-11-02 is 169 hours long.
 *
 * A fixed-offset implementation fails every pre-2016 case.
 */
function istanbulWeek(): LeaderboardWeek
{
    return new LeaderboardWeek('Europe/Istanbul');
}

function utc(string $instant): CarbonImmutable
{
    return CarbonImmutable::parse($instant, 'UTC');
}

/**
 * [week_start, UTC start, UTC end, hours].
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
 */
function weekFixtures(): array
{
    return [
        'current (+03)' => ['2026-09-21', '2026-09-20 21:00:00.000', '2026-09-27 21:00:00.000', 168],
        'year rollover' => ['2026-12-28', '2026-12-27 21:00:00.000', '2027-01-03 21:00:00.000', 168],
        'first week of 2027' => ['2027-01-04', '2027-01-03 21:00:00.000', '2027-01-10 21:00:00.000', 168],
        'leap week 2028' => ['2028-02-28', '2028-02-27 21:00:00.000', '2028-03-05 21:00:00.000', 168],
        'winter 2015 (+02)' => ['2015-01-12', '2015-01-11 22:00:00.000', '2015-01-18 22:00:00.000', 168],
        'spring DST 2015 (+02 → +03)' => ['2015-03-23', '2015-03-22 22:00:00.000', '2015-03-29 21:00:00.000', 167],
        'autumn 2015 (+03 → +02)' => ['2015-11-02', '2015-11-01 21:00:00.000', '2015-11-08 22:00:00.000', 169],
    ];
}

it('bounds each week by local Monday 00:00, in UTC, from the zone rules', function (string $weekStart, string $start, string $end, int $hours): void {
    [$startsAt, $endsAt] = istanbulWeek()->periodFor($weekStart);

    expect($startsAt->format('Y-m-d H:i:s.v'))->toBe($start)
        ->and($startsAt->getTimezone()->getName())->toBe('UTC')
        ->and($endsAt->format('Y-m-d H:i:s.v'))->toBe($end)
        ->and((int) round(($endsAt->getTimestamp() - $startsAt->getTimestamp()) / 3600))->toBe($hours);
})->with(weekFixtures());

it('puts the last millisecond of local Sunday in the old week and local Monday 00:00 in the new', function (string $weekStart, string $start, string $end): void {
    $week = istanbulWeek();

    expect($week->weekStartFor(utc($start)))->toBe($weekStart)
        ->and($week->weekStartFor(utc($start)->subMillisecond()))->not->toBe($weekStart)
        ->and($week->weekStartFor(utc($start)->subMillisecond()))->toBe(CarbonImmutable::parse($weekStart)->subWeek()->toDateString())
        ->and($week->weekStartFor(utc($end)->subMillisecond()))->toBe($weekStart)
        ->and($week->weekStartFor(utc($end)))->toBe(CarbonImmutable::parse($weekStart)->addWeek()->toDateString());
})->with(weekFixtures());

it('attributes the run-start examples the product documents', function (): void {
    $week = istanbulWeek();

    // Sunday 23:58 local, the run in leaderboards.md §3.2.
    expect($week->weekStartFor(utc('2026-09-27 20:58:00.000')))->toBe('2026-09-21')
        // Monday 00:03 local.
        ->and($week->weekStartFor(utc('2026-09-27 21:03:00.000')))->toBe('2026-09-28')
        // Thursday 2026-12-31 and Friday 2027-01-01 share a week that starts in 2026.
        ->and($week->weekStartFor(utc('2026-12-31 12:00:00.000')))->toBe('2026-12-28')
        ->and($week->weekStartFor(utc('2027-01-01 12:00:00.000')))->toBe('2026-12-28')
        // 29 February 2028.
        ->and($week->weekStartFor(utc('2028-02-29 12:00:00.000')))->toBe('2028-02-28');
});

it('is not a fixed +03:00 offset', function (): void {
    // 2015-01-11 21:30Z is Sunday 23:30 at +02 but Monday 00:30 at a fixed +03.
    expect(istanbulWeek()->weekStartFor(utc('2015-01-11 21:30:00.000')))->toBe('2015-01-05');
});

it('uses Monday whatever the Carbon locale says the week starts on', function (string $locale): void {
    $previous = CarbonImmutable::getLocale();
    CarbonImmutable::setLocale($locale);

    try {
        // A Sunday afternoon, local: under a Sunday-first locale a default
        // startOfWeek() would answer the same day.
        expect(istanbulWeek()->weekStartFor(utc('2026-09-27 12:00:00.000')))->toBe('2026-09-21')
            ->and(istanbulWeek()->weekStartFor(utc('2026-09-26 12:00:00.000')))->toBe('2026-09-21');
    } finally {
        CarbonImmutable::setLocale($previous);
    }
})->with(['en_US', 'ar', 'tr', 'es']);

it('ignores the PHP default timezone', function (): void {
    $previous = date_default_timezone_get();
    date_default_timezone_set('America/Los_Angeles');

    try {
        expect(istanbulWeek()->weekStartFor(utc('2026-09-27 21:00:00.000')))->toBe('2026-09-28');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('recognises only real Mondays as week keys', function (string $key, bool $valid): void {
    expect(istanbulWeek()->isWeekStart($key))->toBe($valid);
})->with([
    ['2026-09-21', true],
    ['2028-02-28', true],
    ['2026-09-22', false],
    ['2026-02-30', false],
    ['2026-9-21', false],
    ['2026-09-21T00:00', false],
    ['', false],
    ['garbage', false],
]);

it('refuses a period for anything but a week key', function (): void {
    istanbulWeek()->periodFor('2026-09-22');
})->throws(InvalidArgumentException::class);
