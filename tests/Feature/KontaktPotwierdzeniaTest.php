<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

class KontaktPotwierdzeniaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    private function send(?string $email = 'basia@example.test', array $extra = []): string
    {
        $response = $this->post(route('kontakt.store'), array_merge([
            'kind' => 'blad', 'message' => 'Nie działa dodawanie zdjęcia.', 'contact_email' => $email,
        ], $extra))->assertRedirect();
        $this->withCookie((string) config('session.cookie'), session()->getId());

        return $response->headers->get('Location');
    }

    private function screen(string $url): string
    {
        $response = $this->get($url)->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

        return $this->trescEkranu($response->getContent());
    }

    public function test_bez_wyslania_nie_ma_sukcesu_ani_twierdzenia_o_braku_adresu(): void
    {
        $html = $this->screen(route('kontakt.potwierdzenie'));
        $this->assertStringNotContainsString('Mamy Twoją wiadomość', $html);
        $this->assertStringNotContainsString('W formularzu nie było adresu', $html);
        $this->assertStringContainsString('Nie wysyłaj jej ponownie tylko z tego powodu.', $html);
        $this->assertSame(0, ContactMessage::count());
    }

    public function test_adres_i_brak_adresu_przetrwaja_odswiezenie_i_inna_strone(): void
    {
        foreach (['basia@example.test', null] as $email) {
            $url = $this->send($email);
            $this->assertStringNotContainsString('basia@example.test', $url);
            for ($i = 0; $i < 3; $i++) {
                $html = $this->screen($url);
                $this->assertStringContainsString('Mamy Twoją wiadomość', $html);
                $this->assertStringContainsString($email ?? 'nie mamy jak odpisać', $html);
                $this->get(route('kontakt'))->assertOk();
            }
        }
        $this->assertSame(2, ContactMessage::count());
    }

    public function test_dwie_karty_nie_podmieniaja_sobie_potwierdzen(): void
    {
        $first = $this->send('pierwszy@example.test');
        $second = $this->send('drugi@example.test');
        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('pierwszy@example.test', $this->screen($first));
        $this->assertStringNotContainsString('drugi@example.test', $this->screen($first));
        $this->assertStringContainsString('drugi@example.test', $this->screen($second));
        $this->assertSame(2, ContactMessage::count());
    }

    public function test_potwierdzenie_wygasa_i_nie_jest_dostepne_w_innej_sesji(): void
    {
        $url = $this->send();
        $this->travel(31)->minutes();
        $html = $this->screen($url);
        $this->assertStringNotContainsString('Mamy Twoją wiadomość', $html);
        $this->assertStringNotContainsString('basia@example.test', $html);
        $this->travelBack();
        $url = $this->send();
        session()->invalidate();
        session()->save();
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->assertStringNotContainsString('basia@example.test', $this->screen($url));
    }

    public function test_poprawiony_email_tworzy_nowa_wiadomosc_bez_nadpisania_poprzedniej(): void
    {
        config(['logging.channels.blad_webhook.url' => 'https://example.test/webhook']);
        Log::shouldReceive('channel')->with('blad_webhook')->times(4)->andReturnSelf();
        Log::shouldReceive('error')->times(4);
        $this->pozwolNaKontekstKorelacji();
        foreach (['bledny@example.test', null] as $old) {
            $key = (string) Str::uuid7();
            $this->send($old, ['klucz_wyslania' => $key]);
            $original = ContactMessage::where('klucz_wyslania', $key)->sole();
            $url = $this->send('poprawny@example.test', ['klucz_wyslania' => $key]);
            $this->assertSame($old, $original->fresh()->contact_email);
            $this->assertStringContainsString('poprawny@example.test', $this->screen($url));
        }
        $this->assertSame(4, ContactMessage::count());
    }

    public function test_identyczne_powtorzenie_ma_jeden_zapis_i_jeden_dzwonek(): void
    {
        config(['logging.channels.blad_webhook.url' => 'https://example.test/webhook']);
        Log::shouldReceive('channel')->with('blad_webhook')->once()->andReturnSelf();
        Log::shouldReceive('error')->once();
        $this->pozwolNaKontekstKorelacji();
        $key = (string) Str::uuid7();
        $this->send(null, ['klucz_wyslania' => $key]);
        $this->send(null, ['klucz_wyslania' => $key]);
        $this->assertSame(1, ContactMessage::count());
    }

    public function test_zalogowany_ma_wykonalna_instrukcje_i_poczta_jako_alternatywe(): void
    {
        $this->actingAs($this->user('basia', ['email' => 'konto@example.test']));
        $html = $this->screen($this->send('podstawiony@example.test'));
        $this->assertStringContainsString('konto@example.test', $html);
        $this->assertStringContainsString('href="'.route('settings.email').'"', $html);
        $this->assertStringContainsString('mailto:'.config('kuking.community.contact_email'), $html);
        $this->assertStringNotContainsString('napisz do nas jeszcze raz', $html);
        $this->assertNull(ContactMessage::sole()->contact_email);
        $this->get(route('settings.email'))->assertOk();
    }

    public function test_gosc_ma_instrukcje_poprawienia_adresu(): void
    {
        $html = $this->screen($this->send());
        $this->assertStringContainsString('napisz do nas jeszcze raz', $html);
        $this->assertStringContainsString(route('kontakt'), $html);
    }

    public function test_podstawiony_email_zalogowanego_nie_zmienia_tozsamosci_powtorzenia(): void
    {
        $this->actingAs($this->user('konto', ['email' => 'konto@example.test']));
        $key = (string) Str::uuid7();
        $this->send('pierwszy@example.test', ['klucz_wyslania' => $key]);
        $url = $this->send('drugi@example.test', ['klucz_wyslania' => $key]);
        $this->assertSame(1, ContactMessage::count());
        $this->assertNull(ContactMessage::sole()->contact_email);
        $this->assertStringContainsString('konto@example.test', $this->screen($url));
    }

    public function test_zmiana_konta_i_nieprawidlowy_klucz_nie_pokazuja_potwierdzenia(): void
    {
        $this->actingAs($this->user('pierwsze'));
        $url = $this->send();
        $this->actingAs($this->user('drugie'));
        $this->assertStringNotContainsString('Mamy Twoją wiadomość', $this->screen($url));
        $this->assertStringNotContainsString('Mamy Twoją wiadomość', $this->screen(route('kontakt.potwierdzenie', ['potwierdzenie' => ['zly']])));
    }

    /**
     * PRZEPUSZCZA KONTEKST KORELACJI PRZEZ ATRAPĘ DZIENNIKA.
     *
     * `Log::shouldReceive()` podmienia CAŁY `LogManager` na ścisłą atrapę
     * Mockery: każda metoda bez zadeklarowanego oczekiwania rzuca
     * `BadMethodCallException`. Te dwa testy deklarują `channel()` i
     * `error()`, bo o nie im chodzi — ale `CorrelateRequest`
     * (`bootstrap/app.php`, grupa `web`) woła przy KAŻDYM żądaniu jeszcze
     * `shareContext()`, `sharedContext()`, `withoutContext()` oraz
     * `flushSharedContext()`, żeby identyfikator żądania dożył do
     * wyrenderowania strony błędu.
     *
     * Bez tych zgód pierwszy `$this->send(...)` kończył się HTTP 500
     * z „Received …LogManager::shareContext(), but no expectations were
     * specified" — czyli test mierzył własną atrapę, nie formularz kontaktu.
     *
     * `byDefault()` jest tu istotne: to ZGODA, nie oczekiwanie. Liczbę
     * wywołań pilnują `times(4)` i `once()` wyżej, i mają dalej pilnować —
     * gdyby te cztery metody były zwykłymi oczekiwaniami, każda zmiana
     * liczby żądań w teście zmieniałaby też jego wynik z powodu, o który
     * temu testowi nie chodzi.
     */
    private function pozwolNaKontekstKorelacji(): void
    {
        Log::shouldReceive('shareContext')->andReturnSelf()->byDefault();
        Log::shouldReceive('sharedContext')->andReturn([])->byDefault();
        Log::shouldReceive('withoutContext')->andReturnSelf()->byDefault();
        Log::shouldReceive('flushSharedContext')->andReturnSelf()->byDefault();
    }
}
