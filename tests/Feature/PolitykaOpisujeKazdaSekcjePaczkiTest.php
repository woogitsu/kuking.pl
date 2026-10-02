<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\InwentarzDanychKonta;
use Tests\TestCase;

/**
 * Każda kategoria danych konta ma opis w polityce prywatności (#2281, audyt 30.09 Z5).
 *
 * Tabela celów w punkcie 2 nie nadążała za funkcjami V2: planer, wspólne
 * zeszyty, zapamiętany postęp gotowania i wczytanie własnej paczki zbierały
 * dane bez celu, podstawy i terminu w polityce (RODO art. 13 ust. 1 lit. c
 * i ust. 2 lit. a). Nikt tego nie przemilczał celowo — po prostu nic nie
 * pytało o to w dniu, w którym powstawała tabela.
 *
 * Pyta teraz `InwentarzDanychKonta`: każda kolumna wskazująca na konto ma
 * tam sekcję paczki, a KAŻDA sekcja `EKSPORT` musi mieć tu frazę, która stoi
 * w polityce. Nowa tabela z nową sekcją oblewa ten test, dopóki ktoś nie
 * opisze jej w polityce i nie dopisze tu, gdzie szukać tego opisu.
 *
 * WYJĄTKI SĄ WARUNKOWE. Funkcja wyłączona w konfiguracji (Web Push, API
 * mobilne, import przepisów przez model) może nie mieć jeszcze wiersza —
 * `DEPLOYMENT_RUNBOOK.md` każe dopisać go przed włączeniem. Test sprawdza,
 * że wyłącznik jest domyślnie zamknięty; gdy ktoś go otworzy w kodzie,
 * wyjątek przestaje działać.
 *
 * CZEGO NIE DOWODZI: że opis jest pełny i prawdziwy. Dowodzi, że żadna
 * kategoria z paczki nie jest w polityce przemilczana.
 */
class PolitykaOpisujeKazdaSekcjePaczkiTest extends TestCase
{
    /**
     * Sekcja paczki → fraza, która w polityce opisuje te dane.
     *
     * @var array<string, string>
     */
    private const FRAZY = [
        'konto' => '| Założenie i prowadzenie konta |',
        'profil' => '| Twój publiczny profil |',
        'przepisy' => 'przepisy wraz z ich wcześniejszymi wersjami',
        'wersje_przepisow' => 'przepisy wraz z ich wcześniejszymi wersjami',
        'wpisy' => '| Publikowanie treści |',
        'ugotowalem' => 'komentarze, „Ugotowałem” i zeszyty',
        // Wskazówki od gotujących (#2352, D-333): obie strony tej samej tabeli.
        'wskazowki_z_moich_wykonan' => '| Wskazówki od gotujących (',
        'wskazowki_do_moich_przepisow' => '| Wskazówki od gotujących (',
        'moje_komentarze' => 'zdjęcia, przepisy wraz z ich wcześniejszymi wersjami, wpisy, komentarze',
        'kolekcje' => 'komentarze, „Ugotowałem” i zeszyty',
        'zeszyty_udostepnione_mi' => '| Wspólne zeszyty',
        'zaproszenia_do_zeszytow' => 'kto kogo zaprosił do którego zeszytu',
        'obserwuje' => 'kogo obserwujesz',
        'obserwuja_mnie' => '| Relacje w serwisie |',
        'zablokowane_osoby' => 'kogo blokujesz',
        'obserwowane_tagi' => 'jakie tagi obserwujesz',
        'co_mam_w_domu' => '| Lista „Co mam w domu” |',
        // Paczka J (#27) dopisuje tę sekcję i wiersz polityki — fraza czeka tu na scalenie.
        'lista_zakupow' => '| Lista zakupów |',
        'postep_gotowania' => '| Zapamiętany postęp w trybie gotowania |',
        'wspolne_gotowanie' => '| Wspólne gotowanie |',
        'dopiski_z_gotowania' => '| Prywatny dopisek z gotowania |',
        'zapamietane_porcje' => '| Zapamiętana liczba porcji przy przepisie |',
        'ukryte' => '| Ukrywanie wpisów i osób',
        'moje_reakcje' => '| Reakcja „Smakowicie wygląda”',
        'moje_podziekowania' => '| „Dziękuję” pod cudzym komentarzem',
        'powiadomienia' => '| Powiadomienia w serwisie |',
        'zdjecia' => '| Publikowanie treści | zdjęcia',
        'dziennik_zgod' => 'ustawienia konta, zgody',
        'polaczone_konta' => 'identyfikator Twojego konta Google i data połączenia',
        'aktywne_sesje' => '| Utrzymanie zalogowania',
        'zmiana_adresu_email' => 'Gdy zmieniasz adres e-mail, zapisujemy też nowy adres',
        'wyslane_podsumowania_tygodnia' => 'zapis jego tygodnia',
        'zamowione_paczki' => 'paczek z Twoimi danymi do pobrania',
        'zdarzenia_w_serwisie' => '| Analiza działania serwisu',
        'wiadomosci_do_serwisu' => '| Wiadomości do nas przez formularz „Napisz do nas"',
        'moje_zgloszenia' => '| Obsługa zgłoszeń i moderacji |',
        'decyzje_moderacji' => '| Obsługa zgłoszeń i moderacji |',
        'odwolania' => 'rozstrzygnięcia odwołania',
        'planer' => '| Plan na tydzień |',
        'dawne_nazwy_profilu' => 'Gdy zmienisz nazwę użytkownika, zapamiętujemy dotychczasową',
    ];

