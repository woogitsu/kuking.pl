<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Collections\KierunekPrzesuniecia;
use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Collections\KonfliktKolejnosci;
use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Domain\Collections\WynikPrzesuniecia;
use App\Domain\Collections\ZamekZapisuDoZeszytu;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * „Wyżej" / „Niżej" / „Na początek" / „Na koniec" przy przepisie we własnym,
 * prywatnym zeszycie (#2544, V2, F7/D-333).
 *
 * CO TU JEST ATOMOWE
 * Jedna transakcja: świeże konto i świeży zeszyt pod zamkiem
 * (`ZamekZapisuDoZeszytu::mutuj()` — ten sam zamek, który biorą dopisanie
 * i wyjęcie przepisu), Policy `reorder` zadana drugi raz na ŚWIEŻYM stanie,
 * odcisk układu z karty porównany z bazą i dopiero zapis. Dopisanie przepisu
 * w drugiej karcie czeka na zamek, więc nie dostanie numeru zajętego przez
 * przesunięcie.
 *
 * STARA KARTA NIE PSUJE KOLEJNOŚCI
 * Formularz niesie odcisk układu z chwili wyświetlenia (wzór #2400).
 * Rozbieżność = {@see KonfliktKolejnosci}, nic nie jest zapisane. Brak
 * odcisku (stary formularz, własny klient) to też konflikt: nie wiemy, co
 * człowiek widział, a „przesuń wyżej" bez tej wiedzy trafiłoby w inną
 * parę niż ta, którą wskazał.
 *
 * CO SIĘ PRZESUWA
 * Sąsiad to sąsiad na liście WIDOCZNEJ (ta sama `WidocznaZawartoscZeszytu`
 * co ekran), więc przycisk zawsze coś zmienia na oczach człowieka. Przepisy
 * niewidoczne (usunięte, ukryte, od zablokowanego) zostają na swoich
 * miejscach w pełnej liście. Przepis spoza zeszytu albo niewidoczny dla
 * właściciela to „nie ma takiej pozycji" (404), nie ciche nic.
 *
 * ZAPIS: pozycje całej listy są przenumerowane do 1..N, ale dotykamy tylko
 * wierszy, których numer się zmienił (przesunięcie o jedno miejsce to dwa
 * wiersze). Dwa kroki — najpierw `NULL`, potem docelowe numery — bo unikalny
 * indeks `(collection_id, position)` nie lubi chwilowego powtórzenia.
 * `created_at`, notatka i autor dopisania zostają nietknięte.
 */
final class PrzesunPrzepisWZeszycie
{
    public function __construct(
        private readonly ZamekZapisuDoZeszytu $zamek,
        private readonly WidocznaZawartoscZeszytu $zawartosc = new WidocznaZawartoscZeszytu,
    ) {}

    /**
     * @throws KonfliktKolejnosci gdy układ zmienił się od wyświetlenia karty
     * @throws ModelNotFoundException gdy przepisu nie ma w tym zeszycie albo jest niewidoczny
     */
    public function handle(User $user, Collection $zeszyt, string $przepisId, KierunekPrzesuniecia $kierunek, ?string $odcisk): WynikPrzesuniecia
    {
        return $this->zamek->mutuj($user, $zeszyt, function (User $aktor, Collection $cel) use ($przepisId, $kierunek, $odcisk): WynikPrzesuniecia {
            Gate::forUser($aktor)->authorize('reorder', $cel);

            $pelny = KolejnoscPrzepisow::uklad($cel);

            if ($odcisk === null || ! hash_equals(KolejnoscPrzepisow::odcisk($pelny), $odcisk)) {
                throw new KonfliktKolejnosci;
            }

            $widoczneSet = array_flip($this->zawartosc->przepisy($cel, $aktor)->select('recipes.id')->reorder()
                ->pluck('recipes.id')->map(fn ($id): string => (string) $id)->all());

            // Lista widoczna w kolejności pełnej listy.
            $widoczne = array_values(array_filter($pelny, fn (string $id): bool => isset($widoczneSet[$id])));
            $miejsce = array_search($przepisId, $widoczne, true);

            if ($miejsce === false) {
                throw new ModelNotFoundException;
            }

            $nowy = $this->przestaw($pelny, $widoczne, $miejsce, $kierunek);
            $zmieniono = $nowy !== $pelny;

            if ($zmieniono) {
                $this->zapisz($cel, $nowy);
            }

            $widoczneTeraz = array_values(array_filter($nowy, fn (string $id): bool => isset($widoczneSet[$id])));

            return new WynikPrzesuniecia(
                tytul: (string) Recipe::query()->whereKey($przepisId)->value('title'),
                pozycja: (int) array_search($przepisId, $widoczneTeraz, true) + 1,
                ile: count($widoczneTeraz),
                zmieniono: $zmieniono,
            );
        });
    }

    /**
     * Nowa pełna lista po jednym ruchu. Zamiana dotyczy sąsiada na liście
     * WIDOCZNEJ; ruch na skraj wstawia przepis tuż przed pierwszym albo tuż po
     * ostatnim widocznym.
     *
     * @param  list<string>  $pelny
     * @param  list<string>  $widoczne
     * @return list<string>
     */
    private function przestaw(array $pelny, array $widoczne, int $miejsce, KierunekPrzesuniecia $kierunek): array
    {
        $id = $widoczne[$miejsce];
        $ostatnie = count($widoczne) - 1;

        $cel = match ($kierunek) {
            KierunekPrzesuniecia::Wyzej => $miejsce > 0 ? $widoczne[$miejsce - 1] : null,
            KierunekPrzesuniecia::Nizej => $miejsce < $ostatnie ? $widoczne[$miejsce + 1] : null,
            KierunekPrzesuniecia::NaPoczatek => $miejsce > 0 ? $widoczne[0] : null,
            KierunekPrzesuniecia::NaKoniec => $miejsce < $ostatnie ? $widoczne[$ostatnie] : null,
        };

        // Już stoi tam, dokąd miał trafić (pierwszy + „Wyżej" itp.).
        if ($cel === null) {
            return $pelny;
        }

        if ($kierunek === KierunekPrzesuniecia::Wyzej || $kierunek === KierunekPrzesuniecia::Nizej) {
            $a = (int) array_search($id, $pelny, true);
            $b = (int) array_search($cel, $pelny, true);
            [$pelny[$a], $pelny[$b]] = [$pelny[$b], $pelny[$a]];

            return $pelny;
        }

        $bez = array_values(array_filter($pelny, fn (string $x): bool => $x !== $id));
        $gdzie = (int) array_search($cel, $bez, true);
        array_splice($bez, $kierunek === KierunekPrzesuniecia::NaPoczatek ? $gdzie : $gdzie + 1, 0, [$id]);

        return $bez;
    }

    /**
     * Numeruje całą listę od 1 i zapisuje tylko wiersze, których numer się
     * zmienił (przy pierwszym ułożeniu — wszystkie).
     *
     * @param  list<string>  $nowy
     */
    private function zapisz(Collection $zeszyt, array $nowy): void
    {
        $obecne = DB::table('collection_items')
            ->where('collection_id', $zeszyt->getKey())
            ->whereNotNull('recipe_id')
            ->pluck('position', 'recipe_id');

        $ids = [];
        $numery = [];

        foreach ($nowy as $indeks => $id) {
            $numer = $indeks + 1;
            $stary = $obecne[$id] ?? null;

            if ($stary === null || (int) $stary !== $numer) {
                $ids[] = $id;
                $numery[] = $numer;
            }
        }

        if ($ids === []) {
            return;
        }

        $idsLiteral = '{'.implode(',', $ids).'}';

        DB::update(
            'UPDATE collection_items SET position = NULL WHERE collection_id = ? AND recipe_id = ANY (?::uuid[])',
            [$zeszyt->getKey(), $idsLiteral],
        );
        DB::update(
            'UPDATE collection_items ci SET position = v.numer FROM unnest(?::uuid[], ?::int[]) AS v(id, numer) '
            .'WHERE ci.collection_id = ? AND ci.recipe_id = v.id',
            [$idsLiteral, '{'.implode(',', $numery).'}', $zeszyt->getKey()],
        );
    }
}
