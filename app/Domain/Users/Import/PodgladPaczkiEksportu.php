<?php

declare(strict_types=1);

namespace App\Domain\Users\Import;

use App\Domain\Users\Exports\WersjaFormatuPaczki;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Support\LimityTekstuPrzepisu;
use JsonException;
use ZipArchive;

/**
 * Czyta paczkę eksportu Kuking (ZIP z `dane.json`) i mówi, co by się stało przy
 * wczytaniu. NICZEGO nie zapisuje (issue #1985, etap 1: „najpierw parser i podgląd
 * bez zapisu”).
 *
 * Paczka jest daną NIEZAUFANĄ — człowiek mógł ją edytować albo podać cudzy plik.
 * Dlatego:
 *
 * - ZIP nie jest rozpakowywany na dysk. Czytamy jeden wpis, `dane.json`, strumieniem
 *   z twardym sufitem bajtów (rozmiar z nagłówka ZIP bywa kłamstwem, więc liczymy
 *   bajty faktycznie odczytane). Bomba archiwum kończy się odmową, nie pamięcią.
 * - Odmawiamy całej paczce, gdy w archiwum jest wpis z nazwą wychodzącą poza
 *   katalog (`../`, ścieżka bezwzględna, `\`, bajt zerowy), gdy plików jest ponad
 *   miarę albo gdy `dane.json` występuje dwa razy. Nic z tego nie zostałoby
 *   rozpakowane, ale zmodyfikowana paczka nie zasługuje na częściowe zaufanie.
 * - JSON ma ograniczoną głębokość i liczbę pozycji w sekcji. Żaden adres z pliku
 *   nie jest otwierany ani pobierany; pola `zrodlo_adres` i inne adresy nie są
 *   nawet czytane.
 * - Z paczki czytamy WYŁĄCZNIE treść przepisów, własnych wpisów i nazwy zeszytów.
 *   Identyfikatory, `konto`, `profil`, zgody, sesje, komentarze innych osób,
 *   relacje i decyzje moderacji nie są czytane wcale — nie ma więc nic, co
 *   mogłoby przypisać treść komuś innemu niż zalogowana osoba. Własność ustala
 *   `$user`, nie plik.
 * - Wpis lub przepis, który moderacja ukryła albo usunęła (`hidden`, `removed`),
 *   jest odrzucany: import nie ma przywracać treści przez tylne drzwi.
 *
 * Wartości pól są sprawdzane tymi samymi granicami co formularze
 * (`LimityTekstuPrzepisu`, `Recipe::MAX_*`, 4000 znaków wpisu, tytuł pytania
 * 10–180). Pozycja, która ich nie spełnia, jest w podglądzie oznaczona jako
 * odrzucona z powodem — reszta paczki działa dalej.
 */
final class PodgladPaczkiEksportu
{
    /** Sufit liczby wpisów w archiwum (przepisy, zdjęcia, strony HTML). */
    public const MAX_PLIKOW = 50_000;

    /** Sufit rozpakowanego `dane.json`. Konto z tysiącem przepisów to ok. 10 MB. */
    public const MAX_DANE_BAJTOW = 32 * 1024 * 1024;

    /** Głębokość JSON: sama paczka ma ich mniej niż dziesięć. */
    public const MAX_GLEBOKOSC_JSON = 16;

    /** Liczba pozycji w jednej sekcji (przepisy, wpisy, zeszyty). */
    public const MAX_POZYCJI = 5_000;

    public const MAX_WPIS_ZNAKOW = 4_000;

    public const MAX_ZESZYT_NAZWA = 120;

    public const MAX_ZESZYT_OPIS = 500;

    private const SERWIS = 'Kuking.pl';

    /** @var array<string, true> */
    private array $mojePrzepisy = [];

    /** @var array<string, true> */
    private array $mojeWpisy = [];

    /** @var array<string, true> */
    private array $mojeZeszyty = [];

