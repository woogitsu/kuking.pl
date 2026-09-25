<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Wersja;
use Tests\TestCase;

/**
 * Podbicie etykiety wersji bez wpisu w CHANGELOG.md.
 *
 * PO CO TO JEST
 * `config/kuking.php` mówi wprost, trzy linie nad `'etykieta' => …`:
 * „KAŻDE PODBICIE CYFRY MA WPIS W `CHANGELOG.md` — jedno pilnuje drugiego."
 * Nic tej reguły nie pilnowało: audyt z 21.09.2026 znalazł CZTERY gałęzie
 * (`gpt-ugotowalem-dostep`, `gpt-zalegle`, `gpt-zdjecia-limity`,
 * `gpt-zdjecia-publikacja`), z których każda podbija `etykieta` z
 * „Alfa 0.67" na „Alfa 0.68", a ŻADNA nie dotyka `CHANGELOG.md`.
 *
 * DLACZEGO TO NIE PORÓWNUJE Z GITEM
 * Runtime floty (`_wspolne/przygotuj-runtime.sh`) i obraz produkcyjny
 * (`.dockerignore` wycina `*.md` i `docs/`) nie mają dostępu do historii ani
 * czasem nawet do samego pliku. Test, który wymagałby `git diff`, działałby
 * tylko tam, gdzie jest `.git` — czyli w CI i lokalnie, ale nie wszędzie, gdzie
 * ktoś mógłby chcieć go uruchomić. Zamiast „czy wersja SIĘ ZMIENIŁA", pytamy
 * o niezmiennik, który nie potrzebuje historii: „czy AKTUALNA etykieta wersji
 * jest tą samą etykietą, którą zapowiada NAJŚWIEŻSZY wpis w CHANGELOG.md".
 * Gdy ktoś podbije `etykieta` i zapomni o wpisie, te dwie wartości się
 * rozjeżdżają i test pada — bez jednego wywołania gita. Gdy ktoś zmieni coś
 * bez ruszania wersji, obie wartości zostają takie, jak były, i test dalej
 * jest zielony: reguła pilnuje PODBICIA, nie każdej zmiany.
 *
 * SEKCJA „NIEOPUBLIKOWANE" (decyzja właściciela, 23.09.2026)
 * PR-y nie podbijają numeru — dopisują linię w `## Nieopublikowane` na samej
 * górze CHANGELOG-u, a numer rośnie raz, przy wydaniu. Dlatego ten test
 * pilnuje dwóch rzeczy: że sekcja stoi PIERWSZA (bez niej bramka CI nie ma
 * gdzie szukać wpisu i każdy PR z widokiem oblewa) i że pierwszy nagłówek
 * WERSJI pod nią to aktualna etykieta — sekcję „Nieopublikowane" pomija.
 *
 * DRUGI KIERUNEK TEJ REGUŁY STOI GDZIE INDZIEJ
 * Ten test nie pyta „zmieniono coś, co człowiek zobaczy — czy jest wpis?",
 * bo to pytanie o RÓŻNICĘ, a tu świadomie nie ma gita (powód wyżej). Tego
 * pilnuje `scripts/bramka-wersji.sh`, wołany przez job CI `bramka_wersji`;
 * jego zachowania i podpięcia pilnuje `BramkaPodbiciaWersjiTest`.
 *
 * GDZIE TO CHODZI, A GDZIE NIE
 * Chodzi wszędzie, gdzie leży pełne drzewo repozytorium z `CHANGELOG.md`:
 * CI, lokalny `php artisan test`, runtime floty zbudowany przez
 * `_wspolne/przygotuj-runtime.sh` (kopiuje cały worktree, nie tylko `.git`).
 * NIE chodzi w obrazie produkcyjnym z Railify/Dockera — `.dockerignore`
 * wycina `*.md` i `docs/`, a testy tam i tak się nie wykonują. To jest guard
 * na etapie code review / CI, nie strażnik uruchamiany na produkcji.
 */
