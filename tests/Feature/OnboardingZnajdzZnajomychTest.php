<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Znasz już kogoś tutaj?" — krok onboardingu `/witaj/ludzie`
 * (`docs/research/MIGRACJA_Z_GARNKA.md` §3.1, issue z 10 września 2026).
 *
 * Wyszukiwanie na tym ekranie idzie DOKŁADNIE przez `SearchQuery::people()` —
 * tę samą klasę, której używa `SearchController` na `/szukaj`
 * (patrz `tests/Feature/SearchTest.php`). Te testy dlatego NIE powtarzają
 * pokrycia samej klasy `SearchQuery` (literówki, próg podobieństwa,
 * wydajność zapytania) — sprawdzają wyłącznie to, co jest NOWE na tym
 * ekranie: pole wyszukiwania, checkbox obserwowania z wyniku, ograniczenie
 * liczby wyników i brak nowego zapisu do bazy.
 */
class OnboardingZnajdzZnajomychTest extends TestCase
{
    use RefreshDatabase;

    /** Wycinek HTML-a odpowiadający jednej sekcji ekranu — patrz uwaga w PR-ze
     *  o pułapce „assertSee na całym HTML-u łapie to samo słowo gdzie indziej". */
    private function wycinek(string $html, string $od, string $do): string
    {
        $start = mb_strpos($html, $od);
        $this->assertNotFalse($start, "Nie znalazłem znacznika początku „{$od}” w HTML-u.");

        $koniec = mb_strpos($html, $do, $start);
        $koniec = $koniec === false ? mb_strlen($html) : $koniec;

        return mb_substr($html, $start, $koniec - $start);
    }

    public function test_ekran_ma_pole_do_szukania_znajomej_osoby(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('onboarding.people'))
            ->assertOk()
            ->getContent();

