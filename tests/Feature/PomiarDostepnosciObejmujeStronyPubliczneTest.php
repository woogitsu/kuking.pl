<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Każda publiczna strona serwisu jest MIERZONA przez `scripts/dostepnosc.mjs`
 * albo stoi na wypisanej niżej liście świadomych wyjątków — trzeciej
 * możliwości nie ma.
 *
 * PO CO TO JEST. `/tagi` powstało 10 września (#273, D-087) i przez dwie doby
 * było jedyną stroną publiczną serwisu, której ten automat nie oglądał.
 * Nie z decyzji — z tego, że plik automatu trzymały wtedy trzy gałęzie naraz
 * i nikt nie chciał go ruszać. Nikt tego nie zauważył, bo BRAK ekranu na
 * liście niczego nie psuje: raport wygląda na kompletny i świeci na zielono,
 * a jedna strona po prostu nie jest badana. To jest ta sama klasa fałszywej
 * zieleni, przed którą ostrzega nagłówek tamtego pliku („pusty ekran
 * przechodzi każdy test dostępności, nie sprawdzając niczego") — tylko
 * o poziom wyżej: nie pusty ekran, lecz ekran nieobecny.
 *
 * DLACZEGO LISTA WYJĄTKÓW, A NIE „WSZYSTKO MUSI BYĆ MIERZONE". Bo część tras
 * publicznych to nie są strony do oglądania (`robots.txt`, `sitemap.xml`,
 * `/up`, skrypty Livewire), a część to ekrany potwierdzenia, których bez
 * wysłanego formularza nie ma czego pokazać — a pusty ekran przechodzi każdy
 * audyt, nie sprawdzając niczego. Wyjątek wolno mieć; wyjątek MILCZĄCY —
 * nie. Dopisanie trasy tutaj jest jedną linijką i wymaga napisania powodu,
 * czyli dokładnie tyle wysiłku, ile trzeba, żeby to była decyzja, a nie
 * przeoczenie.
 *
 * @see PomiarDostepnosciKonczyKodemJedenTest — drugi strażnik
 *      tego samego pliku, pilnujący warunku wyjścia (sekcja 14.5 przekazania)
 */
class PomiarDostepnosciObejmujeStronyPubliczneTest extends TestCase
{
    /**
     * Trasy publiczne ŚWIADOMIE nieoglądane przez automat, każda z powodem.
     *
     * @var array<string, string>
     */
    private const WYJATKI = [
        'health' => 'punkt kontrolny dla monitoringu — JSON, nie strona',
        'up' => 'punkt kontrolny frameworka — nie ma interfejsu',
        'robots.txt' => 'plik tekstowy dla robotów',
        'sitemap.xml' => 'plik XML dla robotów',
        'napisz-do-nas/dziekujemy' => 'ekran potwierdzenia — bez wysłanego formularza nie ma czego pokazać, a pusty ekran przechodzi każdy audyt',
        'zglos-nielegalna-tresc/przyjete' => 'ekran potwierdzenia — jak wyżej',
        /*
         * SIEDEM POZYCJI, KTÓRE TU STAŁY, JEST OD 11 WRZEŚNIA W `EKRANY`.
         *
         * `o-kuking`, `pomoc`, `odwolanie` (gość), `nie-pamietam-hasla`,
         * `logowanie/link`, `logowanie/kod` i `cofnij-usuniecie-konta` były
         * wpisane tutaj jako DŁUG NAZWANY: prawdziwe strony publiczne, których
         * automat nie oglądał ani razu. Wypisał je sam ten skan, przy pierwszym
         * uruchomieniu — i to było jego pierwsze znalezisko. Spłatę widać
         * w `scripts/dostepnosc.mjs`: każda z siódemki ma tam własny wpis
         * z powodem, a `logowanie/kod` dodatkowo czwarty stan przeglądarki
         * (sesja po haśle, przed kodem — `stanPrzedKodem2FA()`), bo w żadnym
         * z trzech istniejących ten ekran nie istnieje. Dwa ekrany odzyskania
         * dostępu wymagały przy okazji rozstrzygnięcia, w KTÓRYM ze swoich
         * dwóch stanów mają być mierzone — patrz **D-106**.
         *
         * Po tych siedmiu zostaje ten akapit, a nie czysta luka. Powód jest
         * ten sam, dla którego w ogóle powstał ten test: wypadnięcie strony
         * z `EKRANY` jest jedyną zmianą, która NIE zostawia śladu w raporcie
         * automatu — ten świeci wtedy zielono nad niepełną listą. Ślad ma więc
         * zostać w pliku, żeby następne czytanie zaczynało się od pytania
         * „czy te siedem nadal tam jest", a nie od pustego miejsca.
         */

        /*
         * WEJŚCIE KONTEM GOOGLE (D-069) — dwie różne przyczyny, nie jedna.
         *
         * `wejdz/google` i `wejdz/google/wroc` nie mają czego pokazać:
         * `start()` i `callback()` zwracają `RedirectResponse`, nigdy widoku.
         * Nie jest to dług — tam po prostu NIE MA strony do zmierzenia.
         *
         * `domknij` i `polacz` to prawdziwe ekrany i powinny być mierzone.
         * Automat ich nie otworzy, bo oba czytają tożsamość z Google z sesji
         * (`GoogleLoginController::tozsamoscZSesji()`, klucz zakładany
         * WYŁĄCZNIE przez `callback()` po udanej wymianie kodu u Google);
         * bez niej oba odsyłają na `/login`.
         *
         * SPRAWDZONE 11 WRZEŚNIA, przy spłacie długu siedmiu stron wyżej —
         * i dlatego ta pozycja zostaje, a tamte nie. Droga na skróty
         * musiałaby być jedną z dwóch, i obie są zamknięte:
         *
         *  1. Podstawienie klucza sesji z zewnątrz. Wymaga trasy, komendy albo
         *     middleware'u, który pozwala zapisać do sesji „tożsamość
         *     potwierdzoną przez Google" bez przejścia przez Google. Wiersz
         *     w `tozsamosci_zewnetrzne` JEST drogą wejścia na konto (D-098),
         *     więc taki właz jest przejęciem konta czekającym na pomyłkę
         *     w konfiguracji — i zostałby w repozytorium na zawsze, pokazując
         *     następnej osobie, że tak wolno. To ta sama granica, której nie
         *     przekracza `stanModeratora()` przy 2FA.
         *  2. Podstawienie odpowiedzi Google. Wymiana kodu na token idzie
         *     Z SERWERA (`KlientGoogle`), a nie z przeglądarki, więc
         *     Playwright nie ma czego przechwycić — jego `route()` widzi
         *     wyłącznie ruch karty.
         *
         * Zostaje to więc długiem, ale długiem o innym powodzie niż tamte
         * siedem: nie „nikt się nie zabrał", tylko „zmierzenie tego wymaga
         * najpierw atrapy dostawcy tożsamości". To jest osobna praca i osobna
         * decyzja, a nie linijka w automacie.
         */
        'wejdz/google' => 'przekierowanie do Google — `start()` zwraca RedirectResponse, nie ma strony',
        'wejdz/google/wroc' => 'powrót z Google — `callback()` zwraca RedirectResponse, nie ma strony',
        'wejdz/google/domknij' => 'DŁUG: prawdziwy ekran, ale wymaga tożsamości Google w sesji — automat jej nie założy',
        'wejdz/google/polacz' => 'DŁUG: prawdziwy ekran, ale wymaga tożsamości Google w sesji — automat jej nie założy',

        /*
         * WEJŚCIE KONTEM FACEBOOKA (#259, D-098) — ten sam podział i te same
         * dwa powody, co przy Google wyżej. Nie powtarzam wywodu; powtarzam
         * jedno zdanie, które się przez to ZMIENIŁO:
         *
         * dług przestał być długiem jednego dostawcy. Ekranów, których
         * automat nie ogląda z powodu „tożsamość siedzi w sesji", są teraz
         * CZTERY, nie dwa — i będzie ich sześć przy trzecim dostawcie.
         * Atrapa dostawcy tożsamości była przy Google osobną pracą wartą
         * odłożenia; przy dwóch dostawcach zaczyna być tańsza niż to, co
         * przez jej brak pozostaje niezmierzone. To jest jedyna rzecz,
         * którą ta pozycja dokłada do rozstrzygnięcia z 11 września —
         * i celowo NIE rozstrzygam jej tutaj, w tablicy wyjątków.
         *
         * Czego ta pozycja NIE znaczy: że te ekrany nie były oglądane wcale.
         * `facebook-finish` i `facebook-link` mają testy funkcjonalne
         * (`LogowanieKontemFacebookiemTest`), a reguły 50+ — rozmiar tekstu,
         * rozmiar przycisku, ikona nigdy sama — pilnuje osobny zestaw
         * testów tekstów. Niezmierzone jest tu axe-core na żywym HTML-u,
         * i tylko to.
         */
        'wejdz/facebook' => 'przekierowanie do Facebooka — `start()` zwraca RedirectResponse, nie ma strony',
        'wejdz/facebook/wroc' => 'powrót z Facebooka — `callback()` zwraca RedirectResponse albo ekran bez własnego adresu, nie ma czego otworzyć',
        'wejdz/facebook/domknij' => 'DŁUG: prawdziwy ekran, ale wymaga tożsamości Facebooka w sesji — automat jej nie założy',
        'wejdz/facebook/polacz' => 'DŁUG: prawdziwy ekran, ale wymaga tożsamości Facebooka w sesji i zalogowania — automat ani jednego, ani drugiego nie założy',
    ];

    /** Adresy wymienione w `EKRANY` w automacie dostępności. */
    private function mierzoneAdresy(): array
    {
        $sciezka = base_path('scripts/dostepnosc.mjs');

        $this->assertFileExists($sciezka, 'Nie ma automatu dostępności — ten test pilnowałby pustki.');

        $zrodlo = (string) file_get_contents($sciezka);

        $poczatek = strpos($zrodlo, 'const EKRANY = [');
        $this->assertNotFalse($poczatek, 'W automacie nie ma już listy `EKRANY`.');

        $koniec = strpos($zrodlo, "\n];", $poczatek);
        $this->assertNotFalse($koniec);

        $lista = substr($zrodlo, $poczatek, $koniec - $poczatek);

        preg_match_all("~adres:\s*(?:'([^']*)'|`([^`]*)`)~", $lista, $trafienia);

        $adresy = array_values(array_filter(array_map(
            static fn (string $a, string $b): string => $a !== '' ? $a : $b,
            $trafienia[1],
            $trafienia[2],
        )));

        /*
         * PRÓG LICZBY ZNALEZIONYCH ADRESÓW — bez niego ten test przechodzi
         * także wtedy, gdy zmieni się nazwa stałej albo kształt wpisów
         * i wyrażenie nie złapie NICZEGO. Zero trafień byłoby wtedy dla
         * niego sukcesem (`docs/PULAPKI_TESTOW.md`, pułapka 2).
         */
        $this->assertGreaterThan(
            15,
            count($adresy),
            'Z listy `EKRANY` wyszło podejrzanie mało adresów — zmienił się kształt pliku, '
            .'a test przestał cokolwiek mierzyć.',
        );

        return $adresy;
    }

    public function test_kazda_publiczna_strona_jest_mierzona_albo_wypisana_jako_wyjatek(): void
    {
        $mierzone = $this->mierzoneAdresy();

        // Ścieżki bez parametrów i bez wiodącego ukośnika, do porównania
        // z `uri` z tablicy tras.
        $mierzoneUri = array_map(
            static fn (string $adres): string => ltrim(Str::before($adres, '?'), '/'),
            $mierzone,
        );

        $pominiete = [];
        $sprawdzone = 0;

        foreach (Route::getRoutes() as $trasa) {
            if (! in_array('GET', $trasa->methods(), true)) {
                continue;
            }

            $uri = $trasa->uri();

            // Trasy z parametrem mają w automacie własny sposób ustalania
            // adresu (`znajdz`), więc nie da się ich porównać po `uri`.
            if (str_contains($uri, '{')) {
                continue;
            }

            // Wewnętrzne zasoby frameworka i Livewire'a — nie są stronami.
            if (str_starts_with($uri, '_') || str_starts_with($uri, 'livewire')
                || str_starts_with($uri, 'storage') || str_starts_with($uri, 'api')) {
                continue;
            }

            $posrednie = array_map(
                static fn (mixed $m): string => is_string($m) ? $m : '',
                $trasa->gatherMiddleware(),
            );

            // Interesują nas WYŁĄCZNIE strony dostępne bez logowania: ekrany
            // zalogowanego automat dobiera osobno i nie da się ich policzyć
            // z tablicy tras (część istnieje tylko dla autora treści).
            foreach ($posrednie as $m) {
                if (preg_match('~^(auth|admin|moderat|signed|verified|can:)~i', $m)) {
                    continue 2;
                }
            }

            $sprawdzone++;

            if (in_array($uri, $mierzoneUri, true) || ($uri === '/' && in_array('/', $mierzone, true))) {
                continue;
            }

            if (array_key_exists($uri, self::WYJATKI)) {
                continue;
            }

            $pominiete[] = $uri;
        }

        /*
         * Kontrola dodatnia dla samego skanu (pułapka 2 z PULAPKI_TESTOW.md):
         * gdyby tablica tras była pusta albo filtr odcinał wszystko, poniższa
         * pętla nie miałaby czego pominąć i test świeciłby na zielono nad
         * niczym.
         */
        $this->assertGreaterThan(
            10,
            $sprawdzone,
            'Skan tras publicznych nic nie znalazł — zmienił się filtr albo tablica tras.',
        );

        $this->assertSame(
            [],
            $pominiete,
            "Te strony publiczne nie są mierzone przez `scripts/dostepnosc.mjs`: \n  ".
            implode("\n  ", $pominiete)."\n".
            'Dopisz je do `EKRANY` w tamtym pliku albo — jeśli świadomie nie mają być '
            .'mierzone — do stałej WYJATKI w tym teście, z powodem. Milczące pominięcie '
            .'jest tu najgorszą z możliwości: raport wygląda wtedy na kompletny.',
        );
    }

    public function test_spis_tematow_jest_na_liscie_ekranow(): void
    {
        /*
         * Osobna, JAWNA asercja na `/tagi` obok skanu wyżej. Skan pilnuje
         * reguły ogólnej i można go uciszyć jedną linijką w WYJATKI — a to
         * jest dokładnie ta strona, która z tej listy raz już wypadła, i to
         * bez śladu. Ta asercja tego nie wybaczy: żeby ją uciszyć, trzeba
         * skasować test, czyli zrobić coś widocznego w przeglądzie kodu.
         */
        $this->assertContains(
            '/tagi',
            $this->mierzoneAdresy(),
            'Spis wszystkich tagów (`/tagi`, D-087) wypadł z listy `EKRANY` w automacie '
            .'dostępności. To strona publiczna — gęste rzędy odnośników z licznikami, '
            .'czyli układ, który przy 320 px i tekście 140% najłatwiej wypycha stronę w bok.',
        );
    }

    public function test_kazdy_wyjatek_ma_powod(): void
    {
        // Wyjątek bez powodu przestaje być decyzją, a staje się listą, na
        // którą wpisuje się wszystko, co akurat przeszkadza.
        foreach (self::WYJATKI as $uri => $powod) {
            $this->assertGreaterThan(
                10,
                strlen($powod),
                "Wyjątek „{$uri}” nie ma napisanego powodu.",
            );
        }
    }
}
