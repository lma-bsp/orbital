<?php

declare(strict_types=1);

namespace App\Services\Import\Amtelco;

use App\Rules\NoControlCharacters;

/**
 * Turns Amtelco's all-caps client names into title case for the portal:
 * "DR SHAHID SHEIKH'S SERV" -> "Dr Shahid Sheikh's Serv".
 *
 * Abbreviations are the hard part. Amtelco names are squeezed into 24
 * characters, so they are full of them, and title-casing "NYU" or
 * "BCHP" makes them worse, not better. The rules, tuned on the real
 * export:
 *
 *   - A name that already has lowercase letters was typed by a person
 *     who meant that casing; only its whitespace is tidied.
 *   - Tokens in KEEP_UPPER stay as they are (NYU, ENT, OBGYN, ...).
 *   - Tokens with no vowel are abbreviations (BCHP, WMG, HSP) and stay
 *     upper, except the common ones in TITLE_CASE (Dr, Grp, Ctr, St).
 *   - Tokens with digits are codes and are left alone.
 *   - Small words (of, and, the, ...) are lowercase except first.
 *   - Everything else gets an upper first letter, including after
 *     hyphens, slashes and a leading Mc / O'.
 */
class ClientNameFormatter
{
    private const KEEP_UPPER = [
        'ENT', 'NYU', 'NYP', 'NYC', 'NY', 'NJ', 'CT', 'PA', 'FL', 'USA',
        'OB', 'GYN', 'OBGYN', 'ICU', 'ER', 'IM', 'GI', 'ID', 'MRI', 'EKG',
        'II', 'III', 'IV', 'VI', 'VII', 'VIII', 'IX', 'XI', 'XII',
        'AIDS', 'HIV', 'EMS', 'IVF', 'ADHD', 'UHS', 'AHS',
        'LLC', 'PLLC', 'LLP', 'MD', 'MDS', 'DO', 'DDS', 'DMD', 'DPM', 'NP',
        'RN', 'PT', 'OT', 'CPA', 'ESQ',
        'NCI', 'VM', 'PBX', 'DID', 'SMS', 'CDT', 'TV', 'AM', 'PM',
    ];

    /**
     * Lowercase inside a name, as in "School of Medicine". Not "A" (in
     * these names it is nearly always a middle initial), "OR" (an
     * operating room) or "IN" ("Check In Line").
     */
    private const SMALL_WORDS = ['OF', 'AND', 'THE', 'AT', 'FOR', 'ON', 'TO', 'BY'];

    private const TITLE_CASE = [
        'DR', 'DRS', 'MR', 'MRS', 'MS', 'ST', 'MT', 'RD', 'JR', 'SR',
        'CTR', 'CTRS', 'GRP', 'GRPS', 'HLTH', 'SVC', 'SVCS', 'SRVC',
        'MGT', 'MGMT', 'DEPT', 'BLVD', 'FT', 'GR', 'RM', 'PKWY', 'HWY',
    ];

    public function format(string $name): string
    {
        // Spreadsheet cells carry stray line breaks and control bytes;
        // names go on to dialplan comments, CSVs and logs as one line.
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) NoControlCharacters::strip($name)));

        if ($name === '' || preg_match('/\p{Ll}/u', $name)) {
            return $name;
        }

        $first = true;

        return (string) preg_replace_callback(
            "/(?<paren>(?<=\\()[A-Z]{2,4}(?=\\)))|[\\p{L}\\p{N}']+/u",
            function (array $m) use (&$first): string {
                $token = $m[0];
                $isFirst = $first;
                $first = false;

                // A short word alone in parentheses is an acronym: "(AA)".
                if (($m['paren'] ?? '') !== '') {
                    return $token;
                }

                if (! $isFirst && in_array($token, self::SMALL_WORDS, true)) {
                    return mb_strtolower($token);
                }

                return $this->formatToken($token);
            },
            $name,
        );
    }

    private function formatToken(string $token): string
    {
        // A possessive split off by a stray space: "DR BELLO 'S"
        if ($token === "'S") {
            return "'s";
        }

        // "SHEIKH'S" -> word + possessive, handled separately so the S
        // stays lower: "Sheikh's".
        if (preg_match("/^(.+)'S$/u", $token, $m)) {
            return $this->formatToken($m[1])."'s";
        }

        $bare = str_replace("'", '', $token);

        if (preg_match('/\p{N}/u', $bare)) {
            return $token;
        }

        if (in_array($bare, self::KEEP_UPPER, true)) {
            return $token;
        }

        if (in_array($bare, self::TITLE_CASE, true)) {
            return $this->capitalize($token);
        }

        // Single letters are initials ("JOHN R SMITH").
        if (mb_strlen($bare) === 1) {
            return $token;
        }

        if (! preg_match('/[AEIOUY]/u', $bare)) {
            return $token;
        }

        // O'BRIEN -> O'Brien, D'ANGELO -> D'Angelo
        if (preg_match("/^([A-Z])'(\\p{L}{2,})$/u", $token, $m)) {
            return $m[1]."'".$this->capitalize($m[2]);
        }

        // MCDONALD -> McDonald
        if (preg_match('/^MC(\p{L}{3,})$/u', $token, $m)) {
            return 'Mc'.$this->capitalize($m[1]);
        }

        return $this->capitalize($token);
    }

    private function capitalize(string $word): string
    {
        $lower = mb_strtolower($word);

        return mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
    }
}
