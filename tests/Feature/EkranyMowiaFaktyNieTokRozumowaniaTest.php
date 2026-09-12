<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cztery ekrany po audycie tekstów z 11 września 2026.
 *
 * REGUŁA, KTÓREJ TE TESTY PILNUJĄ:
 *
 * > Użytkownikowi piszemy, co się dzieje, co to dla niego znaczy i co ma
 * > zrobić. Powód architektoniczny, historia decyzji, odrzucone warianty
 * > i dowód z audytu zostają w repozytorium.
 *
 * KAŻDY EKRAN MA TU PARĘ ASERCJI, NIE JEDNĄ. Asercja „tego zdania nie ma"
 * przechodzi także wtedy, gdy ekran nie zwraca niczego albo gdy ktoś skasował
 * pół strony (pułapka 4 z `docs/PULAPKI_TESTOW.md`). Dlatego przy każdym
 * zdjętym zdaniu stoi obok kontrola dodatnia na FAKT, który miał zostać.
 *
 * Wszystko idzie po WYRENDEROWANYM ekranie, nie po pliku Blade.
 */
class EkranyMowiaFaktyNieTokRozumowaniaTest extends TestCase
{
    use RefreshDatabase;

    /** Wycinek HTML-a między dwoma znacznikami — patrz pułapka 1. */
    private function wycinek(string $html, string $od, string $do): string
    {
        $start = mb_strpos($html, $od);
        $this->assertNotFalse($start, "Nie znalazłem znacznika początku „{$od}”.");

        $koniec = mb_strpos($html, $do, $start);
        $koniec = $koniec === false ? mb_strlen($html) : $koniec;

        $wycinek = mb_substr($html, $start, $koniec - $start);
        $this->assertGreaterThan(80, mb_strlen($wycinek), 'Wycinek jest podejrzanie krótki.');

        return $wycinek;
    }

    /* ------------------------------------------------------------------
       1. /ustawienia/twoje-dane — usuwanie konta bez kazania
    ------------------------------------------------------------------ */

    public function test_ekran_danych_nie_ocenia_wyboru_czlowieka(): void
    {
        $html = $this->actingAs($this->user('ania'))
            ->get(route('settings.data'))
            ->assertOk()
            ->getContent();

        // Kontrola dodatnia: to na pewno ten ekran.
        $this->assertStringContainsString('Co zniknie, a co zostanie', $html);

        // Zdjęte: zdanie mówiące człowiekowi, że wybierając drugą opcję,
        // zabrałby coś ludziom, którzy o nic nie prosili. Uzasadnienie
        // domyślnego zakresu usunięcia to D-022 i mieszka w repozytorium.
        $this->assertStringNotContainsString('o nic nie prosili', $html);
        $this->assertStringNotContainsString('zabrałoby coś ludziom', $html);

        // Zdjęte: „Dlatego haczyk jest domyślnie pusty" — powód naszej
        // decyzji. Że haczyk jest pusty, człowiek widzi w formularzu.
        $this->assertStringNotContainsString('Dlatego haczyk jest domyślnie pusty', $html);
    }

    public function test_ekran_danych_nadal_mowi_co_zniknie_i_co_zrobic(): void
    {
        $html = $this->actingAs($this->user('ania'))
            ->get(route('settings.data'))
            ->assertOk()
            ->getContent();

        // To jest ekran, na którym pomyłka kosztuje najwięcej. Trzy listy
        // i zdanie o nieodwracalności muszą stać nienaruszone.
        $this->assertStringContainsString('Znikną na stałe — zawsze', $html);
        $this->assertStringContainsString('Zostaną bez Twojego nazwiska', $html);
        $this->assertStringContainsString('Znikną razem z resztą', $html);
        $this->assertStringContainsString('Tego nie da się odwrócić', $html);
        // Bez „przywróci" na końcu: zdanie łamie się w źródle na dwie linie,
        // a asercja na całej frazie sprawdzałaby wtedy układ pliku Blade,
        // nie treść ekranu.
        $this->assertStringContainsString('skasowanego tekstu nikt już nie', $html);

        // Co ma zrobić, jeśli chce usunąć tylko część.
        $this->assertStringContainsString('usuń je samodzielnie, zanim', $html);
        $this->assertStringContainsString('Zanim to zrobisz, warto najpierw pobrać swoje dane', $html);

        // Fakt, że przepis może być w cudzym zeszycie, nie zniknął razem
        // z kazaniem — stoi na liście skutków zaznaczonego haczyka.
        $this->assertStringContainsString('znikną też z zeszytów innych osób', $html);
    }

    /* ------------------------------------------------------------------
       2. /tagi — opis dla wyszukiwarki mówi, co jest na stronie
    ------------------------------------------------------------------ */

    public function test_opis_spisu_tematow_nie_opisuje_polityki_sortowania(): void
    {
        $html = $this->get(route('tags.index'))->assertOk()->getContent();

        $opis = $this->wycinek($html, '<meta name="description"', '>');

        // Kontrola dodatnia: opis w ogóle jest i mówi, co to za strona.
        $this->assertStringContainsString('Spis tagów w Kuking', $opis);
        $this->assertStringContainsString('składniki', $opis);

        // Zdjęte: nasza polityka porządkowania treści.
        $this->assertStringNotContainsString('Bez rankingu', $opis);
        $this->assertStringNotContainsString('wybór gospodarza', $opis);
    }

