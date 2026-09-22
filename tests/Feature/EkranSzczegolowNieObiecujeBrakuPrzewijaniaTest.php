<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ekran „Dopisz szczegóły" nie obiecuje, że nie trzeba przewijać.
 *
 * CO BYŁO. Nad formularzem stało: „Wszystko jest na jednej stronie — nie
 * musisz nic przewijać ani szukać." Zmierzone 11 września 2026 na postawionej
 * lokalnie instancji (Chromium 1243, konto z demo, ten sam ekran):
 *
 * | stan przepisu | 360 px | 1280 px |
 * |---|---|---|
 * | sam tytuł (3 puste wiersze składników i kroków) | 10 249 px | 7 836 px |
 * | 8 składników i 6 kroków | 16 586 px | 13 065 px |
 *
 * Przy oknie telefonu 640 px to jest 16 do 26 ekranów przewijania. Zdanie było
 * po prostu nieprawdziwe, a nieprawda na ekranie, na którym ktoś dopisuje
 * szczegóły do przepisu po babci, kosztuje zaufanie od razu.
 *
 * CZEGO TEN TEST PILNUJE — DWÓCH RZECZY NARAZ, I TO JEST CAŁY SENS.
 *
 *  1. obietnicy o nieprzewijaniu na tym ekranie NIE MA;
 *  2. informacja, po którą człowiek tu przyszedł, ZOSTAŁA: że dodatkowe szczegóły nie są
 *     obowiązkowe, wypełnia tyle, ile chce, i że poprawnie wpisane dane
 *     nie zginą.
 *
 * Bez punktu 2 najprostszym „przejściem" tego testu byłoby skasowanie całego
 * akapitu — czyli wyrzucenie informacji razem z obietnicą.
 *
 * Test chodzi po WYRENDEROWANYM ekranie, nie po pliku Blade: to samo zdanie
 * może wrócić z komponentu, z tłumaczenia albo z drugiego widoku, a plik
 * `szczegoly.blade.php` byłby wtedy czysty.
 */
