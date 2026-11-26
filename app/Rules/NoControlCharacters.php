<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Single-line names and labels: no line breaks, tabs, other C0/C1
 * control characters, Unicode line/paragraph separators or bidi
 * override characters. These names reach generated Asterisk config,
 * logs and CSV exports, where a line break starts a new record.
 *
 * Importers that cannot bounce a row use {@see self::strip()} instead.
 */
final class NoControlCharacters implements ValidationRule
{
    private const CHARACTERS = '[\p{Cc}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]';

    public const PATTERN = '/'.self::CHARACTERS.'/u';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Type checks belong to `string` / `max`; this rule only judges text.
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! mb_check_encoding($value, 'UTF-8') || preg_match(self::PATTERN, $value) === 1) {
            $fail('The :attribute must be a single line of text without control characters.');
        }
    }

    /**
     * Replace control characters with a space and collapse the runs,
     * for data the caller normalises rather than rejects.
     */
    public static function strip(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        // A run of control characters and the blanks around it becomes
        // one space: "Front\r\n desk" reads "Front desk".
        return trim(preg_replace('/ *'.self::CHARACTERS.'+ */u', ' ', $value) ?? '');
    }
}
