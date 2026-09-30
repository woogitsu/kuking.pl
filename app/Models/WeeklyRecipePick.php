<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\UgotujmyRazem\TydzienGotowania;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * „Ugotujmy razem” (F3) — jeden przepis tygodnia wybrany przez gospodarza.
 *
 * Kształt tabeli i rollback: migracja
 * `2026_09_30_180000_create_weekly_recipe_picks_table`.
 *
 * Bez `$fillable`: wszystkie trzy kolumny (tydzień, przepis, kto wybrał) są
 * decyzją gospodarza i wchodzą wyłącznie przez nazwaną akcję
 * `App\Domain\UgotujmyRazem\WybierzPrzepisTygodnia` (`forceFill`), razem
 * z wpisem dziennika audytu — nie przez masowe przypisanie z żądania.
 *
 * TO NIE JEST GRUPA (#22): nie ma członkostwa, zapisów ani listy uczestników.
 * Udziałem jest zwykłe „Ugotowałem” pod tym przepisem w tym tygodniu.
 */
class WeeklyRecipePick extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'week_starts_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function chooser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chosen_by');
    }

    public function tydzien(): TydzienGotowania
    {
        return TydzienGotowania::odDnia($this->week_starts_on);
    }
}
