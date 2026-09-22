<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Zgłoszenie #858 — filtr tekstowy i „Załaduj więcej” na ekranie „Twoje tagi”.
 *
 * DLACZEGO TEN TEST WYGLĄDA JAK PRZEGLĄDARKA, A NIE JAK WYWOŁANIE KONTROLERA
 * Usterka, przed którą ten plik stoi, nie mieszka w kontrolerze — mieszka
 * w RÓŻNICY między tym, co widok narysował, a tym, co przeglądarka odeśle.
 * Formularz z zaznaczeniami odsyła WYŁĄCZNIE te pola, które w nim stoją:
 * niezaznaczony checkbox nie istnieje w żądaniu, a checkbox, którego widok
 * w ogóle nie narysował (bo tag jest za oknem doładowania albo poza filtrem),
 * nie istnieje tym bardziej. Test, który sam układa tablicę `tags`, sprawdza
 * własne wyobrażenie o formularzu, a nie formularz.
 *
 * Dlatego `wyslij()` czyta z HTML-u wszystkie pola `tags[]` — zaznaczone
 * checkboxy ORAZ pola ukryte — i odsyła dokładnie to, co odesłałaby
 * przeglądarka. Gdyby widok przestał nieść wybór ukryty przez filtr, ten
 * test zobaczy to jako utratę obserwowania, bo tak to zobaczy człowiek.
 */
class TagiFiltrIDoladowanieTest extends TestCase
{
    use RefreshDatabase;

    private const PORCJA = 20;

    private int $pozycja = 0;

