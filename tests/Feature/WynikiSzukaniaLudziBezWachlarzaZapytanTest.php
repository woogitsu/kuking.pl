<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/szukaj` — wachlarz zapytań (N+1) na wynikach „Ludzie".
 *
 * CO BYŁO ZEPSUTE
 * `SearchQuery::people()` doładowywało `['user', 'avatar']`, czyli konto
 * i zdjęcie profilowe — i wyglądało to na komplet. Nie było komplet, bo oba
 * ekrany korzystające z tej metody rysują zdjęcie komponentem
 * `<x-avatar :user="$profil->user" />`, a ten komponent przyjmuje KONTO
 * i sam wraca po profil (`$user?->profile`, potem `zdjecieDoPokazania()`
 * → `avatar`). Wynikiem zapytania są PROFILE, więc doładowany `avatar`
 * siedział na innej instancji niż ta, po którą sięgał komponent.
 *
 * Skutek: jedno `select * from profiles where user_id = ?` na KAŻDĄ wypisaną
 * osobę, a przy kontach ze zdjęciem profilowym jeszcze jedno po wiersz
 * `media`. Strona wyników wypisuje do dwudziestu osób i ma parametr `?ile=`
 * z górną granicą dwustu (`SearchController::MAKS`), więc jedno wejście
 * mogło kosztować czterysta zapytań ponad plan.
 *
 * METODA (ta sama co w `MiniaturyBezWachlarzaZapytanTest` i w teście „bez
 * wachlarza zapytań" z `FeedTagowNieGubiKolumnPrzepisuTest`): nie „ile
 * zapytań wypada", tylko „czy liczba ROŚNIE z liczbą wierszy". Próg na
 * sztywno zestarzałby się przy pierwszej uzasadnionej zmianie tego ekranu.
 *
 * ZMIERZONE PRZED POPRAWKĄ: 16 zapytań przy 2 osobach, 34 przy 20.
 * PO POPRAWCE: 15 i 15 — o jedno mniej także przy dwóch osobach, bo znika
 * zapytanie, które i tak było zbędne.
 *
 * TEN SAM BŁĄD STAŁ NA DRUGIM EKRANIE i dlatego poprawka jest w klasie
 * domenowej, a nie w kontrolerze: krok onboardingu „znasz już kogoś tutaj?"
 * (`OnboardingController::people()`) woła tę samą metodę i rysuje wyniki tym
 * samym komponentem. Pilnuje tego ostatni test w tym pliku.
 */
class WynikiSzukaniaLudziBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    /** Ile osób wypisuje strona wyników — `SearchController::NA_STRONIE`. */
    private const PELNA_STRONA = 20;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    /**
     * Konta pasujące do frazy „pierogarz", KAŻDE ZE ZDJĘCIEM PROFILOWYM.
     *
     * Zdjęcie nie jest ozdobą danych. Bez niego `zdjecieDoPokazania()`
     * kończy się na pustym `avatar_media_id` i druga warstwa wachlarza —
     * zapytanie po wiersz `media` na każdą osobę — w ogóle nie ma jak się
     * pokazać. Test bez zdjęć mierzyłby połowę usterki.
     */
    private function ludzie(int $ile, int $od = 1): void
    {
        for ($i = $od; $i < $od + $ile; $i++) {
            $osoba = $this->user('pierogarz'.$i, ['display_name' => 'Pierogarz numer '.$i]);

            Profile::query()->where('user_id', $osoba->getKey())->update([
                'avatar_media_id' => Media::factory()->create(['owner_id' => $osoba->getKey()])->getKey(),
            ]);
        }
    }

    public function test_wyniki_po_ludziach_nie_maja_wachlarza_zapytan(): void
    {
        $widz = $this->user('szukajaca');

        // MAŁO: dwie osoby pasujące do frazy.
        $this->ludzie(2);
        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('search', ['q' => 'pierogarz', 'sekcja' => 'ludzie']))->assertOk(),
        );

        // DUŻO: pełna strona wyników. Zbioru NIE czyścimy — tu właśnie
        // o wzrost chodzi, a obie próby są osobnymi żądaniami HTTP, więc
        // druga nie dolicza niczego z pierwszej (inaczej niż przy feedzie
        // w `MiniaturyBezWachlarzaZapytanTest`, gdzie wpisy z pierwszej
        // próby zostawały w strumieniu drugiej).
        $this->ludzie(self::PELNA_STRONA - 2, od: 3);

        $odpowiedz = $this->actingAs($widz)->get(route('search', ['q' => 'pierogarz', 'sekcja' => 'ludzie']))->assertOk();

        // KONTROLA DODATNIA (docs/PULAPKI_TESTOW.md §4): stała liczba zapytań
        // przechodziłaby także wtedy, gdyby wyszukiwarka nie zwracała NIKOGO.
        // Dopiero to, że dwudziesta osoba naprawdę jest na ekranie, czyni
        // z pomiaru niżej dowód czegokolwiek.
        $this->assertStringContainsString(
            'Pierogarz numer '.self::PELNA_STRONA,
            $odpowiedz->getContent(),
            'Na ekranie nie ma ostatniej z dwudziestu osób — pomiar niżej nie mierzy pełnej strony wyników.',
        );

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('search', ['q' => 'pierogarz', 'sekcja' => 'ludzie']))->assertOk(),
        );

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Liczba zapytań rośnie z liczbą znalezionych osób (N+1): {$maloZapytan} przy 2 osobach, "
            ."{$duzoZapytan} przy ".self::PELNA_STRONA.'. Zdjęcie profilowe jest dociągane osobno dla każdego wyniku.',
        );
    }

    /**
     * Zakładka „Wszystko" — obie listy na jednym ekranie.
     *
     * Osobny test, bo psuje się osobno: `/szukaj?sekcja=ludzie` pomija
     * przepisy w ogóle (`$szukaPrzepisow` jest wtedy fałszywe), więc wachlarz
     * na KARCIE PRZEPISU nie miałby jak się tam pokazać. Domyślna zakładka
     * pokazuje jedno i drugie i to ona jest tym, co widzi człowiek, który po
     * prostu wpisał słowo w pole.
     */
    public function test_zakladka_wszystko_nie_ma_wachlarza_zapytan_ani_na_ludziach_ani_na_przepisach(): void
    {
        $widz = $this->user('szukajacy_wszystko');
        $autor = $this->user('pierogarz_autor', ['display_name' => 'Pierogarz od przepisów']);

        $this->ludzie(2);
        $this->przepisy($autor, 2);

        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('search', ['q' => 'pierog']))->assertOk(),
        );

        $this->ludzie(self::PELNA_STRONA - 2, od: 3);
        $this->przepisy($autor, self::PELNA_STRONA - 2, od: 3);

        $odpowiedz = $this->actingAs($widz)->get(route('search', ['q' => 'pierog']))->assertOk();

        // Kontrola dodatnia dla OBU list naraz — przy „Wszystko" pusta może
        // być jedna z nich, a druga nie, i wtedy pomiar dotyczy tylko połowy
        // ekranu.
        $this->assertStringContainsString('Pierogarz numer '.self::PELNA_STRONA, $odpowiedz->getContent());
        $this->assertStringContainsString('Pierogi ruskie numer '.self::PELNA_STRONA, $odpowiedz->getContent());

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('search', ['q' => 'pierog']))->assertOk(),
        );

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Zakładka „Wszystko”: {$maloZapytan} zapytań przy 2 wynikach w każdej liście, {$duzoZapytan} przy "
            .self::PELNA_STRONA.' — wachlarz zapytań na wynikach wyszukiwania.',
        );
    }

    /**
     * Krok onboardingu „znasz już kogoś tutaj?" — DRUGI ekran tej samej
     * usterki.
     *
     * `OnboardingController::people()` woła `SearchQuery::people()` i rysuje
     * wyniki tym samym komponentem awatara. Gdyby poprawka wylądowała
     * w `SearchController` zamiast w klasie domenowej, ten ekran zostałby
     * z wachlarzem — i nikt by się o tym nie dowiedział, bo to jest pierwsza
     * godzina nowego konta, a nie ekran, na który ktoś patrzy z zegarkiem.
     */
    public function test_onboarding_szukajac_ludzi_tez_nie_ma_wachlarza_zapytan(): void
    {
        $nowy = $this->user('nowa_osoba');

        $this->ludzie(2);
        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($nowy)->get(route('onboarding.people', ['q' => 'pierogarz']))->assertOk(),
        );

        // Ten ekran wypisuje najwyżej pięć wyników
        // (`OnboardingController::WYNIKI_WYSZUKIWANIA`), więc różnica między
        // próbami to 2 → 5 wypisanych osób, a nie 2 → 20. Mniejsza niż na
        // `/szukaj`, ale dokładnie taka, jaką ten ekran umie pokazać —
        // dosypywanie ponad limit mierzyłoby wzrost, którego tu nie ma.
        $this->ludzie(8, od: 3);

        $odpowiedz = $this->actingAs($nowy)->get(route('onboarding.people', ['q' => 'pierogarz']))->assertOk();
        $this->assertStringContainsString('Pierogarz numer 1', $odpowiedz->getContent());

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($nowy)->get(route('onboarding.people', ['q' => 'pierogarz']))->assertOk(),
        );

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Krok onboardingu: {$maloZapytan} zapytań przy 2 znalezionych osobach, {$duzoZapytan} przy pięciu (pełna strona tego kroku).",
        );
    }

    /** Przepisy pasujące do frazy „pierog", każdy ze zdjęciem głównym. */
    private function przepisy(User $autor, int $ile, int $od = 1): void
    {
        for ($i = $od; $i < $od + $ile; $i++) {
            Recipe::factory()->for($autor, 'author')->create([
                'title' => 'Pierogi ruskie numer '.$i,
                'hero_media_id' => Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
            ]);
        }
    }
}
