<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Models\Report;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *
 * ALE JEDNO KLIKNIĘCIE TO JEDNA SPRAWA (decyzja właściciela, ADR
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`, pytanie P4).
 *
 * To nie jest sprzeczność z akapitem wyżej, bo mówi o czym innym: tam o dwóch
 * WYSŁANIACH tej samej treści, tu o jednym wysłaniu policzonym dwa razy.
 * Zmierzone (ADR §1.4.3): indeks `reports_one_open_per_pair` tych wierszy nie
 * widzi, bo `reporter_id` jest tu `NULL` — więc podwójne kliknięcie zakładało
 * DRUGĄ sprawę, z własnym terminem odpowiedzi z art. 16 i własną decyzją do
 * wydania. Dlatego formularz niesie `klucz_wyslania`, a tabela ma na nim
 * częściowy indeks UNIQUE.
 *
 * Nieznany klucz albo brak klucza znaczy „przyjmij normalnie", nigdy
 * „odmawiam" (ADR §4.3): odmowa zamknęłaby drogę, którą przepis nakazuje
 * udostępnić każdemu, i uderzyłaby najmocniej w osoby z najstarszymi
 * przeglądarkami.
 */
final class ZglosNielegalnaTresc
{
    public function __construct(private readonly AlarmujOPilnymZgloszeniu $alarm) {}

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
     * @param  string|null  $kluczWyslania  tożsamość TEGO wysłania formularza; `null` znaczy
     *                                      „nie wiemy, przyjmuj normalnie"
     */
    public function handle(
        ?string $imie,
        ?string $email,
        string $adres,
        string $uzasadnienie,
        string $powod,
        ?string $typCelu = null,
        ?string $idCelu = null,
        ?string $kluczWyslania = null,
    ): Report {
        $zapisz = fn (?string $klucz): Report => DB::transaction(fn (): Report => Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'klucz_wyslania' => $klucz,
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

        try {
            $zgloszenie = $zapisz($kluczWyslania);
        } catch (UniqueConstraintViolationException $e) {
            if ($kluczWyslania === null) {
                // Bez klucza nie ma jak odbić się o
                // `reports_one_per_klucz_wyslania` — to inne ograniczenie
                // i nie wolno go tu wyciszyć, bo zgłoszenie prawne, które
                // po cichu nie powstało, jest najgorszym z możliwych skutków.
                throw $e;
            }

            $istniejace = $this->zgloszenieZTegoWyslania($kluczWyslania, $adres, $powod, $uzasadnienie);

            if ($istniejace !== null) {
                // Drugie kliknięcie ma być nieodróżnialne od pierwszego:
                // ten sam wiersz, ten sam numer sprawy na ekranie i ANI JEDNO
                // potwierdzenie odbioru więcej (art. 16 ust. 4 mówi o jednym
                // potwierdzeniu jednej sprawy, nie o liście na każde
                // kliknięcie).
                return $istniejace;
            }

            // Klucz jest zajęty, ale nie przez to zgłoszenie. Przyjmujemy
            // sprawę BEZ klucza — nigdy nie oddajemy cudzego wiersza ani
            // cudzego numeru sprawy, i nigdy nie odmawiamy przyjęcia.
            $zgloszenie = $zapisz(null);
        }

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

        // ALARM DO MODERATORA — ta sama reguła i ta sama klasa, co przy
        // zgłoszeniu społecznościowym. Ta droga jest OTWARTA DLA KAŻDEGO,
        // także bez konta (art. 16 ust. 1), więc to właśnie tędy przychodzi
        // zgłoszenie od kogoś, kto nie ma u nas konta i nie założy go po to,
        // żeby zgłosić przestępstwo. Cisza po tej stronie byłaby najgorsza.
        //
        // Poza transakcją i PO potwierdzeniu odbioru: przyjęte zgłoszenie
        // nie może zależeć od tego, czy alarm wyszedł. Zwróconej wartości
        // nie sprawdzamy — pusty `alarm_email` znaczy „bez poczty" i jest
        // normalnym stanem (patrz `AlarmujOPilnymZgloszeniu`).
        $this->alarm->handle($zgloszenie);

        return $zgloszenie;
    }

    /**
     * Zgłoszenie przyjęte z TEGO wysłania formularza — jeśli zostało przyjęte.
     *
     * Klucz nie wystarcza sam: przy zgłoszeniu bez konta nie ma kolumny, która
     * mówiłaby, KTO wysłał formularz, więc gdyby ktoś podstawił cudzą wartość
     * klucza, oddanie tamtego wiersza pokazałoby mu numer cudzej sprawy. UUID
     * w żądaniu nie jest autoryzacją (`AGENTS.md` §7). Dlatego wiersz musi
     * zgadzać się także treścią zgłoszenia — dla prawdziwego podwójnego
     * kliknięcia jest identyczna, bo to bajt w bajt to samo żądanie.
     */
    private function zgloszenieZTegoWyslania(
        ?string $kluczWyslania,
        string $adres,
        string $powod,
        string $uzasadnienie,
    ): ?Report {
        if ($kluczWyslania === null) {
            return null;
        }

        return Report::query()
            ->where('klucz_wyslania', $kluczWyslania)
            ->where('source', Report::SOURCE_LEGAL_NOTICE)
            ->whereNull('reporter_id')
            ->where('target_url', $adres)
            ->where('reason', $powod)
            ->where('illegality_explanation', $uzasadnienie)
            ->first();
    }
}
