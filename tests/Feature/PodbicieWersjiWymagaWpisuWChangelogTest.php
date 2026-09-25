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
 * GDZIE TO CHODZI, A GDZIE NIE
 * Chodzi wszędzie, gdzie leży pełne drzewo repozytorium z `CHANGELOG.md`:
 * CI, lokalny `php artisan test`, runtime floty zbudowany przez
 * `_wspolne/przygotuj-runtime.sh` (kopiuje cały worktree, nie tylko `.git`).
 * NIE chodzi w obrazie produkcyjnym z Railify/Dockera — `.dockerignore`
 * wycina `*.md` i `docs/`, a testy tam i tak się nie wykonują. To jest guard
 * na etapie code review / CI, nie strażnik uruchamiany na produkcji.
 *
 * @bez-kontroli-dodatniej Brak szukanego nagłówka w CHANGELOG.md daje czerwień przez assertMatchesRegularExpression, więc reguła nie może po cichu przestać obowiązywać.
 */
class PodbicieWersjiWymagaWpisuWChangelogTest extends TestCase
{
    public function test_aktualna_etykieta_wersji_ma_wpis_na_gorze_changelog(): void
    {
        $etykieta = Wersja::etykieta();
        $najnowszyWpis = $this->etykietaNajnowszegoWpisu();

        $this->assertNotNull(
            $najnowszyWpis,
            'CHANGELOG.md nie ma żadnego nagłówka „## …" — nie da się sprawdzić, '.
            'czy aktualna wersja ma wpis.',
        );

        $this->assertSame(
            $etykieta,
            $najnowszyWpis,
            "config/kuking.php mówi „{$etykieta}”, ale najświeższy wpis w ".
            "CHANGELOG.md mówi „{$najnowszyWpis}”. Podbicie etykiety wersji MA ".
            'mieć własny wpis na górze CHANGELOG.md — patrz komentarz trzy '.
            "linie nad 'etykieta' w config/kuking.php.",
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
     * Pierwszy nagłówek `## …` w CHANGELOG.md, obcięty do samej etykiety
     * wersji (część przed „ — "). `null`, gdy pliku nie da się przeczytać albo
     * nie ma w nim żadnego nagłówka drugiego poziomu.
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

        if (! preg_match('/^##\s+(.+?)\s+—/mu', $tresc, $dopasowanie)) {
            return null;
        }

        return trim($dopasowanie[1]);
    }
}
