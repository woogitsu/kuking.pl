<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Profile;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Najtrudniejszy wariant profilu WCHODZI do próbki automatu (issue #440).
 *
 * CO BYŁO NIE TAK
 * ---------------
 * `scripts/dostepnosc.mjs` mierzył profil na dwóch kontach demo: „Basia"
 * (5 znaków) i „Ania" (4). `display_name` ma w walidacji maksimum z konfiguracji
 * (`kuking.profil.dlugosc_nazwy`) i ani jednego ograniczenia na długość
 * pojedynczego SŁOWA, więc pełny limit w jednym kawałku jest poprawnym
 * wejściem — a automat nie widział go ani razu.
 *
 * Skutek: zdanie „nic nie wyjeżdża w bok na profilu" dotyczyło ekranu,
 * którego najtrudniejszego wariantu nikt nie sprawdził. To jest pułapka 5
 * z `docs/PULAPKI_TESTOW.md` od strony DANYCH, i groźniejsza niż zwykle:
 * przy pustym ekranie widać przynajmniej, że nic nie ma. Tu ekran był
 * pełny, poprawny i po prostu łatwy.
 *
 * CZEGO TEN TEST NIE ROBI
 * -----------------------
 * Nie mierzy przepełnienia. Czy strona wyjeżdża w bok przy 320 px
 * i czcionce 200%, wie wyłącznie przeglądarka — i to mierzy
 * `scripts/dostepnosc.mjs`, który chodzi z ręki i w CI, nie w `php artisan test`.
 *
 * Ten test pilnuje rzeczy, której tamten pomiar pilnować nie może: żeby
 * PRÓBKA nie skurczyła się z powrotem. Automat pokazujący zero naruszeń na
 * łatwej próbce wygląda dokładnie tak samo jak automat pokazujący zero na
 * trudnej — i to jest cały problem.
 */
class ProfilZNajdluzszaNazwaWchodziDoPomiaruTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /**
     * Maksimum z walidacji `display_name` — to ono definiuje „najtrudniejszy".
     *
     * CZYTANE Z KONFIGURACJI, NIE WPISANE. Poprzednia wersja tego pliku miała
     * tu stałą `100` i komentarz „jeśli maksimum jest inne, popraw stałą
     * w tym teście ORAZ nazwę w DemoSeederze". Przy zmianie limitu (#467)
     * okazało się, że tych miejsc jest sześć, nie dwa. Liczba przepisana
     * w drugie miejsce rozjeżdża się przy pierwszej zmianie, a rozjazd znaczy
     * tutaj „próbka przestaje być najtrudniejszym wariantem, nie przestając
     * nim wyglądać" — czyli dokładnie to, przed czym ten plik ma bronić.
     */
    private function maksimum(): int
    {
        return (int) config('kuking.profil.dlugosc_nazwy');
    }

    #[Test]
    public function test_walidacja_bierze_limit_z_konfiguracji_i_nie_ogranicza_dlugosci_slowa(): void
    {
        /*
         * Pytamy o to, czy kontroler CZYTA limit z konfiguracji, a nie o samą
         * liczbę. Liczba wpisana w kontrolerze przeszłaby ten test dokładnie
         * raz — w dniu, w którym akurat równa się konfiguracji.
         *
         * Wszystkie miejsca walidacji, bo `display_name` wchodzi czterema
         * drzwiami: rejestracja, ustawienia i dwa logowania zewnętrzne.
         * Oba logowania zewnętrzne walidują ekran domknięcia w jednym
         * przypadku użycia (`WejdzPrzezDostawce`, #1035), więc plików są trzy.
         * Rozjazd między nimi znaczy „przez rejestrację wejdzie nazwa,
         * której ustawienia już nie przyjmą".
         */
        $drzwi = [
            'app/Http/Controllers/Auth/RegisterController.php',
            'app/Http/Controllers/Settings/ProfileSettingsController.php',
            'app/Domain/Security/WejsciePrzezDostawce/WejdzPrzezDostawce.php',
        ];

        foreach ($drzwi as $sciezka) {
            $this->assertStringContainsString(
                "'display_name' => ['required', 'string', 'min:2', 'max:'.config('kuking.profil.dlugosc_nazwy')]",
                (string) file_get_contents(base_path($sciezka)),
                $sciezka.' nie bierze limitu nazwy z konfiguracji. Liczba wpisana wprost '.
                'rozjedzie się z pozostałymi drzwiami przy pierwszej zmianie.',
            );
        }

        // Ograniczenia długości pojedynczego SŁOWA nadal nie ma — i to ono,
        // nie suma znaków, wypycha stronę w bok.
        $this->assertStringNotContainsString(
            'regex:/^\\S{1,',
            (string) file_get_contents(base_path($drzwi[0])),
            'Doszło ograniczenie długości słowa. „Najtrudniejszy wariant" znaczy '.
            'wtedy co innego i cały ten plik pilnuje nieaktualnego założenia.',
        );
    }

    #[Test]
    public function test_dane_demo_maja_profil_z_nazwa_na_pelny_limit(): void
    {
        $this->seed(DemoSeeder::class);

        $nazwy = Profile::query()->pluck('display_name', 'username');

        // KONTROLA DODATNIA do asercji niżej (pułapka 4): sam fakt, że
        // istnieje profil o nazwie 100-znakowej, nic by nie znaczył, gdyby
        // seeder nie utworzył żadnego innego — wtedy „najdłuższy" byłby
        // jedynym, a nie najtrudniejszym z kilku.
        $this->assertGreaterThanOrEqual(
            4,
            $nazwy->count(),
            'DemoSeeder przestał tworzyć konta demo — profilu nie ma z czym porównać.',
        );

        $najdluzsza = $nazwy->map(fn (string $n): int => mb_strlen($n))->max();

        $this->assertSame(
            $this->maksimum(),
            $najdluzsza,
            'W danych demo nie ma profilu z nazwą na pełne '.$this->maksimum().' znaków. '.
            'Bez niego automat dostępności mierzy profil wyłącznie z nazwą krótką, '.
            'a o najtrudniejszym wariancie tego ekranu nie mówi nic.',
        );

        // I najdłuższe pojedyncze SŁOWO — bo to ono, nie suma znaków,
        // wypycha stronę w bok. Nazwa złożona z samych krótkich wyrazów
        // miałaby pełną długość i nie byłaby trudna.
        $zNajdluzsza = $nazwy->first(fn (string $n): bool => mb_strlen($n) === $this->maksimum());
        $najdluzszeSlowo = max(array_map('mb_strlen', preg_split('/\s+/u', (string) $zNajdluzsza) ?: []));

        /*
         * PRÓG JEST UŁAMKIEM LIMITU, NIE LICZBĄ. Przy limicie 100 znaków
         * wymagaliśmy słowa na co najmniej 40 — czyli 40% nazwy w jednym
         * nieprzerwanym ciągu. Wpisana na sztywno czterdziestka przy limicie
         * 40 znaków znaczyłaby „cała nazwa musi być jednym słowem", co jest
         * wymaganiem nie do spełnienia dla nazwy wyglądającej jak nazwa.
         */
        $prog = (int) ceil(0.4 * $this->maksimum());

        $this->assertGreaterThanOrEqual(
            $prog,
            $najdluzszeSlowo,
            'Nazwa ma pełne '.$this->maksimum().' znaków, ale same krótkie wyrazy — '.
            'a stronę w bok wypycha najdłuższy nieprzerwany ciąg, nie suma. '.
            'Taka próbka wygląda na trudną i nie jest.',
        );
    }

    #[Test]
    public function test_ekran_profilu_naprawde_pokazuje_te_nazwe(): void
    {
        $this->seed(DemoSeeder::class);

        $profil = Profile::query()
            ->get()
            ->first(fn (Profile $p): bool => mb_strlen((string) $p->display_name) === $this->maksimum());

        $this->assertNotNull($profil, 'Brak profilu o nazwie na pełny limit.');

        $odpowiedz = $this->get('/@'.$profil->username);
        $odpowiedz->assertOk();

        // W TREŚCI EKRANU, nie w całym dokumencie: nazwa profilu stoi także
        // w `<title>` i w `<meta>` (pułapka 1b). Gdyby główka przestała ją
        // pokazywać, asercja na całym HTML-u i tak by przeszła — a automat
        // mierzyłby wtedy ekran BEZ długiej nazwy, czyli znowu ten łatwy.
        $this->assertStringContainsString(
            (string) $profil->display_name,
            $this->trescEkranu($odpowiedz->getContent() ?: ''),
            'Ekran profilu nie pokazuje długiej nazwy w treści. Automat mierzyłby '.
            'wtedy profil bez niej — czyli dokładnie ten łatwy wariant, przed '.
            'którym ten plik ma chronić.',
        );
    }

    #[Test]
    public function test_automat_dostepnosci_ma_ten_profil_na_liscie_ekranow(): void
    {
        $skrypt = (string) file_get_contents(base_path('scripts/dostepnosc.mjs'));

        $this->assertGreaterThan(
            2000,
            substr_count($skrypt, "\n"),
            'Czytamy podejrzanie krótki plik — to na pewno automat dostępności?',
        );

        $this->assertStringContainsString(
            "const KONTO_DLUGA_NAZWA = 'zofia_z_bieszczad';",
            $skrypt,
            'Z automatu zniknęła stała z nazwą konta o najdłuższej dopuszczalnej nazwie.',
        );

        // Pozycja na liście EKRANY — czyli w tej jednej pętli, która przechodzi
        // przez komplet szerokości i skal tekstu. Sama stała nie wystarcza:
        // nieużyta byłaby martwym kodem, a raport dalej wyglądałby na kompletny.
        $this->assertMatchesRegularExpression(
            '/\{\s*nazwa:\s*\x27profil \(najdłuższa dopuszczalna nazwa\)\x27,\s*adres:\s*`\/@\$\{KONTO_DLUGA_NAZWA\}`/u',
            $skrypt,
            'Profil z najdłuższą dopuszczalną nazwą nie jest pozycją listy EKRANY. Bez tego '.
            'automat go nie otwiera — a brak ekranu na liście niczego nie psuje: '.
            'raport wygląda na kompletny i świeci na zielono.',
        );
    }

    #[Test]
    public function test_fokus_po_tabie_nie_lapduje_pod_przypieta_belka(): void
    {
        /*
         * REGRESJA ZNALEZIONA TĄ WŁAŚNIE PRÓBKĄ (issue #440).
         *
         * Po dopisaniu profilu z najdłuższą dopuszczalną nazwą `scripts/dostepnosc.mjs`
         * pokazał trzy naruszenia WCAG 2.2 AA 2.4.11 (Focus Not Obscured),
         * których przedtem nie było ANI JEDNEGO. Wszystkie na tym samym
         * elemencie: menu „Więcej przy tym wpisie" na karcie wpisu autora
         * o długiej nazwie.
         *
         * Zmierzone przy 320 px i tekście 140%, dojściem Tabem jak w automacie:
         *
         *     autor „Basia" (5 znaków)   menu na 314 px   0% zakryte
         *     autor o 100 znakach        menu na   1 px   100% ZAKRYTE
         *
         * Przyczyną nie jest samo menu: przewijanie fokusu w widok liczy się
         * do krawędzi OKNA i nie wie, że stoi tam przypięta belka. Długa nazwa
         * tylko ustawiła jedną kontrolkę dokładnie tam, gdzie to widać.
         * Dlatego poprawka jest na korzeniu (`scroll-padding-top`), a nie na
         * tym jednym elemencie — i dlatego ten test pilnuje korzenia.
         *
         * TEN TEST ZMIENIŁ TOKEN PRZY SCALANIU, I TO JEST CAŁA ZMIANA.
         * Tę samą dziurę naprawiono 12 września 2026 dwa razy niezależnie,
         * w tym samym pliku: tutaj przez `--rezerwa-nad-trescia` (stałe 13rem
         * / 6rem / 0rem) i na drugiej gałęzi przez `--rezerwa-nad-belka`
         * (`calc(7rem + 5rem * var(--user-text-scale, 1))`). Została JEDNA,
         * ta druga — bo liczy się z naszym ustawieniem wielkości tekstu,
         * a nie tylko z czcionką przeglądarki, i bo ma zmierzony SUFIT:
         * powyżej 244,5 px (320 px, tekst 140%) przeglądarka przestaje równać
         * kontrolkę dołem do pasa i zaczyna równać górą, czyli każdy następny
         * piksel rezerwy u góry wpycha jej dół pod DOLNĄ belkę. Wyprowadzenie
         * obu liczb stoi w komentarzu przy samej regule w `app.css`.
         *
         * ZMIERZONE PO SCALENIU, tą właśnie próbką (`node scripts/dostepnosc.mjs`):
         *
         *     scroll-padding-top: auto (stan sprzed obu poprawek)   3 × 100%
         *     scroll-padding-top: var(--rezerwa-nad-belka)          0 × 100%
         *
         * Czyli formuła, która została, zdejmuje dokładnie te trzy naruszenia,
         * które znalazła ta próbka. Nie jest to wniosek z podobieństwa obu
         * poprawek, tylko osobny pomiar.
         *
         * CO PILNUJE CZEGO. Ten test pilnuje JEDNEGO: że rezerwa nad paskiem
         * dalej istnieje i dalej jest wpięta w `scroll-padding-top` — bo to
         * jest regresja znaleziona tą próbką i tu jest jej miejsce.
         * Kształtu samej rezerwy (wiązanie ze skalą tekstu, zgodność progów
         * zerowania z progami odpięcia `.topbar` z D-107) pilnuje
         * `RezerwaNadPaskiemTest`, żeby te same asercje nie stały w dwóch
         * plikach i nie rozjechały się po cichu.
         *
         * Projekt miał już bliźniaczą rezerwę od DOLNEJ belki
         * (`scroll-padding-bottom`); brakowało wyłącznie odpowiednika od góry.
         *
         * KOMENTARZE PRECZ PRZED ASERCJĄ (`docs/PULAPKI_TESTOW.md`, pułapka 1):
         * komentarz przy tej regule cytuje dosłownie odrzucony wariant
         * (`--rezerwa-nad-trescia`, „13rem"), więc asercja na surowym pliku
         * mogłaby trafić we własne uzasadnienie zamiast w kod.
         */
        $arkusz = (string) preg_replace(
            '~/\*.*?\*/~s',
            '',
            (string) file_get_contents(base_path('resources/css/app.css')),
        );

        $this->assertNotSame(
            '',
            trim($arkusz),
            'Arkusz app.css po zdjęciu komentarzy jest pusty — test sprawdzałby pustkę.',
        );

        $this->assertMatchesRegularExpression(
            '/scroll-padding-top:\s*var\(--rezerwa-nad-belka\)\s*;/u',
            $arkusz,
            'Zniknęła rezerwa nad przypiętym paskiem górnym. Bez niej kontrolka, '.
            'do której Tab przewinie stronę, ląduje pod paskiem — widoczna dla '.
            'kodu, niewidoczna dla człowieka (WCAG 2.2 AA, 2.4.11). Zmierzone '.
            'tą próbką: trzy naruszenia na menu „Więcej przy tym wpisie".',
        );

        // Rezerwa MUSI schodzić do zera tam, gdzie pasek przestaje być
        // przypięty — inaczej sama spycha fokus poza ekran. Bez tej asercji
        // „poprawka" przechodziłaby też w wariancie, w którym szkodzi.
        // Zgodności WSZYSTKICH progów zerowania z progami odpięcia `.topbar`
        // (D-107) pilnuje `RezerwaNadPaskiemTest`; tutaj sprawdzamy ten jeden,
        // przy którym ta próbka była mierzona.
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*15rem\)\s*\{\s*:root\s*\{\s*--rezerwa-nad-belka:\s*0rem/u',
            $arkusz,
            'Rezerwa nad paskiem nie schodzi do zera przy czcionce przeglądarki '.
            '200%, gdzie pasek nie jest już przypięty. Rezerwa nad paskiem, '.
            'który wyjeżdża razem z treścią, to czysta strata ekranu — i przy '.
            'dolnej rezerwie nie zostaje z okna żaden pas na kontrolkę.',
        );
    }

    #[Test]
    public function test_dlugu_nazwe_trzymaja_w_ryzach_dwi_e_reguly_i_obie_zostaja(): void
    {
        /*
         * ZMIERZONE, NIE ZAŁOŻONE — i pomiar poprawił tu założenie.
         *
         * Kontrola ujemna miała brzmieć „zdejmij `overflow-wrap: anywhere`
         * z `.profil-tozsamosc > *`, a pomiar ma wywalić". Nie wywalił.
         * Przyczyną nie była słaba próbka, tylko słaby SABOTAŻ (pułapka 3
         * z `docs/PULAPKI_TESTOW.md`): ten ekran trzymają DWIE niezależne
         * reguły i zdjęcie jednej zostawia drugą.
         *
         * Zmierzone na `/@zofia_z_bieszczad` przy 320 px, profil z nazwą
         * na 100 znaków (najdłuższy nieprzerwany ciąg: 55). Tyle wynosił
         * wtedy limit; od #467 wynosi tyle, ile mówi `kuking.profil.dlugosc_nazwy`.
         * Liczby niżej zostają takie, jakie były w dniu pomiaru — pomiar
         * przepisany pod nowy stan przestaje być pomiarem:
         *
         *   obie reguły                          scrollWidth 320  bez przepełnienia
         *   bez `.profil-tozsamosc > *`          scrollWidth 320  bez przepełnienia
         *   bez globalnej z `tokens.css`         scrollWidth 320  bez przepełnienia
         *   BEZ OBU                              scrollWidth 557  PRZEPEŁNIENIE
         *
         * 557 px w oknie 320 px to 237 px poza ekranem — próbka mierzy
         * dokładnie to, o co chodzi, i mierzyła przez cały czas.
         *
         * Dlatego ten test pilnuje OBU reguł. Pilnowanie jednej mówiłoby
         * nieprawdę o tym, co ten ekran trzyma — a nieprawda w komunikacie
         * oblanego testu jest gorsza niż brak testu: wysyła następną osobę
         * pod zły adres.
         */
        $profil = (string) file_get_contents(base_path('resources/css/ekran-profilu.css'));

        $this->assertMatchesRegularExpression(
            '/\.profil-tozsamosc\s*>\s*\*\s*\{[^}]*overflow-wrap:\s*anywhere\s*;/u',
            $profil,
            'Z `.profil-tozsamosc > *` zniknęło `overflow-wrap: anywhere` — drugi '.
            'z dwóch pasów, które trzymają długą nazwę w ryzach. Sam w sobie nie '.
            'jest dziś jedyną obroną (patrz pomiar w docblocku), ale `anywhere` '.
            'łamie ciąg AGRESYWNIEJ niż globalne `break-word` i jest jedyną '.
            'regułą pisaną pod ten ekran.',
        );

        $tokeny = (string) file_get_contents(base_path('resources/css/tokens.css'));

        $this->assertMatchesRegularExpression(
            '/overflow-wrap:\s*break-word\s*;/u',
            $tokeny,
            'Z `tokens.css` zniknęło globalne `overflow-wrap: break-word`. To ono '.
            'jest dziś pierwszym pasem — po jego zdjęciu profil z nazwą na sto '.
            'znaków wypycha stronę do 557 px w oknie 320 px.',
        );
    }
}
