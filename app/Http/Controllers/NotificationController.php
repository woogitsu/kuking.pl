<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Notifications\QuestionNotificationContext;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request, QuestionNotificationContext $questionContext): View
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

        $this->sprawdzWykonania($notifications->items());

        return view('pages.notifications', [
            'notifications' => $notifications,
            // ISSUE #1402: „Oznacz wszystkie" tylko wtedy, gdy JEST co
            // oznaczyć — liczone na tym samym zbiorze co lista i licznik
            // (`visibleTo`), po wszystkich stronach, nie po bieżącej.
            // Nieprzeczytane na tej stronie rozstrzyga bez zapytania;
            // dopiero strona samych przeczytanych pyta o resztę.
            'saNieprzeczytane' => collect($notifications->items())->contains(fn (Notification $n): bool => $n->read_at === null)
                || $user->unreadNotificationsCount() > 0,
            'questionTitles' => $questionContext->titles($notifications->items(), $user),
            'destinationUrls' => Notification::destinationUrls($notifications->items(), $user),
            'decyzjeModeracyjne' => $this->decyzje($notifications->items()),
            // ISSUE #758 / D-229: wycinek komentarza liczy się z AKTUALNEJ
            // treści, przy wyświetlaniu — i tak samo jak decyzje wyżej idzie
            // JEDNYM zapytaniem na całą stronę, a nie jednym na wiersz.
            'wycinkiKomentarzy' => Notification::zyweWycinkiKomentarzy($notifications->items()),
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
     * ZGŁOSZENIE IDZIE RAZEM Z DECYZJĄ, I TO NIE JEST OZDOBA.
     * Jedno zapytanie na wiersze decyzji nie wystarczało, bo N+1 wracał
     * o jedną warstwę niżej: `UzasadnienieDecyzji::skadSprawa()` pyta
     * `$decyzja->report?->wykrylAutomat()` oraz `$decyzja->report?->reason`,
     * żeby napisać prawdę wymaganą przez DSA art. 17 ust. 3 lit. b i c —
     * czy sprawę zaczęło czyjeś zgłoszenie, czy wskazał ją automat, i który.
     * Bez `with('report')` każde takie powiadomienie dokładało własne
     * `select * from reports where id = ?`.
     *
     * Zmierzone przed poprawką (`PowiadomieniaBezWachlarzaZapytanTest`):
     * 9 zapytań przy 2 powiadomieniach moderacyjnych i 27 przy 20 — czyli
     * dokładnie jedno na powiadomienie. Strona mieści ich 30.
     *
     * Bez zawężania kolumn (`report:id,...`): `wykrylAutomat()` czyta
     * `source`, zdanie o narzędziu czyta `reason`, a następna wersja tego
     * uzasadnienia sięgnie po kolejną kolumnę — a kolumna spoza listy wraca
     * jako `null` bez błędu i bez śladu (issue #368). Cicho przekłamane
     * uzasadnienie decyzji moderacyjnej to nie jest cena za jedną kolumnę
     * mniej w selekcie.
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
            ->with('report')
            ->whereIn('id', array_keys($identyfikatory))
            ->get()
            ->keyBy(fn (ModerationAction $decyzja): string => (string) $decyzja->getKey());
    }

    /**
     * Czy wykonania z powiadomień o ugotowaniu na tej stronie jeszcze
     * istnieją (issue #771) — JEDNYM zapytaniem, z tego samego powodu co
     * `decyzje()` wyżej: bez tego każde takie powiadomienie pytałoby
     * o swoje wykonanie osobno, w widoku, dwa razy.
     *
     * @param  list<Notification>  $powiadomienia
     */
    private function sprawdzWykonania(array $powiadomienia): void
    {
        $doSprawdzenia = [];

        foreach ($powiadomienia as $powiadomienie) {
            $id = $powiadomienie->data['cooked_event_id'] ?? null;

            if ($powiadomienie->type === Notification::TYPE_COOKED && is_string($id) && Str::isUuid($id)) {
                $doSprawdzenia[$id][] = $powiadomienie;
            }
        }

        if ($doSprawdzenia === []) {
            return;
        }

        $istniejace = CookedEvent::query()
            ->whereIn('id', array_keys($doSprawdzenia))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->flip();

        foreach ($doSprawdzenia as $id => $grupa) {
            foreach ($grupa as $powiadomienie) {
                $powiadomienie->zapamietajIstnienieWykonania($istniejace->has($id));
            }
        }
    }

    /**
     * Oznaczenie wszystkiego jako przeczytane jest JAWNYM kliknięciem,
     * nie efektem ubocznym wejścia na stronę. Osoba, która przypadkiem
     * weszła w powiadomienia, nie może stracić informacji o tym, że
     * ktoś ugotował z jej przepisu.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $user = $request->user();

        // `visibleTo()` — TEN SAM zbiór co lista i licznik (issue #969, #1401).
        // Bez niego przycisk gasił też powiadomienia ukryte blokadą albo
        // statusem sprawcy; po odblokowaniu wracały jako przeczytane,
        // choć człowiek nigdy ich nie zobaczył.
        $oznaczono = $user->notifications()->visibleTo($user)->whereNull('read_at')->update(['read_at' => now()]);

        // ISSUE #1402: komunikat mówi o tym, co się NAPRAWDĘ stało. Zero
        // zmienionych wierszy (np. druga karta zdążyła wcześniej) to nie
        // „oznaczone" — to informacja, że nie było czego oznaczać.
        if ($oznaczono === 0) {
            return back()->with('status', 'Nie było nic do oznaczenia — wszystkie powiadomienia są już przeczytane.');
        }

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
     *
     * ISSUE #276 — POWIADOMIENIE BEZ CELU (`adresDocelowy() === null`)
     * Ta metoda od początku radziła sobie z brakiem adresu: oznacza
     * `read_at`, po czym `return back()` niżej po prostu zostaje na tej
     * samej stronie. Dziurą nie był ten kod — była nim strona: widok
     * pokazywał formularz „Zobacz" tylko wtedy, gdy `adresDocelowy()`
     * zwracał coś niepuste, więc dla powiadomień typu „Sprawdziliśmy Twoje
     * odwołanie. Cofamy decyzję." przycisk w ogóle nie istniał i ta gałąź
     * `back()` była martwa. Widok teraz pokazuje dla nich ten sam formularz
     * z napisem „Oznacz jako przeczytane" zamiast „Zobacz" — ta metoda się
     * nie zmieniła, bo nie musiała.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        // WŁAŚCICIELSTWO EGZEKWUJE ZAPYTANIE, NIE SAM IDENTYFIKATOR.
        // AGENTS.md §7: UUID w adresie to nie autoryzacja. Szukamy wyłącznie
        // wśród powiadomień TEJ osoby, więc cudzy identyfikator nie wybierze
        // żadnego wiersza i kończy się na 404 — bez ujawnienia, czy taki
        // wiersz w ogóle istnieje.
        //
        // `visibleTo()` — ta sama granica co lista (issue #1351): wiersz
        // schowany na liście nie otwiera się też wprost po identyfikatorze.
        $powiadomienie = $request->user()
            ->notifications()
            ->visibleTo($request->user())
            ->whereKey($notification)
            ->first();

        // ISSUE #759: komentarz mógł zniknąć (usunięty, ukryty przez
        // moderację, treść nad nim schowana) między wyświetleniem listy
        // a kliknięciem. Zamiast gołego 404 — zdanie, co się stało, bez
        // żadnego fragmentu komentarza. Wiersz ukryty z INNEGO powodu
        // (blokada, zbanowany sprawca, #1351) dalej kończy się na 404:
        // `visibleTo(..., false)` pomija tylko warunek dostępności treści.
        if ($powiadomienie === null) {
            $bezTresci = $request->user()
                ->notifications()
                ->visibleTo($request->user(), false)
                ->whereKey($notification)
                ->whereIn('type', Notification::TYPY_Z_WYCINKIEM_KOMENTARZA)
                ->exists();

            abort_unless($bezTresci, 404);

            return back()->with('status', 'Tego komentarza już nie ma albo nie jest już dostępny. Wróć do listy powiadomień.');
        }

        // TYLKO GDY NIEPRZECZYTANE — I ROZSTRZYGA TO BAZA, NIE PHP (D-079).
        //
        // Powtórne kliknięcie nie ma prawa przesuwać znacznika w przód: od
        // `read_at` liczy się retencja (`PrzedawnionePowiadomienia`, trzy
        // miesiące), więc każde odświeżenie znacznika przedłużałoby życie
        // danych, których polityka prywatności obiecuje ludziom nie trzymać.
        //
        // CO BYŁO ZŁAMANE (issue #276, druga warstwa — nie sama dziura
        // w widoku). Stało tu `if ($powiadomienie->isUnread()) { save(); }`,
        // czyli SPRAWDZENIE W PHP na wierszu odczytanym zapytaniem wyżej,
        // a potem osobny zapis `update … where id = ?`. Między odczytem
        // a zapisem jest okno, w którym stan tego wiersza może się zmienić —
        // i zmienia się realnie, bo to jest przycisk, w który człowiek klika
        // dwa razy pod rząd, kiedy strona myśli (zgłoszenie z 8 września
        // brzmiało wprost: „Znowu wchodzę, patrzę i nic"). Przeplot:
        //
        //   1. żądanie A czyta wiersz — `read_at` puste;
        //   2. żądanie B (drugie kliknięcie, „oznacz wszystkie" w innej
        //      karcie, `markAllRead()`) ustawia `read_at` na swój czas;
        //   3. żądanie A widzi w PAMIĘCI dalej puste `read_at`, więc zapisuje
        //      `now()` — i przesuwa znacznik w przód, czyli robi dokładnie
        //      to, czego ten warunek miał zabronić.
        //
        // `docs/PULAPKI_TESTOW.md` nazywa to jednym rodzajem błędu ze
        // wszystkich ośmiu P1 z audytu: „inwariant sprawdzany, a potem
        // wykonywany, zamiast wykonany atomowo". D-079 rozstrzyga to wprost —
        // gwarancję daje ograniczenie albo blokada, nie `exists()` w PHP.
        //
        // DLACZEGO JEDNO ZDANIE SQL, A NIE `lockForUpdate()` W TRANSAKCJI.
        // Blokada służy tam, gdzie pod nią trzeba PODJĄĆ DECYZJĘ i dopisać
        // więcej niż jeden wiersz (`ZamekKonta`, `ModerationController::decide()`).
        // Tutaj decyzja jest jednym warunkiem na jednej kolumnie jednego
        // wiersza, więc warunek wchodzi do `WHERE` samego zapisu: PostgreSQL
        // bierze wtedy blokadę wiersza sam i sam ponownie sprawdza warunek po
        // jej zwolnieniu (`read_at IS NULL` przestaje pasować). Spóźnione
        // żądanie trafia na zero zmienionych wierszy i nie ma czego przesunąć.
        // Rewalidacji „pod blokadą" nie da się tu więc pominąć — ona JEST tym
        // zapisem, a nie osobnym krokiem, który da się kiedyś skasować.
        //
        // WŁAŚCICIELSTWO STOI W TYM SAMYM ZDANIU. Zapis idzie przez relację
        // `notifications()`, czyli `WHERE user_id = <ta osoba>` — cudzy wiersz
        // nie wejdzie do `UPDATE`, nawet gdyby ktoś kiedyś rozluźnił `firstOrFail()`
        // wyżej. AGENTS.md §7: UUID w adresie to nie autoryzacja.
        $oznaczono = $request->user()
            ->notifications()
            ->whereKey($powiadomienie->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // ISSUE #771: wykonanie mogło zniknąć między wyświetleniem listy
        // a kliknięciem. Zamiast 404 — zdanie, co się stało.
        if ($powiadomienie->wykonanieUsuniete()) {
            return back()->with('status', 'To ugotowanie zostało usunięte.');
        }

        $cel = $powiadomienie->adresDocelowy();

        // ODESŁANIE TYLKO W OBRĘBIE SERWISU (issue #733) — patrz `adresWewnetrzny()`.
        $cel = $cel === null ? null : $this->adresWewnetrzny($cel);

        if ($cel === null) {
            return back();
        }

        $odeslanie = redirect()->to($cel);

        // ISSUE #770: PIERWSZE „Zobacz" przy ugotowaniu ma pokazać ekran
        // „Komuś wyszło". `celebrate()` rozpoznaje „już pokazano" po `read_at`,
        // a ten właśnie ustawiliśmy wyżej — bez tej informacji pierwsze
        // kliknięcie lądowało od razu na zwykłym wpisie. Nie cofamy `read_at`
        // (od niego liczy się retencja, D-079), tylko przekazujemy jednemu
        // następnemu żądaniu, że to TO kliknięcie zgasiło powiadomienie.
        // `$oznaczono === 1` rozstrzyga baza, więc drugie kliknięcie (albo
        // wcześniejsze „oznacz wszystkie") celebracji nie powtórzy.
        if ($oznaczono === 1 && $powiadomienie->type === Notification::TYPE_COOKED) {
            $odeslanie->with(Notification::SESJA_PIERWSZE_OTWARCIE, (string) $powiadomienie->getKey());
        }

        return $odeslanie;
    }

    /**
     * Adres wewnątrz serwisu, pod który wolno odesłać — albo `null` (issue #733).
     *
     * Adres dla typów spoza `match` w `adresDocelowy()` bierze się
     * z `data['url']`, czyli z wiersza w bazie. Dziś wpisuje go wyłącznie
     * nasz kod, ale przekierowanie pod dowolny adres z bazy to gotowe
     * otwarte przekierowanie na przyszłość.
     *
     * CO PRZEPUSZCZAŁ POPRZEDNI WARUNEK („zaczyna się od `/`, ale nie od `//`"
     * albo „host równy hostowi aplikacji"): `/\obcy.invalid` (przeglądarki
     * czytają `\` jak `/`), `https://kuking.pl//obcy.invalid` (po
     * wyjęciu ścieżki `//obcy` to adres bez schematu), inny port tego
     * samego hosta, `user@host`.
     *
     * JEDNO KRYTERIUM: wynikiem jest zawsze ŚCIEŻKA od jednego ukośnika,
     * którą `redirect()->to()` dokleja do adresu aplikacji. Adres pełny
     * przechodzi tylko z hostem aplikacji, schematem http(s), bez danych
     * logowania i bez obcego portu — i też jest sprowadzany do ścieżki.
     * Dzięki temu stary `http://` z tym samym hostem (nie ma na to decyzji,
     * żeby go odrzucać) i tak kończy się na originie aplikacji, a nie
     * na tym, który stoi w bazie.
     */
    private function adresWewnetrzny(string $adres): ?string
    {
        // Znaki sterujące i białe (przeglądarka po cichu wycina tabulator
        // i nową linię, więc `/\t/obcy` staje się `//obcy`) oraz backslash.
        if ($adres === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $adres) === 1) {
            return null;
        }

        $czesci = parse_url($adres);

        if ($czesci === false) {
            return null;
        }

        if (isset($czesci['scheme']) || isset($czesci['host'])) {
            $schemat = strtolower($czesci['scheme'] ?? '');
            $aplikacja = parse_url((string) config('app.url'));
            $hostAplikacji = strtolower((string) ($aplikacja['host'] ?? ''));

            if (! in_array($schemat, ['http', 'https'], true)
                || isset($czesci['user'])
                || isset($czesci['pass'])
                || $hostAplikacji === ''
                || strtolower((string) ($czesci['host'] ?? '')) !== $hostAplikacji) {
                return null;
            }

            if (isset($czesci['port'])) {
                $portAplikacji = $aplikacja['port']
                    ?? (strtolower((string) ($aplikacja['scheme'] ?? 'https')) === 'http' ? 80 : 443);

                if ($czesci['port'] !== $portAplikacji) {
                    return null;
                }
            }

            $adres = ($czesci['path'] ?? '') === '' ? '/' : $czesci['path'];
            $adres .= isset($czesci['query']) ? '?'.$czesci['query'] : '';
            $adres .= isset($czesci['fragment']) ? '#'.$czesci['fragment'] : '';
        }

        if (! str_starts_with($adres, '/') || str_starts_with($adres, '//')) {
            return null;
        }

        return $adres;
    }
}
