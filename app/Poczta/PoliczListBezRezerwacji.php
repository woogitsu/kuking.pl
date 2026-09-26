<?php

declare(strict_types=1);

namespace App\Poczta;

use App\Domain\Security\DziennyBudzetListow;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * KAŻDY LIST, KTÓRY WYCHODZI DO DOSTAWCY, JEST W RACHUNKU PULI (audyt B8-02).
 *
 * `MessageSending` leci w chwili prawdziwej wysyłki (worker albo wysyłka
 * synchroniczna), tuż przed transportem. List z nagłówkiem
 * `ListZarezerwowany::NAGLOWEK` ma już miejsce w puli — zdejmujemy tylko
 * znacznik, żeby nie wyszedł do dostawcy. Każdy inny list doliczamy do
 * wspólnego licznika w klasie `zwykla`.
 *
 * `zajmij()`, NIE `sprobujZarezerwowac()`: ten słuchacz LICZY, nie decyduje.
 * Odmowa tutaj oznaczałaby list, który wołający uznał za wysłany, a który
 * po cichu nie wyszedł — np. decyzja moderacyjna z terminem odwołania
 * (DSA art. 17). O tym, co gaśnie przy pustej puli, decydują drogi
 * z rezerwacją; ten licznik sprawia, że widzą one prawdziwe zużycie.
 *
 * Słuchacz NIE MA PRAWA przerwać wysyłki: wyjątek licznika (np. baza cache
 * chwilowo niedostępna) kończy się wpisem w dzienniku, a list wychodzi.
 * Zwraca `void` — `false` z tego zdarzenia anulowałoby list.
 */
final class PoliczListBezRezerwacji
{
    public function handle(MessageSending $zdarzenie): void
    {
        $naglowki = $zdarzenie->message->getHeaders();

        if ($naglowki->has(ListZarezerwowany::NAGLOWEK)) {
            $naglowki->remove(ListZarezerwowany::NAGLOWEK);

            return;
        }

        try {
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_ZWYKLA)->zajmij();
        } catch (Throwable $e) {
            Log::warning('Nie udało się doliczyć listu do wspólnej puli poczty — list wychodzi mimo to.', [
                'wyjatek' => $e::class,
            ]);
        }
    }
}
