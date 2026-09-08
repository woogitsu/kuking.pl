<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Z Kuking da się wyjść — i widać, gdzie.
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ
 * Trasa `POST /logout` istniała od początku i działała. W warstwie widoków
 * nie było do niej ANI JEDNEGO odwołania:
 *
 *     grep -rn "route('logout')" resources/views/   →   0 trafień
 *
 * Jedyne „Wyloguj" w całym serwisie to „Wyloguj INNE urządzenia" na
 * `/ustawienia/bezpieczenstwo` — czyli coś zupełnie innego, i jeszcze
 * schowane na ekranie, do którego trzeba trafić. Człowiek na cudzym albo
 * wspólnym komputerze nie miał jak wyjść z konta.
 *
 * Tego nie znalazł audyt kodu, tylko obchód żywej strony przeglądarką.
 * Warto to zapisać: brakujący ODNOŚNIK jest niewidoczny dla narzędzi,
 * które czytają trasy i kontrolery — trasa była, kontroler był, test
 * trasy przechodził.
 *
 * DWA MIEJSCA, BO NAWIGACJA BOCZNA ZNIKA NA TELEFONIE
 * `.side-nav` ma `display: none` poniżej 64rem (`app.css`), a pasek dolny
 * nie ma pozycji „Ustawienia". Dlatego wylogowanie stoi też na własnym
 * profilu — jedynym ekranie z obsługą konta w zasięgu kciuka. Oba testy
 * niżej są potrzebne: pierwszy pilnuje desktopu, drugi telefonu.
 */
class DaSieWylogowacTest extends TestCase
{
    use RefreshDatabase;

    public function test_nawigacja_boczna_ma_wylogowanie(): void
    {
        $czlowiek = $this->user('wychodzi');

        $odpowiedz = $this->actingAs($czlowiek)->get(route('home'));

        $odpowiedz->assertOk();
        $odpowiedz->assertSee(route('logout'), escape: false);
        $odpowiedz->assertSee('Wyloguj się');
    }

    /**
     * Na telefonie nawigacji bocznej nie ma, a pasek dolny prowadzi na
     * własny profil — więc to tam musi stać wyjście.
     */
    public function test_wlasny_profil_ma_wylogowanie(): void
    {
        $czlowiek = $this->user('wychodzi2');

        $odpowiedz = $this->actingAs($czlowiek)
            ->get(route('profile.show', $czlowiek->profile->username));

        $odpowiedz->assertOk();
        $odpowiedz->assertSee(route('logout'), escape: false);
    }

    /**
     * Na CUDZYM profilu wylogowanie jest tylko raz — w nawigacji.
     *
     * Bezpiecznik przed najgłupszym możliwym błędem tej zmiany: składnik
     * wstawiony poza `@if($isOwner)` dawałby „Wyloguj się" w rzędzie akcji
     * na profilu każdej oglądanej osoby, co czyta się jak „wyloguj JĄ".
     *
     * LICZYMY WYSTĄPIENIA, NIE SPRAWDZAMY OBECNOŚCI — i to jest poprawka
     * mojego własnego, pierwszego podejścia. `assertDontSee` na tym adresie
     * padało, i słusznie: nawigacja boczna stoi na KAŻDEJ stronie po
     * zalogowaniu, więc jedno wystąpienie jest tam zawsze i ma być.
     * Pytanie brzmi, czy doszło DRUGIE, z rzędu akcji profilu.
     */
    public function test_na_cudzym_profilu_wylogowanie_jest_tylko_w_nawigacji(): void
    {
        $czlowiek = $this->user('ogladajacy');
        $ktosInny = $this->user('ogladany');

        $wlasny = $this->actingAs($czlowiek)
            ->get(route('profile.show', $czlowiek->profile->username))
            ->getContent();

        $cudzy = $this->actingAs($czlowiek)
            ->get(route('profile.show', $ktosInny->profile->username))
            ->getContent();

        $this->assertSame(
            2,
            substr_count((string) $wlasny, route('logout')),
            'Na własnym profilu wylogowanie ma być dwa razy: w nawigacji i w rzędzie akcji.',
        );

        $this->assertSame(
            1,
            substr_count((string) $cudzy, route('logout')),
            'Na cudzym profilu wylogowanie wyszło poza nawigację — czyta się jak „wyloguj tę osobę”.',
        );
    }

    /**
     * Gość nie widzi wyjścia z konta, którego nie ma.
     */
    public function test_niezalogowany_nie_widzi_wylogowania(): void
    {
        $odpowiedz = $this->get(route('landing'));

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee(route('logout'), escape: false);
    }

    /**
     * I najważniejsze: przycisk naprawdę wylogowuje.
     *
     * Bez tego testy wyżej dowodziłyby tylko, że w kodzie strony stoi
     * odpowiedni adres — a nie, że kliknięcie cokolwiek robi.
     */
    public function test_wyslanie_formularza_konczy_sesje(): void
    {
        $czlowiek = $this->user('konczy');

        $this->actingAs($czlowiek)->post(route('logout'))->assertRedirect();

        $this->assertGuest();
    }
}
