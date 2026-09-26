<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Notifications\PotwierdzenieOdwolaniaZglaszajacego;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Złożenie odwołania od decyzji PRZEZ ZGŁASZAJĄCEGO (issue #23, DSA art. 20
 * ust. 1). Odpowiednik `FileAppeal` dla drugiej strony sprawy — ta sama DSA,
 * inny człowiek, inna droga wejścia.
 *
 * CO JEST INNE NIŻ PRZY AUTORZE
 *
 *  1. ODWOŁUJE SIĘ OD DECYZJI NA SWOIM ZGŁOSZENIU, nie od decyzji dotyczącej
 *     WŁASNEGO konta — zgłaszający nie musi mieć konta wcale (art. 16
 *     ust. 2 lit. c, migracja `allow_anonymous_legal_notices`). Tożsamość,
 *     jaką tu mamy, to `Report` (imię i/albo e-mail, oba mogą być puste),
 *     nie `User`.
 *
 *  2. `no_action` JEST odwoływalne. To jest dokładnie ta decyzja, dla której
 *     zgłaszający potrzebuje tej drogi — art. 20 ust. 1 wymienia „decyzje
 *     o niepodjęciu działania" wprost.
 *     `ModerationAction::isAppealableByReporter()` dlatego nie filtruje po
 *     `action`, w przeciwieństwie do `isAppealable()` używanego przez
 *     `FileAppeal`.
 *
 *  3. AUTORYZACJA to podpisany, wygasający link (`URL::temporarySignedRoute`
 *     w `DecyzjaWSprawieZgloszenia`, sprawdzany przez middleware `signed`
 *     w `ReporterAppealController`), nie sesja ani hasło. UUID zgłoszenia
 *     w adresie i tak nie byłby autoryzacją (AGENTS.md §7) — podpis
 *     kryptograficzny jest tym, co odróżnia ten link od zgadywalnego UUID-a,
 *     i wygasa dokładnie z terminem na odwołanie.
 *
 * KOGO TO NIE OBEJMUJE
 * Zgłaszającego bez adresu e-mail. Nie ma dokąd wysłać linku, a bez linku
 * nie ma jak dotrzeć na tę stronę — art. 16 ust. 2 lit. c pozwala zgłosić
 * się bez żadnych danych, i ta anonimowość ma cenę, którą jest właśnie brak
 * kanału do systemu skarg. Nie da się tego naprawić bez naruszenia samej
 * anonimowości, o którą przepis prosi (patrz migracja
 * `2026_09_07_800000_appeals_open_to_reporters.php`).
 */
final class FileReporterAppeal
{
    public function __construct(private readonly PowiadomOOdwolaniu $powiadom = new PowiadomOOdwolaniu) {}

    public function handle(Report $zgloszenie, string $tresc, ?string $ip = null): Appeal
    {
        if (! $zgloszenie->jestZgloszeniemPrawnym()) {
            throw new BladDlaCzlowieka('To zgłoszenie nie ma systemu odwołań — dotyczy tylko zgłoszeń treści niezgodnej z prawem.');
        }

        // Jedna decyzja na zgłoszenie (`moderation_actions_one_per_report`),
        // więc to zapytanie zawsze trafia w co najwyżej jeden wiersz.
        $decyzja = ModerationAction::query()->where('report_id', $zgloszenie->getKey())->first();

        if ($decyzja === null) {
            throw new BladDlaCzlowieka('Ta sprawa nie ma jeszcze decyzji, od której można się odwołać.');
        }

        if (! $decyzja->isAppealableByReporter()) {
            throw new BladDlaCzlowieka(
                'Termin na odwołanie od tej decyzji minął '
                .$decyzja->appealDeadline()->translatedFormat('j F Y').'. '
                .'Jeśli pojawiły się nowe okoliczności, napisz na '
                .config('kuking.community.contact_email').'.',
            );
        }

        if ($decyzja->reporterAppeal()->exists()) {
            throw new BladDlaCzlowieka(
                'Odwołanie od tej decyzji już do nas trafiło. Nie trzeba wysyłać go drugi raz.',
            );
        }

        // PISMO, ZLECENIE POTWIERDZENIA I ZAWIADOMIENIA W JEDNEJ TRANSAKCJI
        // (issue #1305) — powód przy `FileAppeal`. Potwierdzenie pocztowe
        // wchodzi do niej jako WIERSZ W `jobs`: kolejka jest bazodanowa, na
        // tym samym połączeniu (`config/queue.php`), więc zlecenie wysyłki
        // zatwierdza się razem z pismem albo wcale — to ten sam outbox co
        // przy eksporcie danych (audyt A02). Samo `afterCommit()` zostawiłoby
        // okno między zatwierdzeniem pisma a zapisem zlecenia. Awaria
        // dostawcy poczty dzieje się już w workerze, PO zatwierdzeniu:
        // zlecenie zostaje w kolejce do ponowienia, a pisma nie trzeba
        // składać drugi raz. Jedno pismo = jedno zlecenie, bo zlecenie
        // powstaje tylko razem z nowym wierszem `appeals`.
        $odwolanie = DB::transaction(function () use ($zgloszenie, $decyzja, $tresc): Appeal {
            try {
                $odwolanie = Appeal::create([
                    'moderation_action_id' => $decyzja->getKey(),
                    'report_id' => $zgloszenie->getKey(),
                    'appellant' => Appeal::APPELLANT_REPORTER,
                    'body' => trim($tresc),
                    'status' => Appeal::STATUS_OPEN,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Dwa kliknięcia „Wyślij" z tego samego linku. Baza odbiła
                // drugie — i dobrze. Człowiek ma zobaczyć „mamy to", nie błąd
                // serwera (ten sam wzorzec co w `FileAppeal`).
                throw new BladDlaCzlowieka(
                    'Odwołanie od tej decyzji już do nas trafiło. Nie trzeba wysyłać go drugi raz.',
                );
            }

            // Potwierdzenie odbioru — zgłaszający nie ma sesji ani powiadomień
            // w serwisie, więc jedyny kanał to ten sam e-mail, na który poszedł
            // link. Zawsze obecny na tym etapie: bez adresu nie dałoby się
            // w ogóle dostarczyć linku, którym ta osoba tu trafiła.
            if ($zgloszenie->notifier_email !== null) {
                Notification::route('mail', $zgloszenie->notifier_email)
                    ->notify(new PotwierdzenieOdwolaniaZglaszajacego($zgloszenie));
            }

            // Zawiadomienie dla administratora — ta sama droga i ten sam powód co
            // przy odwołaniu autora (`FileAppeal`): art. 20 daje na odpowiedź
            // termin, a nie „kiedy ktoś zajrzy". Nazwa bierze się ze zgłoszenia,
            // bo zgłaszający nie musi mieć konta w ogóle (art. 16 ust. 2 lit. c).
            $this->powiadom->handle(
                $odwolanie,
                $zgloszenie->notifier_name ?? 'zgłaszający bez podanych danych',
            );

            return $odwolanie;
        });

        // Wpis pomocniczy (D-249, klasa 2) — powód przy `FileAppeal`.
        AuditLogEntry::recordBezWywracania(
            action: 'appeal.filed',
            actor: null,
            subject: $odwolanie,
            metadata: [
                'moderation_action_id' => (string) $decyzja->getKey(),
                'report_id' => (string) $zgloszenie->getKey(),
                'appellant' => Appeal::APPELLANT_REPORTER,
                'decision' => $decyzja->action,
            ],
            ip: $ip,
        );

        return $odwolanie;
    }
}
