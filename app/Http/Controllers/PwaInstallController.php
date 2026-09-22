<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Pwa\InstallPrompt;
use App\Domain\Pwa\InstallPromptContext;
use App\Domain\Pwa\RecordPromptShown;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PwaInstallController extends Controller
{
    public function update(Request $request, InstallPrompt $prompt, InstallPromptContext $context, ZapiszSygnal $signals, RecordPromptShown $shown): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validate([
            'context' => ['required', 'string', 'max:4096'],
            'action' => ['required', Rule::in(['offer', 'shown', 'request', 'dismiss', 'installed'])],
        ], [
            'context.*' => 'Odśwież stronę, żeby zapisać wybór instalacji.',
            'action.*' => 'Wybierz jedną z dostępnych czynności instalacji.',
        ]);
        abort_unless($context->valid($data['context'], $user, $request->session()->getId()), 409);

        $changed = match ($data['action']) {
            'offer' => $prompt->offer($user),
            'shown' => $shown->handle($user, $signals),
            'request' => $prompt->requestInstallation($user),
            'dismiss' => $prompt->dismiss($user),
            'installed' => $prompt->installed($user),
        };

        if ($changed && in_array($data['action'], ['request', 'dismiss', 'installed'], true)) {
            $signals->handle($user, match ($data['action']) {
                'request' => ZapiszSygnal::PWA_INSTALL_REQUESTED,
                'dismiss' => ZapiszSygnal::PWA_PROMPT_DISMISSED,
                'installed' => ZapiszSygnal::PWA_INSTALLED,
            });
        }

        return response()->json(['changed' => $changed]);
    }
}
