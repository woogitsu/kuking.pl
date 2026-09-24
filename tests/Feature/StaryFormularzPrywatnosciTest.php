<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\OdnosnikWypisania;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stara karta z formularzem Prywatność nie odwraca nowszej decyzji
 * podjętej inną drogą (issue #880).
 *
 * Oba haczyki idą jednym „Zapisz". Ktoś otwiera ustawienia w karcie A,
 * wypisuje się z listu odnośnikiem z e-maila, wraca do karty A i odznacza
 * tylko wspomnienia. Karta A wciąż ma zaznaczony haczyk listu — i do tej
 * poprawki zapis ponownie włączał list oraz dopisywał do dziennika zgodę,
 * której nikt nie udzielił.
 *
 * Formularz jest tu czytany z prawdziwej odpowiedzi GET, a nie składany
 * ręcznie — test ma przesłać dokładnie to, co przesłałaby przeglądarka.
 */
class StaryFormularzPrywatnosciTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pola formularza tak, jak wysłałaby je przeglądarka: ukryte zawsze,
     * checkbox tylko zaznaczony.
     *
     * @return array<string, string>
     */
    private function otworzFormularz(User $osoba): array
    {
        $html = $this->actingAs($osoba)->get(route('settings.privacy'))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $formularz = $xpath->query('//form[@action="'.route('settings.privacy').'"]')->item(0);
        $this->assertNotNull($formularz);

        $pola = [];
        foreach ($xpath->query('.//input', $formularz) as $pole) {
            $typ = $pole->getAttribute('type');
            if ($typ === 'checkbox' && ! $pole->hasAttribute('checked')) {
                continue;
            }
            $pola[$pole->getAttribute('name')] = $pole->getAttribute('value');
        }

        return $pola;
    }

    /** @return array<int, string> */
    private function czynnosci(User $osoba): array
    {
        return WpisZgody::query()->where('user_id', $osoba->getKey())
            ->orderBy('id')->pluck('czynnosc')->all();
    }

    public function test_stara_karta_nie_zapisuje_ponownie_na_list_po_wypisaniu_z_emaila(): void
    {
        $osoba = $this->user('dwie_karty', ['wants_weekly_digest' => true, 'memories_enabled' => true]);

        $kartaA = $this->otworzFormularz($osoba);
        $this->assertSame('1', $kartaA['wants_weekly_digest'] ?? null, 'Karta A pokazuje zaznaczony list.');

        // Druga droga: odnośnik z e-maila, bez logowania.
        $this->app['auth']->forgetGuards();
        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();
        $this->assertFalse((bool) $osoba->fresh()->wants_weekly_digest);
        $this->assertSame([WpisZgody::WYCOFANA], $this->czynnosci($osoba));

        // Powrót do karty A: odznaczone tylko wspomnienia.
        unset($kartaA['memories_enabled']);
        $this->actingAs($osoba->fresh())
            ->from(route('settings.privacy'))
            ->followingRedirects()
            ->put(route('settings.privacy'), $kartaA)
            ->assertOk()
            ->assertSee('Tygodniowy e-mail został w międzyczasie wyłączony w innym miejscu')
            ->assertSee('zaznacz pole poniżej i zapisz');

        $osoba->refresh();
        $this->assertFalse((bool) $osoba->wants_weekly_digest, 'Stara karta ponownie zapisała na list.');
        $this->assertFalse((bool) $osoba->memories_enabled, 'Zmiana wspomnień, której człowiek chciał, przepadła.');
        $this->assertSame([WpisZgody::WYCOFANA], $this->czynnosci($osoba), 'Dopisano zgodę, której nikt nie udzielił.');
    }

    public function test_swiadome_zaznaczenie_z_aktualnego_ekranu_wlacza_list_z_dowodem(): void
    {
        $osoba = $this->user('wraca_na_list', ['wants_weekly_digest' => true]);
        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();

        $formularz = $this->otworzFormularz($osoba->fresh());
        $this->assertArrayNotHasKey('wants_weekly_digest', $formularz, 'Aktualny ekran pokazuje list wyłączony.');

        $formularz['wants_weekly_digest'] = '1';
        $this->actingAs($osoba->fresh())
            ->from(route('settings.privacy'))
            ->followingRedirects()
            ->put(route('settings.privacy'), $formularz)
            ->assertOk()
            ->assertSee('Zapisane.')
            ->assertDontSee('w międzyczasie');

        $this->assertTrue((bool) $osoba->fresh()->wants_weekly_digest);
        $this->assertSame([WpisZgody::WYCOFANA, WpisZgody::UDZIELONA], $this->czynnosci($osoba));
        $this->assertSame(
            WpisZgody::ZRODLO_USTAWIENIA,
            WpisZgody::query()->where('user_id', $osoba->getKey())->latest('id')->value('zrodlo'),
        );
    }

    public function test_zmiana_listu_ze_starej_karty_nie_wlacza_wylaczonych_gdzie_indziej_wspomnien(): void
    {
        $osoba = $this->user('wspomnienia_dwie_karty', ['wants_weekly_digest' => false, 'memories_enabled' => true]);

        $kartaA = $this->otworzFormularz($osoba);
        $this->assertSame('1', $kartaA['memories_enabled'] ?? null);

        // Karta B: wyłączone same wspomnienia.
        $kartaB = $this->otworzFormularz($osoba);
        unset($kartaB['memories_enabled']);
        $this->actingAs($osoba)->put(route('settings.privacy'), $kartaB)->assertRedirect();
        $this->assertFalse((bool) $osoba->fresh()->memories_enabled);

        // Karta A: zaznaczony list, wspomnienia wciąż zaznaczone z dawna.
        $kartaA['wants_weekly_digest'] = '1';
        $this->actingAs($osoba->fresh())
            ->from(route('settings.privacy'))
            ->followingRedirects()
            ->put(route('settings.privacy'), $kartaA)
            ->assertOk()
            ->assertSee('Wspomnienia zostały w międzyczasie wyłączone w innym miejscu');

        $osoba->refresh();
        $this->assertFalse((bool) $osoba->memories_enabled, 'Stara karta ponownie włączyła wspomnienia.');
        $this->assertTrue((bool) $osoba->wants_weekly_digest);
        $this->assertSame([WpisZgody::UDZIELONA], $this->czynnosci($osoba));
    }
}
