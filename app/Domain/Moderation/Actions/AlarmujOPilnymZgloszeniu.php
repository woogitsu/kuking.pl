<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\HumanUrgentAlarmAttempt;
use App\Domain\Moderation\PriorytetSprawy;
use App\Models\Report;
use App\Notifications\PilneZgloszenieOdCzlowieka;
use App\Poczta\DziennyBudzetListow;
use App\Poczta\PowodOdmowy;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Throwable;

/**
 * LIST DO MODERATORA PRZY ZGŁOSZENIU OD CZŁOWIEKA, KTÓRE NIE MOŻE CZEKAĆ.
 *
 * DLACZEGO TO W OGÓLE POWSTAŁO
 * Bo alarm istniał do tej pory WYŁĄCZNIE po stronie automatu
 * (`AlarmujModeratora`, D-055). Skutek dosłowny: model, który sam z siebie
 * podejrzewał treść seksualną z udziałem dziecka, budził moderatora listem —
 * a człowiek, który TO SAMO zgłosił przyciskiem „Zgłoś", trafiał wyłącznie
 * do kolejki, czyli do ekranu, na który ktoś musi najpierw wejść. W nocy,
 * w weekend i w święta nie wchodzi tam nikt. Cichsza była dokładnie ta droga,
 * po której idzie CZŁOWIEK, czyli ta, na której ktoś już to zobaczył.
 *
 * DLACZEGO OSOBNA KLASA, A NIE WYWOŁANIE W AKCJI ZGŁOSZENIA
 * Z tego samego powodu, dla którego osobno stoi `AlarmujModeratora`:
 * alarmować muszą DWIE drogi — zgłoszenie społecznościowe (`ReportContent`)
 * i zgłoszenie nielegalnej treści z DSA art. 16 (`ZglosNielegalnaTresc`,
 * dostępne bez konta). Druga kopia tego warunku rozjechałaby się z pierwszą,
 * a rozjazd wygląda tu tak, że jedna droga alarmuje, a druga milczy — i nikt
 * tego nie zauważa, dopóki nie zdarzy się coś złego po tej cichej stronie.
 *
 * KANAŁEM JEST POCZTA I NIE DOKŁADAMY DRUGIEGO (`AGENTS.md`). Ten sam
 * transport, ta sama konfiguracja i ten sam wyłącznik, co przy alarmie
 * automatu: pusty `alarm_email` znaczy „bez poczty" i jest normalnym stanem
 * lokalnie oraz w testach — zostaje sama kolejka w panelu, w której sprawa
 * i tak stoi teraz pierwsza (`PriorytetSprawy`).
 *
 * CO POWSTRZYMUJE ZALANIE — TRZY ZAMKI, KAŻDY NA INNĄ DROGĘ
 *
 * Pierwsza wersja zakładała, że wystarczy `reports_one_open_per_pair`
 * (jedno otwarte zgłoszenie na parę osoba–treść). Nie wystarczało, bo ten
 * indeks pilnuje PARY, a list wychodził na każde zgłoszenie: czterdzieści
 * osób zgłaszających jeden wpis dawało czterdzieści listów, jedno konto
 * zaznaczające „dotyczy dziecka" przy kolejnych celach — do sześćdziesięciu
 * na godzinę (limit zgłoszeń), a formularz DSA działa bez konta. Wszystko
 * to z puli EmailLabs 300/dobę, poza wspólnym licznikiem poczty (D-239),
 * czyli kosztem listów logowania i rejestracji.
 *
 *  1. JEDEN LIST NA CEL W OKNIE (`moderation.alarm_czlowieka.okno_celu_godzin`).
 *     `Cache::add()` zakłada klucz celu tylko wtedy, gdy go nie ma, i robi
 *     to atomowo (sterownik `database` wstawia wiersz albo odbija się od
 *     klucza głównego) — dwa równoległe zgłoszenia tego samego wpisu nie
 *     wyślą dwóch listów. Kolejne zgłoszenia i tak stoją w kolejce na górze.
 *  2. DOBOWY SUFIT (`moderation.alarm_czlowieka.dzienny_sufit`) na wszystkie
 *     cele razem. Ostatni list doby mówi to wprost, więc cisza po nim nie
 *     wygląda jak „nic się nie dzieje". Powyżej — tylko wpis w dzienniku
 *     i sprawa w kolejce z plakietką.
 *  3. WSPÓLNA PULA POCZTY. Sufit z punktu 2 jest licznikiem
 *     `DziennyBudzetListow::dlaAlarmuModeracji()` zagnieżdżonym we wspólnym
 *     liczniku — jedna atomowa rezerwacja zajmuje miejsce w obu.
 *
 * Gdy list nie wychodzi po zajęciu klucza celu, klucz jest oddawany: zamek
 * „już alarmowano o tym celu" nie może stać na celu, o którym nikt się nie
 * dowiedział.
 */
