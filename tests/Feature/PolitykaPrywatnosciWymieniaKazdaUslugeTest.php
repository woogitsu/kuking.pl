<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Google\KlientGoogle;
use App\Moderacja\KlientOpenAI;
use App\Poczta\TransportEmailLabs;
use App\Support\AnalitykaCloudflare;
use App\Support\Turnstile;
use PHPUnit\Framework\Attributes\Test;
use Sentry\Laravel\ServiceProvider;
use Tests\TestCase;

/**
 * Polityka prywatności ma nadążać za kodem, a nie za naszymi zamiarami.
 *
 * CO SIĘ STAŁO
 * Przez cały 9 września polityka mówiła użytkownikom:
 *
 *     „Dostawcy poczty e-mail jeszcze nie wybraliśmy, więc serwis dziś
 *      nie wysyła wiadomości. Gdy go wybierzemy, dopiszemy go do tabeli
 *      wyżej, ZANIM PIERWSZA WIADOMOŚĆ WYJDZIE."
 *
 * Tego samego dnia rano poczta ruszyła przez EmailLabs (D-047), a wieczorem
 * doszedł Cloudflare Turnstile (D-050) — usługa amerykańskiej spółki, która
 * przy każdym logowaniu i każdej rejestracji widzi adres IP użytkownika.
 * Ani jedno, ani drugie nie trafiło do tabeli. Obietnica „dopiszemy, zanim
 * pierwsza wiadomość wyjdzie" została złamana w ciągu jednego dnia — nie ze
 * złej woli, tylko dlatego, że dokument i kod żyły osobno.
 *
 * To nie jest drobiazg redakcyjny. To jest nieprawdziwa informacja o tym,
 * komu przekazujemy cudze dane osobowe — czyli dokładnie ta rzecz, po którą
 * człowiek otwiera politykę prywatności.
 *
 * CO TEN TEST ROBI
 * Dla każdej zewnętrznej usługi wykrywalnej w kodzie sprawdza, czy polityka
 * ją wymienia. Gdy ktoś doda kolejnego dostawcę (na przykład model AI do
 * moderacji), test oblewa DOPÓKI nie dopisze go do dokumentu.
 *
 * CZEGO NIE DOWODZI
 * Że opis w polityce jest prawdziwy i wystarczający — tego z PHP sprawdzić
 * się nie da. Dowodzi jednego: że dostawca, którego kod używa, nie jest
 * w dokumencie przemilczany.
 */
