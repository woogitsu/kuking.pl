<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\PriorytetSprawy;
use App\Domain\Security\DziennyBudzetListow;
use App\Models\Report;
use App\Notifications\PilneZgloszenieOdCzlowieka;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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

        $kluczCelu = self::kluczCelu($zgloszenie);
        $okno = max(1, (int) config('kuking.moderation.alarm_czlowieka.okno_celu_godzin', 6));

        if (Cache::add($kluczCelu, (string) $zgloszenie->getKey(), now()->addHours($okno)) !== true) {
            return false;
        }

        $budzet = DziennyBudzetListow::dlaAlarmuModeracji();

        if (! $budzet->sprobujZarezerwowac()) {
            Cache::forget($kluczCelu);

            // Sprawa i tak stoi pierwsza w kolejce; dziennik mówi, dlaczego
            // tym razem bez listu. Bez treści i bez danych zgłaszającego.
            Log::warning('Pilne zgłoszenie bez listu alarmowego: dobowy sufit alarmów albo pula poczty wyczerpane.', [
                'numer_sprawy' => $zgloszenie->numer_sprawy,
                'co_zrobic' => 'Sprawdź kolejkę /admin/zgloszenia — sprawa jest na górze z napisem „Nie może czekać".',
            ]);

            return false;
        }

        Notification::route('mail', $adres)->notify(new PilneZgloszenieOdCzlowieka(
            $zgloszenie,
            ostatniDzis: $budzet->zostalo() === 0,
        ));

        return true;
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
