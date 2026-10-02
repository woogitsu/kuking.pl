<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Models\CookedEvent;
use App\Support\FrazaWyszukiwania;
use Illuminate\Database\Eloquent\Builder;

/**
 * „Szukaj w moich wykonaniach” — fraza z pola na WŁASNEJ zakładce
 * „Ugotowane” (issue #2070).
 *
 * TEN SAM WZORZEC CO „SZUKAJ W MOICH ZESZYTACH” (#779): tytuł przepisu,
 * kolumna `recipes.title_search` (`kuking_normalize`, D-004/D-046) i fraza
 * po `FrazaWyszukiwania` — „zurek” znajdzie „Żurek”, a `%` i `_` są
 * zwykłym tekstem (#753). Te same progi długości i te same zdania błędu.
 *
 * FRAZA PASUJE TYLKO DO TYTUŁU, KTÓRY KARTA I TAK POKAZUJE.
 * Własna lista „Ugotowane” celowo nie jest filtrowana widocznością przepisu
 * (to zdjęcie i notatka kucharza), a karta sama decyduje, co powiedzieć
 * o przepisie (`components/cooked-card.blade.php`):
 *  - przepis usunięty — „już nie ma”, bez tytułu (A23). `whereHas('recipe')`
 *    pomija miękko usunięte, więc do frazy nie pasuje;
 *  - autor za blokadą w którąkolwiek stronę — karta chowa tytuł (#1394),
 *    więc fraza też go nie dotyka. Inaczej wpisanie „bigos” i jedna karta
 *    w wyniku zdradzałyby tytuł, którego ekran celowo nie pokazuje;
 *  - przepis prywatny albo ukryty przez moderację — karta pokazuje tytuł
 *    jako zwykły tekst (#766), więc fraza go znajduje. Nic, czego człowiek
 *    już nie widzi na tej samej liście.
 * Wyszukiwanie niczego do listy nie DOKŁADA — tylko ją zawęża.
 *
 * ROZSZERZENIE (#2472): fraza pasuje też do WŁASNEJ uwagi (`note`) i tekstu
 * „Po swojemu” (`changes_note`) tego wykonania — dwa pola, które karta pokazuje
 * obok zdjęcia. Warunki są alternatywą wewnątrz zakresu właściciela (lista jest
 * już jego), a ochrona tytułów zostaje: gałąź TYTUŁU działa jak dotąd (przepis
 * usunięty albo autor za blokadą nie pasuje do frazy). Gałęzie uwagi i „Po
 * swojemu” dotyczą wyłącznie tekstu, który napisał właściciel, więc wykonanie
 * znalezione po nich nie ujawnia ani tytułu, ani treści przepisu — karta dalej
 * chowa tytuł, a pokazuje własne zdjęcie i notatkę.
 */
final class FrazaWUgotowanych
{
    public const ETYKIETA = 'Szukaj w moich wykonaniach';

    private function __construct(
        public readonly string $fraza,
        public readonly ?string $blad,
    ) {}

    public static function zAdresu(mixed $wartosc): self
    {
        $fraza = is_string($wartosc) ? trim($wartosc) : '';

        if ($fraza === '') {
            return new self('', null);
        }

        if (mb_strlen($fraza) > SearchQuery::MAX_PHRASE_LENGTH) {
            return new self($fraza, 'Skróć tekst w polu „'.self::ETYKIETA.'” do '.SearchQuery::MAX_PHRASE_LENGTH.' znaków i spróbuj ponownie.');
        }

        // Długość PO normalizacji (#1050): fraza z samych emoji znika
        // w `Str::ascii()` i dawałaby `LIKE '%%'`, czyli wszystko.
        if (mb_strlen(FrazaWyszukiwania::normalizuj($fraza)) < 2) {
            return new self($fraza, 'Wpisz co najmniej dwie litery z tytułu przepisu, swojej uwagi albo tekstu „Po swojemu”.');
        }

        return new self($fraza, null);
    }

    /** Czy lista ma być zawężona — fraza jest i nie ma błędu. */
    public function aktywna(): bool
    {
        return $this->fraza !== '' && $this->blad === null;
    }

    /**
     * @param  Builder<CookedEvent>  $query
     * @param  list<string>  $autorzyZaBlokada  osoby z blokadą z właścicielem (w obie strony)
     */
    public function zawez(Builder $query, array $autorzyZaBlokada): void
    {
        if (! $this->aktywna()) {
            return;
        }

        $wzorzec = '%'.FrazaWyszukiwania::doLike(FrazaWyszukiwania::normalizuj($this->fraza)).'%';

        $query->where(function ($szukaj) use ($wzorzec, $autorzyZaBlokada): void {
            $szukaj->whereHas('recipe', function ($przepis) use ($wzorzec, $autorzyZaBlokada): void {
                $przepis->where('recipes.title_search', 'like', $wzorzec)
                    ->when($autorzyZaBlokada !== [], fn ($q) => $q->whereNotIn('recipes.author_id', $autorzyZaBlokada));
            })
                // Własny tekst wykonania (#2472): ta sama normalizacja co `title_search`
                // (`kuking_normalize`), puste pola (NULL) nie pasują do niczego.
                ->orWhereRaw('kuking_normalize(cooked_events.note) like ?', [$wzorzec])
                ->orWhereRaw('kuking_normalize(cooked_events.changes_note) like ?', [$wzorzec]);
        });
    }
}