    /**
     * @throws PaczkaOdrzucona gdy cała paczka jest nie do wczytania
     */
    public function czytaj(User $user, string $sciezkaZip): PodgladPaczki
    {
        $dane = $this->dane($this->odczytajDaneJson($sciezkaZip));

        $wersja = $this->sprawdzNaglowek($dane);

        foreach (['przepisy', 'wpisy', 'kolekcje'] as $sekcja) {
            $pozycje = $dane[$sekcja] ?? null;

            if (! is_array($pozycje) || ! array_is_list($pozycje)) {
                throw new PaczkaOdrzucona(
                    PaczkaOdrzucona::ZLA_STRUKTURA,
                    'W tym pliku brakuje części z danymi albo jest uszkodzona. Pobierz paczkę z Kuking jeszcze raz i wybierz ją ponownie.',
                );
            }

            if (count($pozycje) > self::MAX_POZYCJI) {
                throw new PaczkaOdrzucona(
                    PaczkaOdrzucona::ZA_DUZO_POZYCJI,
                    'W tej paczce jest zbyt wiele pozycji naraz — ponad '.self::MAX_POZYCJI.' w jednej części. Napisz do nas przez formularz kontaktowy, a pomożemy przenieść dane w częściach.',
                );
            }
        }

        $this->wczytajIstniejace($user);

        return new PodgladPaczki(
            wersjaFormatu: $wersja,
            wygenerowano: $this->wygenerowano($dane),
            przepisy: $this->przepisy($dane['przepisy']),
            wpisy: $this->wpisy($dane['wpisy']),
            zeszyty: $this->zeszyty($dane['kolekcje']),
            pominiete: self::POMINIETE,
        );
    }

    /**
     * Czego import celowo nie odtwarza. Zdania stoją w podglądzie ZAWSZE — także
     * przy paczce bez zdjęć — bo opisują regułę importu, nie tę jedną paczkę.
     */
    public const POWOD_PYTANIE = 'Pytań nie wczytujemy, bo pytanie w Poradźcie jest zawsze publiczne, a wszystko, co wczytujemy, zostaje prywatne. Jeśli chcesz je zachować, zadaj je jeszcze raz w Poradźcie.';

    public const POMINIETE = [
        'Wczytujemy sam tekst przepisów, własnych wpisów i nazwy zeszytów. Zdjęć z paczki na razie nie przenosimy.',
        'Wszystko, co wczytamy, będzie prywatne. O publikacji zdecydujesz osobno, po wczytaniu.',
        'Pytań z Poradźcie nie wczytujemy: pytanie jest zawsze publiczne, a wczytane treści mają zostać prywatne.',
        'Nie odtwarzamy konta, hasła, zgód, obserwowanych osób, powiadomień, komentarzy innych osób ani decyzji moderacji.',
        'Cudze przepisy i wpisy odłożone do zeszytu zostają w Kuking — w paczce jest z nich tylko tytuł, więc nie mamy czego wczytać.',
    ];

    // ---------------------------------------------------------------------
    // Plik
    // ---------------------------------------------------------------------

