<?php

declare(strict_types=1);

namespace App\Domain\Kolejka;

use App\Logging\WebhookBleduHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * „Kolejka stanęła" albo „coś padło w nocy" na webhook właściciela (#599).
 *
 * TEN SAM KANAŁ, CO BŁĘDY 500, CZUJKA KOPII I BUDŻET POŁĄCZEŃ
 * (`blad_webhook`, D-041) — jedno miejsce, w które właściciel patrzy.
 * Nie dokładamy drugiej platformy monitoringu; to jest wymóg #599.
 *
 * OGRANICZENIE POWTÓRZEŃ I POWRÓT DO ZDROWIA
 * Czujka chodzi co kwadrans, a martwy worker bywa martwy przez dobę. Bez
 * ciszy dałoby to 96 identycznych wiadomości i nauczyło ignorować kanał.
 * Zmiana stanu dzwoni od razu (także eskalacja „nowe nieudane" → „zaległość"),
 * ten sam stan nie częściej niż raz na `cisza_godzin`, a powrót do spokoju
 * daje DOKŁADNIE JEDNĄ wiadomość odwołującą.
 *
 * CISZA NALEŻY SIĘ ZA DZWONEK, KTÓRY KANAŁ PRZYJĄŁ — NIE ZA SAMĄ PRÓBĘ
 * (poprawka do #599; usterkę zmierzył odbiór #676).
 * Do 18.09.2026 `wyslij()` uznawał wysyłkę za udaną na SAM BRAK WYJĄTKU,
 * a nieudane żądanie HTTP wyjątku nie rzuca: klient Laravela bez `throw()`
 * oddaje 404 z odwołanego webhooka jako zwykłą odpowiedź, a
 * `WebhookBleduHandler::write()` z zasady nigdy nie rzuca dalej. Skutek:
 * jedna nieudana próba zapisywała pamięć wyciszania i zagłuszała następny,
 * SPRAWNY dzwonek o wciąż stojącej kolejce. Pełne uzasadnienie kompromisu
 * „ochrona przed lawiną prób" kontra „porażka to nie dostarczenie" stoi
 * w bliźniaczym `App\Domain\Polaczenia\AlarmPolaczen` — w skrócie: cisza
 * (godziny) należy się tylko wiadomości POTWIERDZONEJ przez kanał, a każdej
 * próbie należy się wyłącznie krótka przerwa (minuty), która ogranicza
 * liczbę żądań do martwego kanału i nie pomija żadnego przebiegu czujki.
 *
 * ZNANE OGRANICZENIE PAMIĘCI
 * Pamięć stanu mieszka w cache, a `docker/entrypoint.sh` czyści cache przy
 * każdym starcie kontenera. Po wdrożeniu pamięć jest pusta, więc trwający
 * alarm zadzwoni raz dodatkowo, a niewysłane odwołanie przepadnie. To jest
 * świadomie zaakceptowane: nadmiarowa wiadomość o prawdziwej awarii jest
 * tańsza niż tabela i migracja dla stanu, który wolno zgubić. SAMA OCENA
 * stanu jest bezstanowa (`StanKolejki` liczy z okna czasowego), więc restart
 * nie generuje fałszywej awarii — tylko ewentualne powtórzenie prawdziwej.
 */
final class AlarmKolejki
{
    private const KLUCZ = 'kuking:kolejka:ostatni-alarm';

    /**
     * Ile czekamy z PONOWIENIEM próby, której kanał nie potwierdził.
     *
     * To NIE jest cisza o awarii (ta stoi w `cisza_godzin` i liczy się
     * w godzinach) — to jest wyłącznie odstęp między ŻĄDANIAMI do kanału,
     * który nie odpowiada jak trzeba. Ta czujka chodzi co kwadrans, więc
     * pięć minut nie pomija żadnego jej przebiegu.
     */
    private const PONOWIENIE_PO_NIEUDANEJ_MINUT = 5;

    /** Stany, które są ALARMEM. `spokojna` nie dzwoni. */
    private const ALARMUJACE = [
        StanKolejki::ZALEGLOSC,
        StanKolejki::NOWE_NIEUDANE,
        StanKolejki::NIEDOSTEPNA,
    ];

    /**
     * @param  array<string, mixed>  $wynik
     * @return bool czy kanał PRZYJĄŁ wiadomość (odpowiedź 2xx). To NIE jest
     *              to samo, co „ktoś ją zobaczył" — patrz `kanalPrzyjal()`.
     */
    public function zadzwonJesliTrzeba(array $wynik): bool
    {
        $stan = (string) ($wynik['stan'] ?? '');

        if (! in_array($stan, self::ALARMUJACE, true)) {
            return $this->odwolajJesliTrzeba($stan);
        }

        if (! $this->kanalWlaczony() || ! $this->wolnoDzwonic($stan)) {
            return false;
        }

        $przyjeto = $this->kanalPrzyjal($this->tresc($wynik));

        // Pamięć zapisuje się ZAWSZE po próbie, ale zapisuje DWIE RÓŻNE
        // rzeczy: „kanał to przyjął o tej godzinie" albo „próbowaliśmy
        // o tej godzinie i się nie udało". Tylko pierwsza kupuje ciszę.
        $this->zapamietajProbe($stan, $przyjeto);

        return $przyjeto;
    }

    private function odwolajJesliTrzeba(string $stan): bool
    {
        if ($stan !== StanKolejki::SPOKOJNA || ! $this->kanalWlaczony()) {
            return false;
        }

        $poprzedni = Cache::get(self::KLUCZ);

        if (! is_array($poprzedni)) {
            return false;
        }

        // ALARMU, KTÓRY DO NIKOGO NIE DOSZEDŁ, NIE MA CZEGO ODWOŁYWAĆ.
        // „Kolejka wróciła do normy (poprzedni stan: zaleglosc)" wysłane po
        // awarii, o której właściciel nigdy się nie dowiedział, jest gorsze
        // niż cisza: opisuje zdarzenie, którego nikt nie widział.
        if ($this->dostarczoneO($poprzedni) === 0) {
            Cache::forget(self::KLUCZ);

            return false;
        }

        if (! $this->wolnoPonowicOdwolanie($poprzedni)) {
            return false;
        }

        $przyjeto = $this->kanalPrzyjal(sprintf(
            'kolejka wróciła do normy (poprzedni stan: %s).',
            (string) ($poprzedni['stan'] ?? 'nieznany'),
        ));

        if (! $przyjeto) {
            // Odwołanie, którego kanał nie potwierdził, NIE jest odwołaniem.
            // Pamięć zostaje — skasowana znaczyłaby „odwołane" i człowiek
            // zostałby z alarmem bez zakończenia.
            $poprzedni['proba_o'] = $this->teraz();
            $poprzedni['odwolanie_nieudane'] = true;
            Cache::put(self::KLUCZ, $poprzedni, $this->pamiec());

            return false;
        }

        Cache::forget(self::KLUCZ);

        return true;
    }

    /**
     * Metoda publiczna, bo to ONA jest przedmiotem testu „czego tu nie ma".
     *
     * @param  array<string, mixed>  $wynik
     */
    public function tresc(array $wynik): string
    {
        $stan = (string) ($wynik['stan'] ?? '');

        // Teksty są STAŁYMI z tego pliku, dobieranymi przez `match` po
        // wartości z listy stałych. Nic, co przyszło z `payload` albo
        // `exception`, nie ma prawa się tu znaleźć.
        $co = match ($stan) {
            StanKolejki::ZALEGLOSC => sprintf(
                'najstarsze gotowe zadanie czeka %d s (próg %d s), oczekujących %d, zawieszonych %d. '
                .'To wygląda na workera, który NIE PRACUJE — a worker, który nie chodzi, nie zgłasza żadnego błędu.',
                (int) ($wynik['zaleglosc_sekundy'] ?? 0),
                (int) ($wynik['prog_zaleglosci_sekundy'] ?? 0),
                (int) ($wynik['oczekujace'] ?? 0),
                (int) ($wynik['zawieszone'] ?? 0),
            ),
            StanKolejki::NOWE_NIEUDANE => sprintf(
                'w ostatnich %d h padło %d zadań (w tabeli razem: %d).',
                (int) ($wynik['okno_godzin'] ?? 0),
                (int) ($wynik['nieudane_w_oknie'] ?? 0),
                (int) ($wynik['nieudane_razem'] ?? 0),
            ),
            StanKolejki::NIEDOSTEPNA => 'nie udało się odczytać stanu tabel kolejki.',
            default => 'nieznany stan kolejki.',
        };

        return implode(' ', [
            'kolejka:',
            $co,
            'Co zrobić: `php artisan kuking:sprawdz-kolejke`, potem `php artisan kuking:martwe-zadania`',
            '(niczego nie kasuje bez `--skasuj`). NIE ponawiaj zbiorczo starych zadań —',
            'żeton resetu hasła wygasa i ponowienie wysyła człowiekowi martwy link.',
        ]);
    }

    /**
     * DWA RÓŻNE ZEGARY. Cisza liczy się od DOSTARCZENIA (`dostarczony_o`),
     * a nie od próby — więc wiadomość, której kanał nie przyjął, nie kupuje
     * ani sekundy ciszy. Przerwa między próbami liczy się od OSTATNIEJ
     * PRÓBY (`proba_o`) i chroni wyłącznie przed pętlą żądań. Dzwonimy,
     * gdy minęły OBIE. Zmiana stanu przechodzi bez czekania.
     */
    private function wolnoDzwonic(string $stan): bool
    {
        $poprzedni = Cache::get(self::KLUCZ);

        if (! is_array($poprzedni) || ($poprzedni['stan'] ?? null) !== $stan) {
            return true;
        }

        $dostarczoneO = $this->dostarczoneO($poprzedni);
        $cisza = max(1, (int) config('kuking.kolejka.cisza_godzin')) * 3600;

        $najwczesniej = max(
            $dostarczoneO > 0 ? $dostarczoneO + $cisza : 0,
            $this->probaO($poprzedni) + self::PONOWIENIE_PO_NIEUDANEJ_MINUT * 60,
        );

        return $this->teraz() >= $najwczesniej;
    }

    /**
     * Odwołanie idzie natychmiast po dostarczonym alarmie — chyba że
     * poprzednia próba odwołania sama nie doszła.
     *
     * @param  array<string, mixed>  $poprzedni
     */
    private function wolnoPonowicOdwolanie(array $poprzedni): bool
    {
        if (($poprzedni['odwolanie_nieudane'] ?? false) !== true) {
            return true;
        }

        return ($this->teraz() - $this->probaO($poprzedni)) >= self::PONOWIENIE_PO_NIEUDANEJ_MINUT * 60;
    }

    private function zapamietajProbe(string $stan, bool $przyjeto): void
    {
        $teraz = $this->teraz();
        $poprzedni = Cache::get(self::KLUCZ);

        // Wcześniejsze DOSTARCZENIE tego samego stanu zostaje w pamięci,
        // żeby nieudane ponowienie nie skasowało okna ciszy, które należy
        // się wiadomości, która naprawdę doszła.
        $dostarczoneO = is_array($poprzedni) && ($poprzedni['stan'] ?? null) === $stan
            ? $this->dostarczoneO($poprzedni)
            : 0;

        Cache::put(self::KLUCZ, [
            'stan' => $stan,
            'proba_o' => $teraz,
            'dostarczony_o' => $przyjeto ? $teraz : $dostarczoneO,
        ], $this->pamiec());
    }

    /**
     * Kiedy kanał POTWIERDZIŁ ostatnią wiadomość o tym stanie. `0` = nigdy.
     *
     * @param  array<string, mixed>  $zapis
     */
    private function dostarczoneO(array $zapis): int
    {
        // `o` czytamy dla zapisów sprzed tej poprawki: powstawały wyłącznie
        // po (rzekomo) udanej wysyłce, więc ich znaczeniem było „dostarczone".
        return (int) ($zapis['dostarczony_o'] ?? $zapis['o'] ?? 0);
    }

    /**
     * Kiedy PRÓBOWALIŚMY ostatni raz — niezależnie od wyniku.
     *
     * @param  array<string, mixed>  $zapis
     */
    private function probaO(array $zapis): int
    {
        return (int) ($zapis['proba_o'] ?? $zapis['o'] ?? 0);
    }

    /** Zegar przez Carbona, nie `time()` — inaczej okien czasowych nie da się zmierzyć testem. */
    private function teraz(): int
    {
        return Carbon::now()->getTimestamp();
    }

    private function kanalWlaczony(): bool
    {
        // Brak adresu = kanał wyłączony — tak jest DZIŚ na produkcji (odczyt
        // listy zmiennych usługi, 17.09.2026: brak `LOG_BLAD_WEBHOOK_URL`).
        // Ten sam warunek stoi w `bootstrap/app.php`, `AlarmKopii`
        // i `AlarmPolaczen`.
        return ! blank(config('logging.channels.blad_webhook.url'));
    }

    /**
     * Czy kanał PRZYJĄŁ wiadomość — czyli czy odpowiedział 2xx.
     *
     * NAZWA JEST DOSŁOWNA I TAKA MA ZOSTAĆ. „Przyjął" znaczy: usługa po
     * drugiej stronie potwierdziła odbiór żądania. NIE znaczy: „człowiek to
     * zobaczył". Kto patrzy na kanał Discorda albo Slacka, na który wskazuje
     * webhook, jest poza zasięgiem tego kodu — dlatego ta metoda nie nazywa
     * się `dostarczono()` ani `powiadomiono()`.
     */
    private function kanalPrzyjal(string $tresc): bool
    {
        // CZYSTA KARTKA PRZED PRÓBĄ. Pamięć wyniku w handlerze jest
        // STATYCZNA, czyli wspólna dla całego procesu — a w jednym przebiegu
        // harmonogramu idą po sobie czujki kopii, połączeń i kolejki. Bez
        // wyzerowania cudzy sukces sprzed chwili zostałby odczytany jako nasz.
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        try {
            Log::channel('blad_webhook')->error($tresc);
        } catch (Throwable) {
            // Nieudane powiadomienie nie ma prawa przewrócić zadania
            // harmonogramu — w roli `all` błąd harmonogramu kładł kiedyś
            // cały kontener (`docker/entrypoint.sh`).
            return false;
        }

        // BRAK WYJĄTKU NIE JEST DOWODEM PRZYJĘCIA. Klient HTTP Laravela bez
        // `throw()` oddaje 404 i 500 jako zwykłą odpowiedź, a
        // `WebhookBleduHandler::write()` z zasady nigdy nie rzuca dalej —
        // więc `catch` wyżej nie złapie ANI JEDNEGO prawdziwego
        // niedodzwonienia się. Kod odpowiedzi zna handler i trzeba go
        // o niego zapytać. `null` (nie próbowaliśmy) też nie jest przyjęciem.
        return WebhookBleduHandler::ostatniaWysylkaSieUdala() === true;
    }

    private function pamiec(): \DateInterval
    {
        $godziny = max(1, (int) config('kuking.kolejka.cisza_godzin')) * 3;

        return new \DateInterval('PT'.$godziny.'H');
    }
}