final class AlarmujOPilnymZgloszeniu
{
    private const PREFIKS_CELU = 'moderacja:alarm-czlowieka:cel:';

    /** @return bool czy list naprawdę poszedł */
    public function handle(Report $zgloszenie): bool
    {
        $adres = config('kuking.moderation.model.alarm_email');

        if (! is_string($adres) || $adres === '') {
            return false;
        }

        // Oznaczenia automatu mają własny alarm i własną kolejkę; tutaj nie
        // mają czego szukać, a drugi list o tej samej sprawie byłby dokładnie
        // tym hałasem, przed którym stoi cała ta reguła.
        if ($zgloszenie->source === Report::SOURCE_AUTOMAT) {
            return false;
        }

        if (PriorytetSprawy::dla($zgloszenie) !== PilneZgloszenieOdCzlowieka::prog()) {
            return false;
        }

        try {
            $this->sprawdzWspolnaBaze();

            return DB::transaction(function () use ($zgloszenie, $adres): bool {
                $swieze = Report::query()->whereKey($zgloszenie->getKey())->lockForUpdate()->first();

                if ($swieze === null || ! $swieze->isOpen()
                    || $swieze->alarm_czlowieka_obsluzony_at !== null
                    || PriorytetSprawy::dla($swieze) !== PilneZgloszenieOdCzlowieka::prog()) {
                    return false;
                }

                $kluczCelu = self::kluczCelu($swieze);
                $okno = max(1, (int) config('kuking.moderation.alarm_czlowieka.okno_celu_godzin', 6));
                self::zablokujCel($kluczCelu);

                if (Cache::add($kluczCelu, (string) $swieze->getKey(), now()->addHours($okno)) !== true) {
                    // Inne zgłoszenie tego celu już obudziło moderatora. Powrót
                    // do starego wiersza po wygaśnięciu okna nie może wysłać listu.
                    // Stary, osierocony klucz sprzed #2066 nie jest dowodem
                    // zlecenia: właściciel musi mieć trwały znacznik.
                    $wlasciciel = Report::query()->whereKey(Cache::get($kluczCelu))->first();
                    if ($wlasciciel?->alarm_czlowieka_obsluzony_at !== null) {
                        $swieze->forceFill(['alarm_czlowieka_obsluzony_at' => now()])->save();
                    }

                    return false;
                }

                $budzet = DziennyBudzetListow::dlaAlarmuModeracji();

                if (! $budzet->sprobujZarezerwowac()) {
                    Cache::forget($kluczCelu);
                    Log::warning('Pilne zgłoszenie bez listu alarmowego: dobowy sufit alarmów albo pula poczty wyczerpane.', [
                        'numer_sprawy' => $swieze->numer_sprawy,
                        'co_zrobic' => 'Sprawdź kolejkę /admin/zgloszenia — sprawa jest na górze z napisem „Nie może czekać".',
                    ]);

                    return false;
                }

                // Database cache, budżet i database queue zapisują do tej samej
                // transakcji. Wyjątek po INSERT do jobs cofa także blokadę celu
                // i oba liczniki; niepewny wynik COMMIT rozstrzyga stan bazy.
                $probaId = HumanUrgentAlarmAttempt::create((string) $swieze->getKey());
                Notification::route('mail', $adres)->notify(new PilneZgloszenieOdCzlowieka(
                    $swieze,
                    ostatniDzis: $budzet->zostalo() === 0,
                    probaId: $probaId,
                ));
                $swieze->forceFill(['alarm_czlowieka_obsluzony_at' => now()])->save();

                return true;
            });
        } catch (Throwable $awaria) {
            report($awaria);

            return false;
        }
    }

