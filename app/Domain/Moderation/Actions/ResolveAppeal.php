<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\CofniecieUkryciaWersji;
use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\NowaDecyzja;
use App\Domain\Moderation\PodstawaDecyzji;
use App\Domain\Moderation\TrescZabezpieczonaJakoDowod;
use App\Domain\Moderation\WlasnejTresciNiePrzywracasz;
use App\Domain\Users\ZamekUprzywilejowanegoAktora;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\RecipeHint;
use App\Models\User;
use App\Models\ZabezpieczenieDowodu;
use App\Support\ZabezpieczoneDowody;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Rozpatrzenie odwołania (issue #10, DSA art. 20).
 *
 * ODWOŁANIE, PO KTÓRYM NIC SIĘ NIE ZMIENIA, NIE JEST ODWOŁANIEM
 * Dlatego „cofam decyzję" nie jest tu samym wpisem w kolumnie: przywraca
 * treść (`RestoreContent`, issue #65) albo odblokowuje konto
 * (`User::reinstate()`). Bez tego moderator zaznaczałby „cofnięte", a treść
 * dalej byłaby ukryta — czyli papier znowu mówiłby co innego niż serwis.
 *
 * KARENCJA NA PODTRZYMANIE WŁASNEJ DECYZJI
 * `docs/legal/MODERATION_PLAYBOOK.md` §3: „jedna osoba nie powinna być
 * jednocześnie moderatorem i jedynym organem odwoławczym dla własnych decyzji
 * — jeśli to niemożliwe personalnie, przynajmniej odczekaj i spójrz na sprawę
 * drugi raz po czasie". Przy zespole 1-2 osób wymóg „ktoś inny" jest nie do
 * spełnienia, więc egzekwujemy to, co da się spełnić: ten sam człowiek nie
 * PODTRZYMA własnej decyzji przez pierwsze 24 godziny.
 *
 * COFNIĘCIE własnej decyzji działa natychmiast i celowo nie ma karencji.
 * Kazanie komuś siedzieć dobę z ukrytą treścią dlatego, że moderator
 * zorientował się w pomyłce za szybko, szkodziłoby wyłącznie poszkodowanemu.
 * Karencja ma powstrzymać odruchowe „podtrzymuję", a nie przyznanie się
 * do błędu.
 *
 * DWIE ROLE ODWOŁUJĄCEGO SIĘ, JEDNA ŚCIEŻKA ROZPATRZENIA (issue #23)
 * Ta klasa nie wie ani nie musi wiedzieć, kto się odwołał — karencja,
 * cofnięcie skutków i log audytu są identyczne dla obu ról. Różni się
 * wyłącznie DORĘCZENIE odpowiedzi na końcu: autor ma konto i powiadomienia
 * w serwisie (`NotifyAppealOutcome`), zgłaszający może nie mieć konta wcale
 * i dostaje odpowiedź mailem (`NotifyReporterAppealOutcome`) na adres
 * zapisany przy jego zgłoszeniu.
 *
 * ODWOŁANIE ZGŁASZAJĄCEGO OD DECYZJI BEZ DZIAŁANIA (#989, DSA art. 20 ust. 4)
 * Cofnięcie `no_action` nie ma czego przywrócić, więc „cofam" bez niczego
 * więcej byłoby odpowiedzią „zmieniamy decyzję" bez zmiany. Do 24.09.2026
 * dokładnie tak było: moderator miał działać „ręcznie, poza systemem".
 * Teraz uznanie takiego odwołania WYMAGA nowej decyzji
 * (`Appeal::wymagaNowejDecyzji()`, `NowaDecyzja`), a `DecyzjaPoOdwolaniu`
 * wykonuje ją w tej samej transakcji: skutek, wiersz `moderation_actions`
 * z `appeal_id`, powiadomienie autora, zgłoszenie `resolved`. Jeśli nowej
 * decyzji nie da się wykonać (celu nie ma, kara wobec wyższej rangi),
 * odwołania nie da się uznać — zostaje „podtrzymuję" z uzasadnieniem.
 */
final class ResolveAppeal
{
    public const JUZ_ROZPATRZONE = 'To odwołanie zostało już rozpatrzone. Odśwież stronę, żeby zobaczyć odpowiedź.';

    public const WYBIERZ_NOWA_DECYZJE = 'Cofając decyzję „Bez działania”, wybierz nową decyzję wobec zgłoszonej treści. '
        .'Jeśli po ponownym sprawdzeniu nadal nie trzeba nic robić, wybierz „Podtrzymuję decyzję”.';

    /** Dopisek do odpowiedzi, gdy uchylona kara nie jest tą, która dziś obowiązuje (#933). */
    public const KONTO_ZOSTAJE_ZABLOKOWANE = 'Tę decyzję cofnęliśmy. Twoje konto pozostaje jednak zablokowane '
        .'na podstawie późniejszej, osobnej decyzji. Od niej możesz odwołać się osobno.';

    public const KONTO_ZOSTAJE_ZAWIESZONE = 'Tę decyzję cofnęliśmy. Twoje konto pozostaje jednak zawieszone '
        .'na podstawie późniejszej, osobnej decyzji. Od niej możesz odwołać się osobno.';

    public const BLOKADA_Z_DOWODEM = 'Tej blokady nie można cofnąć w panelu, bo została nałożona razem z zabezpieczeniem dowodu. '
        .'Pozostaw odwołanie otwarte i przekaż sprawę właścicielowi serwisu albo prawnikowi do ręcznego rozpatrzenia.';

    public function __construct(
        private readonly RestoreContent $przywroc,
        private readonly NotifyAppealOutcome $powiadom,
        private readonly NotifyReporterAppealOutcome $powiadomZglaszajacego,
        private readonly NotifyReporterDecisionChanged $skorygujZglaszajacemu,
        private readonly DecyzjaPoOdwolaniu $decyzjaPoOdwolaniu,
        private readonly CofniecieUkryciaWersji $wersjaPrzepisu,
    ) {}

    /**
     * @param  string  $wynik  Appeal::STATUS_UPHELD albo Appeal::STATUS_OVERTURNED
     * @param  ?NowaDecyzja  $nowaDecyzja  wymagana (i używana) wyłącznie przy uznaniu
     *                                     odwołania, dla którego `wymagaNowejDecyzji()` (#989)
     *
     * @throws AuthorizationException gdy `$moderator`
     *                                nie ma roli uprawniającej do rozstrzygania odwołań (issue #1087)
     * @throws BladDlaCzlowieka gdy odwołania nie wolno teraz zamknąć
     */
    public function handle(
        User $moderator,
        Appeal $odwolanie,
        string $wynik,
        string $uzasadnienie,
        ?string $ip = null,
        ?NowaDecyzja $nowaDecyzja = null,
    ): Appeal {
        // KTO ROZSTRZYGA — PYTANIE DOMENY, NIE KONTROLERA (issue #1087).
        //
        // Reguła „odwołanie zamyka administrator, nie każdy moderator"
        // (`UserPolicy::resolveAppeals`, A-4) była egzekwowana WYŁĄCZNIE
        // w `AppealController::resolve()`. Czyli obowiązywała dokładnie na
        // jednej drodze do tej akcji — każde inne wywołanie (komenda
        // artisan, zadanie w kolejce, test, przyszły endpoint albo webhook)
        // zamykało cudze odwołanie, cofało decyzję moderacyjną
        // i odwieszało konto bez żadnej bramki. Nazwa parametru
        // `$moderator` była jedyną „kontrolą" roli, jaka tu stała.
        //
        // Bramka stoi TUTAJ, a nie w kontrolerze, bo to ta klasa robi
        // skutki: `cofnij()` woła `reinstate()` na koncie i `RestoreContent`
        // na treści. Bramka pilnująca skutków musi stać przy skutkach —
        // inaczej chroni jedną drogę, a nie czynność.
        //
        // `AuthorizationException`, a nie `BladDlaCzlowieka`: to nie jest
        // komunikat do formularza. `AppealController` łapie
        // `BladDlaCzlowieka` i zamienia go na błąd pola — brak uprawnień
        // zamieniony na „popraw formularz" byłby odmową, która wygląda jak
        // literówka. Tu ma wyjść 403.
        //
        // Kontroler NIE traci swojego `authorize()`: tam bramka odpowiada
        // za kod HTTP i za to, że walidacja formularza w ogóle się nie
        // uruchamia. Ta jest ostatnią linią, nie jedyną.
        // Wstępna odmowa. Autorytatywna kontrola jest niżej, na świeżym
        // aktorze pod blokadą trzymaną przez całą decyzję (#2086).
        Gate::forUser($moderator)->authorize('resolveAppeals', User::class);

        // Tanie sprawdzenie na wejściu — ten sam komunikat co pod blokadą
        // niżej. Rozstrzyga WYŁĄCZNIE sprawdzenie pod blokadą; to tutaj
        // oszczędza tylko transakcję, gdy strona była dawno nieodświeżona.
        if (! $odwolanie->isOpen()) {
            throw new BladDlaCzlowieka(self::JUZ_ROZPATRZONE);
        }

        if (! in_array($wynik, [Appeal::STATUS_UPHELD, Appeal::STATUS_OVERTURNED], true)) {
            throw new BladDlaCzlowieka('Wybierz, czy podtrzymujesz decyzję, czy ją cofasz.');
        }

        $uzasadnienie = trim($uzasadnienie);

        if ($uzasadnienie === '') {
            throw new BladDlaCzlowieka('Napisz, dlaczego tak decydujesz. Bez tego nie da się wysłać odpowiedzi.');
        }

        // JEDNA TRANSAKCJA, POD BLOKADĄ WIERSZA ODWOŁANIA (#950).
        //
        // Do 24.09.2026 sprawdzenie `isOpen()` stało na obiekcie z wiązania
        // trasy, a skutek, wynik, odpowiedź i wpis w dzienniku szły osobnymi
        // zapisami bez transakcji. Dwa równoległe rozpatrzenia (dwie karty,
        // dwóch administratorów) oba widziały `open`: jedno cofało decyzję
        // i odblokowywało konto, drugie „podtrzymywało" i nadpisywało wynik —
        // człowiek dostawał dwie sprzeczne odpowiedzi, a konto zostawało
        // odblokowane przy odwołaniu zapisanym jako podtrzymane. Awaria
        // w połowie zostawiała treść przywróconą przy odwołaniu dalej
        // otwartym.
        //
        // Teraz: `lockForUpdate()` wstrzymuje drugie rozpatrzenie do końca
        // pierwszego, a ponowne sprawdzenie stanu JUŻ POD BLOKADĄ daje mu
        // „już rozpatrzone" bez żadnego skutku. Skutek, wynik, powiadomienie
        // w serwisie i wpis w dzienniku zatwierdzają się razem albo wcale.
        // List do zgłaszającego to zadanie w kolejce `database`, więc jego
        // wiersz w `jobs` też jest częścią tej transakcji — po wycofaniu nie
        // wyjdzie, a po zatwierdzeniu ponawia go kolejka, nie człowiek.
        //
        // Kolejność blokad: wspólny zamek roli → aktor → odwołanie →
        // treść (`RestoreContent`) albo konto. Nikt inny nie bierze blokady
        // odwołania, więc ta kolejność nie ma z kim się odwrócić. Pomiar:
        // `tests/Dwa/RozpatrzenieOdwolaniaNaDwochPolaczeniachTest.php`.
        // Wskazówka od gotujących (#2352): cofnięcie albo nowa decyzja blokuje
        // konta kucharza i autora przepisu, więc idą z aktorem jednym przebiegiem
        // rosnąco po `id` (jak „Wycofaj zgodę”).
        $pierwotna = ModerationAction::query()->whereKey($odwolanie->moderation_action_id)->first(['id', 'target_type', 'target_id']);
        $konta = $pierwotna?->target_type === 'recipe_hint' ? RecipeHint::kontaDoBlokady($pierwotna->target_id) : [];

        return ZamekUprzywilejowanegoAktora::wykonaj($moderator, function (User $swiezy) use ($odwolanie, $wynik, $uzasadnienie, $ip, $nowaDecyzja): Appeal {
            Gate::forUser($swiezy)->authorize('resolveAppeals', User::class);

            $moderator = $swiezy;

            $zablokowane = Appeal::query()->whereKey($odwolanie->getKey())->lockForUpdate()->first();

            if ($zablokowane === null || ! $zablokowane->isOpen()) {
                throw new BladDlaCzlowieka(self::JUZ_ROZPATRZONE);
            }

            $decyzja = $zablokowane->moderationAction;

            if ($wynik === Appeal::STATUS_UPHELD) {
                $this->sprawdzKarencje($moderator, $decyzja);
            }

            $dopisek = null;
            $dopisekWersji = null;
            $poOdwolaniu = null;

            if ($wynik === Appeal::STATUS_OVERTURNED && $zablokowane->wymagaNowejDecyzji()) {
                if ($nowaDecyzja === null) {
                    throw new BladDlaCzlowieka(self::WYBIERZ_NOWA_DECYZJE);
                }

                $poOdwolaniu = $this->decyzjaPoOdwolaniu->handle($moderator, $zablokowane, $decyzja, $nowaDecyzja, $ip);
            } elseif ($wynik === Appeal::STATUS_OVERTURNED) {
                $dopisek = $this->cofnij($moderator, $decyzja, $uzasadnienie, $ip, $dopisekWersji);
            }

            $zablokowane->update([
                'status' => $wynik,
                'decided_by' => $moderator->getKey(),
                'decision_note' => $uzasadnienie,
                'decided_at' => now(),
            ]);

            $zablokowane->refresh();

            if ($zablokowane->isFromReporter()) {
                $this->powiadomZglaszajacego->handle($zablokowane);
            } else {
                $this->powiadom->handle($zablokowane, $dopisek ?? $dopisekWersji);
                // Druga strona sprawy (#1024): zgłaszający dostał „treści nie
                // ma", a po cofnięciu treść wraca. Klasa sama sprawdza, czy
                // skutek naprawdę się zmienił — przy `upheld` nic nie robi.
                $this->skorygujZglaszajacemu->handle($zablokowane);
            }

            AuditLogEntry::record(
                action: 'appeal.resolved',
                actor: $moderator,
                subject: $zablokowane,
                metadata: [
                    'outcome' => $wynik,
                    'appellant' => $zablokowane->appellant,
                    'original_decision' => $decyzja->action,
                    'original_moderator_id' => (string) $decyzja->moderator_id,
                    // Uchylona kara nie była tą obowiązującą — konto zostało
                    // przy późniejszej decyzji (#933).
                    'later_sanction_kept' => $dopisek !== null,
                    // Decyzja wykonana po uznaniu odwołania od „Bez działania” (#989).
                    'new_action_id' => $poOdwolaniu === null ? null : (string) $poOdwolaniu->getKey(),
                    'new_decision' => $poOdwolaniu?->action,
                ],
                ip: $ip,
            );

            return $zablokowane;
        }, $konta);
    }

    private function sprawdzKarencje(User $moderator, ModerationAction $decyzja): void
    {
        if ($decyzja->moderator_id !== $moderator->getKey()) {
            return;
        }

        $godziny = (int) config('kuking.moderation.appeal_self_uphold_hours');
        $mozliwe = $decyzja->created_at->copy()->addHours($godziny);

        if ($mozliwe->isFuture()) {
            throw new BladDlaCzlowieka(
                'To Twoja własna decyzja sprzed niecałych '.$godziny.' godzin. '
                .'Podtrzymać ją możesz od '.$mozliwe->translatedFormat('j F Y, H:i')
                .' — do tego czasu sprawę może zamknąć druga osoba z zespołu. '
                .'Cofnąć decyzję możesz od razu.',
            );
        }
    }

    /**
     * Realne cofnięcie skutków decyzji.
     *
     * Świadomie NIE rzuca wyjątkiem, gdy nie ma czego cofać (treść już
     * przywrócona ręcznie, konto już odwieszone po upływie terminu). Odwołanie
     * ma zostać zamknięte i odpowiedź ma dojść — brak roboty technicznej nie
     * jest powodem, żeby człowiek nie dostał odpowiedzi.
     */
    private function cofnij(User $moderator, ModerationAction $decyzja, string $uzasadnienie, ?string $ip, ?string &$dopisekWersji = null): ?string
    {
        if (in_array($decyzja->action, [ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN], true)) {
            // Ban z procedury CSAM ma własną decyzję, powiązaną w `note`
            // z decyzją o zabezpieczeniu treści. Rejestr dowodu potwierdza
            // ten konkretny związek — sam kod podstawy albo inne dowody
            // dotyczące konta nie mogą blokować zwykłego odwołania.
            if ($decyzja->action === ModerationAction::ACTION_BAN && $this->blokadaZDowodem($decyzja)) {
                throw new BladDlaCzlowieka(self::BLOKADA_Z_DOWODEM);
            }

            return $this->zdejmijKareKonta($decyzja);
        }

        if (! in_array($decyzja->action, [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE], true)) {
            // `warn` nie zrobiło nic z treścią ani z kontem — cofnięcie jest
            // w całości treścią odpowiedzi.
            return null;
        }

        // TREŚĆ ZABEZPIECZONA JAKO DOWÓD NIE WRACA (ścieżka CSAM, D-333),
        // więc odwołania od takiej decyzji nie da się uznać w panelu.
        // Sprawdzamy PRZED `RestoreContent` i poza `try`: odmowa stamtąd
        // byłaby połknięta niżej jak „już widoczna”, odwołanie zamknęłoby się
        // jako uznane z uzasadnieniem „cofnięto”, a treść dalej byłaby ukryta
        // — nieprawdziwa odpowiedź (DSA art. 20). Transakcja się wycofuje,
        // odwołanie zostaje otwarte, moderator dostaje czytelny błąd.
        if ($this->zabezpieczona($decyzja)) {
            throw new TrescZabezpieczonaJakoDowod;
        }

        // Wersja przepisu (#2270): nie ma `status`, więc `RestoreContent`
        // jej nie przywróci — wraca przez `hidden_at`. Te same dwie reguły
        // co niżej: własnej treści nie przywracasz (wyjątek wychodzi), „nie
        // ma czego cofać” nie blokuje odpowiedzi.
        if ($decyzja->target_type === CofniecieUkryciaWersji::TYP) {
            try {
                // Zdanie dla autora, gdy wersja wróciła do JEGO ukrycia
                // (decyzja 30.09.2026) — osobno od `$dopisek` kary na koncie.
                $dopisekWersji = $this->wersjaPrzepisu->poOdwolaniu($moderator, $decyzja, $uzasadnienie, $ip);
            } catch (WlasnejTresciNiePrzywracasz $odmowa) {
                throw $odmowa;
            } catch (BladDlaCzlowieka) {
                // Wersji już nie ma — odwołanie i tak dostaje odpowiedź.
            }

            return null;
        }

        // Wskazówka od gotujących (#2352): wraca przez `moderation_hidden_at`, nie
        // przez `status` (to zgoda kucharza i `RestoreContent` jej nie ruszy).
        if ($decyzja->target_type === 'recipe_hint') {
            $this->przywrocWskazowke($moderator, $decyzja, $uzasadnienie, $ip);

            return null;
        }

        $tresc = ModeratedContent::znajdz($decyzja->target_type, $decyzja->target_id, zUsunietymi: true);

        if ($tresc === null || ! ModeratedContent::daSieUkryc($tresc)) {
            return null;
        }

        try {
            // `podZamkiem`, nie `handle`: zamek aktora trzyma już `handle()`
            // tej klasy, a `$moderator` to jego świeży model (#2086).
            $this->przywroc->podZamkiem(
                swiezy: $moderator,
                target: $tresc,
                reasonCode: 'appeal_overturned',
                note: 'Cofnięte po odwołaniu.',
                userMessage: $uzasadnienie,
                ip: $ip,
                // Odpowiedź na odwołanie idzie osobno i mówi to samo lepiej.
                zPowiadomieniem: false,
            );
        } catch (TrescZabezpieczonaJakoDowod $odmowa) {
            // Zabezpieczony dowód wykryty dopiero pod blokadą (wyścig z zabezpieczeniem).
            throw $odmowa;
        } catch (WlasnejTresciNiePrzywracasz $odmowa) {
            // Własnej treści nie przywracasz nawet z odwołania (#1479).
            // Połknięcie zamknęłoby odwołanie jako „cofam" przy treści, która
            // dalej jest schowana. Nic jeszcze nie zapisaliśmy — odmowa
            // wychodzi do formularza, a sprawę zamknie ktoś inny.
            throw $odmowa;
        } catch (BladDlaCzlowieka) {
            // „Ta treść jest już widoczna" — nie ma czego cofać. Patrz wyżej.
            // Tylko to: `RuntimeException` połykałby tu także `QueryException`
            // (dziedziczy po nim przez `PDOException`), czyli awaria bazy
            // w środku cofania decyzji zniknęłaby bez śladu.
        }

        return null;
    }

    private function blokadaZDowodem(ModerationAction $decyzja): bool
    {
        if ($decyzja->target_type !== 'user'
            || $decyzja->subject_user_id === null
            || $decyzja->target_id !== $decyzja->subject_user_id
            || $decyzja->reason_code !== PodstawaDecyzji::KRZYWDZENIE_DZIECI
            || preg_match('/\ABlokada razem z zabezpieczeniem dowodu \(decyzja ([0-9a-f-]{36})\)\.\z/u', (string) $decyzja->note, $trafienia) !== 1) {
            return false;
        }

        return ZabezpieczenieDowodu::query()
            ->where('moderation_action_id', $trafienia[1])
            ->where('subject_user_id', $decyzja->subject_user_id)
            ->exists();
    }

    /** Czy treść tej decyzji (także wersja przepisu i zdjęcie) jest zabezpieczona jako dowód. */
    private function zabezpieczona(ModerationAction $decyzja): bool
    {
        if ($decyzja->target_type === CofniecieUkryciaWersji::TYP) {
            $przepisId = DB::table('recipe_versions')->where('id', $decyzja->target_id)->value('recipe_id');

            return is_string($przepisId) && ZabezpieczoneDowody::przepis($przepisId);
        }

        return ZabezpieczoneDowody::dotyczy($decyzja->target_type, $decyzja->target_id);
    }

    /**
     * Cofnięcie ukrycia wskazówki po uznanym odwołaniu kucharza (#2352).
     *
     * Zdejmuje wyłącznie ślad moderacji. Jeśli kucharz w międzyczasie wycofał
     * zgodę, wskazówka zostaje wycofana — jego decyzja jest ważniejsza niż
     * decyzja moderacji. Zapis `unhide` i wpis w dzienniku jak przy każdym
     * przywróceniu; powiadomienia nie ma, bo odpowiedź na odwołanie mówi to
     * samo lepiej. Własnej wskazówki moderator nie przywraca (#1479).
     *
     * Tak jak przy innych treściach „nie ma czego cofać” (wskazówki już nie ma,
     * nie była ukryta) NIE blokuje odpowiedzi na odwołanie.
     *
     * @throws WlasnejTresciNiePrzywracasz gdy rozpatrujący jest autorem uwagi
     */
    private function przywrocWskazowke(User $moderator, ModerationAction $decyzja, string $uzasadnienie, ?string $ip): void
    {
        $wskazowka = RecipeHint::zablokujDoDecyzji((string) $decyzja->target_id);

        if ($wskazowka === null) {
            return;
        }

        if ($wskazowka->cook_id === $moderator->getKey()) {
            throw new WlasnejTresciNiePrzywracasz;
        }

        if (! $wskazowka->zdejmijUkrycieModeracji()) {
            return;
        }

        $przywrocenie = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => null,
            'target_type' => 'recipe_hint',
            'target_id' => $wskazowka->getKey(),
            'subject_user_id' => $wskazowka->cook_id,
            'action' => ModerationAction::ACTION_UNHIDE,
            'previous_status' => null,
            'reason_code' => 'appeal_overturned',
            'note' => 'Cofnięte po odwołaniu.',
            'user_message' => $uzasadnienie,
        ]);

        AuditLogEntry::record(
            action: 'moderation.restored',
            actor: $moderator,
            subject: $przywrocenie,
            metadata: [
                'target_type' => 'recipe_hint',
                'target_id' => (string) $wskazowka->getKey(),
            ],
            ip: $ip,
        );
    }

    /**
     * Zdjęcie kary z konta — wyłącznie tej, której dotyczy odwołanie (#933).
     *
     * Do 24.09.2026 każde uznane odwołanie od zawieszenia albo blokady wołało
     * `reinstate()` bez pytania, CO dziś trzyma konto. Uchylenie starego
     * zawieszenia A zdejmowało więc późniejszy, niezależny ban B, którego
     * nikt nie rozpatrywał — a `reinstate()` na koncie `pending_delete`
     * albo `erased` przywracałoby do życia konto w trakcie usuwania.
     *
     * Reguła: kara schodzi tylko wtedy, gdy konto jest dziś zawieszone
     * albo zablokowane — w `status`, a w cyklu usuwania w `punishment_status`
     * (#980) — I obowiązująca kara to właśnie ta decyzja — czyli
     * najnowsza decyzja `suspend`/`ban` wobec tej osoby, której nikt dotąd
     * nie cofnął po odwołaniu. Kary nie zapisanej w `moderation_actions`
     * nie ma: zawieszenie i blokadę nakłada wyłącznie decyzja moderacyjna.
     *
     * Blokada wiersza konta PRZED odczytem obowiązującej kary: równoległa
     * decyzja nakładająca nową karę pisze do tego samego wiersza, więc albo
     * zatwierdzi się przed nami (i ją zobaczymy), albo po nas (i jej kara
     * nadpisze nasze odblokowanie). Obie kolejności kończą się stanem
     * zgodnym z nowszą decyzją.
     *
     * @return ?string dopisek do odpowiedzi, gdy decyzję cofamy, a konto
     *                 zostaje przy późniejszej karze
     */
    private function zdejmijKareKonta(ModerationAction $decyzja): ?string
    {
        if ($decyzja->subject_user_id === null) {
            return null;
        }

        $osoba = User::query()->whereKey($decyzja->subject_user_id)->lockForUpdate()->first();

        if ($osoba === null) {
            return null;
        }

        // Kara, która dziś trzyma konto. W cyklu usuwania (`pending_delete`,
        // `erased`) status mówi o usuwaniu, a kara czeka odłożona
        // w `punishment_status` (#980) — i to ją trzeba zdjąć, bo inaczej
        // uchylony ban wróciłby przy „Cofnij usunięcie konta”.
        $kara = in_array($osoba->status, [User::STATUS_PENDING_DELETE, User::STATUS_ERASED], true)
            ? $osoba->punishment_status
            : $osoba->status;

        if (! in_array($kara, [User::STATUS_SUSPENDED, User::STATUS_BANNED], true)) {
            // Konto już czynne (termin minął, kara zdjęta wcześniej) albo
            // w trakcie usuwania bez odłożonej kary — nie ma czego zdejmować.
            return null;
        }

        $obowiazujaca = ModerationAction::query()
            ->where('subject_user_id', $osoba->getKey())
            ->whereIn('action', [ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN])
            ->whereNotIn('id', Appeal::query()
                ->where('status', Appeal::STATUS_OVERTURNED)
                ->select('moderation_action_id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($obowiazujaca !== null && ! $obowiazujaca->is($decyzja)) {
            return $kara === User::STATUS_BANNED
                ? self::KONTO_ZOSTAJE_ZABLOKOWANE
                : self::KONTO_ZOSTAJE_ZAWIESZONE;
        }

        // Na koncie w cyklu usuwania `reinstate()` zdejmuje tylko karę
        // odłożoną — samo żądanie usunięcia i karencja zostają (#980).
        $osoba->reinstate();

        return null;
    }
}
