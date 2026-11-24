<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Services\Import\Amtelco\ClientNameFormatter;
use App\Services\Import\Amtelco\DirectoryListingMapper;
use PHPUnit\Framework\TestCase;

/**
 * Listings below are taken from NCI's real directory export.
 */
class DirectoryListingMapperTest extends TestCase
{
    private function map(array $listing, array $names = []): array
    {
        return (new DirectoryListingMapper(new ClientNameFormatter))->map($listing, $names);
    }

    public function test_a_contact_with_private_numbers(): void
    {
        $mapped = $this->map([
            'id' => '4346 SYMPHON#2',
            'record_type' => 'contact',
            'name' => ['raw' => 'RUDNICKI,JANUSZ', 'last' => 'RUDNICKI', 'first' => 'JANUSZ'],
            'contact_methods' => [
                ['type' => 'cell', 'label' => 'PRIVATE CELL', 'number' => '+19144132083', 'flags' => ['private'], 'raw' => '9144132083'],
                ['type' => 'home', 'label' => 'PRIVATE HOME', 'number' => '+19142323305', 'flags' => ['private'], 'raw' => '9142323305'],
                ['type' => 'text', 'label' => 'TEXT CELL', 'number' => '+19144132083', 'paging_code' => '.200SX."9144132083".210S', 'raw' => '.200SX."9144132083".210S'],
            ],
            'attributes' => ['contact_procedure' => ['HOME 1ST (CAREFUL)/MSG CELL & TEXT']],
        ]);

        $this->assertSame('contact', $mapped['kind']);
        $v = $mapped['values'];
        $this->assertSame('Janusz Rudnicki', $v['name']);
        $this->assertSame('+19144132083', $v['cell_phone']);
        $this->assertSame('+19142323305', $v['home_phone']);
        $this->assertSame('+19144132083', $v['primary_phone'], 'no office line, so the cell is primary');
        $this->assertSame('+19144132083', $v['text_to'], 'the SMS macro is decoded, not kept');
        $this->assertSame("HOME 1ST (CAREFUL)/MSG CELL & TEXT\nPRIVATE, do not give to callers: PRIVATE CELL, PRIVATE HOME", $v['contact_procedure']);
        $this->assertSame('4346 SYMPHON#2', $v['amtelco_ref']);
    }

    public function test_extra_numbers_and_local_numbers_go_to_notes_not_phone_fields(): void
    {
        $v = $this->map([
            'id' => 'X#1',
            'record_type' => 'contact',
            'name' => ['raw' => 'DR LAGER'],
            'contact_methods' => [
                ['type' => 'office', 'label' => 'OFFICE #', 'number_local' => '949-1200', 'number_status' => 'no_area_code', 'raw' => '9491200'],
                ['type' => 'office', 'label' => 'OFFICE 2', 'number' => '+19739281325', 'flags' => ['backup'], 'raw' => '1973-928-1325'],
                ['type' => 'fax', 'label' => 'FAX', 'number' => '+19739281326', 'raw' => '19739281326'],
            ],
        ])['values'];

        $this->assertSame('949-1200', $v['primary_phone']);
        $this->assertSame("OFFICE #: no area code\nOFFICE 2: +19739281325 (backup)\nFAX: +19739281326", $v['notes']);
    }

    public function test_sms_macros_go_to_the_text_field_whatever_the_type(): void
    {
        $v = $this->map([
            'id' => 'X#2',
            'record_type' => 'contact',
            'name' => ['raw' => 'DR LAYNE'],
            'contact_methods' => [
                // Typed as a pager by the converter, but the macro is an SMS.
                ['type' => 'pager', 'label' => 'TXT/BPR', 'number' => '+19299307616', 'paging_code' => '.200SX."9299307616".210S', 'raw' => '.200SX."9299307616".210S'],
                // Clipped opening, same macro: a second SMS number.
                ['type' => 'text', 'label' => 'BACKUP TEXT', 'number' => '+19177540662', 'paging_code' => 'C."9177540662".210S', 'raw' => 'C."9177540662".210S'],
                // Not decoded yet: keeps its code.
                ['type' => 'text', 'label' => 'MISECURE TXT', 'handle' => 'JLAYNE', 'paging_code' => '.150S."JLAYNE".151S', 'raw' => '.150S."JLAYNE".151S'],
                ['type' => 'pager', 'label' => 'PAGER #', 'number' => '+19145391679', 'paging_code' => '.81S"9145391679".0.1.1M', 'raw' => '.81S"9145391679".0.1.1M'],
            ],
        ])['values'];

        $this->assertSame('+19299307616', $v['text_to']);
        $this->assertSame('+19145391679 (code .81S"9145391679".0.1.1M)', $v['pager']);
        $this->assertSame("BACKUP TEXT: +19177540662 (SMS)\nMISECURE TXT: JLAYNE (code .150S.\"JLAYNE\".151S)", $v['notes']);
    }

