<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\PriorytetSprawy;
use App\Models\Report;
use App\Notifications\PilneZgloszenieOdCzlowieka;
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
 * CO POWSTRZYMUJE NADUŻYCIE
 * Kategorię wybiera zgłaszający, więc „zaznaczę »dotyczy dziecka«, żeby
 * wywołać list" jest możliwe. Nie budujemy na to osobnego mechanizmu, bo
 * baza już go ma: `reports_one_open_per_pair` dopuszcza JEDNO otwarte
 * zgłoszenie na parę osoba–treść, a zgłoszenie społecznościowe wymaga konta.
 * Jedna osoba nie zrobi z tego fali. Gdyby kiedyś zrobiła — widać to będzie
 * w `reports`, a nie dopiero na rachunku za pocztę.
 */
final class AlarmujOPilnymZgloszeniu
{
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

        Notification::route('mail', $adres)->notify(new PilneZgloszenieOdCzlowieka($zgloszenie));

        return true;
    }
}
