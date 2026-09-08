<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ModerationAction;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // `visibleTo` wycina powiadomienia od osób, z którymi łączy tę osobę
        // blokada — także te sprzed blokady. Ten sam filtr chodzi w liczniku
        // nieprzeczytanych (`User::unreadNotificationsCount()`); gdyby chodził
        // tylko tutaj, w belce świeciłoby „3 nieprzeczytane" nad pustą listą.
        $notifications = $user
            ->notifications()
            ->visibleTo($user)
            ->with('actor.profile.avatar')
            ->paginate(30);

        return view('pages.notifications', [
            'notifications' => $notifications,
            'decyzjeModeracyjne' => $this->decyzje($notifications->items()),
        ]);
    }

    /**
     * Decyzje moderacyjne dla powiadomień z tej strony — JEDNYM zapytaniem.
     *
     * Uzasadnienie z art. 17 ust. 3 (`UzasadnienieDecyzji`) powstaje przy
     * WYŚWIETLANIU, nie przy zapisie, bo termin na odwołanie liczy
     * `ModerationAction::appealDeadline()` i zamrożenie daty w `data`
     * pokazywałoby po zmianie konfiguracji termin krótszy niż prawdziwy.
     * Wczytanie w widoku (`$decyzja->appeal`) dałoby jednak N+1 na stronie
     * z trzydziestoma powiadomieniami, więc identyfikatory zbieramy tutaj.
     *
     * @param  list<Notification>  $powiadomienia
     * @return Collection<string, ModerationAction>
     */
    private function decyzje(array $powiadomienia): Collection
    {
        $identyfikatory = [];

        foreach ($powiadomienia as $powiadomienie) {
            if ($powiadomienie->type !== Notification::TYPE_MODERATION) {
                continue;
            }

            $id = $powiadomienie->data['action_id'] ?? null;

            if (is_string($id) && $id !== '') {
                $identyfikatory[$id] = true;
            }
        }

        if ($identyfikatory === []) {
            return new Collection;
        }

        return ModerationAction::query()
            ->whereIn('id', array_keys($identyfikatory))
            ->get()
            ->keyBy(fn (ModerationAction $decyzja): string => (string) $decyzja->getKey());
    }

    /**
     * Oznaczenie wszystkiego jako przeczytane jest JAWNYM kliknięciem,
     * nie efektem ubocznym wejścia na stronę. Osoba, która przypadkiem
     * weszła w powiadomienia, nie może stracić informacji o tym, że
     * ktoś ugotował z jej przepisu.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Wszystkie powiadomienia oznaczone jako przeczytane.');
    }

    /**
     * „Zobacz" — oznacza TO JEDNO powiadomienie jako przeczytane i odsyła
     * do treści, której dotyczy.
     *
     * CO BYŁO ZEPSUTE (zgłoszenie właściciela, 8 września)
     * „Zobacz" był zwykłym odnośnikiem `<a href>`. Człowiek klikał, oglądał
     * treść, wracał — i licznik dalej pokazywał jedno nieprzeczytane.
     * Klikał jeszcze raz, znowu nic. Dopiero „oznacz wszystkie jako
     * przeczytane" gasiło plakietkę. Żadnej z tych rzeczy nie widać
     * w kodzie jako błędu: po prostu NIE BYŁO trasy, która oznaczałaby
     * pojedynczy wiersz.
     *
     * DLACZEGO TO NIE JEST SPRZECZNE Z REGUŁĄ OBOK
     * `markAllRead()` broni się tym, że oznaczanie wszystkiego nie może być
     * skutkiem ubocznym samego wejścia na stronę — ktoś, kto zajrzał
     * przypadkiem, nie ma prawa stracić informacji, że ktoś ugotował z jego
     * przepisu. Tu jest odwrotnie: kliknięcie w konkretne powiadomienie to
     * najczystszy możliwy dowód, że człowiek je zobaczył. Reguła brzmi
     * „nie po cichu", a nie „nigdy pojedynczo".
     *
     * DLACZEGO POST, A NIE `<a href>`
     * Bo to zapis. GET zmieniający stan oznacza, że wystarczy podrzucić
     * komuś obrazek wskazujący na ten adres, żeby wyczyścić mu
     * powiadomienia. Formularz działa też BEZ JAVASCRIPTU (AGENTS.md),
     * więc nic na tym nie tracimy.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        // WŁAŚCICIELSTWO EGZEKWUJE ZAPYTANIE, NIE SAM IDENTYFIKATOR.
        // AGENTS.md §7: UUID w adresie to nie autoryzacja. Szukamy wyłącznie
        // wśród powiadomień TEJ osoby, więc cudzy identyfikator nie wybierze
        // żadnego wiersza i kończy się na 404 — bez ujawnienia, czy taki
        // wiersz w ogóle istnieje.
        $powiadomienie = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();

        // Tylko gdy nieprzeczytane. Powtórne kliknięcie nie ma prawa
        // przesuwać znacznika w przód — od `read_at` zależy retencja
        // (`PrzedawnionePowiadomienia`), więc przesuwanie go przedłużałoby
        // życie danych przy każdym zajrzeniu.
        if ($powiadomienie->isUnread()) {
            $powiadomienie->forceFill(['read_at' => now()])->save();
        }

        $cel = $powiadomienie->adresDocelowy();

        // ODESŁANIE TYLKO W OBRĘBIE SERWISU. Adres dla typów spoza `match`
        // bierze się z `data['url']`, czyli z wiersza w bazie. Dziś wpisuje
        // go wyłącznie nasz kod, ale przekierowanie pod dowolny adres z bazy
        // to gotowe otwarte przekierowanie na przyszłość — a koszt zamknięcia
        // tego dziś wynosi trzy linijki.
        if ($cel === null || ! $this->wlasnyAdres($cel)) {
            return back();
        }

        return redirect()->to($cel);
    }

    /** Czy adres prowadzi do tego serwisu, a nie na zewnątrz. */
    private function wlasnyAdres(string $adres): bool
    {
        if (str_starts_with($adres, '/') && ! str_starts_with($adres, '//')) {
            return true;
        }

        $gospodarz = parse_url($adres, PHP_URL_HOST);

        return $gospodarz !== false
            && $gospodarz !== null
            && $gospodarz === parse_url((string) config('app.url'), PHP_URL_HOST);
    }
}
