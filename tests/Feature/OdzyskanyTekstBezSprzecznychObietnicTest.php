<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OdzyskanyTekstBezSprzecznychObietnicTest extends TestCase
{
    use RefreshDatabase;

    public static function odpowiedzi(): array
    {
        return [[419], [429]];
    }

    #[DataProvider('odpowiedzi')]
    public function test_czesciowe_odzyskanie_nie_obiecuje_calosci_takze_przy_zdjeciu(int $status): void
    {
        // 51 poprawnych kroków nie jest już nadmiarem (#524). Obcięcie
        // wymuszamy polem większym od jawnego bezpiecznika pojedynczej wartości.
        $dane = ['title' => 'Przepis zachowany w części', 'steps' => [['instruction' => str_repeat('a', 4000)], ['instruction' => str_repeat('b', 200001)]], 'hero_photo' => UploadedFile::fake()->image('obiad.jpg')];
        $html = $this->odpowiedz($status, $dane);
        $this->assertStringContainsString('Przepis zachowany w części', $html);
        $this->assertStringContainsString('name="steps[0][instruction]"', $html);
        $this->assertStringNotContainsString('name="steps[1][instruction]"', $html);
        $this->assertStringContainsString('nie wszystko udało się przenieść', $html);
        $this->assertStringContainsString('Wybierz zdjęcie jeszcze raz', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('Wyślij jeszcze raz', $html);
        $this->assertStringNotContainsString('nic nie przepadło', $html);
        $this->assertStringNotContainsString('nic z niego nie przepadło', $html);
        $this->assertStringNotContainsString('brakuje tylko pliku', $html);
    }

    #[DataProvider('odpowiedzi')]
    public function test_brak_odzyskanych_pol_nie_oznacza_braku_wpisanego_tekstu(int $status): void
    {
        $html = $this->odpowiedz($status, ['body' => str_repeat('z', 200001)]);
        $this->assertStringContainsString('Nie udało się odzyskać tekstu', $html);
        $this->assertStringNotContainsString('Nie było w nim jednak nic do zapisania', $html);
        $this->assertStringNotContainsString('nic nie przepadło', $html);
        $this->assertStringNotContainsString('Wyślij jeszcze raz', $html);
        $this->assertStringNotContainsString('Nic się nie zepsuło', $html);
        $this->assertStringNotContainsString('name="body"', $html);
    }

    #[DataProvider('odpowiedzi')]
    public function test_zwykly_tekst_nadal_wraca_w_calosci(int $status): void
    {
        $tekst = 'Własny opis przygotowania obiadu, zachowany w całości po odbiciu formularza.';
        $html = $this->odpowiedz($status, ['body' => $tekst, 'hero_photo' => UploadedFile::fake()->image('obiad.jpg')]);
        $this->assertStringContainsString($tekst, $html);
        $this->assertStringContainsString('Twój tekst jest na miejscu', $html);
        $this->assertStringContainsString('nic z niego nie przepadło', $html);
        $this->assertStringContainsString('Wybierz zdjęcie jeszcze raz', $html);
        $this->assertStringNotContainsString('nic nie przepadło', $html);
        $this->assertStringContainsString('Wyślij jeszcze raz', $html);
        $this->assertStringNotContainsString('nie wszystko udało się przenieść', $html);
        $this->assertStringNotContainsString('Nie udało się odzyskać tekstu', $html);
    }

    public function test_publiczne_zgloszenie_po_419_nie_odsyla_do_logowania(): void
    {
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $response = $this->withSession(['_token' => 'token-sesji'])->post(route('zglos.nielegalna.store'), [
                '_token' => 'wygasly-token',
                'illegality_explanation' => 'Własne uzasadnienie zgłoszenia, które można wysłać bez zakładania konta.',
            ]);
        } finally {
            $this->app['env'] = $env;
        }
        $response->assertStatus(419);
        $response->assertSee('Własne uzasadnienie zgłoszenia');
        $response->assertSee('Wyślij jeszcze raz');
        $response->assertDontSee('Najpierw zaloguj się jeszcze raz');
        $response->assertDontSee('Otwórz logowanie w nowej karcie');
        $this->assertGuest();
    }

    public function test_prywatny_formularz_po_419_nadal_prosi_goscia_o_logowanie(): void
    {
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $response = $this->withSession(['_token' => 'token-sesji'])->post(route('recipes.store'), [
                '_token' => 'wygasly-token',
                'body' => 'Własny opis przepisu zachowany przed ponownym logowaniem autora.',
            ]);
        } finally {
            $this->app['env'] = $env;
        }
        $response->assertStatus(419);
        $response->assertSee('Własny opis przepisu zachowany');
        $response->assertSee('Najpierw zaloguj się jeszcze raz');
        $response->assertSee('Otwórz logowanie w nowej karcie');
        $this->assertGuest();
    }

    private function odpowiedz(int $status, array $dane): string
    {
        $this->actingAs($this->user('odczyt'));
        if ($status === 429) {
            // Zwykłe niepoprawne POST zużywają rzeczywisty limit, bez zapisu przepisu.
            for ($i = 0; $i < 20; $i++) {
                $this->post(route('recipes.store'), [])->assertStatus(302);
            }
            $response = $this->post(route('recipes.store'), $dane);
            $this->assertNotNull($response->headers->get('Retry-After'));
        } else {
            $env = $this->app['env'];
            $this->app['env'] = 'production';
            try {
                $response = $this->withSession(['_token' => 'token-sesji'])->post(route('recipes.store'), $dane + ['_token' => 'wygasly-token']);
            } finally {
                $this->app['env'] = $env;
            }
        }
        $response->assertStatus($status);
        $this->assertDatabaseCount('recipes', 0);
        preg_match('~<main\b[^>]*>(.*?)</main>~s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }
}
