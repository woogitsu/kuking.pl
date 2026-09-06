<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Http\Controllers\Controller;
use App\Rules\ObslugiwaneZdjecie;
use App\Rules\ReservedUsername;
use App\Rules\UsernameNotTaken;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ProfileSettingsController extends Controller
{
    public function __construct(private readonly StoreUploadedImage $storeImage) {}

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
        // osobie możliwość zapisania czegokolwiek: bio, regionu, avatara.
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
            'avatar' => ['nullable', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
        ], [
            'display_name.required' => 'Podaj imię, którym mamy Cię nazywać.',
            'username.regex' => 'Nazwa użytkownika może zawierać tylko litery bez polskich znaków, cyfry i podkreślnik.',
            'username.unique' => 'Ta nazwa jest już zajęta.',
            'bio.max' => 'Ten opis jest za długi. Zmieść się w 500 znakach.',
            'avatar.image' => 'Zdjęcie profilowe musi być plikiem JPG, PNG lub WebP.',
            // Wcześniej brakowało tego komunikatu — za duży plik pokazywał
            // domyślny, angielski błąd Laravela (narusza AGENTS.md).
            'avatar.max' => LimityZdjec::komunikatZaDuzyPlik(),
        ]);

        try {
            if ($request->hasFile('avatar')) {
                $data['avatar_media_id'] = $this->storeImage->handle($user, $request->file('avatar'))->getKey();
            }
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['avatar' => $e->getMessage()]);
        }

        unset($data['avatar']);
        $profile->update($data);

        return back()->with('status', 'Zapisane.');
    }
}
