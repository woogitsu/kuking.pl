<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Złożenie odwołania od decyzji moderacyjnej PRZEZ AUTORA TREŚCI (issue #10,
 * DSA art. 20). Zgłaszający ma osobną klasę — `FileReporterAppeal`
 * (issue #23) — bo wchodzi inną drogą (podpisany link z maila, nie sesja),
 * odwołuje się od INNEGO zestawu decyzji (włącznie z `no_action`) i nie ma
 * gwarancji posiadania konta.
 *
 * TRZY REGUŁY, KTÓRE MUSZĄ ŻYĆ TUTAJ, A NIE W KONTROLERZE
 *
 * Odwołanie wchodzi DWIEMA drogami — formularzem osoby zalogowanej i
 * formularzem przed logowaniem, dla osób zablokowanych. Reguła zapisana
 * w jednym kontrolerze byłaby regułą omijalną przez drugi.
 *
 *  1. JEDNO ODWOŁANIE NA JEDNĄ DECYZJĘ. Bez limitu jedna sprawa potrafi
 *     zająć jedynego moderatora na tydzień, a piąte pismo w tej samej sprawie
 *     nie wnosi nowych faktów. DSA art. 20 wymaga dostępu do wewnętrznego
 *     rozpatrzenia skargi, nie nieskończonej liczby instancji. Pilnuje tego
 *     także `UNIQUE (moderation_action_id)` w bazie — tu sprawdzamy po to,
 *     żeby człowiek dostał zdanie po polsku zamiast błędu 500.
 *
 *  2. TERMIN 14 DNI OD DECYZJI. Tyle obiecuje każdy szablon wiadomości
 *     w `docs/legal/MODERATION_PLAYBOOK.md` §4. Obietnica bez egzekucji jest
 *     gorsza niż jej brak: po roku i tak odpowiedzielibyśmy „za późno",
 *     tylko po zmarnowaniu komuś nadziei.
 *
 *  3. ODWOŁUJE SIĘ TEN, KOGO DECYZJA DOTYCZY. `moderation_actions.id` jest
 *     w adresie formularza, a UUID w adresie NIE JEST autoryzacją
 *     (AGENTS.md §7).
 */
final class FileAppeal
{
    public function __construct(private readonly PowiadomOOdwolaniu $powiadom = new PowiadomOOdwolaniu) {}

    public function handle(User $osoba, ModerationAction $decyzja, string $tresc, ?string $ip = null): Appeal
    {
        if ($decyzja->subject_user_id === null || $decyzja->subject_user_id !== $osoba->getKey()) {
            throw new BladDlaCzlowieka('Ta decyzja nie dotyczy Twojego konta.');
        }

        if (! in_array($decyzja->action, ModerationAction::ODWOLYWALNE, true)) {
            throw new BladDlaCzlowieka('Od tej decyzji nie ma odwołania — nic nie zostało ograniczone.');
        }

        if ($decyzja->authorAppeal()->exists()) {
            throw new BladDlaCzlowieka(
                'Odwołanie od tej decyzji już do nas trafiło. Odpowiemy na nie w ciągu '
                .config('kuking.moderation.appeal_response_working_days').' dni roboczych. '
                .'Jeśli pojawiły się nowe okoliczności, napisz na '
                .config('kuking.community.contact_email').'.',
            );
        }

        if (! $decyzja->appealDeadline()->isFuture()) {
            throw new BladDlaCzlowieka(
                'Termin na odwołanie od tej decyzji minął '
                .$decyzja->appealDeadline()->translatedFormat('j F Y').'. '
                .'Jeśli pojawiły się nowe okoliczności, napisz na '
                .config('kuking.community.contact_email').'.',
            );
        }

        // PISMO I ZAWIADOMIENIA ADMINISTRATORÓW W JEDNEJ TRANSAKCJI (issue
        // #1305). Wcześniej każdy zapis zatwierdzał się osobno: awaria przy
        // drugim zawiadomieniu zostawiała odwołanie złożone, o którym część
        // zespołu się nie dowiadywała — a ponowienie odbijało się o „już do
        // nas trafiło”, więc nie było jak tego naprawić. Teraz albo powstaje
        // pismo razem z zawiadomieniami, albo nic i człowiek może bezpiecznie
        // wysłać je jeszcze raz.
        $odwolanie = DB::transaction(function () use ($osoba, $decyzja, $tresc): Appeal {
            try {
                $odwolanie = Appeal::create([
                    'moderation_action_id' => $decyzja->getKey(),
                    'user_id' => $osoba->getKey(),
                    'appellant' => Appeal::APPELLANT_AUTHOR,
                    'body' => trim($tresc),
                    'status' => Appeal::STATUS_OPEN,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Dwa kliknięcia „Wyślij" albo dwie zakładki. Baza odbiła drugie —
                // i dobrze. Człowiek ma zobaczyć „mamy to", nie błąd serwera.
                throw new BladDlaCzlowieka(
                    'Odwołanie od tej decyzji już do nas trafiło. Nie trzeba wysyłać go drugi raz.',
                );
            }

            // Zawiadomienie dla administratora — odwołanie ma termin (DSA art. 20)
            // i kolejka, o której nikt nie wie, że coś w niej leży, to termin,
            // który upływa po cichu. Stoi TUTAJ, a nie w kontrolerze, bo odwołanie
            // wchodzi dwiema drogami (formularz osoby zalogowanej i formularz
            // przed logowaniem, dla osób zablokowanych) — reguła zapisana
            // w jednym kontrolerze byłaby regułą omijalną przez drugi, dokładnie
            // jak trzy reguły opisane na górze tej klasy.
            $this->powiadom->handle($odwolanie, $osoba->displayName());

            return $odwolanie;
        });

        // Wpis pomocniczy (D-249, klasa 2) — jak `content.reported`: to
        // czynność samego człowieka, a jej pełny ślad (kto, od czego, kiedy)
        // stoi w wierszu `appeals`. Awaria dziennika nie może cofnąć pisma
        // z biegnącym terminem ani zamienić go w błąd po zatwierdzeniu.
        AuditLogEntry::recordBezWywracania(
            action: 'appeal.filed',
            actor: $osoba,
            subject: $odwolanie,
            metadata: [
                'moderation_action_id' => (string) $decyzja->getKey(),
                'decision' => $decyzja->action,
            ],
            ip: $ip,
        );

        return $odwolanie;
    }
}
