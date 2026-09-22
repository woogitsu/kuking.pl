<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regresja produkcji przy 320 px: normal ma 300 px, formularz tylko 246 px.
 * Sprawdzamy konfigurację rzeczywistego widgetu w odpowiedziach tras, a nie
 * inne data-theme z obudowy. Geometrię zewnętrznej ramki mierzy przeglądarka.
 */
class TurnstileRozmiarIMotywTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.turnstile.klucz_publiczny' => '1x00000000000000000000AA',
            'kuking.turnstile.sekret' => '1x0000000000000000000000000000000AA',
            'mail.default' => 'smtp',
        ]);
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function formularzeIMotywy(): iterable
    {
        foreach (['/login', '/register', '/nie-pamietam-hasla', '/logowanie/link', '/napisz-do-nas', '/cofnij-usuniecie-konta', '/zglos-nielegalna-tresc'] as $adres) {
            foreach ([null, 'light', 'dark', 'nieznany'] as $ciasteczko) {
                yield $adres.' '.($ciasteczko ?? 'domyslny') => [$adres, $ciasteczko, $ciasteczko === 'dark' ? 'dark' : 'light'];
            }
        }
    }

    #[DataProvider('formularzeIMotywy')]
    public function test_formularz_ma_waski_widget_w_motywie_strony(string $adres, ?string $ciasteczko, string $motyw): void
    {
        if ($ciasteczko !== null) {
            $this->withCookie((string) config('kuking.theme.cookie'), $ciasteczko);
        }

        $this->sprawdzWidget($adres, $motyw);
    }

    public function test_wybor_konta_ma_pierwszenstwo_przed_ciasteczkiem(): void
    {
        foreach (['dark' => 'light', 'light' => 'dark'] as $konto => $ciasteczko) {
            $this->actingAs($this->user(null, ['theme' => $konto]))
                ->withCookie((string) config('kuking.theme.cookie'), $ciasteczko);

            $this->sprawdzWidget('/napisz-do-nas', $konto);
        }
    }

    private function sprawdzWidget(string $adres, string $motyw): void
    {
        $odpowiedz = $this->get($adres)->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML($odpowiedz->getContent());
        $xpath = new DOMXPath($dom);
        $widgety = $xpath->query('//div[@class="cf-turnstile"]');
        $this->assertCount(1, $widgety, $adres);
        $widget = $widgety->item(0);
        $this->assertInstanceOf(DOMElement::class, $widget);
        $this->assertSame('compact', $widget->getAttribute('data-size'), $adres);
        $this->assertSame($motyw, $widget->getAttribute('data-theme'), $adres);
        $html = $dom->documentElement;
        $this->assertSame($motyw, $html->getAttribute('data-theme') ?: 'light', $adres);
        $this->assertSame('pl', $widget->getAttribute('data-language'));
        $this->assertSame('1x00000000000000000000AA', $widget->getAttribute('data-sitekey'));
        $this->assertCount(1, $xpath->query('ancestor::form//input[@name="_token"]', $widget));
        $this->assertCount(1, $xpath->query('ancestor::form//noscript', $widget));
        $skrypty = $xpath->query('//script[@src="https://challenges.cloudflare.com/turnstile/v0/api.js"]');
        $this->assertCount(1, $skrypty);
        $skrypt = $skrypty->item(0);
        $this->assertInstanceOf(DOMElement::class, $skrypt);
        $nonce = $skrypt->getAttribute('nonce');
        $this->assertNotSame('', $nonce);
        $this->assertStringContainsString("'nonce-{$nonce}'", (string) $odpowiedz->headers->get('Content-Security-Policy'));
    }
}
