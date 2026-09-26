<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Para kluczy VAPID dla Web Push (issue #35, D-303).
 *
 * Uruchamia się RAZ na środowisko, lokalnie albo w konsoli Railwaya, a wynik
 * wkleja do Shared Variables: `VAPID_PUBLIC_KEY` i `VAPID_PRIVATE_KEY`
 * („Sealed"). Komenda NICZEGO nie zapisuje — ani do `.env`, ani do bazy —
 * żeby klucz prywatny nie wylądował przypadkiem w repozytorium albo
 * w dzienniku wdrożenia.
 *
 * NIE ZMIENIAJ KLUCZY NA ŻYWYM ŚRODOWISKU bez powodu: każda zapisana
 * subskrypcja jest przypięta do klucza publicznego i po wymianie przestaje
 * przyjmować wysyłki (usługa push odpowie błędem, a wiersz zostanie
 * skasowany przy 404/410). Ludzie musieliby włączyć powiadomienia od nowa.
 */
class GenerujKluczeVapid extends Command
{
    protected $signature = 'kuking:klucze-vapid';

    protected $description = 'Wypisuje nową parę kluczy VAPID do wklejenia w zmienne środowiska (Web Push)';

    public function handle(): int
    {
        $klucze = VAPID::createVapidKeys();

        $this->line('Wklej do zmiennych środowiska (Railway → Shared Variables). Klucz prywatny oznacz jako „Sealed”.');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$klucze['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$klucze['privateKey']);
        $this->newLine();
        $this->warn('Nie wklejaj tych wartości do repozytorium ani do zgłoszeń. Każde środowisko dostaje własną parę.');

        return self::SUCCESS;
    }
}
