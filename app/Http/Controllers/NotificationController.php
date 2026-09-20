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
        $powiadomienie = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();

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
        //
        // WYJĄTEK: TYPE_COOKED Z ISTNIEJĄCYM CELEM CZEKA Z OZNACZENIEM NA
        // `CookedEventController::celebrate()` — patrz uzasadnienie niżej,
        // przy liczeniu `$oznaczTutaj`. Rdzeń zapisu (jedno zdanie SQL,
        // `WHERE read_at IS NULL`, bez sprawdzenia w PHP) jest identyczny
        // w obu miejscach.
        $cel = $powiadomienie->adresDocelowy();

        // WYŚCIG MIĘDZY WYŚWIETLENIEM LISTY A TYM KLIKNIĘCIEM (issue #771).
        //
        // `Notification::scopeVisibleTo()` już ukrywa z LISTY powiadomienia
        // o ugotowaniu/zapisie, których cel zniknął — ale to filtr przy
        // ODCZYCIE strony, sprzed chwili. Cel mógł zniknąć MIĘDZY tamtym
        // odczytem a tym POST-em (usunięcie wykonania albo przepisu w tym
        // oknie, druga karta przeglądarki, stary link z powiadomienia
        // e-mail). Bez tej kontroli `redirect()->to($cel)` niżej prowadziłby
        // do route model bindingu, który i tak skończyłby 404 — tyle że
        // brzydko, zamiast tym samym grzecznym „zostań na miejscu", co
        // powiadomienie bez celu ma od issue #276.
        //
        // JEDNO zapytanie na TĘ JEDNĄ notatkę, nie na całą stronę — inaczej
        // niż `scopeVisibleTo()`, to nie jest N+1: `open()` obsługuje zawsze
        // dokładnie jeden wiersz.
        if ($cel !== null && ! $powiadomienie->celIstniejeNadal()) {
            $cel = null;
        }

        /*
         * ISSUE #770 — PIERWSZE „ZOBACZ" NIE MA PRAWA OMINĄĆ CELEBRACJI.
         *
         * `CookedEventController::celebrate()` traktuje `read_at` jako dowód
         * „ekran już był pokazany" (patrz jego własny komentarz) i na tej
         * podstawie odsyła DALEJ, do zwykłego `cooked.show`, zamiast pokazać
         * „Komuś wyszło". Ten kontroler, oznaczając powiadomienie jako
         * przeczytane TU, ZANIM celebracja w ogóle się wyświetliła,
         * podsuwał `celebrate()` fałszywy dowód — pierwsze kliknięcie
         * „Zobacz" z listy powiadomień lądowało od razu na zwykłym wpisie.
         *
         * DLA TYPE_COOKED Z ISTNIEJĄCYM CELEM znacznik zostaje więc
         * NIEUSTAWIONY tutaj — ustawi go `celebrate()` w chwili, gdy
         * ekran NAPRAWDĘ się pokaże, dokładnie tak jak przy bezpośrednim
         * wejściu z e-maila czy zakładki. To nie łamie D-079 („nie
         * przesuwaj `read_at` w przód przy powtórce"): tu nic jeszcze nie
         * jest ustawione, więc nie ma czego przesuwać — a `celebrate()` ma
         * własną, atomową wersję tego samego zapisu.
         *
         * POZOSTAŁE PRZYPADKI (cel zniknął, inny typ powiadomienia) NIE
         * mają żadnego drugiego ekranu, który mógłby to później oznaczyć —
         * dla nich znacznik stoi TUTAJ, jak dotychczas.
         */
        $oznaczTutaj = ! ($powiadomienie->type === Notification::TYPE_COOKED && $cel !== null);

        if ($oznaczTutaj) {
            $request->user()
                ->notifications()
                ->whereKey($powiadomienie->getKey())
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

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

    /**
     * Czy adres prowadzi do tego serwisu, a nie na zewnątrz (issue #733).
     *
     * CO BYŁO ZA SŁABE. Pierwsza gałąź przyjmowała każdy napis zaczynający
     * się od pojedynczego `/` — w tym `/\example.invalid/proba`. Przeglądarki
     * (i parser WHATWG URL) traktują odwrócony ukośnik w adresie
     * względnym jak zwykły `/`, więc taki napis w nagłówku `Location`
     * wychodzi jako adres DO INNEGO HOSTA, nie ścieżka w tym serwisie.
     * Druga gałąź porównywała wyłącznie `PHP_URL_HOST`, pomijając schemat
     * i port — `https://kuking.pl:444/x` albo `http://kuking.pl/x` przy
     * HTTPS w `app.url` przechodziły, choć mają inne origin.
     *
     * KRYTERIUM PO POPRAWCE: znormalizowana ścieżka względna (pojedynczy
     * `/`, bez odwróconego ukośnika i bez znaków sterujących) ALBO adres
     * HTTP(S) o dokładnie tym samym schemacie, hoście i efektywnym porcie
     * co `config('app.url')` — ten sam pomysł co
     * `App\Support\LinkiWTekscie::wewnetrzny()`, tylko dopuszczający też
     * adresy względne, bo `data['url']` w tej tabeli bywa jednym i drugim.
     * `userinfo` (`user:pass@host`) jest zawsze odrzucane — taki adres nie
     * jest ani ścieżką, ani czystym adresem do naszego hosta.
     */
    private function wlasnyAdres(string $adres): bool
    {
        if ($adres === '' || str_contains($adres, '\\') || preg_match('/[\x00-\x1F\x7F]/', $adres) === 1) {
            return false;
        }

        $cel = parse_url($adres);

        if ($cel === false || isset($cel['user']) || isset($cel['pass'])) {
            return false;
        }

        if (! isset($cel['scheme']) && ! isset($cel['host'])) {
            return isset($cel['path'])
                && str_starts_with($cel['path'], '/')
                && ! str_starts_with($cel['path'], '//');
        }

        $aplikacja = parse_url((string) config('app.url'));

        if ($aplikacja === false || ! isset($cel['scheme'], $cel['host'], $aplikacja['scheme'], $aplikacja['host'])) {
            return false;
        }

        $port = static fn (array $url): int => $url['port'] ?? (strtolower($url['scheme']) === 'https' ? 443 : 80);

        return in_array(strtolower($cel['scheme']), ['http', 'https'], true)
            && strtolower($cel['scheme']) === strtolower($aplikacja['scheme'])
            && strtolower($cel['host']) === strtolower($aplikacja['host'])
            && $port($cel) === $port($aplikacja);
    }
}