class PolitykaPrywatnosciWymieniaKazdaUslugeTest extends TestCase
{
    /**
     * Usługa → [warunek, że kod jej używa, słowo, które musi paść w dokumencie].
     *
     * @return array<string, array{bool, string}>
     */
    private function uslugi(): array
    {
        return [
            'EmailLabs (poczta wychodząca, D-047)' => [
                class_exists(TransportEmailLabs::class),
                'EmailLabs',
            ],
            'Cloudflare Turnstile (captcha, D-050)' => [
                class_exists(Turnstile::class),
                'Turnstile',
            ],
            'Cloudflare R2 (zdjęcia)' => [
                array_key_exists('r2', (array) config('filesystems.disks')),
                'R2',
            ],
            'Sentry (zbieranie błędów)' => [
                class_exists(ServiceProvider::class)
                    && (string) config('sentry.dsn') !== '',
                'Sentry',
            ],
            'OpenAI (moderacja treści)' => [
                class_exists(KlientOpenAI::class),
                'OpenAI',
            ],
            /*
             * Google — wejście kontem Google (D-069). Warunkiem jest ISTNIENIE
             * KLASY, nie obecność kluczy w środowisku, i to jest celowe: bez
             * kluczy funkcja nie działa u nas lokalnie i w CI, ale KOD, który
             * wysyła dane do Google, jest już w repozytorium i pójdzie na
             * produkcję. Gdyby warunkiem były klucze, ten test przechodziłby
             * wszędzie poza produkcją — czyli wszędzie tam, gdzie ktokolwiek
             * go uruchamia.
             */
            'Google (logowanie kontem Google, D-069)' => [
                class_exists(KlientGoogle::class),
                /*
                 * SŁOWO „Google" BY TU NIE WYSTARCZYŁO i to jest ważniejsze
                 * niż sam wpis. Polityka od dawna pisze „ani Google
                 * Analytics, ani żadnego innego" w akapicie o statystykach —
                 * więc asercja na słowo „Google" przechodziłaby także wtedy,
                 * gdyby o logowaniu kontem Google dokument milczał
                 * KOMPLETNIE. Test wyglądałby na zielony, nie sprawdzając
                 * niczego. Szukamy więc podmiotu, którym Google świadczy tę
                 * usługę w Europie — te słowa mogą paść tylko w akapicie
                 * o tej usłudze.
                 *
                 * To jest ta sama pułapka, którą ten plik opisuje w swoim
                 * komentarzu klasy: dokument i kod żyją osobno, a test, który
                 * łapie słowo z innego miejsca dokumentu, jest jej wersją na
                 * poziomie asercji.
                 */
                'Google Ireland Limited',
            ],
            /*
             * Cloudflare Web Analytics (analityka odwiedzin, D-092).
             *
             * WARUNKIEM JEST ISTNIENIE KLASY, A NIE
             * `AnalitykaCloudflare::wlaczona()` — i to jest tu rzecz
             * najważniejsza. W testach i w CI `CLOUDFLARE_ANALYTICS_TOKEN`
             * jest pusty, więc `wlaczona()` oddaje `false`; gdyby to ono
             * rozstrzygało, cała ta pozycja byłaby POMIJANA dokładnie tam,
             * gdzie ma pilnować, i test byłby ozdobą. Pytanie brzmi „czy kod
             * potrafi wysłać dane temu dostawcy", a nie „czy akurat na tej
             * maszynie wysyła" — tak samo jak przy EmailLabs i Turnstile
             * wyżej.
             *
             * Szukamy nazwy „Cloudflare Web Analytics", a nie samego
             * „Cloudflare": ta druga stoi w polityce od Turnstile'a i od R2,
             * więc przechodziłaby także wtedy, gdyby o analityce nie było
             * w dokumencie ani słowa (`docs/PULAPKI_TESTOW.md` §1 — to samo
             * słowo skądinąd).
             */
            'Cloudflare Web Analytics (analityka odwiedzin, D-092)' => [
                class_exists(AnalitykaCloudflare::class),
                'Cloudflare Web Analytics',
            ],
        ];
    }

    private function polityka(): string
    {
        $sciezka = resource_path('legal/polityka-prywatnosci.md');

        $this->assertFileExists($sciezka, 'Nie ma pliku polityki prywatności — popraw ścieżkę w teście.');

        return (string) file_get_contents($sciezka);
    }

    #[Test]
    public function kazda_usluga_uzywana_przez_kod_jest_wymieniona_w_polityce(): void
    {
        $polityka = $this->polityka();

        // Kontrola metody pomiaru: gdybym czytał pusty albo zły plik, reszta
        // testu przechodziłaby „bo nic nie znalazłem".
        $this->assertStringContainsString(
            'Komu przekazujemy dane',
            $polityka,
            'W czytanym pliku nie ma sekcji o podmiotach przetwarzających. Czytam zły dokument.',
        );

        foreach ($this->uslugi() as $nazwa => [$kodJejUzywa, $slowo]) {
            if (! $kodJejUzywa) {
                continue;
            }

            $this->assertStringContainsString(
                $slowo,
                $polityka,
                'Kod używa usługi „'.$nazwa.'", a polityka prywatności o niej milczy. '
                .'Dopisz ją do tabeli podmiotów przetwarzających w `resources/legal/polityka-prywatnosci.md` — '
                .'razem z tym, gdzie trafiają dane i na jakiej podstawie, jeśli to podmiot spoza EOG. '
                .'Człowiek otwiera ten dokument właśnie po to, żeby się dowiedzieć, komu przekazujemy jego dane.',
            );
        }
    }

    /**
     * Zdanie „nie wysyłamy jeszcze poczty" było prawdziwe do 9 września rano.
     * Po D-047 jest fałszywe i nie może wrócić.
     */
    #[Test]
    public function polityka_nie_twierdzi_ze_serwis_nie_wysyla_poczty(): void
    {
        $polityka = $this->polityka();

        foreach (['nie wysyła wiadomości', 'jeszcze nie wybraliśmy'] as $nieprawda) {
            $this->assertStringNotContainsString(
                $nieprawda,
                $polityka,
                'Polityka znowu twierdzi, że serwis nie wysyła poczty albo nie ma dostawcy. '
                .'Poczta chodzi przez EmailLabs od 9 września (D-047) — to zdanie byłoby nieprawdą '
                .'na temat przetwarzania danych osobowych.',
            );
        }
    }
}
