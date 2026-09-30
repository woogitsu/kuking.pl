<?php

declare(strict_types=1);

namespace App\Domain\UgotujmyRazem;

use App\Support\Czas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Tydzień ISO „Ugotujmy razem” (F3) — od poniedziałku 00:00 do następnego
 * poniedziałku 00:00 w strefie `kuking.strefa` (Europe/Warsaw).
 *
 * DLACZEGO NIE W UTC
 * Baza i aplikacja liczą w UTC (`Czas`). Gdyby tydzień liczyć tam, niedzielny
 * obiad zjedzony w Polsce o 23:30 wpadałby latem do NASTĘPNEGO tygodnia
 * wspólnego gotowania, a poniedziałkowy o 0:30 — do POPRZEDNIEGO. Granicę
 * pilnuje `UgotujmyRazemTest::test_granica_tygodnia_liczy_sie_w_polskiej_strefie`.
 *
 * Adres tygodnia to jego zapis ISO, np. `2026-W40` — czytelny, stały
 * i nieprzewidywalny tylko w tym sensie, w jakim nieprzewidywalny jest
 * kalendarz. To NIE jest autoryzacja: wejście na tydzień przechodzi przez
 * `WeeklyRecipePickPolicy::view`.
 */
final class TydzienGotowania
{
    private function __construct(private readonly CarbonImmutable $poniedzialek) {}

    /** Tydzień, w którym leży ten moment — w polskim kalendarzu. */
    public static function zawierajacy(CarbonInterface $moment): self
    {
        $lokalnie = CarbonImmutable::instance($moment)->setTimezone(Czas::strefa());

        return new self($lokalnie->startOfWeek(CarbonInterface::MONDAY)->startOfDay());
    }

    public static function biezacy(): self
    {
        return self::zawierajacy(now());
    }

    /** Tydzień zaczynający się w tym dniu (kolumna `week_starts_on`). */
    public static function odDnia(CarbonInterface|string $dzien): self
    {
        $data = $dzien instanceof CarbonInterface ? $dzien->toDateString() : $dzien;

        return self::zawierajacy(CarbonImmutable::createFromFormat('!Y-m-d', $data, Czas::strefa()));
    }

    /** `2026-W40` → tydzień albo `null`, gdy zapis nie jest tygodniem ISO. */
    public static function zIso(string $zapis): ?self
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $zapis, $m) !== 1) {
            return null;
        }

        $rok = (int) $m[1];
        $numer = (int) $m[2];
        $poniedzialek = CarbonImmutable::create($rok, 1, 4, 0, 0, 0, Czas::strefa())
            ->setISODate($rok, $numer, 1)
            ->startOfDay();

        // `setISODate` przyjmuje też tydzień 53 roku, który go nie ma, i po
        // cichu przesuwa się na następny rok. Zapis, który nie wraca w tej
        // samej postaci, nie jest tygodniem.
        $tydzien = new self($poniedzialek);

        return $tydzien->iso() === $zapis ? $tydzien : null;
    }

    public function iso(): string
    {
        return $this->poniedzialek->isoFormat('GGGG-[W]WW');
    }

    /** Dzień do kolumny `week_starts_on`. */
    public function dzienStartu(): string
    {
        return $this->poniedzialek->toDateString();
    }

    /** Początek tygodnia jako moment (UTC) — do porównań z `cooked_at`. */
    public function poczatek(): CarbonImmutable
    {
        return $this->poniedzialek->utc();
    }

    /** Koniec tygodnia (wyłącznie) — następny poniedziałek 00:00 czasu polskiego. */
    public function koniec(): CarbonImmutable
    {
        return $this->poniedzialek->addWeek()->utc();
    }

    public function nastepny(): self
    {
        return new self($this->poniedzialek->addWeek());
    }

    public function jestPrzyszly(): bool
    {
        return $this->poniedzialek->greaterThan(self::biezacy()->poniedzialek);
    }

    public function jestBiezacy(): bool
    {
        return $this->iso() === self::biezacy()->iso();
    }

    /** „od 28 września do 4 października 2026” — po polsku, w polskiej strefie. */
    public function opis(): string
    {
        $od = $this->poniedzialek;
        $do = $this->poniedzialek->addDays(6);

        $formatOd = $od->year === $do->year ? 'j F' : 'j F Y';

        return 'od '.$od->translatedFormat($formatOd).' do '.$do->translatedFormat('j F Y');
    }
}