    public function test_sama_strona_tematow_dalej_mowi_skad_kolejnosc(): void
    {
        $html = $this->get(route('tags.index'))->assertOk()->getContent();

        // Informacja nie zniknęła — przeniosła się tam, gdzie jest dla
        // czytelnika, a nie dla wyszukiwarki.
        $this->assertStringContainsString('Wszystkie tagi od A do Z', $html);
    }

    /* ------------------------------------------------------------------
       3. /o-kuking — charakter zostaje, odpieranie zarzutów nie
    ------------------------------------------------------------------ */

    public function test_o_kuking_nie_odpiera_zarzutow_ktorych_nikt_nie_postawil(): void
    {
        $html = $this->get(route('about'))->assertOk()->getContent();

        // KOTWICA W TREŚCI STRONY, NIE W NAGŁÓWKU DOKUMENTU (pułapka 1).
        //
        // Do 12.09.2026 stało tu `assertStringContainsString('O Kuking', $html)`
        // na CAŁYM dokumencie. Zmierzone: po D-145 napis „O Kuking" nie pada na
        // tej stronie ani razu w treści — jest wyłącznie w `<title>` i w dwóch
        // `<meta>`, bo nagłówek brzmi „O kuKING" i nazwa jest rozbita na
        // znaczniki. Asercja przechodziła więc z powodu nagłówka DOKUMENTU
        // i nie umiała się zaświecić na czerwono (D-132).
        $tresc = $this->wycinek($html, '<main', '</main>');

        // Kontrola dodatnia: to na pewno ten ekran — i to jego własny nagłówek,
        // w dwukolorowym zapisie nazwy z D-145.
        $this->assertStringContainsString('<h1>O <span class="kuking-word">', $tresc);

        $this->assertStringNotContainsString('nie kolejna baza przepisów', $html);
        $this->assertStringNotContainsString('a nie „kiedyś', $html);
        $this->assertStringNotContainsString('liczników w twarz', $html);
    }

    public function test_o_kuking_nadal_mowi_glosno_o_tym_co_jest_prawda(): void
    {
        $html = $this->get(route('about'))->assertOk()->getContent();

        // Ta strona jest jednym z dwóch miejsc, gdzie charakter marki ma
        // prawo mówić głośno (COPY_STYLE.md §3). Poprawka nie miała jej
        // wygładzić do zera — i ten test jest na to jedynym zabezpieczeniem.
        $this->assertStringContainsString('Garnek.pl', $html);
        $this->assertStringContainsString('Durszlak.pl', $html);
        $this->assertStringContainsString('eksport własnych danych działa od pierwszego dnia', $html);
        // Sekcja zobowiązań ma tu zostać — zmienił się tylko jej TYTUŁ, bo
        // właściciel kazał przepisać całą sekcję z zaprzeczenia na twierdzenie.
        // Czego pilnuje treść tej sekcji, pilnuje
        // `ZobowiazaniaNaOKukingSaTwierdzeniamiTest`.
        $this->assertStringContainsString('Na co możesz liczyć', $html);
        $this->assertStringContainsString('Ludzie, nie treści', $html);
        $this->assertStringContainsString('Bez rankingu popularności', $html);
    }

    /* ------------------------------------------------------------------
       4. /napisz-do-nas/dziekujemy — bez zapewnień o implementacji
    ------------------------------------------------------------------ */

    public function test_potwierdzenie_nie_opowiada_o_tym_jak_to_dziala_u_nas(): void
    {
        $html = $this->withSession(['kontakt_odpowiedz_na' => 'basia@example.test'])
            ->get(route('kontakt.potwierdzenie'))
            ->assertOk()
            ->getContent();

        // Zdjęte zapewnienie o trwałości zapisu — prawdziwe, ale mówiące
        // o naszej architekturze, nie o sprawie czytelnika.
        $this->assertStringNotContainsString('Zapisała się w Kuking', $html);
        $this->assertStringNotContainsString('nawet gdyby akurat nie działała poczta', $html);

        // Zdjęta postawa i powód po naszej stronie.
        $this->assertStringNotContainsString('nie będziemy go udawać', $html);
        $this->assertStringNotContainsString('prowadzi na razie', $html);
    }

    public function test_potwierdzenie_nadal_mowi_ze_mamy_wiadomosc_i_gdzie_odpiszemy(): void
    {
        $html = $this->withSession(['kontakt_odpowiedz_na' => 'basia@example.test'])
            ->get(route('kontakt.potwierdzenie'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Mamy Twoją wiadomość', $html);
        $this->assertStringContainsString('Odpowiedź wyślemy na', $html);
        $this->assertStringContainsString('basia@example.test', $html);

        // Oczekiwanie co do czasu zostaje — bez tego człowiek nie wie,
        // czy ma na co czekać.
        $this->assertStringContainsString('czasem po weekendzie', $html);
    }

    public function test_potwierdzenie_bez_adresu_dalej_mowi_ze_nie_ma_jak_odpisac(): void
    {
        $html = $this->get(route('kontakt.potwierdzenie'))->assertOk()->getContent();

        // Kontrola dodatnia dla drugiej gałęzi warunku (pułapka 3b — każda
        // gałąź potrzebuje własnego sprawdzenia).
        $this->assertStringContainsString('nie mamy jak odpisać', $html);
        $this->assertStringNotContainsString('Zapisała się w Kuking', $html);
    }
}
