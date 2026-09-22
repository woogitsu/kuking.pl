<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Profile;
use App\Support\NazwaUzytkownika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REJESTRACJA NIE MOŻE ODBIĆ CZŁOWIEKA OD ZAPISU NAZWY.
 *
 * SKĄD TEN TEST
 * 63-letnia mama właściciela zakładała konto i dostała: „Nazwa użytkownika
 * może zawierać tylko litery bez polskich znaków, cyfry i podkreślnik" —
 * i nie zrozumiała, o co chodzi. To najgorsze możliwe miejsce na taką
 * przeszkodę: pierwszy ekran produktu, człowiek bez żadnego powodu, żeby
 * próbować drugi raz.
 *
 * TE TESTY PILNUJĄ DWÓCH RZECZY NARAZ, I OBIE SĄ KONIECZNE:
 *  1. że wpisane po ludzku „Małgorzata Kowalska" PRZECHODZI;
 *  2. że wybaczanie zapisu NIE OTWIERA furtki — nazwa zastrzeżona, nazwa
 *     zajęta i nazwa cudza dalej są odrzucane, bo normalizacja stoi PRZED
 *     tymi regułami, nie po nich.
 */
class NazwaUzytkownikaWybaczaZapisTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function zapisy(): array
    {
        return [
            'polskie litery' => ['Małgorzata', 'malgorzata'],
            'imię i nazwisko ze spacją' => ['Małgorzata Kowalska', 'malgorzata_kowalska'],
            'wielkie litery i spacje' => ['Basia Z Podkarpacia', 'basia_z_podkarpacia'],
            'kropki' => ['basia.kowalska', 'basia_kowalska'],
            'łącznik' => ['Anna-Maria', 'anna_maria'],
            'apostrof' => ["O'Brien", 'o_brien'],
            'podwójne spacje i spacja na końcu' => ['  Zofia   Nowak  ', 'zofia_nowak'],
            'wszystkie ogonki' => ['Żółćąęśń', 'zolcaesn'],
            'emoji obok imienia' => ['Basia 🍲', 'basia'],
        ];
    }

    #[Test]
    #[DataProvider('zapisy')]
    public function test_wpisane_po_ludzku_zamienia_sie_w_nazwe_do_adresu(string $wpisane, string $oczekiwane): void
    {
        $this->assertSame($oczekiwane, NazwaUzytkownika::znormalizuj($wpisane));
    }

    #[Test]
    public function test_nazwa_juz_poprawna_zostaje_nietknieta(): void
    {
        // Kto wpisał poprawnie, nie może zobaczyć, że coś mu zmieniliśmy —
        // wielka litera w „Basia_1971" jest jej wyborem, nie pomyłką.
        $this->assertSame('Basia_1971', NazwaUzytkownika::znormalizuj('Basia_1971'));
        $this->assertSame('AniaGotuje', NazwaUzytkownika::znormalizuj('AniaGotuje'));
    }

    #[Test]
    public function test_z_samych_emoji_nie_da_sie_ulozyc_nazwy(): void
    {
        $this->assertSame('', NazwaUzytkownika::znormalizuj('🍲🍲🍲'));
        $this->assertSame('', NazwaUzytkownika::znormalizuj('!!! ??? ...'));
    }

    #[Test]
    public function test_rejestracja_z_polskimi_literami_i_spacja_konczy_sie_kontem(): void
    {
        $odpowiedz = $this->post(route('register'), $this->dane(['username' => 'Małgorzata Kowalska']));

        $odpowiedz->assertSessionHasNoErrors();

        $profil = Profile::query()->where('username', 'malgorzata_kowalska')->first();

        $this->assertNotNull($profil, 'Konto nie powstało — czyli mama właściciela znowu odbiła się od tego ekranu.');
        $this->assertSame('Małgorzata', $profil->display_name);
    }

    #[Test]
    public function test_gdy_nie_da_sie_nic_ulozyc_komunikat_mowi_co_wpisac(): void
    {
        $odpowiedz = $this->post(route('register'), $this->dane(['username' => '🍲🍲🍲']));

        $odpowiedz->assertSessionHasErrors('username');

        $blad = (string) session('errors')->first('username');

        // Komunikat NIE MOŻE wyliczać dozwolonych znaków — to był cały błąd
        // starej wersji. Ma prosić o coś, co człowiek umie podać.
        $this->assertStringNotContainsString('podkreślnik', $blad);
        $this->assertStringNotContainsString('polskich znaków', $blad);
        $this->assertStringContainsString('imię', $blad);
    }

    #[Test]
    public function test_zajeta_nazwa_dostaje_gotowa_wolna_propozycje(): void
    {
        $this->user('malgorzata');

        $odpowiedz = $this->post(route('register'), $this->dane(['username' => 'Małgorzata']));

        $odpowiedz->assertSessionHasErrors('username');

        $blad = (string) session('errors')->first('username');

        // „Spróbuj dodać coś na końcu" to zadanie do wykonania. Wolna nazwa
        // wprost w komunikacie to wyjście, które wystarczy przepisać.
        $this->assertStringContainsString('malgorzata_2', $blad);
    }

    #[Test]
    public function test_normalizacja_nie_omija_nazw_zastrzezonych(): void
    {
        // TO JEST NAJWAŻNIEJSZY TEST W TYM PLIKU. Gdyby normalizacja szła PO
        // sprawdzeniu listy zastrzeżonej, „ądmin" przeszłoby jako nazwa
        // nieznana i zamieniło się w „admin" dopiero przy zapisie.
        $odpowiedz = $this->post(route('register'), $this->dane(['username' => 'ądmin']));

        // Komunikat MUSI być tym od nazwy zastrzeżonej, nie tym od wzorca —
        // to jest jedyny sposób, żeby udowodnić KOLEJNOŚĆ. Gdyby normalizacja
        // szła po sprawdzeniu listy, „ądmin" odbiłoby się o wzorzec i test
        // przechodziłby, nie sprawdzając niczego o zastrzeżeniu.
        $odpowiedz->assertSessionHasErrors(['username' => 'Ta nazwa jest zarezerwowana. Wybierz inną.']);
        $this->assertSame(0, Profile::query()->where('username', 'admin')->count());
    }

    #[Test]
    public function test_normalizacja_nie_omija_zajetosci_bez_rozroznienia_wielkosci_liter(): void
    {
        $this->user('basia');

        $odpowiedz = $this->post(route('register'), $this->dane(['username' => 'BASIA']));

        // Tu z kolei musi zadziałać reguła zajętości — „BASIA" przechodzi
        // wzorzec, więc gdyby normalizacja psuła kolejność, powstałoby drugie
        // konto różniące się tylko wielkością liter (audyt A25).
        $odpowiedz->assertSessionHasErrors(['username' => 'Ta nazwa jest już zajęta. Wpisz zamiast niej BASIA_2 — ta jest wolna.']);
        $this->assertSame(1, Profile::query()->whereRaw('lower(username) = ?', ['basia'])->count());
    }

    #[Test]
    public function test_zmiana_nazwy_w_ustawieniach_wybacza_ten_sam_zapis(): void
    {
        $osoba = $this->user('stara_nazwa');

        $this->actingAs($osoba)
            ->put('/ustawienia/profil', [
                'display_name' => 'Zofia',
                'username' => 'Zofia Nowak',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('zofia_nowak', $osoba->refresh()->profile->username);
    }

    #[Test]
    public function test_propozycja_omija_nazwy_zajete_po_kolei(): void
    {
        $this->user('zofia');
        $this->user('zofia_2');

        // ZAPIS ZOSTAJE TAKI, JAKI CZŁOWIEK WPISAŁ. „Zofia" jest już poprawną
        // nazwą, więc nie sprowadzamy jej do małych liter tylko dlatego, że
        // szukamy wolnego wariantu — propozycja ma być rozpoznawalna jako
        // „to nadal ja". Zajętość i tak sprawdzamy bez rozróżniania wielkości
        // liter, więc `Zofia_2` nie powstanie obok istniejącej `zofia_2`.
        $this->assertSame('Zofia_3', NazwaUzytkownika::wolnaPropozycja('Zofia'));

        // Ta sama nazwa wpisana z polską literą przechodzi przez transliterację,
        // więc propozycja jest już małymi literami.
        $this->assertSame('zofia_3', NazwaUzytkownika::wolnaPropozycja('Zofią'));
    }

    #[Test]
    public function test_propozycja_nie_powstaje_z_niczego(): void
    {
        $this->assertNull(NazwaUzytkownika::wolnaPropozycja('🍲'));
        $this->assertNull(NazwaUzytkownika::wolnaPropozycja('ab'));
    }

    /** @return array<string, mixed> */
    private function dane(array $nadpisz = []): array
    {
        return array_merge([
            'display_name' => 'Małgorzata',
            'username' => 'malgorzata',
            'email' => 'malgorzata@example.test',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ], $nadpisz);
    }
}
