<?php

declare(strict_types=1);

namespace App\Services\Import\Amtelco;

/**
 * Turns one Amtelco directory listing (as converted into
 * nci_directories.json) into directory entry values.
 *
 * A listing is a person with numbers, a "go to account N" routing
 * line, an operator instruction, or a free note. All four become
 * entries; the kind is returned so the importer can tag it.
 *
 * Numbers land in the field for their kind (cell, home, pager, text,
 * office), the first office/phone number, or failing that the first
 * number of any kind, is the primary phone, and anything that doesn't
 * fit a field is spelled out in notes so nothing in the listing is
 * lost. Numbers Amtelco marks private stay in their normal field and
 * the contact procedure says which ones must not be given to callers.
 */
class DirectoryListingMapper
{
    /** Directory fields beyond the provisioner's defaults. key => [label, type, sort] */
    public const EXTRA_FIELDS = [
        'contact_procedure' => ['How to reach', 'textarea', 45],
        'cell_phone' => ['Cell', 'phone', 52],
        'home_phone' => ['Home', 'phone', 54],
        'pager' => ['Pager', 'text', 56],
        'text_to' => ['Text (SMS)', 'text', 58],
        'account_ref' => ['Go to account', 'text', 75],
        'location' => ['Location', 'text', 77],
        'amtelco_ref' => ['Amtelco listing', 'text', 99],
    ];

    /** Contact method type => field it fills first. */
    private const FIELD_FOR_TYPE = [
        'office' => 'primary_phone',
        'phone' => 'primary_phone',
        'cell' => 'cell_phone',
        'home' => 'home_phone',
        'pager' => 'pager',
        'paging_code' => 'pager',
        'text' => 'text_to',
        'text_gateway' => 'text_to',
        'email' => 'email',
    ];

    /** Fields typed `phone`: a bare number only. */
    private const PHONE_FIELDS = ['primary_phone', 'cell_phone', 'home_phone'];

    public function __construct(private readonly ClientNameFormatter $names) {}

