<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

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

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'wants_weekly_digest' => ['nullable', 'boolean'],
            'memories_enabled' => ['nullable', 'boolean'],
        ]);

        $request->user()->update([
            'wants_weekly_digest' => $request->boolean('wants_weekly_digest'),
            // Wspomnienia „Rok temu gotowałaś…" (issue #34). Wyłączenie musi
            // być JEDNYM przełącznikiem i musi działać od razu — człowiek,
            // któremu wspomnienia zaczęły sprawiać ból, nie ma odklikiwać ich
            // po kolei ani szukać tego ustawienia w trzecim menu.
            'memories_enabled' => $request->boolean('memories_enabled'),
        ]);

        return back()->with('status', 'Zapisane.');
    }
}
