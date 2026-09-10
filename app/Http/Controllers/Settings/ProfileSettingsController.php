<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Rules\ReservedUsername;
use App\Rules\UsernameNotTaken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Profil: imię, nazwa użytkownika, kilka słów o sobie.
 *
 * ZDJĘCIA PROFILOWEGO TU JUŻ NIE MA — jest na `/ustawienia/zdjecie`
 * (`AvatarSettingsController`). Nie jest to druga droga obok tej: pole
 * zostało STĄD PRZENIESIONE. Powód jest w dwóch miejscach naraz:
 *
 *  - droga: zdjęcie stało jako szóste pole formularza, pod nazwą, nazwą
 *    użytkownika, opisem, regionem i specjalnością — czyli pod pięcioma
 *    polami, których człowiek chcący wstawić swoją twarz nie zamierzał ruszać;
 *  - walidacja: ten formularz wysyła WSZYSTKIE pola naraz, więc zmiana samego
 *    zdjęcia odbijała się od błędu przy nazwie użytkownika (zajęta,
 *    zastrzeżona, za krótka) — od czegoś, czego nikt tu nie dotykał.
 */
class ProfileSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('pages.settings.profile', [
            'profile' => $request->user()->profile,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $profile = $user->profile;

        // Druga — łatwiejsza do przeoczenia — droga do nazwy użytkownika.
        // Sprawdzanie tylko przy rejestracji byłoby zabezpieczeniem na pokaz:
        // wystarczyłoby założyć konto „basia" i zmienić je tutaj na „pomoc".
        $usernameRules = [
            'required', 'string', 'min:3', 'max:40', 'regex:/^[a-zA-Z0-9_]+$/',
        ];

        // Listę zastrzeżonych sprawdzamy TYLKO wtedy, gdy nazwa faktycznie się
        // zmienia. Konta obsługi mają takie nazwy legalnie (w DemoSeederze jest
        // @moderacja) — a formularz profilu wysyła wszystkie pola naraz, więc
        // reguła działająca też przy niezmienionej nazwie zabrałaby takiej
        // osobie możliwość zapisania czegokolwiek: bio, regionu, specjalności.
        // Odebranie komuś zastrzeżonej nazwy to sprawa moderacji, nie
        // walidatora formularza ustawień.
        if ($request->input('username') !== $profile->username) {
            $usernameRules[] = new ReservedUsername;
        }

        // Zajętość sprawdzamy bez rozróżniania wielkości liter (audyt A25) —
        // inaczej ta droga zostawiałaby otwartą furtkę, którą rejestracja
        // właśnie zamknęła: konto „basia2" zmieniające nazwę na „Basia".
        $usernameRules[] = new UsernameNotTaken($user->getKey());

        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:100'],
            'username' => $usernameRules,
            'bio' => ['nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:80'],
            'speciality' => ['nullable', 'string', 'max:120'],
        ], [
            'display_name.required' => 'Podaj imię, którym mamy Cię nazywać.',
            'username.regex' => 'Nazwa użytkownika może zawierać tylko litery bez polskich znaków, cyfry i podkreślnik.',
            'username.unique' => 'Ta nazwa jest już zajęta.',
            'bio.max' => 'Ten opis jest za długi. Zmieść się w 500 znakach.',
        ]);

        $profile->update($data);

        return back()->with('status', 'Zapisane.');
    }
}
