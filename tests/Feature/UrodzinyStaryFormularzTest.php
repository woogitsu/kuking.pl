<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Stara karta nie może odwrócić nowszej decyzji o urodzinach (#2864). */
final class UrodzinyStaryFormularzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-03-12 08:00:00', 'UTC'));
    }

    public function test_stara_karta_nie_przywraca_widocznosci_i_nie_wysyla_przypomnienia(): void
    {
        $ania = $this->solenizantka();
        $marek = $this->user('marek2864');
        $marek->following()->attach($ania->getKey());

        $kartaA = $this->formularz($this->actingAs($ania)->get(route('settings.birthday'))->assertOk());
        $kartaB = $this->formularz($this->actingAs($ania)->get(route('settings.birthday'))->assertOk());
        $this->assertSame('1', $kartaA['original_birthday_visible_to_followers']);
        $this->assertSame('1', $kartaA['birthday_visible_to_followers']);

        unset($kartaB['birthday_visible_to_followers']);
        $this->actingAs($ania)->put(route('settings.birthday.preferences'), $kartaB)
            ->assertSessionHasNoErrors();
        $this->assertFalse($ania->fresh()->birthday_visible_to_followers);

        unset($kartaA['birthday_wishes_enabled']);
        $odmowa = $this->actingAs($ania)->put(route('settings.birthday.preferences'), $kartaA);
        $this->assertFalse($ania->fresh()->birthday_visible_to_followers, 'URODZINY_2864_NOWSZA_DECYZJA: stara karta przywróciła ujawnianie urodzin.');
        $odmowa->assertSessionHasErrors('birthday_visible_to_followers');
        $this->assertTrue($ania->fresh()->birthday_wishes_enabled, 'Przy konflikcie nie zapisujemy nawet niezależnego wyboru życzeń.');
        $this->assertFalse($ania->fresh()->wants_birthday_email);

        $formularzPoBledzie = $this->formularz($this->actingAs($ania)->get(route('settings.birthday'))->assertOk());
        $this->assertSame('1', $formularzPoBledzie['original_birthday_visible_to_followers']);
        $this->assertSame('1', $formularzPoBledzie['birthday_visible_to_followers']);
        $this->assertArrayNotHasKey('birthday_wishes_enabled', $formularzPoBledzie);

        Artisan::call('kuking:przypomnij-o-urodzinach');
        $this->assertSame(0, Notification::query()->where('user_id', $marek->getKey())->where('type', Notification::TYPE_BIRTHDAY)->count(), 'URODZINY_2864_BEZ_NIECHCIANEGO_PRZYPOMNIENIA');

        // Dopiero świeżo otwarty formularz może świadomie włączyć widoczność.
        $aktualny = $this->formularz($this->actingAs($ania)->get(route('settings.birthday'))->assertOk());
        $this->assertSame('0', $aktualny['original_birthday_visible_to_followers']);
        $aktualny['birthday_visible_to_followers'] = '1';
        $this->actingAs($ania)->put(route('settings.birthday.preferences'), $aktualny)->assertSessionHasNoErrors();
        $this->assertTrue($ania->fresh()->birthday_visible_to_followers);
        Artisan::call('kuking:przypomnij-o-urodzinach');
        $this->assertSame(1, Notification::query()->where('user_id', $marek->getKey())->where('type', Notification::TYPE_BIRTHDAY)->count());
    }

    public function test_formularz_sprzed_poprawki_bez_odcisku_widocznosci_odmawia(): void
    {
        $ania = $this->solenizantka();
        $this->actingAs($ania)->put(route('settings.birthday.preferences'), [
            '_formularz' => 'wybory',
            'original_birthday_email' => '0',
            'birthday_wishes_enabled' => '0',
            'birthday_visible_to_followers' => '0',
        ])->assertSessionHasErrors('original_birthday_visible_to_followers');

        $this->assertTrue($ania->fresh()->birthday_visible_to_followers);
        $this->assertTrue($ania->fresh()->birthday_wishes_enabled);
    }

    private function solenizantka(): User
    {
        $ania = $this->user('ania2864');
        $ania->forceFill([
            'birthday_day' => 12,
            'birthday_month' => 3,
            'birthday_wishes_enabled' => true,
            'birthday_visible_to_followers' => true,
            'wants_birthday_email' => false,
        ])->save();

        return $ania;
    }

    /** @return array<string, string> */
    private function formularz(TestResponse $odpowiedz): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.(string) $odpowiedz->getContent());
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $form = $xpath->query('//form[@action="'.route('settings.birthday.preferences').'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);

        $pola = ['_formularz' => 'wybory'];
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            $this->assertInstanceOf(DOMElement::class, $input);
            $name = $input->getAttribute('name');
            if ($name === '_token' || $name === '_method') {
                continue;
            }
            if ($input->getAttribute('type') !== 'checkbox' || $input->hasAttribute('checked')) {
                $pola[$name] = $input->getAttribute('value');
            }
        }

        return $pola;
    }
}
