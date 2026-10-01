<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\OdpowiedzDlaZglaszajacego;
use App\Domain\Moderation\ZmianaDecyzjiPoOdwolaniu;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\Notification;
use App\Notifications\ZmianaDecyzjiWSprawieZgloszenia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Poczta;

/**
 * KOREKTA DLA ZGŁASZAJĄCEGO PO COFNIĘCIU DECYZJI W ODWOŁANIU AUTORA (#1024).
 *
 * Pierwsza odpowiedź (`NotifyReporterDecision` albo list
 * `DecyzjaWSprawieZgloszenia`) mówiła „treść nie jest już dostępna". Po
 * wygranym odwołaniu autora treść wraca — i zgłaszający ma się o tym
 * dowiedzieć tym samym kanałem, którym dostał pierwszą odpowiedź (DSA
 * art. 16 ust. 5: informacja o decyzji, czyli także o jej zmianie).
 *
 * PIERWSZEGO POWIADOMIENIA NIE RUSZAMY. Opisuje decyzję, która naprawdę
 * zapadła; korekta jest NOWYM wpisem obok, a aktualny skutek na karcie
 * sprawy liczy `ZmianaDecyzjiPoOdwolaniu` z logu, nie z powiadomień.
 *
 * JEDNA KOREKTA NA ODWOŁANIE — W SERWISIE I W LIŚCIE (#2380).
 * Akcja bierze blokadę wiersza odwołania (`FOR UPDATE`) i dopiero pod nią
 * sprawdza, czy korekta już poszła. W serwisie kluczem jest
 * `data.zmiana_po_odwolaniu`, w liście — wpis dziennika
 * `appeal.reporter_correction_mailed` z odwołaniem jako przedmiotem
 * (adresu w nim nie ma; jest przy zgłoszeniu). Drugie wywołanie — równoległe
 * albo ponowione po sukcesie — czeka na pierwsze i zastaje ślad, więc nie
 * dokłada drugiej korekty ani drugiego listu.
 *
 * `ResolveAppeal` woła tę klasę już pod tą samą blokadą (#950), więc tam
 * blokada jest ponowna w tej samej transakcji i nic nie kosztuje. Własną
 * blokadę klasa ma po to, żeby obietnica „jedna korekta” nie zależała od
 * tego, KTO ją woła — do 1 października 2026 sprawdzenie „już jest” stało
 * bez blokady, a dwa bezpośrednie wywołania dawały dwa wpisy (pomiar:
 * `tests/Dwa/KorektaDlaZglaszajacegoNaDwochPolaczeniachTest.php`).
 *
 * List idzie po zatwierdzeniu transakcji (`afterCommit`), a znacznik
 * zapisuje się w tej samej transakcji co zakolejkowanie — wycofane
 * rozpatrzenie nie wyśle listu i nie zostawi znacznika. Ponowienie samego
 * zadania kolejki po wysłaniu to osobna, ogólna granica kolejki, nie tej klasy.
 *
 * Zgłoszenie anonimowe bez adresu nie ma kanału — i nie próbujemy go szukać.
 */
final class NotifyReporterDecisionChanged
{
    public const ZNACZNIK_LISTU = 'appeal.reporter_correction_mailed';

    public function handle(Appeal $odwolanie): void
    {
        DB::transaction(function () use ($odwolanie): void {
            // Stan czytany POD blokadą, nie z obiektu, który przyszedł
            // z zewnątrz — ten mógł być wczytany przed cudzym zapisem.
            $zablokowane = Appeal::query()->whereKey($odwolanie->getKey())->lockForUpdate()->first();

            if ($zablokowane !== null) {
                $this->skoryguj($zablokowane);
            }
        });
    }

    private function skoryguj(Appeal $odwolanie): void
    {
        if ($odwolanie->isFromReporter() || $odwolanie->status !== Appeal::STATUS_OVERTURNED) {
            return;
        }

        $decyzja = $odwolanie->moderationAction;
        $zgloszenie = $decyzja?->report;

        if ($decyzja === null || $zgloszenie === null || ZmianaDecyzjiPoOdwolaniu::czyZmieniona($decyzja) === null) {
            return;
        }

        $skutek = OdpowiedzDlaZglaszajacego::skutekPoZmianie();

        if ($zgloszenie->reporter_id !== null) {
            $juzJest = Notification::query()
                ->where('user_id', $zgloszenie->reporter_id)
                ->where('type', Notification::TYPE_REPORT_DECIDED)
                ->where('data->zmiana_po_odwolaniu', (string) $odwolanie->getKey())
                ->exists();

            if (! $juzJest) {
                Notification::create([
                    'user_id' => $zgloszenie->reporter_id,
                    'actor_id' => null,
                    'type' => Notification::TYPE_REPORT_DECIDED,
                    'data' => [
                        'report_id' => (string) $zgloszenie->getKey(),
                        'numer_sprawy' => $zgloszenie->numer_sprawy,
                        'naglowek' => $skutek['naglowek'],
                        'reszta' => $skutek['reszta'],
                        // Retencja liczy termin od tej samej decyzji co
                        // pierwsza odpowiedź (`terminOchronyOdwolawczej()`).
                        'action_id' => (string) $decyzja->getKey(),
                        // Klucz jednej korekty. Sam identyfikator — widok
                        // go nie pokazuje i nic nie mówi o autorze.
                        'zmiana_po_odwolaniu' => (string) $odwolanie->getKey(),
                    ],
                ]);
            }

            return;
        }

        if (! $zgloszenie->maAdresDoOdpowiedzi()) {
            return;
        }

        $listJuzPoszedl = AuditLogEntry::query()
            ->where('action', self::ZNACZNIK_LISTU)
            ->where('subject_type', class_basename($odwolanie))
            ->where('subject_id', $odwolanie->getKey())
            ->exists();

        if ($listJuzPoszedl) {
            return;
        }

        Poczta::route('mail', $zgloszenie->notifier_email)
            ->notify(new ZmianaDecyzjiWSprawieZgloszenia($zgloszenie));

        AuditLogEntry::record(
            action: self::ZNACZNIK_LISTU,
            subject: $odwolanie,
            metadata: ['report_id' => (string) $zgloszenie->getKey()],
        );
    }
}
