<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

use App\Support\Czas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Archiwum opublikowanych wersji regulaminu (#2220, kryterium 4; decyzja
 * właściciela z 30.09.2026 „Budujemy”, wiersz w D-333).
 *
 * BEZ SILNIKA I BEZ BAZY. Każda wersja to zwykły plik w repozytorium:
 * `resources/legal/archiwum/regulamin-RRRR-MM-DD.md`, gdzie data to ta sama
 * data, którą niesie nagłówek dokumentu („opisuje stan serwisu na …”),
 * `kuking.zgody.wersja_regulaminu` i kolumna `dziennik_zgod.wersja_regulaminu`
 * (#2217). Dzięki temu wiersz dziennika „zaakceptował 2026-09-30” prowadzi
 * wprost do adresu `/regulamin/wersje/2026-09-30` — do słowa, które ta osoba
 * mogła wtedy przeczytać.
 *
 * Plik bieżącej wersji jest DOKŁADNĄ kopią `resources/legal/regulamin.md`
 * (pilnuje `ArchiwumRegulaminuTest`). Przy każdej zmianie regulaminu:
 *  - zmiana z nową datą — dodaj nowy plik z tą datą, starych nie ruszaj;
 *  - poprawka bez nowej daty — popraw też plik bieżącej wersji.
 * Pliki starszych wersji są zamrożone: to dowód, jak regulamin brzmiał.
 */
final class ArchiwumRegulaminu
{
    public const KATALOG = 'legal/archiwum';

    private const WZORZEC_PLIKU = '/^regulamin-(\d{4}-\d{2}-\d{2})\.md$/';

    /**
     * Daty wszystkich zachowanych wersji, od najnowszej.
     *
     * @return list<string>
     */
    public static function wersje(): array
    {
        $daty = [];

        foreach (glob(resource_path(self::KATALOG.'/regulamin-*.md')) ?: [] as $plik) {
            if (preg_match(self::WZORZEC_PLIKU, basename($plik), $dopasowanie) === 1 && self::poprawnaData($dopasowanie[1])) {
                $daty[] = $dopasowanie[1];
            }
        }

        rsort($daty);

        return $daty;
    }

    /** Ścieżka pliku wersji albo null, gdy takiej wersji nie ma w archiwum. */
    public static function plik(string $data): ?string
    {
        if (! self::poprawnaData($data)) {
            return null;
        }

        $sciezka = resource_path(self::KATALOG."/regulamin-{$data}.md");

        return is_file($sciezka) ? $sciezka : null;
    }

    public static function tresc(string $data): ?string
    {
        $plik = self::plik($data);

        return $plik === null ? null : (string) file_get_contents($plik);
    }

    /** Wersja, która wisi dziś pod `/regulamin` (data z konfiguracji). */
    public static function biezaca(): string
    {
        return WersjaDokumentu::regulamin()->opublikowana;
    }

    /**
     * Zdanie nad treścią wersji: czy obowiązuje. Przy zmianie istotnej
     * (D-327) nowy tekst wisi już pod `/regulamin`, a do dnia wejścia
     * w życie obowiązuje poprzedni — wtedy „obecna” nie znaczy „obowiązująca”.
     */
    public static function opisWersji(string $data, ?CarbonInterface $chwila = null): string
    {
        $wersja = WersjaDokumentu::regulamin();
        $slownie = self::dataSlownie($data);
        $obowiazujaca = $wersja->obowiazujaca($chwila);

        if ($data === $wersja->opublikowana && $data === $obowiazujaca) {
            return "To jest obecna wersja regulaminu, z {$slownie}. Obowiązuje.";
        }

        $odDnia = $wersja->obowiazujeOd()->locale('pl')->isoFormat('D MMMM YYYY');

        if ($data === $wersja->opublikowana) {
            return "To jest nowa wersja regulaminu, z {$slownie}. Zacznie obowiązywać {$odDnia}.";
        }

        if ($data === $obowiazujaca) {
            $doDnia = $wersja->obowiazujeOd()->subDay()->locale('pl')->isoFormat('D MMMM YYYY');

            return "To jest wersja regulaminu z {$slownie}. Obowiązuje do {$doDnia} włącznie, a od {$odDnia} zastąpi ją nowa wersja.";
        }

        return "To jest wcześniejsza wersja regulaminu, z {$slownie}. Już nie obowiązuje.";
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
