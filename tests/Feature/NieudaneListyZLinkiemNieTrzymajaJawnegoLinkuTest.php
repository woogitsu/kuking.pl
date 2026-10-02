<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\PotwierdzenieNowegoAdresu;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * `failed_jobs` NIE TRZYMA JAWNEGO LINKU ANI ŻETONU Z LISTÓW LOGOWANIA
 * I POTWIERDZEŃ (#2708, pytanie 8 analizy prawnej z 2.10.2026).
 *
 * Gwarancja ma dwie nogi:
 *  1. powiadomienie, które NIESIE odnośnik albo żeton w konstruktorze, jest
 *     szyfrowane kluczem aplikacji (`ShouldBeEncrypted`) — pilnuje tego
 *     test strukturalny po wszystkich klasach z `app/Notifications`, więc
 *     nowy list z żetonem bez szyfrowania zapali się od razu;
 *  2. `PotwierdzenieAdresu` (rejestracja) nie niesie niczego: link powstaje
 *     dopiero w chwili wysyłki.
 * Sprzątanie po czasie: `queue:prune-failed` co dobę (30 dni), a ręcznie
 * `kuking:martwe-zadania` z progiem ważności każdego żetonu.
 */
class NieudaneListyZLinkiemNieTrzymajaJawnegoLinkuTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://kuking.pl/ustawienia/e-mail/potwierdz/ZNACZNIKLINKU2708?signature=PODPIS2708';

    public function test_kazdy_list_w_kolejce_z_zetonem_lub_linkiem_jest_szyfrowany(): void
    {
        $sprawdzone = 0;

        foreach (glob(app_path('Notifications/*.php')) ?: [] as $plik) {
            $klasa = 'App\\Notifications\\'.basename($plik, '.php');

            if (! class_exists($klasa)) {
                continue;
            }

            $odbicie = new ReflectionClass($klasa);

            if (! $odbicie->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $parametry = array_map(
                fn (\ReflectionParameter $p): string => $p->getName(),
                $odbicie->getConstructor()?->getParameters() ?? [],
            );

            if (array_intersect($parametry, ['token', 'zeton', 'linkUrl', 'url', 'link']) === []) {
                continue;
            }

            $sprawdzone++;
            $this->assertTrue(
                $odbicie->implementsInterface(ShouldBeEncrypted::class),
                "$klasa niesie żeton albo link w konstruktorze, a jej ładunek w kolejce nie jest szyfrowany (#2708).",
            );
        }

        // Kontrola dodatnia: strażnik widzi listy z żetonem (logowanie,
        // hasło x2, zaproszenie, Facebook) i potwierdzenie nowego adresu.
        $this->assertGreaterThanOrEqual(6, $sprawdzone);
    }

    public function test_nieudane_potwierdzenie_nowego_adresu_nie_zostawia_jawnego_linku(): void
    {
        Mail::extend('odmawia2708', fn (array $config): AbstractTransport => new class extends AbstractTransport
        {
            public function __toString(): string
            {
                return 'odmawia2708://';
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Dostawca nie przyjął wiadomości.');
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Dostawca nie przyjął wiadomości.');
            }
        });

        config([
            'queue.default' => 'database',
            'mail.default' => 'odmawia2708',
            'mail.mailers.odmawia2708' => ['transport' => 'odmawia2708'],
        ]);

        Notification::route('mail', 'nowy@przyklad.pl')->notify(
            new PotwierdzenieNowegoAdresu(self::LINK, now()->addDay(), 'Maria'),
        );

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'high,default', '--once' => true, '--tries' => 1]);

        $this->assertSame(1, DB::table('failed_jobs')->count(), 'Wysyłka miała się nie udać i zostawić wiersz w failed_jobs.');

        $wiersz = DB::table('failed_jobs')->first();
        $this->assertNotNull($wiersz);
        $wszystko = $wiersz->payload."\n".$wiersz->exception;

        $this->assertStringNotContainsString('ZNACZNIKLINKU2708', $wszystko);
        $this->assertStringNotContainsString('PODPIS2708', $wszystko);
    }
}
