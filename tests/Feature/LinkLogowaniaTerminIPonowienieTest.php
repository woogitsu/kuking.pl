<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use App\Domain\Security\WyslijLinkDoLogowania;
use App\Models\LoginLinkToken;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Support\Turnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #889 — list z linkiem logowania pilnuje terminu KONKRETNEGO linku przy
 * wykonaniu zadania z kolejki: po terminie nie wychodzi, przy opóźnieniu
 * mówi, ile naprawdę zostało.
 *
 * #890 — chwilowa odmowa rezerwacji budżetu każe kliknąć jeszcze raz,
 * więc wpisany adres musi zostać w polu.
 *
 * Powiadomienie jest wykonywane naprawdę: serializacja zadania, odtworzenie
 * i kanał pocztowy z `ArrayTransport`. Atrapą jest wyłącznie kolejka, która
 * przechwytuje zlecenie.
 */
class LinkLogowaniaTerminIPonowienieTest extends TestCase
{
    use RefreshDatabase;

    private QueueFake $kolejka;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'kuking.login_link.waznosc_minut' => 30]);
    }

    /** @return array<string, array{int, int, ?string}> */
    public static function opoznienia(): array
    {
        return [
            'od razu' => [0, 1, 'przez pół godziny od chwili zamówienia'],
            'po 25 minutach' => [25 * 60, 1, 'jeszcze przez około 5 min.'],
            'sekunda przed' => [1799, 1, 'jeszcze przez niecałą minutę'],
            'dokładnie termin' => [1800, 0, null],
            'po terminie' => [1801, 0, null],
        ];
    }

    #[DataProvider('opoznienia')]
    public function test_opozniona_wysylka_pilnuje_terminu_linku(int $sekundy, int $listow, ?string $tekst): void
    {
        [$user, $payload] = $this->zlecenie();
        $przed = LoginLinkToken::sole()->getAttributes();

        $this->travel($sekundy)->seconds();
        // Worker startuje z inną konfiguracją; stary link nie dostaje nowego terminu.
        config(['kuking.login_link.waznosc_minut' => 60]);
        $this->dostarcz($payload, $listow);

        // Ani odnowienia tokenu, ani przesunięcia terminu.
        $this->assertSame($przed, LoginLinkToken::sole()->getAttributes());
        $this->assertSame($user->id, LoginLinkToken::sole()->user_id);

        if ($tekst !== null) {
            $html = $this->html();
            $this->assertStringContainsString('Link działa '.$tekst, $html);
            if ($sekundy > 0) {
                $this->assertStringNotContainsString('przez pół godziny', $html);
                $this->assertStringContainsString('list wyszedł z opóźnieniem', $html);
            }
        }
    }

    public function test_stare_zadanie_bez_daty_czyta_termin_z_bazy(): void
    {
        [$user] = $this->zlecenie();
        $token = $this->tokenZWiersza($user);

        $payload = serialize(new SendQueuedNotifications($user, new LinkDoLogowania($token), ['mail']));
        $this->dostarcz($payload, 1);
        $this->assertStringContainsString('przez pół godziny od chwili zamówienia', $this->html());

        $this->travel(1801)->seconds();
        $this->dostarcz($payload, 1);
    }

    public function test_wczesniejsza_przekazana_data_skraca_termin(): void
    {
        [$user] = $this->zlecenie();
        $token = $this->tokenZWiersza($user);

        $payload = serialize(new SendQueuedNotifications($user, new LinkDoLogowania($token, now()->addMinutes(10)), ['mail']));
        $this->dostarcz($payload, 1);
        $this->assertStringContainsString('przez 10 min. od chwili zamówienia', $this->html());

        $this->travel(10)->minutes();
        $this->dostarcz($payload, 1);
    }

    public function test_zastapiony_link_nie_wychodzi(): void
    {
        [$user, $payload] = $this->zlecenie();

        // Druga prośba zastępuje wiersz — pierwszy list nie ma już czego nieść.
        $this->assertTrue(app(WyslijLinkDoLogowania::class)->handle($user->email, '127.0.0.1'));
        $this->dostarcz($payload, 0);
    }

    public function test_odmowa_rezerwacji_zachowuje_wpisany_adres(): void
    {
        config(['mail.default' => 'smtp', 'kuking.login_link.dzienny_budzet' => 120]);
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        Notification::fake();

        $cudza = Cache::lock('poczta:budzet:blokada:link-logowania', 30);
        $this->assertTrue($cudza->get());
        try {
            $odpowiedz = $this->from(route('login.link'))->post(route('login.link.send'), [
                'email' => 'basia@example.com',
                Turnstile::POLE => 'jednorazowy-token',
            ]);
        } finally {
            $cudza->release();
        }

        $odpowiedz->assertRedirect(route('login.link'));
        $odpowiedz->assertSessionHasNoErrors();
        $odpowiedz->assertSessionHasInput('email', 'basia@example.com');
        $this->assertArrayNotHasKey(Turnstile::POLE, (array) session('_old_input'));
        $this->assertStringContainsString('jeszcze raz', (string) session('status'));

        Notification::assertNotSentTo($basia, LinkDoLogowania::class);
        $this->assertDatabaseCount('login_link_tokens', 0);
        $this->assertSame(0, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());

        // Adres jest naprawdę w polu formularza po powrocie.
        $this->get(route('login.link'))->assertOk()->assertSee('value="basia@example.com"', false);
    }

    public function test_zwykla_prosba_nie_zostawia_adresu_w_sesji(): void
    {
        config(['mail.default' => 'smtp']);
        $this->user('basia', ['email' => 'basia@example.com']);
        Notification::fake();

        $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => 'basia@example.com'])
            ->assertRedirect()
            ->assertSessionMissing('_old_input');
    }

    /** @return array{User, string} */
    private function zlecenie(): array
    {
        // Zegar zamrożony TYLKO tu: `Lock::block()` budżetu liczy czekanie
        // z `now()`, więc w testach HTTP z trzymaną blokadą czekałby w nieskończoność.
        $this->freezeTime();
        $this->kolejka = Queue::fake();
        $user = $this->user('basia', ['email' => 'basia@example.com']);
        $this->assertTrue(app(WyslijLinkDoLogowania::class)->handle($user->email, '127.0.0.1'));

        return [$user, serialize($this->kolejka->pushed(SendQueuedNotifications::class)->sole())];
    }

    /** Token jawny z payloadu zlecenia — w bazie leży tylko skrót. */
    private function tokenZWiersza(User $user): string
    {
        $zadanie = $this->kolejka->pushed(SendQueuedNotifications::class)->sole();
        $token = (fn (): string => $this->token)->call($zadanie->notification);
        $this->assertNotNull(LoginLinkToken::znajdzPoTokenie($token));

        return $token;
    }

    private function dostarcz(string $payload, int $listow): void
    {
        unserialize($payload)->handle(app(ChannelManager::class));
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount($listow, $transport->messages(), 'Wygasły albo zastąpiony link nie może trafić do transportu.');
    }

    private function html(): string
    {
        $html = self::transportTablicowy()->messages()->last()->getOriginalMessage()->getHtmlBody();

        return html_entity_decode(preg_replace('/\s+/', ' ', (string) $html) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
