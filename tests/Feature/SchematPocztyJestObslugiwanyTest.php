<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Throwable;

/**
 * Wartość `MAIL_SCHEME` z `.railway/railway.ts` musi być schematem, który
 * Symfony naprawdę obsługuje.
 *
 * CO SIĘ STAŁO 9 WRZEŚNIA 2026
 * `.railway/railway.ts` wpisywał `MAIL_SCHEME: "tls"` z komentarzem
 * „`tls` = STARTTLS na porcie 587". Komentarz opisywał prawdziwą intencję
 * i nieprawdziwą składnię: Symfony przyjmuje wyłącznie `smtp` i `smtps`,
 * a przy `tls` rzuca `UnsupportedSchemeException`.
 *
 * Skutek na produkcji: `MAIL_MAILER` był poprawny, dostawca poprawny, hasło
 * poprawne, worker chodził — a każde zadanie kończyło się
 *
 *     App\Notifications\UstawienieNowegoHasla ... FAIL
 *     The "tls" scheme is not supported
 *
 * czyli człowiek prosił o nowe hasło, strona mówiła „wysłaliśmy", i nic nie
 * przychodziło. Dokładnie ten rodzaj cichej awarii, którą `App\Support\Poczta`
 * miał tępić — tylko o warstwę niżej, bo `Poczta::dziala()` pyta o sterownik,
 * a nie o to, czy transport w ogóle da się zbudować.
 *
 * DLACZEGO TEN TEST CZYTA PLIK, A NIE KONFIGURACJĘ
 * W testach `MAIL_SCHEME` nie jest ustawione i sterownik jest `array` — więc
 * test czytający `config()` przechodziłby zawsze, także z „tls" w
 * `railway.ts`. Prawda o produkcji leży w tamtym pliku i to jego trzeba pytać.
 *
 * DLACZEGO PRÓBUJEMY ZBUDOWAĆ TRANSPORT, A NIE PORÓWNUJEMY DO LISTY
 * Lista dozwolonych schematów należy do Symfony i może się zmienić przy
 * aktualizacji. Druga kopia tej listy w naszym teście rozjechałaby się po
 * cichu. Pytamy więc tę samą klasę, która odpowiada na produkcji.
 */
class SchematPocztyJestObslugiwanyTest extends TestCase
{
    public function test_schemat_z_railway_ts_da_sie_zbudowac(): void
    {
        $schemat = $this->schematZRailwayTs();

        config()->set('mail.mailers.smtp.scheme', $schemat);
        config()->set('mail.mailers.smtp.host', 'localhost');
        config()->set('mail.mailers.smtp.port', 587);
        Mail::purge('smtp');

        try {
            Mail::mailer('smtp')->getSymfonyTransport();
        } catch (Throwable $e) {
            $this->fail(
                "`.railway/railway.ts` ustawia `MAIL_SCHEME: \"{$schemat}\"`, a Symfony tego nie przyjmuje:\n"
                .$e->getMessage()."\n\n"
                .'Na porcie 587 poprawną wartością jest `smtp` — STARTTLS negocjuje się samo. '
                .'`smtps` jest dla portu 465. Wartość `tls` wygląda sensownie i nie działa: '
                .'transport nie powstaje, a każdy list kończy się FAIL w kolejce, '
                .'przy poprawnym dostawcy i poprawnym haśle.',
            );
        }

        $this->assertTrue(true);
    }

    /**
     * Kontrola metody pomiaru: gdyby ten test nie umiał odróżnić wartości
     * złej od dobrej, punkt wyżej przechodziłby zawsze.
     */
    public function test_kontrola_zla_wartosc_naprawde_wywraca_transport(): void
    {
        config()->set('mail.mailers.smtp.scheme', 'tls');
        config()->set('mail.mailers.smtp.host', 'localhost');
        config()->set('mail.mailers.smtp.port', 587);
        Mail::purge('smtp');

        $wyjatek = null;

        try {
            Mail::mailer('smtp')->getSymfonyTransport();
        } catch (Throwable $e) {
            $wyjatek = $e;
        }

        $this->assertNotNull(
            $wyjatek,
            'Symfony przestało odrzucać schemat „tls". Jeśli to prawda, ten plik '
            .'jest już niepotrzebny — usuń go razem z asercją wyżej, zamiast go obchodzić.',
        );
        $this->assertStringContainsString('tls', $wyjatek->getMessage());
    }

