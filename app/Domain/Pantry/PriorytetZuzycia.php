<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Domain\Rocznice\Urodziny;
use App\Models\PantryItem;
use App\Models\User;
use App\Support\Czas;
use App\Support\Odmiana;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * „Zużyj w pierwszej kolejności” — jawna reguła priorytetu produktów z listy
 * „Co mam w domu” (#1903, D-333). Jedno źródło reguły, tak jak
 * `CoUgotuje::REGULA` jest jedynym źródłem kolejności przepisów.
 *
 * DZIŚ = data w `Europe/Warsaw` (`Czas::dzisiajData()`), liczona raz na
 * żądanie i podawana jako parametr. Termin z opakowania to dzień
 * kalendarzowy bez strefy (`date`), więc porównujemy daty, nie momenty.
 *
 * GRUPY, W TEJ KOLEJNOŚCI
 *  1. Termin minął: `expires_on < dziś`, nie mrożone, poza `use_by`.
 *  2. Do N dni (domyślnie 3, `kuking.pantry.pilne_dni`): `dziś ≤ expires_on ≤ dziś + N`,
 *     nie mrożone. Grupy 1 i 2 razem to sekcja „Zużyj w pierwszej kolejności”.
 *     Wewnątrz: termin rosnąco, przy remisie „Należy zużyć do” przed
 *     „Najlepiej spożyć przed”, potem nazwa, potem `id`.
 *  3. Później: termin dalszy niż N dni, nie mrożone, po terminie.
 *  4. Bez terminu: alfabetycznie. Nigdy nie opisujemy ich jako świeżych
 *     ani bezpiecznych — brak terminu to brak informacji.
 *  5. Mrożone: osobna sekcja, bez pilności. Zamrożenie nie zmienia wpisanego
 *     terminu, tylko wyłącza produkt z pilnych.
 *  6. Po terminie „Należy zużyć do”: osobno, bez zachęty do gotowania;
 *     jeśli data została wpisana błędnie, można ją poprawić; produkt można usunąć.
 *
 * KOLEJNOŚĆ USTAWIA WYŁĄCZNIE DATA, KTÓRĄ WPISUJE OSOBA. Nie zależy od
 * niczyich reakcji (AGENTS.md §8) — pilnuje tego `FeedNieSortujePoMierzeReakcjiTest`,
 * który skanuje cały katalog `app/Domain/Pantry`.
 */
final class PriorytetZuzycia
{
    public const PILNE = 'pilne';

    public const POZNIEJ = 'pozniej';

    public const BEZ_TERMINU = 'bez_terminu';

    public const MROZONE = 'mrozone';

    public const PO_TERMINIE = 'po_terminie';

    public const RODZAJ_ZUZYC_DO = 'use_by';

    public const RODZAJ_NAJLEPIEJ_PRZED = 'best_before';

    /** Ten sam warunek dostępności w zapytaniach do przepisów i na liście. */
    public const DOSTEPNY_SQL = "(p.expiry_kind IS DISTINCT FROM 'use_by' OR p.expires_on >= ? OR p.frozen)";

    /**
     * Źródło „opakowań” w SQL (#2568): pierwsze opakowanie z `pantry_items`
     * i drugie z `pantry_second_packages`, oba z `id`, `user_id`, nazwą
     * i rdzeniami PRODUKTU. Zapytania używają go jako `FROM … p` zamiast
     * gołego `pantry_items p`, więc dostępność, pilność i dopasowanie do
     * składników liczą się osobno dla każdego opakowania, a produkt „jest”
     * (`EXISTS`, `count(DISTINCT p.id)`), gdy choć jedno opakowanie spełnia
     * warunek. Produkt z jednym opakowaniem daje dokładnie te same wiersze co dawniej.
     */
    public const OPAKOWANIA_SQL = '(SELECT i.id, i.user_id, i.name, i.rdzenie, i.expires_on, i.expiry_kind, i.frozen FROM pantry_items i '
        .'UNION ALL SELECT i.id, i.user_id, i.name, i.rdzenie, d.expires_on, d.expiry_kind, d.frozen '
        .'FROM pantry_second_packages d JOIN pantry_items i ON i.id = d.pantry_item_id)';

    /** Podpisy rodzajów terminu — dosłownie jak na opakowaniach. */
    public const RODZAJE = [
        self::RODZAJ_ZUZYC_DO => 'Należy zużyć do',
        self::RODZAJ_NAJLEPIEJ_PRZED => 'Najlepiej spożyć przed',
    ];

    /**
     * Zdanie z regułą — jedno źródło dla widoku i dokumentacji.
     * Liczba dni jest wstawiana z konfiguracji (`regula()`).
     */
    private const REGULA = 'Na górze są produkty z terminem, który minął albo upływa w ciągu %d %s, od najwcześniejszego. '
        .'Produkty po terminie „Należy zużyć do” są osobno i nie podpowiadamy ich do gotowania. '
        .'Niżej te z dalszym terminem, potem bez terminu. Kolejność ustawia tylko data, którą wpisujesz Ty.';

    public static function pilneDni(): int
    {
        return max(1, (int) config('kuking.pantry.pilne_dni', 3));
    }

    public static function regula(): string
    {
        $dni = self::pilneDni();

        return sprintf(self::REGULA, $dni, $dni === 1 ? 'dnia' : 'dni');
    }

    /** Dziś jako `Y-m-d` w strefie człowieka. */
    public static function dzis(): string
    {
        return Czas::dzisiajData();
    }

    /** Ostatni dzień, który jeszcze liczy się jako pilny (`dziś + N`), `Y-m-d`. */
    public static function granicaPilnych(?string $dzis = null): string
    {
        return CarbonImmutable::parse($dzis ?? self::dzis())->addDays(self::pilneDni())->toDateString();
    }

    /**
     * Pilne produkty TEJ osoby, od najwcześniejszego terminu — blok na `/home`
     * i sobotni list. Filtr w SQL (termin do `dziś + N`, nie mrożone), kolejność
     * z `pogrupuj()`, więc ta sama co na liście.
     *
     * @return Collection<int, Opakowanie>
     */
    public static function pilneDla(User $user, ?string $dzis = null): Collection
    {
        $dzis ??= self::dzis();
        $granica = self::granicaPilnych($dzis);

        // Wstępny filtr w SQL: produkt, którego PIERWSZE albo DRUGIE opakowanie
        // ma termin do `dziś + N` i nie jest mrożone. O pilności decyduje
        // potem `grupa()` osobno dla każdego opakowania (mrożone opakowanie
        // nie chowa pilnego niemrożonego, a po terminie „Należy zużyć do”
        // nie jest pilne).
        $produkty = PantryItem::query()->from('pantry_items as p')
            ->where('p.user_id', $user->getKey())
            ->where(function ($q) use ($granica): void {
                $q->where(fn ($pierwsze) => $pierwsze->where('p.frozen', false)->whereNotNull('p.expires_on')->where('p.expires_on', '<=', $granica))
                    ->orWhereExists(function ($drugie) use ($granica): void {
                        $drugie->selectRaw('1')->from('pantry_second_packages as d')
                            ->whereColumn('d.pantry_item_id', 'p.id')
                            ->where('d.frozen', false)->whereNotNull('d.expires_on')->where('d.expires_on', '<=', $granica);
                    });
            })
            ->with('secondPackage')
            ->get();

        // Produkt raz, z najwcześniejszym pilnym opakowaniem (kolejność z `pogrupuj()`):
        // dwa pilne opakowania tej samej nazwy to jedna pozycja w liście i w zdaniu na Starcie.
        return self::pogrupuj($produkty, $dzis)['pilne']->unique(fn (Opakowanie $o): string => $o->id)->values();
    }

    /**
     * Jedno zdanie na `/home`: „Do zużycia w ciągu 3 dni: mleko, szynka i jeszcze
     * 2 produkty.” — albo `null`, gdy nic nie jest pilne (blok się wtedy nie
     * pojawia, bez pustego stanu). Bez licznika i bez ikon.
     *
     * @param  Collection<int, PantryItem|Opakowanie>  $pilne  wynik `pilneDla()`
     */
    public static function zdanieDlaStartu(Collection $pilne): ?string
    {
        if ($pilne->isEmpty()) {
            return null;
        }

        $nazwy = $pilne->take(2)->map(fn (PantryItem|Opakowanie $p): string => (string) $p->name)->values();
        $reszta = $pilne->count() - $nazwy->count();
        $dni = self::pilneDni();
        $poczatek = 'Do zużycia w ciągu '.$dni.' '.($dni === 1 ? 'dnia' : 'dni').': ';

        if ($reszta > 0) {
            return $poczatek.$nazwy->implode(', ').' i jeszcze '.$reszta.' '.Odmiana::rzeczownik($reszta, 'produkt', 'produkty', 'produktów').'.';
        }

        return $poczatek.($nazwy->count() === 2 ? $nazwy[0].' i '.$nazwy[1] : $nazwy[0]).'.';
    }

    public static function grupa(PantryItem|Opakowanie $produkt, ?string $dzis = null): string
    {
        if ($produkt->frozen) {
            return self::MROZONE;
        }

        if ($produkt->expires_on === null) {
            return self::BEZ_TERMINU;
        }

        $dzis ??= self::dzis();

        if ($produkt->expiry_kind === self::RODZAJ_ZUZYC_DO && $produkt->expires_on->toDateString() < $dzis) {
            return self::PO_TERMINIE;
        }

        return $produkt->expires_on->toDateString() <= self::granicaPilnych($dzis)
            ? self::PILNE
            : self::POZNIEJ;
    }

    /**
     * Każde OPAKOWANIE trafia do swojej grupy osobno (#2568): produkt z jednym
     * opakowaniem po terminie „Należy zużyć do” i drugim dobrym stoi w dwóch
     * sekcjach. Produkt bez wczytanej relacji `secondPackage` ma jedno opakowanie.
     *
     * @param  iterable<PantryItem|Opakowanie>  $produkty
     * @return array{pilne: Collection<int, Opakowanie>, pozniej: Collection<int, Opakowanie>, bez_terminu: Collection<int, Opakowanie>, mrozone: Collection<int, Opakowanie>, po_terminie: Collection<int, Opakowanie>}
     */
    public static function pogrupuj(iterable $produkty, ?string $dzis = null): array
    {
        $dzis ??= self::dzis();
        $zbiory = [self::PILNE => [], self::POZNIEJ => [], self::BEZ_TERMINU => [], self::MROZONE => [], self::PO_TERMINIE => []];

        foreach ($produkty as $produkt) {
            foreach ($produkt instanceof PantryItem ? Opakowanie::zProduktu($produkt) : [$produkt] as $opakowanie) {
                $zbiory[self::grupa($opakowanie, $dzis)][] = $opakowanie;
            }
        }

        return [
            'pilne' => self::posortuj($zbiory[self::PILNE], true),
            'pozniej' => self::posortuj($zbiory[self::POZNIEJ], true),
            'bez_terminu' => self::posortuj($zbiory[self::BEZ_TERMINU], false),
            'mrozone' => self::posortuj($zbiory[self::MROZONE], true),
            'po_terminie' => self::posortuj($zbiory[self::PO_TERMINIE], true),
        ];
    }

    /**
     * @param  list<Opakowanie>  $lista
     * @return Collection<int, Opakowanie>
     */
    private static function posortuj(array $lista, bool $poTerminie): Collection
    {
        usort($lista, function (Opakowanie $a, Opakowanie $b) use ($poTerminie): int {
            if ($poTerminie) {
                // Bez terminu (mrożone bez daty) na końcu swojej grupy.
                $porownanie = ($a->expires_on?->toDateString() ?? '9999-12-31') <=> ($b->expires_on?->toDateString() ?? '9999-12-31');
                if ($porownanie !== 0) {
                    return $porownanie;
                }

                // Remis dat: „Należy zużyć do” przed „Najlepiej spożyć przed”.
                $porownanie = self::wagaRodzaju($a) <=> self::wagaRodzaju($b);
                if ($porownanie !== 0) {
                    return $porownanie;
                }
            }

            // Na końcu numer opakowania: dwa opakowania jednego produktu z tym
            // samym terminem mają stałą kolejność (pierwsze, drugie).
            return [mb_strtolower((string) $a->name), (string) $a->getKey(), $a->numer]
                <=> [mb_strtolower((string) $b->name), (string) $b->getKey(), $b->numer];
        });

        return new Collection($lista);
    }

    private static function wagaRodzaju(PantryItem|Opakowanie $produkt): int
    {
        return match ($produkt->expiry_kind) {
            self::RODZAJ_ZUZYC_DO => 0,
            self::RODZAJ_NAJLEPIEJ_PRZED => 1,
            default => 2,
        };
    }

    /** „Należy zużyć do 3 października” — albo null, gdy nie ma terminu. */
    public static function etykietaTerminu(PantryItem|Opakowanie $produkt): ?string
    {
        if ($produkt->expires_on === null) {
            return null;
        }

        return self::RODZAJE[$produkt->expiry_kind] ?? 'Termin';
    }

    /** „3 października 2026”. */
    public static function dataSlownie(CarbonImmutable|\DateTimeInterface $data, bool $zRokiem = true): string
    {
        $d = CarbonImmutable::instance($data);
        $tekst = $d->day.' '.Urodziny::MIESIACE_DOPELNIACZ[$d->month];

        return $zRokiem ? $tekst.' '.$d->year : $tekst;
    }

    /**
     * Linia stanu słowami — stan niesie tekst, nie sam kolor.
     *
     * Nie mówimy „świeże”, „bezpieczne”, „zepsute”: termin jest notatką
     * właściciela z opakowania, a Kuking nie ocenia, czy produkt nadaje się
     * do jedzenia.
     */
    public static function opisStanu(PantryItem|Opakowanie $produkt, ?string $dzis = null): string
    {
        if ($produkt->frozen) {
            return 'W zamrażarce.';
        }

        if ($produkt->expires_on === null) {
            return 'Bez terminu.';
        }

        $dzis ??= self::dzis();
        $roznica = (int) CarbonImmutable::parse($dzis)->startOfDay()
            ->diffInDays($produkt->expires_on->toImmutable()->startOfDay(), false);
        $data = self::dataSlownie($produkt->expires_on, $produkt->expires_on->year !== (int) substr($dzis, 0, 4));

        return match (true) {
            $roznica < 0 && $produkt->expiry_kind === self::RODZAJ_ZUZYC_DO => 'Termin „Należy zużyć do” minął. Nie używaj tego produktu do gotowania. Jeśli termin został wpisany błędnie, popraw go. W przeciwnym razie usuń produkt z listy.',
            $roznica < -1 => 'Termin minął '.abs($roznica).' dni temu. Sprawdź produkt przed użyciem albo usuń go z listy.',
            $roznica === -1 => 'Termin minął wczoraj. Sprawdź produkt przed użyciem albo usuń go z listy.',
            $roznica === 0 => 'Termin dziś.',
            $roznica === 1 => "Termin jutro ({$data}).",
            default => "Termin za {$roznica} dni ({$data}).",
        };
    }
}
