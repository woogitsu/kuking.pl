<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\User;

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
 */
final class ResolveAppeal
{
    public function __construct(
        private readonly RestoreContent $przywroc,
        private readonly NotifyAppealOutcome $powiadom,
    ) {}

    /**
     * @param  string  $wynik  Appeal::STATUS_UPHELD albo Appeal::STATUS_OVERTURNED
     *
     * @throws BladDlaCzlowieka gdy odwołania nie wolno teraz zamknąć
     */
    public function handle(
        User $moderator,
        Appeal $odwolanie,
        string $wynik,
        string $uzasadnienie,
        ?string $ip = null,
    ): Appeal {
        if (! $odwolanie->isOpen()) {
            throw new BladDlaCzlowieka('To odwołanie zostało już rozpatrzone. Odśwież stronę, żeby zobaczyć odpowiedź.');
        }

        if (! in_array($wynik, [Appeal::STATUS_UPHELD, Appeal::STATUS_OVERTURNED], true)) {
            throw new BladDlaCzlowieka('Wybierz, czy podtrzymujesz decyzję, czy ją cofasz.');
        }

        $uzasadnienie = trim($uzasadnienie);

        if ($uzasadnienie === '') {
            throw new BladDlaCzlowieka('Napisz, dlaczego tak zdecydowałeś. Bez tego nie da się wysłać odpowiedzi.');
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

        $this->powiadom->handle($odwolanie->refresh());

        AuditLogEntry::record(
            action: 'appeal.resolved',
            actor: $moderator,
            subject: $odwolanie,
            metadata: [
                'outcome' => $wynik,
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
