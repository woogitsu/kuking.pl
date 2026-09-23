<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Models\Report;
use App\Notifications\PilnyAlarmModeracyjny;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * LIST DO MODERATORA — wyłącznie przy kategoriach, które nie mogą czekać (D-055).
 *
 * DLACZEGO OSOBNA KLASA, A NIE METODA W ZADANIU
 * Bo od issue #237 alarmować muszą DWA zadania: analiza wpisu i komentarza
 * (`PrzeanalizujTresc`) oraz ocena zdjęcia profilowego (`PrzeanalizujAwatar`).
 * Od D-240 zdjęcie profilowe nie idzie do modelu, więc wołającym jest dziś
 * tylko `PrzeanalizujTresc` — klasa zostaje jako jedno miejsce tej reguły.
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
 * ═══════════════════════════════════════════════════════════════════════
 *  KAŻDA CISZA MA TU WŁASNĄ NAZWĘ (issue #1051)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * CO BYŁO PRZEDTEM
 * Ta metoda oddawała `bool` opisany w komentarzu jako „czy list naprawdę
 * poszedł" i oddawała `false` w DWÓCH zupełnie różnych sytuacjach: pusty
 * `KUKING_MODEL_ALARM_EMAIL` (kanał alarmowy NIE ISTNIEJE) oraz brak
 * sygnałów pilnych (nie ma o czym alarmować). Obaj wołający — oba zadania
 * z kolejki — ten wynik IGNOROWALI, więc różnica i tak nie miała gdzie się
 * objawić. Cisza z braku konfiguracji wyglądała identycznie jak cisza
 * z zadowolenia; to ta sama klasa błędu, którą `StanKopiiBazy` rozbroił
 * stanem `WYLACZONA` przy pustym buckecie.
 *
 * Trzecia cisza była jeszcze gorsza: wyjątek z kanału pocztowego łapał
 * blankietowy `catch (Throwable)` w zadaniu i zostawiał po sobie
 * `Log::warning`. Na produkcji `LOG_BLAD_WEBHOOK_URL` nie jest ustawiony,
 * więc ten wpis nie dociera do nikogo, a `PrzeanalizujTresc::$tries = 1`
 * oznacza, że nie ma też drugiej próby.
 *
 * CO JEST TERAZ
 * Wynikiem jest NAZWANY STAN, a jego kopia siada na wierszu sprawy
 * (`reports.alarm_pilny_stan`). Sprawa pilna bez `alarm_pilny_zlecony_at`
 * jest widoczna w bazie i pyta o nią sonda `alarmy_moderacji` w `/health`.
 * Zgłoszenie albo dociera, albo zostawia ślad, że nie dotarło.
 *
 * ZNACZNIK STAWIAMY PO ZLECENIU LISTU, NIE PRZED — I TO JEST WYBÓR
 * Kolejność odwrotna („zajmij znacznik, potem wyślij", jak
 * w `NotifyReporterReceipt`) chroni przed drugim listem, ale wprowadza
 * okno, w którym worker ubity W TRAKCIE zlecania zostawia wiersz ze stanem
 * `zlecony` i pustą skrzynką moderatora. Tamta klasa może sobie na to
 * pozwolić, bo cofa znacznik transakcją — tutaj nie ma czego cofać:
 * zlecenie do kanału pocztowego nie jest zapisem w bazie. Przy wyborze
 * między „dwa takie same listy o tej samej sprawie" a „fałszywa zieleń
 * przy sprawie dotyczącej dziecka" bierzemy dwa listy.
 *
 * Realne podwojenie i tak jest trudne: `reports_jeden_automat_na_tresc`
 * dopuszcza jedno oznaczenie na treść, a ponowna analiza widzi już
 * ustawiony `alarm_pilny_zlecony_at` i nie robi nic.
 */
final class AlarmujModeratora
{
    /** Sprawa nie jest pilna — nie ma o czym alarmować i nic nie zapisujemy. */
    public const NIEPILNE = 'niepilne';

    /** Alarm o tej sprawie został już zlecony wcześniej; drugiego nie ma. */
    public const JUZ_ZLECONY = 'juz_zlecony';

    /**
     * @param  list<Sygnal>  $sygnaly
     * @return string jeden z: `self::NIEPILNE`, `self::JUZ_ZLECONY`,
     *                `Report::ALARM_ZLECONY`, `Report::ALARM_BEZ_ADRESU`,
     *                `Report::ALARM_NIEUDANY`
     */
    public function handle(Report $oznaczenie, array $sygnaly): string
    {
        $pilne = array_filter($sygnaly, static fn (Sygnal $s): bool => $s->pilny);

        if ($pilne === []) {
            return self::NIEPILNE;
        }

        if ($oznaczenie->alarm_pilny_zlecony_at !== null) {
            // Ten alarm już poszedł — najczęściej przy pierwszej analizie tej
            // samej treści. Ponowienie nie ma prawa dołożyć drugiego listu
            // o sprawie, o której moderator już wie.
            return self::JUZ_ZLECONY;
        }

        // Obowiązek zapisuje zwykle `OznaczDoPrzegladu`, w jednej transakcji
        // z wierszem sprawy. Tutaj dopisujemy go dla wierszy, które powstały
        // PRZED tą zmianą albo przy drodze, na której alarm liczy pilność
        // z innego zestawu sygnałów niż ten, który stworzył wiersz — żeby
        // stan na wierszu nigdy nie był pusty przy sprawie pilnej.
        if ($oznaczenie->alarm_pilny_stan === null) {
            $this->zapisz($oznaczenie, Report::ALARM_ZALEGLY);
        }

        $adres = config('kuking.moderation.model.alarm_email');

        if (! is_string($adres) || $adres === '') {
            /*
             * PUSTY ADRES ZNACZY „BEZ POCZTY" I JEST NORMALNYM STANEM
             * lokalnie oraz w testach — zostaje sama kolejka w panelu.
             * Na PRODUKCJI znaczy coś zupełnie innego: jedyny natychmiastowy
             * kanał o treściach dotyczących dziecka jest wyłączony, a
             * rejestracja stoi otworem dla każdego. Dlatego to nie jest
             * `return false` jak dawniej, tylko nazwany, zapisany stan —
             * człowiek naprawia go wpisaniem adresu, nie ponowieniem.
             */
            $this->zapisz($oznaczenie, Report::ALARM_BEZ_ADRESU);

            return Report::ALARM_BEZ_ADRESU;
        }

        try {
            Notification::route('mail', $adres)->notify(new PilnyAlarmModeracyjny($oznaczenie));
        } catch (Throwable $awaria) {
            /*
             * WYJĄTEK NIE LECI DALEJ, ALE TEŻ NIE GINIE.
             *
             * Dalej poleciałby w blankietowy `catch (Throwable)` obu zadań
             * i skończył jako `Log::warning` bez odbiorcy. `report()` kieruje
             * go tam, gdzie idą awarie, a stan `nieudany` na wierszu zostaje
             * niezależnie od tego, czy ten kanał dziś działa — i to on jest
             * tu trwałym śladem, bo baza jest jedyną rzeczą, która w tym
             * przebiegu na pewno odpowiada.
             */
            $this->zapisz($oznaczenie, Report::ALARM_NIEUDANY);
            report($awaria);

            return Report::ALARM_NIEUDANY;
        }

        $this->zapisz($oznaczenie, Report::ALARM_ZLECONY, znacznik: true);

        return Report::ALARM_ZLECONY;
    }

    /**
     * Zapis stanu alarmu na wierszu sprawy.
     *
     * `update()` przez query builder, a nie `save()` na modelu: wiersz mógł
     * w międzyczasie zmienić status w panelu moderacji, a alarm nie ma prawa
     * cofnąć czyjejś decyzji, zapisując model wczytany trzy sekundy
     * wcześniej. Model w ręku wołającego dostaje te same wartości wprost,
     * bo `update()` go nie odświeża.
     */
    private function zapisz(Report $oznaczenie, string $stan, bool $znacznik = false): void
    {
        $zmiana = ['alarm_pilny_stan' => $stan];

        if ($znacznik) {
            $zmiana['alarm_pilny_zlecony_at'] = now();
        }

        Report::query()->whereKey($oznaczenie->getKey())->update($zmiana);

        $oznaczenie->forceFill($zmiana);
    }
}
