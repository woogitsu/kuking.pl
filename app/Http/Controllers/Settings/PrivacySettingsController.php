<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Users\Actions\UpdatePrivacySettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrivacySettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('pages.settings.privacy', [
            'blocked' => $request->user()->blocking()->with('profile.avatar')->get(),
        ]);
    }

    public function update(Request $request, UpdatePrivacySettings $settings): RedirectResponse
    {
        $request->mergeIfMissing(['wants_weekly_digest' => '0', 'memories_enabled' => '0']);
        $request->validate([
            'wants_weekly_digest' => ['nullable', 'boolean'],
            'memories_enabled' => ['nullable', 'boolean'],
            'original_digest' => ['required', 'boolean'],
            'original_memories' => ['required', 'boolean'],
        ], [
            'original_digest.*' => 'Otwórz aktualne ustawienia i wybierz ponownie zgodę na tygodniowy e-mail.',
            'original_memories.*' => 'Otwórz aktualne ustawienia i wybierz ponownie ustawienie wspomnień.',
        ]);

        $settings->handle(
            $request->user(),
            $request->boolean('wants_weekly_digest'),
            $request->boolean('memories_enabled'),
            $request->boolean('original_digest'),
            $request->boolean('original_memories'),
        );

        return back()->with('status', 'Zapisane.');
    }
}