    private function promowany(string $nazwa): Tag
    {
        $tag = Tag::factory()->create([
            'name' => $nazwa,
            'normalized_name' => Tag::znormalizujNazwe($nazwa),
        ]);
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => $this->pozycja++]);

        return $tag;
    }

    /** Pola `tags[]`, które przeglądarka naprawdę odeśle z tej strony. */
    private function odsylaneTagi(string $html): array
    {
        preg_match_all('/<input\b[^>]*name="tags\[\]"[^>]*>/', $html, $pola);
        $wysylane = [];
        foreach ($pola[0] as $pole) {
            preg_match('/value="([^"]*)"/', $pole, $wartosc);
            $ukryte = str_contains($pole, 'type="hidden"');
            $zaznaczone = (bool) preg_match('/\bchecked\b/', $pole);
            if ($ukryte || $zaznaczone) {
                $wysylane[] = $wartosc[1];
            }
        }

        return $wysylane;
    }

    /** Widoczne pola wyboru — to, co człowiek ma przed oczami. */
    private function widoczneTagi(string $html): array
    {
        preg_match_all('/<input\b(?![^>]*type="hidden")[^>]*name="tags\[\]"[^>]*value="([^"]*)"/', $html, $pola);

        return $pola[1];
    }

    private function polaUkryte(string $html, string $nazwa): ?string
    {
        preg_match('/<input type="hidden" name="'.preg_quote($nazwa, '/').'" value="([^"]*)"/', $html, $m);

        return $m[1] ?? null;
    }

    private function wartoscPolaSzukaj(string $html): string
    {
        preg_match('/<input\b[^>]*id="f-szukaj"[^>]*value="([^"]*)"/', $html, $m);

        return $m[1] ?? '';
    }

    private function otworz(User $user): string
    {
        return $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();
    }

    /**
     * Wysyła formularz dokładnie tak, jak zrobiłaby to przeglądarka:
     * wszystkie pola `tags[]` ze strony plus to, co człowiek zmienił.
     *
     * @param  array<int,string>  $zaznacz  dodatkowo zaznaczone przez człowieka
     * @param  array<int,string>  $odznacz  odznaczone przez człowieka
     */
    private function wyslij(string $html, array $nadpisz = [], array $zaznacz = [], array $odznacz = []): TestResponse
    {
        $tagi = array_values(array_diff(array_unique([...$this->odsylaneTagi($html), ...$zaznacz]), $odznacz));

        return $this->put(route('settings.tags.update'), array_merge([
            'form_scope' => $this->polaUkryte($html, 'form_scope'),
            'ile' => $this->polaUkryte($html, 'ile'),
            'szukaj' => $this->wartoscPolaSzukaj($html),
            'tags' => $tagi,
        ], $nadpisz));
    }

    /** Przechodzi przez przekierowanie z `withInput()` i zwraca nowy HTML. */
    private function poPrzeladowaniu(TestResponse $odpowiedz, User $user): string
    {
        $odpowiedz->assertRedirect(route('settings.tags'));

        return $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();
    }

    /**
     * WARUNEK Z DECYZJI WŁAŚCICIELA: doładowanie ROZSZERZA listę w tokenie,
     * nie zastępuje jej. Zapis po doładowaniu obejmuje pozycje z pierwszej
     * porcji i z kolejnych, a nic poza tym się nie rusza.
     */
    public function test_858_doladowanie_rozszerza_zakres_a_zapis_obejmuje_obie_porcje(): void
    {
        $user = $this->user();
        $tagi = collect(range(1, 30))->map(fn (int $i): Tag => $this->promowany(sprintf('Temat %03d', $i)));
        $wPierwszej = $tagi[0];   // Temat 001 — obserwowany od początku
        $wDrugiej = $tagi[25];    // Temat 026 — obserwowany, widoczny dopiero po doładowaniu
        $user->followedTags()->attach([$wPierwszej->getKey(), $wDrugiej->getKey()], ['created_at' => now()->subDay()]);
        $daty = fn (): array => DB::table('tag_follows')->where('user_id', $user->getKey())
            ->pluck('created_at', 'tag_id')->all();
        $przed = $daty();

        $strona = $this->otworz($user);
        $this->assertCount(self::PORCJA, $this->widoczneTagi($strona),
            'Pierwsze otwarcie pokazuje jedną porcję, nie całą listę.');
        $this->assertNotContains($wDrugiej->getKey(), $this->widoczneTagi($strona));

        $strona = $this->poPrzeladowaniu($this->wyslij($strona, ['akcja' => 'wiecej']), $user);
        $widoczne = $this->widoczneTagi($strona);
        $this->assertCount(30, $widoczne, 'Doładowanie ma dołożyć porcję, a nie ją podmienić.');
        $this->assertContains($wPierwszej->getKey(), $widoczne, 'Pierwsza porcja zniknęła po doładowaniu.');
        $this->assertContains($wDrugiej->getKey(), $widoczne);
        $this->assertContains($wDrugiej->getKey(), $this->odsylaneTagi($strona),
            'Tag obserwowany, pokazany dopiero w drugiej porcji, wrócił jako niezaznaczony.');

        // Człowiek zaznacza po jednym z każdej porcji i zapisuje.
        $nowyZPierwszej = $tagi[4];
        $nowyZDrugiej = $tagi[29];
        $this->wyslij($strona, [], [$nowyZPierwszej->getKey(), $nowyZDrugiej->getKey()])
            ->assertSessionHasNoErrors();

        $po = $daty();
        $this->assertEqualsCanonicalizing([
            $wPierwszej->getKey(), $wDrugiej->getKey(), $nowyZPierwszej->getKey(), $nowyZDrugiej->getKey(),
        ], array_keys($po), 'Zapis po doładowaniu nie objął obu porcji albo ruszył coś poza nimi.');
        $this->assertSame($przed[$wPierwszej->getKey()], $po[$wPierwszej->getKey()]);
        $this->assertSame($przed[$wDrugiej->getKey()], $po[$wDrugiej->getKey()]);
    }

    /**
     * Tag ukryty przez filtr NIE traci zaznaczenia — wybór idzie do serwera
     * niezależnie od tego, co filtr aktualnie pokazuje.
     */
    public function test_858_tag_ukryty_przez_filtr_nie_traci_obserwowania(): void
    {
        $user = $this->user();
        $zupa = $this->promowany('Zupa pomidorowa');
        $ciasto = $this->promowany('Ciasto drożdżowe');
        $user->followedTags()->attach($zupa->getKey(), ['created_at' => now()->subDay()]);

        $strona = $this->otworz($user);
        $strona = $this->poPrzeladowaniu($this->wyslij($strona, ['akcja' => 'filtruj', 'szukaj' => 'ciasto']), $user);

        $this->assertSame([$ciasto->getKey()], $this->widoczneTagi($strona), 'Filtr nie zawęził listy.');
        $this->assertContains($zupa->getKey(), $this->odsylaneTagi($strona),
            'Tag schowany przez filtr przestał być wysyłany — zapis by go odobserwował.');

        $this->wyslij($strona, [], [$ciasto->getKey()])->assertSessionHasNoErrors();

        $this->assertTrue($user->isFollowingTag($zupa), 'Zapis przy włączonym filtrze odobserwował ukryty tag.');
        $this->assertTrue($user->isFollowingTag($ciasto));
    }

    /** Zaznaczenie zrobione przed zmianą filtra przeżywa zmianę filtra. */
    public function test_858_zaznaczenie_przezywa_zmiane_filtra(): void
    {
        $user = $this->user();
        $zupa = $this->promowany('Zupa pomidorowa');
        $ciasto = $this->promowany('Ciasto drożdżowe');

        $strona = $this->otworz($user);
        // Zaznacza zupę i dopiero potem filtruje na „ciasto”.
        $strona = $this->poPrzeladowaniu(
            $this->wyslij($strona, ['akcja' => 'filtruj', 'szukaj' => 'ciasto'], [$zupa->getKey()]),
            $user,
        );
        $this->assertContains($zupa->getKey(), $this->odsylaneTagi($strona));

        $this->wyslij($strona, [], [$ciasto->getKey()])->assertSessionHasNoErrors();
        $this->assertTrue($user->isFollowingTag($zupa));
        $this->assertTrue($user->isFollowingTag($ciasto));
    }

    /** Odznaczenie widocznego tagu nadal działa — filtr nie betonuje wyboru. */
    public function test_858_odznaczenie_widocznego_tagu_nadal_usuwa_obserwowanie(): void
    {
        $user = $this->user();
        $zupa = $this->promowany('Zupa pomidorowa');
        $ciasto = $this->promowany('Ciasto drożdżowe');
        $user->followedTags()->attach([$zupa->getKey(), $ciasto->getKey()], ['created_at' => now()->subDay()]);

        $strona = $this->otworz($user);
        $strona = $this->poPrzeladowaniu($this->wyslij($strona, ['akcja' => 'filtruj', 'szukaj' => 'zupa']), $user);

        $this->wyslij($strona, [], [], [$zupa->getKey()])->assertSessionHasNoErrors();

        $this->assertFalse($user->isFollowingTag($zupa), 'Odznaczenie widocznego tagu nie zadziałało.');
        $this->assertTrue($user->isFollowingTag($ciasto), 'Zapis ruszył tag spoza filtra.');
    }

    /** Filtr ma widoczną etykietę, nie sam placeholder (UX_50_PLUS). */
    public function test_858_filtr_ma_widoczna_etykiete(): void
    {
        $user = $this->user();
        $this->promowany('Zupa pomidorowa');

        $strona = $this->otworz($user);

        $this->assertStringContainsString('<label for="f-szukaj">', $strona);
        $this->assertStringContainsString('Szukaj wśród tagów', $strona);
    }

    /** Zero wyników mówi, CO ZROBIĆ, a nie „brak wyników”. */
    public function test_858_zero_wynikow_mowi_co_zrobic(): void
    {
        $user = $this->user();
        $this->promowany('Zupa pomidorowa');

        $strona = $this->otworz($user);
        $strona = $this->poPrzeladowaniu($this->wyslij($strona, ['akcja' => 'filtruj', 'szukaj' => 'kalafior']), $user);

        $this->assertSame([], $this->widoczneTagi($strona));
        $this->assertStringContainsString('kalafior', $strona);
        // Ekran bez ani jednego wyniku nie może zostawić człowieka bez wyjścia.
        $this->assertStringContainsString('Pokaż wszystkie tagi', $strona);
    }

    /**
     * Wyjście z filtra jednym naciśnięciem — i bez utraty zaznaczeń.
     * Bez tego przycisku człowiek musiałby wyczyścić pole I nacisnąć
     * „Pokaż pasujące”, czyli zrobić dwie rzeczy, żeby cofnąć jedną.
     */
    public function test_858_pokaz_wszystkie_tagi_czysci_filtr_i_zostawia_wybor(): void
    {
        $user = $this->user();
        $zupa = $this->promowany('Zupa pomidorowa');
        $ciasto = $this->promowany('Ciasto drożdżowe');

        $strona = $this->otworz($user);
        $strona = $this->poPrzeladowaniu(
            $this->wyslij($strona, ['akcja' => 'filtruj', 'szukaj' => 'ciasto'], [$zupa->getKey()]),
            $user,
        );
        $strona = $this->poPrzeladowaniu($this->wyslij($strona, ['akcja' => 'wszystkie']), $user);

        $this->assertSame('', $this->wartoscPolaSzukaj($strona), 'Pole filtra nie zostało wyczyszczone.');
        $this->assertEqualsCanonicalizing([$zupa->getKey(), $ciasto->getKey()], $this->widoczneTagi($strona));
        $this->assertContains($zupa->getKey(), $this->odsylaneTagi($strona),
            'Powrót do całej listy zgubił zaznaczenie zrobione przy włączonym filtrze.');
    }

    /** Przycisk doładowania mówi, ile pozycji dojdzie, i znika na końcu listy. */
    public function test_858_przycisk_doladowania_mowi_ile_pozycji_dojdzie(): void
    {
        $user = $this->user();
        collect(range(1, 25))->each(fn (int $i) => $this->promowany(sprintf('Temat %03d', $i)));

        $strona = $this->otworz($user);
        $this->assertStringContainsString('Pokaż kolejne 5 tagów', $strona);

        $strona = $this->poPrzeladowaniu($this->wyslij($strona, ['akcja' => 'wiecej']), $user);
        $this->assertStringNotContainsString('Pokaż kolejne', $strona,
            'Na końcu listy zostaje przycisk, po którym nic nie dochodzi.');
    }

    /**
     * Odmowa walidacji nie zamyka drogi do reszty listy.
     *
     * Zakres w tokenie po odmowie celowo NIE rośnie (#854), ale przycisk
     * „Pokaż kolejne…” liczy się od pełnej listy — inaczej po jednym błędzie
     * człowiek zostawałby z dwudziestoma pozycjami i bez sposobu, żeby
     * zobaczyć resztę inaczej niż przeładowując stronę z ręki.
     */
    public function test_858_po_odmowie_walidacji_nadal_da_sie_doladowac(): void
    {
        $user = $this->user();
        collect(range(1, 30))->each(fn (int $i) => $this->promowany(sprintf('Temat %03d', $i)));

        $strona = $this->otworz($user);
        $this->wyslij($strona, ['tags' => ['to-nie-jest-uuid']])->assertSessionHasErrors('tags');
        $strona = $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();

        $this->assertCount(self::PORCJA, $this->widoczneTagi($strona),
            'Odmowa walidacji rozszerzyła zakres formularza.');
        $this->assertStringContainsString('Pokaż kolejne 10 tagów', $strona);
        $this->assertStringContainsString('Widzisz 20 z 30 tagów', $strona);

        $strona = $this->poPrzeladowaniu($this->wyslij($strona, ['akcja' => 'wiecej']), $user);
        $this->assertCount(30, $this->widoczneTagi($strona));
    }

    /** Krótka lista nie dostaje przycisku, którego nie ma po co naciskać. */
    public function test_858_krotka_lista_nie_ma_przycisku_doladowania(): void
    {
        $user = $this->user();
        collect(range(1, 5))->each(fn (int $i) => $this->promowany(sprintf('Temat %03d', $i)));

        $this->assertStringNotContainsString('Pokaż kolejne', $this->otworz($user));
    }

    /** Filtr nie zależy od polskich znaków — tak samo jak wyszukiwarka. */
    public function test_858_filtr_ignoruje_polskie_znaki(): void
    {
        $user = $this->user();
        $tag = $this->promowany('Żurek śląski');
        $this->promowany('Zupa pomidorowa');

        $strona = $this->otworz($user);
        $strona = $this->poPrzeladowaniu($this->wyslij($strona, ['akcja' => 'filtruj', 'szukaj' => 'zurek']), $user);

        $this->assertSame([$tag->getKey()], $this->widoczneTagi($strona));
    }

    /**
     * Doładowanie i filtrowanie NIE zapisują — to jest przeglądanie.
     * Gdyby zapisywały, samo szukanie tagu zmieniałoby obserwowanie.
     */
    public function test_858_doladowanie_i_filtr_niczego_nie_zapisuja(): void
    {
        $user = $this->user();
        $tagi = collect(range(1, 30))->map(fn (int $i): Tag => $this->promowany(sprintf('Temat %03d', $i)));
        $user->followedTags()->attach($tagi[0]->getKey(), ['created_at' => now()->subDay()]);

        $strona = $this->otworz($user);
        $this->wyslij($strona, ['akcja' => 'wiecej'], [$tagi[1]->getKey()])->assertRedirect();

        $this->assertSame([$tagi[0]->getKey()], $user->followedTags()->pluck('tags.id')->all(),
            'Doładowanie zapisało zmianę, której człowiek nie potwierdził.');
    }

    /**
     * Doładowanie nie jest furtką obok kontroli z #854: token cudzego konta
     * nie staje się na tej drodze własnym.
     *
     * Okno listy (ile pozycji widać) świadomie NIE jest tu granicą — granicą
     * jest token. Cudzy token zostaje odrzucony, lista pokazanych zaczyna się
     * od zera i mintuje się na nowo z WŁASNEGO wszechświata, więc nic z cudzego
     * formularza nie wchodzi do zakresu zapisu.
     */
    public function test_858_doladowanie_z_obcym_tokenem_nie_wnosi_cudzego_zakresu(): void
    {
        $user = $this->user();
        $obcy = $this->user();
        $tagi = collect(range(1, 30))->map(fn (int $i): Tag => $this->promowany(sprintf('Temat %03d', $i)));
        $obcy->followedTags()->attach($tagi[27]->getKey(), ['created_at' => now()->subDay()]);
        $obcyFormularz = $this->otworz($obcy);
        $obcyToken = $this->polaUkryte($obcyFormularz, 'form_scope');

        $strona = $this->otworz($user);
        $this->wyslij($strona, ['akcja' => 'wiecej', 'form_scope' => $obcyToken])->assertRedirect();

        $strona = $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();
        $this->assertNotSame($obcyToken, $this->polaUkryte($strona, 'form_scope'),
            'Formularz niesie dalej token cudzego konta.');

        $this->wyslij($strona)->assertSessionHasNoErrors();
        $this->assertSame([], $user->followedTags()->pluck('tags.id')->all());
        $this->assertSame([$tagi[27]->getKey()], $obcy->followedTags()->pluck('tags.id')->all(),
            'Zapis ruszył relacje cudzego konta.');
    }
}
