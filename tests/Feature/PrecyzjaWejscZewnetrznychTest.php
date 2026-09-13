<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PrecyzjaWejscZewnetrznychTest extends TestCase
{
    use RefreshDatabase;

    public function test_dostawcy_zachowuja_dostepnosc_i_nie_obiecuja_liczby_klikniec(): void
    {
        foreach ([false, true] as $google) {
            foreach ([false, true] as $facebook) {
                foreach (['google' => $google, 'facebook' => $facebook] as $provider => $enabled) {
                    config([
                        'kuking.'.$provider.'.wlaczone' => $enabled,
                        'kuking.'.$provider.'.identyfikator_klienta' => $enabled ? 'testowy-klient' : '',
                        'kuking.'.$provider.'.sekret_klienta' => $enabled ? 'testowy-sekret' : '',
                    ]);
                }
                $html = Blade::render('<x-wejscia-zewnetrzne />');
                $this->assertStringNotContainsString('jednym kliknięciem', $html);
                foreach (['google' => $google, 'facebook' => $facebook] as $provider => $enabled) {
                    $this->assertSame($enabled, str_contains($html, 'href="'.route($provider.'.start').'"'));
                }
                $single = Blade::render('<x-wejdz-google />');
                $this->assertStringNotContainsString('jednym kliknięciem', $single);
                $this->assertSame($google, str_contains($single, 'href="'.route('google.start').'"'));
            }
        }
    }

    public function test_instrukcje_i_mail_opisuja_przycisk_bez_gwarancji_jednego_klikniecia(): void
    {
        foreach ([
            ['auth.google-link', ['email' => 'pomiar@example.test', 'displayName' => 'Pomiar']],
            ['auth.facebook-bez-adresu', []],
            ['mail.proba-wejscia-kontem-facebooka', ['displayName' => 'Pomiar', 'linkLogowania' => 'https://example.test/logowanie']],
        ] as [$view, $data]) {
            $html = view($view, $data)->render();
            $this->assertStringNotContainsString('jednym kliknięciem', $html, $view);
            $this->assertStringContainsString('Wejdź kontem', $html, $view);
        }
    }

    public function test_pierwszy_wpis_zacheca_do_odpowiedzi_bez_tezy_o_retencji(): void
    {
        $user = $this->user('opiekun');
        $this->actingAs($user);
        $notice = Notification::create(['user_id' => $user->id, 'type' => Notification::TYPE_FIRST_POST, 'data' => ['display_name' => 'Nowa osoba']]);
        $html = view('pages.notifications', [
            'notifications' => Notification::whereKey($notice->id)->paginate(30),
            'decyzjeModeracyjne' => collect(),
        ])->render();
        $this->assertStringContainsString('To pierwszy wpis tej osoby. Warto odpowiedzieć szybko.', $html);
        $this->assertStringNotContainsString('pierwszy wpis bez reakcji zwykle bywa ostatnim', $html);
        $this->assertStringContainsString('Nowa osoba', $html);
    }

    public function test_ustawienia_opisuja_polaczone_i_niepolaczone_konto_bez_obietnicy_klikniec(): void
    {
        config(['kuking.facebook.wlaczone' => true, 'kuking.facebook.identyfikator_klienta' => 'testowy-klient', 'kuking.facebook.sekret_klienta' => 'testowy-sekret']);
        $user = $this->user('ustawienia');
        $this->actingAs($user);
        foreach ([false, true] as $connected) {
            if ($connected) {
                $user->connectFacebook('testowa-tozsamosc');
                $this->actingAs($user->fresh());
            }
            $html = (string) $this->get(route('settings.security'))->assertOk()->getContent();
            $this->assertStringNotContainsString('jednym kliknięciem', $html);
            $this->assertStringContainsString('Wejdź kontem Facebooka', $html);
            $this->assertSame($connected, str_contains($html, 'połączone z Twoim kontem Facebooka'));
        }
    }
}
