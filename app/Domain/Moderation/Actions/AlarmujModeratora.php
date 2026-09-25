<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Domain\Security\DziennyBudzetListow;
use App\Models\Report;
use App\Notifications\PilnyAlarmModeracyjny;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 *
 * DOBOWY SUFIT (audyt B8-02). Do 25.09.2026 każdy pilny sygnał wysyłał list
 * bez rezerwacji w `DziennyBudzetListow` — seria wpisów oznaczonych przez
 * model zjadała pulę, a licznik aplikacji dalej pokazywał wolne miejsce.
 * Teraz list idzie tylko spod `dlaAlarmuAutomatu()` — w `handle()` i przy
 * dosyłaniu, bo `PilnyAlarmModeracyjny` niesie znacznik „miejsce już
 * zarezerwowane" i list bez rezerwacji wypadłby z rachunku puli. Po
 * wyczerpaniu sufitu stan na wierszu ZOSTAJE bez znacznika (`zalegly` albo
 * poprzedni), więc sprawa czeka w panelu, sonda `/health` nadal ją widzi,
 * a `kuking:doslij-pilne-alarmy` wyśle list, gdy sufit się odnowi. Dziennik
 * mówi, dlaczego bez listu.
 */
final class AlarmujModeratora
{
    /** Sprawa nie jest pilna — nie ma o czym alarmować i nic nie zapisujemy. */
    public const NIEPILNE = 'niepilne';

    /** Alarm o tej sprawie został już zlecony wcześniej; drugiego nie ma. */
    public const JUZ_ZLECONY = 'juz_zlecony';

    /**
     * Dobowy sufit alarmów automatu albo wspólna pula poczty wyczerpane
     * (audyt B8-02). Listu nie ma, stan na wierszu zostaje bez znacznika.
     */
    public const SUFIT = 'sufit';

    /**
     * @param  list<Sygnal>  $sygnaly
     * @return string jeden z: `self::NIEPILNE`, `self::JUZ_ZLECONY`,
     *                `Report::ALARM_ZLECONY`, `Report::ALARM_BEZ_ADRESU`,
     *                `Report::ALARM_NIEUDANY`, `self::SUFIT`
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
        if ($oznaczenie->alarm_pilny_stan === null && ! $this->zapisz($oznaczenie, Report::ALARM_ZALEGLY)) {
            return self::JUZ_ZLECONY;
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
            return $this->zapisz($oznaczenie, Report::ALARM_BEZ_ADRESU)
                ? Report::ALARM_BEZ_ADRESU
                : self::JUZ_ZLECONY;
        }

        if (! $this->zarezerwujMiejsce($oznaczenie)) {
            return self::SUFIT;
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
            report($awaria);

            return $this->zapisz($oznaczenie, Report::ALARM_NIEUDANY)
                ? Report::ALARM_NIEUDANY
                : self::JUZ_ZLECONY;
        }

        $this->zapisz($oznaczenie, Report::ALARM_ZLECONY, znacznik: true);

        return Report::ALARM_ZLECONY;
    }

    /**
     * DOSŁANIE ALARMU, KTÓRY NIE DOTARŁ (`kuking:doslij-pilne-alarmy`).
     *
     * Bez listy sygnałów, bo komenda jej nie ma i mieć nie może — pilność
     * żyła w pamięci workera. Dowodem pilności jest tu SAM STAN na wierszu:
     * `OznaczDoPrzegladu` stawia go wyłącznie przy sprawie pilnej.
     *
     * KOLEJNOŚĆ ODWROTNA NIŻ W `handle()` — ZAJMIJ, POTEM WYŚLIJ, W JEDNEJ
     * TRANSAKCJI. Tu wolno, bo komenda chodzi co godzinę i może zdarzyć się
     * wyścig dwóch przebiegów (wdrożenie: stary i nowy kontener,
     * `onOneServer()` pilnuje tylko tej samej minuty). Warunkowy `UPDATE`
     * rozstrzyga go w bazie: dokładnie jeden przebieg dostaje wiersz, drugi
     * widzi zero i nie wysyła niczego — ten sam wzorzec co
     * `NotifyReporterReceipt`. Powiadomienie jest kolejkowane
     * (`ShouldQueue`), a kolejka to tabela `jobs` w tej samej bazie, więc
     * zadanie wysyłki powstaje W TEJ SAMEJ transakcji: albo jest znacznik
     * i zadanie, albo żadne. Awaria zlecenia cofa znacznik.
     *
     * SUFIT (audyt B8-02) SPRAWDZAMY PRZED TRANSAKCJĄ: rezerwacja w puli to
     * blokada i licznik w cache, nie wiersz sprawy — nie mieszamy jej
     * z warunkowym `UPDATE`. Przegrany wyścig dwóch przebiegów kosztuje co
     * najwyżej jedno miejsce w suficie, nigdy list poza nim.
     *
     * @return string `Report::ALARM_ZLECONY`, `Report::ALARM_BEZ_ADRESU`,
     *                `Report::ALARM_NIEUDANY`, `self::SUFIT` (stan na wierszu
     *                bez zmian, następny przebieg spróbuje znowu)
     *                albo `self::JUZ_ZLECONY`, gdy
     *                wiersz zajął ktoś inny albo sprawa przestała być do
     *                dosłania (zamknięta w międzyczasie)
     */
    public function doslij(Report $oznaczenie): string
    {
        $adres = config('kuking.moderation.model.alarm_email');

        if (! is_string($adres) || $adres === '') {
            return Report::ALARM_BEZ_ADRESU;
        }

        if (! $this->zarezerwujMiejsce($oznaczenie)) {
            return self::SUFIT;
        }

        try {
            $zajete = DB::transaction(function () use ($oznaczenie, $adres): bool {
                $zmiana = [
                    'alarm_pilny_stan' => Report::ALARM_ZLECONY,
                    'alarm_pilny_zlecony_at' => now(),
                ];

                $zajete = Report::query()
                    ->whereKey($oznaczenie->getKey())
                    ->pilneDoDoslania()
                    ->update($zmiana);

                if ($zajete === 0) {
                    return false;
                }

                $oznaczenie->forceFill($zmiana);
                Notification::route('mail', $adres)->notify(new PilnyAlarmModeracyjny($oznaczenie));

                return true;
            });
        } catch (Throwable $awaria) {
            // Transakcja cofnęła znacznik — wiersz wraca do „nie dotarł"
            // i następny przebieg spróbuje znowu. Powód wyjątku jak w `handle()`.
            report($awaria);
            $oznaczenie->refresh();

            return $this->zapisz($oznaczenie, Report::ALARM_NIEUDANY)
                ? Report::ALARM_NIEUDANY
                : self::JUZ_ZLECONY;
        }

        if (! $zajete) {
            $oznaczenie->refresh();

            return self::JUZ_ZLECONY;
        }

        return Report::ALARM_ZLECONY;
    }

