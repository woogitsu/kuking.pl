<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Prawdziwa wysyłka przez standard Web Push z podpisem VAPID
 * (`minishlink/web-push`). Treść jest szyfrowana kluczem przeglądarki —
 * usługa push (Google, Mozilla, Apple) przenosi ją, ale nie czyta.
 */
final class TransportWebPush implements TransportPush
{
    private ?WebPush $webPush = null;

    public function wyslij(PushSubscription $subskrypcja, string $tresc): WynikWysylkiPush
    {
        try {
            $raport = $this->webPush()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subskrypcja->endpoint,
                    'publicKey' => $subskrypcja->klucz_p256dh,
                    'authToken' => $subskrypcja->klucz_auth,
                    'contentEncoding' => $subskrypcja->kodowanie,
                ]),
                $tresc,
                ['TTL' => (int) config('kuking.push.ttl_sekund', 86400), 'urgency' => 'normal'],
            );
        } catch (Throwable) {
            return WynikWysylkiPush::Blad;
        }

        if ($raport->isSuccess()) {
            return WynikWysylkiPush::Wyslano;
        }

        return $raport->isSubscriptionExpired() ? WynikWysylkiPush::Wygasla : WynikWysylkiPush::Blad;
    }

    private function webPush(): WebPush
    {
        return $this->webPush ??= new WebPush([
            'VAPID' => [
                'subject' => (string) config('kuking.push.vapid_subject'),
                'publicKey' => (string) config('kuking.push.vapid_public_key'),
                'privateKey' => (string) config('kuking.push.vapid_private_key'),
            ],
        ]);
    }
}
