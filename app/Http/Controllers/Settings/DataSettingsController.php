<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Compliance\RejestrPotwierdzenRodo;
use App\Domain\Users\Exports\ExportFileNames;
use App\Domain\Users\OdmowaOstatniegoAdministratora;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateUserExport;
use App\Models\AuditLogEntry;
use App\Models\DataExport;
use App\Models\User;
use App\Support\Poczta;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 *
 * Odzyskanie NIE dzieje się tutaj. Zgłoszenie usunięcia wylogowuje od razu
 * (patrz niżej), więc formularz „Twoje dane" jest dla tej osoby niedostępny
 * — cofnięcie idzie przez publiczny `AccountDeletionController`, tym samym
 * wzorcem identyfikacji co formularz odwołań dla zablokowanych kont
 * (issue #10). Egzekucję karencji po 30 dniach wykonuje komenda
 * `kuking:usun-wygasle-konta` (`App\Domain\Users\Actions\EraseAccountData`).
 */
class DataSettingsController extends Controller
{
    /**
     * Jedna treść na dwie drogi dojścia do tego samego faktu: „paczka już się
     * robi" (D-078). Pierwsza droga to `exists()` przed wstawieniem, druga —
     * konflikt na indeksie `data_exports_one_active_per_user` przy dwóch
     * równoległych żądaniach. Człowiek nie ma prawa rozpoznać, którą z nich
     * trafił, więc zdanie musi być JEDNO; dwie kopie tego samego komunikatu
     * rozjechałyby się przy pierwszej korekcie tekstu.
     */
    private const JUZ_TRWA = 'Przygotowanie paczki z Twoimi danymi już trwa. Gotową paczkę znajdziesz tutaj, w sekcji „Twoje paczki”.';

    /**
     * Dopisek o liście — TYLKO gdy poczta naprawdę wysyła (`Poczta::dziala()`,
     * issue #820). Do 23 września 2026 każdy z trzech komunikatów niżej
     * obiecywał „napiszemy na Twój adres e-mail" bezwarunkowo: przy
     * `MAIL_MAILER=log` człowiek czekał na list, który nie powstanie, a przy
     * awarii poczty — na list, którego nikt nie ponawiał. Miejscem, gdzie
     * gotowość widać zawsze, jest sekcja „Twoje paczki”, więc to ona stoi
     * w zdaniu głównym, a e-mail jest dodatkiem.
     */
    private static function obietnicaListu(): string
    {
        return Poczta::dziala() ? ' Napiszemy też do Ciebie e-mail, gdy paczka będzie gotowa.' : '';
    }

    public function show(Request $request): View
    {
        $exports = $request->user()->dataExports()->latest()->limit(5)->get();

        return view('pages.settings.data', [
            'exports' => $exports,
            'graceDays' => (int) config('kuking.account.delete_grace_days'),

            // Adres pobrania jest generowany dopiero na tym ekranie i tylko dla
            // paczek, które naprawdę da się pobrać. Podpis wygasa razem z paczką,
            // więc nie da się zapamiętać linku „na później”.
            'downloadUrls' => $exports
                ->filter(fn (DataExport $export): bool => $export->isDownloadable())
                ->mapWithKeys(fn (DataExport $export): array => [
                    $export->getKey() => URL::temporarySignedRoute(
                        'settings.data.download',
                        $export->expires_at,
                        ['export' => $export->getKey()],
                    ),
                ])
                ->all(),
        ]);
    }

    /**
     * Pobranie gotowej paczki.
     *
     * Trasa ma middleware `signed`, więc sam adres musi być podpisany przez
     * Kuking i nie może być przedawniony. To jednak NIE JEST autoryzacja —
     * podpis mówi tylko „ten link wystawiliśmy my”. Dlatego niżej sprawdzamy
     * jeszcze dwie rzeczy:
     *
     *  1. czy pobiera WŁAŚCICIEL paczki (przekazany komuś link nic nie da),
     *  2. czy paczka nadal jest do pobrania (`ready` i przed `expires_at`).
     *
     * Odpowiedzią na cudzą paczkę jest 404, a nie 403 — nie potwierdzamy nawet
     * tego, że taki eksport istnieje.
     */
    public function download(Request $request, DataExport $export): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()->getKey(), 404);
        abort_unless($export->isDownloadable(), 404);
        abort_if($export->disk === null || $export->object_key === null, 404);

        $disk = Storage::disk($export->disk);

        abort_unless($disk->exists($export->object_key), 404);

        AuditLogEntry::record('data.export_downloaded', $request->user(), $export, ip: $request->ip());

        return $disk->download($export->object_key, ExportFileNames::archiveFile($export));
    }

    public function requestExport(Request $request): RedirectResponse
    {
        $user = $request->user();

        $aktywny = $this->aktywnyEksport($user);

        if ($aktywny !== null) {
            return $this->odpowiedzNaTrwajacy($aktywny);
        }

        try {
            // `DB::transaction()` WOKÓŁ JEDNEGO `INSERT` — nie z ostrożności
            // na zapas, tylko dlatego, że na PostgreSQL samo try/catch nie
            // wystarcza. Gdy to żądanie biegnie wewnątrz SZERSZEJ transakcji
            // (a w testach `RefreshDatabase` opakowuje w nią cały test),
            // nieudany `INSERT` zatruwa CAŁĄ otaczającą transakcję: każde
            // następne zapytanie tym samym połączeniem odbija się o „current
            // transaction is aborted" — także to sprawdzające niżej, czy
            // aktywny eksport naprawdę istnieje. Laravel w trakcie transakcji
            // otwiera SAVEPOINT, więc konflikt cofa TYLKO tę jedną wstawkę.
            // Ta sama pułapka i to samo lekarstwo co w
            // `App\Domain\Analytics\ZapiszSygnal` i
            // `App\Domain\Contact\Actions\PrzyjmijWiadomosc`.
            // ZLECENIE WCHODZI DO TEJ SAMEJ TRANSAKCJI CO REKORD (audyt A02, P1).
            //
            // Przedtem `dispatch()` stał ZA tą transakcją i to jest całe
            // znalezisko: pomiędzy commitem rekordu `queued` a wysłaniem
            // zadania było okno, w którym awaria zapisu do kolejki, `kill`,
            // wyczerpana pamięć albo restart przy wdrożeniu zostawiały
            // rekord, którego NIKT nie wykona — a `maAktywnyEksport()`
            // blokował wtedy każde następne zgłoszenie. Człowiek widział
            // bezterminowo „już przygotowujemy" i nie miał ani przycisku
            // ponowienia, ani awarii, którą ekran mógłby pokazać. To jest
            // odmowa wykonania prawa z RODO art. 15 i 20, wyglądająca jak
            // cierpliwość. Zmierzone:
            // `tests/Feature/EksportNieUtykaMiedzyCommitemAWyslaniemTest.php`.
            //
            // `afterCommit()` TEGO NIE ZAMYKA i audyt pisze to wprost —
            // przenosi wysyłkę na moment po zatwierdzeniu, czyli zostawia
            // dokładnie to samo okno, tylko w innym miejscu.
            //
            // Zamyka to natomiast rzecz, której nie trzeba tu dobudowywać:
            // kolejka jest BAZODANOWA i stoi na tym samym połączeniu co
            // aplikacja, więc wiersz w `jobs` i wiersz w `data_exports`
            // zatwierdzają się RAZEM. To jest transactional outbox z ustaleń
            // audytu, tylko bez nowej tabeli, bez Redisa i bez nowego
            // mechanizmu dostarczania — czyli w granicach `AGENTS.md` §3.
            $export = DB::transaction(function () use ($user): DataExport {
                $export = DataExport::create([
                    'user_id' => $user->getKey(),
                    'status' => DataExport::STATUS_QUEUED,
                ]);

                $this->zlecWykonanie($export);

                return $export;
            });
        } catch (UniqueConstraintViolationException $e) {
            // TU WCHODZI DRUGIE, RÓWNOLEGŁE ŻĄDANIE (audyt QUEUE-04/RACE-05,
            // D-078). `exists()` wyżej jest dobre na komunikat, ale nie jest
            // gwarancją: dwa żądania widzą „nie ma aktywnego eksportu"
            // jednocześnie i oba idą do `INSERT`. Gwarancję daje indeks
            // częściowy `data_exports_one_active_per_user` — i dopiero on
            // sprowadza tu jedno z tych żądań.
            //
            // Człowiek, który kliknął dwa razy, musi zobaczyć DOKŁADNIE TO
            // SAMO co ten, który kliknął raz. Dlatego ten sam komunikat co
            // wyżej, a nie 500: to nie jest awaria, tylko druga odpowiedź na
            // to samo pytanie, i odpowiedź na nie jest twierdząca („już
            // przygotowujemy"). Ekran błędu byłby tu karą za dwuklik, czyli
            // za rzecz, która w grupie 60+ jest normalna
            // (`docs/UX_50_PLUS.md`).
            $aktywny = $this->aktywnyEksport($user);

            if ($aktywny === null) {
                // Konflikt unikalności, ale NIE ten. `data_exports` ma poza
                // tym indeksem tylko klucz główny, więc tu nie powinno się
                // dać wejść — a jeśli się dało, to znaczy, że odbiło się coś
                // innego, i wyciszenie tego zamiotłoby usterkę pod dywan
                // razem z paczką, której człowiek nie dostanie.
                throw $e;
            }

            return $this->odpowiedzNaTrwajacy($aktywny);
        } catch (QueryException $e) {
            // Baza nie przyjęła rekordu albo zadania (issue #824). Transakcja
            // wyżej cofnęła OBA, więc nie zostaje `queued` bez wykonawcy —
            // ale człowiek nie może dostać ekranu 500, tylko zdanie, co
            // zrobić. Konflikt unikalności łapie gałąź wyżej; `LogicException`
            // z `zlecWykonanie()` (zła kolejka) celowo przelatuje — to błąd
            // wdrożenia, nie chwilowa awaria.
            report($e);

            // `status`, bo to jedyny komunikat, który układ strony pokazuje
            // po przekierowaniu na „Twoje dane".
            return back()->with('status',
                'Nie udało się teraz przyjąć prośby o paczkę z Twoimi danymi — nic nie zostało zapisane. '
                .'Spróbuj jeszcze raz za kilka minut przyciskiem „Przygotuj paczkę z moimi danymi”.',
            );
        }

        // WPIS POMOCNICZY ZA TRANSAKCJĄ (D-249, klasa 2; #1429). Autorytatywny
        // ślad przyjęcia żądania to wiersz `data_exports` z `created_at`
        // i zadanie w `jobs` — oba już zatwierdzone. Awaria dziennika nie
        // cofnie paczki, więc 500 byłoby tu nieprawdą, a ponowienie trafiłoby
        // w „już przygotowujemy" i wpisu i tak nie uzupełniło. Brak idzie do
        // `report()` z nazwą zdarzenia; człowiek dostaje potwierdzenie.
        AuditLogEntry::recordBezWywracania('data.export_requested', $user, $user, ip: $request->ip());

        return back()->with('status',
            'Przygotowujemy paczkę z Twoimi danymi. To może potrwać kilkanaście minut. '
            .'Gotową paczkę znajdziesz tutaj, w sekcji „Twoje paczki”.'.self::obietnicaListu(),
        );
    }

    /**
     * Eksport, który to konto ma teraz w robocie — albo `null`.
     *
     * Jedno pytanie, dwa wywołania: raz PRZED wstawieniem (żeby dać spokojny
     * komunikat bez dobijania się do bazy o konflikt), raz PO konflikcie
     * (żeby rozpoznać, czy odbiło się o TEN indeks, czy o coś innego).
     * Gdyby ta lista stanów stała w dwóch miejscach osobno, rozjechałaby się
     * przy pierwszej zmianie słownika stanów — i to cicho, bo obie gałęzie
     * kończą się tym samym ekranem.
     *
     * Zwraca MODEL, nie `bool`: od audytu A02 odpowiedź na „już trwa" zależy
     * od tego, czy ten eksport naprawdę jeszcze się rusza.
     */
    private function aktywnyEksport(User $user): ?DataExport
    {
        return $user->dataExports()
            ->whereIn('status', [DataExport::STATUS_QUEUED, DataExport::STATUS_PROCESSING])
            ->first();
    }

    /**
     * DRUGA POŁOWA A02: rekord porzucony w kolejce daje się PONOWIĆ.
     *
     * Transactional outbox wyżej zamyka okno na przyszłość, ale nie
     * odblokowuje konta, w którym eksport utknął WCZEŚNIEJ — ani żadnej innej
     * drogi zgubienia zadania: wyczyszczona tabela `jobs`, worker, który nigdy
     * nie wstał, zmieniona nazwa kolejki. Wszystkie kończą się tak samo:
     * wiersz `queued` bez wykonawcy i ekran, który bezterminowo mówi „już
     * przygotowujemy".
     *
     * Ponawiamy TEN SAM wiersz, nie zakładamy drugiego. Indeks częściowy
     * `data_exports_one_active_per_user` (D-078) i tak nie dopuści dwóch
     * aktywnych, ale powód jest głębszy: dwa wiersze znaczyłyby dwie paczki
     * tych samych zdjęć i dwa listy z dobowego wiadra (D-076).
     *
     * GRANICA JEST WĄSKA I ŚWIADOMA — tylko `queued`. Eksport w stanie
     * `processing` worker już PODJĄŁ; ponowienie dałoby dwa procesy pakujące
     * setki megabajtów na jednym rekordzie. Rekordy zawieszone w `processing`
     * domyka `GenerateUserExport::failed()`, który łapie także przekroczenie
     * 15-minutowego limitu czasu, i to jest właściwe dla nich miejsce.
     */
    private function odpowiedzNaTrwajacy(DataExport $aktywny): RedirectResponse
    {
        if (! $this->porzucony($aktywny)) {
            return back()->with('status', self::JUZ_TRWA.self::obietnicaListu());
        }

        // POD BLOKADĄ WIERSZA I Z REWALIDACJĄ POD NIĄ (D-079 §2). Dwuklik
        // i dwa równoległe żądania dają tu dwa wejścia w to samo miejsce;
        // bez blokady oba wysłałyby zadanie. Stan czytamy jeszcze raz, bo
        // między pytaniem wyżej a tą transakcją worker mógł już rekord podjąć.
        $ponowiony = DB::transaction(function () use ($aktywny): bool {
            $swiezy = DataExport::query()->whereKey($aktywny->getKey())->lockForUpdate()->first();

            if ($swiezy === null || ! $this->porzucony($swiezy)) {
                return false;
            }

            // Znacznik ruchu PRZED wysłaniem zadania: od tej chwili rekord
            // nie jest już porzucony, więc następny dwuklik nie zrobi
            // trzeciego zadania. Bez tego „ponów" byłby przyciskiem do
            // mnożenia zadań pakujących te same zdjęcia.
            $swiezy->touch();

            $this->zlecWykonanie($swiezy);

            return true;
        });

        if (! $ponowiony) {
            return back()->with('status', self::JUZ_TRWA.self::obietnicaListu());
        }

        return back()->with('status',
            'Przygotowanie paczki z Twoimi danymi trwało dłużej, niż powinno, więc właśnie ponowiliśmy '
            .'zlecenie. Gotową paczkę znajdziesz tutaj, w sekcji „Twoje paczki”.'.self::obietnicaListu(),
        );
    }

    /** Czy ten eksport stoi w kolejce bez ruchu dłużej, niż wolno (`DataExport::MINUT_NA_PODJECIE`). */
    private function porzucony(DataExport $export): bool
    {
        return $export->status === DataExport::STATUS_QUEUED
            && $export->updated_at !== null
            && $export->updated_at->lt(now()->subMinutes(DataExport::MINUT_NA_PODJECIE));
    }

    /**
     * Wysłanie zadania — WYŁĄCZNIE wewnątrz transakcji, która tworzy albo
     * przejmuje rekord.
     *
     * Odmowa, a nie ciche obejście, gdy kolejka nie jest ani `sync`, ani
     * bazodanowa na TYM SAMYM połączeniu co aplikacja. Przy takiej kolejce
     * (Redis, SQS) wiersz zadania stałby się widoczny dla workera PRZED
     * commitem rekordu — czyli ta sama usterka co A02, tylko odwrócona:
     * worker sięgałby po `data_exports`, którego jeszcze nie ma.
     *
     * `AGENTS.md` §3 zabrania tu Redisa i SQS-a, więc to nie jest przypadek
     * do obsłużenia — to jest pułapka do zastawienia. Ten sam wzorzec co
     * `ZdjeciaDoPrzypiecia::zablokuj()`: niech pada głośno, przy pierwszym
     * uruchomieniu testów, a nie po cichu na produkcji.
     */
    private function zlecWykonanie(DataExport $export): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'GenerateUserExport wolno wysłać tylko wewnątrz transakcji rekordu eksportu (audyt A02) '
                .'— poza nią wraca okno pomiędzy commitem a wysyłką.',
            );
        }

        $nazwa = (string) config('queue.default');
        $sterownik = (string) config("queue.connections.{$nazwa}.driver");
        $polaczenieKolejki = config("queue.connections.{$nazwa}.connection");

        $tejSamejBazy = $sterownik === 'database'
            && ($polaczenieKolejki === null || $polaczenieKolejki === config('database.default'));

        if ($sterownik !== 'sync' && ! $tejSamejBazy) {
            throw new LogicException(
                'Kolejka "'.$nazwa.'" (sterownik "'.$sterownik.'") nie zapisuje zadań do tej samej bazy '
                .'co rekord eksportu, więc zadanie i rekord nie zatwierdzą się razem (audyt A02). '
                .'Kuking używa kolejki bazodanowej (AGENTS.md §3) — zmiana sterownika wymaga tu '
                .'osobnego mechanizmu dostarczenia, nie zdjęcia tego warunku.',
            );
        }

        GenerateUserExport::dispatch((string) $export->getKey());
    }

    /**
     * ZAKRES USUNIĘCIA WYBIERA CZŁOWIEK (D-022).
     *
     * Haczyk „usuń także moje treści" jest DOMYŚLNIE ODHACZONY i dlatego
     * nie ma tu żadnej reguły `required`: brak pola w żądaniu to poprawna,
     * najczęstsza odpowiedź, znacząca „zostaw teksty". `boolean` pilnuje
     * tylko, żeby nie dało się wcisnąć tam czegoś innego niż tak/nie.
     *
     * Wybór idzie do KOLUMNY, nie do sesji ani do zadania w kolejce —
     * egzekucja jest 30 dni później (D-022, punkt 2).
     */
    public function requestDeletion(Request $request, RejestrPotwierdzenRodo $rejestr): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
            'usun_tresci' => ['nullable', 'boolean'],
        ], [
            'password.required' => 'Wpisz swoje hasło, żeby potwierdzić, że to Ty.',
            'confirm.accepted' => 'Zaznacz, że rozumiesz, co się stanie.',
            'usun_tresci.boolean' => 'Zaznacz haczyk albo zostaw go pustym.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Wpisz poprawne hasło, żeby potwierdzić usunięcie konta.'])
                ->withInput($request->only('usun_tresci'));
        }

        $zakres = $request->boolean('usun_tresci')
            ? User::DELETE_SCOPE_EVERYTHING
            : User::DELETE_SCOPE_MINIMUM;

        // OZNACZENIE KONTA I OTWARCIE SPRAWY W REJESTRZE RODO — JEDNA
        // TRANSAKCJA, nie dwie instrukcje obok siebie.
        //
        // `potwierdzenia_zadan_rodo` ma jedną sprawę na jedno żądanie
        // (`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`). Gdyby te dwa zapisy
        // szły osobno, zostawałby stan pośredni: konto oznaczone do usunięcia
        // BEZ sprawy w rejestrze (żądanie, którego nie ma jak potwierdzić —
        // i którego egzekutor karencji za 30 dni nie będzie miał czym
        // domknąć) albo sprawa w rejestrze bez oznaczonego konta (rejestr
        // twierdzący, że coś przyjęliśmy, choć nic się nie dzieje).
        //
        // Ta sama zasada, z tego samego powodu, wiąże domknięcie sprawy
        // z `EraseAccountData` i `CancelAccountDeletion`.
        // TRANSAKCJA Z POŁĄCZENIA MODELU, NIE Z FASADY `DB` — ŚWIADOMIE.
        //
        // KOLIZJA, KTÓREJ GIT NIE ZGŁASZA. Ten sam plik przepisuje #1259
        // („awaria poczty nie niszczy paczki eksportu"), zdejmując
        // `use Illuminate\Support\Facades\DB` — słusznie, bo po jego zmianie
        // jedyne pozostałe użycie fasady w tym pliku (transakcja w metodzie
        // eksportu) znika razem z nim. Ta metoda i tamta to RÓŻNE metody, więc
        // scalenie przechodzi BEZ KONFLIKTU, a wynik jest zepsuty: zostaje
        // wywołanie `DB::` bez importu, czyli
        //
        //     Class "App\Http\Controllers\Settings\DB" not found
        //
        // przy KAŻDYM zgłoszeniu usunięcia konta. Żadna z gałęzi osobno tego
        // nie pokazuje i żadne CI nie złapie tego przed scaleniem.
        //
        // Pełna nazwa `\Illuminate\…\DB` NIE jest tu rozwiązaniem: `pint`
        // (reguła `fully_qualified_strict_types`) skraca ją z powrotem do
        // `DB::`, dopóki import istnieje — sprawdzone, nie przypuszczane.
        // Połączenie wzięte z modelu nie zależy od żadnego importu, więc działa
        // niezależnie od kolejności scalania. To ta sama transakcja i to samo
        // połączenie.
        try {
            $user->getConnection()->transaction(function () use ($user, $zakres, $rejestr): void {
                $user->markForDeletion($zakres);

                $rejestr->przyjmijZadanieUsunieciaKonta($user);
            });
        } catch (OdmowaOstatniegoAdministratora) {
            // Ostatni czynny administrator (#1016). Transakcja wycofana:
            // konto czynne, bez sprawy w rejestrze i bez wpisu w audycie.
            return back()->withErrors([
                'confirm' => 'Jesteś ostatnim czynnym administratorem serwisu. Zanim usuniesz konto, '
                    .'nadaj rolę administratora innemu czynnemu kontu — bez tego nikt nie rozpatrzy odwołań.',
            ])->withInput($request->only('usun_tresci'));
        } catch (BladDlaCzlowieka $blad) {
            // Świeży stan pod blokadą mówi, że konto już jest w usuwaniu
            // (drugie kliknięcie, druga karta — #980). Nic nie zapisano.
            return back()->withErrors(['confirm' => $blad->getMessage()]);
        }

        // Zakres w audycie, bo to jest jedyny zapis tego, CO człowiek wybrał
        // i kiedy. Gdyby ktoś kiedyś zapytał „dlaczego moje przepisy
        // zniknęły" (albo „dlaczego NIE zniknęły"), odpowiedź musi dać się
        // znaleźć bez zgadywania.
        AuditLogEntry::record(
            'account.delete_requested',
            $user,
            $user,
            metadata: ['zakres' => $zakres],
            ip: $request->ip(),
        );

        $days = (int) config('kuking.account.delete_grace_days');

        $coZTekstami = $zakres === User::DELETE_SCOPE_EVERYTHING
            ? "Po {$days} dniach usuniemy też Twoje przepisy, wpisy, komentarze, wykonania i zeszyty — tak jak mówi zaznaczony haczyk. "
            : "Po {$days} dniach Twoje przepisy, wpisy i komentarze zostaną w serwisie bez Twojego nazwiska, podpisane „Użytkownik usunięty”. ";

        // Wylogowujemy w TYM SAMYM żądaniu, nie czekamy, aż zrobi to
        // `EnsureAccountIsActive` przy kolejnym wejściu (audyt A8).
        //
        // Bez tego przeglądarka i tak szła za przekierowaniem niżej, ale po
        // drodze middleware widziało jeszcze zalogowane, oznaczone do
        // usunięcia konto — wylogowywało je SAMO i PODMIENIAŁO to
        // przekierowanie na ekran logowania z zupełnie INNYM komunikatem.
        // Flash ustawiony tutaj nigdy nie docierał do człowieka: widział
        // tylko komunikat z `LoginController::komunikatOdmowy()`, który do
        // niedawna w ogóle nie wspominał o istnieniu drogi powrotu.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing')->with('status',
            "Konto zostało oznaczone do usunięcia i wylogowaliśmy Cię. Masz {$days} dni, żeby zmienić zdanie — "
            .'zrobisz to na stronie „Cofnij usunięcie konta” ('.route('account.delete.cancel').'), podając e-mail '
            .'albo nazwę użytkownika i hasło. Jeśli nie pamiętasz hasła, najpierw je zresetuj — to też zadziała. '
            .$coZTekstami,
        );
    }
}
