<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Asterisk\ConfigValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigValueTest extends TestCase
{
    /** @return array<string, array{mixed, string}> */
    public static function texts(): array
    {
        return [
            'plain' => ['Front desk', 'Front desk'],
            'apostrophe and ampersand kept' => ["O'Brien & Co", "O'Brien & Co"],
            'accents kept' => ['Clínica São José', 'Clínica São José'],
            'newline becomes space' => ["Front\ndesk", 'Front desk'],
            'crlf and tab' => ["a\r\n\tb", 'a b'],
            'unicode separators' => ["a\u{2028}b\u{2029}c\u{202E}d", 'a b c d'],
            'nul byte' => ["a\0b", 'a b'],
            'comment starters' => ['a ;-- b ; c', 'a -- b c'],
            'argument separator' => ['a,b', 'a b'],
            'parens and brackets' => ['a(b)[c]{d}', 'a b c d'],
            'expressions' => ['${SHELL(id)} $[1+1]', 'SHELL id 1+1'],
            'quotes and escapes' => ['"a" \\b', 'a b'],
            'voicemail assignment' => ['a => b | c ^ d', 'a b c d'],
            'null' => [null, ''],
            'int' => [42, '42'],
            'array' => [['x'], ''],
            'invalid utf-8' => ["a\xffb", 'a?b'],
        ];
    }

    #[DataProvider('texts')]
    public function test_text(mixed $in, string $out): void
    {
        $this->assertSame($out, ConfigValue::text($in));
    }

    public function test_text_is_capped(): void
    {
        $this->assertSame(ConfigValue::TEXT_MAX_LENGTH, mb_strlen(ConfigValue::text(str_repeat('é', 500))));
    }

    /** @return array<string, array{mixed, string}> */
    public static function tokens(): array
    {
        return [
            'number' => ['1001', '1001'],
            'e164' => ['+15550001111', '+15550001111'],
            'pattern' => ['_1[2-9]XX.', '_1[2-9]XX.'],
            'dial string' => ['PJSIP/+15550001111@trunk_3', 'PJSIP/+15550001111@trunk_3'],
            'host and port' => ['sip.example.com:5060', 'sip.example.com:5060'],
            'injection' => ["100,1,System(id)\n;x", '1001Systemidx'],
            'expression' => ['${SHELL(id)}', 'SHELLid'],
            'spaces' => ['1 0 0', '100'],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('tokens')]
    public function test_token(mixed $in, string $out): void
    {
        $this->assertSame($out, ConfigValue::token($in));
    }
}
