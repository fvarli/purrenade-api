<?php

declare(strict_types=1);

namespace App\Support;

use Normalizer;

/**
 * Display-name rules, in one place.
 *
 * The rules themselves are APPROVED product decisions
 * (`purrenade/docs/product/leaderboards.md` §display names): 3–20 characters,
 * Unicode letters and digits plus `_ . -`, at least one letter, no leading or
 * trailing punctuation, case-insensitive uniqueness.
 *
 * Normalisation lives in PHP rather than in a `lower(display_name)` index
 * because PostgreSQL's `lower()` is locale-dependent, and Turkish dotted and
 * dotless I is precisely where that produces surprises. One implementation the
 * test suite can pin down beats one the database chooses for us.
 */
final class DisplayName
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 20;

    /**
     * Unicode letters and digits, plus the three permitted punctuation marks.
     *
     * `\p{L}` covers Turkish and Spanish letters without an allow-list, which is
     * the point: a Latin-only rule would reject legitimate names.
     */
    public const PATTERN = '/^[\p{L}\p{N}](?:[\p{L}\p{N}_.\-]*[\p{L}\p{N}])?$/u';

    /**
     * Combining dot above, the residue of lower-casing `İ` (U+0130).
     *
     * Unicode's locale-blind lowercase mapping for the Turkish dotted capital I
     * is `i` followed by this mark, because in Lithuanian the dot carries
     * meaning. In Turkish it does not: `İ` is simply the capital of `i`.
     */
    private const COMBINING_DOT_ABOVE = "\u{0307}";

    /**
     * The comparison form used for case-insensitive uniqueness.
     *
     * Four steps, each earning its place:
     *
     * 1. **NFKC.** Collapses compatibility forms, so a name cannot be duplicated
     *    by spelling the same glyphs with different code points — fullwidth
     *    `Ａyse` and `ayse` become one name rather than two.
     *
     * 2. **Case fold.** The "case-insensitive" half of the APPROVED rule.
     *
     * 3. **Drop the dot left over from `İ`.** Without this, `İSTANBUL` and
     *    `istanbul` are *different* names: PHP's lowercase of U+0130 is `i` plus
     *    a combining dot, which is the correct locale-blind mapping and also
     *    exactly wrong for a Turkish-first product. Two accounts called
     *    "İstanbul" and "istanbul" on the same leaderboard is an impersonation
     *    path in the primary audience's own language, not the exotic confusable
     *    problem that `leaderboards.md` defers. PostgreSQL's `lower()` already
     *    folds these together; this makes PHP agree, so the application and the
     *    database never disagree about whether a name is taken.
     *
     *    The rule is deliberately narrow — only this mark, only after `i`. It
     *    does not touch `ş`, `ñ`, `é` or any other accented letter, because
     *    those are distinct letters rather than cased forms of a Latin base.
     *
     *    One asymmetry remains and is accepted: dotless `ı` stays distinct from
     *    `i`, so `ıstanbul` is its own name while `ISTANBUL` and `İSTANBUL`
     *    collide. No locale-blind fold can satisfy the Turkish and English
     *    mappings of `I` at once; this is the choice every mainstream system
     *    makes, and it is deterministic and documented rather than incidental.
     *
     * 4. **NFC.** So what is stored is well-formed.
     *
     * This value is never displayed — the player's own casing is preserved in
     * `display_name`.
     */
    public static function normalize(string $name): string
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);

        if ($normalized === false) {
            // Not valid UTF-8 in a normalisable form. Validation rejects it, but
            // this method must still be total: returning the raw bytes keeps the
            // uniqueness index meaningful instead of throwing during a backfill.
            $normalized = $name;
        }

        $folded = mb_strtolower($normalized, 'UTF-8');

        $folded = str_replace('i'.self::COMBINING_DOT_ABOVE, 'i', $folded);

        $recomposed = Normalizer::normalize($folded, Normalizer::FORM_C);

        return $recomposed === false ? $folded : $recomposed;
    }

    /**
     * Does this name satisfy the character and shape rules?
     *
     * Composed first, and that is not a detail. `\p{L}` does not match `\p{M}`,
     * so a decomposed name — `n` followed by U+0303 rather than `ñ` — failed the
     * pattern while the identical-looking precomposed form passed. Which one a
     * browser sends depends on the platform and the input method, so the same
     * name was accepted or refused according to how the player typed it. Worse,
     * `normalize()` composes and `isWellFormed()` did not, so the shape rule and
     * the uniqueness rule were reading different strings; a unit test asserting
     * that decomposed and precomposed accents unify was asserting a property the
     * validation path did not have, because that input never reached it.
     *
     * NFC rather than the NFKC `normalize()` uses: this decides what a name may
     * *be*, and compatibility folding belongs to deciding when two names are the
     * same. Length is then measured on the composed form, so `ñ` counts once
     * however it arrived.
     *
     * Length is counted in code points, which is what the approved "3–20
     * characters" most naturally means; grapheme-aware counting is a refinement,
     * not a v1 rule.
     */
    public static function isWellFormed(string $name): bool
    {
        $composed = Normalizer::normalize($name, Normalizer::FORM_C);

        if ($composed === false) {
            // Not valid UTF-8. Nothing further to ask about it.
            return false;
        }

        $name = $composed;

        $length = mb_strlen($name, 'UTF-8');

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }

        if (preg_match(self::PATTERN, $name) !== 1) {
            return false;
        }

        // "At least one letter" — a name of pure digits and punctuation is not a
        // name, and pure digits collide with rank numbers on the leaderboard.
        return preg_match('/\p{L}/u', $name) === 1;
    }
}