    /**
     * NARZĘDZIE DIAGNOSTYCZNE NIE MOŻE DORADZAĆ WARTOŚCI, KTÓRA WYWRACA POCZTĘ.
     *
     * PR #198 poprawił `MAIL_SCHEME` w `.railway/railway.ts` z „tls" na „smtp"
     * i ten plik zaczął tego pilnować. Nikt natomiast nie tknął
     * `kuking:sprawdz-poczte`, która po awarii wypisywała człowiekowi trzy
     * rady, i wszystkie trzy mówiły: ustaw `MAIL_SCHEME=tls`.
     *
     * Czyli: strażnik pilnował pliku, a komenda uruchamiana DOKŁADNIE w chwili
     * awarii namawiała do jej powtórzenia. Żaden test nie sprawdzał treści
     * tych stringów, więc narzędzia były zielone przez cały dzień.
     *
     * Test czyta źródło komendy, bo te rady są w niej literałami — nie ma
     * innego miejsca, w którym dałoby się je złapać.
     */
    public function test_komenda_diagnostyczna_nie_doradza_schematu_tls(): void
    {
        $sciezka = base_path('app/Console/Commands/SprawdzPoczte.php');

        $this->assertFileExists($sciezka, 'Nie ma komendy `kuking:sprawdz-poczte`. Jeśli ją przeniesiono, popraw ścieżkę tutaj.');

        $zrodlo = (string) file_get_contents($sciezka);

        // Kontrola metody pomiaru: gdyby komenda przestała w ogóle wspominać
        // o `MAIL_SCHEME`, asercja niżej przechodziłaby, nie sprawdzając nic.
        $this->assertStringContainsString(
            'MAIL_SCHEME',
            $zrodlo,
            'Komenda nie wspomina już o MAIL_SCHEME — czytam zły plik albo diagnostyka SMTP zniknęła.',
        );

        // Łapiemy postacie, w których rada NAKAZUJE `tls`: „MAIL_SCHEME=tls",
        // „MAIL_SCHEME powinien być `tls`", „587 → `tls`". Zdania mówiące, że
        // `tls` NIE działa, mają prawo tam stać i stoją.
        $zlerady = [
            '/MAIL_SCHEME\s*=\s*`?tls/i',
            '/MAIL_SCHEME[^.]{0,40}powinien być\s*`?tls/iu',
            '/587\s*(?:→|->)\s*`?tls/u',
        ];

        foreach ($zlerady as $wzorzec) {
            $this->assertDoesNotMatchRegularExpression(
                $wzorzec,
                $zrodlo,
                'Komenda `kuking:sprawdz-poczte` znowu doradza `MAIL_SCHEME=tls`. Symfony tej wartości nie zna '
                .'(patrz asercje wyżej w tym pliku), więc rada prowadzi wprost do awarii z 9 września. '
                .'Na porcie 587 poprawną wartością jest `smtp`, na 465 — `smtps`.',
            );
        }
    }

    private function schematZRailwayTs(): string
    {
        $plik = (string) file_get_contents(base_path('.railway/railway.ts'));

        $this->assertSame(
            1,
            preg_match('/MAIL_SCHEME:\s*"([^"]+)"/', $plik, $dopasowanie),
            'Nie znalazłem `MAIL_SCHEME` w `.railway/railway.ts`. Jeśli zmienna '
            .'zniknęła stamtąd celowo, popraw ten test razem z nią.',
        );

        return $dopasowanie[1];
    }
}