    public function test_sms_macros_in_procedure_text_are_spelled_out(): void
    {
        $this->assertSame('SMS +19175499172', DirectoryListingMapper::spellOutSmsMacros('.200SX."9175499172".210S'));
        $this->assertSame('CALL CELL, THEN SMS +19179744237', DirectoryListingMapper::spellOutSmsMacros('CALL CELL, THEN .200SX."9179744237".210SX'));
        $this->assertSame('MSG CELL & TEXT', DirectoryListingMapper::spellOutSmsMacros('MSG CELL & TEXT'));
        $this->assertTrue(DirectoryListingMapper::isSmsMacro('.200SX."9179744237".210SX'));
        $this->assertFalse(DirectoryListingMapper::isSmsMacro('.150S."JLAYNE".151S'));
    }

    public function test_routing_lines_point_at_the_client(): void
    {
        $mapped = $this->map([
            'id' => '4318 MAIN#6',
            'record_type' => 'routing',
            'name' => ['raw' => 'CHRISTOPHER DIGIORNO'],
            'attributes' => [
                'account_numbers' => [['label' => 'ACCT', 'raw' => '43184', 'numbers' => ['43184']]],
                'specialty' => 'GASTRO',
            ],
            'notes' => [['label' => 'NOTES', 'text' => 'CONFIRM THEY WANT GI!!! GASTRO!!!']],
        ], [43184 => 'Gastro Assoc']);

        $this->assertSame('routing', $mapped['kind']);
        $this->assertSame('Christopher Digiorno', $mapped['values']['name']);
        $this->assertSame('Gastro', $mapped['values']['department']);
        $this->assertSame('#43184 Gastro Assoc', $mapped['values']['account_ref']);
        $this->assertSame('CONFIRM THEY WANT GI!!! GASTRO!!!', $mapped['values']['notes']);
        $this->assertArrayNotHasKey('primary_phone', $mapped['values']);
    }

    public function test_a_listing_without_a_name_uses_its_first_field(): void
    {
        $v = $this->map([
            'id' => '0002 HSP DIR#1',
            'record_type' => 'contact',
            'contact_methods' => [['type' => 'phone', 'label' => 'PHONE #', 'number' => '+17189513060', 'raw' => '17189513060']],
            'other_fields' => [['label' => 'HOSPITAL', 'value' => 'BETH ISRAEL KINGS HIGHWAY (BROOKLYN)']],
        ])['values'];

        $this->assertSame('Beth Israel Kings Highway (Brooklyn)', $v['name']);
        $this->assertSame('HOSPITAL: BETH ISRAEL KINGS HIGHWAY (BROOKLYN)', $v['notes']);
    }

    public function test_a_note_keeps_its_text(): void
    {
        $mapped = $this->map([
            'id' => '7683#8',
            'record_type' => 'note',
            'unlabeled_text' => ['AFTER 5PM & WKNDS:', '1ST CALL  = HOSP BP 2560'],
        ]);

        $this->assertSame('note', $mapped['kind']);
        $this->assertSame('AFTER 5PM & WKNDS:', $mapped['values']['name']);
        $this->assertSame("AFTER 5PM & WKNDS:\n1ST CALL  = HOSP BP 2560", $mapped['values']['notes']);
    }

    public function test_sibling_account_shorthand_is_expanded(): void
    {
        $this->assertSame([50951, 50952], DirectoryListingMapper::expandNumbers(['50951', '2'], '50951&2'));
        $this->assertSame([43131, 43132], DirectoryListingMapper::expandNumbers(['43131', '2'], '43131/2'));
        $this->assertSame([300, 631], DirectoryListingMapper::expandNumbers(['0300', '0631'], '0300/0631 IM'));
        $this->assertSame([2025], DirectoryListingMapper::expandNumbers(['2025'], '2025 MAIN'));
    }
}
