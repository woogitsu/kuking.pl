<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

use App\Support\Czas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Archiwum opublikowanych wersji dokumentu prawnego: regulaminu i polityki
 * prywatności (#2220, kryterium 4; decyzje właściciela z 30.09.2026
 * „Budujemy” i „archiwum polityki tylko z czystą historią”, wiersze w D-333).
 *
 * BEZ SILNIKA I BEZ BAZY. Każda wersja to zwykły plik w repozytorium:
 * `resources/legal/archiwum/<dokument>-RRRR-MM-DD.md`, gdzie data to ta sama
 * data, którą niesie nagłówek dokumentu („opisuje stan serwisu na …”),
 * `kuking.zgody.wersja_regulaminu` / `wersja_polityki` i kolumny
 * `dziennik_zgod.wersja_regulaminu` / `wersja_polityki` (#2217, D-072).
 * Dzięki temu wiersz dziennika „zaakceptował 2026-09-30” prowadzi wprost do
 * adresu `/regulamin/wersje/2026-09-30` (albo `/prywatnosc/wersje/…`) — do
 * słowa, które ta osoba mogła wtedy przeczytać.
 *
 * Plik bieżącej wersji jest DOKŁADNĄ kopią `resources/legal/<dokument>.md`
 * (pilnują `ArchiwumRegulaminuTest` i `ArchiwumPolitykiTest`). Przy każdej
 * zmianie dokumentu:
 *  - zmiana z nową datą — dodaj nowy plik z tą datą, starych nie ruszaj;
 *  - poprawka bez nowej daty — popraw też plik bieżącej wersji.
 * Pliki starszych wersji są zamrożone: to dowód, jak dokument brzmiał.
 *
 * POLITYKA: archiwum zaczyna się od 25 września 2026. Wcześniejsze daty mają
 * w historii gita niespójne nagłówki (np. „11 września” i „24 września” przy
 * tej samej `wersja_polityki` = 2026-09-10), więc nie da się uczciwie
 * powiedzieć, które brzmienie należało do której daty. Starsze brzmienia
 * wydajemy na prośbę (`starszeNaProsbe`), kanałem, który polityka już podaje.
 */
final class ArchiwumDokumentu
{
    public const KATALOG = 'legal/archiwum';

    public const REGULAMIN = 'regulamin';

    public const POLITYKA = 'polityka-prywatnosci';

    /**
     * @param  string  $slug  nazwa pliku bez `.md` w `resources/legal/` i przedrostek w archiwum
     * @param  string  $trasa  przedrostek nazw tras (`terms`, `privacy`)
     * @param  string  $dopelniacz  „wersja regulaminu”, „wersja polityki prywatności”
     * @param  string  $dopelniaczKrotko  „Wszystkie wersje polityki”
     * @param  string  $biernikKrotko  „Pobierz politykę”
     * @param  string  $mianownik  „Regulamin”, „Polityka prywatności” — tytuł strony
     * @param  bool  $starszeNaProsbe  wersje sprzed najstarszego pliku wydajemy na prośbę
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $trasa,
        public readonly string $dopelniacz,
        public readonly string $dopelniaczKrotko,
        public readonly string $biernikKrotko,
        public readonly string $mianownik,
        public readonly bool $starszeNaProsbe,
    ) {}

    public static function regulamin(): self
    {
        return new self(self::REGULAMIN, 'terms', 'regulaminu', 'regulaminu', 'regulamin', 'Regulamin', false);
    }

    public static function polityka(): self
    {
        return new self(self::POLITYKA, 'privacy', 'polityki prywatności', 'polityki', 'politykę', 'Polityka prywatności', true);
    }

    /** Archiwum po nazwie z trasy (`->defaults('dokument', …)`). */
    public static function dla(string $dokument): self
    {
        return match ($dokument) {
            self::REGULAMIN => self::regulamin(),
            self::POLITYKA => self::polityka(),
            default => throw new InvalidArgumentException("Nie ma archiwum dokumentu „{$dokument}”."),
        };
    }

    /**
     * Daty wszystkich zachowanych wersji, od najnowszej.
     *
     * @return list<string>
     */
    public function wersje(): array
    {
        $daty = [];
        $wzorzec = '/^'.preg_quote($this->slug, '/').'-(\d{4}-\d{2}-\d{2})\.md$/';

        foreach (glob(resource_path(self::KATALOG."/{$this->slug}-*.md")) ?: [] as $sciezka) {
            if (preg_match($wzorzec, basename($sciezka), $dopasowanie) === 1 && self::poprawnaData($dopasowanie[1])) {
                $daty[] = $dopasowanie[1];
            }
        }

        rsort($daty);

        return $daty;
    }

    /** Najstarsza wersja, która ma plik w archiwum. */
    public function najstarsza(): ?string
    {
        $wersje = $this->wersje();

        return $wersje === [] ? null : $wersje[array_key_last($wersje)];
    }

    /**
     * Data jest poprawna, pliku nie ma, a leży przed początkiem archiwum —
     * tę wersję wydajemy na prośbę (tylko polityka, patrz opis klasy).
     */
    public function wydawanaNaProsbe(string $data): bool
    {
        $najstarsza = $this->najstarsza();

        return $this->starszeNaProsbe
            && $najstarsza !== null
            && self::poprawnaData($data)
            && $data < $najstarsza;
    }

    /** Ścieżka pliku wersji albo null, gdy takiej wersji nie ma w archiwum. */
    public function plik(string $data): ?string
    {
        if (! self::poprawnaData($data)) {
            return null;
        }

        $sciezka = resource_path(self::KATALOG."/{$this->slug}-{$data}.md");

        return is_file($sciezka) ? $sciezka : null;
    }

    public function tresc(string $data): ?string
    {
        $sciezka = $this->plik($data);

        return $sciezka === null ? null : (string) file_get_contents($sciezka);
    }

    /** Ścieżka dokumentu, który wisi dziś na stronie (`resources/legal/<dokument>.md`). */
    public function sciezkaBiezacegoDokumentu(): string
    {
        return resource_path("legal/{$this->slug}.md");
    }

    /** Nazwa pobieranego pliku, np. `polityka-prywatnosci-kuking-2026-09-30.txt`. */
    public function nazwaPobieranegoPliku(string $data): string
    {
        return "{$this->slug}-kuking-{$data}.txt";
    }

    /** Wersja, która wisi dziś na stronie dokumentu (data z konfiguracji). */
    public function biezaca(): string
    {
        return $this->wersjaDokumentu()->opublikowana;
    }

    public function wersjaDokumentu(): WersjaDokumentu
    {
        return $this->slug === self::REGULAMIN ? WersjaDokumentu::regulamin() : WersjaDokumentu::polityka();
    }

    /**
     * Zdanie nad treścią wersji: czy obowiązuje. Przy zmianie istotnej
     * (D-327) nowy tekst wisi już na stronie, a do dnia wejścia w życie
     * obowiązuje poprzedni — wtedy „obecna” nie znaczy „obowiązująca”.
     */
    public function opisWersji(string $data, ?CarbonInterface $chwila = null): string
    {
        $wersja = $this->wersjaDokumentu();
        $slownie = self::dataSlownie($data);
        $obowiazujaca = $wersja->obowiazujaca($chwila);

        if ($data === $wersja->opublikowana && $data === $obowiazujaca) {
            return "To jest obecna wersja {$this->dopelniacz}, z {$slownie}. Obowiązuje.";
        }

        $odDnia = $wersja->obowiazujeOd()->locale('pl')->isoFormat('D MMMM YYYY');

        if ($data === $wersja->opublikowana) {
            return "To jest nowa wersja {$this->dopelniacz}, z {$slownie}. Zacznie obowiązywać {$odDnia}.";
        }

        if ($data === $obowiazujaca) {
            $doDnia = $wersja->obowiazujeOd()->subDay()->locale('pl')->isoFormat('D MMMM YYYY');

            return "To jest wersja {$this->dopelniacz} z {$slownie}. Obowiązuje do {$doDnia} włącznie, a od {$odDnia} zastąpi ją nowa wersja.";
        }

        return "To jest wcześniejsza wersja {$this->dopelniacz}, z {$slownie}. Już nie obowiązuje.";
    }

    /**
     * Plakietka przy wersji na liście wszystkich wersji. „Obowiązuje” nosi
     * wersja, która obowiązuje TERAZ (przy zmianie istotnej to jeszcze
     * poprzednia); wersja opublikowana przed terminem wejścia w życie to
     * „Nowa — od {data}”. Dawniej obie dostawały „Obecna”, co przy okresie
     * przejściowym mówiło nieprawdę o wersji, która jeszcze nie obowiązuje.
     */
    public function plakietkaWersji(string $data, ?CarbonInterface $chwila = null): ?string
    {
        $wersja = $this->wersjaDokumentu();

        if ($data === $wersja->obowiazujaca($chwila)) {
            return 'Obowiązuje';
        }

        if ($data === $wersja->opublikowana) {
            return 'Nowa — od '.$wersja->obowiazujeOd()->locale('pl')->isoFormat('D MMMM YYYY');
        }

        return null;
    }

    /** „30 września 2026” — tak, jak data stoi w nagłówku dokumentu. */
    public static function dataSlownie(string $data): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $data, Czas::strefa())
            ->locale('pl')
            ->isoFormat('D MMMM YYYY');
    }

    private static function poprawnaData(string $data): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) !== 1) {
            return false;
        }

        [$rok, $miesiac, $dzien] = array_map('intval', explode('-', $data));

        return checkdate($miesiac, $dzien, $rok);
    }
}
