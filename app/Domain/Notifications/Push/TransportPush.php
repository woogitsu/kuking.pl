<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

use App\Models\PushSubscription;

/**
 * Jedno wysłanie jednej wiadomości na jedną przeglądarkę.
 *
 * Interfejs istnieje po to, żeby testy NIGDY nie wysyłały prawdziwych pushy:
 * podmieniają go fałszywym transportem (`Tests\Support\FalszywyTransportPush`),
 * a reguły — kto, kiedy, ile — sprawdzają bez sieci i bez przeglądarki.
 */
interface TransportPush
{
    public function wyslij(PushSubscription $subskrypcja, string $tresc): WynikWysylkiPush;
}
