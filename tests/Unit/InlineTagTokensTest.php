<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tags\InlineTagTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InlineTagTokensTest extends TestCase
{
    public static function bodies(): array
    {
        return [
            'początek środek koniec i dedup' => ['#Sernik dziś #chleb, potem #sernik', ['sernik', 'chleb']],
            'nawiasy cudzysłów nowa linia' => ["(#sernik) [#chleb] {#zupa} \"#obiad\"\n#kolacja", ['sernik', 'chleb', 'zupa', 'obiad', 'kolacja']],
            'NFC i NFD' => ["#Z\u{0307}UREK #żurek", ['żurek']],
            'slug' => ['#zupa-pomidorowa', ['zupa-pomidorowa']],
            'URL i środek słowa' => ['https://example.test/#sernik /strona#chleb słowo#zupa e@mail#obiad', []],
            'niedozwolony cały token' => ['#sernik_abc #_sernik #-sernik #a ##chleb', []],
            'bez ucinania długiego tokenu' => ['#'.str_repeat('a', 41), []],
            'cyfra jest dozwolona' => ['#2026 #chleb2', ['2026', 'chleb2']],
            'pusty' => [null, []],
        ];
    }

    #[DataProvider('bodies')]
    public function test_wydobywa_wylacznie_cale_tokeny(?string $body, array $expected): void
    {
        $this->assertSame($expected, (new InlineTagTokens)->fromBody($body));
    }
}
