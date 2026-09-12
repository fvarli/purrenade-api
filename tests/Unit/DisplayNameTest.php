<?php

declare(strict_types=1);

use App\Support\DisplayName;

/**
 * Pure logic, so no application boot: if these rules ever needed the framework,
 * that would itself be the defect.
 */
describe('shape', function (): void {
    it('accepts names within the approved rules', function (string $name): void {
        expect(DisplayName::isWellFormed($name))->toBeTrue();
    })->with([
        'minimum length' => ['abc'],
        'maximum length' => ['abcdefghijklmnopqrst'],
        'Turkish letters' => ['Ayşenur'],
        'Spanish letters' => ['Señorita'],
        'digits and letters' => ['ayse42'],
        'inner underscore' => ['ayse_nur'],
        'inner dot' => ['ayse.nur'],
        'inner hyphen' => ['ayse-nur'],
        'mixed punctuation' => ['a_b.c-d'],
        'decomposed accent' => ["sen\u{0303}orita"],
        'non-Latin script' => ['Ярослав'],
    ]);

    it('rejects names outside them', function (string $name): void {
        expect(DisplayName::isWellFormed($name))->toBeFalse();
    })->with([
        'too short' => ['ab'],
        'too long' => ['abcdefghijklmnopqrstu'],
        'empty' => [''],
        'leading underscore' => ['_ayse'],
        'leading dot' => ['.ayse'],
        'leading hyphen' => ['-ayse'],
        'trailing underscore' => ['ayse_'],
        'trailing dot' => ['ayse.'],
        'digits only' => ['123456'],
        'punctuation only' => ['_._._'],
        'internal space' => ['ayse nur'],
        'emoji' => ['ayse🐾'],
        'slash' => ['ayse/nur'],
        'angle bracket' => ['<script>'],
        'zero-width joiner' => ["ayse\u{200D}nur"],
        'zero-width space' => ["ayse\u{200B}nur"],
        'zero-width non-joiner' => ["ayse\u{200C}nur"],
        'byte order mark' => ["ayse\u{FEFF}nur"],
        'soft hyphen' => ["ayse\u{00AD}nur"],
        'word joiner' => ["ayse\u{2060}nur"],
        'right-to-left override' => ["ayse\u{202E}nur"],
        'left-to-right isolate' => ["ayse\u{2066}nur"],
        'non-breaking space' => ["ayse\u{00A0}nur"],
        'trailing hyphen' => ['ayse-'],
        'leading combining mark' => ["\u{0303}ayse"],
    ]);
});

describe('normalisation', function (): void {
    it('is case-insensitive', function (): void {
        expect(DisplayName::normalize('AySeNuR'))->toBe(DisplayName::normalize('aysenur'));
    });

    it('folds Turkish letters correctly', function (): void {
        expect(DisplayName::normalize('AYŞENUR'))->toBe(DisplayName::normalize('ayşenur'))
            ->and(DisplayName::normalize('ÇİĞDEM'))->toBe(DisplayName::normalize('çiğdem'));
    });

    it('folds the dotted capital I to a plain i', function (): void {
        // Without this, "İstanbul" and "istanbul" are two separate leaderboard
        // entries — an impersonation path in the primary audience's own
        // language. PostgreSQL's lower() already folds them; this keeps PHP in
        // agreement, so the application and the index never disagree about
        // whether a name is taken.
        expect(DisplayName::normalize('İstanbul'))->toBe('istanbul')
            ->and(DisplayName::normalize('İSTANBUL'))->toBe('istanbul')
            ->and(DisplayName::normalize('Istanbul'))->toBe('istanbul');
    });

    it('keeps dotless i distinct', function (): void {
        // The accepted asymmetry: no locale-blind fold satisfies the Turkish and
        // English mappings of I at once.
        expect(DisplayName::normalize('ıstanbul'))->not->toBe('istanbul');
    });

    it('folds Spanish letters correctly', function (): void {
        expect(DisplayName::normalize('SEÑORITA'))->toBe(DisplayName::normalize('señorita'));
    });

    it('collapses compatibility forms', function (): void {
        // NFKC, so a name cannot be duplicated with fullwidth or other
        // compatibility code points for the same glyphs.
        expect(DisplayName::normalize('Ａｙｓｅ'))->toBe('ayse');
    });

    it('unifies decomposed and precomposed accents', function (): void {
        expect(DisplayName::normalize("sen\u{0303}orita"))->toBe(DisplayName::normalize('señorita'));
    });

    it('is idempotent', function (string $name): void {
        // Applying it twice must change nothing, or the stored comparison value
        // could drift from a freshly computed one and uniqueness would silently
        // stop working.
        $once = DisplayName::normalize($name);

        expect(DisplayName::normalize($once))->toBe($once);
    })->with([['Ayşenur'], ['İSTANBUL'], ['Ａｙｓｅ'], ['señorita'], ['a_b.c-d']]);

    it('is total, even for input validation would reject', function (): void {
        // Used by a migration backfill, so it must never throw.
        expect(DisplayName::normalize(''))->toBe('')
            ->and(DisplayName::normalize("\xC3\x28"))->toBeString();
    });
});
