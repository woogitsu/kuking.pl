<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PodsumowanieWalidacjiNieZgadujePrzyczynyTest extends TestCase
{
    use RefreshDatabase;

    public static function stanyNotatki(): array
    {
        return [['nowa', ['handler_note']], ['nieznany', ['handler_note', 'status']]];
    }

    #[DataProvider('stanyNotatki')]
    public function test_wpisany_tekst_nie_jest_opisany_jako_brakujacy(string $status, array $bledy): void
    {
        $wiadomosc = ContactMessage::factory()->create();
        $tekst = str_repeat('ą', 2001);
        $adres = route('admin.contact.show', $wiadomosc);
        $ekran = $this->actingAs($this->moderator())->followingRedirects()->from($adres)
            ->post(route('admin.contact.update', $wiadomosc), ['status' => $status, 'handler_note' => $tekst])
            ->assertOk();
        $ekran->assertSee('Sprawdź formularz')->assertDontSee('rzeczy jeszcze brakuje');
        $ekran->assertSee('Notatka jest za długa')->assertSee($tekst);
        $ekran->assertSee('href="#f-handler_note"', false)->assertSee('role="alert" tabindex="-1"', false);
        foreach ($bledy as $pole) {
            $ekran->assertSee('href="#f-'.$pole.'"', false);
        }
        $this->assertNull($wiadomosc->refresh()->handler_note);
        $this->assertSame(ContactMessage::STATUS_NOWA, $wiadomosc->status);
    }

    public function test_kreator_wskazuje_poprawe_nieprawidlowej_nazwy(): void
    {
        $tekst = 'aa';
        Livewire::actingAs($this->user('kreator527'))->test('recipe-wizard')
            ->set('title', $tekst)->call('next')->assertHasErrors('title')
            ->assertSet('title', $tekst)->assertSee('Sprawdź formularz')
            ->assertDontSee('rzeczy jeszcze brakuje');
    }

    public static function wejscia(): array
    {
        return [['auth.register'], ['auth.google-finish'], ['auth.facebook-finish']];
    }

    #[DataProvider('wejscia')]
    public function test_instrukcja_pod_formularzem_nie_zgaduje_przyczyny(string $widok): void
    {
        // Render rzeczywistego widoku z błędem formatu; bez połączenia z OAuth.
        $bledy = (new ViewErrorBag)->put('default', new MessageBag(['username' => 'Nazwa ma niedozwolony znak.']));
        app('view')->share('errors', $bledy);
        $ekran = $this->view($widok, ['errors' => $bledy, 'email' => 'lokalny@example.test',
            'proponowaneImie' => 'Basia', 'proponowanaNazwa' => 'basia', 'zaproszenie' => null]);
        $ekran->assertSee('co trzeba poprawić')->assertDontSee('czego jeszcze brakuje');
        $ekran->assertSee('Nazwa ma niedozwolony znak.');
    }
}
