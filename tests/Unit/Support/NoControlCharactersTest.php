<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Rules\NoControlCharacters;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoControlCharactersTest extends TestCase
{
    /** @return array<string, array{mixed, bool}> */
    public static function values(): array
    {
        return [
            'plain' => ['Jane Smith', true],
            'punctuation' => ["O'Brien (Front); [x] \${y}", true],
            'accents and emoji' => ['José 👩‍⚕️', true],
            'empty' => ['', true],
            'null' => [null, true],
            'newline' => ["Jane\nSmith", false],
            'carriage return' => ["Jane\rSmith", false],
            'tab' => ["Jane\tSmith", false],
            'nul' => ["Jane\0", false],
            'escape' => ["Jane\e[31m", false],
            'c1 control' => ["Jane\u{85}Smith", false],
            'line separator' => ["Jane\u{2028}Smith", false],
            'bidi override' => ["Jane\u{202E}htimS", false],
            'invalid utf-8' => ["Jane\xff", false],
        ];
    }

    #[DataProvider('values')]
    public function test_validation(mixed $value, bool $passes): void
    {
        $v = Validator::make(['name' => $value], ['name' => [new NoControlCharacters]]);

        $this->assertSame($passes, $v->passes());
    }

    public function test_strip_turns_runs_into_one_space(): void
    {
        $this->assertSame('Front desk', NoControlCharacters::strip("Front \r\n\t desk"));
        $this->assertSame('a b', NoControlCharacters::strip("\na\0b\n"));
        $this->assertSame('keep  double', NoControlCharacters::strip('keep  double'));
        $this->assertNull(NoControlCharacters::strip(null));
    }
}
