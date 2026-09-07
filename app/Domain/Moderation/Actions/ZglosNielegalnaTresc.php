<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Models\Report;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Przyjęcie zgłoszenia nielegalnej treści (DSA art. 16).
 *
 * DLACZEGO OSOBNO OD `ReportContent`
 * Tamta akcja obsługuje zgłoszenie SPOŁECZNOŚCIOWE: wymaga rozpoznanego celu
 * w bazie, scala duplikaty tego samego zgłaszającego i nie ma żadnych
 * obowiązków wobec niego poza tym, że ktoś to przeczyta.
 *
 * Zgłoszenie prawne jest inne w trzech miejscach naraz i to jest cały powód
 * tej klasy:
 *
 *  1. PRZYCHODZI OD KOGOŚ BEZ KONTA. Prawnik, rodzic, osoba, która rozpoznała
 *     siebie na cudzym zdjęciu. Nie wolno kazać im zakładać konta w serwisie
 *     kulinarnym po to, żeby mogli zgłosić przestępstwo.
 *
 *  2. ADRES MOŻE SIĘ NIE ROZWIĄZAĆ. Ktoś wkleja link z pamięci albo ze
 *     zrzutu ekranu; treść mogła już zniknąć. Zgłoszenie i tak MUSI zostać
 *     przyjęte — odrzucenie go, bo nie rozpoznaliśmy adresu, byłoby
 *     odmówieniem mechanizmu, który przepis nakazuje udostępnić. Zapisujemy
 *     więc adres tak, jak go wpisano, i zostawiamy moderatorowi.
 *
 *  3. NALEŻY SIĘ ODPOWIEDŹ. Potwierdzenie odbioru bez zbędnej zwłoki
 *     (art. 16 ust. 4), a potem powiadomienie o decyzji z pouczeniem
 *     o środkach odwoławczych (ust. 5).
 *
 * NIE SCALAMY DUPLIKATÓW. Przy zgłoszeniu społecznościowym scalanie ma sens:
 * ta sama osoba klika dwa razy. Tutaj dwa zgłoszenia tej samej treści mogą
 * pochodzić od dwóch różnych osób, z dwóch różnych podstaw prawnych, i każdej
 * z nich należy się osobna odpowiedź.
 */
final class ZglosNielegalnaTresc
{
    /**
     * @param  string|null  $imie  NULL jest dopuszczalny — patrz niżej
     * @param  string|null  $email  NULL jest dopuszczalny — art. 16 ust. 2
     *                              lit. c zwalnia z podania DANYCH
     *                              zgłaszającego (nie tylko adresu) przy
     *                              zgłoszeniach dotyczących przestępstw
     *                              z art. 3-7 dyrektywy 2011/93/UE.
     *                              Zgłoszenie anonimowe to nie zgłoszenie
     *                              puste: uzasadnienie i dobra wiara zostają
     *                              wymagane, także w CHECK-u w bazie
     *                              (migracja `allow_anonymous_legal_notices`).
     */
    public function handle(
        ?string $imie,
        ?string $email,
        string $adres,
        string $uzasadnienie,
        string $powod,
        ?string $typCelu = null,
        ?string $idCelu = null,
    ): Report {
        $zgloszenie = DB::transaction(fn (): Report => Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'notifier_name' => $imie,
            'notifier_email' => $email,
            // Adres mógł się nie rozwiązać — wtedy typ to `unknown`, a cel
            // zostaje PUSTY. Kusiło, żeby wpisać tam UUID z samych zer, ale
            // to byłoby kłamstwo w kolumnie: identyfikator, który wygląda jak
            // identyfikator i niczego nie wskazuje. Kolejka moderatora
            // pokazuje wtedy sam adres, tak jak go wpisano.
            'target_type' => $typCelu ?? 'unknown',
            'target_id' => $idCelu,
            'target_url' => $adres,
            'reason' => $powod,
            'illegality_explanation' => $uzasadnienie,
            // Znacznik czasu, nie `boolean`: przy sporze liczy się, KIEDY
            // oświadczenie o dobrej wierze złożono.
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]));

        // POTWIERDZENIE ODBIORU (art. 16 ust. 4) — poza transakcją.
        //
        // Wysyłka listu nie może wycofać zapisanego zgłoszenia, a zapisane
        // zgłoszenie nie może zależeć od tego, czy poczta akurat działa.
        // Powiadomienie jest kolejkowane, więc awaria serwera poczty ląduje
        // w `failed_jobs`, a nie na ekranie człowieka.
        if ($zgloszenie->notifier_email !== null) {
            Notification::route('mail', $zgloszenie->notifier_email)
                ->notify(new PotwierdzenieZgloszeniaNielegalnejTresci($zgloszenie));

            $zgloszenie->forceFill(['receipt_sent_at' => now()])->save();
        }

        return $zgloszenie;
    }
}