    /**
     * @param  array<string, mixed>  $listing
     * @param  array<int, string>  $accountNames  account number => client name, to label routing targets
     * @return array{kind: string, values: array<string, string>}
     */
    public function map(array $listing, array $accountNames = []): array
    {
        $values = [];
        $notes = [];
        $private = [];

        $values['name'] = $this->name($listing);

        $name = $listing['name'] ?? [];
        if (! empty($name['notes'])) {
            $notes[] = self::text($name['notes']);
        }

        $attributes = $listing['attributes'] ?? [];
        if (! empty($attributes['title'])) {
            $values['title'] = $this->names->format(self::text($attributes['title']));
        }
        if (! empty($attributes['specialty'])) {
            $values['department'] = $this->names->format(self::text($attributes['specialty']));
        }

        foreach ($listing['contact_methods'] ?? [] as $method) {
            $this->placeNumber($method, $values, $notes, $private);
        }

        // People are reached on whatever number they have; give the
        // entry a primary phone even when there is no office line.
        if (! isset($values['primary_phone'])) {
            foreach (['cell_phone', 'home_phone'] as $field) {
                if (isset($values[$field])) {
                    $values['primary_phone'] = $values[$field];
                    break;
                }
            }
        }

        $procedure = array_map(fn ($line) => self::spellOutSmsMacros(self::text($line)), (array) ($attributes['contact_procedure'] ?? []));
        if ($private !== []) {
            $procedure[] = 'PRIVATE, do not give to callers: '.implode(', ', array_unique($private));
        }
        if ($procedure !== []) {
            $values['contact_procedure'] = implode("\n", $procedure);
        }

        $accounts = [];
        foreach (['account_numbers', 'routing_accounts'] as $key) {
            foreach ($attributes[$key] ?? [] as $ref) {
                foreach (self::expandNumbers($ref['numbers'] ?? [], (string) ($ref['raw'] ?? '')) as $number) {
                    $accounts[$number] = isset($accountNames[$number]) ? "#{$number} {$accountNames[$number]}" : "#{$number}";
                }
            }
        }
        if ($accounts !== []) {
            $values['account_ref'] = implode(', ', $accounts);
        }

        if (! empty($listing['location'])) {
            $values['location'] = (string) $listing['location'];
        }

        if (! empty($attributes['address'])) {
            $notes[] = 'Address: '.self::text($attributes['address']);
        }
        if (! empty($attributes['call_type'])) {
            $notes[] = 'Call type: '.self::text($attributes['call_type']);
        }
        foreach ($listing['other_fields'] ?? [] as $field) {
            $notes[] = self::text($field['label'] ?? '').': '.self::text($field['value'] ?? '');
        }
        foreach ($listing['other_names'] ?? [] as $other) {
            $notes[] = self::text($other['label'] ?? 'Name').': '.self::text($other['raw'] ?? '');
        }
        foreach ($listing['notes'] ?? [] as $note) {
            $notes[] = self::text($note['text'] ?? '');
        }
        foreach ($listing['unlabeled_text'] ?? [] as $line) {
            $notes[] = self::text($line);
        }

        $notes = array_values(array_filter($notes, fn (string $n) => $n !== '' && $n !== ':'));
        if ($notes !== []) {
            $values['notes'] = implode("\n", $notes);
        }

        $values['amtelco_ref'] = (string) ($listing['id'] ?? '');

        return [
            'kind' => (string) ($listing['record_type'] ?? 'contact'),
            'values' => array_filter($values, fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * Amtelco's SMS text macro: `.200SX"<number>".210S`, whose opening
     * is sometimes clipped in the export. The closing `".210S` is what
     * identifies it.
     */
    public static function isSmsMacro(string $code): bool
    {
        return (bool) preg_match('/"[^"]+"\.210SX?$/', trim($code));
    }

    /**
     * Spell out SMS macros written into free text, as some contact
     * procedures are: `.200SX."9175499172".210S` -> `SMS +19175499172`.
     */
    public static function spellOutSmsMacros(string $text): string
    {
        return (string) preg_replace_callback(
            '/\S*?"([^"]+)"\.210SX?\b/',
            fn (array $m) => 'SMS '.(preg_match('/^\d{10}$/', $m[1]) ? '+1'.$m[1] : $m[1]),
            $text,
        );
    }

    /**
     * The converter emits most fields as strings but a few (specialty,
     * title) as lists when the listing had several.
     */
    public static function text(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' / ', array_filter(array_map(self::text(...), $value), fn (string $v) => $v !== ''));
        }

        return trim(is_scalar($value) ? (string) $value : '');
    }

    /**
     * Amtelco writes sibling accounts as "50951&2" or "43131/2", which
     * the converter splits into ["50951", "2"]. A short trailing number
     * replaces the end of the first: 50952, 43132.
     *
     * @param  array<int, string>  $numbers
     * @return array<int, int>
     */
    public static function expandNumbers(array $numbers, string $raw = ''): array
    {
        $out = [];
        $first = null;
        foreach ($numbers as $number) {
            $number = trim((string) $number);
            if (! ctype_digit($number)) {
                continue;
            }
            if ($first !== null && strlen($number) <= 2 && strlen($number) < strlen($first) && preg_match('/[&\/]/', $raw)) {
                $number = substr($first, 0, strlen($first) - strlen($number)).$number;
            }
            $first ??= $number;
            $out[] = (int) $number;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function name(array $listing): string
    {
        $name = $listing['name'] ?? [];

        if (! empty($name['last'])) {
            $parts = array_filter([trim((string) ($name['first'] ?? '')), trim((string) $name['last'])]);

            return $this->names->format(implode(' ', $parts));
        }
        if (! empty($name['raw'])) {
            return $this->names->format((string) $name['raw']);
        }
        foreach ($listing['other_fields'] ?? [] as $field) {
            if (! empty($field['value'])) {
                return $this->names->format((string) $field['value']);
            }
        }
        foreach ($listing['unlabeled_text'] ?? [] as $line) {
            if (trim((string) $line) !== '') {
                return trim((string) $line);
            }
        }

        return ucfirst((string) ($listing['record_type'] ?? 'listing')).' '.($listing['id'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $method
     * @param  array<string, string>  $values
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $private
     */
    private function placeNumber(array $method, array &$values, array &$notes, array &$private): void
    {
        $type = (string) ($method['type'] ?? '');
        $label = trim((string) ($method['label'] ?? strtoupper($type)));
        $value = (string) ($method['number'] ?? $method['number_local'] ?? $method['handle'] ?? $method['gateway_address'] ?? $method['pager_id'] ?? $method['paging_code'] ?? $method['raw'] ?? '');
        if ($value === '') {
            return;
        }

        $flags = array_map('strval', (array) ($method['flags'] ?? []));
        if (in_array('private', $flags, true)) {
            $private[] = $label;
        }

        // Pager and text entries carry the Amtelco dial macro that sends
        // them. `.200SX"<number>".210S` (often with its start clipped:
        // `SX."…".210S`, `C."…".210S`) is a plain SMS text, so the number
        // alone says everything and it belongs in the text field. Other
        // macros aren't decoded yet; their code stays next to the number,
        // as it is how operators page.
        $sms = self::isSmsMacro((string) ($method['paging_code'] ?? ''));
        $extra = [];
        if (! $sms && ! empty($method['paging_code']) && $method['paging_code'] !== $value) {
            $extra[] = 'code '.$method['paging_code'];
        }
        if (! empty($method['extension'])) {
            $extra[] = 'ext '.self::text($method['extension']);
        }
        if (($method['number_status'] ?? null) === 'no_area_code') {
            $extra[] = 'no area code';
        }

        $field = $sms ? 'text_to' : (self::FIELD_FOR_TYPE[$type] ?? null);
        $otherFlags = array_values(array_diff($flags, ['private', 'from_procedure']));
        if ($sms && $field !== null && isset($values[$field])) {
            // A second SMS number: say what it is in the note.
            $extra[] = 'SMS';
        }

        if ($field !== null && ! isset($values[$field]) && ! in_array('inactive', $flags, true)) {
            if (in_array($field, self::PHONE_FIELDS, true)) {
                // Phone fields hold a number and nothing else.
                $values[$field] = $value;
                $otherFlags = [...$otherFlags, ...$extra];
            } else {
                $values[$field] = $value.($extra !== [] ? ' ('.implode('; ', $extra).')' : '');
            }
            if ($otherFlags !== []) {
                $notes[] = "{$label}: ".implode(', ', $otherFlags);
            }

            return;
        }

        $otherFlags = [...$otherFlags, ...$extra];
        $notes[] = $label.': '.$value.($otherFlags !== [] ? ' ('.implode('; ', $otherFlags).')' : '');
    }
}
