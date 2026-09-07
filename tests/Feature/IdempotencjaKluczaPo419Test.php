<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Support\OdzyskiwalneDane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Klucz wysłania musi wrócić na ekranie 419 — i dlatego nie wolno mu mieć
 * w nazwie fragmentu „token" (ADR §1.4.4, POMIAR 4).
 *
 * `OdzyskiwalneDane::jestWrazliwe()` dopasowuje po FRAGMENCIE nazwy, a lista
 * fragmentów zawiera `token`. Ekran 419 przenosi pola jako ukryte, więc pole
 * nazwane `token_wyslania` albo `idempotency_token` NIE wróciłoby — i ponowne
 * wysłanie po wygaśnięciu sesji byłoby dla serwera nowym wysłaniem. Czyli:
 * mechanizm przestawałby działać dokładnie w tej jednej sytuacji, w której
 * wiemy na pewno, że człowiek kliknie „wyślij" drugi raz.
 *
 * Filtr działa prawidłowo — to nazwa pola musi być inna. Ten test broni
 * pomiaru, który najłatwiej przypadkowo zepsuć zmianą nazwy pola.
 */
class IdempotencjaKluczaPo419Test extends TestCase
{
    use RefreshDatabase;

    private const ZNACZNIK_KLUCZA = '01a07cc1-0000-7000-8000-00000000abcd';

    /**
     * Prawdziwy 419, przez HTTP — ta sama metoda co
     * w `SekretyNieWracajaNaEkranTest` (`PreventRequestForgery` przepuszcza
     * każde żądanie pod PHPUnitem, więc bez podmiany środowiska ten test
     * sprawdzałby własną atrapę).
     *
     * @param  array<string, mixed>  $dane
     */
    private function ekran419(string $adres, array $dane): string
    {
        $poprzednie = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $odpowiedz = $this->withSession(['_token' => 'token-sesji'])
                ->call('POST', $adres, $dane + ['_token' => 'token-z-wygaslej-strony']);
        } finally {
            $this->app['env'] = $poprzednie;
        }

        $odpowiedz->assertStatus(419);

        return $odpowiedz->getContent() ?: '';
    }

    public function test_nazwa_z_fragmentem_token_jest_wycinana_a_klucz_wyslania_nie(): void
    {
        // POMIAR 4 z ADR-u, powtórzony jako asercja. Pierwsze dwa wiersze są
        // kontrolą: gdyby filtr przestał wycinać `token`, ten test padłby
        // i pokazał, że pomiar przestał obowiązywać.
        $this->assertTrue(OdzyskiwalneDane::jestWrazliwe('token_wyslania'));
        $this->assertTrue(OdzyskiwalneDane::jestWrazliwe('idempotency_token'));

        $this->assertFalse(OdzyskiwalneDane::jestWrazliwe('klucz_wyslania'));
    }

    public function test_ekran_419_przenosi_klucz_wyslania_z_formularza_wpisu(): void
    {
        $html = $this->ekran419('/dodaj/zdjecie', [
            'body' => 'Rosół na niedzielę, pisany długo.',
            'visibility' => 'public',
            'klucz_wyslania' => self::ZNACZNIK_KLUCZA,
        ]);

        $this->assertStringContainsString(
            self::ZNACZNIK_KLUCZA,
            $html,
            'Klucz wysłania nie wrócił na ekranie 419 — po wygaśnięciu sesji ponowne wysłanie utworzyłoby drugi wpis.',
        );
    }

    public function test_ekran_419_nie_przenosi_pola_z_fragmentem_token(): void
    {
        // Druga strona tej samej monety: to nie jest usterka filtra, tylko
        // powód, dla którego nazwa kolumny i pola brzmi `klucz_wyslania`.
        $html = $this->ekran419('/dodaj/zdjecie', [
            'body' => 'Rosół na niedzielę, pisany długo.',
            'visibility' => 'public',
            'token_wyslania' => self::ZNACZNIK_KLUCZA,
        ]);

        $this->assertStringNotContainsString(self::ZNACZNIK_KLUCZA, $html);
    }

    public function test_formularz_odzyskany_po_419_publikuje_dokladnie_jeden_wpis(): void
    {
        // Cała droga z issue #81, od początku do końca: sesja wygasła,
        // człowiek widzi swój tekst, klika „Wyślij jeszcze raz" — a potem,
        // bo strona znów myśli chwilę, klika drugi raz. Wpis ma być jeden.
        $osoba = $this->user('powygasnieciu');

        $this->actingAs($osoba);

        $html = $this->ekran419('/dodaj/zdjecie', [
            'body' => 'Rosół na niedzielę, pisany przed przerwą na garnek.',
            'visibility' => 'public',
            'klucz_wyslania' => self::ZNACZNIK_KLUCZA,
        ]);

        $this->assertSame(1, preg_match('/name="klucz_wyslania" value="([^"]+)"/', $html, $trafienia));

        $odzyskany = [
            'body' => 'Rosół na niedzielę, pisany przed przerwą na garnek.',
            'visibility' => 'public',
            'klucz_wyslania' => $trafienia[1],
        ];

        $this->actingAs($osoba)->post(route('posts.store'), $odzyskany)->assertSessionHasNoErrors();
        $this->actingAs($osoba)->post(route('posts.store'), $odzyskany)->assertSessionHasNoErrors();

        $this->assertSame(1, Post::query()->count(), 'Po ekranie 419 dwa kliknięcia dały dwa wpisy.');
        $this->assertSame(self::ZNACZNIK_KLUCZA, Post::query()->firstOrFail()->klucz_wyslania);
    }

    public function test_ekran_419_przenosi_klucz_z_formularza_ugotowalem_i_zgloszenia(): void
    {
        foreach (['/przepisy/rosol-babci/ugotowalem', '/zglos-nielegalna-tresc'] as $adres) {
            $html = $this->ekran419($adres, [
                'note' => 'Wyszło pięknie.',
                'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody.',
                'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
                'reason' => 'copyright',
                'klucz_wyslania' => self::ZNACZNIK_KLUCZA,
            ]);

            $this->assertStringContainsString(self::ZNACZNIK_KLUCZA, $html, "Klucz zginął na trasie {$adres}.");
        }
    }
}
