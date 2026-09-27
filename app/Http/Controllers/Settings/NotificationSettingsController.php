<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Notifications\Push\KanalPush;
use App\Domain\Notifications\Push\OdlaczCudzaSubskrypcjePush;
use App\Domain\Notifications\Push\ZapiszSubskrypcjePush;
use App\Http\Controllers\Controller;
use App\Models\UstawieniaPowiadomienZewnetrznych;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * `/ustawienia/powiadomienia` — WYŁĄCZNIE kanały poza serwisem (D-303).
 *
 * Powiadomienia w serwisie (dzwonek) nie mają tu żadnego przełącznika
 * i mieć nie będą (AGENTS.md §1). Ekran ustawia trzy rzeczy: czy push jest
 * włączony (per przeglądarka), ciszę nocną i dzienny limit.
 *
 * BEZ KLUCZY VAPID EKRANU NIE MA (404), tak jak nie ma go w spisie ustawień.
 *
 * BEZ IDENTYFIKATORA W ADRESIE i bez Policy: każda akcja działa na koncie
 * zalogowanej osoby, a subskrypcję do wyłączenia wskazuje jej adres
 * w obrębie `$request->user()->pushSubscriptions()` — cudzego wiersza nie
 * da się tu ani odczytać, ani skasować (AGENTS.md §7).
 */
class NotificationSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless(KanalPush::dostepny(), 404);

        $user = $request->user();

        return view('pages.settings.notifications', [
            'ustawienia' => $this->ustawienia($user->ustawieniaPowiadomienZewnetrznych),
            'urzadzenia' => $user->pushSubscriptions()->orderBy('created_at')->get(),
            'kluczPubliczny' => KanalPush::kluczPubliczny(),
            'limity' => $this->limity(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(KanalPush::dostepny(), 404);

        $dane = $request->validate([
            'cisza_od' => ['required', 'integer', 'between:0,23'],
            'cisza_do' => ['required', 'integer', 'between:0,23'],
            'dzienny_limit' => ['required', 'integer', Rule::in($this->limity())],
        ], [
            'cisza_od.*' => 'Wybierz z listy godzinę, od której mamy nic nie wysyłać.',
            'cisza_do.*' => 'Wybierz z listy godzinę, do której mamy nic nie wysyłać.',
            'dzienny_limit.*' => 'Wybierz z listy, ile powiadomień dziennie możemy wysłać.',
        ]);

        $request->user()->ustawieniaPowiadomienZewnetrznych()->updateOrCreate([], [
            'cisza_od' => (int) $dane['cisza_od'],
            'cisza_do' => (int) $dane['cisza_do'],
            'dzienny_limit' => (int) $dane['dzienny_limit'],
        ]);

        return back()->with('status', 'Zapisane.');
    }

    /**
     * Wołane przez `resources/js/powiadomienia-push.js` PO zgodzie
     * przeglądarki, którą człowiek dał kliknięciem przycisku na tym ekranie.
     */
    public function subscribe(Request $request, ZapiszSubskrypcjePush $zapisz): JsonResponse
    {
        abort_unless(KanalPush::dostepny(), 404);

        $dane = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', function (string $atrybut, mixed $wartosc, \Closure $zle): void {
                if (! is_string($wartosc) || ! KanalPush::hostDozwolony($wartosc)) {
                    $zle('Ta przeglądarka zgłosiła nieznaną usługę powiadomień. Spróbuj w Chrome, Edge, Firefoksie albo Safari.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'min:40', 'max:200', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'min:16', 'max:100', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'contentEncoding' => ['nullable', 'string', Rule::in(['aes128gcm', 'aesgcm'])],
        ], [
            'endpoint.required' => 'Przeglądarka nie przekazała adresu powiadomień. Odśwież stronę i spróbuj jeszcze raz.',
            'keys.*' => 'Przeglądarka przekazała niepełne dane powiadomień. Odśwież stronę i spróbuj jeszcze raz.',
            'contentEncoding.*' => 'Ta przeglądarka używa nieobsługiwanego sposobu szyfrowania powiadomień.',
        ]);

        $zapisz->handle(
            $request->user(),
            $dane['endpoint'],
            $dane['keys']['p256dh'],
            $dane['keys']['auth'],
            $dane['contentEncoding'] ?? 'aes128gcm',
        );

        return response()->json(['status' => 'Powiadomienia na tym urządzeniu są włączone.'], 201);
    }

    /** Wyłączenie na TEJ przeglądarce (wołane z JS razem z `unsubscribe()` w przeglądarce). */
    public function unsubscribe(Request $request): Response
    {
        $dane = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        $request->user()->pushSubscriptions()->where('endpoint', $dane['endpoint'])->delete();

        return response()->noContent();
    }

    /** Po wygaśnięciu sesji odłącz subskrypcję poprzedniej osoby z tej przeglądarki. */
    public function reconcileDevice(Request $request, OdlaczCudzaSubskrypcjePush $odlacz): JsonResponse
    {
        $dane = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);

        $odlaczone = $odlacz->handle(
            $request->user(), $dane['endpoint'], $dane['keys']['p256dh'], $dane['keys']['auth'],
        );

        return response()->json(['odlaczone' => $odlaczone]);
    }

    /**
     * „Wyłącz na wszystkich urządzeniach" — zwykły formularz, działa bez JS.
     * Działa także przy braku kluczy VAPID: wyłączenie ma być zawsze możliwe.
     */
    public function disableAll(Request $request): RedirectResponse
    {
        $request->user()->pushSubscriptions()->delete();

        return back()->with('status', 'Powiadomienia poza serwisem są wyłączone na wszystkich urządzeniach.');
    }

    /** @return array{cisza_od: int, cisza_do: int, dzienny_limit: int} */
    private function ustawienia(?UstawieniaPowiadomienZewnetrznych $zapisane): array
    {
        return [
            'cisza_od' => $zapisane?->cisza_od ?? (int) config('kuking.notifications.zewnetrzne.cisza_od_godziny', 21),
            'cisza_do' => $zapisane?->cisza_do ?? (int) config('kuking.notifications.zewnetrzne.cisza_do_godziny', 8),
            'dzienny_limit' => $zapisane?->dzienny_limit ?? (int) config('kuking.notifications.zewnetrzne.dzienny_limit', 1),
        ];
    }

    /** @return list<int> */
    private function limity(): array
    {
        $limity = array_map('intval', (array) config('kuking.notifications.zewnetrzne.limity_do_wyboru', [1, 2, 3, 5]));
        $domyslny = (int) config('kuking.notifications.zewnetrzne.dzienny_limit', 1);

        // Domyślny limit zawsze jest do wybrania — inaczej formularz pokazałby
        // wartość, której nie da się zapisać.
        if ($domyslny >= 1 && $domyslny <= 10 && ! in_array($domyslny, $limity, true)) {
            $limity[] = $domyslny;
        }

        $limity = array_values(array_unique(array_filter($limity, fn (int $l): bool => $l >= 1 && $l <= 10)));
        sort($limity);

        return $limity;
    }
}
