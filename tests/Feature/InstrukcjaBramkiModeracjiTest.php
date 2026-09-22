<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstrukcjaBramkiModeracjiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ustawienia_2fa_opisuja_wymagana_aplikacje_bez_obietnicy_czasu(): void
    {
        app('blade.compiler')->compile(resource_path('views/pages/settings/two_factor/index.blade.php'));
        $user = $this->user('ustawienia2fa');
        $response = $this->actingAs($user)->get(route('settings.two_factor.edit'));
        $response->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new DOMXPath($dom);
        $main = $xpath->query('//main')->item(0);
        $this->assertNotNull($main);
        $tekst = (string) preg_replace('/\s+/u', ' ', $main->textContent);
        $this->assertStringContainsString('aplikacji uwierzytelniającej w telefonie', $tekst);
        $this->assertStringContainsString('Google Authenticator, Aegis albo 1Password', $tekst);
        $this->assertDoesNotMatchRegularExpression('/(?:mniej niż|zajmuje|w ciągu).{0,40}(?:minut|sekund)/iu', $tekst);
        $link = $xpath->query('//main//a[@href="'.route('settings.two_factor.enable').'"]');
        $this->assertCount(1, $link);
        $this->assertSame('Włącz weryfikację dwuetapową', trim($link->item(0)->textContent));
    }

    public function test_bramka_wyjasnia_zabezpieczenie_i_prowadzi_do_2fa_bez_obietnicy_czasu(): void
    {
        app('blade.compiler')->compile(resource_path('views/pages/admin/wymagane_2fa.blade.php'));
        $moderator = $this->user('moderatorinstrukcji', ['role' => User::ROLE_MODERATOR]);
        $response = $this->actingAs($moderator)->get(route('admin.reports'));
        $response->assertForbidden();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new DOMXPath($dom);
        $main = $xpath->query('//main')->item(0);
        $this->assertNotNull($main);
        $tekst = (string) preg_replace('/\s+/u', ' ', $main->textContent);
        $this->assertStringContainsString('to kod z aplikacji w telefonie, obok hasła', $tekst);
        $this->assertDoesNotMatchRegularExpression('/(?:mniej niż|zajmuje|w ciągu).{0,40}(?:minut|sekund)/iu', $tekst);
        $link = $xpath->query('//main//a[@href="'.route('settings.two_factor.enable').'"]');
        $this->assertCount(1, $link);
        $this->assertSame('Włącz weryfikację dwuetapową', trim($link->item(0)->textContent));
    }
}
