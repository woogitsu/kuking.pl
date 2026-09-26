<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Czas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Prywatne ukrycie wpisu albo osoby przez jednego widza (issue #1810, D-278).
 *
 * `$fillable` PUSTE: każda kolumna to albo klucz właściciela (`user_id`), albo
 * obiekt, albo termin — wszystkie ustawia jawna akcja domenowa
 * (`App\Domain\Ukrycia\Actions\*`), nigdy dane z żądania wprost.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $post_id
 * @property string|null $hidden_user_id
 * @property Carbon|null $hidden_until
 */
class Hide extends Model
{
    use HasUuids;

    protected $table = 'hides';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'hidden_until' => 'datetime',
        ];
    }

    /**
     * Ukrycie, które jeszcze działa: na stałe albo z terminem w przyszłości.
     *
     * @param  Builder<Hide>  $query
     */
    public function scopeAktywne(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('hides.hidden_until')->orWhere('hides.hidden_until', '>', now()));
    }

    /**
     * Domyślny koniec ukrycia: KONIEC DNIA w strefie człowieka, za
     * `kuking.ukrycia.dni` dni (przegląd #1781).
     *
     * Komunikat mówi „do 25 października" — człowiek czyta to jako „przez cały
     * ten dzień". Termin liczony od chwili kliknięcia (np. 25 października
     * o 9:14) oddawał wpis rano tego dnia, czyli wcześniej, niż obiecał
     * napis. Zapis w UTC, bo `timestamptz` i reszta modeli liczą w UTC.
     */
    public static function domyslnyTermin(): Carbon
    {
        return now()
            ->setTimezone(Czas::strefa())
            ->addDays((int) config('kuking.ukrycia.dni'))
            ->endOfDay()
            ->utc();
    }

    /**
     * Ukryj (albo przedłuż ukrycie) jednego obiektu jednego widza.
     *
     * PODWÓJNY KLIK NIE MOŻE DAĆ 500 (przegląd #1781). Dwa żądania naraz oba
     * nie znajdowały wiersza, oba robiły `INSERT`, a drugie padało na indeksie
     * unikalnym (`hides_user_post_unique` / `hides_user_person_unique`).
     * Teraz wstawienie to `INSERT … ON CONFLICT DO NOTHING` (`insertOrIgnore`)
     * i ponowny odczyt: kto przegrał wyścig, dostaje wiersz zwycięzcy.
     *
     * Ukrycie „na stałe" (`hidden_until = NULL`) zostaje na stałe — drugi klik
     * w menu nie może skrócić decyzji podjętej na liście „Ukryte".
     *
     * @param  'post_id'|'hidden_user_id'  $kolumna
     */
    public static function ukryjDla(User $widz, string $kolumna, string $obiektId): self
    {
        $znajdz = fn (): ?self => self::query()
            ->where('user_id', $widz->getKey())
            ->where($kolumna, $obiektId)
            ->first();

        $ukrycie = $znajdz();

        if ($ukrycie === null) {
            $teraz = now();
            self::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $widz->getKey(),
                $kolumna => $obiektId,
                'hidden_until' => self::domyslnyTermin(),
                'created_at' => $teraz,
                'updated_at' => $teraz,
            ]);

            return $znajdz() ?? throw new RuntimeException('Ukrycie nie zapisało się ani nie istnieje.');
        }

        if ($ukrycie->hidden_until !== null) {
            $ukrycie->forceFill(['hidden_until' => self::domyslnyTermin()])->save();
        }

        return $ukrycie;
    }

    public function jestAktywne(): bool
    {
        return $this->hidden_until === null || $this->hidden_until->isFuture();
    }

    public function naStale(): bool
    {
        return $this->hidden_until === null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function hiddenUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_user_id');
    }
}
