<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * „Jak mamy do Ciebie pisać?” — zapis formy zwracania się (D-268, #1752).
 *
 * DWIE DROGI, JEDNA AKCJA: ekran `/ustawienia/profil` i krok „Gotowe”
 * w onboardingu wysyłają to samo pole do tej samej walidacji. Różni się
 * wyłącznie to, dokąd człowiek wraca.
 *
 * OSOBNY FORMULARZ, NIE SZÓSTE POLE `ProfileSettingsController`. Tamten
 * formularz wysyła wszystkie pola naraz, więc wybór formy odbijałby się od
 * błędu przy nazwie użytkownika (zajęta, zastrzeżona, za krótka) — od czegoś,
 * czego człowiek nie dotykał. Ten sam powód przeniósł zdjęcie profilowe na
 * własny ekran (`AvatarSettingsController`).
 *
 * AUTORYZACJA BEZ IDENTYFIKATORA W ADRESIE: trasa działa zawsze na profilu
 * osoby zalogowanej, a regułę „swój profil zmienia właściciel” i tak trzyma
 * `ProfilePolicy::update` (AGENTS.md §7).
 *
 * DZIAŁA BEZ JAVASCRIPTU — zwykły formularz z polami wyboru. Nie jest to
 * newralgiczny formularz w rozumieniu D-053.
 */
class FormOfAddressController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $this->zapisz($request);

        return redirect()->to(route('settings.profile').'#forma-zwracania')
            ->with('status', 'Zapisane. Tak będziemy do Ciebie i o Tobie pisać.');
    }

    /**
     * Krok onboardingu jest POMIJALNY: dwa wyjścia z ekranu „Gotowe” działają
     * bez wysyłania tego formularza, a brak wyboru zostawia formę neutralną.
     */
    public function updateFromOnboarding(Request $request): RedirectResponse
    {
        $this->zapisz($request);

        return redirect()->route('onboarding.done')
            ->with('status', 'Zapisane. Zmienisz to w każdej chwili w ustawieniach profilu.');
    }

    private function zapisz(Request $request): void
    {
        $profile = $request->user()->profile;

        $this->authorize('update', $profile);

        $data = $request->validate([
            'form_of_address' => ['required', Rule::in(Profile::FORM_CHOICES)],
        ], [
            'form_of_address.required' => 'Zaznacz jedną z trzech odpowiedzi i kliknij „Zapisz formę”. Jeśli nie chcesz wybierać, zaznacz formę neutralną.',
            'form_of_address.in' => 'Zaznacz jedną z trzech odpowiedzi i kliknij „Zapisz formę”. Jeśli nie chcesz wybierać, zaznacz formę neutralną.',
        ]);

        $profile->update([
            'form_of_address' => Profile::formOfAddressFromChoice((string) $data['form_of_address']),
        ]);
    }
}
