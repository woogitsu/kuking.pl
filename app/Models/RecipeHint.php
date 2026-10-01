<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecipeHintFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * „Wskazówka od gotujących” (#2352, D-333, decyzja właściciela z 1.10.2026).
 *
 * Autor przepisu PROPONUJE, żeby uwagę z wykonania pokazać przy jego
 * przepisie; kucharz się zgadza albo nie, i może zgodę wycofać. Tekst nie jest
 * tu kopiowany — wskazówka to `cooked_events.note` (patrz migracja).
 *
 * `$fillable` jest PUSTE i ma takie zostać: stan (`status`), klucze osób
 * (`author_id`, `cook_id`), klucze treści, znaczniki decyzji i ukrycie przez
 * moderację (`moderation_hidden_at`) ustawiają
 * wyłącznie akcje domenowe (`App\Domain\Wskazowki`), jawnym przypisaniem —
 * nigdy żądanie (AGENTS.md §7: pola sterujące i klucze właściciela poza
 * `$fillable`). Przejścia stanu to nazwane metody niżej, nie `update()`.
 *
 * Kolumnę `moderation_hidden_at` dodaje migracja surowym SQL-em, którego
 * Larastan nie odczyta.
 *
 * @property Carbon|null $moderation_hidden_at
 */
class RecipeHint extends Model
{
    /** @use HasFactory<RecipeHintFactory> */
    use HasFactory;

    use HasUuids;

    /** Autor poprosił, kucharz jeszcze nie odpowiedział — nic nie jest publiczne. */
    public const STATUS_PROPOSED = 'proposed';

    /** Kucharz się zgodził — wskazówka stoi przy przepisie. */
    public const STATUS_ACCEPTED = 'accepted';

    /** Kucharz odpowiedział „Nie” — ostateczne, autor nie prosi drugi raz. */
    public const STATUS_DECLINED = 'declined';

    /** Kucharz wycofał zgodę — wskazówka zniknęła, ostateczne. */
    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * Autor sam wycofał własną czekającą prośbę (D-333, 1.10.2026) —
     * ostateczne jak „Nie”: to samo wykonanie nie dostaje drugiej prośby.
     * Kucharz nie odpowiadał, więc `decided_at` zostaje pusty.
     */
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'recipe_version_number' => 'integer',
            'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'moderation_hidden_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<CookedEvent, $this> */
    public function cookedEvent(): BelongsTo
    {
        return $this->belongsTo(CookedEvent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cook(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cook_id');
    }

    /**
     * Prośba, na którą kucharz jeszcze może odpowiedzieć: czeka i NIE wygasła.
     * Wygasła prośba nie ma osobnego stanu — to ten sam wiersz `proposed`,
     * tylko starszy niż okno z konfiguracji (patrz `wygasla()`).
     */
    public function czekaNaOdpowiedz(): bool
    {
        return $this->status === self::STATUS_PROPOSED && ! $this->wygasla();
    }

    /**
     * Czekająca prośba po upływie okna (`kuking.wskazowki.prosba_wygasa_po_dniach`,
     * liczone od `created_at`). Wygaśnięcie to KONIEC: bez odpowiedzi, bez
     * ponowienia (unikalność wykonania zostaje) i bez wiadomości do kogokolwiek.
     * Nie ma zadania w tle ani zmiany stanu — wiek wiersza rozstrzyga przy
     * każdym odczycie, więc nie ma okna, w którym prośba „jeszcze żyje” po
     * terminie.
     */
    public function wygasla(): bool
    {
        return $this->status === self::STATUS_PROPOSED
            && $this->created_at !== null
            && $this->created_at->lte(self::granicaWygasniecia());
    }

    /** Najstarsza data `created_at`, od której czekająca prośba jeszcze żyje. */
    public static function granicaWygasniecia(): Carbon
    {
        return now()->subDays((int) config('kuking.wskazowki.prosba_wygasa_po_dniach'));
    }

    public function jestAnulowana(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function jestPrzyjeta(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /** Moderacja zdjęła tę wskazówkę z sekcji przy przepisie (zgoda kucharza zostaje, jaka była). */
    public function jestUkrytaPrzezModeracje(): bool
    {
        return $this->moderation_hidden_at !== null;
    }

    /**
     * Czy wskazówka stoi dziś przy przepisie: kucharz się zgodził, nie wycofał
     * zgody i moderacja jej nie ukryła. Jedno źródło dla strony przepisu,
     * Policy zgłoszenia i decyzji moderacyjnych.
     */
    public function jestPokazywana(): bool
    {
        return $this->jestPrzyjeta() && ! $this->jestUkrytaPrzezModeracje();
    }

    /**
     * Ukrycie przez moderację (decyzja `hide`, #2352): znika SAMA wskazówka,
     * wykonanie z uwagą zostaje nietknięte. Wołają je wyłącznie decyzje
     * moderacyjne, pod blokadą wiersza (`zablokujDoDecyzji()`); o tym, KTO
     * wolno, decyduje panel moderacji, nie ta metoda.
     */
    public function ukryjPrzezModeracje(): void
    {
        if (! $this->jestPokazywana()) {
            throw new LogicException('Ukryć można tylko wskazówkę, która stoi przy przepisie.');
        }

        $this->moderation_hidden_at = now();
        $this->save();
    }

    /**
     * Zdjęcie ukrycia po uznanym odwołaniu. Zgoda kucharza nie jest tu
     * ruszana: jeśli w międzyczasie wycofał zgodę, wskazówka zostaje
     * wycofana, a znika tylko ślad moderacji.
     *
     * @return bool czy było co zdejmować
     */
    public function zdejmijUkrycieModeracji(): bool
    {
        if ($this->moderation_hidden_at === null) {
            return false;
        }

        $this->moderation_hidden_at = null;
        $this->save();

        return true;
    }

    /**
     * Konta, które decyzja moderacyjna o tej wskazówce zablokuje (kucharz i autor
     * przepisu) — do `ZamekUprzywilejowanegoAktora::wykonaj()`, żeby wszystkie
     * wiersze `users` szły jednym przebiegiem rosnąco po `id`. Odczyt bez
     * blokady; pusta lista, gdy wskazówki nie ma.
     *
     * @return list<string>
     */
    public static function kontaDoBlokady(?string $id): array
    {
        if ($id === null) {
            return [];
        }

        $wiersz = self::query()->whereKey($id)->first(['id', 'cook_id', 'author_id']);

        return $wiersz === null ? [] : [(string) $wiersz->cook_id, (string) $wiersz->author_id];
    }

    /**
     * Wskazówka pod blokadą do decyzji moderacyjnej. Kolejność zamków jak w
     * akcjach kucharza i autora (`ZamekPary`): oba konta rosnąco po `id`, potem
     * wiersz wskazówki — odwrotna kolejność zakleszczałaby się z „Wycofaj
     * zgodę” (konta, potem wskazówka). Zwraca świeży model z relacjami
     * potrzebnymi decyzji (kucharz, przepis) albo `null`, gdy wskazówki już nie ma.
     */
    public static function zablokujDoDecyzji(string $id): ?self
    {
        $wstepna = self::query()->whereKey($id)->first(['id', 'cook_id', 'author_id']);

        if ($wstepna === null) {
            return null;
        }

        $konta = array_values(array_unique([(string) $wstepna->cook_id, (string) $wstepna->author_id]));
        sort($konta, SORT_STRING);

        foreach ($konta as $kontoId) {
            User::query()->whereKey($kontoId)->lockForUpdate()->first(['id']);
        }

        return self::query()->whereKey($id)->with(['cook', 'recipe', 'cookedEvent'])->lockForUpdate()->first();
    }

    /**
     * Przejścia stanu. Wołają je wyłącznie akcje domenowe, pod blokadą
     * wiersza — metoda sama niczego nie sprawdza poza własnym stanem
     * wyjściowym, bo o tym, KTO wolno, decyduje Policy.
     */
    public function przyjmij(): void
    {
        $this->przejdz(self::STATUS_PROPOSED, self::STATUS_ACCEPTED);
        $this->decided_at = now();
        $this->save();
    }

    public function odrzuc(): void
    {
        $this->przejdz(self::STATUS_PROPOSED, self::STATUS_DECLINED);
        $this->decided_at = now();
        $this->save();
    }

    public function anuluj(): void
    {
        $this->przejdz(self::STATUS_PROPOSED, self::STATUS_CANCELLED);
        $this->save();
    }

    public function wycofaj(): void
    {
        $this->przejdz(self::STATUS_ACCEPTED, self::STATUS_WITHDRAWN);
        $this->withdrawn_at = now();
        $this->save();
    }

    private function przejdz(string $z, string $do): void
    {
        if ($this->status !== $z) {
            throw new LogicException("Wskazówka w stanie „{$this->status}” nie przechodzi do „{$do}”.");
        }

        $this->status = $do;
    }

    /**
     * Wskazówki zajmujące miejsce w limicie przepisu: przyjęte i czekające,
     * które nie wygasły. Odrzucone, wycofane, anulowane i wygasłe miejsca nie
     * zajmują.
     *
     * @param  Builder<RecipeHint>  $query
     */
    public function scopeZajmujaceMiejsce(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->where('recipe_hints.status', self::STATUS_ACCEPTED)
                ->orWhere(function (Builder $czekajace): void {
                    $czekajace->where('recipe_hints.status', self::STATUS_PROPOSED)
                        ->where('recipe_hints.created_at', '>', self::granicaWygasniecia());
                });
        });
    }

    /**
     * Przyjęte i nieukryte wskazówki jednego przepisu — kolejność po dacie zgody, bez
     * rankingu (AGENTS.md §8, §12). `id` rozstrzyga remisy sekundy.
     *
     * @param  Builder<RecipeHint>  $query
     */
    public function scopePrzyjeteDlaPrzepisu(Builder $query, Recipe $recipe): void
    {
        // Ukryta przez moderację (`moderation_hidden_at`) wypada z sekcji,
        // choć kucharz dalej jest zgodny — zob. `jestPokazywana()`.
        $query->where('recipe_hints.recipe_id', $recipe->getKey())
            ->where('recipe_hints.status', self::STATUS_ACCEPTED)
            ->whereNull('recipe_hints.moderation_hidden_at')
            ->orderBy('recipe_hints.decided_at')
            ->orderBy('recipe_hints.id');
    }
}