        // Nazwa serwisu w tym nagłówku jest zapisana dwukolorowo
        // (`docs/brand/GLOS_MARKI.md` §2), więc w HTML-u nie ma napisu
        // „Kuking" — jest komponent. Sprawdzamy część stałą nagłówka
        // i to, że nazwa w nim jest komponentem, a nie zwykłym tekstem.
        $this->assertStringContainsString('<h2>Znasz już kogoś w <span class="kuking-word">', $html);
        $this->assertStringContainsString('name="q"', $html);
        // Formularz szukania jest GET, nie POST — działa jako zwykły link
        // bez JavaScriptu (AGENTS.md §5).
        $this->assertMatchesRegularExpression(
            '~<form method="GET" action="[^"]*'.preg_quote(route('onboarding.people'), '~').'"~',
            $html,
        );
    }

    public function test_szukanie_po_nazwie_uzytkownika_znajduje_osobe_i_pozwala_ja_zaobserwowac(): void
    {
        $this->user('halina_z_lodzi', ['display_name' => 'Halina']);
        $basia = $this->user('basia');

        $html = $this->actingAs($basia)
            ->get(route('onboarding.people', ['q' => 'halina_z_lodzi']))
            ->assertOk()
            ->getContent();

        $wyniki = $this->wycinek($html, 'Wyniki wyszukiwania', 'Osoby, które polecamy');
        $this->assertStringContainsString('Halina', $wyniki);
        $this->assertStringContainsString('value="halina_z_lodzi"', $wyniki);

        $this->actingAs($basia)
            ->post(route('onboarding.people'), ['follow' => ['halina_z_lodzi']])
            ->assertRedirect(route('onboarding.done'));

        $halina = Profile::poNazwie('halina_z_lodzi')->user;
        $this->assertTrue($basia->fresh()->isFollowing($halina));
    }

    public function test_szukanie_po_imieniu_dziala_tym_samym_mechanizmem_co_wyszukiwarka_ludzi(): void
    {
        $this->user('basia_z_podkarpacia', ['display_name' => 'Basia']);
        $this->user('basia')->profile->update(['speciality' => 'zupy']);

        $html = $this->actingAs($this->user('marek'))
            ->get(route('onboarding.people', ['q' => 'Basia']))
            ->assertOk()
            ->getContent();

        $wyniki = $this->wycinek($html, 'Wyniki wyszukiwania', 'Osoby, które polecamy');
        $this->assertStringContainsString('Basia', $wyniki);
    }

    public function test_brak_wynikow_mowi_co_zrobic_a_nie_tylko_ze_nic_nie_ma(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('onboarding.people', ['q' => 'nikttakiegoniema']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Nic nie znaleźliśmy', $html);
        $this->assertStringContainsString('Sprawdź pisownię', $html);
    }

    public function test_zbyt_krotka_fraza_pokazuje_uczciwy_komunikat_a_nie_brak_wynikow(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('onboarding.people', ['q' => 'a']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('za krótka, żeby zacząć szukać', $html);
        $this->assertStringNotContainsString('Nic nie znaleźliśmy', $html);
    }

    // -----------------------------------------------------------------
    // Widoczność — te same zakresy co wyszukiwarka ludzi, nic nowego.
    //
    // KAŻDY Z TYCH TESTÓW MA KONTROLĘ DODATNIĄ, I TO NIE JEST OZDOBA.
    // Sam `assertStringNotContainsString` jest słabą assercją: przechodzi
    // także wtedy, gdy wyszukiwarka NIE DZIAŁA WCALE — bo wtedy w HTML-u
    // nie ma nikogo, więc nie ma też ukrytej osoby. Przechodzi również po
    // literówce we frazie albo w samym szukanym łańcuchu. Test, który
    // przechodzi po zepsuciu tego, czego pilnuje, nie jest testem.
    //
    // Dlatego przy każdej ukrytej osobie stoi druga, AKTYWNA, pasująca do
    // tej samej frazy. Dopiero para „widoczna wyszła, ukryta nie wyszła"
    // dowodzi, że wyszukiwarka pracowała i że to zakres widoczności ją
    // odsiał, a nie przypadek.
    // -----------------------------------------------------------------

    public function test_zbanowana_osoba_nie_wychodzi_w_wynikach(): void
    {
        $zbanowany = $this->user('kucharz_zbanowany', ['display_name' => 'Zbanowany']);
        $zbanowany->forceFill(['status' => User::STATUS_BANNED])->save();

        // Kontrola dodatnia: ta sama fraza `kucharz_` łapie też kogoś
        // aktywnego. Bez niej test przeszedłby przy wyszukiwarce, która
        // nie zwraca nikogo.
        $this->user('kucharz_aktywny', ['display_name' => 'Aktywny']);

        $wyniki = $this->wycinek(
            $this->actingAs($this->user('basia'))
                ->get(route('onboarding.people', ['q' => 'kucharz_']))
                ->assertOk()
                ->getContent(),
            'Wyniki wyszukiwania',
            'Osoby, które polecamy',
        );

        $this->assertStringContainsString('name="follow[]" value="kucharz_aktywny"', $wyniki);
        $this->assertStringNotContainsString('name="follow[]" value="kucharz_zbanowany"', $wyniki);
    }

    public function test_zawieszona_osoba_nie_wychodzi_w_wynikach(): void
    {
        $zawieszony = $this->user('piekarz_zawieszony', ['display_name' => 'Zawieszony']);
        $zawieszony->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->user('piekarz_aktywny', ['display_name' => 'Aktywny']);

        $wyniki = $this->wycinek(
            $this->actingAs($this->user('basia'))
                ->get(route('onboarding.people', ['q' => 'piekarz_']))
                ->assertOk()
                ->getContent(),
            'Wyniki wyszukiwania',
            'Osoby, które polecamy',
        );

        $this->assertStringContainsString('name="follow[]" value="piekarz_aktywny"', $wyniki);
        $this->assertStringNotContainsString('name="follow[]" value="piekarz_zawieszony"', $wyniki);
    }

    public function test_konto_w_trakcie_usuwania_nie_wychodzi_w_wynikach(): void
    {
        $usuwany = $this->user('cukiernik_usuwany', ['display_name' => 'Usuwany']);
        $usuwany->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();

        $this->user('cukiernik_aktywny', ['display_name' => 'Aktywny']);

        $wyniki = $this->wycinek(
            $this->actingAs($this->user('basia'))
                ->get(route('onboarding.people', ['q' => 'cukiernik_']))
                ->assertOk()
                ->getContent(),
            'Wyniki wyszukiwania',
            'Osoby, które polecamy',
        );

        $this->assertStringContainsString('name="follow[]" value="cukiernik_aktywny"', $wyniki);
        $this->assertStringNotContainsString('name="follow[]" value="cukiernik_usuwany"', $wyniki);
    }

    public function test_blokada_dziala_w_obie_strony(): void
    {
        $basia = $this->user('basia');
        $nieprzyjemny = $this->user('nieprzyjemny_typ', ['display_name' => 'Nieprzyjemny']);

        DB::table('blocks')->insert([
            'blocker_id' => $basia->getKey(),
            'blocked_id' => $nieprzyjemny->getKey(),
            'created_at' => now(),
        ]);

        // Basia zablokowała — nie widzi go w wynikach.
        $this->actingAs($basia)
            ->get(route('onboarding.people', ['q' => 'nieprzyjemny_typ']))
            ->assertOk()
            ->assertDontSee('name="follow[]" value="nieprzyjemny_typ"', false);

        // I odwrotnie — zablokowany nie widzi Basi.
        $this->actingAs($nieprzyjemny)
            ->get(route('onboarding.people', ['q' => 'basia']))
            ->assertOk()
            ->assertDontSee('name="follow[]" value="basia"', false);
    }

    public function test_wlasne_konto_nie_pojawia_sie_we_wlasnych_wynikach(): void
    {
        $basia = $this->user('basia_szuka', ['display_name' => 'Basia']);

        $html = $this->actingAs($basia)
            ->get(route('onboarding.people', ['q' => 'basia_szuka']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="follow[]" value="basia_szuka"', $html);
        $this->assertStringContainsString('Nic nie znaleźliśmy', $html);
    }

    public function test_wynikow_jest_najwyzej_piec_a_nie_pelna_lista(): void
    {
        foreach (range(1, 7) as $i) {
            $this->user("basia{$i}", ['display_name' => "Basia {$i}"]);
        }

        $html = $this->actingAs($this->user('szukajaca'))
            ->get(route('onboarding.people', ['q' => 'basia']))
            ->assertOk()
            ->getContent();

        $wyniki = $this->wycinek($html, 'Wyniki wyszukiwania', 'Osoby, które polecamy');

        $this->assertSame(5, substr_count($wyniki, 'name="follow[]"'));
        $this->assertStringContainsString('Wpisz dokładniejsze imię', $wyniki);
    }

    /**
     * Issue #945 — własny profil wykluczany PRZED `LIMIT`, nie po nim.
     * Przed poprawką pasujący własny profil zajmował jedno z sześciu miejsc,
     * `reject()` w PHP je zwalniało i ekran pokazywał pięć osób BEZ
     * wskazówki „Wpisz dokładniejsze imię", choć szósta osoba istniała.
     */
    public function test_pasujacy_wlasny_profil_nie_zabiera_miejsca_ani_komunikatu_o_dalszych_wynikach(): void
    {
        // Dokładnie „Basia" — najwyższe `similarity`, więc na pewno
        // w pierwszej szóstce zwróconej przez bazę.
        $ja = $this->user('basia', ['display_name' => 'Basia']);

        foreach (range(1, 6) as $i) {
            $this->user("basia{$i}", ['display_name' => "Basia {$i}"]);
        }

        $html = $this->actingAs($ja)
            ->get(route('onboarding.people', ['q' => 'basia']))
            ->assertOk()
            ->getContent();

        $wyniki = $this->wycinek($html, 'Wyniki wyszukiwania', 'Osoby, które polecamy');

        $this->assertStringNotContainsString('name="follow[]" value="basia"', $wyniki);
        $this->assertSame(5, substr_count($wyniki, 'name="follow[]"'));
        $this->assertStringContainsString('Wpisz dokładniejsze imię', $wyniki);
    }

    public function test_pieciu_cudzych_i_wlasny_profil_to_piec_wynikow_bez_komunikatu_o_dalszych(): void
    {
        // Kontrola dodatnia: gdy cudzych trafień jest DOKŁADNIE pięć,
        // wykluczenie siebie nie może udawać, że jest ich więcej.
        $ja = $this->user('basia', ['display_name' => 'Basia']);

        foreach (range(1, 5) as $i) {
            $this->user("basia{$i}", ['display_name' => "Basia {$i}"]);
        }

        $html = $this->actingAs($ja)
            ->get(route('onboarding.people', ['q' => 'basia']))
            ->assertOk()
            ->getContent();

        $wyniki = $this->wycinek($html, 'Wyniki wyszukiwania', 'Osoby, które polecamy');

        $this->assertStringNotContainsString('name="follow[]" value="basia"', $wyniki);
        $this->assertSame(5, substr_count($wyniki, 'name="follow[]"'));
        $this->assertStringNotContainsString('Wpisz dokładniejsze imię', $wyniki);
    }

    // -----------------------------------------------------------------
    // RODO — nic nowego nie zapisujemy.
    // -----------------------------------------------------------------

    public function test_szukanie_na_tym_kroku_nie_zapisuje_zadnego_sygnalu(): void
    {
        $this->user('halina', ['display_name' => 'Halina']);

        $this->actingAs($this->user('basia'))
            ->get(route('onboarding.people', ['q' => 'halina']))
            ->assertOk();

        $this->assertSame(
            0,
            DB::table('product_signals')->count(),
            'Onboarding zapisał sygnał — research i PR zakładają rozwiązanie, które nic nie zapisuje.',
        );
    }

    /**
     * Issue #738 — tablica w `q` jest niepoprawnym kształtem parametru GET,
     * ale nie może wywrócić ekranu ani uruchomić wyszukiwarki fikcyjną frazą
     * „Array”. Każdy wariant jest osobnym żądaniem rzeczywistej trasy.
     */
    public function test_tablicowe_q_daje_pusty_ekran_bez_wyszukiwania_i_sygnalu(): void
    {
        $basia = $this->user('basiatablica');
        $zapytania = [];

        DB::listen(function ($query) use (&$zapytania): void {
            $zapytania[] = $query->sql;
        });

        foreach ([
            ['q' => ['halina']],
            ['q' => ['nazwa' => 'halina']],
            ['q' => [['halina']]],
        ] as $parametry) {
            $response = $this->actingAs($basia)
                ->get(route('onboarding.people').'?'.http_build_query($parametry))
                ->assertOk();

            $this->assertSame('', $response->viewData('phrase'));
            $this->assertFalse($response->viewData('zaKrotka'));
            $this->assertNull($response->viewData('wynikiWyszukiwania'));
        }

        $this->assertSame(0, DB::table('product_signals')->count());
        $this->assertFalse(
            collect($zapytania)->contains(fn (string $sql): bool => str_contains($sql, 'display_name_search')),
            'Tablicowe q uruchomiło zapytanie wyszukiwarki osób.',
        );
    }

    public function test_tekstowe_q_z_polskim_znakiem_nadal_wyszukuje_osobe(): void
    {
        $szukana = $this->user('zaneta', ['display_name' => 'Żaneta']);

        $response = $this->actingAs($this->user('szukajacazanety'))
            ->get(route('onboarding.people', ['q' => 'Żaneta']))
            ->assertOk();

        $this->assertSame('Żaneta', $response->viewData('phrase'));
        $this->assertSame(
            [$szukana->getKey()],
            $response->viewData('wynikiWyszukiwania')->pluck('user_id')->all(),
        );
    }

    // -----------------------------------------------------------------
    // Regresja: krok da się nadal pominąć, sugerowana ósemka nadal działa.
    // -----------------------------------------------------------------

    public function test_krok_da_sie_pominac_mimo_dodanego_szukania(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('onboarding.people'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~<button class="btn btn-quiet" type="submit" formmethod="POST" formaction="'.preg_quote(route('onboarding.skip'), '~').'" formnovalidate name="_token" value="[^"]+">Pomiń ten krok</button>~',
            $html,
        );

        $this->actingAs($this->user('marek'))
            ->get(route('onboarding.done'))
            ->assertOk();
    }

    public function test_bez_szukania_ekran_wyglada_jak_dawniej_bez_sekcji_wynikow(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('onboarding.people'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Wyniki wyszukiwania', $html);
        $this->assertStringNotContainsString('Nic nie znaleźliśmy', $html);
    }
}
