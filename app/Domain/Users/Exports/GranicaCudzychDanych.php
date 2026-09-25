<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

/**
 * JEDNA granica dla cudzych danych w paczce RODO (audyt B2-05, B2-06, B5 pkt 12,
 * issue #1245).
 *
 * Paczka oddaje człowiekowi JEGO dane, ale przy nich stoją cudze: nazwy
 * obserwujących, treść wpisu, pod którym komentował, tytuł przepisu, który
 * ugotował, komentarze innych pod jego treścią. Na ekranie każdą z tych rzeczy
 * pilnuje Policy albo zakres widoczności; w paczce każda szła osobną drogą i
 * część — bez żadnej granicy. Skutek: osoba, która poprosiła o usunięcie konta
 * (`pending_delete`), albo zbanowana, dalej figurowała z imienia w cudzych
 * paczkach, a fragment wpisu przełączonego na „Tylko ja” wychodził na zawsze
 * w ZIP-ie, który da się komuś wysłać.
 *
 * Ta klasa jest jedynym miejscem, które mówi „co z cudzych danych wolno tej
 * osobie wynieść” — i mówi to TYM SAMYM językiem co ekran:
 *
 *  - osoby: `widocznyJakoOsoba()` i brak blokady w którąkolwiek stronę —
 *    dokładnie warunek listy obserwujących (`SocialController`);
 *  - komentarze: `Comment::widoczneDla()` na korzeniach i odpowiedziach —
 *    jak pod przepisem, wpisem i wykonaniem;
 *  - treść nadrzędna (wpis, przepis, wykonanie): `Gate::allows('view')` —
 *    ta sama Policy co pod adresem tej treści.
 *
 * Czego NIE filtrujemy: własnych danych tej osoby (jej wpisów, przepisów,
 * komentarzy, listy osób, które SAMA zablokowała) — to są jej decyzje i jej
 * treść, nie cudza. Ile schowaliśmy, paczka mówi liczbą — tak jak zeszyty.
 */
final class GranicaCudzychDanych
{
    /** @var array<string, bool> */
    private array $widoczne = [];

    public function __construct(private readonly User $widz) {}

    /**
     * Zawęża zapytanie o osoby do tych, które ekran pokazałby na liście.
     *
     * @template T of Builder|Relation
     *
     * @param  T  $zapytanie
     * @return T
     */
    public function osoby(Builder|Relation $zapytanie): Builder|Relation
    {
        $widzId = $this->widz->getKey();

        return $zapytanie
            ->widocznyJakoOsoba()
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('blocks')
                ->where(fn ($w) => $w->where('blocks.blocker_id', $widzId)->whereColumn('blocks.blocked_id', 'users.id'))
                ->orWhere(fn ($w) => $w->whereColumn('blocks.blocker_id', 'users.id')->where('blocks.blocked_id', $widzId)));
    }

    /**
     * Relacje cudzych komentarzy pod treścią widza — korzenie i odpowiedzi
     * przez `widoczneDla()` (issue #1245).
     *
     * @return array<string|int, mixed>
     */
    public function relacjeKomentarzy(): array
    {
        return [
            'comments' => fn ($q) => $q->widoczneDla($this->widz),
            'comments.author.profile',
            'comments.replies' => fn ($q) => $q->widoczneDla($this->widz),
            'comments.replies.author.profile',
        ];
    }

    /**
     * Czy widz może dziś zobaczyć tę cudzą treść (wpis, przepis, wykonanie).
     * Brak treści (skasowana) = nie. Wynik zapamiętany na czas budowy paczki.
     */
    public function widzi(?Model $tresc): bool
    {
        if ($tresc === null) {
            return false;
        }

        $klucz = $tresc::class.':'.$tresc->getKey();

        return $this->widoczne[$klucz] ??= Gate::forUser($this->widz)->allows('view', $tresc);
    }
}