    /** Ponowienie wyłącznie potwierdzonej odmowy dostawcy, bez zgadywania po timeout. */
    public function recover(Report $zgloszenie): bool
    {
        $adres = config('kuking.moderation.model.alarm_email');
        if (! is_string($adres) || $adres === '') {
            return false;
        }

        try {
            $this->sprawdzWspolnaBaze();

            return DB::transaction(function () use ($zgloszenie, $adres): bool {
                $swieze = Report::query()->whereKey($zgloszenie->getKey())->lockForUpdate()->first();
                if ($swieze === null || ! $swieze->isOpen()
                    || $swieze->source === Report::SOURCE_AUTOMAT
                    || $swieze->created_at->lt(now()->subHours(72))
                    || PriorytetSprawy::dla($swieze) !== PilneZgloszenieOdCzlowieka::prog()) {
                    return false;
                }

                $poprzednia = DB::table('human_urgent_alarm_attempts')
                    ->where('report_id', $swieze->getKey())
                    ->orderByDesc('queued_at')
                    ->orderByDesc('id')
                    ->first();
                if ($poprzednia === null || $poprzednia->state !== HumanUrgentAlarmAttempt::REJECTED) {
                    return false;
                }
                if ($poprzednia->failure_kind === PowodOdmowy::LIMIT_DOBOWY->value
                    && Carbon::parse($poprzednia->failed_at ?? $poprzednia->queued_at)->utc()->isSameDay(now('UTC'))) {
                    return false;
                }
                if (! in_array($poprzednia->failure_kind, [PowodOdmowy::PRZEJSCIOWA->value, PowodOdmowy::LIMIT_DOBOWY->value], true)) {
                    return false;
                }

                $kluczCelu = self::kluczCelu($swieze);
                self::zablokujCel($kluczCelu);
                $wlasciciel = Cache::get($kluczCelu);
                $nowyKlucz = false;
                if ($wlasciciel === null) {
                    $okno = max(1, (int) config('kuking.moderation.alarm_czlowieka.okno_celu_godzin', 6));
                    if (Cache::add($kluczCelu, (string) $swieze->getKey(), now()->addHours($okno)) !== true) {
                        return false;
                    }
                    $nowyKlucz = true;
                } elseif ((string) $wlasciciel !== (string) $swieze->getKey()) {
                    return false;
                }

                $budzet = DziennyBudzetListow::dlaAlarmuModeracji();
                if (! $budzet->sprobujZarezerwowac()) {
                    if ($nowyKlucz) {
                        Cache::forget($kluczCelu);
                    }

                    return false;
                }

                if (! $nowyKlucz) {
                    $okno = max(1, (int) config('kuking.moderation.alarm_czlowieka.okno_celu_godzin', 6));
                    Cache::put($kluczCelu, (string) $swieze->getKey(), now()->addHours($okno));
                }

                DB::table('human_urgent_alarm_attempts')->where('id', $poprzednia->id)
                    ->update(['state' => HumanUrgentAlarmAttempt::RETRIED, 'retried_at' => now()]);
                $probaId = HumanUrgentAlarmAttempt::create((string) $swieze->getKey());
                Notification::route('mail', $adres)->notify(new PilneZgloszenieOdCzlowieka(
                    $swieze,
                    ostatniDzis: $budzet->zostalo() === 0,
                    probaId: $probaId,
                ));

                return true;
            });
        } catch (Throwable $awaria) {
            report($awaria);

            return false;
        }
    }

    /** Jeden zamek także między różnymi zgłoszeniami tego samego celu. */
    private static function zablokujCel(string $klucz): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$klucz]);
    }

    private function sprawdzWspolnaBaze(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $kolejka = Queue::connection();
        $baza = DB::connection();
        $cache = config('cache.stores.database');

        if (! $kolejka instanceof DatabaseQueue
            || $kolejka->getDatabase() !== $baza
            || config('queue.connections.database.after_commit') !== false
            || config('cache.default') !== 'database'
            || DB::connection($cache['connection'] ?? null) !== $baza
            || DB::connection($cache['lock_connection'] ?? null) !== $baza) {
            throw new LogicException('Pilny alarm wymaga wspólnej bazy dla cache, budżetu i kolejki oraz after_commit=false.');
        }
    }

    /**
     * Klucz CELU, nie zgłoszenia. Zgłoszenie społecznościowe zawsze ma
     * `target_type` + `target_id`; zgłoszenie prawne może mieć sam adres
     * (cel nierozpoznany), więc wtedy liczy się adres — bez części po `?`
     * i `#` i bez końcowego ukośnika, żeby „?x=1" nie otwierało nowego okna.
     */
    private static function kluczCelu(Report $zgloszenie): string
    {
        if ($zgloszenie->target_id !== null && $zgloszenie->target_id !== '') {
            $cel = $zgloszenie->target_type.':'.$zgloszenie->target_id;
        } else {
            $adres = mb_strtolower(trim((string) $zgloszenie->target_url));
            $cel = 'url:'.rtrim((string) preg_replace('/[?#].*$/s', '', $adres), '/');
        }

        return self::PREFIKS_CELU.hash('sha256', $cel);
    }
}
