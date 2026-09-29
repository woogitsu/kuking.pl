<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

use App\Support\Czas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Wersja dokumentu prawnego: data publikacji osobno od daty wejścia w życie
 * (D-327, decyzja właściciela z 26 września 2026).
 *
 * - `opublikowana` — data z nagłówka dokumentu („opisuje stan serwisu na …”),
 *   czyli `kuking.zgody.wersja_polityki` / `wersja_regulaminu`. Od tego dnia
 *   tekst wisi na stronie, a pasek o zmianie się pokazuje;
 * - `istotna` — JAWNE oznaczenie w `kuking.zgody.zmiana_*.istotna`. Bez
 *   wartości domyślnej: brak klucza albo coś innego niż true/false to
 *   wyjątek, bo „zapomniałem oznaczyć” nie może po cichu znaczyć „drobna”;
 * - zmiana ISTOTNA obowiązuje od `opublikowana` + 14 dni
 *   (`kuking.zgody.okres_istotnej_zmiany_dni`), a do tego dnia obowiązuje
 *   `poprzednia`. Wolno przesunąć termin później (`obowiazuje_od`), nigdy
 *   wcześniej;
 * - zmiana DROBNA (redakcyjna, bez zmiany praw i obowiązków) obowiązuje od
 *   dnia publikacji.
 *
 * Wszystko, co zapisuje „na jaką wersję ktoś się zgodził” (dziennik zgód,
 * D-072), bierze `obowiazujaca()`, nie `opublikowana`.
 */
final class WersjaDokumentu
{
    private function __construct(
        public readonly string $opublikowana,
        public readonly bool $istotna,
        public readonly ?string $poprzednia,
        private readonly ?string $obowiazujeOdWKonfiguracji,
        private readonly string $klucz,
    ) {}

    public static function polityka(): self
    {
        return self::zKonfiguracji('polityki');
    }

    public static function regulamin(): self
    {
        return self::zKonfiguracji('regulaminu');
    }

    public static function okresIstotnejZmianyDni(): int
    {
        return (int) config('kuking.zgody.okres_istotnej_zmiany_dni');
    }

    private static function zKonfiguracji(string $dokument): self
    {
        $klucz = "kuking.zgody.zmiana_{$dokument}";
        $zmiana = config($klucz);

        if (! is_array($zmiana) || ! array_key_exists('istotna', $zmiana) || ! is_bool($zmiana['istotna'])) {
            throw new RuntimeException(
                "Oznacz zmianę jawnie: ustaw {$klucz}.istotna na true (zmiana istotna, wchodzi w życie po "
                .self::okresIstotnejZmianyDni().' dniach od publikacji) albo false (drobna poprawka bez zmiany praw i obowiązków, wchodzi od razu).',
            );
        }

        $poprzednia = self::tekstLubNull($zmiana['poprzednia'] ?? null);
        $obowiazujeOd = self::tekstLubNull($zmiana['obowiazuje_od'] ?? null);

        if ($zmiana['istotna'] && $poprzednia === null) {
            throw new RuntimeException(
                "Zmiana istotna potrzebuje {$klucz}.poprzednia: do dnia wejścia w życie obowiązuje poprzednia wersja i trzeba wiedzieć, która.",
            );
        }

        if (! $zmiana['istotna'] && $obowiazujeOd !== null) {
            throw new RuntimeException(
                "Drobna poprawka wchodzi w życie w dniu publikacji. Usuń {$klucz}.obowiazuje_od albo oznacz zmianę jako istotną.",
            );
        }

        return new self(
            (string) config("kuking.zgody.wersja_{$dokument}"),
            $zmiana['istotna'],
            $poprzednia,
            $obowiazujeOd,
            $klucz,
        );
    }

    private static function tekstLubNull(mixed $wartosc): ?string
    {
        return is_string($wartosc) && trim($wartosc) !== '' ? trim($wartosc) : null;
    }

    public function obowiazujeOd(): CarbonImmutable
    {
        $publikacja = CarbonImmutable::parse($this->opublikowana, Czas::strefa())->startOfDay();

        if (! $this->istotna) {
            return $publikacja;
        }

        $najwczesniej = $publikacja->addDays(self::okresIstotnejZmianyDni());

        if ($this->obowiazujeOdWKonfiguracji === null) {
            return $najwczesniej;
        }

        $jawnie = CarbonImmutable::parse($this->obowiazujeOdWKonfiguracji, Czas::strefa())->startOfDay();

        if ($jawnie->lessThan($najwczesniej)) {
            throw new RuntimeException(
                "{$this->klucz}.obowiazuje_od wypada wcześniej niż ".self::okresIstotnejZmianyDni()
                .' dni po publikacji. Zmiana istotna nie może wejść w życie szybciej — przesuń datę albo usuń klucz.',
            );
        }

        return $jawnie;
    }

    /** Nowy tekst już wisi, ale jeszcze nie obowiązuje. Tylko przy zmianie istotnej. */
    public function wOkresiePrzejsciowym(?CarbonInterface $chwila = null): bool
    {
        // Drobna zmiana nie parsuje dat w ogóle: obowiązuje od publikacji.
        if (! $this->istotna) {
            return false;
        }

        return ($chwila ?? now())->lessThan($this->obowiazujeOd());
    }

    /** Wersja, która obowiązuje w danej chwili — tę zapisujemy przy zgodzie. */
    public function obowiazujaca(?CarbonInterface $chwila = null): string
    {
        return $this->wOkresiePrzejsciowym($chwila) ? (string) $this->poprzednia : $this->opublikowana;
    }
}
