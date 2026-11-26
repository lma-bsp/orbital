<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Carbon;

/**
 * This deployment's RFC 9116 security.txt.
 *
 * Each Orbital install is run by a different platform operator, so the
 * contact for "I found a problem with this site" is theirs, not Call
 * Theory's. The file is built from two Platform Settings
 * (orbital.security.contact, orbital.security.policy_url) and is only
 * served once a contact is set: pointing a deployment's users at
 * upstream would send reports about an operator's own install to people
 * who can't act on them.
 */
final class SecurityTxt
{
    /** How far ahead Expires is set. RFC 9116 asks for under a year. */
    public const EXPIRES_DAYS = 180;

    /**
     * The file body, or null when no usable contact is configured.
     */
    public function render(?Carbon $now = null): ?string
    {
        $contact = self::contactUri(config('orbital.security.contact'));

        if ($contact === null) {
            return null;
        }

        $now ??= Carbon::now();

        $lines = [
            'Contact: '.$contact,
            'Expires: '.$now->copy()->utc()->addDays(self::EXPIRES_DAYS)->format('Y-m-d\TH:i:s.v\Z'),
        ];

        $policy = self::httpsUrl(config('orbital.security.policy_url'));
        if ($policy !== null) {
            $lines[] = 'Policy: '.$policy;
        }

        $canonical = self::httpsUrl(rtrim((string) config('app.url'), '/').'/.well-known/security.txt');
        if ($canonical !== null) {
            $lines[] = 'Canonical: '.$canonical;
        }

        $lines[] = 'Preferred-Languages: en';

        return implode("\n", $lines)."\n";
    }

    /**
     * Turn the configured contact into a security.txt Contact URI.
     *
     * A bare address becomes mailto:. A web URI must be https (RFC 9116
     * §2.5.3). Anything else — including a value with whitespace or a
     * line break, which could inject extra fields — is treated as unset.
     */
    public static function contactUri(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || preg_match('/\s/', $value) === 1) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return 'mailto:'.$value;
        }

        if (str_starts_with(strtolower($value), 'mailto:')) {
            return filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL) !== false ? $value : null;
        }

        if (str_starts_with(strtolower($value), 'tel:')) {
            return preg_match('/^tel:\+?[0-9().-]+$/i', $value) === 1 ? $value : null;
        }

        return self::httpsUrl($value);
    }

    private static function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === ''
            || preg_match('/\s/', $value) === 1
            || ! str_starts_with(strtolower($value), 'https://')
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }
}
