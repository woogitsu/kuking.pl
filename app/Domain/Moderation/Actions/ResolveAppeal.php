<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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
 * GRANICA, KTÓREJ TA KLASA ŚWIADOMIE NIE PRZESUWA: cofnięcie decyzji
 * `no_action` po odwołaniu ZGŁASZAJĄCEGO nie ma dziś żadnego mechanicznego
 * odpowiednika — `cofnij()` niżej poprawnie nic nie robi (nic nie było
 * ukryte), a rzeczywiste podjęcie działania wobec zgłoszonej treści
 * wymagałoby NOWEJ decyzji moderacyjnej na już rozstrzygniętym zgłoszeniu,
 * czego `moderation_actions_one_per_report` i `ModerationController::decide()`
 * dziś nie dopuszczają. To jest świadomie zostawiona granica tej zmiany, nie
 * przeoczenie: naprawia dostęp do systemu skarg (art. 20), nie dodaje
 * mechanizmu ponownego rozpatrzenia zgłoszenia. Jeśli moderator uzna
 * odwołanie za zasadne, realne działanie na treści wykonuje dziś ręcznie,
 * tak jak każdą decyzję poza tym systemem — `decision_note` jest miejscem,
 * w którym mówi zgłaszającemu, co konkretnie zrobi.
 */
final class ResolveAppeal
{
    public function __construct(
        private readonly RestoreContent $przywroc,
        private readonly NotifyAppealOutcome $powiadom,
        private readonly NotifyReporterAppealOutcome $powiadomZglaszajacego,
        private readonly NotifyReporterDecisionChanged $skorygujZglaszajacemu,
    ) {}

    /**
     * @param  string  $wynik  Appeal::STATUS_UPHELD albo Appeal::STATUS_OVERTURNED
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
        Gate::forUser($moderator)->authorize('resolveAppeals', User::class);

        if (! $odwolanie->isOpen()) {
            throw new BladDlaCzlowieka('To odwołanie zostało już rozpatrzone. Odśwież stronę, żeby zobaczyć odpowiedź.');
        }

        if (! in_array($wynik, [Appeal::STATUS_UPHELD, Appeal::STATUS_OVERTURNED], true)) {
            throw new BladDlaCzlowieka('Wybierz, czy podtrzymujesz decyzję, czy ją cofasz.');
        }

        $uzasadnienie = trim($uzasadnienie);

        if ($uzasadnienie === '') {
            throw new BladDlaCzlowieka('Napisz, dlaczego tak decydujesz. Bez tego nie da się wysłać odpowiedzi.');
        }

        $decyzja = $odwolanie->moderationAction;

        if ($wynik === Appeal::STATUS_UPHELD) {
            $this->sprawdzKarencje($moderator, $decyzja);
        }

        if ($wynik === Appeal::STATUS_OVERTURNED) {
            $this->cofnij($moderator, $decyzja, $uzasadnienie, $ip);
        }

        $odwolanie->update([
            'status' => $wynik,
            'decided_by' => $moderator->getKey(),
            'decision_note' => $uzasadnienie,
            'decided_at' => now(),
        ]);

        $odwolanie->refresh();

        if ($odwolanie->isFromReporter()) {
            $this->powiadomZglaszajacego->handle($odwolanie);
        } else {
            $this->powiadom->handle($odwolanie);
            // Druga strona sprawy (#1024): zgłaszający dostał „treści nie
            // ma", a po cofnięciu treść wraca. Klasa sama sprawdza, czy
            // skutek naprawdę się zmienił — przy `upheld` nic nie robi.
            $this->skorygujZglaszajacemu->handle($odwolanie);
        }

        AuditLogEntry::record(
            action: 'appeal.resolved',
            actor: $moderator,
            subject: $odwolanie,
            metadata: [
                'outcome' => $wynik,
                'appellant' => $odwolanie->appellant,
                'original_decision' => $decyzja->action,
                'original_moderator_id' => (string) $decyzja->moderator_id,
            ],
            ip: $ip,
        );

        return $odwolanie;
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
    private function cofnij(User $moderator, ModerationAction $decyzja, string $uzasadnienie, ?string $ip): void
    {
        if (in_array($decyzja->action, [ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN], true)) {
            $decyzja->subject?->reinstate();

            return;
        }

        if (! in_array($decyzja->action, [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE], true)) {
            // `warn` nie zrobiło nic z treścią ani z kontem — cofnięcie jest
            // w całości treścią odpowiedzi.
            return;
        }

        $tresc = ModeratedContent::znajdz($decyzja->target_type, $decyzja->target_id, zUsunietymi: true);

        if ($tresc === null || ! ModeratedContent::daSieUkryc($tresc)) {
            return;
        }

        try {
            $this->przywroc->handle(
                moderator: $moderator,
                target: $tresc,
                reasonCode: 'appeal_overturned',
                note: 'Cofnięte po odwołaniu.',
                userMessage: $uzasadnienie,
                ip: $ip,
                // Odpowiedź na odwołanie idzie osobno i mówi to samo lepiej.
                zPowiadomieniem: false,
            );
        } catch (BladDlaCzlowieka) {
            // „Ta treść jest już widoczna" — nie ma czego cofać. Patrz wyżej.
            // Tylko to: `RuntimeException` połykałby tu także `QueryException`
            // (dziedziczy po nim przez `PDOException`), czyli awaria bazy
            // w środku cofania decyzji zniknęłaby bez śladu.
        }
    }
}
