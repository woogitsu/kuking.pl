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
 * ZNACZNIK WYSYŁKI DOPIERO PO WYSYŁCE (issue #1960). Do 26 września 2026
 * `zaplanuj()` ustawiało `push_wyslano_at` PRZED pętlą wysyłki do urządzeń —
 * czyli w chwili ZAREZERWOWANIA grupy, nie w chwili faktycznego dostarczenia.
 * Awaria transportu (`WynikWysylkiPush::Blad`) była tylko logowana, `$tries`
 * jest `1`, więc żadne ponowienie nie mogło jej podjąć — a znacznik dalej
 * twierdził „wysłano”. Dziś:
 *
 *  - `push_proba_at` (osobna kolumna) jest REZERWACJĄ grupy — ustawia ją
 *    `zaplanuj()`, pod tą samą blokadą doradczą, i TA rezerwacja jest barierą
 *    przed dublem: dopóki stoi, żadne INNE (świeżo zdarzeniowe) zadanie tego
 *    samego odbiorcy nie wybierze tej samej grupy powiadomień drugi raz;
 *  - `push_wyslano_at` ustawia WYŁĄCZNIE udana wysyłka do WSZYSTKICH
 *    urządzeń tej grupy — nigdy rezerwacja;
 *  - błąd transportu na części albo na wszystkich urządzeniach ponawia
 *    WYŁĄCZNIE dostarczenie do urządzeń, które go jeszcze nie dostały
 *    (`$pominieteSubskrypcje` rośnie o te, które już się udały) — udane
 *    urządzenie nie dostaje drugiej kopii tego samego pushu;
 *  - po `kuking.notifications.zewnetrzne.push_maks_prob_transportu` próbach
 *    transportu rezygnujemy z automatycznego ponawiania (push jest
 *    szturchnięciem, nie listem poleconym — powiadomienie w serwisie i tak
 *    czeka) i zostawiamy TRWALE puste `push_wyslano_at` z wpisem w dzienniku;
 *  - wygasła subskrypcja (404/410) jest kasowana od razu, jak dotąd —
 *    tego reguła nie zmienia.
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

    /**
     * @param  list<string>  $notificationIds  ID powiadomień z JUŻ ZDECYDOWANEJ grupy —
     *                                         puste znaczy „świeże zadanie, policz od zera"
     *                                         w `zaplanuj()`; niepuste znaczy „ponowienie
     *                                         po błędzie transportu", które pomija ciszę
     *                                         nocną i dzienny limit (już raz rozstrzygnięte).
     * @param  string|null  $tresc  Treść pushu ZAMROŻONA z pierwszej próby — ponowienie nie
     *                              przelicza jej na nowo, żeby nie zmieniło się w trakcie
     *                              (np. inna liczba zgrupowanych powiadomień).
     * @param  list<string>  $pominieteSubskrypcje  ID subskrypcji, które już dostały TĘ
     *                                              grupę — nie próbujemy ich drugi raz.
     * @param  int  $probaTransportu  Która to próba DOSTARCZENIA (nie: cisza/limit).
     */
    public function __construct(
        public string $userId,
        public array $notificationIds = [],
        public ?string $tresc = null,
        public array $pominieteSubskrypcje = [],
        public int $probaTransportu = 1,
    ) {
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

        $subskrypcje = $user->pushSubscriptions()
            ->when($this->pominieteSubskrypcje !== [], fn ($q) => $q->whereNotIn('id', $this->pominieteSubskrypcje))
            ->get();

        $ponowienie = $this->notificationIds !== [];

        if ($subskrypcje->isEmpty()) {
            // PONOWIENIE, KTÓREMU ZABRAKŁO ODBIORCÓW (rzadkie — subskrypcja
            // zniknęła między próbami): reszta już dostała tę grupę, więc
            // z punktu widzenia dostarczenia jest gotowa.
            if ($ponowienie) {
                $this->potwierdzWyslanie($this->notificationIds);
            }

            return;
        }

        if ($ponowienie) {
            $tresc = (string) $this->tresc;
        } else {
            $teraz = CarbonImmutable::now();
            $plan = DB::transaction(fn (): ?array => $this->zaplanuj($user, $subskrypcje->min('created_at'), $teraz));

            if ($plan === null) {
                return;
            }

            if (isset($plan['odloz'])) {
                self::dispatch($this->userId)->delay($plan['odloz']);

                return;
            }

            $this->notificationIds = $plan['id_powiadomien'];
            $tresc = (string) json_encode($plan['tresc'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $nieudane = [];

        foreach ($subskrypcje as $subskrypcja) {
            $wynik = $this->wyslijNa($transport, $subskrypcja, $tresc);

            if ($wynik === WynikWysylkiPush::Blad) {
                $nieudane[] = $subskrypcja;
            }
        }

        if ($nieudane === []) {
            $this->potwierdzWyslanie($this->notificationIds);

            return;
        }

        $maksProb = (int) config('kuking.notifications.zewnetrzne.push_maks_prob_transportu', 3);

        if ($this->probaTransportu >= $maksProb) {
            // TRWAŁA PORAŻKA — bez adresu subskrypcji (poświadczenie),
            // z liczbą, żeby dało się to policzyć i zauważyć trend.
            Log::error('Web Push: trwała porażka transportu — rezygnuję z ponawiania po wyczerpaniu prób.', [
                'proby' => $this->probaTransportu,
                'nieudane_urzadzenia' => count($nieudane),
                'wszystkie_urzadzenia' => $subskrypcje->count(),
            ]);

            return;
        }

        $udaneId = $subskrypcje
            ->reject(fn (PushSubscription $s): bool => in_array($s, $nieudane, true))
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $opoznienieSekund = (int) config('kuking.notifications.zewnetrzne.push_ponowienie_sekund', 30);

        self::dispatch(
            $this->userId,
            $this->notificationIds,
            $tresc,
            [...$this->pominieteSubskrypcje, ...$udaneId],
            $this->probaTransportu + 1,
        )->delay(CarbonImmutable::now()->addSeconds($opoznienieSekund));
    }

    /**
     * Pod blokadą doradczą odbiorcy: co czeka, czy wolno teraz, i REZERWACJA
     * (nie: potwierdzenie) grupy jednym znacznikiem `push_proba_at`.
     *
     * @return array{odloz: CarbonImmutable}|array{id_powiadomien: list<string>, tresc: array<string, string>}|null
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
            // Grupa już ZAREZERWOWANA (rezerwacja w toku albo trwale
            // nieudana) nie wraca do puli przez zwykłe zdarzenie — jedyna
            // droga powrotu to jawne ponowienie z `$notificationIds` wyżej.
            ->whereNull('notifications.push_proba_at')
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

        $id = $oczekujace->modelKeys();

        Notification::query()
            ->whereKey($id)
            ->update(['push_proba_at' => $teraz]);

        /** @var list<string> $id */
        return ['id_powiadomien' => array_map(strval(...), $id), 'tresc' => TrescPush::zbuduj($oczekujace)];
    }

    /** Ile pushy (nie powiadomień) wyszło w bieżącej dobie odbiorcy — liczy WYSŁANE, nie zarezerwowane. */
    private function wyslaneWDobie(User $user, CarbonImmutable $teraz): int
    {
        return (int) Notification::query()
            ->where('user_id', $user->getKey())
            ->where('push_wyslano_at', '>=', Termin::poczatekDoby($teraz))
            ->distinct()
            ->count('push_wyslano_at');
    }

    /**
     * Transport przyjął wiadomość na WSZYSTKIE urządzenia tej grupy —
     * dopiero teraz grupa jest „wysłana". `whereNull` chroni przed
     * przesunięciem znacznika, gdyby to samo zadanie (np. przez ponowienie
     * kolejki) wykonało się dwa razy.
     *
     * @param  list<string>  $notificationIds
     */
    private function potwierdzWyslanie(array $notificationIds): void
    {
        if ($notificationIds === []) {
            return;
        }

        Notification::query()
            ->whereKey($notificationIds)
            ->whereNull('push_wyslano_at')
            ->update(['push_wyslano_at' => CarbonImmutable::now()]);
    }

    private function wyslijNa(TransportPush $transport, PushSubscription $subskrypcja, string $tresc): WynikWysylkiPush
    {
        $wynik = $transport->wyslij($subskrypcja, $tresc);

        if ($wynik === WynikWysylkiPush::Wygasla) {
            $subskrypcja->delete();

            return $wynik;
        }

        if ($wynik === WynikWysylkiPush::Blad) {
            // Sama usługa, bez adresu subskrypcji — adres jest poświadczeniem.
            Log::warning('Web Push: nieudana wysyłka.', ['usluga' => $subskrypcja->usluga()]);
        }

        return $wynik;
    }
}
