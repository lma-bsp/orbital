<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Services\Import\Amtelco\ClientNameFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClientNameFormatterTest extends TestCase
{
    /**
     * Cases taken from NCI's real Amtelco export.
     *
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'plain words' => ['NORTHWELL HEALTH', 'Northwell Health'],
            'possessive' => ["DR SHAHID SHEIKH'S SERV", "Dr Shahid Sheikh's Serv"],
            'detached possessive' => ["DR MARY BELLO 'S SERVICE", "Dr Mary Bello 's Service"],
            'kept acronyms' => ['NYU INTERNAL MED AT HV', 'NYU Internal Med at HV'],
            'vowel-less abbreviation' => ['BCHP - GASTRO', 'BCHP - Gastro'],
            'common abbreviations' => ['NY PRESBYTERIAN MED GRP', 'NY Presbyterian Med Grp'],
            'small words' => ['MT SINAI SCHOOL OF MED', 'Mt Sinai School of Med'],
            'small word first' => ['THE DOCTORS GROUP', 'The Doctors Group'],
            'initial is not a small word' => ['JOHN A SMITH', 'John A Smith'],
            'ampersand and extra spaces' => ['ENT & ALLERGY  ASSOC', 'ENT & Allergy Assoc'],
            'irish name' => ["DR O'BRIEN", "Dr O'Brien"],
            'mc prefix' => ['MCLEAN FAMILY PRACTICE', 'McLean Family Practice'],
            'hyphenated' => ['DR BETH SCHORR-LESNICK', 'Dr Beth Schorr-Lesnick'],
            'codes with digits' => ['WESTMED 302 OBGYN ACCT', 'Westmed 302 OBGYN Acct'],
            'decorations kept' => ['ROCKLAND ENDOCRINE->>>>', 'Rockland Endocrine->>>>'],
            'already mixed case left alone' => ['NCI Doctors  Svc', 'NCI Doctors Svc'],
            'acronym in parentheses' => ['ALCOHOLICS ANONYMOUS (AA)', 'Alcoholics Anonymous (AA)'],
            'place in parentheses' => ['BETH ISRAEL (BROOKLYN)', 'Beth Israel (Brooklyn)'],
            'empty' => ['   ', ''],
        ];
    }

    #[DataProvider('names')]
    public function test_formats_amtelco_names(string $in, string $out): void
    {
        $this->assertSame($out, (new ClientNameFormatter)->format($in));
    }
}
