<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Zamrożony obraz przepisu w chwili istotnej zmiany.
 *
 * Po co: ktoś ugotował z wersji z 2027 roku i zostawił komentarz "wyszło
 * idealnie". Jeśli autor w 2029 zmieni proporcje, ten komentarz przestanie
 * mieć sens bez dostępu do starej wersji.
 *
 * Wersji NIGDY się nie nadpisuje (decyzja właściciela z 24.09.2026, issue
 * #1316). Poprawka treści to zawsze nowa wersja; próba `update()`/`save()`
 * istniejącej kończy się wyjątkiem, zanim cokolwiek trafi do bazy.
 *
 * JEDEN WYJĄTEK: ukrycie wersji (issue #2270). `hidden_at` i `hidden_by_role`
 * nie są treścią wersji, tylko tym, KTO ją widzi — zmieniają je wyłącznie
 * nazwane metody `ukryj()` i `odkryj()` (wołane z
 * `App\Domain\Recipes\Historia\UkrywanieWersji`). Obie kolumny są poza
 * `$fillable`: to pola sterujące widocznością (AGENTS.md §7). Zapis, który
 * rusza cokolwiek innego, nadal kończy się wyjątkiem.
 *
 * `hidden_by_role` dodaje migracja surowym SQL-em, którego Larastan nie
 * odczyta — stąd jawna deklaracja niżej.
 *
 * @property CarbonImmutable|null $hidden_at
 * @property string|null $hidden_by_role
 */
class RecipeVersion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Wersję ukrył autor przepisu — tylko autor może ją przywrócić. */
    public const UKRYL_AUTOR = 'author';

    /** Wersję ukryła moderacja — autor jej nie przywraca (issue #2270). */
    public const UKRYLA_MODERACJA = 'moderator';

    /**
     * Wartość `moderation_actions.previous_status` przy decyzji, która
     * PRZEJĘŁA ukrycie zrobione wcześniej przez autora (decyzja 30.09.2026).
     * Stan sprzed decyzji jest faktem o decyzji (docs/DATABASE.md), więc
     * uznane odwołanie czyta go z decyzji, której dotyczy — nie z audytu.
     * Mieści się w `string(20)`; kolumna nie ma CHECK-a.
     */
    public const STAN_PRZED_PRZEJECIEM = 'hidden_by_author';

    /** Kolumny, które wolno zmienić w istniejącej wersji — i tylko one. */
    private const KOLUMNY_UKRYCIA = ['hidden_at', 'hidden_by_role'];

    protected $fillable = [
        'recipe_id',
        'editor_id',
        'version_number',
        'snapshot',
        'change_note',
    ];

    protected static function booted(): void
    {
        static::updating(function (RecipeVersion $wersja): void {
            if (array_diff(array_keys($wersja->getDirty()), self::KOLUMNY_UKRYCIA) !== []) {
                throw new LogicException('Wersji przepisu nie wolno zmieniać — zapisz nową wersję (issue #1316).');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'version_number' => 'integer',
            'hidden_at' => 'immutable_datetime',
        ];
    }

    public function czyUkryta(): bool
    {
        return $this->hidden_at !== null;
    }

    public function czyUkrytaPrzezModeracje(): bool
    {
        return $this->czyUkryta() && $this->hidden_by_role === self::UKRYLA_MODERACJA;
    }

    /**
     * `$kto` przychodzi z `UkrywanieWersji::strona()`; nieznana wartość to
     * błąd programisty, a baza i tak by ją odrzuciła CHECK-iem — tu pada
     * wcześniej, z czytelnym komunikatem.
     *
     * @param  string  $kto  `UKRYL_AUTOR` albo `UKRYLA_MODERACJA`
     */
    public function ukryj(string $kto): void
    {
        if (! in_array($kto, [self::UKRYL_AUTOR, self::UKRYLA_MODERACJA], true)) {
            throw new LogicException('Nieznana strona ukrycia wersji: '.$kto);
        }

        $this->forceFill(['hidden_at' => now(), 'hidden_by_role' => $kto])->save();
    }

    /**
     * Uznane odwołanie od PRZEJĘCIA ukrycia (decyzja właściciela z 30.09.2026):
     * wersja dalej jest ukryta, ale znów jako ukrycie autora, które autor
     * może cofnąć sam. `hidden_at` zostaje — wersja nie była publiczna ani
     * chwili; zmienia się tylko strona.
     */
    public function oddajAutorowi(): void
    {
        $this->forceFill(['hidden_by_role' => self::UKRYL_AUTOR])->save();
    }

    public function odkryj(): void
    {
        $this->forceFill(['hidden_at' => null, 'hidden_by_role' => null])->save();
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
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }
}
