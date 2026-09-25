<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\User;

/**
 * Nowe konto zaczyna obserwować gospodarza (docs/product/COLD_START.md).
 *
 * DLACZEGO TO JEST KONTRAKT, A NIE WYWOŁANIE `FollowUser` (issue #971)
 * `ZalozKonto` jest jednym wejściem dla wszystkich dróg rejestracji (D-069),
 * więc obserwowanie gospodarza musi zostać wywołane właśnie stamtąd. Samo
 * obserwowanie należy jednak do modułu `Social`, który z kolei potrzebuje
 * `Users` (`ZamekPary` → `ZamekKonta`, D-079/D-080). Bezpośredni import
 * `FollowUser` w `ZalozKonto` zamykał cykl `Users → Social → Users`.
 *
 * Kontrakt mieszka po stronie wołającego, implementacja po stronie `Social`
 * (`App\Domain\Social\Actions\ObserwujGospodarza`), a łączy je
 * `AppServiceProvider` — jedyne miejsce, które zna oba moduły. Kierunek
 * zależności to wtedy wyłącznie `Social → Users`. Pilnuje tego
 * `GrafModulowDomenyBezCykliTest`.
 *
 * Polityka awarii zostaje w `ZalozKonto` (`BladDlaCzlowieka` po cichu, reszta
 * do `report()`), bo to reguła rejestracji, nie obserwowania.
 */
interface ObserwowanieGospodarza
{
    /**
     * Gospodarza wskazuje `App\Domain\Community\HostUserResolver` (po UUID
     * konta, #1089) — implementacja sama go odszukuje.
     *
     * @throws BladDlaCzlowieka gdy obserwowanie jest świadomie niemożliwe
     */
    public function zacznij(User $konto): void;
}
