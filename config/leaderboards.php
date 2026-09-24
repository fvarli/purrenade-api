<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Leaderboards (M10)
|--------------------------------------------------------------------------
|
| None of these is read from the environment, following `game_runs`: the week
| a run belongs to, and the page a player is served, must not depend on where
| the request landed.
|
*/

return [

    /*
     * The weekly boundary: Monday 00:00 in this zone (LB-1, APPROVED).
     *
     * An IANA zone name, applied with its full rule history by both PHP and
     * PostgreSQL — never a fixed `+03:00`. The migration's backfill and the
     * reconciliation name the same zone in SQL; `LeaderboardWeekTest` proves
     * the two agree.
     */
    'week_timezone' => 'Europe/Istanbul',

    /*
     * Page sizes (LB-2, APPROVED): 25 by default, never more than 100.
     */
    'default_limit' => 25,

    'max_limit' => 100,

];
