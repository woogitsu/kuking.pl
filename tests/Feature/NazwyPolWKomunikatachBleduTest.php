<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Komunikat błędu nazywa pole słowami z ekranu, nie kluczem z formularza.
 *
 * SKĄD TEN TEST (audyt B9 pkt 3, audyt B1 znalezisko 5)
 * `lang/pl/validation.php` nie miał nazw dla `target_url`, `notifier_name`,
 * `notifier_email` (zgłoszenie treści niezgodnej z prawem), `contact_email`
 * („Napisz do nas”) i `steps.*.photo` (zdjęcie kroku przepisu). Reguły bez
 * własnego komunikatu — `max`, `uploaded` — pokazywały wtedy „Pole «target
 * url» jest za długie” albo „…w polu «steps.0.photo»”.
 *
 * Przy okazji: „To hasło jest nieprawidłowe.” (zmiana adresu e-mail i reguła
 * `current_password`) nie mówiło, co zrobić.
 *
 * KONTROLA DODATNIA: `test_pole_bez_nazwy_daje_klucz` pokazuje, że ta sama
 * miara na polu BEZ wpisu widzi surowy klucz — więc zieleń reszty nie jest
 * przypadkiem sposobu mierzenia.
 */
class NazwyPolWKomunikatachBleduTest extends TestCase
{
    use RefreshDatabase;

    private const NAZWY = [
        'target_url' => 'adres strony z tą treścią',
        'notifier_name' => 'imię i nazwisko albo nazwa instytucji',
        'notifier_email' => 'adres e-mail',
        'contact_email' => 'Twój adres e-mail',
        'steps.0.photo' => 'zdjęcie kroku',
    ];

    public function test_pola_formularzy_maja_polskie_nazwy_w_komunikatach(): void
    {
        foreach (self::NAZWY as $pole => $nazwa) {
            $komunikat = $this->komunikatMax($pole);

            $this->assertStringContainsString("„{$nazwa}”", $komunikat, "Pole {$pole}: {$komunikat}");
            $this->assertStringNotContainsString(str_replace('_', ' ', $pole), $komunikat);
            $this->assertStringNotContainsString($pole, $komunikat);
        }
    }

    public function test_pole_bez_nazwy_daje_klucz(): void
    {
        $this->assertStringContainsString('pole bez nazwy', $this->komunikatMax('pole_bez_nazwy'));
    }

    public function test_za_dlugi_adres_na_formularzu_dsa_mowi_nazwe_z_ekranu(): void
    {
        $this->from(route('zglos.nielegalna'))
            ->post(route('zglos.nielegalna.store'), [
                'target_url' => str_repeat('a', 2001),
                'reason' => 'copyright',
                'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
                'good_faith' => '1',
            ])
            ->assertSessionHasErrors('target_url');

        $komunikat = (string) session('errors')->first('target_url');
        $this->assertStringContainsString('„adres strony z tą treścią”', $komunikat);
    }

    public function test_zle_obecne_haslo_przy_zmianie_adresu_mowi_co_zrobic(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $this->actingAs($basia)->post(route('settings.email.request'), [
            'current_password' => 'zgaduje-haslo',
            'email' => 'nowa.basia@example.test',
        ])->assertSessionHasErrors([
            'current_password' => 'To hasło nie pasuje do konta. Wpisz swoje obecne hasło — to, którym logujesz się dziś.',
        ]);

        $this->assertSame(
            'To hasło nie pasuje do konta. Wpisz swoje obecne hasło — to, którym logujesz się dziś.',
            __('validation.current_password'),
        );
    }

    private function komunikatMax(string $pole): string
    {
        $wzorzec = str_contains($pole, '.') ? preg_replace('/\.\d+\./', '.*.', $pole) : $pole;
        $dane = [];
        data_set($dane, $pole, 'za długi tekst');

        return (string) Validator::make($dane, [$wzorzec => ['string', 'max:1']])->errors()->first($pole);
    }
}
