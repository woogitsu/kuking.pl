<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Zgłoszenie CSP nie wnosi tokenów do logu (audyt W3-14).
 *
 * SKĄD SEKRET W ZGŁOSZENIU CSP
 * Przeglądarka przysyła PEŁNY adres strony, na której doszło do naruszenia,
 * a adresy tego serwisu niosą sekrety wprost w ścieżce i w zapytaniu:
 *
 *     /nowe-haslo/{token}
 *     /potwierdz-email/{id}/{hash}?expires=…&signature=…
 *
 * Wystarczy wtyczka przeglądarki blokująca skrypt na stronie zmiany hasła
 * i token resetu ląduje w naszym logu — gdzie zostaje. To ta sama klasa
 * błędu co zalogowanie hasła: dane trafiają tam, gdzie nikt ich nie szuka.
 *
 * Endpoint jest z konieczności publiczny (zgłoszenie wysyła sama przeglądarka,
 * bez sesji i bez tokenu CSRF), więc nie chroni go żadna autoryzacja.
 */
class LogCspNieZapisujeSekretowTest extends TestCase
{
    use RefreshDatabase;

    private function zglos(string $adresStrony, string $zablokowany = 'inline'): void
    {
        $this->call('POST', route('csp.report'), [], [], [], [], json_encode([
            'csp-report' => [
                'document-uri' => $adresStrony,
                'effective-directive' => 'script-src',
                'blocked-uri' => $zablokowany,
            ],
        ]))->assertNoContent();
    }

    public function test_token_zmiany_hasla_nie_trafia_do_logu(): void
    {
        $log = Log::spy();

        $token = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0';

        $this->zglos("https://kuking.pl/nowe-haslo/{$token}");

        $log->shouldHaveReceived('info')->once()->withArgs(
            function (string $wiadomosc, array $kontekst) use ($token): bool {
                $wszystko = json_encode($kontekst, JSON_UNESCAPED_SLASHES);

                $this->assertStringNotContainsString($token, (string) $wszystko);

                // Trasa ZOSTAJE — po to jest ten log. Bez niej nie wiadomo,
                // która strona psuje politykę.
                $this->assertStringContainsString('nowe-haslo', (string) $wszystko);

                return true;
            },
        );
    }

    public function test_podpis_linku_weryfikacyjnego_nie_trafia_do_logu(): void
    {
        $log = Log::spy();

        $this->zglos(
            'https://kuking.pl/potwierdz-email/9f1c2a34-1111-2222-3333-444455556666/'
            .str_repeat('b', 40).'?expires=1788000000&signature='.str_repeat('c', 64),
        );

        $log->shouldHaveReceived('info')->once()->withArgs(
            function (string $wiadomosc, array $kontekst): bool {
                $wszystko = (string) json_encode($kontekst, JSON_UNESCAPED_SLASHES);

                // Całe zapytanie wycinamy — nie ma w nim nic, co byłoby
                // potrzebne do diagnozy, a jest podpis i termin ważności.
                $this->assertStringNotContainsString('signature', $wszystko);
                $this->assertStringNotContainsString('expires', $wszystko);
                $this->assertStringNotContainsString(str_repeat('b', 40), $wszystko);

                // UUID konta też nie — to identyfikator człowieka.
                $this->assertStringNotContainsString('9f1c2a34', $wszystko);

                return true;
            },
        );
    }

    /**
     * NAZWA KONTA TO NIE SEKRET, TYLKO TOŻSAMOŚĆ — i też nie ma prawa
     * zostać w logu platformy (issue #1083).
     *
     * `/@{username}` i wszystko, co pod nim wisi, to trasy niosące w ścieżce
     * nazwę konta. Naruszenie CSP na takiej stronie prowokuje byle wtyczka
     * przeglądarki, a przy otwartej rejestracji lista „kto kiedy czyj profil
     * oglądał" rośnie w logu sama, bez żadnej decyzji człowieka.
     *
     * DLACZEGO TEN TEST MA DWIE ASERCJE, A NIE JEDNĄ
     * Samo „log nie zawiera nazwy" przechodzi na pustym kontekście, przy
     * braku wywołania i przy wyjątku po drodze. Dlatego obok stoi asercja
     * DODATNIA: wpis musi zawierać znacznik `@[UZYTKOWNIK]`, czyli dowód,
     * że kontroler ten segment naprawdę zobaczył i naprawdę go zastąpił.
     */
    public function test_nazwa_konta_z_adresu_profilu_nie_trafia_do_logu(): void
    {
        $log = Log::spy();

        $this->zglos('https://kuking.pl/@basia-z-podlasia/obserwujacy');

        $log->shouldHaveReceived('info')->once()->withArgs(
            function (string $wiadomosc, array $kontekst): bool {
                $wszystko = (string) json_encode($kontekst, JSON_UNESCAPED_SLASHES);

                $this->assertStringNotContainsString('basia-z-podlasia', $wszystko);

                // Kontrola dodatnia: segment został ROZPOZNANY i zastąpiony,
                // a nie zgubiony po drodze razem z całym wpisem.
                $this->assertStringContainsString('@[UZYTKOWNIK]', $wszystko);

                // Reszta trasy ZOSTAJE — po to ten log istnieje: bez
                // `obserwujacy` nie wiadomo, który ekran psuje politykę.
                $this->assertStringContainsString('obserwujacy', $wszystko);

                return true;
            },
        );
    }

    /**
     * Nazwa konta bywa też w polu `blocked-uri` — gdy polityka zablokuje
     * zasób ładowany z adresu profilu (np. zdjęcie profilowe podane
     * przez trasę pod `/@…`). To ta sama ścieżka do tego samego logu.
     */
    public function test_nazwa_konta_nie_przechodzi_takze_polem_zablokowanego_zasobu(): void
    {
        $log = Log::spy();

        $this->zglos('https://kuking.pl/home', 'https://kuking.pl/@jankowalski');

        $log->shouldHaveReceived('info')->once()->withArgs(
            function (string $wiadomosc, array $kontekst): bool {
                $this->assertStringNotContainsString('jankowalski', (string) $kontekst['zablokowane']);
                $this->assertSame('https://kuking.pl/@[UZYTKOWNIK]', $kontekst['zablokowane']);

                return true;
            },
        );
    }

    public function test_zwykly_adres_przepisu_zostaje_czytelny(): void
    {
        // Kontrola w drugą stronę: gdyby czyszczenie było zbyt szerokie,
        // log przestałby odpowiadać na pytanie, po co istnieje.
        $log = Log::spy();

        $this->zglos('https://kuking.pl/przepis/rosol-babci-zofii');

        $log->shouldHaveReceived('info')->once()->withArgs(
            function (string $wiadomosc, array $kontekst): bool {
                $this->assertSame('https://kuking.pl/przepis/rosol-babci-zofii', $kontekst['strona']);

                return true;
            },
        );
    }

    public function test_wartosci_nieadresowe_przechodza_bez_zmian(): void
    {
        // `inline`, `eval`, `data` — nie mają ścieżki i nic z nich nie wycieka,
        // a są najczęstszą treścią pola `blocked-uri`.
        $log = Log::spy();

        $this->zglos('https://kuking.pl/home', 'inline');

        $log->shouldHaveReceived('info')->once()->withArgs(
            fn (string $wiadomosc, array $kontekst): bool => $kontekst['zablokowane'] === 'inline',
        );
    }
}