class PodbicieWersjiWymagaWpisuWChangelogTest extends TestCase
{
    private const NIEOPUBLIKOWANE = 'Nieopublikowane';

    public function test_aktualna_etykieta_wersji_ma_wpis_na_gorze_changelog(): void
    {
        $skarga = $this->niezgodnosc(Wersja::etykieta());

        $this->assertNull($skarga, (string) $skarga);
    }

    /**
     * KONTROLA UJEMNA (przeniesiona z PR-a #1247): podbicie etykiety bez
     * nagłówka nowej wersji MA wywrócić sprawdzenie z testu wyżej.
     *
     * Bez tego testu plik mógłby być zielony dlatego, że porównanie nigdy
     * niczego nie zgłasza. Podbicie idzie tą samą drogą, którą poszłoby
     * prawdziwe: przez wartość konfiguracji, którą czyta `Wersja::etykieta()`.
     * Przy zasadzie „Nieopublikowane" to jest dokładnie błąd PR-a wydania,
     * który podbił numer i zapomniał dać liście nagłówek — sama sekcja
     * `## Nieopublikowane` na górze NIE może uchodzić za wpis nowej wersji.
     */
    public function test_podbicie_etykiety_bez_wpisu_zostaje_wykryte(): void
    {
        $przed = Wersja::etykieta();
        $this->assertNull($this->niezgodnosc($przed), 'Punkt wyjścia ma być zgodny, inaczej ten test niczego nie dowodzi.');

        $podbita = $this->etykietaPodbitaOJeden($przed);
        $this->assertNotSame($przed, $podbita, 'Podbicie musi dać INNY numer, inaczej nie ma czego wykrywać.');

        config(['kuking.wersja.etykieta' => $podbita]);
        $skarga = $this->niezgodnosc(Wersja::etykieta());

        $this->assertNotNull(
            $skarga,
            "Etykieta podbita na „{$podbita}” bez nagłówka w CHANGELOG.md przeszła bez skargi — ".
            'ten strażnik nie pilnuje niczego.',
        );

        $this->assertStringContainsString($podbita, $skarga, 'Skarga ma mówić, jaka wersja stoi w configu.');
        $this->assertStringContainsString("mówi „{$przed}”.", $skarga,
            'Skarga ma mówić, co zapowiada CHANGELOG.md — pierwszą WERSJĘ, nie sekcję „Nieopublikowane".');
    }

    /**
     * Druga kontrola dodatnia (przeniesiona z PR-a #1247): sprawdzamy TĘ
     * etykietę, która stoi w pliku `config/kuking.php`, a nie wartość
     * doklejoną gdzie indziej.
     *
     * `Wersja::etykieta()` czyta konfigurację, a ta może przyjść ze
     * zbuforowanego `bootstrap/cache/config.php` albo z `config()` ustawionego
     * w teście. Gdyby to się rozjechało, strażnik wyżej pilnowałby czegoś
     * innego niż linijki, nad którą stoi reguła — i nadal byłby zielony.
     * Tę samą linijkę czyta `scripts/bramka-wersji.sh` (droga „wydanie").
     */
    public function test_etykieta_widziana_przez_aplikacje_pochodzi_z_pliku_configu(): void
    {
        $sciezka = base_path('config/kuking.php');
        $this->assertFileExists($sciezka);

        $zrodlo = (string) file_get_contents($sciezka);
        $this->assertSame(
            1,
            preg_match_all("/^\s*'etykieta' => '([^']+)',/mu", $zrodlo, $dopasowanie),
            "W config/kuking.php nie ma dokładnie jednej linii „'etykieta' => '…',” — ".
            'strażnik wersji nie ma czego pilnować.',
        );

        $this->assertSame(
            $dopasowanie[1][0],
            Wersja::etykieta(),
            'Aplikacja pokazuje inną etykietę niż ta zapisana w config/kuking.php.',
        );
    }

