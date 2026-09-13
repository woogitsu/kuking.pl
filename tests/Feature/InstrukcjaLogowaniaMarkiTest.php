<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class InstrukcjaLogowaniaMarkiTest extends TestCase
{
    public function test_instrukcje_prowadza_przez_link_i_potwierdzenie_na_stronie(): void
    {
        config(['mail.default' => 'smtp', 'kuking.login_link.wlaczone' => true]);
        view()->share('errors', new ViewErrorBag);
        foreach (['auth.login', 'auth.login-link'] as $widok) {
            app('blade.compiler')->compile(resource_path('views/'.str_replace('.', '/', $widok).'.blade.php'));
            $tekst = (string) preg_replace('/\s+/u', ' ', strip_tags(view($widok)->render()));
            $this->assertStringContainsString('Otwórz go, a na stronie kliknij „Zaloguj mnie”.', $tekst);
            $this->assertStringNotContainsString('a po kliknięciu wejdziesz na konto', $tekst);
            if ($widok === 'auth.login-link') {
                $this->assertStringContainsString('Jeśli adres konta nie był potwierdzony, wiadomość poprosi najpierw o ustawienie hasła.', $tekst);
            }
        }
    }

    public function test_potwierdzenie_opisuje_jednorazowosc_linku_bez_obietnicy_wylacznosci_dostepu(): void
    {
        app('blade.compiler')->compile(resource_path('views/auth/login-link-confirm.blade.php'));
        $html = view('auth.login-link-confirm', ['token' => 'testowy-token', 'displayName' => 'Anna', 'adresSkrot' => 'a***@example.test'])->render();
        $tekst = (string) preg_replace('/\s+/u', ' ', strip_tags($html));
        $this->assertStringContainsString('Po zalogowaniu nie można go użyć ponownie.', $tekst);
        $this->assertStringNotContainsString('nikt inny nie wejdzie', $tekst);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('action="'.route('login.link.store').'"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_nawigacja_ustawien_nie_obiecuje_wyslania_hasla(): void
    {
        app('blade.compiler')->compile(resource_path('views/components/ustawienia-nawigacja.blade.php'));
        $html = Blade::render('<x-ustawienia-nawigacja />');
        $this->assertStringContainsString('Zobacz i zmień adres do wiadomości z Kuking', $html);
        $this->assertStringNotContainsString('przychodzi nowe hasło', $html);
    }
}
