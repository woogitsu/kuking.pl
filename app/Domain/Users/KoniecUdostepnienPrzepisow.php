<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\User;

/**
 * Koniec udostępnień przepisów przy blokadzie i przy wymazaniu konta (#2650).
 *
 * Ten sam kształt i ten sam powód co `KoniecWspolnychZeszytow`: blokadę
 * zakłada `Social` (`BlockUser`), konto wymazuje `Users`
 * (`EraseAccountData`), a udostępnienia należą do `Recipes`, który sam
 * korzysta z `ZamekPary` (`Social`). Bezpośredni import zamknąłby cykl
 * `Social ↔ Recipes`. Kontrakt mieszka tutaj, implementacja
 * w `App\Domain\Recipes\Udostepnienia\ZerwijUdostepnieniaPrzepisow`, łączy
 * je `AppServiceProvider` (`GrafModulowDomenyBezCykliTest`).
 */
interface KoniecUdostepnienPrzepisow
{
    /**
     * Blokada w którąkolwiek stronę: znikają udostępnienia przepisów A dla B
     * i przepisów B dla A. Odblokowanie ich nie przywraca. Wołać pod zamkiem pary.
     */
    public function miedzy(User $a, User $b): void;

    /** Wymazanie konta (każdy zakres): udostępnienia dla tej osoby i jej przepisów. */
    public function przyWymazaniu(User $user): void;
}
