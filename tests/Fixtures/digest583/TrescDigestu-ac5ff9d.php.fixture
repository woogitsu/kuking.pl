<?php

declare(strict_types=1);

namespace App\Domain\Digest;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\User;

/**
 * Treść JEDNEGO tygodniowego podsumowania — to, co ma się znaleźć w liście
 * do jednej konkretnej osoby (issue #11, `docs/DECISIONS.md` D-057).
 *
 * DLACZEGO OSOBNY OBIEKT, A NIE TABLICA PRZEKAZANA DO WIDOKU
 * Bo najważniejsza reguła całego mechanizmu — **pustego listu nie wysyłamy**
 * — jest pytaniem o TREŚĆ, nie o odbiorcę i nie o szablon. Gdyby mieszkała
 * w komendzie, drugie miejsce wysyłające list (podgląd, test ręczny,
 * przyszły przycisk „wyślij mi próbkę") musiałoby ją powtórzyć albo, co
 * bardziej prawdopodobne, zapomnieć. Tu jest jedna metoda `jestPusty()`
 * i jedno miejsce, w którym da się ją zepsuć.
 *
 * CO JEST W ŚRODKU I DLACZEGO AKURAT TO
 *
 * 1. **Kto ugotował z Twojego przepisu.** `AGENTS.md` §1 stawia to wyżej niż
 *    jakikolwiek lajk i to jest jedyny powód powrotu z górnej półki
 *    (`docs/product/RETENTION_LOOPS.md` §1: „ktoś zwrócił się do mnie").
 * 2. **Kto zaczął Cię obserwować.** Też osobiste, też o adresacie, a przy
 *    tym jedyna rzecz, którą ma nowa osoba bez ani jednego przepisu.
 * 3. **Co pokazali ludzie, których obserwujesz.** Chronologicznie, bez
 *    żadnego wyboru „najlepszych" — `AGENTS.md` §8 i §12.
 *
 * CZEGO TU CELOWO NIE MA
 * - **Rankingu.** Żadnych „najaktywniejszych", „najpopularniejszych" ani
 *   „top" czegokolwiek (`AGENTS.md` §12). Każda sekcja jest chronologiczna.
 * - **Komentarzy pod Twoimi treściami.** Nie dlatego, że są nieważne — bo
 *   już mają własne, natychmiastowe powiadomienie (`docs/product/
 *   RETENTION_LOOPS.md` §3.1). Powtórzenie ich po tygodniu w liście byłoby
 *   drugą wiadomością o tej samej rzeczy.
 * - **Propozycji nieznajomych („osoby, które warto poznać").** To jest
 *   redakcyjny wybór gospodarza, a nie coś, co wolno złożyć zapytaniem —
 *   każde automatyczne „warto poznać" jest rankingiem pod inną nazwą.
 */
final class TrescDigestu
{
    /**
     * @param  list<CookedEvent>  $wykonania  cudze „Ugotowałem" z przepisów adresata
     * @param  list<User>  $nowiObserwujacy  osoby pokazane z imienia (przycięte do limitu)
     * @param  int  $ileNowychObserwujacych  ilu ich było naprawdę — liczba bywa większa niż lista
     * @param  list<Post>  $wpisyObserwowanych  co pokazali obserwowani
     */
    public function __construct(
        public readonly User $odbiorca,
        public readonly array $wykonania,
        public readonly array $nowiObserwujacy,
        public readonly int $ileNowychObserwujacych,
        public readonly array $wpisyObserwowanych,
        public readonly ?string $pytanieGospodarza,
    ) {}

    public static function pusta(User $odbiorca): self
    {
        return new self($odbiorca, [], [], 0, [], null);
    }

    /**
     * Czy w tym liście NIE MA O CZYM PISAĆ.
     *
     * PYTANIE GOSPODARZA NIE LICZY SIĘ DO TREŚCI i to jest sedno tej metody.
     * Stoi w konfiguracji, więc jest takie samo dla wszystkich i w każdym
     * tygodniu — gdyby wystarczało do wysyłki, serwis co tydzień rozsyłałby
     * pięciuset osobom to samo jedno zdanie i nazywał to podsumowaniem.
     * `docs/product/RETENTION_LOOPS.md` §6 wiersz 5: list, który wygląda jak
     * marketing, kończy się wypisami, a wypisu nie da się cofnąć prośbą.
     */
    public function jestPusty(): bool
    {
        return $this->wykonania === []
            && $this->ileNowychObserwujacych === 0
            && $this->wpisyObserwowanych === [];
    }

    /**
     * Czy wydarzyło się coś, co dotyczy WPROST adresata.
     *
     * Rozstrzyga o temacie listu: „{imię} ugotowała Twój rosół" otwiera się
     * nieporównanie lepiej niż „Co się działo w Kuking" (`docs/brand/
     * COPY_STYLE.md` §6, wiersz „temat digestu").
     */
    public function maCosOsobistego(): bool
    {
        return $this->wykonania !== [] || $this->ileNowychObserwujacych > 0;
    }

    /**
     * Liczby do sygnału produktowego — bez adresu, bez nazw, bez treści.
     *
     * `App\Domain\Analytics\ZapiszSygnal` przyjmuje `properties` z twardym
     * zakazem danych osobowych (AGENTS.md §7), więc kształt tej tablicy jest
     * częścią tamtej umowy, a nie wygodą wywołującego.
     *
     * @return array<string, int>
     */
    public function miary(): array
    {
        return [
            'wykonania' => count($this->wykonania),
            'nowi_obserwujacy' => $this->ileNowychObserwujacych,
            'wpisy' => count($this->wpisyObserwowanych),
        ];
    }
}
