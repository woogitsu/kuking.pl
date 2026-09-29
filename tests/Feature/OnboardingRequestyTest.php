<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Onboarding\ObserwujWybraneOsoby;
use App\Domain\Onboarding\WynikObserwowaniaWybranych;
use App\Http\Requests\Onboarding\ZapisObserwowanychRequest;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wejście onboardingu wyjęte z `OnboardingController` do Form Requestów
 * i akcji domenowych (issue #970). Zachowanie nie miało się zmienić —
 * istniejące testy onboardingu i rejestracji przechodzą bez zmian, a ten
 * plik pilnuje granic, które przy przenosinach łatwo zgubić: limitu na
 * tablicy, filtrowania pól technicznych i tokenu wyboru.
 */
class OnboardingRequestyTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwadziescia_jeden_osob_wraca_z_komunikatem_i_nie_tworzy_relacji(): void
    {
        $widz = $this->user('widz');
        $nazwy = [];

        for ($i = 1; $i <= 21; $i++) {
            $nazwy[] = 'osoba'.$i;
            $this->user('osoba'.$i);
        }

        $this->actingAs($widz)->from(route('onboarding.people'))
            ->post(route('onboarding.people'), ['follow' => $nazwy])
            ->assertRedirect(route('onboarding.people'))
            ->assertSessionHasErrors(['follow' => 'Zaznacz najwyżej 20 osób. Odznacz pozostałe i kliknij „Dalej”.'])
            ->assertSessionHasInput('follow');

        $this->assertDatabaseCount('follows', 0);
    }

    public function test_dwadziescia_osob_przechodzi_kontrola_ujemna_limitu(): void
    {
        $widz = $this->user('widz');
        $nazwy = [];

        for ($i = 1; $i <= 20; $i++) {
            $nazwy[] = 'osoba'.$i;
            $this->user('osoba'.$i);
        }

        $this->actingAs($widz)->post(route('onboarding.people'), ['follow' => $nazwy])
            ->assertRedirect(route('onboarding.done'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('follows', 20);
    }

    public function test_pola_techniczne_niezaznaczonych_osob_nie_liczą_sie_do_limitu(): void
    {
        $widz = $this->user('widz');
        $halina = $this->user('halina');
        $oczekiwani = [];

        for ($i = 1; $i <= 30; $i++) {
            $oczekiwani['ktos'.$i] = (string) Str::uuid();
        }

        $oczekiwani['halina'] = (string) $halina->getKey();

        $this->actingAs($widz)->post(route('onboarding.people'), ['follow' => ['halina'], 'oczekiwani' => $oczekiwani])
            ->assertRedirect(route('onboarding.done'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($widz->isFollowing($halina));
    }

    public function test_smieciowa_para_niezaznaczonej_osoby_nie_blokuje_zapisu(): void
    {
        $widz = $this->user('widz');
        $halina = $this->user('halina');

        // Pola techniczne przy niezaznaczonych osobach są poza walidacją:
        // człowiek ich nie widzi i nie może poprawić.
        $this->actingAs($widz)->post(route('onboarding.people'), [
            'follow' => ['halina'],
            'oczekiwani' => ['halina' => (string) $halina->getKey(), 'ktos' => ['x']],
        ])->assertRedirect(route('onboarding.done'))->assertSessionHasNoErrors();

        $this->assertTrue($widz->isFollowing($halina));
    }

    public function test_oczekiwani_nie_bedacy_tekstem_daje_blad_pola(): void
    {
        $widz = $this->user('widz');
        $this->user('halina');

        $this->actingAs($widz)->post(route('onboarding.people'), [
            'follow' => ['halina'],
            'oczekiwani' => ['halina' => ['x']],
        ])->assertSessionHasErrors('oczekiwani.halina');

        $this->assertDatabaseCount('follows', 0);
    }

    public function test_zaznaczone_nazwy_bez_powtorzen_z_zachowana_pisownia_i_parami_malymi_literami(): void
    {
        $zadanie = ZapisObserwowanychRequest::create('/witaj/ludzie', 'POST', [
            'follow' => ['Halina', 'HALINA', 'Jan'],
            'oczekiwani' => ['Halina' => 'id-1', 'Inna' => 'id-2'],
        ]);
        $zadanie->setContainer(app())->setRedirector(app('redirect'));
        $zadanie->validateResolved();

        $this->assertSame(['Halina', 'Jan'], $zadanie->zaznaczoneNazwy());
        // „Inna" nie jest zaznaczona, więc jej para nie trafia do walidowanych danych.
        $this->assertSame(['halina' => 'id-1'], $zadanie->oczekiwani());
    }

    public function test_nazwa_ktora_zmienila_wlasciciela_nie_jest_obserwowana_i_ma_komunikat(): void
    {
        $widz = $this->user('widz');
        $nowyWlasciciel = $this->user('halina');

        $wynik = app(ObserwujWybraneOsoby::class)
            ->handle($widz, ['halina'], ['halina' => (string) Str::uuid()]);

        $this->assertFalse($widz->isFollowing($nowyWlasciciel));
        $this->assertSame(0, $wynik->zaobserwowano);
        $this->assertSame(['halina'], $wynik->zmieniloWlasciciela);
        $this->assertStringContainsString('należy teraz do innej osoby', (string) $wynik->komunikat());
    }

    public function test_wynik_bez_problemow_nie_ma_komunikatu_a_oba_problemy_daja_oba_zdania(): void
    {
        $this->assertNull((new WynikObserwowaniaWybranych(2, 0, []))->komunikat());

        $oba = (new WynikObserwowaniaWybranych(1, 1, ['ala', 'ola']))->komunikat();
        $this->assertStringContainsString('Nie udało się dodać wszystkich wybranych osób.', (string) $oba);
        $this->assertStringContainsString('Te nazwy należą teraz do innych osób', (string) $oba);
    }

    public function test_zapis_zainteresowan_odrzuca_za_wiele_tagow_i_nie_tagi(): void
    {
        $widz = $this->user('widz');
        $tagi = array_map(fn () => (string) Str::uuid(), range(1, 51));

        $this->actingAs($widz)->from(route('onboarding.interests'))
            ->post(route('onboarding.interests'), ['tags' => $tagi])
            ->assertRedirect(route('onboarding.interests'))
            ->assertSessionHasErrors(['tags' => 'Wybierz najwyżej 50 tagów z listy i zapisz ponownie.']);

        $this->actingAs($widz)->from(route('onboarding.interests'))
            ->post(route('onboarding.interests'), ['tags' => 'sernik'])
            ->assertSessionHasErrors(['tags' => 'Wybierz tagi z listy i zapisz ponownie.']);
    }

    public function test_zapis_zainteresowan_z_poprawnym_wyborem_przechodzi_dalej(): void
    {
        $tag = Tag::factory()->create();

        $this->actingAs($this->user('widz'))
            ->post(route('onboarding.interests'), ['tags' => [$tag->getKey()]])
            ->assertRedirect(route('onboarding.people'))
            ->assertSessionHasNoErrors();
    }

    public function test_ekran_ludzi_z_tablica_zamiast_frazy_otwiera_sie_bez_bledu(): void
    {
        $this->actingAs($this->user('widz'))
            ->get(route('onboarding.people', ['q' => ['halina']]))
            ->assertOk();
    }

    public function test_wybor_z_adresu_liczy_sie_tylko_z_tokenem_biezacego_kontekstu(): void
    {
        $widz = $this->user('widz');
        $this->user('halina');

        $this->actingAs($widz)->get(route('onboarding.people'))->assertOk();
        $token = (string) session('onboarding.selection.token');
        $this->assertNotSame('', $token);

        // Z tokenem: wybór wraca zaznaczony.
        $this->get(route('onboarding.people', ['follow' => ['halina'], 'selection' => $token]))
            ->assertOk()
            ->assertViewHas('selectedFollows', ['halina'])
            ->assertViewHas('selectionExpired', false);

        // Kontrola ujemna: obcy token nie przenosi wyboru i mówi, że wybór wygasł.
        $this->get(route('onboarding.people', ['follow' => ['halina'], 'selection' => (string) Str::uuid()]))
            ->assertOk()
            ->assertViewHas('selectedFollows', [])
            ->assertViewHas('selectionExpired', true);
    }
}
