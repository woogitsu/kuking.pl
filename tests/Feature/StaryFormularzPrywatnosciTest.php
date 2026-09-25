<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\OdnosnikWypisania;
use App\Domain\Zgody\PrzestawZgodeNaDigest;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Dwie karty wykonane kolejno; ten test nie mierzy dwóch połączeń. */
class StaryFormularzPrywatnosciTest extends TestCase
{
    use RefreshDatabase;

    public static function withdrawals(): array
    {
        return ['odnośnik' => [true], 'formularz' => [false]];
    }

    #[DataProvider('withdrawals')]
    public function test_stary_formularz_nie_wlacza_listu_po_wypisaniu(bool $link): void
    {
        $user = $this->user('prywatnosc', ['wants_weekly_digest' => true, 'memories_enabled' => true]);
        $this->actingAs($user)->get(route('settings.privacy'))->assertOk();
        $old = $this->fields($user);
        if ($link) {
            $this->get(OdnosnikWypisania::dla($user))->assertOk();
        } else {
            $this->put(route('settings.privacy'), [...$old, 'wants_weekly_digest' => '0'])->assertSessionHasNoErrors();
        }
        $this->actingAs($user->fresh())->from(route('settings.privacy'))
            ->put(route('settings.privacy'), [...$old, 'memories_enabled' => '0']);
        $this->assertFalse($user->fresh()->wants_weekly_digest, 'Stary formularz ponownie włączył list.');
        $this->assertSame([WpisZgody::WYCOFANA], WpisZgody::where('user_id', $user->id)->pluck('czynnosc')->all());
        $this->get(route('settings.privacy'))->assertOk()->assertSee('Otwórz aktualne ustawienia', false);
    }

    public function test_stary_formularz_nie_wylacza_nowszej_zgody(): void
    {
        $user = $this->user('zgoda', ['wants_weekly_digest' => false]);
        $old = $this->fields($user);
        $this->actingAs($user)->get(route('settings.privacy'))->assertOk();
        $this->put(route('settings.privacy'), [...$old, 'wants_weekly_digest' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($user->fresh())->from(route('settings.privacy'))
            ->put(route('settings.privacy'), [...$old, 'memories_enabled' => '0']);
        $this->assertTrue($user->fresh()->wants_weekly_digest, 'Stary formularz wyłączył nowszą zgodę.');
    }

    public function test_zmiana_listu_nie_przywraca_nowszego_wylaczenia_wspomnien(): void
    {
        $user = $this->user('wspomnienia', ['wants_weekly_digest' => false, 'memories_enabled' => true]);
        $old = $this->fields($user);
        $this->actingAs($user)->get(route('settings.privacy'))->assertOk();
        $this->put(route('settings.privacy'), [...$old, 'memories_enabled' => '0'])->assertSessionHasNoErrors();
        $this->actingAs($user->fresh())->from(route('settings.privacy'))
            ->put(route('settings.privacy'), [...$old, 'wants_weekly_digest' => '1']);
        $this->assertFalse($user->fresh()->memories_enabled, 'Stary formularz przywrócił wspomnienia.');
        $this->assertFalse($user->fresh()->wants_weekly_digest, 'Konflikt nie może zapisać połowy formularza.');
    }

    public function test_aktualny_formularz_pozwala_swiadomie_wrocic_do_listu(): void
    {
        $user = $this->user('powrot', ['wants_weekly_digest' => true]);
        $this->get(OdnosnikWypisania::dla($user))->assertOk();
        $user->refresh();
        $this->actingAs($user)->get(route('settings.privacy'))->assertOk();
        $this->from(route('settings.privacy'))->put(route('settings.privacy'), [
            ...$this->fields($user), 'wants_weekly_digest' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->wants_weekly_digest);
        $this->assertSame([WpisZgody::WYCOFANA, WpisZgody::UDZIELONA], WpisZgody::where('user_id', $user->id)->orderBy('id')->pluck('czynnosc')->all());
        $this->get(route('settings.privacy'))->assertSee('Zapisane.');
    }

    private function fields(User $user): array
    {
        $html = $this->actingAs($user->fresh())->get(route('settings.privacy'))->assertOk()->getContent();
        $original = [];
        foreach (['original_digest', 'original_memories'] as $name) {
            $this->assertMatchesRegularExpression('/name="'.$name.'" value="[01]"/', $html);
            preg_match('/name="'.$name.'" value="([01])"/', $html, $matches);
            $original[$name] = $matches[1];
        }

        return [
            ...$original,
            'wants_weekly_digest' => (string) (int) $user->wants_weekly_digest,
            'memories_enabled' => (string) (int) $user->memories_enabled,
        ];
    }

    public function test_blad_zachowuje_odznaczenia_i_stan_poczatkowy(): void
    {
        $user = $this->user('blad', ['wants_weekly_digest' => true, 'memories_enabled' => true]);
        $fields = $this->fields($user);
        $this->from(route('settings.privacy'))->put(route('settings.privacy'), [...$fields, 'wants_weekly_digest' => 'bledne', 'memories_enabled' => '0'])
            ->assertSessionHasErrors('wants_weekly_digest');
        $html = $this->get(route('settings.privacy'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="f-memories_enabled"[^>]*>/', $html);
        preg_match('/<input id="f-memories_enabled"[^>]*>/', $html, $input);
        $this->assertStringNotContainsString('checked', $input[0]);
        $this->assertStringContainsString('name="original_memories" value="1"', $html);
        $this->assertTrue($user->fresh()->memories_enabled);
    }

    public function test_formularz_bez_stanu_poczatkowego_nie_zapisuje_i_nie_dostaje_go_po_bledzie(): void
    {
        $user = $this->user('stary', ['wants_weekly_digest' => false]);
        $this->actingAs($user)->from(route('settings.privacy'))->put(route('settings.privacy'), ['wants_weekly_digest' => '1'])
            ->assertSessionHasErrors(['original_digest', 'original_memories']);
        $html = $this->get(route('settings.privacy'))->assertOk()->getContent();
        $this->assertStringContainsString('name="original_digest" value=""', $html);
        $this->assertFalse($user->fresh()->wants_weekly_digest);
    }

    public function test_akcja_zgody_odczytuje_baze_zamiast_starego_modelu(): void
    {
        $user = $this->user('stary_model', ['wants_weekly_digest' => false]);
        $old = $user->fresh();
        $consent = app(PrzestawZgodeNaDigest::class);
        $consent->handle($user, true, WpisZgody::ZRODLO_USTAWIENIA);
        $this->assertTrue($consent->handle($old, false, WpisZgody::ZRODLO_LINK_WYPISANIA));
        $this->assertFalse($user->fresh()->wants_weekly_digest);
        $this->assertSame(2, WpisZgody::where('user_id', $user->id)->count());
    }
}
