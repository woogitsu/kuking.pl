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
        ]);

        $request->user()->update([
            'wants_weekly_digest' => $request->boolean('wants_weekly_digest'),
        ]);

        return back()->with('status', 'Zapisane.');
    }
}
