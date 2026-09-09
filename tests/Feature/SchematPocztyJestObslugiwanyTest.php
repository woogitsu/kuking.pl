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