    private function odczytajDaneJson(string $sciezkaZip): string
    {
        if (! is_file($sciezkaZip) || ! is_readable($sciezkaZip)) {
            throw $this->nieZip();
        }

        $zip = new ZipArchive;

        if ($zip->open($sciezkaZip, ZipArchive::RDONLY) !== true) {
            throw $this->nieZip();
        }

        try {
            if ($zip->numFiles > self::MAX_PLIKOW) {
                throw new PaczkaOdrzucona(
                    PaczkaOdrzucona::ZA_DUZO_PLIKOW,
                    'To archiwum ma podejrzanie dużo plików, więc go nie wczytamy. Wybierz paczkę pobraną z Kuking bez zmian.',
                );
            }

            $indeksDanych = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nazwa = $zip->getNameIndex($i);

                if (! is_string($nazwa) || ! $this->bezpiecznaNazwa($nazwa)) {
                    throw new PaczkaOdrzucona(
                        PaczkaOdrzucona::NIEBEZPIECZNA_NAZWA,
                        'W tym archiwum jest plik o nazwie, której nie wolno wczytać, więc odrzucamy całą paczkę. Wybierz paczkę pobraną z Kuking bez zmian.',
                    );
                }

                if ($nazwa === 'dane.json') {
                    if ($indeksDanych !== null) {
                        throw new PaczkaOdrzucona(
                            PaczkaOdrzucona::NIEBEZPIECZNA_NAZWA,
                            'W tym archiwum jest dwa razy ten sam plik z danymi, więc nie wiemy, który jest prawdziwy. Wybierz paczkę pobraną z Kuking bez zmian.',
                        );
                    }

                    $indeksDanych = $i;
                }
            }

            if ($indeksDanych === null) {
                throw new PaczkaOdrzucona(
                    PaczkaOdrzucona::BRAK_DANYCH,
                    'W tym archiwum nie ma pliku „dane.json”. Wybierz paczkę pobraną z Kuking (ustawienia, „Twoje dane”), a nie własne zdjęcia ani inne archiwum.',
                );
            }

            $rozmiar = $zip->statIndex($indeksDanych)['size'] ?? null;

            if (! is_int($rozmiar) || $rozmiar > self::MAX_DANE_BAJTOW) {
                throw $this->zaDuzeDane();
            }

            $strumien = $zip->getStream('dane.json');

            if ($strumien === false) {
                throw $this->nieZip();
            }

            // Sufit na bajtach FAKTYCZNIE odczytanych: nagłówek ZIP mógł skłamać.
            $tresc = stream_get_contents($strumien, self::MAX_DANE_BAJTOW + 1);
            fclose($strumien);

            if (! is_string($tresc) || strlen($tresc) > self::MAX_DANE_BAJTOW) {
                throw $this->zaDuzeDane();
            }

            return $tresc;
        } finally {
            $zip->close();
        }
    }

    private function bezpiecznaNazwa(string $nazwa): bool
    {
        if ($nazwa === '' || str_contains($nazwa, "\0") || str_contains($nazwa, '\\')) {
            return false;
        }

        if (str_starts_with($nazwa, '/') || preg_match('/^[A-Za-z]:/', $nazwa) === 1) {
            return false;
        }

        foreach (explode('/', $nazwa) as $czesc) {
            if ($czesc === '..') {
                return false;
            }
        }

        return true;
    }

    private function nieZip(): PaczkaOdrzucona
    {
        return new PaczkaOdrzucona(
            PaczkaOdrzucona::NIE_ZIP,
            'Nie udało się otworzyć tego pliku. Wybierz plik ZIP pobrany z Kuking (ustawienia, „Twoje dane”) — bez rozpakowywania i bez zmian.',
        );
    }

    private function zaDuzeDane(): PaczkaOdrzucona
    {
        return new PaczkaOdrzucona(
            PaczkaOdrzucona::ZA_DUZE_DANE,
            'Plik z danymi w tym archiwum jest większy, niż potrafimy wczytać. Napisz do nas przez formularz kontaktowy, a pomożemy przenieść dane w częściach.',
        );
    }

    /** @return array<string, mixed> */
    private function dane(string $json): array
    {
        try {
            $dane = json_decode($json, true, self::MAX_GLEBOKOSC_JSON, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaczkaOdrzucona(
                $e->getCode() === JSON_ERROR_DEPTH ? PaczkaOdrzucona::ZLA_STRUKTURA : PaczkaOdrzucona::NIE_JSON,
                'Plik z danymi w tej paczce jest uszkodzony albo ktoś go zmienił, więc go nie wczytamy. Pobierz paczkę z Kuking jeszcze raz.',
            );
        }

        if (! is_array($dane) || array_is_list($dane)) {
            throw new PaczkaOdrzucona(
                PaczkaOdrzucona::ZLA_STRUKTURA,
                'Plik z danymi w tej paczce ma inny układ, niż daje Kuking. Pobierz paczkę z Kuking jeszcze raz.',
            );
        }

        return $dane;
    }

    /** @param array<string, mixed> $dane */
    private function sprawdzNaglowek(array $dane): int
    {
        $opis = $dane['o_tym_pliku'] ?? null;

        if (! is_array($opis) || ($opis['serwis'] ?? null) !== self::SERWIS) {
            throw new PaczkaOdrzucona(
                PaczkaOdrzucona::NIE_Z_KUKING,
                'To nie wygląda na paczkę z Kuking. Wybierz plik ZIP pobrany z Kuking (ustawienia, „Twoje dane”).',
            );
        }

        $wersja = $opis['wersja_formatu'] ?? WersjaFormatuPaczki::SPRZED_NUMEROWANIA;

        if (! is_int($wersja) || $wersja < 1) {
            throw new PaczkaOdrzucona(
                PaczkaOdrzucona::ZLA_STRUKTURA,
                'Plik z danymi w tej paczce ma uszkodzony opis wersji. Pobierz paczkę z Kuking jeszcze raz.',
            );
        }

        if ($wersja > WersjaFormatuPaczki::AKTUALNA) {
            throw new PaczkaOdrzucona(
                PaczkaOdrzucona::NOWSZY_FORMAT,
                'Ta paczka pochodzi z nowszej wersji Kuking, niż potrafimy wczytać. Pobierz ją jeszcze raz z serwisu, w którym ją utworzono, albo napisz do nas.',
            );
        }

        return $wersja;
    }

    /** @param array<string, mixed> $dane */
    private function wygenerowano(array $dane): ?string
    {
        $kiedy = $dane['o_tym_pliku']['wygenerowano'] ?? null;

        return is_string($kiedy) && mb_strlen($kiedy) <= 40 && ! str_contains($kiedy, "\0") ? $kiedy : null;
    }

    // ---------------------------------------------------------------------
    // To, co ta osoba już ma
    // ---------------------------------------------------------------------

    private function wczytajIstniejace(User $user): void
    {
        $this->mojePrzepisy = [];
        $this->mojeWpisy = [];
        $this->mojeZeszyty = [];

        // Tylko treść tej osoby (`author_id` / `owner_id` z zalogowanego konta),
        // bez skasowanych — skasowany przepis nie jest konfliktem.
        foreach (Recipe::query()->where('author_id', $user->getKey())->select('title')->cursor() as $przepis) {
            $this->mojePrzepisy[$this->klucz((string) $przepis->title)] = true;
        }

        foreach (Post::query()->where('author_id', $user->getKey())->select(['kind', 'title', 'body'])->cursor() as $wpis) {
            $this->mojeWpisy[$this->kluczWpisu((string) $wpis->kind, $wpis->title, $wpis->body)] = true;
        }

        foreach (Collection::query()->where('owner_id', $user->getKey())->select('name')->cursor() as $zeszyt) {
            $this->mojeZeszyty[$this->klucz((string) $zeszyt->name)] = true;
        }
    }

    // ---------------------------------------------------------------------
    // Przepisy
    // ---------------------------------------------------------------------

    /**
     * @param  list<mixed>  $pozycje
     * @return list<PozycjaPodgladu>
     */
    private function przepisy(array $pozycje): array
    {
        $wynik = [];
        $wPaczce = [];

        foreach ($pozycje as $numer => $p) {
            $etykieta = 'Przepis nr '.($numer + 1);

            if (! is_array($p)) {
                $wynik[] = $this->odrzucona('przepis', $etykieta, 'Ta pozycja ma nieznany układ.');

                continue;
            }

            $tytul = $this->tekst($p['tytul'] ?? null, 3, LimityTekstuPrzepisu::POLA['title']);

            if ($tytul === null) {
                $wynik[] = $this->odrzucona('przepis', $etykieta, 'Tytuł przepisu jest pusty, za krótki (najmniej 3 znaki) albo za długi (najwyżej '.LimityTekstuPrzepisu::POLA['title'].').');

                continue;
            }

            $powod = $this->powodOdrzuceniaPrzepisu($p);

            if ($powod !== null) {
                $wynik[] = $this->odrzucona('przepis', $tytul, $powod);

                continue;
            }

            $skladniki = $this->listaTekstowa($p['skladniki'] ?? [], 'zapis');
            $kroki = $this->listaTekstowa($p['kroki'] ?? [], 'opis');

            $odcisk = $this->odcisk(['przepis', $this->klucz($tytul), $skladniki, $kroki]);

            $wynik[] = $this->pozycja(
                'przepis',
                $tytul,
                $this->stanPrzepisu($tytul, $odcisk, $wPaczce),
                $this->uwagiPrzepisu($p),
                $odcisk,
                $this->danePrzepisu($p, $tytul),
            );
        }

        return $wynik;
    }

    /** @param array<mixed> $p */
    private function powodOdrzuceniaPrzepisu(array $p): ?string
    {
        if (in_array($p['status'] ?? null, [Recipe::STATUS_HIDDEN, Recipe::STATUS_REMOVED], true)) {
            return 'Ten przepis został ukryty albo usunięty przez moderację, więc go nie wczytujemy.';
        }

        if (! $this->opcjonalnyTekst($p['krotki_opis'] ?? null, LimityTekstuPrzepisu::POLA['summary'])) {
            return 'Krótki opis przepisu jest za długi (najwyżej '.LimityTekstuPrzepisu::POLA['summary'].' znaków).';
        }

        $skladniki = $p['skladniki'] ?? [];

        if (! is_array($skladniki) || ! array_is_list($skladniki)) {
            return 'Lista składników ma nieznany układ.';
        }

        if (count($skladniki) > Recipe::MAX_INGREDIENTS) {
            return 'Przepis ma za dużo składników (najwyżej '.Recipe::MAX_INGREDIENTS.').';
        }

        foreach ($skladniki as $s) {
            if (! is_array($s)) {
                return 'Lista składników ma nieznany układ.';
            }

            if ($this->tekst($s['zapis'] ?? null, 1, LimityTekstuPrzepisu::POLA['ingredients.*.text']) === null) {
                return 'Któryś składnik jest pusty albo dłuższy niż '.LimityTekstuPrzepisu::POLA['ingredients.*.text'].' znaków.';
            }

            foreach ([
                'grupa' => 'ingredients.*.group_name',
                'uwaga' => 'ingredients.*.note',
                'zamienniki' => 'ingredients.*.substitutes',
            ] as $klucz => $pole) {
                if (! $this->opcjonalnyTekst($s[$klucz] ?? null, LimityTekstuPrzepisu::POLA[$pole])) {
                    return 'Przy którymś składniku pole „'.$klucz.'” jest za długie (najwyżej '.LimityTekstuPrzepisu::POLA[$pole].' znaków).';
                }
            }
        }

        $kroki = $p['kroki'] ?? [];

        if (! is_array($kroki) || ! array_is_list($kroki)) {
            return 'Lista kroków ma nieznany układ.';
        }

        if (count($kroki) > Recipe::MAX_STEPS) {
            return 'Przepis ma za dużo kroków (najwyżej '.Recipe::MAX_STEPS.').';
        }

        foreach ($kroki as $krok) {
            if (! is_array($krok)
                || $this->tekst($krok['opis'] ?? null, 1, LimityTekstuPrzepisu::POLA['steps.*.instruction']) === null) {
                return 'Któryś krok jest pusty albo dłuższy niż '.LimityTekstuPrzepisu::POLA['steps.*.instruction'].' znaków.';
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $p
     * @return list<string>
     */
    private function uwagiPrzepisu(array $p): array
    {
        $uwagi = [];

        if (($p['status'] ?? null) === Recipe::STATUS_PUBLISHED || ($p['widocznosc'] ?? null) === 'public') {
            $uwagi[] = 'W paczce był opublikowany. Po wczytaniu będzie prywatnym szkicem.';
        }

        if ($this->maZdjecie($p)) {
            $uwagi[] = 'Zdjęć nie wczytujemy — tekst przepisu tak.';
        }

        return $uwagi;
    }

    /** @param array<mixed> $p */
    private function maZdjecie(array $p): bool
    {
        if (! empty($p['zdjecie_glowne']) || ! empty($p['skan_zeszytu'])) {
            return true;
        }

        $kroki = $p['kroki'] ?? [];

        foreach (is_array($kroki) ? $kroki : [] as $krok) {
            if (is_array($krok) && ! empty($krok['zdjecie'])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, true> $wPaczce */
    private function stanPrzepisu(string $tytul, string $odcisk, array &$wPaczce): string
    {
        // Powtórzenie w samej paczce rozpoznaje ODCISK (cała treść): dwa przepisy
        // „Sernik” z różnymi składnikami to dwa przepisy, nie duplikat.
        if (isset($wPaczce[$odcisk])) {
            return PozycjaPodgladu::POWTORZONA_W_PACZCE;
        }

        $wPaczce[$odcisk] = true;

        return isset($this->mojePrzepisy[$this->klucz($tytul)])
            ? PozycjaPodgladu::JUZ_JEST
            : PozycjaPodgladu::NOWA;
    }

    // ---------------------------------------------------------------------
    // Wpisy
    // ---------------------------------------------------------------------

    /**
     * @param  list<mixed>  $pozycje
     * @return list<PozycjaPodgladu>
     */
    private function wpisy(array $pozycje): array
    {
        $wynik = [];
        $wPaczce = [];

        foreach ($pozycje as $numer => $p) {
            $etykieta = 'Wpis nr '.($numer + 1);

            if (! is_array($p)) {
                $wynik[] = $this->odrzucona('wpis', $etykieta, 'Ta pozycja ma nieznany układ.');

                continue;
            }

            $rodzaj = $p['rodzaj'] ?? null;

            if (! in_array($rodzaj, [Post::KIND_DISH, Post::KIND_QUESTION], true)) {
                $wynik[] = $this->odrzucona('wpis', $etykieta, 'Nie znamy tego rodzaju wpisu.');

                continue;
            }

            if ($rodzaj === Post::KIND_QUESTION) {
                // Pytanie w Poradźcie jest z definicji publiczne (D-221; `PublishPost` odmawia
                // pytania niepublicznego), a wszystko, co wczytujemy, ma być prywatne (#1985).
                // Nie ma decyzji o prywatnych pytaniach, więc ich nie tworzymy.
                $tytulPytania = $this->tekst($p['tytul'] ?? null, 1, 180) ?? $etykieta;
                $wynik[] = $this->odrzucona('wpis', $tytulPytania, self::POWOD_PYTANIE);

                continue;
            }

            if (in_array($p['status'] ?? null, [Post::STATUS_HIDDEN, Post::STATUS_REMOVED], true)) {
                $wynik[] = $this->odrzucona('wpis', $etykieta, 'Ten wpis został ukryty albo usunięty przez moderację, więc go nie wczytujemy.');

                continue;
            }

            $tresc = $p['tresc'] ?? null;

            if (! $this->opcjonalnyTekst($tresc, self::MAX_WPIS_ZNAKOW)) {
                $wynik[] = $this->odrzucona('wpis', $etykieta, 'Treść wpisu jest za długa (najwyżej '.self::MAX_WPIS_ZNAKOW.' znaków).');

                continue;
            }

            $tresc = is_string($tresc) && trim($tresc) !== '' ? trim($tresc) : null;
            $tytul = null; // pytań nie wczytujemy, więc wpis do wczytania to zawsze danie bez tytułu

            if ($tresc === null) {
                $wynik[] = $this->odrzucona('wpis', $etykieta, 'Ten wpis to samo zdjęcie, a zdjęć nie wczytujemy — nie ma tu tekstu do zapisania.');

                continue;
            }

            $odcisk = $this->odcisk(['wpis', $rodzaj, $tytul === null ? null : $this->klucz($tytul), $tresc === null ? null : $this->klucz($tresc)]);
            $klucz = $this->kluczWpisu($rodzaj, $tytul, $tresc);

            $stan = isset($wPaczce[$klucz])
                ? PozycjaPodgladu::POWTORZONA_W_PACZCE
                : (isset($this->mojeWpisy[$klucz]) ? PozycjaPodgladu::JUZ_JEST : PozycjaPodgladu::NOWA);
            $wPaczce[$klucz] = true;

            $uwagi = [];

            if (($p['status'] ?? null) === Post::STATUS_PUBLISHED || ($p['widocznosc'] ?? null) === Post::VISIBILITY_PUBLIC) {
                $uwagi[] = 'W paczce był opublikowany. Po wczytaniu będzie prywatny.';
            }

            if (! empty($p['zdjecia'])) {
                $uwagi[] = 'Zdjęć nie wczytujemy — tekst wpisu tak.';
            }

            $wynik[] = $this->pozycja('wpis', $tytul ?? mb_strimwidth((string) $tresc, 0, 80, '…'), $stan, $uwagi, $odcisk, [
                'rodzaj' => $rodzaj,
                'tytul' => $tytul,
                'tresc' => $tresc,
            ]);
        }

        return $wynik;
    }

    // ---------------------------------------------------------------------
    // Zeszyty
    // ---------------------------------------------------------------------

    /**
     * @param  list<mixed>  $pozycje
     * @return list<PozycjaPodgladu>
     */
    private function zeszyty(array $pozycje): array
    {
        $wynik = [];
        $wPaczce = [];

        foreach ($pozycje as $numer => $p) {
            $etykieta = 'Zeszyt nr '.($numer + 1);

            if (! is_array($p)) {
                $wynik[] = $this->odrzucona('zeszyt', $etykieta, 'Ta pozycja ma nieznany układ.');

                continue;
            }

            $nazwa = $this->tekst($p['nazwa'] ?? null, 2, self::MAX_ZESZYT_NAZWA);

            if ($nazwa === null) {
                $wynik[] = $this->odrzucona('zeszyt', $etykieta, 'Nazwa zeszytu musi mieć od 2 do '.self::MAX_ZESZYT_NAZWA.' znaków.');

                continue;
            }

            if (! $this->opcjonalnyTekst($p['opis'] ?? null, self::MAX_ZESZYT_OPIS)) {
                $wynik[] = $this->odrzucona('zeszyt', $nazwa, 'Opis zeszytu jest za długi (najwyżej '.self::MAX_ZESZYT_OPIS.' znaków).');

                continue;
            }

            $odcisk = $this->odcisk(['zeszyt', $this->klucz($nazwa)]);
            $klucz = $this->klucz($nazwa);
            $uwagi = [];

            if (($p['domyslna'] ?? false) === true) {
                // Każde konto ma swój domyślny zeszyt — nie zakładamy drugiego.
                $stan = PozycjaPodgladu::JUZ_JEST;
                $uwagi[] = 'To zeszyt domyślny — Ty też masz swój, więc nie tworzymy nowego.';
            } elseif (isset($wPaczce[$klucz])) {
                $stan = PozycjaPodgladu::POWTORZONA_W_PACZCE;
            } elseif (isset($this->mojeZeszyty[$klucz])) {
                $stan = PozycjaPodgladu::JUZ_JEST;
            } else {
                $stan = PozycjaPodgladu::NOWA;
            }

            $wPaczce[$klucz] = true;

            if (($p['widocznosc'] ?? null) === 'public') {
                $uwagi[] = 'W paczce był publiczny. Po wczytaniu będzie prywatny.';
            }

            $opis = is_string($p['opis'] ?? null) && trim($p['opis']) !== '' ? trim($p['opis']) : null;

            $wynik[] = $this->pozycja('zeszyt', $nazwa, $stan, $uwagi, $odcisk, ['nazwa' => $nazwa, 'opis' => $opis]);
        }

        return $wynik;
    }

    // ---------------------------------------------------------------------
    // Drobiazgi
    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $uwagi
     * @param  array<string, mixed>  $dane
     */
    private function pozycja(string $rodzaj, string $tytul, string $stan, array $uwagi, string $odcisk, array $dane = []): PozycjaPodgladu
    {
        return new PozycjaPodgladu($rodzaj, $tytul, $stan, null, $uwagi, $odcisk, $dane);
    }

    /**
     * Treść przepisu do utworzenia — WYŁĄCZNIE pola, które podgląd sprawdził
     * (te same granice co formularz). Ilości, jednostki, czasy, źródło i zdjęcia
     * z paczki nie są czytane: nie ma czego przypisać komuś innemu ani pobrać z sieci.
     *
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    private function danePrzepisu(array $p, string $tytul): array
    {
        $skladniki = [];

        foreach ($p['skladniki'] ?? [] as $s) {
            $skladnik = ['text' => $this->tekst($s['zapis'] ?? null, 1, LimityTekstuPrzepisu::POLA['ingredients.*.text'])];

            foreach (['grupa' => 'group_name', 'uwaga' => 'note', 'zamienniki' => 'substitutes'] as $klucz => $pole) {
                $wartosc = is_string($s[$klucz] ?? null) ? trim($s[$klucz]) : '';
                $skladnik[$pole] = $wartosc === '' ? null : $wartosc;
            }

            $skladniki[] = $skladnik;
        }

        $kroki = [];

        foreach ($p['kroki'] ?? [] as $krok) {
            $kroki[] = ['instruction' => $this->tekst($krok['opis'] ?? null, 1, LimityTekstuPrzepisu::POLA['steps.*.instruction'])];
        }

        $opis = is_string($p['krotki_opis'] ?? null) && trim($p['krotki_opis']) !== '' ? trim($p['krotki_opis']) : null;

        return ['tytul' => $tytul, 'opis' => $opis, 'skladniki' => $skladniki, 'kroki' => $kroki];
    }

    private function odrzucona(string $rodzaj, string $tytul, string $powod): PozycjaPodgladu
    {
        return new PozycjaPodgladu($rodzaj, $tytul, PozycjaPodgladu::ODRZUCONA, $powod, [], $this->odcisk(['odrzucona', $rodzaj, $tytul]));
    }

    /** Tekst niepusty po przycięciu, o długości w granicach; inaczej `null`. */
    private function tekst(mixed $wartosc, int $min, int $max): ?string
    {
        if (! is_string($wartosc) || str_contains($wartosc, "\0")) {
            return null;
        }

        $wartosc = trim($wartosc);
        $dlugosc = mb_strlen($wartosc);

        return $dlugosc >= $min && $dlugosc <= $max ? $wartosc : null;
    }

    /** `null` albo tekst nieprzekraczający granicy, bez bajtu zerowego. */
    private function opcjonalnyTekst(mixed $wartosc, int $max): bool
    {
        if ($wartosc === null) {
            return true;
        }

        return is_string($wartosc) && ! str_contains($wartosc, "\0") && mb_strlen(trim($wartosc)) <= $max;
    }

    /**
     * @return list<string>
     */
    private function listaTekstowa(mixed $lista, string $pole): array
    {
        $wynik = [];

        foreach (is_array($lista) ? $lista : [] as $element) {
            if (is_array($element) && is_string($element[$pole] ?? null)) {
                $wynik[] = $this->klucz($element[$pole]);
            }
        }

        return $wynik;
    }

    /** Ujednolicenie do porównań: małe litery, pojedyncze odstępy. */
    private function klucz(string $tekst): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($tekst)));
    }

    private function kluczWpisu(string $rodzaj, mixed $tytul, mixed $tresc): string
    {
        return $rodzaj.'|'.($tytul === null ? '' : $this->klucz((string) $tytul)).'|'.($tresc === null ? '' : $this->klucz((string) $tresc));
    }

    /** @param  array<int, mixed>  $czesci */
    private function odcisk(array $czesci): string
    {
        return hash('sha256', json_encode($czesci, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
