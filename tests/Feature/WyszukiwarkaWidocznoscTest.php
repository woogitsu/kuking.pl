<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `SearchController`/`SearchQuery` a `RecipePolicy`/`UserPolicy` — ta sama
 * granica widoczności musi obowiązywać w obu warstwach. Ta klasa testów
 * NIE zakłada usterki: kod czytany przed napisaniem tych testów wygląda
 * na poprawny (autor aktywny, blokada w obie strony, `publiclyVisible()`
 * wycina status/widoczność) — testy to MIERZĄ, zamiast wierzyć lekturze.
 *
 * PUŁAPKA ECHA FRAZY (patrz raport, punkt 4): `pages/search.blade.php`
 * powtarza `$phrase` w polu formularza, w `href` chipów zakresu i w tekście
 * pustego wyniku. Dlatego ŻADNA asercja w tym pliku nie sprawdza obecności
 * samej wyszukiwanej frazy — zawsze sprawdzamy odrębny znacznik (tytuł
 * przepisu, `display_name`), którego fraza nie zawiera. Test
 * `test_fraza_faktycznie_wraca_w_echu_co_udowadnia_pulapke` pokazuje to
 * wprost: udowadnia, że `assertSee($phrase)` przechodziłby ZAWSZE, nawet
 * przy zerze wyników — czyli że taka asercja niczego by nie sprawdzała.
 */
class WyszukiwarkaWidocznoscTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(User $autor, string $tytul, array $attrs = []): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create(array_merge([
            'title' => $tytul,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ], $attrs));
    }

    public function test_fraza_faktycznie_wraca_w_echu_co_udowadnia_pulapke(): void
    {
        // Zero wyników, a mimo to fraza jest na stronie (pole formularza,
        // chipy zakresu, tekst „nic nie znaleźliśmy"). Gdyby test wyżej
        // sprawdzał `assertSee('absolutnieniematakiejfrazyxyz')`, przeszedłby
        // ZAWSZE — i to jest dokładnie pułapka, przed którą ostrzega zadanie.
        $odpowiedz = $this->get(route('search', ['q' => 'absolutnieniematakiejfrazyxyz']));

        $odpowiedz->assertSee('absolutnieniematakiejfrazyxyz');
    }

    public function test_przepis_zbanowanego_autora_nie_wchodzi_do_wynikow(): void
    {
        $zbanowany = $this->user('autorzbanowany');
        $aktywny = $this->user('autoraktywny');

        $this->przepis($zbanowany, 'Zupa testowa ZNACZNIK-ZBANOWANY');
        $this->przepis($aktywny, 'Zupa testowa ZNACZNIK-AKTYWNY');

        $zbanowany->ban();

        $this->assertFalse(
            (new UserPolicy)->viewProfile(null, $zbanowany->fresh()),
            'Test zakłada, że profil zbanowanego autora faktycznie daje 403.',
        );

        $odpowiedz = $this->get(route('search', ['q' => 'zupa testowa', 'sekcja' => 'przepisy']));

        $odpowiedz->assertDontSee('ZNACZNIK-ZBANOWANY');
        $odpowiedz->assertSee('ZNACZNIK-AKTYWNY');
    }

    public function test_przepis_ukryty_przez_moderacje_nie_wchodzi_do_wynikow(): void
    {
        $autor = $this->user('autormoderowany');

        $ukryty = $this->przepis($autor, 'Zupa testowa ZNACZNIK-UKRYTY-MODERACJA');
        $this->przepis($autor, 'Zupa testowa ZNACZNIK-ZOSTAJE-WIDOCZNY');

        $ukryty->update(['status' => Recipe::STATUS_HIDDEN]);

        $odpowiedz = $this->get(route('search', ['q' => 'zupa testowa', 'sekcja' => 'przepisy']));

        $odpowiedz->assertDontSee('ZNACZNIK-UKRYTY-MODERACJA');
        $odpowiedz->assertSee('ZNACZNIK-ZOSTAJE-WIDOCZNY');
    }

    public function test_przepis_usuniety_soft_delete_nie_wchodzi_do_wynikow(): void
    {
        $autor = $this->user('autorkasujacy');

        $usuniety = $this->przepis($autor, 'Zupa testowa ZNACZNIK-SKASOWANY');
        $this->przepis($autor, 'Zupa testowa ZNACZNIK-NIESKASOWANY');

        $usuniety->delete();

        $odpowiedz = $this->get(route('search', ['q' => 'zupa testowa', 'sekcja' => 'przepisy']));

        $odpowiedz->assertDontSee('ZNACZNIK-SKASOWANY');
        $odpowiedz->assertSee('ZNACZNIK-NIESKASOWANY');
    }

    public function test_przepis_osoby_zablokowanej_przez_szukajacego_nie_wchodzi_do_wynikow(): void
    {
        $szukajacy = $this->user('szukajacyblokujacy');
        $zablokowany = $this->user('autorzablokowanyprzezszukajacego');
        $obcy = $this->user('autorobcy1');

        $szukajacy->blocking()->attach($zablokowany->getKey(), ['created_at' => now()]);

        $this->przepis($zablokowany, 'Zupa testowa ZNACZNIK-ZABLOKOWANY-PRZEZE-MNIE');
        $this->przepis($obcy, 'Zupa testowa ZNACZNIK-OBCY-WIDOCZNY');

        $odpowiedz = $this->actingAs($szukajacy)
            ->get(route('search', ['q' => 'zupa testowa', 'sekcja' => 'przepisy']));

        $odpowiedz->assertDontSee('ZNACZNIK-ZABLOKOWANY-PRZEZE-MNIE');
        $odpowiedz->assertSee('ZNACZNIK-OBCY-WIDOCZNY');
    }

    /** Blokada działa w OBIE strony: autor, który zablokował SZUKAJĄCEGO, też znika. */
    public function test_przepis_autora_ktory_zablokowal_szukajacego_nie_wchodzi_do_wynikow(): void
    {
        $szukajacy = $this->user('szukajacyzablokowany');
        $blokujacy = $this->user('autorktoryblokujeszukajacego');
        $obcy = $this->user('autorobcy2');

        $blokujacy->blocking()->attach($szukajacy->getKey(), ['created_at' => now()]);

        $this->przepis($blokujacy, 'Zupa testowa ZNACZNIK-BLOKUJE-MNIE');
        $this->przepis($obcy, 'Zupa testowa ZNACZNIK-OBCY-WIDOCZNY-2');

        $odpowiedz = $this->actingAs($szukajacy)
            ->get(route('search', ['q' => 'zupa testowa', 'sekcja' => 'przepisy']));

        $odpowiedz->assertDontSee('ZNACZNIK-BLOKUJE-MNIE');
        $odpowiedz->assertSee('ZNACZNIK-OBCY-WIDOCZNY-2');
    }

    public function test_osoba_zbanowana_nie_wchodzi_do_wynikow_ludzi(): void
    {
        $this->user(null, ['display_name' => 'Ktoś inny']); // szum, żeby nie szukać w pustej tabeli

        $zbanowana = $this->user('osobaznaczniikzbanowana', ['display_name' => 'ZNACZNIK Zbanowana Osoba']);
        $aktywna = $this->user('osobaznaczniikaktywna', ['display_name' => 'ZNACZNIK Aktywna Osoba']);

        $zbanowana->ban();

        $odpowiedz = $this->get(route('search', ['q' => 'znacznik', 'sekcja' => 'ludzie']));

        $odpowiedz->assertDontSee('Zbanowana Osoba');
        $odpowiedz->assertSee('Aktywna Osoba');
    }

    public function test_osoba_oznaczona_do_usuniecia_nie_wchodzi_do_wynikow_ludzi(): void
    {
        $doUsuniecia = $this->user('osobaznaczniikdousuniecia', ['display_name' => 'ZNACZNIK Osoba Do Usuniecia']);
        $aktywna = $this->user('osobaznaczniikaktywna2', ['display_name' => 'ZNACZNIK Osoba Aktywna Druga']);

        $doUsuniecia->markForDeletion();

        $odpowiedz = $this->get(route('search', ['q' => 'znacznik', 'sekcja' => 'ludzie']));

        $odpowiedz->assertDontSee('Osoba Do Usuniecia');
        $odpowiedz->assertSee('Osoba Aktywna Druga');
    }

    public function test_osoba_w_relacji_blokady_z_szukajacym_nie_wchodzi_do_wynikow_ludzi(): void
    {
        $szukajacy = $this->user('szukajacyludzieblokada');
        $zablokowana = $this->user('osobazablokowanaprzezszukajacego', ['display_name' => 'ZNACZNIK Zablokowana Przeze Mnie']);
        $blokujaca = $this->user('osobablokujacaszukajacego', ['display_name' => 'ZNACZNIK Blokuje Mnie Osoba']);
        $obca = $this->user('osobaobcaludzie', ['display_name' => 'ZNACZNIK Obca Widoczna Osoba']);

        $szukajacy->blocking()->attach($zablokowana->getKey(), ['created_at' => now()]);
        $blokujaca->blocking()->attach($szukajacy->getKey(), ['created_at' => now()]);

        $odpowiedz = $this->actingAs($szukajacy)
            ->get(route('search', ['q' => 'znacznik', 'sekcja' => 'ludzie']));

        $odpowiedz->assertDontSee('Zablokowana Przeze Mnie');
        $odpowiedz->assertDontSee('Blokuje Mnie Osoba');
        $odpowiedz->assertSee('Obca Widoczna Osoba');
    }

    /**
     * Scenariusz 8: „jest ich więcej"/licznik nie mogą zdradzać istnienia
     * dopasowań, których lista nie pokazuje. Filtr widoczności musi siedzieć
     * W ZAPYTANIU SQL, przed `LIMIT` — inaczej dwudziesty pierwszy,
     * niewidoczny wiersz i tak zajmowałby miejsce w paczce `ile + 1`
     * i podnosiłby licznik/flagę „pokaż więcej", mimo że sam nigdy
     * nie trafia na listę.
     *
     * Sekcja domyślna pokazuje minimum 20 wyników na stronę
     * (`SearchController::NA_STRONIE`) — stąd dokładnie 20 widocznych
     * dopasowań plus jedno, niewidoczne (zbanowany autor), które w sumie
     * dają 21 TRAFIEŃ, ale tylko 20 z nich wolno pokazać.
     */
    public function test_licznik_i_pokaz_wiecej_nie_zdradzaja_dopasowan_ktorych_nie_widac(): void
    {
        $aktywny = $this->user('autor21widocznych');
        for ($i = 1; $i <= 20; $i++) {
            $this->przepis($aktywny, "Marchewkowa zupa numer {$i}");
        }

        $zbanowany = $this->user('autorniewidocznegoprzepisu');
        $this->przepis($zbanowany, 'Marchewkowa zupa niewidoczna ZNACZNIK-NIEWIDOCZNY');
        $zbanowany->ban();

        $odpowiedz = $this->get(route('search', ['q' => 'marchewkowa zupa', 'sekcja' => 'przepisy']));

        $odpowiedz->assertDontSee('ZNACZNIK-NIEWIDOCZNY');
        $odpowiedz->assertSee('Znaleziono 20 przepisów.');
        $odpowiedz->assertDontSee('Pokaż więcej przepisów');

        // KONTROLA: te same 20 + jeden dwudziesty pierwszy, tym razem
        // WIDOCZNY — flaga „jest ich więcej" musi się wtedy zapalić. Bez
        // tej kontroli test wyżej przeszedłby też wtedy, gdyby zapytanie
        // w ogóle nie umiało pokazać więcej niż 20 wyników.
        $this->przepis($aktywny, 'Marchewkowa zupa dwudziesta pierwsza ZNACZNIK-WIDOCZNY-21');

        $odpowiedzKontrolna = $this->get(route('search', ['q' => 'marchewkowa zupa', 'sekcja' => 'przepisy']));

        $odpowiedzKontrolna->assertSee('Pokaż więcej przepisów');
        $odpowiedzKontrolna->assertSee('Jest ich więcej');
    }
}
