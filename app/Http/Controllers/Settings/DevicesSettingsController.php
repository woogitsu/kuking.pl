<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Api\Actions\OdwolajTokenAplikacji;
use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Urządzenia z dostępem" — telefony i tablety zalogowane w aplikacji
 * mobilnej (D-270). Jedyne miejsce, w którym człowiek WIDZI swoje tokeny
 * i może je odwołać bez telefonu w ręku (zgubiony, sprzedany, u wnuczki).
 *
 * Odwołanie jednego idzie przez Policy (`PersonalAccessTokenPolicy`):
 * identyfikator urządzenia w adresie nie jest autoryzacją (AGENTS.md §7).
 */
class DevicesSettingsController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $urzadzenia = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->getKey())
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->orderByDesc('id')
            ->get(['id', 'name', 'created_at', 'last_used_at']);

        return view('pages.settings.devices', ['urzadzenia' => $urzadzenia]);
    }

    public function destroy(Request $request, PersonalAccessToken $urzadzenie, OdwolajTokenAplikacji $odwolaj): RedirectResponse
    {
        $this->authorize('delete', $urzadzenie);

        $odwolaj->handle($request->user(), $urzadzenie, $request->ip());

        return redirect()->route('settings.devices')->with('status',
            'Gotowe. „'.$urzadzenie->name.'" nie ma już dostępu do Twojego konta. '
            .'Żeby znów z niego korzystać, trzeba będzie zalogować się w aplikacji jeszcze raz.',
        );
    }

    public function destroyAll(Request $request, OdwolajTokenAplikacji $odwolaj): RedirectResponse
    {
        $odwolaj->wszystkie($request->user(), $request->ip());

        return redirect()->route('settings.devices')->with('status',
            'Gotowe. Żadne urządzenie nie ma już dostępu przez aplikację. '
            .'Na tym komputerze/telefonie w przeglądarce zostajesz zalogowany.',
        );
    }
}
