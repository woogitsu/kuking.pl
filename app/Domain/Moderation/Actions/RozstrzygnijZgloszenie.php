<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\DlugoscZawieszenia;
use App\Domain\Moderation\ModeratedContent;
use App\Domain\Users\OdmowaOstatniegoAdministratora;
use App\Domain\Users\OstatniAdministrator;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Decyzja moderacyjna w sprawie otwartego zgłoszenia — transakcja, sankcja,
 * powiadomienia i dziennik jako JEDEN nazwany przypadek użycia.
 *
 * Wyjęte z `ModerationController::decide()` bez zmiany zachowania (issue
 * #970, krok 2): ta sama kolejność kroków, te same komunikaty, ta sama
 * transakcja. Wejście sprawdza `DecyzjaModeracyjnaRequest`; tu przychodzą
 * już zwalidowane dane.
 *
 * Każda decyzja zapisuje uzasadnienie i treść wysłaną użytkownikowi — bez
 * tego nie da się rozpatrzyć odwołania (DSA art. 17).
 *
 * Błędy dla człowieka wychodzą jako `ValidationException` na polu `action`
 * — tak jak wcześniej z kontrolera — więc kolejka wraca z błędem przy
 * decyzji i z wpisanymi danymi.
 */
final class RozstrzygnijZgloszenie
{
    public function __construct(
        private readonly NotifyModerationDecision $powiadom,
        private readonly NotifyReporterDecision $powiadomZglaszajacego,
    ) {}

    /**
     * @param  array<string, mixed>  $data  wynik `DecyzjaModeracyjnaRequest::validated()`
     * @return ModerationAction|null `null`, gdy pod blokadą okazało się, że
     *                               zgłoszenie rozstrzygnął już ktoś inny
     *
     * @throws ValidationException
     */
    public function handle(User $moderator, Report $report, array $data, ?string $ip): ?ModerationAction
    {
        $termin = $this->terminKary($data);

        /*
         * WSZYSTKO PONIŻEJ W JEDNEJ TRANSAKCJI, POD BLOKADĄ WIERSZA
         * (audyt W3-09, W6-01).
         *
         * Sprawdzenie statusu na wejściu (`DecyzjaModeracyjnaRequest`) stało
         * kiedyś samo i miało komentarz mówiący, że chroni przed podwójną
         * decyzją. Nie chroniło: między odczytem a zapisem jest okno,
         * w którym drugie żądanie widzi jeszcze `open`. Dwie karty moderatora
         * wykonywały więc dwie kary, tworzyły dwa wpisy w `moderation_actions`
         * i wysyłały dwa powiadomienia — a przy odwołaniu (DSA art. 17) log
         * przestawał być jednoznaczny. Baza też tego nie łapała:
         * `moderation_actions.report_id` nie ma ograniczenia unikalności.
         *
         * To był komentarz pewniejszy niż kod — ten sam wzorzec, który audyty
         * wskazują jako powtarzalny w tym repozytorium.
         *
         * `lockForUpdate()` wstrzymuje drugie żądanie do końca pierwszej
         * transakcji, a ponowne sprawdzenie statusu JUŻ POD BLOKADĄ rozstrzyga
         * je jednoznacznie. Powiadomienie zostaje w środku świadomie: ma nie
         * wyjść, jeśli zapis się nie powiedzie.
         */
        return DB::transaction(function () use ($report, $data, $moderator, $termin, $ip) {
            // Kara na koncie idzie pod wspólny zamek ostatniego administratora
            // (#1016) PRZED pierwszym zapisem: INSERT do `moderation_actions`
            // bierze `FOR KEY SHARE` na wierszu osoby i zamek wzięty po nim
            // zakleszczyłby się z równoległą zmianą roli.
            if (in_array($data['action'], [ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN], true)) {
                OstatniAdministrator::zablokuj();
            }

            $zablokowane = Report::query()->whereKey($report->getKey())->lockForUpdate()->first();

            if ($zablokowane === null || $zablokowane->status !== Report::STATUS_OPEN) {
                return null;
            }

            // Ta sama reguła co na wejściu, ale już na zablokowanym wierszu:
            // wynik ma zależeć od stanu, pod którym zapada decyzja (#1408).
            $wlasnaSprawa = Gate::forUser($moderator)->inspect('decide', $zablokowane);

            if ($wlasnaSprawa->denied()) {
                throw ValidationException::withMessages(['action' => $wlasnaSprawa->message()]);
            }

            // Cel i osobę wyznaczamy PRZED zapisaniem decyzji i przed jej
            // wykonaniem. Powodów są teraz dwa:
            //  - `remove` kasuje cel, a wtedy nie ma już kogo zapytać o autora;
            //  - do logu wchodzi STAN SPRZED decyzji (`previous_status`), więc
            //    trzeba go odczytać, zanim cokolwiek się zmieni. Bez tego
            //    ukrycia nie da się później cofnąć do właściwego stanu (#65).
            $cel = ModeratedContent::znajdz($report->target_type, $report->target_id, zUsunietymi: true);
            $celNiedostepny = $cel === null
                || (method_exists($cel, 'trashed') && $cel->trashed());

            if ($celNiedostepny && $data['action'] !== ModerationAction::ACTION_NONE) {
                throw ValidationException::withMessages([
                    'action' => 'Tej treści już nie ma albo nie da się jej odnaleźć. '
                        .'Wybierz „Bez działania”, aby zamknąć sprawę bez zapisywania sankcji.',
                ]);
            }

            // Brak celu nie może być cichym sukcesem wybranej sankcji. Przy
            // świadomym „Bez działania” zapisujemy osobny, prawdziwy wynik,
            // żeby odpowiedź nie twierdziła, że treść oceniono i zostawiono.
            $wykonanaAkcja = $celNiedostepny
                ? ModerationAction::ACTION_TARGET_UNAVAILABLE
                : $data['action'];
            $aktywnyCel = $celNiedostepny ? null : $cel;
            $osoba = $aktywnyCel === null ? null : ModeratedContent::osoba($aktywnyCel);

            // KARA NA KONCIE TYLKO WOBEC NIŻSZEJ ROLI (#1408, D-244).
            //
            // Sprawdzane TU, przed `ModerationAction::create()`: odmowa
            // wycofuje transakcję, więc nie zostaje decyzja, powiadomienie
            // ani wpis w dzienniku sugerujący wykonaną sankcję. Cel kary
            // bywa autorem zgłoszonej treści, nie tylko zgłoszonym profilem —
            // dlatego pytamy o `$osoba`, a nie o `target_type === 'user'`.
            if (in_array($wykonanaAkcja, [ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN], true)
                && $osoba !== null
                && $moderator->cannot('sanctionAccount', $osoba)) {
                throw ValidationException::withMessages([
                    'action' => $osoba->isAdmin()
                        ? 'Konta administratora nie da się zawiesić ani zablokować z panelu moderacji. '
                            .'Jeśli sprawa jest poważna, przekaż ją właścicielowi serwisu.'
                        : 'Konto moderatora może zawiesić albo zablokować tylko administrator. '
                            .'Wybierz inną decyzję albo przekaż sprawę administratorowi.',
                ]);
            }

            $akcja = ModerationAction::create([
                'moderator_id' => $moderator->getKey(),
                'report_id' => $report->getKey(),
                'target_type' => $report->target_type,
                'target_id' => $report->target_id,
                'subject_user_id' => $osoba?->getKey(),
                'action' => $wykonanaAkcja,
                'previous_status' => $aktywnyCel === null ? null : ($aktywnyCel->status ?? null),
                'reason_code' => $data['reason_code'],
                'note' => $data['note'] ?? null,
                'user_message' => $data['user_message'] ?? null,
            ]);

            // Odmowa strażnika wycofuje decyzję razem z transakcją: nie
            // zostaje ani wpis, ani powiadomienie o karze, której nie było.
            try {
                $this->applyAction($aktywnyCel, $osoba, $wykonanaAkcja, $termin);
            } catch (OdmowaOstatniegoAdministratora $odmowa) {
                throw ValidationException::withMessages(['action' => $odmowa->getMessage()]);
            }

            // Powiadomienie o decyzji. Dopóki go nie było, `user_message` lądowała
            // wyłącznie w logu moderacji: dokumentacja twierdziła, że autora
            // poinformowano, a autor nie dostawał niczego (audyt A16).
            //
            // Dotyczyło to WSZYSTKICH decyzji zapisujących `user_message`, nie
            // tylko `warn` — `hide`, `remove`, `suspend` i `ban` milczały tak samo.
            if ($osoba !== null) {
                $this->powiadom->handle(
                    osoba: $osoba,
                    decyzja: $wykonanaAkcja,
                    wiadomoscModeratora: $data['user_message'] ?? null,
                    do: $termin,
                    // Bez tego powiadomienie mówi „możesz się odwołać" i nie ma
                    // gdzie kliknąć — a formularz odwołania musi wiedzieć,
                    // KTÓREJ decyzji dotyczy (#10).
                    decyzjaModeracyjna: $akcja,
                );
            }

            // POWIADOMIENIE ZGŁASZAJĄCEGO (DSA art. 16 ust. 5) — audyt W5-02.
            //
            // Osobny obowiązek od tego wyżej: tamto idzie do AUTORA treści
            // (art. 17), to do osoby, która zgłosiła. Do tej pory zgłaszający
            // nie dowiadywał się niczego, nawet tego, że sprawa jest zamknięta,
            // a ekran obiecywał „odpiszemy Ci, co zrobiliśmy".
            //
            // DWA KANAŁY, BO SĄ DWIE DROGI ZGŁOSZENIA — i to jest cała
            // różnica między nimi, nie dwa różne obowiązki (issue #10):
            //
            //  - zgłoszenie prawne (`legal_notice`) przychodzi od osoby, która
            //    może nie mieć konta (art. 16 ust. 2 lit. c), więc jedynym
            //    kanałem jest adres e-mail, jeśli go podała;
            //  - zgłoszenie społecznościowe przychodzi zawsze od osoby
            //    zalogowanej (`reports.create` stoi w grupie `auth`), więc
            //    kanałem jest powiadomienie w serwisie — czytelne także
            //    wtedy, gdy serwis nie ma jeszcze SMTP.
            //
            // Warunki wykluczają się nawzajem: `maAdresDoOdpowiedzi()` wymaga
            // `source = legal_notice`, a `reporter_id` ustawia wyłącznie droga
            // społecznościowa (`ReportContent`). Nikt nie dostanie odpowiedzi
            // dwa razy.
            if ($zablokowane->maAdresDoOdpowiedzi()) {
                Notification::route('mail', $zablokowane->notifier_email)
                    ->notify(new DecyzjaWSprawieZgloszenia($zablokowane, $akcja));

                $zablokowane->forceFill(['decision_sent_at' => now()])->save();
            }

            $this->powiadomZglaszajacego->handle($zablokowane, $akcja);

            $zablokowane->update([
                'status' => $wykonanaAkcja === ModerationAction::ACTION_NONE
                    ? Report::STATUS_REJECTED
                    : Report::STATUS_RESOLVED,
                'resolution_note' => $data['note'] ?? null,
                'resolved_by' => $moderator->getKey(),
                'resolved_at' => now(),
            ]);

            AuditLogEntry::record(
                action: 'moderation.decided',
                actor: $moderator,
                subject: $zablokowane,
                metadata: [
                    'decision' => $wykonanaAkcja,
                    'reason_code' => $data['reason_code'],
                    'suspend_days' => $data['suspend_days'] ?? null,
                    // Sam wybór z listy przestał wystarczać, odkąd jedną z
                    // pozycji jest „własny termin": `wlasny` bez daty nie
                    // mówi w logu nic. Zapisujemy więc TERMIN, który
                    // naprawdę trafił na konto — to jest ta liczba, o którą
                    // pyta się przy odwołaniu (DSA art. 20).
                    'suspend_until' => $termin?->toIso8601String(),
                ],
                ip: $ip,
            );

            return $akcja;
        });
    }

    /**
     * Zamiana wyboru z formularza na konkretną datę.
     *
     * `null` znaczy „bezterminowo, do decyzji człowieka" — i tak ma zostać
     * przy `bezterminowo` oraz przy każdej decyzji innej niż zawieszenie.
     *
     * TU JEST MIEJSCE, W KTÓRYM LICZBA DNI JEST IGNOROWANA po cichu: przy
     * decyzji innej niż „Zawieś konto" wychodzimy od razu i nie zaglądamy ani
     * do wyboru, ani do liczby. Moderator, który zaznaczył termin, a potem
     * zmienił decyzję na ostrzeżenie, nie dostaje za to błędu.
     *
     * @param  array<string, mixed>  $data
     */
    private function terminKary(array $data): ?CarbonInterface
    {
        if (($data['action'] ?? null) !== ModerationAction::ACTION_SUSPEND) {
            return null;
        }

        $wybor = $data['suspend_days'] ?? null;
        $dni = $data['suspend_days_custom'] ?? null;

        return DlugoscZawieszenia::termin(
            is_string($wybor) ? $wybor : null,
            is_numeric($dni) ? (int) $dni : null,
        );
    }

    /**
     * Wykonanie decyzji na obiekcie.
     *
     * `hide` ukrywa treść (da się przywrócić), `remove` usuwa miękko.
     * Automat nigdy nie banuje sam — ban jest zawsze decyzją człowieka.
     *
     * @param  ?User  $osoba  autor zgłoszonej treści albo zgłoszona osoba.
     *                        Kara dotyczy CZŁOWIEKA, więc przy zgłoszonej treści
     *                        sięgamy po jej autora — wcześniej „Zawieś konto" na
     *                        zgłoszonym wpisie tylko ukrywało wpis, a konto
     *                        zostawało aktywne.
     */
    private function applyAction(?object $target, ?User $osoba, string $action, ?CarbonInterface $do = null): void
    {
        if ($action === ModerationAction::ACTION_NONE || $target === null) {
            return;
        }

        match ($action) {
            ModerationAction::ACTION_HIDE => $this->hide($target),

            // `remove` nie jest dozwolone dla celu `user` (macierz
            // ModerationAction::DOZWOLONE), więc tu nigdy nie trafi konto.
            // Wcześniej trafiało i kasowało je bezpowrotnie.
            ModerationAction::ACTION_REMOVE => $target->delete(),

            ModerationAction::ACTION_SUSPEND => $osoba?->suspend($do),
            ModerationAction::ACTION_BAN => $osoba?->ban(),

            // `warn` nie robi nic z treścią ani z kontem — całą jego siłą jest
            // wiadomość do autora. Wysyła ją `NotifyModerationDecision`
            // w `handle()`, wspólnie dla wszystkich decyzji.
            default => null,
        };
    }

    /**
     * Ukrycie treści.
     *
     * Status bierzemy z `ModeratedContent::UKRYTY`, a nie z łańcucha
     * `instanceof`. Poprzednia wersja miała gałęzie dla trzech modeli i cicho
     * przepuszczała czwarty („Ugotowałem"): moderator klikał „Ukryj treść",
     * zgłoszenie robiło się rozstrzygnięte, autor dostawał powiadomienie
     * „ukryliśmy Twoją treść" — a treść stała dalej. Teraz `cooked_event`
     * w ogóle nie ma tej decyzji na liście (`ModerationAction::DOZWOLONE`),
     * a gdyby ktoś ją tam dopisał, ten warunek nadal nie da się oszukać.
     */
    private function hide(object $target): void
    {
        if (! ModeratedContent::daSieUkryc($target)) {
            return;
        }

        $target->forceFill(['status' => ModeratedContent::UKRYTY[$target::class]])->save();
    }
}
