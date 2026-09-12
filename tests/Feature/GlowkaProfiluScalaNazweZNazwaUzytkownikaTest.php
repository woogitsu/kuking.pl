<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Główka profilu na telefonie (issue #435).
 *
 * ZGŁOSZENIE WŁAŚCICIELA brzmiało dosłownie: „Mój profil jest miejsce by dać
 *
 * @woogitsu obok Mateusz". Za tym jednym zdaniem stoi mierzalna rzecz: główka
 * własnego profilu przy 390 px miała 952 px wysokości, więc rząd akcji
 * („Zmień swój profil" · „Dodaj zdjęcie" · „Ustawienia" · „Wyloguj się")
 * stał na `y ≈ 851` przy oknie 844 px — poniżej pierwszego ekranu. To samo
 * zmierzył wcześniej D-168, dochodząc do tej główki od innej strony.
 *
 * TRZY ZMIANY, KAŻDA Z WŁASNYM POMIAREM (`scripts/glowka-profilu.mjs`):
 *   1. `@nazwa` wchodzi do wiersza z nazwą, zamiast brać własny;
 *   2. próg 11rem na kolumnie awatara obowiązuje dopiero od układu
 *      dwukolumnowego — niżej łamał napis przycisku na trzy wiersze;
 *   3. 48 px celu kliknięcia dostają te liczniki, w które da się kliknąć.
 *
 * ZMIERZONE, 390 px, czcionka 100%: główka 951,77 → 868,03 px, rząd akcji
 * `y` 850,77 → 767,03 px, czyli po raz pierwszy NAD zgięciem.
 *
 * CZEGO PILNUJE TEN TEST, A CZEGO NIE
 * Nie pilnuje pikseli — od tego jest skrypt pomiarowy, bo liczba pikseli
 * zależy od czcionki dostępnej na maszynie. Pilnuje trzech rzeczy, które
 * KAŻDA z osobna cicho cofa tę poprawkę, nie psując niczego widocznego
 * w żadnym innym teście.
 */
class GlowkaProfiluScalaNazweZNazwaUzytkownikaTest extends TestCase
{
    use RefreshDatabase;

    private function arkusz(): string
    {
        $sciezka = base_path('resources/css/ekran-profilu.css');

        // Pułapka 2 z `docs/PULAPKI_TESTOW.md`: test skanujący plik przechodzi
        // też wtedy, gdy pliku nie ma. Brak arkusza to błąd, nie zielone.
        $this->assertFileExists($sciezka);

        return (string) file_get_contents($sciezka);
    }

    public function test_nazwa_i_nazwa_uzytkownika_stoja_w_jednym_bloku(): void
    {
        $this->user('woogitsu', ['display_name' => 'Mateusz']);

        $tresc = $this->get(route('profile.show', 'woogitsu'))->assertOk()->getContent();

        // Asercja kontrolna: to naprawdę ten profil, a nie strona błędu,
        // która przepuściłaby wszystko poniżej na pusto.
        $this->assertStringContainsString('Mateusz', (string) $tresc);

        /*
         * DOWODEM JEST ZAGNIEŻDŻENIE, NIE KOLEJNOŚĆ TEKSTU.
         *
         * Przed tą zmianą `@nazwa` stała osobnym akapitem TUŻ POD nazwą —
         * czyli w tekście odpowiedzi też występowała po niej. `assertSeeInOrder`
         * przechodziłoby więc na obu układach i nie dowodziłoby niczego
         * (pułapka 1 z `docs/PULAPKI_TESTOW.md`). Dowodem jest to, że OBIE
         * rzeczy siedzą w JEDNYM `.profil-tozsamosc` — to ten kontener robi
         * z nich wiersz.
         */
        $this->assertMatchesRegularExpression(
            '#<div class="profil-tozsamosc">.*?<h1[^>]*>\s*Mateusz\s*</h1>.*?@woogitsu.*?</div>#s',
            (string) $tresc,
        );
    }

    public function test_prog_szerokosci_kolumny_awatara_stoi_po_regule_bazowej(): void
    {
        $arkusz = $this->arkusz();

        $bazowa = strpos($arkusz, '.profil-awatar-zmiana {');
        $this->assertIsInt($bazowa, 'Zniknęła reguła bazowa `.profil-awatar-zmiana`.');

        $wMediach = strpos($arkusz, 'min-width: 48rem', (int) $bazowa);
        $this->assertIsInt(
            $wMediach,
            'Za regułą bazową nie ma już `@media (min-width: 48rem)` przywracającego próg 11rem '
            .'— bez niego kolumna awatara zabiera pół karty na komputerze.',
        );

        /*
         * TO JEST TEST NA BŁĄD, KTÓRY NAPRAWDĘ ZASZEDŁ (12.09.2026).
         *
         * Pierwsza wersja tej poprawki postawiła `@media` PRZED regułą
         * bazową. Obie mają tę samą specyficzność, więc wygrała ta niżej
         * w pliku — `max-width: 100%` — i próg 11rem przestał działać także
         * przy 1280 px. Wyglądało to na zmianę ograniczoną do telefonów,
         * a nie było nią; złapał to dopiero pomiar.
         */
        $this->assertGreaterThan(
            $bazowa,
            $wMediach,
            'Próg 11rem stoi PRZED regułą bazową `max-width: 100%`, więc przy tej samej '
            .'specyficzności nie działa na żadnej szerokości.',
        );

        $this->assertStringContainsString('max-width: min(11rem, 100%)', substr($arkusz, (int) $wMediach, 400));
    }

    public function test_48_px_celu_klikniecia_dostaja_tylko_klikalne_liczniki(): void
    {
        $arkusz = $this->arkusz();

        $this->assertStringContainsString(
            "a.profil-licznik-pole {\n    min-height: 3rem;",
            $arkusz,
            'Dwa liczniki relacji są odnośnikami do listy osób i muszą mieć 48 px celu kliknięcia '
            .'(AGENTS.md §5).',
        );

        /*
         * NEGATYW: próg nie może wrócić na WSZYSTKIE pięć pozycji. Gdyby
         * wrócił, główka rośnie z powrotem o ~33 px na telefonie, a żaden
         * inny test tego nie zauważy — bo widać dokładnie tę samą treść.
         */
        $bezKlikania = strpos($arkusz, '.profil-licznik-pole {');
        $this->assertIsInt($bezKlikania);

        $regula = substr($arkusz, (int) $bezKlikania, (int) strpos($arkusz, '}', (int) $bezKlikania) - (int) $bezKlikania);

        $this->assertStringNotContainsString(
            'min-height',
            $regula,
            'Trzy z pięciu liczników to zwykły tekst, w który nie da się kliknąć — 48 px celu '
            .'dotknięcia nic im nie daje, a podbija główkę o ~33 px (#435).',
        );
    }
}