    public function test_changelog_zaczyna_sie_od_sekcji_nieopublikowane(): void
    {
        preg_match('/^##[ \t]+(.+)$/mu', (string) file_get_contents(base_path('CHANGELOG.md')), $pierwszy);

        $this->assertSame(
            self::NIEOPUBLIKOWANE,
            trim($pierwszy[1] ?? ''),
            'Pierwszą sekcją CHANGELOG.md ma być „## '.self::NIEOPUBLIKOWANE.'" — tam PR-y '.
            'dopisują wpisy, a numer wersji rośnie dopiero przy wydaniu (AGENTS.md, '.
            '„Wersja i CHANGELOG"). Bez niej bramka CI nie znajdzie żadnego wpisu.',
        );
    }

    /**
     * KONTROLA DODATNIA: skan, który nie znajduje żadnego pliku, przechodzi —
     * i to jest zakazane w tym repozytorium (ZASADY_FLOTY.md). Ten test
     * dowodzi, że `etykietaNajnowszegoWpisu()` NAPRAWDĘ czyta plik, a nie
     * zwraca `null`/pusty ciąg, który przypadkiem zrównałby się z czymkolwiek.
     */
    public function test_skan_naprawde_czyta_naglowek_changelog(): void
    {
        $sciezka = base_path('CHANGELOG.md');
        $this->assertFileExists($sciezka, 'CHANGELOG.md musi istnieć w drzewie repozytorium.');

        $najnowszyWpis = $this->etykietaNajnowszegoWpisu();

        $this->assertNotNull($najnowszyWpis);
        $this->assertMatchesRegularExpression(
            '/^(Alfa|Beta) 0\.\d+$/',
            $najnowszyWpis,
            'Nagłówek CHANGELOG.md powinien zaczynać się etykietą w znanym '.
            'formacie („Alfa 0.N" / „Beta 0.N") — jeśli to nie przechodzi, skan '.
            'czyta zły plik albo zły format nagłówka, a nie brak zmian.',
        );
    }

    /**
     * Issue #928: dwie gałęzie podbijały 0.67 → 0.68 niezależnie. Z tym samym
     * podbiciem etykiety git scala je BEZ konfliktu (ta sama linia, ta sama
     * treść), więc dwa wydania cicho zlewały się w jeden numer. Jedyny ślad,
     * który zostaje po scaleniu, to dwa nagłówki tej samej wersji
     * w CHANGELOG.md — i tego pilnujemy: numery wersji są unikalne i maleją
     * od góry pliku.
     */
    public function test_numery_wersji_w_changelog_sa_unikalne_i_maleja(): void
    {
        $numery = $this->numeryWersji((string) file_get_contents(base_path('CHANGELOG.md')));

        $this->assertNotEmpty($numery, 'CHANGELOG.md nie ma nagłówków „## Alfa 0.N — …".');
        $this->assertSame([], $this->bledyNumeracji($numery), 'CHANGELOG.md: '
            .'numer wersji powtórzony albo nie maleje. Dwie gałęzie podbiły tę samą '
            .'wersję — przenieś wpis jednej z nich pod „## Nieopublikowane” i cofnij '
            .'jej podbicie (docs/flota/chmura/SESJA_GLOWNA.md §5).');
    }

    /**
     * KONTROLA DODATNIA: ten sam skan na treści z dublem i z odwróconą
     * kolejnością naprawdę zgłasza błąd, a na poprawnej — nie.
     */
    public function test_skan_numeracji_wykrywa_dubel_i_odwrocona_kolejnosc(): void
    {
        $dubel = "## Nieopublikowane\n\n## Alfa 0.69 — jedno\n\n## Alfa 0.69 — drugie\n\n## Alfa 0.68 — stare\n";
        $odwrotnie = "## Alfa 0.68 — stare\n\n## Alfa 0.69 — nowe\n";
        $dobrze = "## Nieopublikowane\n\n## Beta 0.70 — nowe\n\n## Alfa 0.69 — stare\n\n## Przygotowane — bez numeru\n";

        $this->assertSame([69, 69, 68], $this->numeryWersji($dubel));
        $this->assertNotSame([], $this->bledyNumeracji($this->numeryWersji($dubel)));
        $this->assertNotSame([], $this->bledyNumeracji($this->numeryWersji($odwrotnie)));
        $this->assertSame([70, 69], $this->numeryWersji($dobrze));
        $this->assertSame([], $this->bledyNumeracji($this->numeryWersji($dobrze)));
    }

