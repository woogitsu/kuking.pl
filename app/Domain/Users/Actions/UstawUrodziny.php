<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Rocznice\Urodziny;
use App\Domain\Zgody\PrzestawZgodeNaZyczeniaMailem;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Zapis i usunięcie daty urodzin (issue #1755).
 *
 * Kolumny `birthday_day` i `birthday_month` są poza `$fillable` — nie dlatego,
 * że są sterujące, tylko żeby żaden `update($request->all())` w innym
 * formularzu nie wpisał ich przy okazji. Jedyna droga zapisu to ta klasa.
 */
final class UstawUrodziny
{
    public function __construct(private readonly PrzestawZgodeNaZyczeniaMailem $zgodaNaMail) {}

    public function zapisz(User $user, int $dzien, int $miesiac): void
    {
        if (! Urodziny::poprawna($dzien, $miesiac)) {
            // Walidator w kontrolerze łapie to wcześniej, a CHECK w bazie
            // później — ten wyjątek pilnuje drogi z pominięciem obu.
            throw new InvalidArgumentException("Nie ma takiej daty: {$dzien}.{$miesiac}.");
        }

        $user->forceFill([
            'birthday_day' => $dzien,
            'birthday_month' => $miesiac,
        ])->save();
    }

    /**
     * „Usuń datę" wyłącza też wszystko, co od daty zależy: zgoda na mail
     * z życzeniami zostaje wycofana (z wpisem w dzienniku zgód), żeby po
     * ponownym podaniu daty list nie wrócił bez nowej decyzji.
     */
    public function usun(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'birthday_day' => null,
                'birthday_month' => null,
            ])->save();

            $this->zgodaNaMail->handle($user, false, WpisZgody::ZRODLO_USTAWIENIA);
        });
    }
}
