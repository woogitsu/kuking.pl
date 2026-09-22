<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TozsamoscZewnetrzna;
use App\Moderacja\KlientOpenAI;
use App\Poczta\TransportEmailLabs;
use App\Support\AnalitykaCloudflare;
use App\Support\Turnstile;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
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
 * ────────────────────────────────────────────────────────────────────────
 *  I STAŁO SIĘ PO RAZ DRUGI — TEN TEST BYŁ ZIELONY (11 września 2026)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wejście kontem Facebooka (issue #259, D-098) pojechało na produkcję —
 * przycisk „Wejdź kontem Facebooka" stał na `/register` obok Google'a —
 * a polityka prywatności nie wymieniała ani Facebooka, ani Mety ANI RAZU.
 * Zmierzone: `git grep -in "facebook\|meta platforms" -- resources/legal/`
 * oddawał zero trafień. RODO art. 13 ust. 1 lit. e wymaga podania kategorii
 * odbiorców danych, a ludzie byli już tą drogą przekierowywani do Mety.
 *
 * TEN TEST TEGO NIE ZŁAPAŁ, I POWÓD JEST JEDEN: lista usług była WPISANA
 * RĘCZNIE. Pilnowała dokładnie tych dostawców, których ktoś do niej dopisał,
 * więc kolejny dostawca tej samej funkcji — drugie logowanie zewnętrzne —
 * przeszedł obok niej bez jednego czerwonego przebiegu. Test wpisany ręcznie
 * chroni przed powtórzeniem pomyłki, którą już znamy, a nie przed następną.
 *
 * CO SIĘ W NIM PRZEZ TO ZMIENIŁO
 * Lista DOSTAWCÓW TOŻSAMOŚCI (logowanie kontem u kogoś) nie jest już
 * wpisana. Powstaje z dwóch miejsc naraz — z `config/kuking.php`
 * i ze stałych `TozsamoscZewnetrzna::DOSTAWCA_*`, czyli z tej samej listy,
 * którą pilnuje CHECK w bazie. Trzeci dostawca oblewa ten test **samym
 * swoim pojawieniem się w kodzie**, dopóki nie zostanie opisany w polityce,
 * i nie trzeba do tego niczyjej pamięci ani jednej linijki w tym pliku.
 *
 * CO TEN TEST ROBI
 * Dla każdej zewnętrznej usługi wykrywalnej w kodzie sprawdza, czy polityka
 * ją wymienia. Gdy ktoś doda kolejnego dostawcę, test oblewa DOPÓKI nie
 * dopisze go do dokumentu.
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
     * DOSTAWCÓW TOŻSAMOŚCI TU NIE MA i to jest celowe: ich lista powstaje
     * z konfiguracji i z kodu, w `dostawcyTozsamosci()` niżej. Tu zostają
     * usługi, których z niczego wyprowadzić się nie da — bo nie tworzą
     * rodziny, nie mają wspólnego kształtu w konfiguracji i każda wchodzi
     * do serwisu inną drogą. Dla nich jawna lista jest jedyną możliwością,
     * a jej kontrolę (że nie skurczyła się do zera) robi asercja na końcu
     * testu.
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

    /**
     * Zdanie, które w polityce musi paść przy DANYM dostawcy tożsamości.
     *
     * SAMA NAZWA DOSTAWCY BY TU NIE WYSTARCZYŁA i to jest ważniejsze niż sam
     * wpis. Polityka od dawna pisze „nie korzystamy z Google Analytics"
     * w akapicie o statystykach, więc asercja na słowo „Google"
     * przechodziłaby także wtedy, gdyby o logowaniu kontem Google dokument
     * milczał KOMPLETNIE — test wyglądałby na zielony, nie sprawdzając
     * niczego (`docs/PULAPKI_TESTOW.md` §1). Szukamy więc podmiotu, którym
     * dostawca świadczy tę usługę w Europie: te słowa mogą paść tylko
     * w akapicie o tej usłudze.
     *
     * Przy Facebooku jest to **Meta Platforms Ireland Limited** — podmiot
     * irlandzki, nie amerykański, i dlatego akapit o przekazywaniu poza EOG
     * mówi o tej drodze co innego niż o Cloudflare i OpenAI
     * (`docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md` §10.1 i §10.2).
     *
     * Brak wpisu dla dostawcy, którego zna kod, NIE JEST tu luką do
     * zignorowania — test oblewa i mówi, co dopisać. Patrz asercja
     * `assertArrayHasKey` niżej.
     *
     * @return array<string, string>
     */
    private function zdanieODostawcy(): array
    {
        return [
            'google' => 'Google Ireland Limited',
            'facebook' => 'Meta Platforms Ireland Limited',
        ];
    }

    /**
     * Dostawcy tożsamości WYPROWADZENI Z KONFIGURACJI — po kształcie wpisu,
     * nie po nazwie.
     *
     * Sekcja `config/kuking.php` z parą kluczy `identyfikator_klienta`
     * i `sekret_klienta` to u nas logowanie kontem u kogoś i nic innego
     * takiego kształtu nie ma. Nowy dostawca bez tej pary nie ruszy (bez
     * obu kluczy `dziala()` oddaje `false`), więc nie da się go wpiąć tak,
     * żeby ten pomiar go nie zobaczył.
     *
     * @return list<string>
     */
    private function dostawcyZKonfiguracji(): array
    {
        $dostawcy = [];

        foreach ((array) config('kuking') as $nazwa => $sekcja) {
            if (! is_array($sekcja)) {
                continue;
            }

            if (array_key_exists('identyfikator_klienta', $sekcja)
                && array_key_exists('sekret_klienta', $sekcja)) {
                $dostawcy[] = (string) $nazwa;
            }
        }

        sort($dostawcy);

        return $dostawcy;
    }

    /**
     * Dostawcy tożsamości WYPROWADZENI Z KODU — ze stałych
     * `TozsamoscZewnetrzna::DOSTAWCA_*`, czyli z tej samej listy, którą
     * trzyma CHECK w bazie.
     *
     * Dwa źródła, nie jedno, bo pilnują różnych pomyłek: konfiguracja mówi,
     * z kim kod UMIE rozmawiać, a stałe — czyje powiązanie wolno zapisać
     * w bazie. Dostawca dodany tylko po jednej stronie i tak wpada w pomiar,
     * bo do sprawdzenia idzie SUMA obu list.
     *
     * @return list<string>
     */
    private function dostawcyZKodu(): array
    {
        $dostawcy = [];

        foreach ((new ReflectionClass(TozsamoscZewnetrzna::class))->getConstants() as $nazwa => $wartosc) {
            if (str_starts_with($nazwa, 'DOSTAWCA_') && is_string($wartosc) && $wartosc !== '') {
                $dostawcy[] = $wartosc;
            }
        }

        sort($dostawcy);

        return $dostawcy;
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

        $sprawdzone = 0;

        foreach ($this->uslugi() as $nazwa => [$kodJejUzywa, $slowo]) {
            if (! $kodJejUzywa) {
                continue;
            }

            $sprawdzone++;

            $this->assertStringContainsString(
                $slowo,
                $polityka,
                'Kod używa usługi „'.$nazwa.'", a polityka prywatności o niej milczy. '
                .'Dopisz ją do tabeli podmiotów przetwarzających w `resources/legal/polityka-prywatnosci.md` — '
                .'razem z tym, gdzie trafiają dane i na jakiej podstawie, jeśli to podmiot spoza EOG. '
                .'Człowiek otwiera ten dokument właśnie po to, żeby się dowiedzieć, komu przekazujemy jego dane.',
            );
        }

        /*
         * KONTROLA METODY POMIARU (`docs/PULAPKI_TESTOW.md` §2).
         *
         * Każdy warunek wyżej to `class_exists()` albo klucz w konfiguracji.
         * Zmiana przestrzeni nazw, literówka w nazwie klasy albo zepsuty
         * autoload oddają `false` — a wtedy pętla nie wykonuje się ani razu
         * i test jest ZIELONY, nie sprawdzając niczego. Ta asercja jest
         * jedynym miejscem, w którym taka awaria pomiaru się odezwie.
         */
        $this->assertGreaterThanOrEqual(
            5,
            $sprawdzone,
            'Ten test nie zmierzył prawie żadnej usługi. To nie znaczy, że polityka jest w porządku — '
            .'to znaczy, że warunki w `uslugi()` przestały rozpoznawać kod (zmieniona nazwa klasy, '
            .'inna przestrzeń nazw, inny klucz w konfiguracji). Napraw warunki, nie tę liczbę.',
        );
    }

    /**
     * Dostawcy logowania zewnętrznego — lista NIE jest tu wpisana.
     *
     * To jest ten test, którego zabrakło 11 września 2026, gdy Facebook
     * pojechał na produkcję, a polityka o nim milczała (patrz komentarz
     * klasy). Pyta o dostawców, których zna KOD, a nie o tych, których zna
     * ten plik — więc następny dostawca obleje go sam.
     */
    #[Test]
    public function kazdy_dostawca_logowania_zewnetrznego_jest_opisany_w_polityce(): void
    {
        $polityka = $this->polityka();

        $zKonfiguracji = $this->dostawcyZKonfiguracji();
        $zKodu = $this->dostawcyZKodu();

        /*
         * KONTROLA METODY POMIARU, OSOBNO DLA KAŻDEGO ŹRÓDŁA
         * (`docs/PULAPKI_TESTOW.md` §2).
         *
         * Pusta lista dostawców dałaby test zielony i niczego
         * niesprawdzający, a suma dwóch list ukryłaby to, że jedna z nich
         * przestała cokolwiek oddawać (zmieniony kształt konfiguracji,
         * przeniesione stałe). Dlatego pytamy każde źródło osobno o dwóch
         * dostawców, których dziś MA znać.
         *
         * Ta jedna asercja jest jawną listą i tak ma zostać: jeśli któryś
         * z tych dostawców zostanie kiedyś świadomie usunięty z serwisu, to
         * jest właśnie to miejsce, w którym ktoś ma o tym przeczytać
         * i poprawić test ręcznie — razem z polityką.
         */
        foreach (['konfiguracji' => $zKonfiguracji, 'kodu' => $zKodu] as $zrodlo => $lista) {
            foreach (['google', 'facebook'] as $znany) {
                $this->assertContains(
                    $znany,
                    $lista,
                    "Pomiar dostawców z {$zrodlo} nie widzi dostawcy „{$znany}”, a serwis go ma. "
                    .'Dopóki to nie zostanie naprawione, ten test nie pilnuje niczego: dostawca '
                    .'niewidoczny dla pomiaru nie zostanie sprawdzony w polityce.',
                );
            }
        }

        $dostawcy = array_values(array_unique(array_merge($zKonfiguracji, $zKodu)));
        sort($dostawcy);

        $zdania = $this->zdanieODostawcy();

        foreach ($dostawcy as $dostawca) {
            /*
             * SIEĆ, KTÓRA DZIAŁA BEZ NICZYJEJ POMOCY: sama nazwa dostawcy,
             * wzięta z kodu. Łapie nawet dostawcę, o którym ten plik nic nie
             * wie — bo nazwa z konfiguracji („apple", „microsoft") pada
             * w polityce w każdej odmianie („kontem Apple", „Facebooka").
             */
            $this->assertStringContainsString(
                $dostawca,
                mb_strtolower($polityka),
                'Kod umie logować ludzi kontem „'.$dostawca.'", a polityka prywatności nie wymienia tej nazwy '
                .'ani razu. RODO art. 13 ust. 1 lit. e wymaga podania kategorii odbiorców danych, a człowiek '
                .'jest tą drogą przekierowywany do cudzego serwisu. Opisz to w '
                .'`resources/legal/polityka-prywatnosci.md` — w tabeli dostawców, w akapicie o tym, co od niego '
                .'dostajemy, ORAZ w akapicie o przekazywaniu poza EOG.',
            );

            /*
             * ASERCJA MOCNA: nazwa podmiotu, którym dostawca świadczy tę
             * usługę. Brak wpisu w `zdanieODostawcy()` oblewa test, i to jest
             * jedyne miejsce w tym pliku, które trzeba tknąć przy trzecim
             * dostawcy — po to, żeby ktoś musiał ustalić, KTO jest po tamtej
             * stronie, zamiast wpisać nazwę z pamięci.
             */
            $this->assertArrayHasKey(
                $dostawca,
                $zdania,
                'W kodzie jest dostawca logowania „'.$dostawca.'", o którym ten test nie wie. Ustal, jaki podmiot '
                .'świadczy tę usługę dla ludzi z Europy (to jest informacja z warunków dostawcy, nie z pamięci), '
                .'opisz go w polityce prywatności i dopisz tutaj, w `zdanieODostawcy()`, słowa, których w niej '
                .'szukać.',
            );

            $this->assertStringContainsString(
                $zdania[$dostawca],
                $polityka,
                'Polityka prywatności nie mówi, kto stoi po drugiej stronie logowania kontem „'.$dostawca.'": '
                .'brakuje w niej słów „'.$zdania[$dostawca].'". Sama nazwa dostawcy nie wystarczy — pada ona '
                .'w dokumencie także w zdaniach o czym innym (np. „nie korzystamy z Google Analytics").',
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