    /**
     * Sekcje funkcji wyłączonych w konfiguracji → [wyłącznik zamknięty?, dlaczego wolno].
     *
     * @return array<string, array{bool, string}>
     */
    private function wylaczone(): array
    {
        $importWylaczony = ! config('kuking.import.url.wlaczony')
            && ! config('kuking.import.pdf.wlaczony')
            && ! config('kuking.import.zrodla.zdjecie');

        return [
            'powiadomienia_poza_serwisem' => [
                (string) config('kuking.push.vapid_public_key') === '',
                'Web Push działa dopiero z kluczami VAPID; przed ich ustawieniem trzeba dopisać go do polityki.',
            ],
            'urzadzenia_z_dostepem' => [
                ! config('kuking.api.wlaczone'),
                'API dla aplikacji mobilnej jest zamknięte (KUKING_API_ENABLED); aplikacji jeszcze nie ma.',
            ],
            'importy_przepisow' => [$importWylaczony, 'Import przepisu z adresu, PDF i zdjęcia czeka na DPA z OpenAI (D-333, #2214).'],
            'odczyty_przepisow' => [$importWylaczony, 'Jak wyżej — odczyt przez model to część importu.'],
            'proby_importu' => [$importWylaczony, 'Jak wyżej — próby odczytu to część importu.'],
        ];
    }

    public function test_kazda_sekcja_paczki_ma_opis_w_polityce(): void
    {
        $sekcje = [];

        foreach ([InwentarzDanychKonta::KOLUMNY_WSKAZUJACE_NA_KONTO, InwentarzDanychKonta::KOLUMNY_KONTA] as $mapa) {
            foreach ($mapa as [$rozstrzygniecie, $sekcja]) {
                if ($rozstrzygniecie === InwentarzDanychKonta::EKSPORT) {
                    $sekcje[$sekcja] = true;
                }
            }
        }

        // Kontrola metody (PULAPKI §2): pusta lista dałaby zieleń bez pomiaru.
        $this->assertGreaterThan(30, count($sekcje), 'Inwentarz oddał podejrzanie mało sekcji — test przestał mierzyć.');

        $polityka = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
        $this->assertStringContainsString('## 2. Jakie dane zbieramy', $polityka, 'Kontrola: czytam zły dokument.');

        $wylaczone = $this->wylaczone();

        foreach (array_keys($sekcje) as $sekcja) {
            if (isset($wylaczone[$sekcja])) {
                [$zamkniety, $powod] = $wylaczone[$sekcja];
                $this->assertTrue(
                    $zamkniety,
                    "Sekcja „{$sekcja}” nie ma opisu w polityce, bo funkcja była wyłączona ({$powod}). "
                    .'Wyłącznik jest teraz otwarty — opisz te dane w polityce i przenieś sekcję do FRAZY.',
                );

                continue;
            }

            $this->assertArrayHasKey(
                $sekcja,
                self::FRAZY,
                "Paczka ma nową sekcję „{$sekcja}”, a ten test nie wie, gdzie polityka ją opisuje. "
                .'Dopisz wiersz do tabeli w punkcie 2 polityki (cel, dane, podstawa, termin) i frazę tutaj.',
            );

            $this->assertStringContainsString(
                self::FRAZY[$sekcja],
                $polityka,
                "Dane z sekcji paczki „{$sekcja}” nie mają opisu w polityce prywatności (szukana fraza: „".self::FRAZY[$sekcja].'”).',
            );
        }
    }

    /** Liczby w nowych wierszach idą z konfiguracji, nie z pamięci (D-024). */
    public function test_terminy_nowych_wierszy_zgadzaja_sie_z_konfiguracja(): void
    {
        $polityka = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));

        $godzinPostepu = (int) config('kuking.cooking_progress.retention_hours');
        $this->assertMatchesRegularExpression(
            '/^\| Zapamiętany postęp w trybie gotowania .*\*\*'.$godzinPostepu.' godzin/mu',
            $polityka,
            "Postęp gotowania żyje {$godzinPostepu} godzin, a polityka podaje inny termin.",
        );

        $godzinDopiskow = (int) config('kuking.cooking_note.retention_hours');
        $this->assertMatchesRegularExpression(
            '/^\| Prywatny dopisek z gotowania .*\*\*'.$godzinDopiskow.' godzin/mu',
            $polityka,
            "Dopisek z gotowania żyje {$godzinDopiskow} godzin, a polityka podaje inny termin.",
        );

        $godzinPaczki = (int) config('kuking.import_paczki.przechowanie_godzin');
        $this->assertGreaterThan(0, $godzinPaczki, 'Kontrola: brak terminu przechowania paczki w konfiguracji.');
        $this->assertMatchesRegularExpression(
            '/^\| Wczytanie paczki z danymi z Kuking .*najwyżej \*\*'.$godzinPaczki.' godzin/mu',
            $polityka,
            "Wybrana paczka czeka {$godzinPaczki} godzin, a polityka podaje inny termin.",
        );

        $godzinZmianyAdresu = (int) config('kuking.account.email_change_ttl_hours');
        $this->assertStringContainsString(
            "link potwierdzający jest ważny **{$godzinZmianyAdresu} godzin",
            $polityka,
        );
    }
}
