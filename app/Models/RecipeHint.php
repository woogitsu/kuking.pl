<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecipeHintFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * „Wskazówka od gotujących” (#2352, D-333, decyzja właściciela z 1.10.2026).
 *
 * Autor przepisu PROPONUJE, żeby uwagę z wykonania pokazać przy jego
 * przepisie; kucharz się zgadza albo nie, i może zgodę wycofać. Tekst nie jest
 * tu kopiowany — wskazówka to `cooked_events.note` (patrz migracja).
 *
 * `$fillable` jest PUSTE i ma takie zostać: stan (`status`), klucze osób
 * (`author_id`, `cook_id`), klucze treści i znaczniki decyzji ustawiają
 * wyłącznie akcje domenowe (`App\Domain\Wskazowki`), jawnym przypisaniem —
 * nigdy żądanie (AGENTS.md §7: pola sterujące i klucze właściciela poza
 * `$fillable`). Przejścia stanu to nazwane metody niżej, nie `update()`.
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

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'recipe_version_number' => 'integer',
            'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
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

    public function czekaNaOdpowiedz(): bool
    {
        return $this->status === self::STATUS_PROPOSED;
    }

    public function jestPrzyjeta(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
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

    public function wycofaj(): void
    {
        $this->przejdz(self::STATUS_ACCEPTED, self::STATUS_WITHDRAWN);
        $this->withdrawn_at = now();
        $this->save();
    }

    private function przejdz(string $z, string $do): void
    {
        if ($this->status !== $z) {
            throw new \LogicException("Wskazówka w stanie „{$this->status}” nie przechodzi do „{$do}”.");
        }

        $this->status = $do;
    }

    /**
     * Przyjęte wskazówki jednego przepisu — kolejność po dacie zgody, bez
     * rankingu (AGENTS.md §8, §12). `id` rozstrzyga remisy sekundy.
     *
     * @param  Builder<RecipeHint>  $query
     */
    public function scopePrzyjeteDlaPrzepisu(Builder $query, Recipe $recipe): void
    {
        $query->where('recipe_hints.recipe_id', $recipe->getKey())
            ->where('recipe_hints.status', self::STATUS_ACCEPTED)
            ->orderBy('recipe_hints.decided_at')
            ->orderBy('recipe_hints.id');
    }
}
