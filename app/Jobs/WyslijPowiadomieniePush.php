<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notifications\Push\KanalPush;
use App\Domain\Notifications\Push\KodZamknieciaPush;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
 *  - przed ponowieniem aktualna widoczność i stan odczytu są sprawdzane
 *    ponownie; retry wysyła neutralną treść bez zapamiętanego imienia
 *    i tytułu, a odrzucone wiersze zamyka jako niewysłane (#2052);
 *  - po `kuking.notifications.zewnetrzne.push_maks_prob_transportu` próbach
 *    transportu rezygnujemy z automatycznego ponawiania (push jest
 *    szturchnięciem, nie listem poleconym — powiadomienie w serwisie i tak
 *    czeka) i zostawiamy TRWALE puste `push_wyslano_at` z wpisem w dzienniku;
 *    `push_zakonczono_at` zamyka rezerwację limitu (#1992);
 *  - KAŻDE zamknięcie niesie kod w `push_wynik` (#2053): `porazka_transportu`
 *    po wyczerpanych próbach, `anulowano` przy świadomym odrzuceniu retry.
 *    Sam dziennik nie wystarczał — zadanie kończyło się sukcesem, bez
 *    `failed_jobs`, więc czujka kolejki nie miała czego zobaczyć. Trwałą
 *    porażkę i utracone ponowienie liczy `kuking:sprawdz-push`; kod NIGDY
 *    nie wraca grupy do puli i niczego nie wysyła ponownie;
 *  - wygasła subskrypcja (404/410) jest kasowana od razu, jak dotąd —
 *    tego reguła nie zmienia.
 *
 * GRUPA MA STAŁY KOSZT, NIEZALEŻNIE OD LICZBY ZDARZEŃ (issue #2021). Do
 * 28 września 2026 `zaplanuj()` hydratowało całą pulę z 48 h (z aktorem
 * i profilem), filtrowało ją w PHP i wkładało jej UUID do `WHERE id IN`
 * oraz do payloadu każdego retry. Dziś kwalifikacja (`KanalPush::zawez()`)
 * i rezerwacja idą jednym `UPDATE` w SQL, liczbę daje sam `UPDATE`, a do
 * treści hydratuje się JEDEN — najnowszy — wiersz. Retry niesie `grupaId`
 * (`push_grupa_id`), nie listę: po tym UUID czujka #2053
 * (`StanWysylkiPush`) rozpoznaje w `jobs`, że rezerwacja jest w toku.
 * Limitu liczby wierszy w grupie celowo NIE ma: nadmiar nad limitem albo
 * wysłałby osobne pushe o tej samej zaległości (i zjadł dzienny limit),
 * albo musiałby zostać zamknięty bez pushu — obie rzeczy zmieniałyby
 * obietnicę „jeden push z tym, co czeka”. Pula i tak jest ograniczona
 * oknem `push_maks_wiek_godzin`, a zestawowy `UPDATE` nie trzyma wierszy
 * w pamięci PHP.
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
     * `push_grupa_id` zarezerwowanej grupy — niepuste znaczy „ponowienie”
     * (issue #2021). Zwykła właściwość z wartością domyślną, nie promowana
     * w konstruktorze: zadania z `jobs` sprzed wdrożenia nie mają jej
     * w serializacji, a typowana właściwość bez domyślnej zostałaby po
     * odtworzeniu niezainicjowana.
     */
    public ?string $grupaId = null;

    /**
     * @param  list<string>  $notificationIds  DAWNY format ponowienia (sprzed #2021): ID
     *                                         powiadomień zdecydowanej grupy. Nowe retry
     *                                         zostawia to puste i niesie `$grupaId`; lista
     *                                         zostaje tylko po to, żeby zadania już stojące
     *                                         w `jobs` w chwili wdrożenia dało się dokończyć.
     *                                         Oba pola puste = „świeże zadanie, policz od
     *                                         zera"; ponowienie pomija ciszę nocną i dzienny
     *                                         limit (już raz rozstrzygnięte).
     * @param  string|null  $tresc  Dawna treść pierwszej próby; retry jej nie
     *                              używa, bo po zmianie widoczności mogłaby
     *                              ujawnić imię lub tytuł przepisu (#2052).
     * @param  list<string>  $pominieteSubskrypcje  ID subskrypcji, które już dostały TĘ
     *                                              grupę — nie próbujemy ich drugi raz.
     * @param  int  $probaTransportu  Która to próba DOSTARCZENIA (nie: cisza/limit).
     * @param  string|null  $grupaId  `push_grupa_id` grupy, którą to retry dokańcza.
     */
    public function __construct(
        public string $userId,
        public array $notificationIds = [],
        public ?string $tresc = null,
        public array $pominieteSubskrypcje = [],
        public int $probaTransportu = 1,
        ?string $grupaId = null,
    ) {
        $this->grupaId = $grupaId;
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
        $ponowienie = $this->grupaId !== null || $this->notificationIds !== [];

        if ($user === null) {
            return;
        }

        if (! $user->mozeCzytac()) {
            if ($ponowienie) {
                DB::transaction(function () use ($user): void {
                    $this->zablokujOdbiorce($user);
                    $this->zakonczProby($user, KodZamknieciaPush::Anulowano);
                });
            }

            return;
        }

        $subskrypcje = $user->pushSubscriptions()
            ->when($this->pominieteSubskrypcje !== [], fn ($q) => $q->whereNotIn('id', $this->pominieteSubskrypcje))
            ->get();

        if ($ponowienie && ! DB::transaction(fn (): bool => $this->kwalifikujPonowienie($user))) {
            return;
        }

        if ($subskrypcje->isEmpty()) {
            // PONOWIENIE, KTÓREMU ZABRAKŁO ODBIORCÓW (rzadkie — subskrypcja
            // zniknęła między próbami): reszta już dostała tę grupę, więc
            // z punktu widzenia dostarczenia jest gotowa.
            if ($ponowienie) {
                $this->potwierdzWyslanie($user);
            }

            return;
        }

        if ($ponowienie) {
            // Stan może zmienić się także po ponownym odczycie, przed I/O.
            // Retry niesie tylko neutralne szturchnięcie, bez starego imienia
            // aktora i tytułu przepisu z pierwszej próby.
            $tresc = (string) json_encode(TrescPush::neutralna(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

            $this->grupaId = $plan['grupa_id'];
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
            $this->potwierdzWyslanie($user);

            return;
        }

        $maksProb = (int) config('kuking.notifications.zewnetrzne.push_maks_prob_transportu', 3);

        if ($this->probaTransportu >= $maksProb) {
            $this->zakonczProby($user, KodZamknieciaPush::PorazkaTransportu);
            // TRWAŁA PORAŻKA — bez adresu subskrypcji (poświadczenie),
            // z liczbą, żeby dało się to policzyć i zauważyć trend.
            Log::error('Web Push: trwała porażka transportu — rezygnuję z ponawiania po wyczerpaniu prób.', [
                'proby' => $this->probaTransportu,
                'nieudane_urzadzenia' => count($nieudane),
                'wszystkie_urzadzenia' => $subskrypcje->count(),
                // Urządzenia, które dostały tę grupę we wcześniejszej próbie —
                // w retry NIE ma ich w `$subskrypcje`, więc „nieudane =
                // wszystkie” nie znaczy „nikt nie dostał”. Runbook #2053
                // zakazuje ręcznego ponowienia, gdy to pole jest > 0.
                'juz_obsluzone' => count($this->pominieteSubskrypcje),
            ]);

            return;
        }

        $udaneId = $subskrypcje
            ->reject(fn (PushSubscription $s): bool => in_array($s, $nieudane, true))
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $opoznienieSekund = (int) config('kuking.notifications.zewnetrzne.push_ponowienie_sekund', 30);

        // Bez treści: retry i tak wysyła neutralną (#2052), a stara treść
        // w `jobs` to tylko imię aktora i tytuł przepisu w kolejce.
        self::dispatch(
            $this->userId,
            $this->grupaId !== null ? [] : $this->notificationIds,
            null,
            [...$this->pominieteSubskrypcje, ...$udaneId],
            $this->probaTransportu + 1,
            $this->grupaId,
        )->delay(CarbonImmutable::now()->addSeconds($opoznienieSekund));
    }

    /**
     * Pod blokadą odbiorcy ponownie sprawdza aktualne powiadomienia grupy.
     * Odpadające zamyka, żeby nie udawały wysłanych ani nie wisiały wiecznie.
     * Wszystko w SQL, bez hydratacji grupy (#2021).
     *
     * @return bool czy w grupie zostało coś do dostarczenia
     */
    private function kwalifikujPonowienie(User $user): bool
    {
        $this->zablokujOdbiorce($user);

        $zarezerwowano = $this->otwartaGrupa($user)->min('push_proba_at');

        if ($zarezerwowano === null) {
            return false;
        }

        if (CarbonImmutable::parse($zarezerwowano)->lessThan(CarbonImmutable::now()->subHours(48))) {
            $this->zakonczProby($user, KodZamknieciaPush::Anulowano);

            return false;
        }

        $this->otwartaGrupa($user)
            ->whereNotExists($this->kwalifikujeTenWiersz($user))
            ->update(['push_zakonczono_at' => CarbonImmutable::now(), 'push_wynik' => KodZamknieciaPush::Anulowano->value]);

        return $this->otwartaGrupa($user)->exists();
    }

    /**
     * Wiersze tej grupy, które wciąż czekają na dostarczenie. Grupa to
     * `push_grupa_id` albo — w dawnym retry — lista ID; zawsze tylko
     * wiersze TEGO odbiorcy, choćby payload niósł cudze ID.
     *
     * @return Builder<Notification>
     */
    private function otwartaGrupa(User $user): Builder
    {
        $query = Notification::query()->where('user_id', $user->getKey());

        if ($this->grupaId !== null) {
            // Zepsuty UUID nie może zamienić się w `IS NULL` (a więc
            // w każdą dawną rezerwację bez grupy) — wtedy grupa jest pusta.
            Str::isUuid($this->grupaId)
                ? $query->where('push_grupa_id', $this->grupaId)
                : $query->whereRaw('false');
        } else {
            $id = array_values(array_filter($this->notificationIds, static fn (mixed $id): bool => Str::isUuid($id)));
            $query->whereKey($id);
        }

        return $query->whereNotNull('push_proba_at')
            ->whereNull('push_wyslano_at')
            ->whereNull('push_zakonczono_at');
    }

    /**
     * Powiadomienia odbiorcy, które w tej chwili WOLNO wysłać pushem:
     * widoczne, nieprzeczytane, z kanału push (`KanalPush::zawez()`).
     *
     * @return Builder<Notification>
     */
    private function kwalifikujace(User $user): Builder
    {
        return KanalPush::zawez(
            Notification::query()
                ->where('notifications.user_id', $user->getKey())
                ->visibleTo($user)
                ->whereNull('notifications.read_at'),
        );
    }

    /**
     * Te same warunki co `kwalifikujace()`, ale jako `SELECT 1 WHERE …` BEZ
     * `FROM`: kolumny `notifications.*` wskazują wtedy na wiersz grupy
     * z zewnętrznego `UPDATE`, więc warunek liczy się dla każdego wiersza
     * na miejscu, bez drugiego skanu puli (przegląd PR #2160).
     *
     * `NOT IN (SELECT id FROM notifications …)` robiło ten drugi skan
     * z pełnym filtrem widoczności; na 3001 wierszach w puli 223 tys.
     * szacowany koszt 1,74 mln przekraczał `jit_above_cost`, a sama
     * kompilacja JIT trwała 2,4 s (`docs/infra/WEB_PUSH_PLANY_2021.md`).
     * `NOT EXISTS` ma też właściwą semantykę NULL: warunek, który dla
     * wiersza daje NULL (np. brak `question_answer`), nie kwalifikuje go,
     * więc wiersz zostaje zamknięty — tak jak `dotyczy()` w PHP.
     */
    private function kwalifikujeTenWiersz(User $user): QueryBuilder
    {
        $warunki = $this->kwalifikujace($user)->toBase();

        return DB::query()->selectRaw('1')
            ->mergeWheres($warunki->wheres, $warunki->getRawBindings()['where']);
    }

    private function zablokujOdbiorce(User $user): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock('.self::PRZESTRZEN_BLOKAD.', hashtext(?))',
            [(string) $user->getKey()],
        );
    }

    /**
     * Pod blokadą doradczą odbiorcy: co czeka, czy wolno teraz, i REZERWACJA
     * (nie: potwierdzenie) grupy jednym znacznikiem `push_proba_at`.
     *
     * @return array{odloz: CarbonImmutable}|array{grupa_id: string, tresc: array<string, string>}|null
     */
    private function zaplanuj(User $user, mixed $najstarszaSubskrypcja, CarbonImmutable $teraz): ?array
    {
        $this->zablokujOdbiorce($user);

        // Nic sprzed włączenia pushu na pierwszym urządzeniu i nic starszego
        // niż limit wieku — push mówi o tym, co się dzieje, nie o archiwum.
        $od = $teraz->subHours((int) config('kuking.notifications.zewnetrzne.push_maks_wiek_godzin', 48));

        if ($najstarszaSubskrypcja !== null && $od->lessThan($najstarszaSubskrypcja)) {
            $od = CarbonImmutable::instance($najstarszaSubskrypcja);
        }

        $pula = $this->kwalifikujace($user)
            ->whereNull('notifications.push_wyslano_at')
            // Grupa już ZAREZERWOWANA (rezerwacja w toku albo trwale
            // nieudana) nie wraca do puli przez zwykłe zdarzenie — jedyna
            // droga powrotu to jawne ponowienie z `$grupaId` wyżej.
            ->whereNull('notifications.push_proba_at')
            ->where('notifications.created_at', '>=', $od);

        if (! (clone $pula)->exists()) {
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

        // Rezerwacja zestawowo: podzapytanie zamiast listy UUID w `IN (...)`,
        // a liczba zarezerwowanych wierszy to wynik samego `UPDATE`.
        $grupaId = (string) Str::uuid();
        $ile = Notification::query()
            ->whereIn('id', $pula->select('notifications.id'))
            ->update([
                'push_proba_at' => $teraz,
                'push_grupa_id' => $grupaId,
            ]);

        if ($ile === 0) {
            return null;
        }

        // Do treści wystarczy najnowsze zdarzenie i liczba reszty.
        $najnowsze = Notification::query()
            ->where('user_id', $user->getKey())
            ->where('push_grupa_id', $grupaId)
            ->with('actor.profile')
            ->reorder('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return ['grupa_id' => $grupaId, 'tresc' => TrescPush::zGrupy($najnowsze, $ile)];
    }

    /**
     * Ile slotów zajmują grupy odbiorcy. Rezerwacja musi być widoczna także
     * drugiemu workerowi, zanim pierwszy transport zakończy żądanie sieciowe.
     * Aktywną rezerwację z wczoraj liczymy dziś; retry nie może wyprzedzić
     * dzisiejszego limitu. Po 48 h stare retry jest odrzucane wyżej.
     * Zakończona porażka zajmuje slot do końca doby zakończenia, potem zwalnia.
     */
    private function wyslaneWDobie(User $user, CarbonImmutable $teraz): int
    {
        $poczatek = Termin::poczatekDoby($teraz);

        $wlicz = static function (Builder $query) use ($poczatek, $teraz): void {
            $query->where('push_proba_at', '>=', $poczatek)
                ->orWhere('push_wyslano_at', '>=', $poczatek)
                ->orWhere('push_zakonczono_at', '>=', $poczatek)
                ->orWhere(function (Builder $aktywne) use ($teraz): void {
                    $aktywne->whereNull('push_wyslano_at')
                        ->whereNull('push_zakonczono_at')
                        ->where('push_proba_at', '>=', $teraz->subHours(48));
                });
        };

        $nowe = (int) Notification::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('push_grupa_id')
            ->where($wlicz)
            ->distinct()
            ->count('push_grupa_id');

        // Stare wiersze nie mają UUID grupy. Liczymy je każdy osobno:
        // to może odłożyć push za długo, ale dwa niezależne transporty
        // z tym samym znacznikiem czasu nie obniżą rachunku limitu.
        $stare = (int) Notification::query()
            ->where('user_id', $user->getKey())
            ->whereNull('push_grupa_id')
            ->where($wlicz)
            ->count();

        return $nowe + $stare;
    }

    /**
     * Zakończona grupa nie wraca do puli, ale przestaje być aktywnym slotem.
     * Kod mówi czujce, czy to awaria, czy świadome anulowanie (#2053).
     */
    private function zakonczProby(User $user, KodZamknieciaPush $kod): void
    {
        $this->otwartaGrupa($user)
            ->update(['push_zakonczono_at' => CarbonImmutable::now(), 'push_wynik' => $kod->value]);
    }

    /**
     * Transport przyjął wiadomość na WSZYSTKIE urządzenia tej grupy —
     * dopiero teraz grupa jest „wysłana". `whereNull` chroni przed
     * przesunięciem znacznika, gdyby to samo zadanie (np. przez ponowienie
     * kolejki) wykonało się dwa razy. Wiersz zamknięty w ponowieniu
     * (`push_zakonczono_at`, np. przeczytany) nie staje się „wysłanym”.
     */
    private function potwierdzWyslanie(User $user): void
    {
        $this->otwartaGrupa($user)->update(['push_wyslano_at' => CarbonImmutable::now()]);
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
