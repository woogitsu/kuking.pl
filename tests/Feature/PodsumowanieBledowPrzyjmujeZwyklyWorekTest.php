<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * `components/error-summary` przyjmuje OBA rodzaje `$errors`.
 *
 * CO SIĘ DZIAŁO
 * Wybór worka błędów (`$errorBag`, osobne formularze 2FA na jednym ekranie)
 * wszedł jako bezwarunkowe `$errors->getBag($errorBag)`. Wyszukiwarka
 * i onboarding dołączają jednak ten komponent przez
 * `@include(..., ['errors' => $searchErrors])` ze ZWYKŁYM `MessageBag`
 * z walidatora frazy, a `MessageBag` nie ma `getBag()`. Skutek: 500 na
 * każdym wejściu na `/szukaj` i `/witaj/ludzie`, także dla gościa i także
 * bez żadnego błędu do pokazania.
 *
 * CZEGO PILNUJE TEN PLIK
 * Obu dróg naraz: zwykły worek się renderuje (strona żyje, błąd frazy jest
 * widoczny), a `ViewErrorBag` dalej wybiera WSKAZANY worek, nie wszystkie —
 * inaczej naprawa cofałaby zachowanie, dla którego `$errorBag` powstał.
 */
final class PodsumowanieBledowPrzyjmujeZwyklyWorekTest extends TestCase
{
    use RefreshDatabase;

    public function test_wyszukiwarka_zyje_bez_bledu_frazy(): void
    {
        $this->get(route('search', ['q' => 'zupa']))->assertOk();
    }

    public function test_wyszukiwarka_pokazuje_blad_zbyt_dlugiej_frazy(): void
    {
        $this->get(route('search', ['q' => str_repeat('a', SearchQuery::MAX_PHRASE_LENGTH + 1)]))
            ->assertOk()
            ->assertSee('Sprawdź formularz')
            ->assertSee('Skróć tekst w polu', false);
    }

    public function test_krok_onboardingu_z_ludzmi_zyje_i_pokazuje_blad_frazy(): void
    {
        $this->actingAs($this->user('onboarding_worek'))
            ->get(route('onboarding.people', ['q' => str_repeat('a', SearchQuery::MAX_PHRASE_LENGTH + 1)]))
            ->assertOk()
            ->assertSee('Sprawdź formularz')
            ->assertSee('Skróć tekst w polu', false);
    }

    public function test_zwykly_worek_renderuje_sie_w_calosci(): void
    {
        $html = view('components.error-summary', [
            'errors' => new MessageBag(['q' => ['Błąd frazy']]),
        ])->render();

        $this->assertStringContainsString('Błąd frazy', $html);
    }

    public function test_view_error_bag_dalej_wybiera_wskazany_worek(): void
    {
        $worki = (new ViewErrorBag)
            ->put('default', new MessageBag(['name' => ['Błąd z formularza domyślnego']]))
            ->put('disable', new MessageBag(['password' => ['Błąd z formularza wyłączania']]));

        $html = view('components.error-summary', [
            'errors' => $worki,
            'errorBag' => 'disable',
        ])->render();

        $this->assertStringContainsString('Błąd z formularza wyłączania', $html);
        $this->assertStringNotContainsString('Błąd z formularza domyślnego', $html);
    }
}
