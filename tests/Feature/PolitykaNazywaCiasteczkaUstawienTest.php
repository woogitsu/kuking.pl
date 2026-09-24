<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * R6 — polityka prywatności musi nazwać KAŻDE ciasteczko ustawień.
 *
 * ZAKRES TEGO PLIKU JEST CELOWO WĄSKI.
 * Gałąź `audyt/8-r1-r6-decyzje` niesie sześć sprawdzeń (R1, R2, R3, R5, R6)
 * w jednym pliku `RozjazdyAudytuZgodnosciTest`. Właściciel wziął z niej
 * WYŁĄCZNIE ujawnienie dwóch ciasteczek — reszta tamtej gałęzi czeka na
 * osobną decyzję i NIE jest tu przepisana. W szczególności NIE ma tu
 * sprawdzenia R1 („pełna kopia"): to samo zdanie polityki rozstrzygnięto
 * na brzmienie z gałęzi `odzysk/testy-regresyjne`, razem z terminem
 * z art. 12 ust. 3 RODO, i pilnuje go `PolitykaNieObiecujePelnejKopiiTest`.
 *
 * DLACZEGO TO JEST STRAŻNIK, A NIE JEDNORAZOWA POPRAWKA TEKSTU.
 * Polityka mówiła o ciasteczkach „np. do utrzymania sesji logowania"
 * i nie nazywała dwóch, które serwis stawia na rok: wielkości tekstu
 * i motywu. „np." jest tu słowem, które ukrywa resztę listy.
 *
 * Test czyta NAZWY Z KONFIGURACJI i wymaga, żeby każda padła w polityce.
 * Trzecie ciasteczko ustawień dopisane kiedyś do `config/kuking.php` zapali
 * czerwone światło w dniu dodania — a to jest dokładnie ten moment, w którym
 * ktoś musi zdecydować, jak je nazwać człowiekowi.
 */
final class PolitykaNazywaCiasteczkaUstawienTest extends TestCase
{
    public function test_polityka_nazywa_kazde_ciasteczko_ustawien(): void
    {
        $ciasteczka = array_filter([
            (string) config('kuking.text.cookie'),
            (string) config('kuking.theme.cookie'),
        ]);

        // KONTROLA METODY: bez dwóch nazw z konfiguracji pętla niżej
        // sprawdzałaby pustkę i byłaby zielona, nic nie mierząc
        // (`docs/PULAPKI_TESTOW.md` §2).
        $this->assertCount(
            2,
            $ciasteczka,
            'Konfiguracja nie oddaje już dwóch nazw ciasteczek ustawień — ten test przestał mierzyć.',
        );

        $polityka = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));

        foreach ($ciasteczka as $nazwa) {
            /*
             * NAZWA MUSI STAĆ W BACKTICKACH, a nie gdziekolwiek w tekście.
             * Pierwsza wersja tego sprawdzenia szukała samego słowa i była
             * zielona z niewłaściwego powodu: „motyw" pada w polityce także
             * jako zwykły wyraz („jasnego albo ciemnego motywu"), więc
             * usunięcie NAZWY ciasteczka niczego nie zapalało. Wyszło to
             * dopiero przy kontroli ujemnej.
             */
            $this->assertStringContainsString(
                '`'.$nazwa.'`',
                $polityka,
                "Serwis stawia ciasteczko „{$nazwa}” (ważne rok), a polityka nie podaje jego NAZWY. "
                .'Człowiek ma prawo wiedzieć, co dokładnie zostaje na jego urządzeniu i jak długo.',
            );
        }
    }
}
