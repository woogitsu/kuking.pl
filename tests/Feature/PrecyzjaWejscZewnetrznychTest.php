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
            ['auth.facebook-link', ['displayName' => 'Pomiar', 'imieZFacebooka' => 'Pomiar']],
            ['auth.facebook-bez-adresu', []],
            ['mail.proba-wejscia-kontem-facebooka', ['displayName' => 'Pomiar', 'linkLogowania' => 'https://example.test/logowanie']],
        ] as [$view, $data]) {
            $html = view($view, $data)->render();
            $tekst = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $this->assertStringNotContainsString('jednym kliknięciem', $tekst, $view);
            $this->assertStringContainsString('Wejdź kontem', $html, $view);
        }
    }

    public function test_polaczenie_facebooka_uprzedza_o_mozliwym_potwierdzeniu(): void
    {
        $html = view('auth.facebook-link', ['displayName' => 'Pomiar', 'imieZFacebooka' => 'Pomiar'])->render();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $main = $xpath->query('//main')->item(0);
        $this->assertNotNull($main);
        $tekst = preg_replace('/\s+/u', ' ', $main->textContent);
        $this->assertStringContainsString('Wejdź kontem Facebooka', $tekst);
        $this->assertStringContainsString('Facebook może poprosić o potwierdzenie.', $tekst);
        $this->assertStringNotContainsString('jednym kliknięciem', $tekst);
        $this->assertSame(1, $xpath->query('//main//form[@method="POST"][@action="'.route('facebook.link.store').'"]//input[@name="_token"]')->length);
    }

    public function test_brak_adresu_nie_obiecuje_listu_po_kazdym_ugotowaniu_ani_dwoch_pol_rejestracji(): void
    {
        $html = view('auth.facebook-bez-adresu')->render();
        $tekst = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('i wypełnij formularz.', $tekst);
        $this->assertStringContainsString('do odzyskania dostępu.', $tekst);
        $this->assertStringNotContainsString('wysyłamy wiadomość, gdy ktoś ugotuje', $tekst);
        $this->assertStringNotContainsString('wymaga tylko adresu oraz hasła', $tekst);
        $this->assertStringNotContainsString('tylko nim odzyskasz konto', $tekst);
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
