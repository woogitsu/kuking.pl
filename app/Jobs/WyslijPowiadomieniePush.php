<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notifications\Push\KanalPush;
use App\Domain\Notifications\Push\TransportPush;
use App\Domain\Notifications\Push\TrescPush;
use App\Domain\Notifications\Push\WynikWysylkiPush;
use App\Domain\Notifications\TerminPowiadomieniaZewnetrznego as Termin;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wysyła JEDEN push z tym, co czeka na odbiorcę (issue #35, D-303).
 *
 * ZADANIE NIE NIESIE POWIADOMIENIA, TYLKO ODBIORCĘ. Przy uruchomieniu samo
 * zbiera wszystkie jeszcze nie wysłane pushem powiadomienia (typy
 * z `KanalPush`) i wysyła je razem. Z tego wynikają trzy reguły naraz:
 *
 *  - GRUPOWANIE: pięć komentarzy w kwadrans przed 8:00 daje o 8:00 jeden push,
 *    nie pięć (`TrescPush`).
 *  - ODŁOŻENIE, NIE SKASOWANIE: cisza nocna i wyczerpany limit
 *    (`TerminPowiadomieniaZewnetrznego`) wstawiają zadanie ponownie na
 *    moment, od którego wolno wysłać. Powiadomienie w serwisie jest od razu.
 *  - JEDNO ZADANIE NA OSOBĘ: `ShouldBeUniqueUntilProcessing` po `userId` —
 *    kolejne zdarzenia w czasie ciszy nie mnożą zadań, dołączają do
 *    czekającego. Zamek zwalnia się na starcie, więc zdarzenie, które
 *    przyjdzie w trakcie wysyłki, wstawi nowe zadanie.
 *
 * PRZECZYTANE W SERWISIE NIE IDZIE PUSHEM. Kto już zobaczył powiadomienie
 * na liście, nie dostaje o nim szturchnięcia rano.
 *
 * BEZ PONAWIANIA (`$tries = 1`). Push to szturchnięcie, nie list polecony:
 * powiadomienie i tak czeka w serwisie. Subskrypcja, której usługa push
 * odpowiada 404/410, jest kasowana od razu — nie ma nieskończonych prób
 * na martwy adres.
 */
final class WyslijPowiadomieniePush implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Przestrzeń blokad doradczych (numer issue) — wzorzec `NotifyRecipeSaved`. */
    private const PRZESTRZEN_BLOKAD = 35;

    public int $tries = 1;

    public int $timeout = 60;

    /** Zamek unikalności nie może przeżyć najdłuższego odłożenia (limit → rano następnej doby). */
    public int $uniqueFor = 172800;

    public function __construct(public string $userId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(TransportPush $transport): void
    {
        if (! KanalPush::mozeWysylac()) {
            if (KanalPush::dostepny()) {
                // Klucz publiczny jest, prywatnego nie ma: ludzie włączają
                // push, a nic nie wychodzi. Konfiguracja, nie awaria sieci.
                Log::error('Web Push: brak VAPID_PRIVATE_KEY w procesie kolejki — nic nie wysłano.');
            }

            return;
        }

        $user = User::with('ustawieniaPowiadomienZewnetrznych')->find($this->userId);

        if ($user === null || ! $user->mozeCzytac()) {
            return;
        }

        $subskrypcje = $user->pushSubscriptions()->get();

        if ($subskrypcje->isEmpty()) {
            return;
        }

        $teraz = CarbonImmutable::now();
        $plan = DB::transaction(fn (): ?array => $this->zaplanuj($user, $subskrypcje->min('created_at'), $teraz));

        if ($plan === null) {
            return;
        }

        if (isset($plan['odloz'])) {
            self::dispatch($this->userId)->delay($plan['odloz']);

            return;
        }

        $tresc = (string) json_encode($plan['tresc'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subskrypcje as $subskrypcja) {
            $this->wyslijNa($transport, $subskrypcja, $tresc);
        }
    }

    /**
     * Pod blokadą doradczą odbiorcy: co czeka, czy wolno teraz, i oznaczenie
     * wysłanych JEDNYM znacznikiem czasu (po nim liczy się dzienny limit).
     *
     * @return array{odloz: CarbonImmutable}|array{tresc: array<string, string>}|null
     */
    private function zaplanuj(User $user, mixed $najstarszaSubskrypcja, CarbonImmutable $teraz): ?array
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock('.self::PRZESTRZEN_BLOKAD.', hashtext(?))',
            [(string) $user->getKey()],
        );

        // Nic sprzed włączenia pushu na pierwszym urządzeniu i nic starszego
        // niż limit wieku — push mówi o tym, co się dzieje, nie o archiwum.
        $od = $teraz->subHours((int) config('kuking.notifications.zewnetrzne.push_maks_wiek_godzin', 48));

        if ($najstarszaSubskrypcja !== null && $od->lessThan($najstarszaSubskrypcja)) {
            $od = CarbonImmutable::instance($najstarszaSubskrypcja);
        }

        $oczekujace = $user->notifications()
            ->visibleTo($user)
            ->whereIn('notifications.type', KanalPush::TYPY)
            ->whereNull('notifications.push_wyslano_at')
            ->whereNull('notifications.read_at')
            ->where('notifications.created_at', '>=', $od)
            ->with('actor.profile')
            ->reorder('notifications.created_at', 'desc')
            ->get()
            ->filter(fn (Notification $n): bool => KanalPush::dotyczy($n->type, is_array($n->data) ? $n->data : []))
            ->values();

        if ($oczekujace->isEmpty()) {
            return null;
        }

        $ustawienia = $user->ustawieniaPowiadomienZewnetrznych;

        $decyzja = Termin::rozstrzygnij(
            $teraz,
            $this->wyslaneWDobie($user, $teraz),
            null,
            $ustawienia?->cisza_od,
            $ustawienia?->cisza_do,
            $ustawienia?->dzienny_limit,
        );

        if ($decyzja['decyzja'] === Termin::KANAL_WYLACZONY) {
            return null;
        }

        if ($decyzja['decyzja'] === Termin::ODLOZ && $decyzja['wyslij_od'] !== null) {
            return ['odloz' => $decyzja['wyslij_od']];
        }

        Notification::query()
            ->whereKey($oczekujace->modelKeys())
            ->update(['push_wyslano_at' => $teraz]);

        return ['tresc' => TrescPush::zbuduj($oczekujace)];
    }

    /** Ile pushy (nie powiadomień) wyszło w bieżącej dobie odbiorcy. */
    private function wyslaneWDobie(User $user, CarbonImmutable $teraz): int
    {
        return (int) Notification::query()
            ->where('user_id', $user->getKey())
            ->where('push_wyslano_at', '>=', Termin::poczatekDoby($teraz))
            ->distinct()
            ->count('push_wyslano_at');
    }

    private function wyslijNa(TransportPush $transport, PushSubscription $subskrypcja, string $tresc): void
    {
        $wynik = $transport->wyslij($subskrypcja, $tresc);

        if ($wynik === WynikWysylkiPush::Wygasla) {
            $subskrypcja->delete();

            return;
        }

        if ($wynik === WynikWysylkiPush::Blad) {
            // Sama usługa, bez adresu subskrypcji — adres jest poświadczeniem.
            Log::warning('Web Push: nieudana wysyłka.', ['usluga' => $subskrypcja->usluga()]);
        }
    }
}
