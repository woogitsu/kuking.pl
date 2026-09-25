<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use App\Notifications\LinkDoLogowania;
use App\Support\Poczta;
use App\Turnstile\KlientTurnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OdmowaBudzetuZachowujeAdresTest extends TestCase
{
    use RefreshDatabase;

    public static function refusals(): array
    {
        return ['ścisk' => [true], 'wyczerpana pula' => [false]];
    }

    #[DataProvider('refusals')]
    public function test_odmowa_zachowuje_tylko_email_i_pozwala_ponowic(bool $contention): void
    {
        config(['mail.default' => 'smtp', 'kuking.login_link.dzienny_budzet' => $contention ? 120 : 0]);
        config([
            'kuking.turnstile.klucz_publiczny' => 'testowy-klucz',
            'kuking.turnstile.sekret' => 'testowy-sekret',
            'kuking.turnstile.miejsca.logowanie_linkiem' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake([KlientTurnstile::ADRES => Http::response(['success' => true, 'hostname' => 'localhost'])]);
        Notification::fake();
        $this->assertTrue(Poczta::dziala()); // Kontrola konfiguracji, bez połączenia z dostawcą.
        $user = $this->user();
        $lock = Cache::lock('poczta:budzet:blokada:link-logowania', 30);
        if ($contention) {
            $this->assertTrue($lock->get());
        }
        try {
            $response = $this->from(route('login.link'))->post(route('login.link.send'), [
                'email' => $user->email,
                'cf-turnstile-response' => 'testowa-odpowiedz',
                'password' => 'nie-zachowuj',
            ]);
        } finally {
            if ($contention) {
                $lock->release();
            }
        }
        $response->assertRedirect(route('login.link'))->assertSessionHasNoErrors();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('login_link_tokens', 0);
        $this->assertSame(0, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());
        $response->assertSessionHas('_old_input', ['email' => $user->email]);
        $status = session('status');
        $this->assertStringContainsString($contention ? 'Kliknij „Wyślij mi link” jeszcze raz' : 'nie czekaj na niego', $status);
        $html = $this->get(route('login.link'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $input = self::elementDom((new \DOMXPath($dom))->query('//input[@name="email"]')->item(0));
        $this->assertSame($user->email, $input->getAttribute('value'));
        if ($contention) {
            $this->from(route('login.link'))->post(route('login.link.send'), [
                'email' => $input->getAttribute('value'),
                'cf-turnstile-response' => 'nowa-odpowiedz',
            ])
                ->assertRedirect(route('login.link'))->assertSessionHasNoErrors();
            Notification::assertSentTo($user, LinkDoLogowania::class);
            $this->assertSame(1, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());
            Http::assertSentCount(2);
        }
    }
}
