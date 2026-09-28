<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Ustawienia2faBledyTest extends TestCase
{
    use RefreshDatabase;

    public static function operations(): array
    {
        return [['regenerate', 'regenerate', 'niepoprawne'], ['disable', 'disable', 'niepoprawne'], ['regenerate', 'regenerate', ''], ['disable', 'disable', '']];
    }

    #[DataProvider('operations')]
    public function test_blad_otwiera_tylko_wlasciwa_sekcje_i_ma_jednoznaczne_powiazania(string $route, string $bag, string $password): void
    {
        $user = $this->user();
        $totp = app(TwoFactorAuthenticator::class);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['TEST-TEST']));
        $before = $user->fresh()->getRawOriginal();
        $response = $this->actingAs($user)->from(route('settings.two_factor.edit'))
            ->post(route('settings.two_factor.'.$route), ['password' => $password]);
        $response->assertRedirect(route('settings.two_factor.edit'));
        $this->assertNull(session('_old_input.password'));
        $page = $this->get(route('settings.two_factor.edit'))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new DOMXPath($dom);
        $form = "//form[@action='".route('settings.two_factor.'.$route)."']";
        $this->assertSame(1, $xpath->query($form.'/ancestor::details[@open]')->length, 'Błąd hasła musi otworzyć właściwy details.');
        $ids = [];
        foreach (self::elementyDom($xpath->query('//*[@id]')) as $node) {
            $id = $node->getAttribute('id');
            $this->assertFalse(isset($ids[$id]), 'Powielony identyfikator pola lub opisu.');
            $ids[$id] = true;
        }
        $field = self::elementDom($xpath->query($form.'//input[@name="password"]')->item(0));
        $this->assertSame('', $field->getAttribute('value'));
        $this->assertSame('true', $field->getAttribute('aria-invalid'));
        $id = $field->getAttribute('id');
        $this->assertSame(1, $xpath->query('//label[@for="'.$id.'"]')->length);
        $this->assertSame(1, $xpath->query('//div[contains(@class,"error-summary")]//a[@href="#'.$id.'"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="password"][@aria-invalid="true"]')->length);
        $this->assertSame(1, $xpath->query('//details[@open]')->length);
        $this->assertNull(session('_old_input.password'));
        foreach (['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_backup_codes'] as $column) {
            $this->assertTrue($before[$column] === $user->fresh()->getRawOriginal($column), 'Odmowa nie może zmienić 2FA.');
        }
    }
}
