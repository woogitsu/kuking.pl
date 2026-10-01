<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Users\DawneNazwyProfilu;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Adapter HTTP dla dawnych nazw profilu: 301 na ten sam ekran pod aktualną
 * nazwą (ta sama trasa, te same parametry zapytania) albo `null` — wtedy
 * wołający odpowiada 404. Decyzję (czy wolno i dokąd) podejmuje domena
 * (`DawneNazwyProfilu::profilDoPrzekierowania`), tu tylko składamy odpowiedź.
 */
final class PrzekierowanieDawnejNazwy
{
    /**
     * @param  string  $trasa  nazwa trasy z parametrem `username`
     */
    public static function dla(Request $request, ?User $widz, string $nazwa, string $trasa): ?RedirectResponse
    {
        $profil = (new DawneNazwyProfilu)->profilDoPrzekierowania($widz, $nazwa);

        if ($profil === null) {
            return null;
        }

        // Bez jawnego zakazu cache przeglądarka (a czytnik kanałów, który
        // omija warstwę `web`) mogłaby zapamiętać 301 na stałe. Po powrocie
        // osoby do dawnej nazwy dwa zapamiętane przekierowania (A → B i B → A)
        // dałyby u tego odwiedzającego pętlę.
        $odpowiedz = redirect()->route($trasa, [...$request->query(), 'username' => $profil->username], 301);
        $odpowiedz->headers->set('Cache-Control', 'private, no-store');

        return $odpowiedz;
    }
}
