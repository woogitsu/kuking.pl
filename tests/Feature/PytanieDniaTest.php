<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WycinaObudoweEkranu;
use Tests\Support\WzorceRodzaju;
use Tests\TestCase;

/**
 * PYTANIE DNIA na `/home` (issue #38 punkt 2).
 *
 * CO TO JEST
 * Pierwsze zdanie nad polem dodawania: „Witaj, {imię}. Co dziś gotujesz?".
 * `docs/brand/COPY_STYLE.md` §6 („Publikacja") trzyma je jako gotowy tekst,
 * a `AGENTS.md` wymienia „Co dziś gotujesz?" jako wzorcową konstrukcję bez
 * rodzaju. Właściciel zdecydował, że pozycja wchodzi.
 *
 * CZEGO TEN PLIK PILNUJE I DLACZEGO KAŻDEJ Z TYCH RZECZY OSOBNO
 *
 * 1. POWITANIE NIE ZALEŻY OD GODZINY. Zależało — i było to napisane tak, że
 *    kłamało na dwa sposoby naraz: „Dobry wieczór" witało od 15:00, a godzina
 *    szła z `now()`, czyli z `config('app.timezone')`, które w tym repozytorium
 *    jest `UTC` i musi zostać (issue #87). Latem serwis liczył więc o dwie
 *    godziny za wcześnie i po 23:00 czasu polskiego pisał „Dzień dobry".
 *    Żadnego z tych dwóch błędów nie pilnował ani jeden test — `git grep
 *    greeting -- tests/` wracał pusty. Test niżej mrozi czas na pięciu
 *    godzinach doby i wymaga, żeby tekst był ZA KAŻDYM RAZEM ten sam; wraca
 *    na czerwono w chwili, w której ktoś znów uzależni powitanie od zegara.
 *
 * 2. GAŁĄŹ BEZ IMIENIA JEST OSOBNA. `User::displayName()` podstawia
 *    „Użytkownik Kuking", gdy profilu nie ma albo nazwa jest pusta — i w
 *    powitaniu byłoby to kłamstwem, bo udaje zwrot po imieniu. Testowana jest
 *    przez profil z samymi spacjami: to ten sam warunek (`trim(...) === ''`),
 *    co brak profilu, a daje się sprawdzić przez HTTP. Konta zupełnie bez
 *    profilu (`User::factory()->create()`) tą drogą sprawdzić się nie da —
 *    `/home` renderuje awatar i podpisy, które profilu wymagają
 *    (`docs/PULAPKI_TESTOW.md`).
 *
 * 3. PYTANIE DNIA NIE ZASTĘPUJE NAZWY GŁÓWNEJ AKCJI. „Dodaj zdjęcie tego, co
 *    ugotowałeś" jest jedynym napisem przy kafelku dodawania; bez niego
 *    zostałaby przy nim sama ikona, czego zakazuje `docs/UX_50_PLUS.md`.
 *    Pytanie stoi NAD nim i ma tam zostać — test sprawdza obecność obu i ich
 *    kolejność, bo „jest na stronie" nie znaczy „jest we właściwym miejscu".
 *
 * 4. PYTANIE DNIA NIE KORZYSTA Z WYJĄTKU RODZAJOWEGO. Fraza „co ugotowałeś"
 *    ma w `Tests\Support\WzorceRodzaju::WYJATKI` wpis nadany celowo (hasło
 *    główne marki). Nowy tekst nie ma prawa się o niego opierać — gdyby się
 *    opierał, `TekstyNiePrzypisujaPlciTest` byłby na niego ślepy, a wyglądałby
 *    na dowód. Dlatego sprawdzamy tu ZARÓWNO brak trafień wzorców, JAK I to,
 *    że w samym powitaniu nie ma żadnej z fraz objętych wyjątkiem.
 *
 * ASERCJE IDĄ PO TREŚCI EKRANU, NIE PO CAŁYM DOKUMENCIE (D-164)
 * `trescEkranu()` zdejmuje `<title>`, belkę i stopkę. Bez tego asercja
 * „strona zawiera «co dziś ugotowałeś»" przeszłaby z samego tytułu dokumentu
 * („Kuking — pokaż, co dziś ugotowałeś"), czyli nie mierzyłaby niczego.
 */
class PytanieDniaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /** Napis przy kafelku dodawania — jedyna nazwa głównej akcji na tym ekranie. */
    private const NAZWA_GLOWNEJ_AKCJI = 'Dodaj zdjęcie tego, co ugotowałeś';

    public function test_pytanie_dnia_wita_po_imieniu(): void
    {
        $halina = $this->user('halina', ['display_name' => 'Halina']);

        $tresc = $this->trescEkranu(
            $this->actingAs($halina)->get('/home')->assertOk()->getContent() ?: '',
        );

        $this->assertStringContainsString(
            'Witaj, Halina. Co dziś gotujesz?',
            $tresc,
            'Na `/home` nie ma pytania dnia. To jest pierwsze zdanie tego ekranu '
            .'(COPY_STYLE.md §6) i bez niego strona zaczyna się od samego przycisku.',
        );
    }

    /**
     * DWIE POSTACIE BRAKU IMIENIA, I TO NIE JEST NADMIAR.
     *
     * Sabotaż przy pisaniu tego pliku pokazał, czemu muszą być obie. Podmiana
     * `$user->profile?->display_name` na `$user->displayName()` — czyli
     * dokładnie ten błąd, o który w tym teście chodzi — przy nazwie z samych
     * SPACJI niczego nie zmienia: „   " jest w PHP prawdziwe, więc `?:` w
     * `displayName()` jej nie podmienia i obie wersje kodu kończą tak samo.
     * Dopiero nazwa PUSTA („") przechodzi przez `?:` na „Użytkownik Kuking" —
     * i wtedy test pada, jak powinien. Sam wariant ze spacjami byłby zielony
     * przy popsutym kodzie.
     *
     * @return list<array{string}>
     */
    public static function brakImienia(): array
    {
        return [
            'nazwa pusta' => [''],
            'nazwa z samych spacji' => ['   '],
        ];
    }

    #[DataProvider('brakImienia')]
    public function test_pytanie_dnia_bez_imienia_zostawia_samo_pytanie(string $nazwa): void
    {
        // Profil jest, ale nazwy w nim nie ma. To ten sam warunek, na który
        // trafia konto bez profilu — a daje się obejrzeć przez HTTP.
        $bezImienia = $this->user('bezimienia', ['display_name' => $nazwa]);

        $tresc = $this->trescEkranu(
            $this->actingAs($bezImienia)->get('/home')->assertOk()->getContent() ?: '',
        );

        // Porównanie CAŁEGO nagłówka, nie „zawiera / nie zawiera". Sabotaż
        // przy pisaniu tego pliku pokazał, po co: wersja sprawdzająca brak
        // frazy „Witaj," przepuszczała „Dzień dobry, . Co dziś gotujesz?" —
        // czyli ten sam błąd, o który tu chodzi, tylko z innym powitaniem.
        $this->assertSame(
            'Co dziś gotujesz?',
            $this->powitanie($tresc),
            'Konto bez imienia dostaje w nagłówku coś innego niż samo pytanie dnia. '
            .'Zwrot po imieniu ma zniknąć w całości — razem z przecinkiem po nim.',
        );

        $this->assertStringNotContainsString(
            'Użytkownik Kuking',
            $tresc,
            'Powitanie zwraca się do człowieka nazwą zastępczą z `User::displayName()`. '
            .'To udaje imię, którego nie mamy — a serwis nie zmyśla tego, czego nie wie.',
        );
    }

    /**
     * Tekst nagłówka `/home` — bez znaczników i bez reszty ekranu.
     *
     * Nagłówek MUSI istnieć: asercje „nie zawiera" są na stronie bez `<h1>`
     * zielone z definicji (`docs/PULAPKI_TESTOW.md`, pułapka 2).
     */
    private function powitanie(string $tresc): string
    {
        $trafil = preg_match('/<h1[^>]*>(.*?)<\/h1>/su', $tresc, $dopasowanie);

        $this->assertSame(1, $trafil, 'Ekran `/home` nie ma `<h1>` — pytania dnia nie ma gdzie stać.');

        $powitanie = trim(strip_tags($dopasowanie[1]));

        $this->assertNotSame('', $powitanie, 'Nagłówek `/home` jest pusty.');

        return $powitanie;
    }

    /**
     * Pięć godzin doby, czas zamrożony. Tekst ma być ZA KAŻDYM RAZEM ten sam.
     *
     * Godziny są podane w UTC, bo `config('app.timezone')` jest UTC i to w niej
     * liczyłby każdy `now()`. W nawiasie stoi godzina, którą w tej samej chwili
     * widzi człowiek w `config('kuking.strefa')` (`Europe/Warsaw`, latem UTC+2) —
     * i to ona pokazuje, czemu stara wersja kłamała: 22:30 UTC to 00:30 w nocy
     * czasu polskiego, a poprzednia metoda witała wtedy „Dobry wieczór", zaś
     * o 08:00 UTC (10:00 w Polsce) pisała „Co dziś gotujesz?" zamiast pytać
     * o obiad — bo liczyła 8:00.
     *
     * @return list<array{string, string}>
     */
    public static function poryDnia(): array
    {
        return [
            'noc, 02:30 UTC (04:30 w Polsce)' => ['2026-09-12 02:30:00', 'noc'],
            'rano, 08:00 UTC (10:00 w Polsce)' => ['2026-09-12 08:00:00', 'rano'],
            'popołudnie, 13:30 UTC (15:30 w Polsce)' => ['2026-09-12 13:30:00', 'popołudnie'],
            'wieczór, 18:00 UTC (20:00 w Polsce)' => ['2026-09-12 18:00:00', 'wieczór'],
            'po północy w Polsce, 22:30 UTC (00:30)' => ['2026-09-12 22:30:00', 'późna noc'],
        ];
    }

    #[DataProvider('poryDnia')]
    public function test_pytanie_dnia_brzmi_tak_samo_o_kazdej_porze(string $chwila, string $pora): void
    {
        $this->travelTo(Carbon::parse($chwila, 'UTC'));

        $halina = $this->user('halina', ['display_name' => 'Halina']);

        $tresc = $this->trescEkranu(
            $this->actingAs($halina)->get('/home')->assertOk()->getContent() ?: '',
        );

        $this->assertStringContainsString(
            'Witaj, Halina. Co dziś gotujesz?',
            $tresc,
            "Powitanie zmieniło się o porze „{$pora}” ({$chwila} UTC). Ma brzmieć tak samo "
            .'o każdej godzinie: serwis nie zna strefy czasowej człowieka i nie ma jak '
            .'sprawdzić, czy u niego jest rano.',
        );

        foreach (['Dzień dobry', 'Dobry wieczór', 'Co dziś na obiad', 'Co dziś wyszło'] as $porowe) {
            $this->assertStringNotContainsString(
                $porowe,
                $tresc,
                "Na `/home` wrócił napis zależny od pory dnia („{$porowe}”) — o {$chwila} UTC. "
                .'Godzina serwera jest w UTC, a nie w strefie człowieka; taki napis kłamie '
                .'o połowie doby (issue #87, `config/app.php`).',
            );
        }
    }

    public function test_pytanie_dnia_stoi_nad_nazwa_glownej_akcji_i_jej_nie_zastepuje(): void
    {
        $halina = $this->user('halina', ['display_name' => 'Halina']);

        $tresc = $this->trescEkranu(
            $this->actingAs($halina)->get('/home')->assertOk()->getContent() ?: '',
        );

        $this->assertStringContainsString(
            self::NAZWA_GLOWNEJ_AKCJI,
            $tresc,
            'Pytanie dnia zjadło napis przy kafelku dodawania. Zostałaby przy nim sama '
            .'ikona, a `docs/UX_50_PLUS.md` mówi: ikona nigdy sama.',
        );

        $pytanie = mb_strpos($tresc, 'Witaj, Halina. Co dziś gotujesz?');
        $akcja = mb_strpos($tresc, self::NAZWA_GLOWNEJ_AKCJI);

        $this->assertIsInt($pytanie);
        $this->assertIsInt($akcja);
        $this->assertLessThan(
            $akcja,
            $pytanie,
            'Pytanie dnia stoi POD główną akcją. Ma stać nad nią — zaprasza do niej, '
            .'a nie podsumowuje ją po fakcie.',
        );
    }

    public function test_pytanie_dnia_nie_opiera_sie_na_wyjatku_rodzajowym(): void
    {
        $halina = $this->user('halina', ['display_name' => 'Halina']);

        $tresc = $this->trescEkranu(
            $this->actingAs($halina)->get('/home')->assertOk()->getContent() ?: '',
        );

        $powitanie = $this->powitanie($tresc);

        $this->assertSame(
            [],
            WzorceRodzaju::trafienia($powitanie),
            "Pytanie dnia przypisuje czytelnikowi płeć: „{$powitanie}”. COPY_STYLE.md §2 "
            .'każe PRZEBUDOWAĆ zdanie, nigdy dopisywać drugiej formy.',
        );

        foreach (array_keys(WzorceRodzaju::WYJATKI) as $wyjatek) {
            $this->assertStringNotContainsString(
                $wyjatek,
                $powitanie,
                "Pytanie dnia korzysta z wyjątku „{$wyjatek}”, nadanego haśle głównemu marki. "
                .'Wyjątek nie jest zapasem do wydawania na nowe teksty — nowy tekst ma być '
                .'bezrodzajowy sam z siebie.',
            );
        }
    }
}
