<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Models\Report;
use App\Notifications\PilnyAlarmModeracyjny;
use Illuminate\Support\Facades\Notification;

/**
 * LIST DO MODERATORA — wyłącznie przy sprawach, które nie mogą czekać
 * (D-055, rozszerzone w D-070).
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
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TRZECIE WEJŚCIE: ZGŁOSZENIE KRYTYCZNE OD CZŁOWIEKA (D-070)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Od 10 września alarmuje też `dlaKrytycznegoZgloszenia()` — sprawa
 * o priorytecie P0 zgłoszona przez CZŁOWIEKA (CSAM, groźba zagrażająca
 * życiu, aktywny doxxing). Podpięte TUTAJ, a nie zbudowane jako drugi
 * mechanizm, i to jest treść tej części decyzji:
 *
 *  - adres odbiorcy, warunek „pusty adres znaczy bez poczty", kolejkowanie
 *    i rachunek za budżet poczty (300 listów dziennie dzielonych z resztą
 *    serwisu) są DOKŁADNIE TE SAME. Druga kopia rozjechałaby się z pierwszą
 *    przy pierwszej zmianie, a rozjazd wygląda tu tak, że jedna droga
 *    alarmuje, a druga milczy — dokładnie ta pułapka, z powodu której ta
 *    klasa w ogóle powstała (issue #237);
 *  - próg „co jest pilne" zostaje jeden na serwis. Automat ma swoją listę
 *    kategorii (`KategorieModeracji::PILNE`), człowiek ma P0
 *    (`PriorytetSprawy::MAPOWANIE`), ale KANAŁ jest jeden, więc nie da się
 *    wyciszyć jednego alarmu, nie widząc, że wycisza się oba.
 *
 * DLACZEGO SAMO P0, A NIE TEŻ P1
 * Bo alarm musi zostać rzadki. P1 to nękanie i mowa nienawiści — kategorie,
 * których przy tysiącu kont bywa kilka na tydzień, a cel czasowy dla nich
 * to 24 godziny w dni robocze. Codzienny list o P1 nauczyłby moderatora
 * nie otwierać tych listów, czyli zabrałby działanie alarmowi P0. Ten sam
 * rachunek, który wyżej ogranicza automat do dwóch kategorii.
 */
final class AlarmujModeratora
{
    /**
     * @param  list<Sygnal>  $sygnaly
     * @return bool czy list naprawdę poszedł
     */
    public function handle(Report $oznaczenie, array $sygnaly): bool
    {
        $pilne = array_filter($sygnaly, static fn (Sygnal $s): bool => $s->pilny);

        if ($pilne === []) {
            return false;
        }

        return $this->wyslij($oznaczenie);
    }

    /**
     * ZGŁOSZENIE KRYTYCZNE (P0) OD CZŁOWIEKA — list natychmiast (D-070).
     *
     * Warunek jest tu jeden i pilnuje go sama sprawa: `priorytet = P0`.
     * Świadomie NIE sprawdzamy `source`: P0 z formularza społecznościowego
     * i P0 ze zgłoszenia prawnego DSA art. 16 to ta sama pilność, a różnica
     * między nimi (komu odpowiadamy, jakie biegną terminy) nie ma wpływu na
     * to, kiedy trzeba wstać od stołu.
     *
     * Oznaczenia automatu tą drogą nie przechodzą, bo mapowanie nie daje im
     * P0 (`PriorytetSprawy::MAPOWANIE`) — mają własne wejście wyżej, ze
     * swoim własnym progiem.
     *
     * @return bool czy list naprawdę poszedł
     */
    public function dlaKrytycznegoZgloszenia(Report $zgloszenie): bool
    {
        if (! $zgloszenie->jestKrytyczna()) {
            return false;
        }

        return $this->wyslij($zgloszenie);
    }

    /**
     * Wspólna wysyłka obu dróg — jedno miejsce, które zna adres i decyduje,
     * czy poczta jest w ogóle włączona.
     */
    private function wyslij(Report $sprawa): bool
    {
        $adres = config('kuking.moderation.model.alarm_email');

        if (! is_string($adres) || $adres === '') {
            return false;
        }

        Notification::route('mail', $adres)->notify(new PilnyAlarmModeracyjny($sprawa));

        return true;
    }
}
