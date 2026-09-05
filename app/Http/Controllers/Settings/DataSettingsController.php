<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\DataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * "Twoje dane" — eksport i usunięcie konta.
 *
 * To jest w MVP, nie "kiedyś". Powody są dwa i oba ważne:
 *  - prawny: RODO art. 15, 17 i 20,
 *  - produktowy: obietnica "Twoje przepisy nie zginą" jest wiarygodna tylko
 *    wtedy, gdy da się je z serwisu wyjąć. Garnek.pl i Durszlak.pl zniknęły
 *    z treściami użytkowników; nie powtarzamy tego.
 *
 * Usunięcie konta jest DWUETAPOWE: konto przechodzi w stan `pending_delete`
 * i przez 30 dni da się je odzyskać. Nieodwracalne usunięcie po jednym
 * kliknięciu byłoby okrutne wobec osoby, która pomyliła przycisk.
 */
class DataSettingsController extends Controller
{
    public function show(Request $request): View
    {
        return view('pages.settings.data', [
            'exports' => $request->user()->dataExports()->latest()->limit(5)->get(),
            'graceDays' => (int) config('kuking.account.delete_grace_days'),
        ]);
    }

    public function requestExport(Request $request): RedirectResponse
    {
        $user = $request->user();

        $pending = $user->dataExports()
            ->whereIn('status', [DataExport::STATUS_QUEUED, DataExport::STATUS_PROCESSING])
            ->exists();

        if ($pending) {
            return back()->with('status', 'Przygotowanie paczki z Twoimi danymi już trwa. Napiszemy, gdy będzie gotowa.');
        }

        DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        AuditLogEntry::record('data.export_requested', $user, $user, ip: $request->ip());

        return back()->with('status',
            'Przygotowujemy paczkę z Twoimi danymi. To może potrwać kilkanaście minut — napiszemy na Twój adres e-mail, gdy będzie gotowa.',
        );
    }

    public function requestDeletion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
        ], [
            'password.required' => 'Wpisz swoje hasło, żeby potwierdzić, że to Ty.',
            'confirm.accepted' => 'Zaznacz, że rozumiesz, co się stanie.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'To hasło jest nieprawidłowe.']);
        }

        $user->markForDeletion();

        AuditLogEntry::record('account.delete_requested', $user, $user, ip: $request->ip());

        $days = (int) config('kuking.account.delete_grace_days');

        return redirect()->route('landing')->with('status',
            "Konto zostało oznaczone do usunięcia. Masz {$days} dni, żeby zmienić zdanie — wystarczy, że się zalogujesz i napiszesz do nas.",
        );
    }
}
