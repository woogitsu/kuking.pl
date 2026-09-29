<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Higiena dziennika decyzji: żadnego roboczego numeru i żadnego odwołania
 * `D-NNN` bez nagłówka (issue #2154).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Audyt A4 z 25.09.2026 znalazł na `main` dwie rzeczy, przy których wszystkie
 * istniejące testy numeracji były zielone:
 *  - wpis `## D-1009-ROBOCZA` — numer roboczy („ostateczny przydziela
 *    koordynator przy scalaniu"), którego nikt nie przydzielił. Wzorce
 *    `NumeryDecyzjiMajaWpisyTest` biorą dokładnie trzy cyfry, więc czterocyfrowy
 *    identyfikator w ogóle nie był dla nich wpisem ani odnośnikiem;
 *  - „regułę o numerze 235", przywołaną dwa razy w dzienniku jako obowiązującą zasadę
 *    numeracji, choć wpisu o tym numerze nigdy nie było. `NumeryDecyzjiMajaWpisyTest`
 *    świadomie nie skanuje `docs/`, a `OdnosnikiDziennikaDecyzjiIstniejaTest`
 *    sprawdza tylko linie `📄`, nie prozę.
 * Numer decyzji jest w tym repozytorium ODNOŚNIKIEM. Roboczy numer wygląda na
 * trwałe uzasadnienie, martwy odnośnik też — i oba zatrzymują dalsze szukanie.
 *
 * CO TEN PLIK MA OSOBNO, A NIE W `NumeryDecyzjiMajaWpisyTest`
 * Tamten skaner ma szeroki zakres i wąski wzorzec: docblock wyjaśnia, dlaczego
 * rozszerzenie go na całe `docs/` dałoby fałszywe alarmy pierwszego dnia. Ten
 * ma własny, węższy kontrakt:
 *  1. NAGŁÓWEK wpisu w dzienniku to `## D-NNN` z dokładnie trzema cyframi i bez
 *     znacznika roboczości. Nic innego nie jest trwałym identyfikatorem;
 *  2. NIGDZIE w skanowanym repozytorium nie stoi identyfikator czterocyfrowy
 *     (`D-1009`) ani roboczy (`D-1009-ROBOCZA`, `D-XYZ-TYMCZASOWA`);
 *  3. każde `D-NNN` w dzienniku, w kodzie, w `AGENTS.md`/`CLAUDE.md`/`README.md`
 *     /`CHANGELOG.md` i w żywej dokumentacji `docs/` ma nagłówek w dzienniku.
 *
 * TREŚĆ, NIE LISTA NUMERÓW. Zbiór nagłówków jest liczony z plików za każdym
 * razem. Nic tu nie wymienia numerów istniejących decyzji, więc PR, który
 * dopisuje kolejne numery razem z odwołaniami, nie musi dotykać tego pliku.
 * Dziennik czytany jest z `docs/DECISIONS.md` ORAZ z `docs/decyzje/D-*.md` —
 * pierwszy jest dziś całością, drugi pojawi się, gdy dziennik zostanie
 * podzielony na pliki (PR #1744); test działa w obu układach.
 *
 * WYJĄTKI SĄ DANYMI Z UZASADNIENIEM, NIE WYŁĄCZENIEM `docs/`
 * Trzy stałe niżej (LUKI_NA_STALE, LUKI_HISTORYCZNE_W_DZIENNIKU,
 * ZAPOWIEDZI_W_DOKUMENTACH) mają po jednym zdaniu powodu przy każdym numerze
 * i test sprawdza, że powód istnieje. Poza kontrolą istnienia stoi krótka
 * lista katalogów, które z założenia opisują STAN Z DNIA (raporty audytów,
 * protokoły floty, przekazania pracy) albo cudzą numerację — patrz
 * POZA_KONTROLA_ISTNIENIA.
 *
 * PUŁAPKA 2 Z `docs/PULAPKI_TESTOW.md`
 * Skaner, który nic nie znalazł, przechodzi. Progi MIN_* stoją poniżej stanu
 * z dnia napisania (274 nagłówki, 4418 plików, 6776 odwołań), ale daleko
 * powyżej zera; nie rosną przy każdym nowym wpisie. Testy na sztucznych treściach (niżej) dowodzą, że
 * reguły umieją zapalić bez ruszania repozytorium; kontrola ujemna na
 * prawdziwych plikach jest w `scripts/kontrole-negatywne-alfa08.py`.
 */
final class DziennikDecyzjiOdwolaniaTest extends TestCase
{
    /**
     * Numery, których w dzienniku NIE MA I NIE BĘDZIE — wolno je przywoływać
     * w dowolnym pliku. Sprawdzone także w drugą stronę: nadanie takiego numeru
     * wpisowi obala test, bo przestałby oznaczać to, co znaczy.
     *
     * @var array<string, string>
     */
    private const LUKI_NA_STALE = [
        '084' => 'Puste świadomie i na stałe — mówi to nagłówek docs/DECISIONS.md („Osobno i wcześniej puste są 084, 086 i 094").',
        '086' => 'Puste świadomie i na stałe — patrz nr 084.',
        '094' => 'Puste świadomie i na stałe — patrz nr 084.',
        '101' => 'Numer z CUDZEGO dziennika systemu projektowego (docs/design/system-v3.1/); dziennik główny przeszedł z nr 107 od razu na nr 113.',
        '102' => 'Numer z cudzego dziennika systemu projektowego — patrz nr 101.',
        '108' => 'Odstęp od cudzej numeracji, zostaje pusty na zawsze (nagłówek docs/DECISIONS.md).',
        '109' => 'Odstęp od cudzej numeracji — patrz nr 108.',
        '110' => 'Odstęp od cudzej numeracji — patrz nr 108.',
        '111' => 'Odstęp od cudzej numeracji — patrz nr 108.',
        '112' => 'Odstęp od cudzej numeracji — patrz nr 108.',
    ];

    /**
     * Numery, które dziennik przywołuje WPROST JAKO nieistniejące albo jako
     * numery z gałęzi, które nigdy nie trafiły na `main` pod tym numerem.
     * Ważne wyłącznie w plikach dziennika. Nikt nie cytuje ich tam jako
     * uzasadnienia — opisują historię numeracji.
     *
     * @var array<string, string>
     */
    private const LUKI_HISTORYCZNE_W_DZIENNIKU = [
        '066' => 'Wpisy 085 i 092 opowiadają o martwym odnośniku do numeru 066 (wpis powstał na zamkniętej gałęzi #254 i nigdy nie trafił do dziennika).',
        '067' => 'Wpis 085 opowiada o numerze 067, którego nigdy nie napisano (przekazanie pracy §13.8) — to opis błędu, nie odwołanie.',
        '226' => 'Wpis 239 mówi wprost, że numery 226 i 227 były numerami z gałęzi, bez własnego nagłówka w dzienniku.',
        '228' => 'Wpis 233 wylicza numer 228 jako numer z gałęzi #966, nie z `main` — opis historii numeracji.',
        '243' => 'Wpis 242 opisuje wyścig o numer 243 między dwiema gałęziami; numer zajęła gałąź `flota/scal-786`, nie dziennik.',
    ];

    /**
     * Zapowiedzi w żywej dokumentacji: decyzja jest w realizacji na otwartym PR,
     * a dokument mówi to wprost („W toku: issue #…, decyzja D-…"). Para
     * plik => numery. Wyjątek jest wąski celowo — jeden plik, jeden numer — bo
     * pozostałe odwołania w tych plikach mają dalej podlegać kontroli.
     *
     * Gdy PR doda wpis, wyjątek staje się zbędny i warto go usunąć; test
     * NIE oblewa z tego powodu, żeby scalenie cudzego PR-a nie robiło
     * czerwonego `main` z powodu tego pliku.
     *
     * @var array<string, array<string, string>>
     */
    private const ZAPOWIEDZI_W_DOKUMENTACH = [
        'docs/legal/COMPLIANCE.md' => [
            '305' => 'Wiersz „W toku: issue #1811, decyzja 305" (numer z prefiksem D) — decyzja przyjdzie z PR-em #1811/#1879, dokument sam mówi, że jest w realizacji.',
        ],
        'docs/research/PREFERENCJE_TRESCI.md' => [
            '305' => 'Zdanie „jest w realizacji: issue #1811, decyzja 305, PR #1879" (numer z prefiksem D) — zapowiedź, nie powołanie na obowiązującą regułę.',
        ],
    ];

    /**
     * Ścieżki (prefiks albo cały plik), w których NIE sprawdzamy istnienia
     * nagłówka, i powód. Identyfikatory czterocyfrowe i robocze sprawdzamy
     * tam mimo to — z wyjątkiem POZA_KONTROLA_FORMY.
     *
     * @var array<string, string>
     */
    private const POZA_KONTROLA_ISTNIENIA = [
        'docs/audyt/' => 'Raporty audytów cytują numery, których BRAK jest ustaleniem (np. audyt A4 o numerze 235) — stan z dnia, nie źródło reguł.',
        'docs/flota/' => 'Protokoły floty opisują numery zajęte na GAŁĘZIACH (226, 228, 247…), których na `main` nigdy nie było pod tym numerem.',
        'docs/zlecenia/' => 'Przekazania pracy z dnia — stan z dnia, wymieniają numery zarezerwowane.',
        'docs/research/audyt-2026-09-10/' => 'Raport audytu z dnia — patrz docs/audyt/.',
        'docs/PRZEKAZANIE_2026_09_20.md' => 'Przekazanie pracy z dnia — wymienia numery z gałęzi (numer 226).',
        'tests/Feature/NumeryDecyzjiMajaWpisyTest.php' => 'Docblock OPOWIADA o numeracji (066, 067, 100, 999); własny skan tego pliku też go pomija.',
        'tests/Feature/DziennikDecyzjiOdwolaniaTest.php' => 'Ten plik wymienia przykładowe, nieistniejące numery, żeby dowieść, że strażnik je łapie.',
    ];

    /**
     * Ścieżki poza kontrolą FORMY (czterocyfrowy i roboczy identyfikator):
     * tylko te, które CYTUJĄ zły identyfikator jako przedmiot ustalenia.
     *
     * @var array<string, string>
     */
    private const POZA_KONTROLA_FORMY = [
        'docs/audyt/' => 'Audyt A4 cytuje „D-1009-ROBOCZA" jako znalezisko, którego dotyczy ten test.',
        'tests/Feature/DziennikDecyzjiOdwolaniaTest.php' => 'Ten plik podaje D-1009 i D-1000-ROBOCZA jako przykłady.',
    ];

    /** Katalogi całkowicie poza skanem: cudza numeracja i zależności. */
    private const KATALOGI_POMIJANE = [
        'vendor', 'node_modules', '.git', 'storage', 'build', '.claude',
        'docs/design/system-v3.1',
    ];

    /** Katalogi i pliki startowe skanu (obok plików w katalogu głównym). */
    private const KORZENIE = [
        '.github', 'app', 'bootstrap', 'config', 'database', 'docker', 'docs',
        'lang', 'public', 'resources', 'routes', 'scripts', 'tests',
    ];

    private const ROZSZERZENIA = [
        'php', 'md', 'txt', 'css', 'js', 'mjs', 'json', 'py', 'sh', 'yml', 'yaml', 'ts', 'sql',
    ];

    /** Roboczy znacznik w nagłówku albo w identyfikatorze. */
    private const WZORZEC_ROBOCZY = '/\bD-\d+-(?:ROBOCZ\w*|TYMCZAS\w*|TMP|DRAFT|WIP|TODO)\b/iu';

    /** Identyfikator czterocyfrowy i dłuższy. */
    private const WZORZEC_DLUGI = '/\bD-\d{4,}\b/';

    /** Zwykłe odwołanie: dokładnie trzy cyfry. */
    private const WZORZEC_ODWOLANIA = '/\bD-(\d{3})\b/';

    /** Słowa w nagłówku wpisu, które robią z niego szkic. */
    private const WZORZEC_SZKICU_W_NAGLOWKU = '/\b(?:ROBOCZ[AYE]|TYMCZASOW[AYE]|DRAFT|WIP|TODO)\b/u';

    private const MIN_NAGLOWKOW = 200;

    private const MIN_PLIKOW = 2500;

    private const MIN_ODWOLAN = 3000;

    // ------------------------------------------------------------------
    // Testy na prawdziwym repozytorium
    // ------------------------------------------------------------------

    public function test_naglowki_dziennika_to_trzycyfrowe_numery_bez_znacznika_roboczosci(): void
    {
        $dziennik = $this->wczytajDziennik();

        $this->assertGreaterThanOrEqual(
            self::MIN_NAGLOWKOW,
            count($dziennik['naglowki']),
            'Znaleziono podejrzanie mało nagłówków „## D-NNN" ('.count($dziennik['naglowki']).'). '
            .'To raczej usterka tego testu (ścieżka, wzorzec) niż dziennika.',
        );

        $this->assertSame(
            [],
            $dziennik['wadliwe'],
            "Nagłówki dziennika, które nie są ostatecznym, trzycyfrowym numerem:\n"
            .implode("\n", $dziennik['wadliwe'])
            ."\n\nNumer roboczy nie ma prawa trafić na main. Nadaj wpisowi pierwszy wolny numer "
            .'względem main i wszystkich gałęzi origin (grep „^## D-" po git show origin/<gałąź>:docs/DECISIONS.md '
            .'oraz docs/decyzje/), przepnij odwołania w tej samej zmianie. Procedura: '
            .'docs/flota/MAPA_NUMEROW_DECYZJI.md, „Procedura nadawania numeru".',
        );

        $zdublowane = array_keys(array_filter($dziennik['ile'], static fn (int $n): bool => $n > 1));
        sort($zdublowane);

        $this->assertSame(
            [],
            array_map(static fn (string $n): string => 'D-'.$n, $zdublowane),
            'Ten sam numer ma więcej niż jeden nagłówek w dzienniku.',
        );
    }

    public function test_zadne_odwolanie_w_repozytorium_nie_jest_robocze_ani_czterocyfrowe_i_kazde_ma_naglowek(): void
    {
        $dziennik = $this->wczytajDziennik();
        $naglowki = $dziennik['naglowki'];

        $this->assertGreaterThanOrEqual(self::MIN_NAGLOWKOW, count($naglowki));

        $pliki = 0;
        $odwolan = 0;
        $naruszenia = [];

        foreach ($this->plikiDoSkanu() as $sciezka => $bezwzgledna) {
            $tresc = @file_get_contents($bezwzgledna);

            if ($tresc === false) {
                continue;
            }

            $pliki++;

            $wynik = $this->naruszeniaWTresci(
                $tresc,
                $sciezka,
                $naglowki,
                $this->czyPlikDziennika($sciezka),
                $this->objeteKontrola($sciezka, self::POZA_KONTROLA_FORMY),
                $this->objeteKontrola($sciezka, self::POZA_KONTROLA_ISTNIENIA),
            );

            $odwolan += $wynik['odwolania'];

            foreach ($wynik['naruszenia'] as $naruszenie) {
                $naruszenia[] = $naruszenie;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_PLIKOW,
            $pliki,
            'Skan przeczytał tylko '.$pliki.' plików, oczekiwano co najmniej '.self::MIN_PLIKOW
            .'. Test, który nic nie czyta, niczego nie pilnuje (docs/PULAPKI_TESTOW.md #2) — sprawdź KORZENIE.',
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_ODWOLAN,
            $odwolan,
            'Skan sprawdził tylko '.$odwolan.' odwołań D-NNN, oczekiwano co najmniej '.self::MIN_ODWOLAN
            .'. Sprawdź WZORZEC_ODWOLANIA i ROZSZERZENIA.',
        );

        sort($naruszenia);

        $this->assertSame(
            [],
            $naruszenia,
            "Odwołania do decyzji, których nie ma w dzienniku, albo identyfikatory robocze/czterocyfrowe:\n"
            .implode("\n", $naruszenia)."\n\n"
            ."Kolejność wyjść: (1) decyzja jest pod INNYM numerem — znajdź ją przez `git log -S'<zdanie>'` i przepnij;\n"
            ."(2) numer nadano na gałęzi i przenumerowano — przepnij odwołania w tej samej zmianie;\n"
            .'(3) tekst opisuje historię numeracji — dopisz numer do LUKI_HISTORYCZNE_W_DZIENNIKU z powodem. '
            .'Nie wymyślaj decyzji, żeby zapełnić lukę.',
        );
    }

    public function test_numery_zarezerwowane_na_stale_nie_maja_naglowka(): void
    {
        $naglowki = $this->wczytajDziennik()['naglowki'];

        $zajete = array_values(array_intersect(array_keys(self::LUKI_NA_STALE), array_keys($naglowki)));

        $this->assertSame(
            [],
            $zajete,
            'Numer z LUKI_NA_STALE dostał nagłówek w dzienniku — przestał być lukę na stałe. '
            .'Wybierz inny numer dla nowego wpisu (zostaw luki do cudzej numeracji puste).',
        );
    }

    public function test_kazdy_wyjatek_ma_uzasadnienie(): void
    {
        $wszystkie = [
            ...self::LUKI_NA_STALE,
            ...self::LUKI_HISTORYCZNE_W_DZIENNIKU,
            ...self::POZA_KONTROLA_ISTNIENIA,
            ...self::POZA_KONTROLA_FORMY,
        ];

        foreach (self::ZAPOWIEDZI_W_DOKUMENTACH as $numery) {
            $wszystkie = [...$wszystkie, ...$numery];
        }

        foreach ($wszystkie as $klucz => $powod) {
            $this->assertGreaterThanOrEqual(
                30,
                mb_strlen($powod),
                'Wyjątek „'.$klucz.'" nie ma uzasadnienia. Lista wyjątków bez powodów to wyłączenie strażnika.',
            );
        }

        foreach (array_keys(self::ZAPOWIEDZI_W_DOKUMENTACH) as $plik) {
            $this->assertFileExists(base_path($plik), 'Wyjątek wskazuje plik, którego nie ma: '.$plik);
        }
    }

    // ------------------------------------------------------------------
    // Reguły na sztucznych treściach — dowód, że umieją zapalić
    // ------------------------------------------------------------------

    public function test_regula_lapie_roboczy_naglowek_wstawiony_do_dziennika(): void
    {
        $tresc = $this->t("# Dziennik\n\n## §001 · Pierwszy\n\nTreść.\n\n## D-1000-ROBOCZA — Szkic (1 stycznia 2027)\n\nTreść.\n");

        $dziennik = $this->naglowkiZTresci($tresc, 'docs/DECISIONS.md');

        $this->assertSame(['001' => true], $dziennik['naglowki']);
        $this->assertCount(1, $dziennik['wadliwe']);
        $this->assertStringContainsString('D-1000-ROBOCZA', $dziennik['wadliwe'][0]);

        $wynik = $this->naruszeniaWTresci($tresc, 'docs/DECISIONS.md', ['001' => true], true, false, false);
        $this->assertNotSame([], $wynik['naruszenia']);
    }

    public function test_regula_lapie_naglowek_z_trzema_cyframi_i_slowem_robocza(): void
    {
        $dziennik = $this->naglowkiZTresci($this->t("## §777 — ROBOCZA: coś tam\n"), 'docs/DECISIONS.md');

        $this->assertSame([], $dziennik['naglowki']);
        $this->assertCount(1, $dziennik['wadliwe']);
    }

    public function test_regula_lapie_odwolanie_do_nieistniejacego_numeru(): void
    {
        $naglowki = ['001' => true, '002' => true];

        $wynik = $this->naruszeniaWTresci($this->t("Zob. §001 oraz §235 i §002.\n"), 'docs/inny.md', $naglowki, false, false, false);

        $this->assertSame(3, $wynik['odwolania']);
        $this->assertCount(1, $wynik['naruszenia']);
        $this->assertStringContainsString($this->t('§235'), $wynik['naruszenia'][0]);
        $this->assertStringContainsString('docs/inny.md:1', $wynik['naruszenia'][0]);
    }

    public function test_regula_lapie_trzycyfrowy_numer_z_dopiskiem_roboczy_nawet_gdy_numer_ma_naglowek(): void
    {
        // Sam dopisek to osobna usterka: numer istnieje, a odwołanie nadal
        // wskazuje na „wersję roboczą", której nikt nie ma prawa cytować.
        $wynik = $this->naruszeniaWTresci($this->t("Zob. §123-ROBOCZA.\n"), 'docs/inny.md', ['123' => true], false, false, false);

        $this->assertCount(1, $wynik['naruszenia']);
        $this->assertStringContainsString('ROBOCZA', $wynik['naruszenia'][0]);
    }

    public function test_regula_lapie_czterocyfrowy_identyfikator_w_dowolnym_pliku(): void
    {
        $wynik = $this->naruszeniaWTresci("linia\nZob. D-1009.\n", 'AGENTS.md', ['001' => true], false, false, false);

        $this->assertCount(1, $wynik['naruszenia']);
        $this->assertStringContainsString('AGENTS.md:2', $wynik['naruszenia'][0]);
    }

    public function test_wyjatki_nie_daja_falszywych_alarmow_i_sa_waskie(): void
    {
        $naglowki = ['001' => true];

        // Luka na stałe: wolno wszędzie.
        $this->assertSame(
            [],
            $this->naruszeniaWTresci($this->t("Puste są §084 i §108.\n"), 'docs/inny.md', $naglowki, false, false, false)['naruszenia'],
        );

        // Opis historii numeracji: wolno w dzienniku, nie poza nim.
        $this->assertSame(
            [],
            $this->naruszeniaWTresci($this->t("Numer §226 był na gałęzi.\n"), 'docs/DECISIONS.md', $naglowki, true, false, false)['naruszenia'],
        );
        $this->assertCount(
            1,
            $this->naruszeniaWTresci($this->t("Numer §226 był na gałęzi.\n"), 'docs/inny.md', $naglowki, false, false, false)['naruszenia'],
        );

        // Zapowiedź: jeden plik i jeden numer, nie „cały plik".
        $plik = 'docs/legal/COMPLIANCE.md';
        $this->assertSame(
            [],
            $this->naruszeniaWTresci($this->t("decyzja §305\n"), $plik, $naglowki, false, false, false)['naruszenia'],
        );
        $this->assertCount(
            1,
            $this->naruszeniaWTresci($this->t("decyzja §306\n"), $plik, $naglowki, false, false, false)['naruszenia'],
        );

        // Plik poza kontrolą istnienia nadal podlega kontroli formy.
        $wynik = $this->naruszeniaWTresci($this->t("§235 i D-1000-ROBOCZA\n"), 'docs/flota/x.md', $naglowki, false, false, true);
        $this->assertNotSame([], $wynik['naruszenia']);
        $this->assertStringContainsString('D-1000-ROBOCZA', implode("\n", $wynik['naruszenia']));
        $this->assertStringNotContainsString($this->t('§235'), implode("\n", $wynik['naruszenia']));

        // Zakres i przykłady w prozie: zakres od pierwszego do ostatniego trzycyfrowego numeru ma zapalić, chyba że plik jest poza kontrolą.
        $this->assertCount(
            1,
            $this->naruszeniaWTresci($this->t("Trzy cyfry obsługują §001…§999.\n"), 'docs/inny.md', $naglowki, false, false, false)['naruszenia'],
        );
    }

    public function test_dziennik_w_formie_plikow_jest_czytany_tak_samo(): void
    {
        $this->assertTrue($this->czyPlikDziennika('docs/DECISIONS.md'));
        $this->assertTrue($this->czyPlikDziennika($this->t('docs/decyzje/§329-pierwszy-wklad-jednorazowy.md')));
        $this->assertFalse($this->czyPlikDziennika('docs/decyzje/ADR_RETENCJE.md'));
        $this->assertFalse($this->czyPlikDziennika('docs/DATABASE.md'));
    }

    // ------------------------------------------------------------------
    // Reguły (czyste funkcje na tekście)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, true>  $naglowki
     * @return array{odwolania: int, naruszenia: list<string>}
     */
    private function naruszeniaWTresci(
        string $tresc,
        string $sciezka,
        array $naglowki,
        bool $plikDziennika,
        bool $pozaForma,
        bool $pozaIstnieniem,
    ): array {
        $odwolania = 0;
        $naruszenia = [];

        foreach (explode("\n", $tresc) as $indeks => $linia) {
            $miejsce = $sciezka.':'.($indeks + 1);

            if (! $pozaForma) {
                foreach ([self::WZORZEC_ROBOCZY, self::WZORZEC_DLUGI] as $wzorzec) {
                    if (preg_match_all($wzorzec, $linia, $m) > 0) {
                        foreach (array_unique($m[0]) as $znaleziony) {
                            $naruszenia[] = $miejsce.' — '.$znaleziony.' (identyfikator roboczy albo czterocyfrowy)';
                        }
                    }
                }
            }

            if (preg_match_all(self::WZORZEC_ODWOLANIA, $linia, $m) === 0) {
                continue;
            }

            foreach ($m[1] as $cyfry) {
                $odwolania++;

                if ($pozaIstnieniem || isset($naglowki[$cyfry])) {
                    continue;
                }

                if ($this->numerDozwolonyBezNaglowka($cyfry, $sciezka, $plikDziennika)) {
                    continue;
                }

                $naruszenia[] = $miejsce.' — D-'.$cyfry.' nie ma nagłówka w dzienniku';
            }
        }

        return ['odwolania' => $odwolania, 'naruszenia' => array_values(array_unique($naruszenia))];
    }

    /**
     * W przykładach „§235" znaczy „D" i myślnik, a potem numer. Sam dosłowny zapis
     * w tym pliku byłby dla `NumeryDecyzjiMajaWpisyTest` cytatem z dziennika,
     * a przykłady mają wskazywać numery, których w dzienniku nie ma.
     */
    private function t(string $wzor): string
    {
        return str_replace('§', 'D-', $wzor);
    }

    private function numerDozwolonyBezNaglowka(string $cyfry, string $sciezka, bool $plikDziennika): bool
    {
        if (isset(self::LUKI_NA_STALE[$cyfry])) {
            return true;
        }

        if ($plikDziennika && isset(self::LUKI_HISTORYCZNE_W_DZIENNIKU[$cyfry])) {
            return true;
        }

        return isset(self::ZAPOWIEDZI_W_DOKUMENTACH[$sciezka][$cyfry]);
    }

    /**
     * Nagłówki jednego pliku dziennika.
     *
     * @return array{naglowki: array<string, true>, wadliwe: list<string>, ile: array<string, int>}
     */
    private function naglowkiZTresci(string $tresc, string $sciezka): array
    {
        $naglowki = [];
        $wadliwe = [];
        $ile = [];

        foreach (explode("\n", $tresc) as $indeks => $linia) {
            if (preg_match('/^## D-(\S+)/u', $linia, $m) !== 1) {
                continue;
            }

            $miejsce = $sciezka.':'.($indeks + 1).' — '.trim($linia);

            if (preg_match('/^\d{3}$/', $m[1]) !== 1) {
                $wadliwe[] = $miejsce.' (numer „D-'.$m[1].'" nie jest dokładnie trzycyfrowy)';

                continue;
            }

            if (preg_match(self::WZORZEC_SZKICU_W_NAGLOWKU, $linia) === 1) {
                $wadliwe[] = $miejsce.' (nagłówek ma znacznik roboczości)';

                continue;
            }

            $naglowki[$m[1]] = true;
            $ile[$m[1]] = ($ile[$m[1]] ?? 0) + 1;
        }

        return ['naglowki' => $naglowki, 'wadliwe' => $wadliwe, 'ile' => $ile];
    }

    // ------------------------------------------------------------------
    // Pliki
    // ------------------------------------------------------------------

    /**
     * @return array{naglowki: array<string, true>, wadliwe: list<string>, ile: array<string, int>}
     */
    private function wczytajDziennik(): array
    {
        $pliki = ['docs/DECISIONS.md'];

        $wpisy = glob(base_path('docs/decyzje/D-*.md'));

        foreach ($wpisy === false ? [] : $wpisy as $wpis) {
            $pliki[] = 'docs/decyzje/'.basename($wpis);
        }

        sort($pliki);

        $wynik = ['naglowki' => [], 'wadliwe' => [], 'ile' => []];

        foreach ($pliki as $plik) {
            $this->assertFileExists(base_path($plik), 'Brak pliku dziennika: '.$plik);

            $czesc = $this->naglowkiZTresci((string) file_get_contents(base_path($plik)), $plik);

            $wynik['naglowki'] += $czesc['naglowki'];
            $wynik['wadliwe'] = [...$wynik['wadliwe'], ...$czesc['wadliwe']];

            foreach ($czesc['ile'] as $numer => $ile) {
                $wynik['ile'][$numer] = ($wynik['ile'][$numer] ?? 0) + $ile;
            }
        }

        ksort($wynik['naglowki']);

        return $wynik;
    }

    private function czyPlikDziennika(string $sciezka): bool
    {
        return $sciezka === 'docs/DECISIONS.md'
            || preg_match('#^docs/decyzje/D-[^/]+\.md$#', $sciezka) === 1;
    }

    /**
     * @param  array<string, string>  $lista
     */
    private function objeteKontrola(string $sciezka, array $lista): bool
    {
        foreach (array_keys($lista) as $klucz) {
            if ($sciezka === $klucz || (str_ends_with($klucz, '/') && str_starts_with($sciezka, $klucz))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ścieżka względna => bezwzględna, posortowane.
     *
     * @return array<string, string>
     */
    private function plikiDoSkanu(): array
    {
        $pliki = [];

        // Pliki w katalogu głównym: AGENTS.md, CLAUDE.md, README.md, CHANGELOG.md…
        $korzen = scandir(base_path());
        $this->assertIsArray($korzen);

        foreach ($korzen as $nazwa) {
            if (is_file(base_path($nazwa)) && $this->maRozszerzenieDoSkanu($nazwa)) {
                $pliki[$nazwa] = base_path($nazwa);
            }
        }

        foreach (self::KORZENIE as $katalog) {
            $this->zbierz($katalog, $pliki);
        }

        ksort($pliki);

        return $pliki;
    }

    /**
     * @param  array<string, string>  $pliki
     */
    private function zbierz(string $wzgledna, array &$pliki): void
    {
        if (in_array($wzgledna, self::KATALOGI_POMIJANE, true) || in_array(basename($wzgledna), self::KATALOGI_POMIJANE, true)) {
            return;
        }

        $bezwzgledna = base_path($wzgledna);

        if (! is_dir($bezwzgledna) || is_link($bezwzgledna)) {
            return;
        }

        // scandir, nie DirectoryIterator: na WSL ten drugi oddaje tylko fragment
        // dużego katalogu (docs/PULAPKI_TESTOW.md 2b).
        $pozycje = scandir($bezwzgledna);

        if ($pozycje === false) {
            return;
        }

        foreach ($pozycje as $nazwa) {
            if ($nazwa === '.' || $nazwa === '..') {
                continue;
            }

            $dziecko = $wzgledna.'/'.$nazwa;

            if (is_dir(base_path($dziecko))) {
                $this->zbierz($dziecko, $pliki);
            } elseif ($this->maRozszerzenieDoSkanu($nazwa)) {
                $pliki[$dziecko] = base_path($dziecko);
            }
        }
    }

    private function maRozszerzenieDoSkanu(string $nazwa): bool
    {
        return in_array(strtolower(pathinfo($nazwa, PATHINFO_EXTENSION)), self::ROZSZERZENIA, true);
    }
}
