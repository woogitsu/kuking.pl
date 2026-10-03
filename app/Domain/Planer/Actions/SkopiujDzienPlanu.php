<?php

declare(strict_types=1);

namespace App\Domain\Planer\Actions;

use App\Domain\Planer\AktywneKontoPlanu;
use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Planer\ZakresDatPlanu;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * „Skopiuj ten dzień” w Planerze (#2494, V2): zestaw pozycji jednego dnia
 * dopisany na inny dzień. Dzień źródłowy zostaje bez zmian.
 *
 * Dwa kroki: PODGLĄD (`ocen()`, tylko odczyt) i ZATWIERDZENIE (`handle()`).
 * Podgląd niesie odcisk źródła i jego dostępności; przy zatwierdzeniu wszystko
 * liczy się od nowa pod blokadą konta (tą samą, którą biorą dopisanie,
 * przeniesienie i kopiowanie tygodnia). Gdy zestaw do skopiowania jest inny niż
 * w podglądzie, nic się nie zapisuje — człowiek dostaje nowy podgląd.
 *
 * Kopia DOPISUJE, nie zastępuje i nie łączy:
 *
 * - pozycja, która już stoi na dniu docelowym (ten sam przepis albo ten sam
 *   tekst), jest pokazana jako „już jest” i nie powstaje drugi raz,
 * - przepis, którego właściciel planu dziś już nie widzi, i ślad po przepisie
 *   usuniętym są tylko policzone — bez tytułu i bez kopiowania,
 * - brak miejsca (dzienny limit) odrzuca CAŁĄ kopię, bez częściowego zestawu,
 * - nowe pozycje mają własne UUID i daty; nie dziedziczą „Zrobione”.
 * - wybrane porcje i prywatny dopisek jadą z nową pozycją, jak w kopii
 *   tygodnia; ich zmiana po podglądzie też wymaga nowego podglądu (#2836).
 *
 * Nie rusza listy zakupów, spiżarni ani wykonań.
 */
final class SkopiujDzienPlanu
{
    public const ZASTOSOWANO = 'zastosowano';

    public const NIC_NOWEGO = 'nic_nowego';

    public const PUSTY = 'pusty';

    public const KONFLIKT = 'konflikt';

    public const TEN_SAM_DZIEN = 'ten_sam_dzien';

    public const KLASA_NOWA = 'nowa';

    public const KLASA_JUZ_JEST = 'juz_jest';

    public const KLASA_NIEDOSTEPNA = 'niedostepna';

    public function __construct(private readonly PlanerTygodnia $planer = new PlanerTygodnia) {}

    /**
     * Podgląd: co zostałoby skopiowane z dnia źródłowego na docelowy.
     *
     * @return array{pozycje: list<array{wpis: MealPlanEntry, stan: string, przepis: ?Recipe, klasa: string}>, nowe: int, juz_sa: int, niedostepne: int, wolne: int, odcisk: string}
     */
    public function ocen(User $user, CarbonImmutable $zrodlo, CarbonImmutable $cel): array
    {
        $naCelu = MealPlanEntry::query()
            ->where('user_id', $user->getKey())
            ->where('day', $cel->toDateString())
            ->get(['recipe_id', 'label']);
        $przepisyNaCelu = $naCelu->pluck('recipe_id')->filter()->flip();
        $tekstyNaCelu = $naCelu->pluck('label')->filter()->flip();

        $pozycje = [];
        $nowe = 0;
        $juzSa = 0;
        $niedostepne = 0;
        $odciskWiersze = [];

        foreach ($this->planer->pozycje($user, $zrodlo, $zrodlo) as $pozycja) {
            $wpis = $pozycja['wpis'];
            $klasa = match ($pozycja['stan']) {
                PlanerTygodnia::STAN_PRZEPIS => $przepisyNaCelu->has($wpis->recipe_id) ? self::KLASA_JUZ_JEST : self::KLASA_NOWA,
                PlanerTygodnia::STAN_WLASNY => $tekstyNaCelu->has($wpis->label) ? self::KLASA_JUZ_JEST : self::KLASA_NOWA,
                default => self::KLASA_NIEDOSTEPNA,
            };

            match ($klasa) {
                self::KLASA_NOWA => $nowe++,
                self::KLASA_JUZ_JEST => $juzSa++,
                default => $niedostepne++,
            };

            $pozycje[] = $pozycja + ['klasa' => $klasa];
            // JSON zachowuje granice pól także wtedy, gdy tekst zawiera „|”.
            $odciskWiersze[] = json_encode([
                $wpis->getKey(), $klasa, $wpis->recipe_id, $wpis->label,
                $wpis->planned_servings, $wpis->note,
            ], JSON_THROW_ON_ERROR);
        }

        return [
            'pozycje' => $pozycje,
            'nowe' => $nowe,
            'juz_sa' => $juzSa,
            'niedostepne' => $niedostepne,
            'wolne' => max(0, PlanerTygodnia::wpisowNaDzien() - $naCelu->count()),
            'odcisk' => hash('sha256', implode("\n", $odciskWiersze)),
        ];
    }

    /**
     * Błąd zakresu dla dnia docelowego (wspólny dla podglądu i zapisu), albo null.
     */
    public static function bladZakresu(CarbonImmutable $cel): ?string
    {
        return ZakresDatPlanu::obejmuje($cel)
            ? null
            : 'Wybierz dzień w zakresie planera: '.ZakresDatPlanu::opis().'.';
    }

    /**
     * Zatwierdzenie kopii.
     *
     * @return array{wynik: self::ZASTOSOWANO|self::NIC_NOWEGO|self::PUSTY|self::KONFLIKT|self::TEN_SAM_DZIEN, dodane: int}
     *
     * @throws ValidationException dzień docelowy poza zakresem albo za mało miejsca
     */
    public function handle(User $user, CarbonImmutable $zrodlo, CarbonImmutable $cel, string $widzianyOdcisk): array
    {
        if ($zrodlo->toDateString() === $cel->toDateString()) {
            return ['wynik' => self::TEN_SAM_DZIEN, 'dodane' => 0];
        }

        return DB::transaction(function () use ($user, $zrodlo, $cel, $widzianyOdcisk): array {
            $swiezy = AktywneKontoPlanu::podBlokada($user);

            $ocena = $this->ocen($swiezy, $zrodlo, $cel);

            if ($ocena['pozycje'] === []) {
                return ['wynik' => self::PUSTY, 'dodane' => 0];
            }

            $blad = self::bladZakresu($cel);
            if ($blad !== null) {
                throw ValidationException::withMessages(['cel' => $blad]);
            }

            if ($ocena['nowe'] === 0) {
                return ['wynik' => self::NIC_NOWEGO, 'dodane' => 0];
            }

            if (! hash_equals($ocena['odcisk'], $widzianyOdcisk)) {
                return ['wynik' => self::KONFLIKT, 'dodane' => 0];
            }

            if ($ocena['nowe'] > $ocena['wolne']) {
                throw ValidationException::withMessages([
                    'cel' => 'Na ten dzień zostało miejsca na '.$ocena['wolne'].', a kopia dodałaby '.$ocena['nowe']
                        .' (dzień mieści najwyżej '.PlanerTygodnia::wpisowNaDzien().' pozycji). Wybierz inny dzień albo usuń któreś pozycje z tego dnia — nic nie zostało skopiowane.',
                ]);
            }

            foreach ($ocena['pozycje'] as $pozycja) {
                if ($pozycja['klasa'] !== self::KLASA_NOWA) {
                    continue;
                }

                $kopia = new MealPlanEntry([
                    'day' => $cel->toDateString(),
                    'recipe_id' => $pozycja['wpis']->recipe_id,
                    'label' => $pozycja['wpis']->label,
                ]);
                $kopia->user_id = $swiezy->getKey();
                $kopia->planned_servings = $pozycja['wpis']->planned_servings;
                $kopia->note = $pozycja['wpis']->note;
                $kopia->save();
            }

            return ['wynik' => self::ZASTOSOWANO, 'dodane' => $ocena['nowe']];
        });
    }
}
