<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Push\TransportPush;
use App\Domain\Notifications\Push\WynikWysylkiPush;
use App\Jobs\WyslijPowiadomieniePush;
use App\Models\Notification;
use App\Models\PushSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FalszywyTransportPush;
use Tests\TestCase;

/** Dwa połączenia PostgreSQL widzą tę samą zatwierdzoną rezerwację. */
final class PowiadomieniaPushDwaPolaczeniaTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $odbiorcaId = null;

    protected function tearDown(): void
    {
        try {
            // DatabaseMigrations wycofuje schemat po teście. Najpierw usuwamy
            // wyłącznie własne powiadomienia z UUID grupy; strażnik down()
            // słusznie odmówiłby utraty tych danych.
            if ($this->odbiorcaId !== null) {
                DB::connection()->table('notifications')->where('user_id', $this->odbiorcaId)->delete();
            }
            DB::disconnect('push_worker_2');
        } finally {
            parent::tearDown();
        }
    }

    public function test_drugi_worker_widzi_slot_zanim_pierwszy_transport_odpowie(): void
    {
        config([
            'kuking.strefa' => 'Europe/Warsaw',
            'kuking.push.vapid_public_key' => 'BTestowyKluczPublicznyNieDoWysylki',
            'kuking.push.vapid_private_key' => 'testowy-klucz-prywatny',
            'kuking.notifications.zewnetrzne.wlaczone' => true,
            'kuking.notifications.zewnetrzne.cisza_od_godziny' => 21,
            'kuking.notifications.zewnetrzne.cisza_do_godziny' => 8,
            'kuking.notifications.zewnetrzne.dzienny_limit' => 1,
            'database.connections.push_worker_2' => config('database.connections.pgsql'),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));
        $autor = $this->user('autor_dwa_polaczenia');
        $this->odbiorcaId = (string) $autor->getKey();
        $aktor = $this->user('aktor_dwa_polaczenia');
        (new PushSubscription)->forceFill([
            'user_id' => $autor->getKey(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/dwa-polaczenia',
            'klucz_p256dh' => str_repeat('A', 87),
            'klucz_auth' => str_repeat('B', 22),
            'kodowanie' => 'aes128gcm',
        ])->save();
        $pierwsze = Notification::create([
            'user_id' => $autor->getKey(),
            'actor_id' => $aktor->getKey(),
            'type' => Notification::TYPE_COOKED,
            'data' => ['recipe_title' => 'Pierwsze'],
        ]);
        Queue::fake();

        $drugiTransport = new FalszywyTransportPush;
        $zaobserwowano = false;
        $transport = new class(function () use ($autor, $aktor, $pierwsze, $drugiTransport, &$zaobserwowano): void {
            $drugi = DB::connection('push_worker_2');
            $this->assertSame('pgsql', $drugi->getDriverName());
            $this->assertNotNull($drugi->table('notifications')->where('id', $pierwsze->getKey())->value('push_proba_at'));
            $this->assertNull($drugi->table('notifications')->where('id', $pierwsze->getKey())->value('push_wyslano_at'));
            $zaobserwowano = true;

            $pierwotne = DB::getDefaultConnection();
            DB::setDefaultConnection('push_worker_2');
            try {
                Notification::create([
                    'user_id' => $autor->getKey(),
                    'actor_id' => $aktor->getKey(),
                    'type' => Notification::TYPE_COOKED,
                    'data' => ['recipe_title' => 'Drugie'],
                ]);
                (new WyslijPowiadomieniePush((string) $autor->getKey()))->handle($drugiTransport);
            } finally {
                DB::setDefaultConnection($pierwotne);
            }
        }) implements TransportPush
        {

            public function __construct(private readonly \Closure $podczasTransportu) {}

            public function wyslij(PushSubscription $subskrypcja, string $tresc): WynikWysylkiPush
            {
                ($this->podczasTransportu)();

                return WynikWysylkiPush::Wyslano;
            }
        };

        (new WyslijPowiadomieniePush((string) $autor->getKey()))->handle($transport);

        $this->assertTrue($zaobserwowano, 'Pierwszy transport nie doszedł do pauzy.');
        $this->assertSame([], $drugiTransport->wyslane, 'Drugi worker przekroczył limit 1.');
        $this->assertNotNull($pierwsze->refresh()->push_wyslano_at);
        Queue::assertPushed(WyslijPowiadomieniePush::class, 1);
    }
}
