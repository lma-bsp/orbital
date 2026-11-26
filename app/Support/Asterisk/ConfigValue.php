<?php

declare(strict_types=1);

namespace App\Support\Asterisk;

use Stringable;

/**
 * Makes database strings safe to print into generated Asterisk config
 * (dialplan, voicemail.conf). Blade's `{{ }}` only HTML-escapes, so a
 * name holding a newline used to become extra dialplan lines, and a
 * `${SHELL(...)}` inside NoOp() would be expanded when the call ran.
 *
 * Two positions, two filters (Blade: `@asttext(...)`, `@asttoken(...)`):
 *
 * - text(): free text in comments, NoOp() arguments and voicemail.conf
 *   full names. A whitelist: letters, marks, digits, spaces and a few
 *   inert punctuation marks. Everything that means something to the
 *   config parser or the dialplan is dropped: line breaks and other
 *   control/format characters, `;` (comment, and `;--` block comment),
 *   `,` (argument separator), `( ) [ ] { }`, `$` (`${...}` / `$[...]`
 *   expansion), `"` and `\` (quoting/escaping), `|` `^` `=` `<` `>`
 *   (`=>` in voicemail.conf).
 *
 * - token(): identifiers in syntactic positions: extension numbers and
 *   patterns, Dial() targets, hosts, mailbox numbers, file paths. Only
 *   `A-Z a-z 0-9 _ - + * # . ! @ / : [ ]` survive (brackets because
 *   extension patterns use character classes like `_1[2-9]XX`).
 */
final class ConfigValue
{
    /** Longest free-text value printed; keeps a pasted essay off one line. */
    public const TEXT_MAX_LENGTH = 120;

    public static function text(mixed $value): string
    {
        $value = self::stringify($value);

        // Dropped characters become spaces so "Front\ndesk" reads
        // "Front desk" and "a,b" reads "a b".
        $value = preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '';
        $value = preg_replace("/[^\\p{L}\\p{M}\\p{N} .\\-_'&\\/:+#@*!?%]+/u", ' ', $value) ?? '';
        $value = trim(preg_replace('/ {2,}/', ' ', $value) ?? '');

        return trim(mb_substr($value, 0, self::TEXT_MAX_LENGTH));
    }

    public static function token(mixed $value): string
    {
        return preg_replace('/[^A-Za-z0-9_\-+*#.!@\/:\[\]]+/', '', self::stringify($value)) ?? '';
    }

    private static function stringify(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (is_string($value) || is_int($value) || is_float($value) || $value instanceof Stringable) {
            $value = (string) $value;
        } else {
            return '';
        }

        // Invalid UTF-8 would make every /u regex above return null.
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
