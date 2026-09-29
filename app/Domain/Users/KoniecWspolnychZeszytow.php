<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\User;

/**
 * Koniec wspólnych zeszytów przy blokadzie i przy usunięciu konta (#1743, D-302).
 *
 * DLACZEGO TO JEST KONTRAKT, A NIE WYWOŁANIE `ZerwijWspoldzielenie`
 * Blokadę zakłada moduł `Social` (`BlockUser`), a konto wymazuje `Users`
 * (`EraseAccountData`) — oba muszą skończyć członkostwa we wspólnych
 * zeszytach, które należą do modułu `Collections`. Zeszyty z kolei potrzebują
 * obu (`ZamekPary` i `ZamekKonta` pod zapisem i zaproszeniem). Bezpośredni
 * import zamykał cykle `Social ↔ Collections` i `Users → Collections →
 * Social → Users` — dokładnie ten rodzaj, który #971 kazał rozcinać
 * kontraktem, jak `ObserwowanieGospodarza`.
 *
 * Kontrakt mieszka po stronie wołających (`Users`, od którego `Social` już
 * zależy), implementacja w `Collections`
 * (`App\Domain\Collections\Wspoldzielenie\ZerwijWspoldzielenie`), a łączy je
 * `AppServiceProvider`. Kierunek zależności to wtedy wyłącznie
 * `Collections → Users`. Pilnuje tego `GrafModulowDomenyBezCykliTest`.
 *
 * Reguły (co znika, w jakiej kolejności blokad, co zostaje) — w implementacji.
 */
interface KoniecWspolnychZeszytow
{
    /**
     * Blokada w którąkolwiek stronę: członkostwa w obie strony i oczekujące
     * zaproszenia po nazwie konta między tymi osobami. Wołać pod zamkiem pary.
     */
    public function miedzy(User $a, User $b): void;

    /** Usunięcie konta (każdy zakres): członkostwa, zaproszenia i podpisy tej osoby. */
    public function przyWymazaniu(User $user): void;
}
