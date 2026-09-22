<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HasloKontoKomunikatyTest extends TestCase
{
    use RefreshDatabase;

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($doc);
    }

    private function field(DOMXPath $dom, string $id): DOMElement
    {
        $nodes = $dom->query('//input[@id="'.$id.'"]');
        $this->assertCount(1, $nodes, 'Pole musi istnieć dokładnie raz: '.$id);

        return $nodes->item(0);
    }

    public static function securityErrors(): array
    {
        return [
            'błędne hasło wylogowania' => ['logout-others', ['password' => 'zle-haslo'], 'password-wyloguj'],
            'brak hasła wylogowania' => ['logout-others', [], 'password-wyloguj'],
            'niezgodne nowe hasła' => ['password', ['current_password' => 'haslo-testowe-123', 'password' => 'nowe-haslo-1234', 'password_confirmation' => 'inne-haslo-1234'], 'password-zmiana'],
        ];
    }

    #[DataProvider('securityErrors')]
    public function test_blad_wskazuje_tylko_wyslany_formularz(string $operation, array $data, string $field): void
    {
        $user = $this->user();
        $route = route('settings.security.'.$operation);
        $this->actingAs($user)->from(route('settings.security'));
        $response = $operation === 'password' ? $this->put($route, $data) : $this->post($route, $data);
        $response->assertRedirect(route('settings.security'));
        foreach (['password', 'current_password', 'password_confirmation'] as $secret) {
            $this->assertArrayNotHasKey($secret, session('_old_input', []));
        }
        $html = $this->actingAs($user)->get(route('settings.security'))->assertOk()->getContent();
        $dom = $this->dom($html);
        $invalid = $dom->query('//input[@aria-invalid="true"]');
        $this->assertCount(1, $invalid, 'Błąd musi oznaczać tylko użyte pole.');
        $input = $this->field($dom, 'f-'.$field);
        $this->assertSame($input, $invalid->item(0));
        $this->assertSame($route, $dom->query('ancestor::form', $input)->item(0)->getAttribute('action'));
        $this->assertStringContainsString('f-'.$field.'-error', $input->getAttribute('aria-describedby'));
        $this->assertCount(1, $dom->query('//div[@role="alert"]//a[@href="#f-'.$field.'"]'));
        foreach ($dom->query('//input[@type="password"]') as $password) {
            $this->assertSame('', $password->getAttribute('value'));
        }
    }

    public static function deletionChoices(): array
    {
        return [[true, 'zle-haslo'], [false, 'zle-haslo'], [true, ''], [false, '']];
    }

    #[DataProvider('deletionChoices')]
    public function test_zakres_usuniecia_przetrwa_blad_i_ponowienie(bool $everything, string $password): void
    {
        $user = $this->user();
        $this->actingAs($user)->from(route('settings.data'));
        $fresh = $this->dom($this->get(route('settings.data'))->assertOk()->getContent());
        $this->assertCount(0, $fresh->query('//input[@name="usun_tresci"][@checked]'));
        $data = ['password' => $password, 'confirm' => '1'];
        if ($everything) {
            $data['usun_tresci'] = '1';
        }
        $response = $this->post(route('settings.data.delete'), $data);
        $response->assertRedirect(route('settings.data'));
        $this->assertArrayNotHasKey('password', session('_old_input', []));
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
        $this->assertNull($user->fresh()->delete_scope);
        $html = $this->get(route('settings.data'))->assertOk()->getContent();
        $dom = $this->dom($html);
        $choice = $dom->query('//input[@name="usun_tresci"]')->item(0);
        $this->assertSame($everything, $choice->hasAttribute('checked'), 'Zakres usunięcia nie może zniknąć.');
        $this->assertSame('', $dom->query('//input[@name="password"]')->item(0)->getAttribute('value'));
        $retry = ['password' => 'haslo-testowe-123', 'confirm' => '1'];
        if ($choice->hasAttribute('checked')) {
            $retry['usun_tresci'] = $choice->getAttribute('value');
        }
        $this->post(route('settings.data.delete'), $retry)->assertRedirect(route('landing'));
        $this->assertSame($everything ? User::DELETE_SCOPE_EVERYTHING : User::DELETE_SCOPE_MINIMUM, $user->fresh()->delete_scope);
    }

    public static function challengeErrors(): array
    {
        return [['code', false, false], ['backup_code', false, false], ['backup_code', true, false], ['backup_code', false, true], ['backup_code', false, false, '']];
    }

    public function test_obie_metody_zuzywaja_ten_sam_limit_konta(): void
    {
        config(['kuking.limits.two_factor' => '2,1']);
        $user = $this->user();
        $totp = app(TwoFactorAuthenticator::class);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['ABCD-1234']));
        $this->withSession(['logowanie.2fa.user_id' => $user->getKey()])->from(route('login.two_factor'));
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->post(route('login.two_factor.store'), ['code' => 'NIE-KOD'])->assertRedirect();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->post(route('login.two_factor.store'), ['backup_code' => 'NIE-KOD'])->assertRedirect();
        $this->assertSame(2, RateLimiter::attempts('weryfikacja-2fa|'.$user->getKey()));
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])
            ->post(route('login.two_factor.store'), ['backup_code' => 'ABCD-1234'])->assertRedirect();
        $html = $this->get(route('login.two_factor'))->assertOk()->getContent();
        $dom = $this->dom($html);
        $error = $dom->query('//*[@id="f-backup_code-error"]')->item(0);
        $this->assertNotNull($error);
        $this->assertStringContainsString('Za dużo prób', $error->textContent);
        $this->assertGuest();
        $this->assertCount(1, $user->fresh()->two_factor_backup_codes);
        $this->travel(61)->seconds();
        $this->post(route('login.two_factor.store'), ['backup_code' => 'ABCD-1234'])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        $this->assertCount(0, $user->fresh()->two_factor_backup_codes);
    }

    public function test_zly_ksztalt_danych_nie_zachowuje_drugiego_sekretu(): void
    {
        $user = $this->user();
        $totp = app(TwoFactorAuthenticator::class);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['ABCD-1234']));
        $this->withSession(['logowanie.2fa.user_id' => $user->getKey()])
            ->from(route('login.two_factor'))
            ->post(route('login.two_factor.store'), ['code' => '123456', 'backup_code' => ['ABCD-1234']])
            ->assertRedirect(route('login.two_factor'));
        $this->assertSame([], session('_old_input', []));
        $html = $this->get(route('login.two_factor'))->assertOk()->getContent();
        $dom = $this->dom($html);
        $this->assertCount(1, $dom->query('//details[@open]//input[@name="backup_code"][@aria-invalid="true"]'));
        $this->assertStringNotContainsString('ABCD-1234', $html);
        $this->assertGuest();
    }

    #[DataProvider('challengeErrors')]
    public function test_blad_kodu_dotyczy_uzytej_metody_bez_zapamietania_sekretu(string $method, bool $used, bool $limited, string $value = 'INVALID-CODE'): void
    {
        $user = $this->user();
        $totp = app(TwoFactorAuthenticator::class);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['ABCD-1234', 'EFGH-5678']));
        if ($used) {
            $this->assertTrue($totp->consumeBackupCode($user, 'ABCD-1234'));
            $value = 'ABCD-1234';
        }
        $before = $user->fresh()->two_factor_backup_codes;
        $key = 'weryfikacja-2fa|'.$user->getKey();
        if ($limited) {
            [$max] = explode(',', config('kuking.limits.two_factor'));
            for ($i = 0; $i < (int) $max; $i++) {
                RateLimiter::hit($key, 60);
            }
        }
        $response = $this->withSession(['logowanie.2fa.user_id' => $user->getKey()])
            ->from(route('login.two_factor'))->post(route('login.two_factor.store'), [$method => $value]);
        $response->assertRedirect(route('login.two_factor'));
        $this->assertArrayNotHasKey('code', session('_old_input', []));
        $this->assertArrayNotHasKey('backup_code', session('_old_input', []));
        $this->assertGuest();
        $this->assertSame($before, $user->fresh()->two_factor_backup_codes);
        $html = $this->get(route('login.two_factor'))->assertOk()->getContent();
        $dom = $this->dom($html);
        $this->assertSame('true', $this->field($dom, 'f-'.$method)->getAttribute('aria-invalid'));
        $this->assertCount(1, $dom->query('//input[@aria-invalid="true"]'));
        $this->assertCount(1, $dom->query('//div[@role="alert"]//a[@href="#f-'.$method.'"]'));
        $error = $dom->query('//*[@id="f-'.$method.'-error"]')->item(0)->textContent;
        if ($limited) {
            $this->assertStringContainsString('Spróbuj ponownie za', $error);
        } elseif ($method === 'backup_code') {
            $this->assertStringContainsString('niewykorzystany kod zapasowy', $error);
            $this->assertStringNotContainsString('telefon', $error);
        } else {
            $this->assertStringContainsString('godzinę w telefonie', $error);
        }
        if ($method === 'backup_code') {
            $this->assertCount(1, $dom->query('//details[@open]//input[@name="backup_code"]'));
        }
        foreach (['code', 'backup_code'] as $secret) {
            $this->assertSame('', $this->field($dom, 'f-'.$secret)->getAttribute('value'));
        }
        if (! $limited) {
            $this->assertSame($value === '' ? 0 : 1, RateLimiter::attempts($key));
        }
    }

    public function test_kody_zapasowe_opisuja_brak_samodzielnego_dostepu(): void
    {
        $html = $this->actingAs($this->user())->withSession(['kody_zapasowe' => ['ABCD-1234']])
            ->get(route('settings.two_factor.codes'))->assertOk()->getContent();
        $dom = $this->dom($html);
        $text = $dom->query('//main')->item(0)->textContent;
        $this->assertStringContainsString('nie zalogujesz się samodzielnie', $text);
        $this->assertStringNotContainsString('na dobre', $text);
        $this->assertStringContainsString('schowaj w bezpiecznym miejscu', $text);
        $this->assertStringNotContainsString('kuking:2fa-wylacz', $html);
    }

    public static function helpStates(): array
    {
        return [[false, 'array'], [true, 'array'], [false, 'smtp'], [true, 'smtp']];
    }

    #[DataProvider('helpStates')]
    public function test_pomoc_opisuje_wyglad_i_odsylacz_do_aktualnej_drogi_resetowania(bool $loggedIn, string $mailer): void
    {
        config(['mail.default' => $mailer]);
        if ($loggedIn) {
            $this->actingAs($this->user());
        }
        $html = $this->get('/pomoc')->assertOk()->getContent();
        $dom = $this->dom($html);
        $article = $dom->query('//main//article')->item(0);
        $text = preg_replace('/\s+/u', ' ', $article->textContent);
        $this->assertStringContainsString('Wygląd', $text);
        $this->assertStringContainsString('Rozmiar tekstu', $text);
        $this->assertStringContainsString('Bez logowania', $text);
        $this->assertStringContainsString('w tej przeglądarce', $text);
        $this->assertStringContainsString('na Twoim koncie', $text);
        $this->assertStringNotContainsString('Wyślemy Ci wiadomość z linkiem', $text);
        $this->assertStringContainsString('postępuj według wskazówek na tej stronie', $text);
        $this->assertCount(1, $dom->query('.//a[@href="'.route('password.request').'"]', $article));
        $this->assertStringContainsString('Jeśli wysyłanie wiadomości jest niedostępne', $text);
        $this->assertGreaterThan(0, $dom->query('.//a[@href="'.route('kontakt').'"]', $article)->length);
    }
}
