<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\ListaKont;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListaKontRequest;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Konta użytkowników — panel moderacji.
 *
 * PO CO TO POWSTAŁO
 * Pytanie właściciela brzmiało wprost: „gdzie będę mógł zarządzać
 * użytkownikami (lista użytkowników, data rejestracji itp.)". Do tej pory
 * jedyną drogą do odpowiedzi na „kim jest ta osoba, od kiedy tu jest i czy
 * już coś z nią było" był `psql`. Moderator, który przy zgłoszeniu musi
 * wiedzieć, czy pisze do kogoś, kto założył konto wczoraj, czy do kogoś, kto
 * gotuje z nami od pół roku, nie ma jak tego sprawdzić z przeglądarki.
 *
 * GDZIE CO LEŻY (issue #970, granica z docs/ARCHITECTURE.md)
 *  * wejście z adresu — filtry, sortowanie, normalizacja frazy:
 *    `App\Http\Requests\Admin\ListaKontRequest`;
 *  * zapytania — lista, liczniki zakładek, historia decyzji:
 *    `App\Domain\Moderation\ListaKont`;
 *  * ten kontroler — autoryzacja, wpis do dziennika przy karcie konta
 *    i złożenie odpowiedzi.
 * Poniższe decyzje dotyczą całego ekranu, więc zostają w jednym miejscu.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  SKALA: TYSIĄCE KONT, NIE DWADZIEŚCIA
 * ══════════════════════════════════════════════════════════════════════════
 *
 * D-012 („zamknięta alfa, ~20 osób") przy TYM ekranie już nie obowiązuje:
 * właściciel zapowiada przejście grupy użytkowniczek z Garnek.pl, czyli setki,
 * a potem tysiące kont. To zmienia trzy rzeczy i wszystkie są tu widoczne:
 *
 *  1. STRONICOWANIE I WYSZUKIWANIE SĄ WARUNKIEM DZIAŁANIA, nie wygodą.
 *     Nic tu nie ładuje całej tabeli — ani lista, ani liczniki przy filtrach.
 *
 *  2. ZERO N+1. Profil idzie przez `with()`, liczba wpisów przez `withCount()`
 *     (podzapytanie w tym samym `SELECT`), liczniki filtrów jednym `GROUP BY`.
 *     Liczba zapytań tej strony NIE ROŚNIE z liczbą kont i pilnuje tego test
 *     (`PanelUzytkownicyTest::test_liczba_zapytan_nie_rosnie_z_liczba_kont`) —
 *     nie zdanie w opisie PR-a.
 *
 *  3. INDEKSY POD TO, PO CZYM NAPRAWDĘ FILTRUJEMY I SORTUJEMY. Migracja
 *     `2026_09_09_400000_add_moderation_list_indexes_to_users` dokłada
 *     `users_created_at_idx` (data rejestracji) i `users_email_trgm_idx`
 *     (szukanie po adresie). Nazwy szukamy po istniejących kolumnach
 *     `profiles.username_search` / `display_name_search`, które mają
 *     indeksy trigramowe od issue #116 — dlatego zapytanie pyta o te kolumny,
 *     a nie o `kuking_normalize(username)` liczone od nowa.
 *
 * FALA REJESTRACJI Z JEDNEGO ŁĄCZA TO NIE JEST SYGNAŁ OSTRZEGAWCZY.
 * Dziesięć kont założonych tego samego popołudnia to zwykle koło gospodyń,
 * biblioteka albo jedna rodzina przy jednym Wi-Fi — czyli dokładnie ci ludzie,
 * dla których ten serwis powstał. Ekran ma to POKAZAĆ (filtr po dacie
 * rejestracji: „kto przyszedł dzisiaj"), żeby dało się takie osoby powitać,
 * i nie ma prawa tego OZNACZAĆ. Dlatego nie ma tu ani kolumny z adresem IP,
 * ani żadnego „podobne konta" — sygnały pasywne z decyzji 3.6 mają prowadzić
 * do przeglądu treści, nie do listy podejrzanych ludzi.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  TEN EKRAN JEST DO PATRZENIA. NIE MA NA NIM ANI JEDNEGO PRZYCISKU
 *  ZMIENIAJĄCEGO KONTO — I TO JEST DECYZJA, NIE BRAK CZASU.
 * ══════════════════════════════════════════════════════════════════════════
 *
 *  * ROLA. Nadaje ją wyłącznie `kuking:nadaj-role` z powłoki (D-039).
 *    Uzasadnienie stoi w nagłówku `App\Console\Commands\NadajRole`: ekran
 *    w przeglądarce znaczyłby, że przejęcie JEDNEGO konta administratora
 *    wystarcza, żeby zrobić administratorów z kolejnych. Nie dokładamy tu
 *    drugiej, słabszej drogi do tej samej rzeczy — `role` nie jest
 *    w `$fillable` (AGENTS.md §7) i ten ekran tego nie omija, bo w ogóle
 *    niczego nie zapisuje.
 *
 *  * ZAWIESZENIE, BLOKADA, PRZYWRÓCENIE. To już istnieje i ma swoje miejsce:
 *    `/admin/zgloszenia` (`ModerationController::decide()` →
 *    `ModerationAction::ACTION_SUSPEND` / `ACTION_BAN`) oraz przywracanie
 *    treści (`ModerationController::restore()`). Tamta droga wymaga POWODU
 *    i zostawia wiersz w `moderation_actions`, od którego przysługuje
 *    odwołanie (DSA art. 20). Przycisk „zawieś" wystawiony obok listy kont
 *    byłby drugą drogą do tej samej kary — tyle że bez sprawy, bez powodu
 *    i bez czegokolwiek, od czego dałoby się odwołać. Stąd na karcie konta
 *    jest ODNOŚNIK do kolejki zgłoszeń, a nie własny formularz.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CO TRAFIA DO `audit_log`, A CO NIE (docs/INSPIRATION_DECISIONS.md poz. 3.2)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Decyzja 3.2 („wpisy przy OGLĄDANIU danych, nie tylko przy zmianie") jest
 * przyjęta, więc pytanie nie brzmi „czy logować", tylko „co". Granicę
 * stawiamy między LISTĄ a KARTĄ:
 *
 *  * KARTA POJEDYNCZEGO KONTA (`show()`) — LOGUJEMY, każde wejście
 *    (`admin.user_viewed`, `subject_id` = oglądane konto). To jest moment,
 *    w którym ktoś czyta dane JEDNEJ, wskazanej osoby w komplecie: pełny
 *    adres e-mail, całą historię decyzji moderacyjnych, kiedy ostatnio tu
 *    była. Na pytanie „kto oglądał moje dane" odpowiada wyłącznie ten wpis,
 *    bo tylko on wskazuje na konkretnego człowieka.
 *
 *  * LISTA (`index()`) — NIE LOGUJEMY, i przy tysiącach kont ta decyzja waży
 *    więcej, nie mniej. Lista jest przeglądaniem, nie czytaniem: adres e-mail
 *    pokazuje w masce (pierwsza litera i domena), a moderator wchodzi na nią kilkanaście
 *    razy dziennie po drodze do czegoś innego. Wpis z niej nie odpowiedziałby
 *    na żadne pytanie — „ktoś obejrzał dwudziestu pięciu ludzi naraz" nie jest
 *    wiedzą o niczyich danych — a przy tej skali zalałby tabelę tak, że
 *    prawdziwe wejścia utonęłyby w szumie. To jest dokładnie to, przed czym
 *    ostrzega sama decyzja 3.2: dziennik ma zostać czytelny, bo dziennik,
 *    w którym nic nie widać, odpowiada „nie wiemy" tak samo jak jego brak.
 *
 * ŚWIADOMIE NIE LOGUJEMY TREŚCI WYSZUKIWANIA. `App\Models\AuditLogEntry`
 * zapisuje FAKT i AKTORA, nigdy treści — a szukana fraza to zwykle imię,
 * nazwisko albo adres e-mail konkretnej osoby, czyli dane osobowe kogoś,
 * kto o tym wpisie nigdy się nie dowie. Dziennik, który sam produkuje nowy
 * zbiór danych osobowych, jest lekarstwem gorszym od choroby.
 *
 * ŚWIADOMIE NIE ZWIJAMY WPISÓW W OKNO CZASOWE („nie loguj drugi raz w ciągu
 * kwadransa"). Kartę otwiera się celowo, po jednej sprawie, więc powodzi z niej
 * nie ma; ryzyko zalania siedziało w liście i zostało zamknięte tym, że listy
 * nie logujemy wcale. Zwijanie kosztowałoby dokładnie tę informację, której
 * ten wpis ma bronić — ile razy i kiedy ktoś do czyjegoś konta wracał.
 *
 * Retencja: zwykła, `config('kuking.audit_log.retention_months')`. Ten wpis
 * nie należy do `AuditLogEntry::NIGDY_NIE_KASUJ` — tamta lista jest zamknięta
 * i obejmuje wyłącznie zdarzenia będące JEDYNYM dowodem wykonania żądania
 * z RODO art. 17. Wgląd moderatora nim nie jest.
 */
class UzytkownicyController extends Controller
{
    public function __construct(
        private readonly ListaKont $listaKont,
    ) {}

    /**
     * Lista kont. Wejście z adresu (filtry, sortowanie) przygotowuje
     * `ListaKontRequest`, zapytania wykonuje `ListaKont` (issue #970) —
     * kontroler tylko składa odpowiedź.
     */
    public function index(ListaKontRequest $request): View
    {
        // Rolę sprawdza już `ListaKontRequest::authorize()`. Zostaje też
        // tutaj, żeby wejście było widać w kontrolerze i żeby przeniesienie
        // wejścia do Form Requestu go nie zgubiło. Adres nie jest
        // autoryzacją (AGENTS.md §7), a trasa może kiedyś trafić do innej
        // grupy niż ta za middleware `moderator`.
        $this->authorize('moderate', User::class);

        $filtry = $request->filtry();
        [$sortuj, $kierunek] = $request->sortowanie();

        return view('pages.admin.uzytkownicy', [
            'uzytkownicy' => $this->listaKont->strona($filtry, $sortuj, $kierunek),
            'filtry' => $filtry,
            'sortuj' => $sortuj,
            'kierunek' => $kierunek,
            'liczniki' => $this->listaKont->liczniki(),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        $this->authorize('moderate', User::class);

        /*
         * WPIS DO DZIENNIKA PRZED ZŁOŻENIEM WIDOKU, nie po.
         *
         * Gdyby renderowanie padło (brak profilu, błąd w Blade), a wpis
         * powstawał na końcu, ślad wglądu zginąłby razem z żądaniem — mimo
         * że dane zostały już z bazy odczytane. Dziennik ma odpowiadać na
         * pytanie „kto to otworzył", a nie „komu się to wyświetliło".
         *
         * Metadanych nie ma żadnych i to jest celowe: `subject_id` już mówi,
         * czyje dane obejrzano, a cokolwiek więcej byłoby przepisywaniem
         * cudzych danych osobowych do drugiej tabeli.
         */
        AuditLogEntry::record(
            action: 'admin.user_viewed',
            actor: $request->user(),
            subject: $user,
            ip: $request->ip(),
        );

        $user->loadCount([
            'posts as wpisow_count',
            'recipes as przepisow_count',
            'comments as komentarzy_count',
            'cookedEvents as ugotowan_count',
        ]);

        return view('pages.admin.uzytkownik', [
            'uzytkownik' => $user->load('profile'),
            'decyzje' => $this->listaKont->historiaDecyzji($user),
        ]);
    }
}