    /**
     * Miejsce w dobowym suficie alarmów automatu i we wspólnej puli poczty
     * (audyt B8-02). Odmowa zostawia ślad w dzienniku — bez treści i bez
     * danych autora, sam numer oznaczenia.
     */
    private function zarezerwujMiejsce(Report $oznaczenie): bool
    {
        if (DziennyBudzetListow::dlaAlarmuAutomatu()->sprobujZarezerwowac()) {
            return true;
        }

        Log::warning('Pilne oznaczenie automatu bez listu alarmowego: dobowy sufit alarmów albo pula poczty wyczerpane.', [
            'oznaczenie' => $oznaczenie->getKey(),
            'co_zrobic' => 'Sprawdź kolejkę /admin/sygnaly — oznaczenie tam czeka.',
        ]);

        return false;
    }

    /**
     * Zapis stanu alarmu na wierszu sprawy.
     *
     * `update()` przez query builder, a nie `save()` na modelu: wiersz mógł
     * w międzyczasie zmienić status w panelu moderacji, a alarm nie ma prawa
     * cofnąć czyjejś decyzji, zapisując model wczytany trzy sekundy
     * wcześniej. Model w ręku wołającego dostaje te same wartości wprost,
     * bo `update()` go nie odświeża.
     *
     * PORAŻKA NIE NADPISUJE SUKCESU. Stany bez znacznika (`zalegly`,
     * `bez_adresu`, `nieudany`) zapisujemy tylko pod
     * `alarm_pilny_zlecony_at IS NULL`: drugie zadanie o tej samej sprawie,
     * któremu padła poczta, nie ma prawa zamienić zleconego już alarmu
     * w „nie dotarł" — sonda zapaliłaby się na czerwono przy sprawie,
     * o której moderator wie. Baza pilnuje tego samego CHECK-iem
     * `reports_alarm_pilny_spojny_check`.
     *
     * @return bool `false`, gdy wiersz ma już zlecony alarm i nic nie zmieniono
     */
    private function zapisz(Report $oznaczenie, string $stan, bool $znacznik = false): bool
    {
        $zmiana = ['alarm_pilny_stan' => $stan];
        $zapytanie = Report::query()->whereKey($oznaczenie->getKey());

        if ($znacznik) {
            $zmiana['alarm_pilny_zlecony_at'] = now();
        } else {
            $zapytanie->whereNull('alarm_pilny_zlecony_at');
        }

        if ($zapytanie->update($zmiana) === 0) {
            $oznaczenie->refresh();

            return false;
        }

        $oznaczenie->forceFill($zmiana);

        return true;
    }
}
