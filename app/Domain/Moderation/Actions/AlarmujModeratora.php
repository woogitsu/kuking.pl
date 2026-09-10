<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Models\Report;
use App\Notifications\PilnyAlarmModeracyjny;
use Illuminate\Support\Facades\Notification;

/**
 * LIST DO MODERATORA — wyłącznie przy kategoriach, które nie mogą czekać (D-055).
 *
 * DLACZEGO OSOBNA KLASA, A NIE METODA W ZADANIU
 * Bo od issue #237 alarmować muszą DWA zadania: analiza wpisu i komentarza
 * (`PrzeanalizujTresc`) oraz ocena zdjęcia profilowego (`PrzeanalizujAwatar`).
 * Druga kopia tego warunku rozjechałaby się z pierwszą przy pierwszej
 * zmianie — a rozjazd wygląda tu tak, że jedna droga alarmuje, a druga
 * milczy, i nikt tego nie zauważa, dopóki nie zdarzy się coś złego akurat
 * po tej cichej stronie.
 *
 * Zwykłe oznaczenia idą raz dziennie, jednym podsumowaniem
 * (`kuking:podsumowanie-automatu`). Gdyby każde oznaczenie wysyłało list,
 * przy fali nowych kont skrzynka moderatora zamieniłaby się w śmietnik —
 * a skończyłoby się tym, że przestałby te listy otwierać, czyli alarm
 * przestałby działać dokładnie wtedy, gdy jest potrzebny. Osobno liczy się
 * limit poczty: 300 listów dziennie dzielone z resztą serwisu.
 *
 * Pusty `alarm_email` znaczy „bez poczty" i jest normalnym stanem lokalnie
 * oraz w testach — zostaje sama kolejka w panelu.
 */
final class AlarmujModeratora
{
    /**
     * @param  list<Sygnal>  $sygnaly
     * @return bool czy list naprawdę poszedł
     */
    public function handle(Report $oznaczenie, array $sygnaly): bool
    {
        $adres = config('kuking.moderation.model.alarm_email');

        if (! is_string($adres) || $adres === '') {
            return false;
        }

        $pilne = array_filter($sygnaly, static fn (Sygnal $s): bool => $s->pilny);

        if ($pilne === []) {
            return false;
        }

        Notification::route('mail', $adres)->notify(new PilnyAlarmModeracyjny($oznaczenie));

        return true;
    }
}
