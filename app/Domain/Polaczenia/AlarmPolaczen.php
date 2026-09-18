<?php

declare(strict_types=1);

namespace App\Domain\Polaczenia;

use App\Logging\WebhookBleduHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * „Pula połączeń PostgreSQL się zapełnia" na webhook właściciela (issue #598).
 *
 * TEN SAM KANAŁ, CO BŁĘDY 500 I CZUJKA KOPII (`blad_webhook`, D-041).
 * Właściciel ma JEDNO miejsce, w które patrzy; drugie znaczyłoby drugie
 * miejsce do niepatrzenia. Wzorzec jest przepisany z `App\Domain\Kopie\AlarmKopii`
 * celowo — nie budujemy drugiego mechanizmu alarmowania.
 *
 * DWIE RZECZY, KTÓRYCH `AlarmKopii` NIE ROBI, A TA KLASA MUSI (issue #599)
 *
 * 1. OGRANICZENIE POWTÓRZEŃ. Czujka kopii chodzi raz na dobę, więc powtórka
 *    jest raz na dobę. Ta chodzi co godzinę, a stan „za dużo połączeń" trwa
 *    godzinami — bez ograniczenia dałby 24 identyczne wiadomości na dobę
 *    i nauczyłby ignorować kanał. Alarm wychodzi więc przy ZMIANIE stanu
 *    i nie częściej niż raz na `cisza_godzin` przy stanie niezmienionym.
 *
 * 2. POWRÓT DO ZDROWIA. Alarm bez odwołania zostawia człowieka z pytaniem
 *    „czy to jeszcze trwa". Po powrocie do `spokojny` wychodzi DOKŁADNIE
 *    JEDNA wiadomość i pamięć stanu się zeruje.
 *
 * CISZA NALEŻY SIĘ ZA DZWONEK, KTÓRY KANAŁ PRZYJĄŁ — NIE ZA SAMĄ PRÓBĘ
 * (poprawka do #599; usterkę zmierzył odbiór #676).
 * Do 18.09.2026 `wyslij()` uznawał wysyłkę za udaną na SAM BRAK WYJĄTKU.
 * Klient HTTP Laravela bez `throw()` oddaje 404 z odwołanego webhooka i 500
 * z zepsutego jako ZWYKŁĄ odpowiedź, a `WebhookBleduHandler::write()`
 * z zasady nigdy nie rzuca dalej — więc `try/catch` nie łapał tu ani jednego
 * prawdziwego przypadku niedodzwonienia się. Skutek: jedna nieudana próba
 * zapisywała pamięć wyciszania i zagłuszała następny, SPRAWNY dzwonek
 * o wciąż trwającej awarii. Dokładnie tę samą usterkę naprawiały wcześniej
 * `HealthController::powiadomWebhook()` i `kuking:sprawdz-alarm`.
 *
 * KOMPROMIS: „OCHRONA PRZED LAWINĄ" KONTRA „PORAŻKA TO NIE DOSTARCZENIE"
 * Te dwie potrzeby stoją naprzeciw siebie i rozstrzygnięcie jest takie:
 *
 *   - CISZA (`cisza_godzin`, godziny) należy się wyłącznie wiadomości, którą
 *     kanał POTWIERDZIŁ. Porażki nie wolno utożsamiać z dostarczeniem —
 *     inaczej zepsuty webhook kupuje ciszę o prawdziwej awarii;
 *   - PRZERWA MIĘDZY PRÓBAMI (`PONOWIENIE_PO_NIEUDANEJ_MINUT`, minuty)
 *     należy się każdej próbie, także nieudanej. Ona nie kupuje ciszy —
 *     ogranicza wyłącznie LICZBĘ ŻĄDAŃ do martwego kanału.
 *
 * Przerwa jest o dwa rzędy wielkości krótsza od ciszy i to jest cały sens:
 * przy czujce chodzącej co godzinę nie pomija ANI JEDNEGO przebiegu, więc
 * alarm o trwającej awarii dochodzi przy pierwszym przebiegu po powrocie
 * kanału do życia. Chroni natomiast przed pętlą tam, gdzie ta sama czujka
 * zostałaby zawołana kilka razy pod rząd w jednym procesie — a to się
 * zdarza (`/health` dzwoni po kilka razy w jednym żądaniu).
 *
 * DLACZEGO PAMIĘĆ W CACHE, A NIE W TABELI
 * Bo zapomnienie tego stanu jest nieszkodliwe: najgorsze, co się stanie po
 * wyczyszczeniu cache, to jedna nadmiarowa wiadomość o stanie, który i tak
 * trwa. Osobna tabela i migracja byłyby tu kosztem bez pokrycia.
 *
 * CO STĄD WYCHODZI — LISTA ZAMKNIĘTA
 * stan, liczba zajętych backendów, liczba dostępnych miejsc, próg i zdanie
 * mówiące, CO ZROBIĆ. Bez nazw baz, użytkowników, hostów i treści zapytań.
 */
final class AlarmPolaczen
{
    private const KLUCZ = 'kuking:polaczenia:ostatni-alarm';

    /**
     * Ile czekamy z PONOWIENIEM próby, której kanał nie potwierdził.
     *
     * To NIE jest cisza o awarii (ta stoi w `cisza_godzin` i liczy się
     * w godzinach) — to jest wyłącznie odstęp między ŻĄDANIAMI do kanału,
     * który nie odpowiada jak trzeba. Uzasadnienie wielkości: czujka chodzi
     * co godzinę, więc pięć minut nie pomija żadnego jej przebiegu, a mimo
     * to ogranicza wołanie w pętli do 12 żądań na godzinę.
     */
    private const PONOWIENIE_PO_NIEUDANEJ_MINUT = 5;

    /** Stany, które są ALARMEM. `spokojny` i `nieobslugiwany` nie dzwonią. */
    private const ALARMUJACE = [
        StanPolaczenBazy::OSTRZEZENIE,
        StanPolaczenBazy::KRYTYCZNY,
        StanPolaczenBazy::NIEDOSTEPNY,
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

    /**
     * Jedna wiadomość o powrocie do normy — i tylko wtedy, gdy wcześniej
     * cokolwiek DOSZŁO. Bez tego warunku kanał dostawałby „wszystko OK"
     * co godzinę, czyli dokładnie ten szum, którego ta klasa ma unikać.
     */
    private function odwolajJesliTrzeba(string $stan): bool
    {
        if ($stan !== StanPolaczenBazy::SPOKOJNY || ! $this->kanalWlaczony()) {
            return false;
        }

        $poprzedni = Cache::get(self::KLUCZ);

        if (! is_array($poprzedni)) {
            return false;
        }

        // ALARMU, KTÓRY DO NIKOGO NIE DOSZEDŁ, NIE MA CZEGO ODWOŁYWAĆ.
        // „Połączenia wróciły do normy (poprzedni stan: krytyczny)" wysłane
        // po awarii, o której właściciel nigdy się nie dowiedział, jest
        // gorsze niż cisza: opisuje zdarzenie, którego nikt nie widział.
        if ($this->dostarczoneO($poprzedni) === 0) {
            Cache::forget(self::KLUCZ);

            return false;
        }

        if (! $this->wolnoPonowicOdwolanie($poprzedni)) {
            return false;
        }

        $przyjeto = $this->kanalPrzyjal(sprintf(
            'połączenia PostgreSQL wróciły do normy (poprzedni stan: %s).',
            (string) ($poprzedni['stan'] ?? 'nieznany'),
        ));

        if (! $przyjeto) {
            // Odwołanie, którego kanał nie potwierdził, NIE jest odwołaniem.
            // Pamięć zostaje — skasowana znaczyłaby „odwołane" i człowiek
            // zostałby z alarmem bez zakończenia. Następny przebieg czujki
            // spróbuje jeszcze raz, po krótkiej przerwie.
            $poprzedni['proba_o'] = $this->teraz();
            $poprzedni['odwolanie_nieudane'] = true;
            Cache::put(self::KLUCZ, $poprzedni, $this->pamiec());

            return false;
        }

        Cache::forget(self::KLUCZ);

        return true;
    }

    /**
     * Metoda publiczna, bo to ONA jest przedmiotem testu „czego tu nie ma":
     * sprawdzenie treści musi dać się zrobić bez stawiania kanału logowania.
     *
     * @param  array<string, mixed>  $wynik
     */
    public function tresc(array $wynik): string
    {
        $stan = (string) ($wynik['stan'] ?? '');

        // Teksty są STAŁYMI z tego pliku, dobieranymi przez `match` po
        // wartości z listy stałych — nie składamy ich z niczego, co przyszło
        // z zewnątrz. Ta sama zasada, co w `AlarmKopii` i w handlerze webhooka.
        $co = match ($stan) {
            StanPolaczenBazy::KRYTYCZNY => sprintf(
                'zajętych backendów: %d z %d dostępnych miejsc (próg krytyczny %d).',
                (int) ($wynik['zajete_serwer'] ?? 0),
                (int) ($wynik['dostepne'] ?? 0),
                (int) ($wynik['prog_krytyczny'] ?? 0),
            ),
            StanPolaczenBazy::OSTRZEZENIE => sprintf(
                'zajętych backendów: %d, a policzony budżet szczytowy tej topologii to %d (próg ostrzegawczy %d).',
                (int) ($wynik['zajete_serwer'] ?? 0),
                (int) ($wynik['budzet_szczytowy'] ?? 0),
                (int) ($wynik['prog_ostrzegawczy'] ?? 0),
            ),
            StanPolaczenBazy::NIEDOSTEPNY => 'nie udało się odczytać stanu połączeń z serwera bazy.',
            default => 'nieznany stan połączeń.',
        };

        return implode(' ', [
            'połączenia PostgreSQL:',
            $co,
            'Co zrobić: NIE dokładaj replik ani workerów, zanim nie wiadomo, co je zajmuje.',
            'Najpierw `php artisan kuking:budzet-polaczen`, potem porównaj z budżetem w docs/DATABASE.md.',
        ]);
    }

    /**
     * Zmiana stanu dzwoni od razu. Ten sam stan — nie częściej niż raz na
     * `cisza_godzin`. Eskalacja `ostrzezenie` → `krytyczny` jest ZMIANĄ
     * stanu, więc przechodzi bez czekania; to jest cały sens tego warunku.
     *
     * DWA RÓŻNE ZEGARY, I TO JEST TU NAJWAŻNIEJSZE ZDANIE. Cisza liczy się
     * od DOSTARCZENIA (`dostarczony_o`), a nie od próby — więc wiadomość,
     * której kanał nie przyjął, nie kupuje ani sekundy ciszy. Przerwa między
     * próbami liczy się od OSTATNIEJ PRÓBY (`proba_o`) i chroni wyłącznie
     * przed pętlą żądań. Dzwonimy, gdy minęły OBIE.
     */
    private function wolnoDzwonic(string $stan): bool
    {
        $poprzedni = Cache::get(self::KLUCZ);

        if (! is_array($poprzedni) || ($poprzedni['stan'] ?? null) !== $stan) {
            return true;
        }

        $dostarczoneO = $this->dostarczoneO($poprzedni);
        $cisza = max(1, (int) config('kuking.polaczenia.cisza_godzin')) * 3600;

        $najwczesniej = max(
            $dostarczoneO > 0 ? $dostarczoneO + $cisza : 0,
            $this->probaO($poprzedni) + self::PONOWIENIE_PO_NIEUDANEJ_MINUT * 60,
        );

        return $this->teraz() >= $najwczesniej;
    }

    /**
     * Odwołanie idzie natychmiast po dostarczonym alarmie — chyba że
     * poprzednia próba odwołania sama nie doszła. Wtedy obowiązuje ta sama
     * krótka przerwa, co przy alarmie.
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
        // Brak adresu = kanał wyłączony — tak jest dziś na produkcji (stan
        // zmierzony 17.09.2026: zmienna LOG_BLAD_WEBHOOK_URL nie istnieje
        // w usłudze). Ten sam warunek stoi w `bootstrap/app.php`
        // i w `AlarmKopii`.
        return ! blank(config('logging.channels.blad_webhook.url'));
    }

    /**
     * Czy kanał PRZYJĄŁ wiadomość — czyli czy odpowiedział 2xx.
     *
     * NAZWA JEST DOSŁOWNA I TAKA MA ZOSTAĆ. „Przyjął" znaczy: usługa po
     * drugiej stronie potwierdziła odbiór żądania. NIE znaczy: „człowiek to
     * zobaczył". To, czy ktokolwiek patrzy na kanał Discorda albo Slacka,
     * na który wskazuje webhook, jest poza zasięgiem tego kodu i nie da się
     * tego stąd sprawdzić — dlatego ta metoda nie nazywa się `dostarczono()`
     * ani `powiadomiono()`. Ta sama granica stoi w `kuking:sprawdz-alarm`
     * i w §7.4 `docs/infra/MONITORING_BLEDOW.md`.
     */
    private function kanalPrzyjal(string $tresc): bool
    {
        // CZYSTA KARTKA PRZED PRÓBĄ. Pamięć wyniku w handlerze jest
        // STATYCZNA, czyli wspólna dla całego procesu — a w jednym przebiegu
        // harmonogramu idą po sobie czujki kopii, połączeń i kolejki. Bez
        // wyzerowania cudzy sukces sprzed chwili zostałby odczytany jako nasz
        // (handler nie dotyka tej pamięci, gdy kanał ma pusty adres).
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        try {
            Log::channel('blad_webhook')->error($tresc);
        } catch (Throwable) {
            // Nieudane powiadomienie nie ma prawa przewrócić zadania
            // harmonogramu — w roli `all` błąd harmonogramu kładł kiedyś
            // cały kontener (`docker/entrypoint.sh`).
            return false;
        }

        // BRAK WYJĄTKU NIE JEST DOWODEM PRZYJĘCIA — i to jest sedno tej
        // poprawki. Klient HTTP Laravela bez `throw()` oddaje 404 i 500 jako
        // zwykłą odpowiedź, a `WebhookBleduHandler::write()` z zasady nigdy
        // nie rzuca dalej, więc `catch` wyżej nie złapie ANI JEDNEGO
        // prawdziwego niedodzwonienia się. Kod odpowiedzi zna handler
        // i trzeba go o niego zapytać. `null` (nie próbowaliśmy — kanał
        // zbudowany z pustym adresem) też nie jest przyjęciem.
        return WebhookBleduHandler::ostatniaWysylkaSieUdala() === true;
    }

    private function pamiec(): \DateInterval
    {
        $godziny = max(1, (int) config('kuking.polaczenia.cisza_godzin')) * 3;

        return new \DateInterval('PT'.$godziny.'H');
    }
}
