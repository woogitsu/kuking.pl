<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kopia bazy i próba odtworzenia — issue #9 (P0) i #193.
 *
 * DLACZEGO TEN PLIK ISTNIEJE, CHOĆ TESTY SĄ W POWŁOCE
 * ---------------------------------------------------
 * Bo kopia bazy i jej odtworzenie są w tym projekcie skryptami powłoki
 * (decyzja D-043: `docker/php.ini` wyłącza `proc_open`, więc `pg_dump`
 * z PHP nie wystartuje), a `php artisan test` skryptów powłoki nie widzi.
 * Testy kopii z `tests/skrypty/kopia-bazy.sh` nie chodziły w CI **w ogóle**,
 * dopóki ktoś tego nie zauważył — zielone CI nie mówiło wtedy o warstwie
 * kopii nic, mimo że cała ona jest w tym repozytorium.
 *
 * Ten plik jest zabezpieczeniem przed powtórzeniem tamtej pomyłki: wciąga
 * `tests/skrypty/proba-odtworzenia.sh` do zwykłego przebiegu, więc
 * mechanizmu kopii i odtworzenia **nie da się przeoczyć przez zielone
 * `php artisan test`**. Świadomie NIE zmieniam do tego ani
 * `scripts/check.sh`, ani `.github/workflows/*` — te pliki trzymają
 * równolegle inni agenci.
 *
 * CO SIĘ TU NAPRAWDĘ DZIEJE
 * Skrypt niżej robi prawdziwe zrzuty prawdziwym `pg_dump`, odtwarza je
 * prawdziwym `pg_restore` do własnej, świeżej bazy `proba_odtworzenia_test*`
 * i sprawdza, co w niej stanęło. Dlatego ten jeden test trwa około minuty —
 * i to jest uczciwa cena za jedyny w tym repozytorium dowód, że z kopii
 * da się mieć z powrotem bazę, a nie tylko plik.
 *
 * CZEGO TEN TEST NIE DOWODZI — i to jest ważniejsze niż to, co dowodzi:
 * że kuking.pl MA kopię. Nie ma. Liczba kopii produkcyjnej bazy wynosi
 * dziś zero i zmienią to wyłącznie czynności właściciela z
 * `docs/infra/KOPIE_I_ODTWORZENIE.md` §8. Zielony wynik znaczy „mechanizm
 * działa”, nie „dane są bezpieczne”.
 *
 * @see tests/skrypty/proba-odtworzenia.sh
 * @see scripts/proba-odtworzenia.sh
 * @see scripts/kopia-lokalna.sh
 */
class ProbaOdtworzeniaTest extends TestCase
{
    #[Test]
    public function test_skrypty_kopii_i_proby_odtworzenia_przechodza_wlasne_testy(): void
    {
        $skrypt = base_path('tests/skrypty/proba-odtworzenia.sh');

        // Bez tej asercji test byłby zielony także wtedy, gdyby plik testów
        // zniknął albo zmienił ścieżkę — `shell_exec` zwróciłby wtedy komunikat
        // powłoki, a nie wynik testów. To pułapka 2 z docs/PULAPKI_TESTOW.md:
        // skan, który nie znajduje żadnego pliku, uznaje to za sukces.
        $this->assertFileExists(
            $skrypt,
            'Nie ma tests/skrypty/proba-odtworzenia.sh — bez niego ten test nie '.
            'sprawdza niczego, a warstwa kopii wraca do stanu, w którym zielone '.
            'CI nie mówi o niej nic.',
        );

        $wyjscie = (string) shell_exec(
            'bash '.escapeshellarg($skrypt).' 2>&1; printf "KOD=%s" "$?"',
        );

        $this->assertStringContainsString(
            'KOD=0',
            $wyjscie,
            'Testy kopii i próby odtworzenia OBLEWAJĄ SIĘ. Uruchom je wprost, żeby '
            ."zobaczyć który przypadek:\n  bash tests/skrypty/proba-odtworzenia.sh\n\n"
            .$wyjscie,
        );

        // KONTROLA DODATNIA DO ASERCJI WYŻEJ.
        //
        // `KOD=0` dostalibyśmy także wtedy, gdyby skrypt wyszedł zerem
        // niczego nie sprawdzając — na przykład po wycięciu z niego
        // wszystkich przypadków. Dlatego pytamy też o LICZBĘ zdanych
        // i o to, czy naprawdę odbyły się kontrole ujemne: nazwy trzech
        // z nich muszą stać w wyjściu.
        $this->assertMatchesRegularExpression(
            '/Wszystkie (\d{2,}) testów kopii i próby odtworzenia przechodzi/u',
            $wyjscie,
            'Skrypt wyszedł zerem, ale nie zameldował dwucyfrowej liczby zdanych '.
            'przypadków — czyli albo nic nie sprawdził, albo zmienił się jego '.
            "komunikat końcowy.\n\n".$wyjscie,
        );

        foreach ([
            'oblewa się na WYZWALACZU-ATRAPIE',
            'łapie przekierowanie połączenia przez ?dbname=',
            'oblewa się na zrzucie zerobajtowym',
            'odmawia odtwarzania do bazy, która NIE jest pusta',
        ] as $kontrola) {
            $this->assertStringContainsString(
                $kontrola,
                $wyjscie,
                "W przebiegu nie ma kontroli ujemnej „{$kontrola}”. Test bez niej "
                .'dowodziłby tylko, że dobry zrzut przechodzi — a dobry zrzut '
                ."przechodzi każdy brak kontroli.\n\n".$wyjscie,
            );
        }
    }
}