class EkranSzczegolowNieObiecujeBrakuPrzewijaniaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wstęp ekranu — od nagłówka do znacznika formularza.
     *
     * Wycinamy sekcję, zamiast szukać w całym HTML-u (pułapka 1
     * z `docs/PULAPKI_TESTOW.md`): słowo „przewijać" ma prawo stać w belce,
     * w stopce albo w prawej szynie i wtedy asercja łapałaby nie to zdanie,
     * o które chodzi.
     */
    private function wstep(string $html): string
    {
        $start = mb_strpos($html, '<h1>');
        $this->assertNotFalse($start, 'Nie znalazłem nagłówka <h1> — to nie jest ten ekran.');

        $koniec = mb_strpos($html, '<form', $start);
        $this->assertNotFalse($koniec, 'Nie znalazłem formularza — wstęp nie ma gdzie się kończyć.');

        $wstep = mb_substr($html, $start, $koniec - $start);

        // Kontrola dodatnia wycinka (pułapka 4): pusty albo mikroskopijny
        // wycinek przeszedłby każdą asercję „czegoś tu nie ma".
        $this->assertGreaterThan(120, mb_strlen($wstep), 'Wycinek wstępu jest podejrzanie krótki.');

        return $wstep;
    }

    private function autorZPrzepisem(): array
    {
        $autor = $this->user('ania');
        $przepis = Recipe::factory()->for($autor, 'author')->create(['title' => 'Rosół babci Zofii']);

        return [$autor, $przepis];
    }

    public function test_dopisz_szczegoly_nie_obiecuje_ze_nie_trzeba_przewijac(): void
    {
        [$autor, $przepis] = $this->autorZPrzepisem();

        $html = $this->actingAs($autor)
            ->get(route('recipes.edit', $przepis))
            ->assertOk()
            ->getContent();

        $wstep = $this->wstep($html);

        // Kontrola dodatnia: to na pewno TEN ekran, a nie strona błędu
        // ani przekierowanie (pułapka 4 — asercja „czegoś nie ma" przechodzi
        // także na pustej stronie).
        $this->assertStringContainsString('Dopisz szczegóły', $wstep);

        // Rdzeń sprawy. Rdzeń „przewij" łapie każdą odmianę: „przewijać",
        // „przewijania", „nie przewiniesz".
        $this->assertStringNotContainsStringIgnoringCase(
            'przewij',
            $wstep,
            'Ekran „Dopisz szczegóły" znowu mówi coś o przewijaniu. '
            .'Zmierzone: 10 249 px (360 px, sam tytuł) do 16 586 px '
            .'(360 px, 8 składników i 6 kroków) — przewijać trzeba.',
        );
        $this->assertStringNotContainsStringIgnoringCase('nie musisz nic szukać', $wstep);
    }

    public function test_dopisz_szczegoly_nadal_mowi_ze_szczegoly_sa_opcjonalne(): void
    {
        [$autor, $przepis] = $this->autorZPrzepisem();

        $wstep = $this->wstep(
            $this->actingAs($autor)
                ->get(route('recipes.edit', $przepis))
                ->assertOk()
                ->getContent(),
        );

        // Druga połowa poprawki: usunięcie obietnicy nie miało prawa zabrać
        // informacji, po którą człowiek na ten ekran przyszedł.
        $this->assertStringContainsString(
            'Pozostałe szczegóły są opcjonalne',
            $wstep,
            'Zniknęła informacja, że dodatkowe szczegóły nie są wymagane — a to jest '
            .'jedyny powód, dla którego ktoś ten długi formularz w ogóle zaczyna.',
        );
        $this->assertStringContainsString('wypełnij tyle, ile chcesz', $wstep);
        $this->assertStringContainsString(
            'Poprawnie wpisane dane nie zginą',
            $wstep,
            'Zniknęła informacja o `old()` — AGENTS.md §5.',
        );
    }

    /**
     * Ten sam widok, drugie wejście: `/dodaj/przepis/jedna-strona`.
     *
     * `pages/recipes/szczegoly.blade.php` obsługuje DWA adresy i ten sam
     * akapit renderuje się na obu. Gdyby test sprawdzał tylko wariant edycji,
     * powrót zdania na wariancie dodawania przeszedłby niezauważony.
     */
    public function test_ten_sam_akapit_bez_obietnicy_takze_przy_dodawaniu(): void
    {
        $wstep = $this->wstep(
            $this->actingAs($this->user('marek'))
                ->get(route('recipes.create.simple'))
                ->assertOk()
                ->getContent(),
        );

        $this->assertStringContainsString('Dodaj przepis ze szczegółami', $wstep);
        $this->assertStringNotContainsStringIgnoringCase('przewij', $wstep);
        $this->assertStringContainsString('Pozostałe szczegóły są opcjonalne', $wstep);
    }

    /**
     * Kontrola dodatnia dla samego POMIARU, nie dla zdania.
     *
     * Ten test nie mierzy pikseli — tego PHPUnit nie umie i nie udaje, że
     * umie (pomiar wysokości jest w opisie klasy i został zrobiony w
     * przeglądarce). Mierzy to, co da się zmierzyć bez przeglądarki i co jest
     * przyczyną tamtych pikseli: że ten ekran renderuje kilkadziesiąt
     * kontrolek. Gdyby kiedyś zeszło ich do kilku, zdanie o nieprzewijaniu
     * przestałoby być nieprawdą — i wtedy ten test ma się upomnieć, żeby
     * ktoś na nowo przeczytał opis klasy, zamiast trzymać zakaz z rozpędu.
     */
    public function test_ekran_naprawde_ma_kilkadziesiat_kontrolek(): void
    {
        [$autor, $przepis] = $this->autorZPrzepisem();

        $html = $this->actingAs($autor)
            ->get(route('recipes.edit', $przepis))
            ->assertOk()
            ->getContent();

        $kontrolki = preg_match_all('~<(input|textarea|select)\b~i', $html);

        $this->assertGreaterThan(
            30,
            $kontrolki,
            'Ten ekran ma nagle mało kontrolek. Jeśli to celowe — przeczytaj opis '
            .'tej klasy i zmierz wysokość ekranu jeszcze raz, zanim cokolwiek '
            .'obiecasz człowiekowi o przewijaniu.',
        );
    }
}
