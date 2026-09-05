<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:100'],
            'username' => [
                'required', 'string', 'min:3', 'max:40', 'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('profiles', 'username')->ignore($user->getKey(), 'user_id'),
            ],
            'bio' => ['nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:80'],
            'speciality' => ['nullable', 'string', 'max:120'],
            'avatar' => ['nullable', 'file', 'image', 'max:'.(int) floor(config('kuking.media.max_bytes') / 1024)],
        ], [
            'display_name.required' => 'Podaj imię, którym mamy Cię nazywać.',
            'username.regex' => 'Nazwa użytkownika może zawierać tylko litery bez polskich znaków, cyfry i podkreślnik.',
            'username.unique' => 'Ta nazwa jest już zajęta.',
            'bio.max' => 'Ten opis jest za długi. Zmieść się w 500 znakach.',
            'avatar.image' => 'Zdjęcie profilowe musi być plikiem JPG, PNG lub WebP.',
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