    /**
     * Numery N z nagłówków „## Alfa 0.N — …" / „## Beta 0.N — …", od góry.
     *
     * @return list<int>
     */
    private function numeryWersji(string $tresc): array
    {
        preg_match_all('/^##\s+(?:Alfa|Beta)\s+0\.(\d+)\s+—/mu', $tresc, $dopasowania);

        return array_map('intval', $dopasowania[1]);
    }

    /**
     * @param  list<int>  $numery
     * @return list<string>
     */
    private function bledyNumeracji(array $numery): array
    {
        $bledy = [];
        for ($i = 1; $i < count($numery); $i++) {
            if ($numery[$i] >= $numery[$i - 1]) {
                $bledy[] = "0.{$numery[$i]} stoi pod 0.{$numery[$i - 1]}";
            }
        }

        return $bledy;
    }

    /**
     * Pierwszy nagłówek `## …` w CHANGELOG.md poza „Nieopublikowane", obcięty
     * do samej etykiety wersji (część przed „ — "). `null`, gdy pliku nie da
     * się przeczytać albo nie ma w nim żadnego nagłówka wersji.
     */
    private function etykietaNajnowszegoWpisu(): ?string
    {
        $sciezka = base_path('CHANGELOG.md');

        if (! is_file($sciezka)) {
            return null;
        }

        $tresc = file_get_contents($sciezka);

        if ($tresc === false) {
            return null;
        }

        preg_match_all('/^##[ \t]+(.+)$/mu', $tresc, $naglowki);

        foreach ($naglowki[1] as $naglowek) {
            $etykieta = trim(explode(' — ', $naglowek, 2)[0]);

            if ($etykieta !== self::NIEOPUBLIKOWANE) {
                return $etykieta;
            }
        }

        return null;
    }

    /**
     * Skarga do człowieka, gdy podana etykieta nie ma swojego nagłówka jako
     * pierwszej WERSJI w `CHANGELOG.md` (pod „Nieopublikowane"). `null`, gdy
     * wszystko się zgadza. Ta sama reguła co w teście na górze pliku, wyrażona
     * jako wartość — żeby kontrola ujemna sprawdzała DOKŁADNIE ją.
     */
    private function niezgodnosc(string $etykieta): ?string
    {
        $najnowszyWpis = $this->etykietaNajnowszegoWpisu();

        if ($najnowszyWpis === null) {
            return 'CHANGELOG.md nie ma ani jednego nagłówka wersji „## …" — nie da się sprawdzić, '.
                "czy wersja „{$etykieta}” ma swój wpis.";
        }

        if ($najnowszyWpis === $etykieta) {
            return null;
        }

        return "config/kuking.php mówi „{$etykieta}”, ale najświeższy nagłówek wersji w CHANGELOG.md ".
            "mówi „{$najnowszyWpis}”. Wydanie, które podbija etykietę, przenosi listę z ".
            '„## Nieopublikowane" pod nowy nagłówek „## <etykieta> — …" (AGENTS.md, „Wersja i CHANGELOG").';
    }

    /** „Alfa 0.68" → „Alfa 0.69". Tylko na użytek kontroli ujemnej. */
    private function etykietaPodbitaOJeden(string $etykieta): string
    {
        return (string) preg_replace_callback(
            '/(\d+)$/',
            static fn (array $cyfry): string => (string) ((int) $cyfry[1] + 1),
            $etykieta,
            1,
        );
    }
}
