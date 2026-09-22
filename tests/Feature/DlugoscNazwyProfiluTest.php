<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Profile;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Limit długości nazwy pokazywanej jest JEDNĄ liczbą i wszystkie drzwi ją znają.
 *
 * DLACZEGO TO JEST LICZBA O UKŁADZIE, A NIE O UPRZEJMOŚCI (issue #467)
 * Nazwa autora w karcie wpisu jest ODNOŚNIKIEM, czyli kontrolką, którą
 * przeglądarka po Tab przewija w widok. Kontrolki wyższej niż okno nie da się
 * pokazać w całości żadnym przewijaniem — ani rezerwą nad paskiem (D-184),
 * ani niczym innym. Przy dawnym limicie 100 znaków sama nazwa brała 825,16 px
 * przy oknie 320 px i czcionce przeglądarki 200%, wobec 740 px okna, w którym
 * mierzy `scripts/dostepnosc.mjs`.
 *
 * CZEGO TEN TEST PILNUJE, A CZEGO NIE
 * Nie mierzy pikseli — od tego jest `scripts/glowka-karty-wpisu.mjs`. Pilnuje
 * rzeczy, której tamten pomiar pilnować nie może: że limit jest jeden, że
 * wchodzi przez wszystkie czworo drzwi tak samo i że baza go pomieści.
 */
class DlugoscNazwyProfiluTest extends TestCase
{
    use RefreshDatabase;

    private function limit(): int
    {
        return (int) config('kuking.profil.dlugosc_nazwy');
    }

    #[Test]
    public function test_limit_jest_ustawiony_i_ma_sens(): void
    {
        $limit = $this->limit();

        // Dolna granica nie jest z palca: nazwa musi pomieścić polskie imię
        // i nazwisko. „Małgorzata Brzęczyszczykiewiczowa" ma 33 znaki.
        $this->assertGreaterThanOrEqual(
            33,
            $limit,
            'Limit nazwy nie mieści polskiego imienia i nazwiska. Nazwa, której '.
            'człowiek nie może wpisać w całości, jest gorsza niż nazwa za długa.',
        );

        // Górna granica jest zmierzona: przy 320 px i czcionce przeglądarki
        // 200% wiersz nazwy ma 55,8 px, a odnośnik rośnie mniej więcej
        // liniowo z długością. Przy 60 znakach przekracza 490 px, czyli
        // dwie trzecie okna, w którym mierzy automat.
        $this->assertLessThanOrEqual(
            50,
            $limit,
            'Limit nazwy przekracza 50 znaków. Zmierzone: przy 320 px i czcionce '.
            'przeglądarki 200% odnośnik nazwy rośnie o 55,8 px na wiersz i przy '.
            '57 znakach ma już 490,38 px. Jeśli ta liczba ma urosnąć, najpierw '.
            'zmierz ponownie `scripts/glowka-karty-wpisu.mjs`.',
        );
    }

    #[Test]
    public function test_kolumna_w_bazie_pomiesci_pelny_limit(): void
    {
        /*
         * Kolumna jest ŚWIADOMIE szersza niż walidacja: baza ma pomieścić to,
         * co już w niej leży, a bramką jest walidacja. Ten test pilnuje
         * jedynego kierunku, w którym rozjazd boli — kolumna WĘŻSZA niż limit
         * znaczy, że poprawna nazwa nie da się zapisać i człowiek zobaczy
         * błąd bazy zamiast komunikatu.
         */
        $dlugosc = DB::selectOne(
            'select character_maximum_length as dlugosc
               from information_schema.columns
              where table_name = ? and column_name = ?',
            ['profiles', 'display_name'],
        )?->dlugosc;

        $this->assertNotNull($dlugosc, 'Nie ma kolumny `profiles.display_name`.');

        $this->assertGreaterThanOrEqual(
            $this->limit(),
            (int) $dlugosc,
            'Kolumna `profiles.display_name` mieści '.$dlugosc.' znaków, a walidacja '.
            'dopuszcza '.$this->limit().'. Poprawna nazwa nie zapisze się i człowiek '.
            'zobaczy błąd bazy zamiast komunikatu.',
        );
    }

    #[Test]
    public function test_rejestracja_odrzuca_nazwe_dluzsza_niz_limit(): void
    {
        $limit = $this->limit();

        /*
         * KOLEJNOŚĆ MA ZNACZENIE I NIE JEST KOSMETYCZNA. Udana rejestracja
         * loguje, a `/register` dla zalogowanego odsyła na `/home` BEZ
         * walidacji — czyli po odwrotnej kolejności przypadek „za długa"
         * kończyłby się przekierowaniem 302 bez ani jednego błędu i test
         * przechodziłby, nie sprawdziwszy niczego.
         */
        $zaDluga = $this->post('/register', $this->dane(str_repeat('a', $limit + 1), 'zadluga'));
        $zaDluga->assertSessionHasErrors('display_name');

        $this->assertDatabaseMissing('profiles', ['username' => 'zadluga']);

        // KONTROLA DODATNIA (pułapka 4): nazwa DOKŁADNIE na limit ma przejść.
        // Bez niej asercja wyżej przechodziłaby także wtedy, gdyby formularz
        // odrzucał wszystko.
        $this->post('/register', $this->dane(str_repeat('a', $limit), 'akurat'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('profiles', ['username' => 'akurat']);
    }

    #[Test]
    public function test_ustawienia_profilu_odrzucaja_nazwe_dluzsza_niz_limit(): void
    {
        $limit = $this->limit();
        $osoba = $this->user('basia');

        // KONTROLA DODATNIA: nazwa dokładnie na limit przechodzi tymi samymi drzwiami.
        $this->actingAs($osoba)
            ->put('/ustawienia/profil', $this->daneProfilu(str_repeat('b', $limit)))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            str_repeat('b', $limit),
            Profile::query()->where('user_id', $osoba->getKey())->value('display_name'),
        );

        $this->actingAs($osoba)
            ->put('/ustawienia/profil', $this->daneProfilu(str_repeat('b', $limit + 1)))
            ->assertSessionHasErrors('display_name');

        // POPRAWNE DANE NIGDY NIE ZNIKAJĄ: odrzucenie nie może zetrzeć
        // nazwy, którą człowiek miał wcześniej.
        $this->assertSame(
            str_repeat('b', $limit),
            Profile::query()->where('user_id', $osoba->getKey())->value('display_name'),
        );
    }

    #[Test]
    public function test_dane_demo_nie_maja_nazwy_ponad_limit(): void
    {
        $this->seed(DemoSeeder::class);

        $ponadLimit = Profile::query()
            ->get()
            ->filter(fn (Profile $p): bool => mb_strlen((string) $p->display_name) > $this->limit())
            ->map(fn (Profile $p): string => $p->username.' ('.mb_strlen((string) $p->display_name).' zn.)')
            ->values()
            ->all();

        $this->assertSame(
            [],
            $ponadLimit,
            'Dane demo mają nazwę dłuższą niż limit, czyli stan, którego przez '.
            'formularz nie da się dziś utworzyć. Automat mierzyłby wtedy wariant '.
            'trudniejszy niż ten, który produkt dopuszcza — a to jest tak samo '.
            'mylące jak wariant łatwiejszy.',
        );
    }

    /** @return array<string, array{int}> */
    public static function limityKomunikatu(): array
    {
        return ['obecny limit' => [40], 'zmieniony limit' => [46]];
    }

    #[DataProvider('limityKomunikatu')]
    public function test_komunikat_rejestracji_podaje_faktyczny_limit(int $limit): void
    {
        config(['kuking.profil.dlugosc_nazwy' => $limit]);
        $nazwa = str_repeat('ą', $limit + 1);
        $komunikat = "To imię jest za długie. Zmieść się w {$limit} znakach.";

        $ekran = $this->followingRedirects()->from('/register')
            ->post('/register', $this->dane($nazwa, 'zadluga'))->assertOk();
        $this->sprawdzBladNazwy($ekran->getContent(), $komunikat, $nazwa);

        $this->assertDatabaseMissing('profiles', ['username' => 'zadluga']);
    }

    #[DataProvider('limityKomunikatu')]
    public function test_komunikat_ustawien_podaje_faktyczny_limit(int $limit): void
    {
        config(['kuking.profil.dlugosc_nazwy' => $limit]);
        $osoba = $this->user('basia');
        $nazwaPrzed = $osoba->profile->display_name;
        $nazwa = str_repeat('ą', $limit + 1);
        $komunikat = "To imię jest za długie. Zmieść się w {$limit} znakach.";

        $ekran = $this->actingAs($osoba)->followingRedirects()->from('/ustawienia/profil')
            ->put('/ustawienia/profil', $this->daneProfilu($nazwa))
            ->assertOk();
        $this->sprawdzBladNazwy($ekran->getContent(), $komunikat, $nazwa);

        $this->assertSame($nazwaPrzed, $osoba->profile->fresh()->display_name);
    }

    private function sprawdzBladNazwy(string $html, string $komunikat, string $nazwa): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $bledy = $xpath->query('//*[@id="f-display_name-error"]');
        $this->assertCount(1, $bledy);
        $this->assertSame($komunikat, trim($bledy->item(0)->textContent));

        $pole = $xpath->query('//input[@id="f-display_name"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $pole);
        $this->assertSame($nazwa, $pole->getAttribute('value'));
    }

    /** @return array<string, string> */
    private function dane(string $nazwa, string $login): array
    {
        return [
            'display_name' => $nazwa,
            'username' => $login,
            'email' => $login.'@example.test',
            'password' => 'kuking-haslo-do-testu-2026',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ];
    }

    /** @return array<string, string> */
    private function daneProfilu(string $nazwa): array
    {
        // `username` jest w tym formularzu wymagane i musi zostać bez zmian —
        // inaczej mierzylibyśmy przy okazji walidację nazwy w adresie.
        return ['display_name' => $nazwa, 'username' => 'basia'];
    }
}
