<?php

declare(strict_types=1);

namespace App\Domain\Polaczenia;

use App\Logging\KanalyAlarmowe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
 * czujka połączeń chodzi co godzinę, kolejki co kwadrans, więc pięć minut
 * nie pomija ANI JEDNEGO ich przebiegu — alarm o trwającej awarii dochodzi
 * przy pierwszym przebiegu po powrocie kanału do życia. Chroni natomiast
 * przed wołaniem w pętli: ręcznym `php artisan` obok harmonogramu i każdą
 * przyszłą pętlą ponowień.
 *
 * CZEGO TA PRZERWA NIE ROBI, żeby następna osoba nie liczyła na więcej:
 * zmiana stanu (`ostrzezenie` → `krytyczny` i z powrotem) omija OBA zegary,
 * bo eskalacja ma dochodzić natychmiast. Wartość migocząca wokół progu
 * wyśle więc wiadomość przy każdym przebiegu — zmierzone. To jest świadomy
 * wybór na rzecz eskalacji, nie przeoczenie.
 *
 * Wcześniejsza wersja tego komentarza uzasadniała pięć minut tym, że
 * `/health` woła tę czujkę kilka razy w jednym żądaniu. To był FAŁSZ:
 * `HealthController` ma własny, niezależny mechanizm (`powiadomWebhook()`
 * z własnym odstępem), a `zadzwonJesliTrzeba()` woła wyłącznie
 * `BudzetPolaczen` i `SprawdzKolejke` — w obu po dwa razy, ale na
 * ROZŁĄCZNYCH gałęziach, więc w jednym przebiegu wykonuje się jedno.
 *
 * DLACZEGO PAMIĘĆ W CACHE, A NIE W TABELI
 * Wyczyszczenie cache może powtórzyć trwający alarm oraz zgubić niewysłane
 * odwołanie. Ten ograniczony mechanizm nie jest trwałą kolejką doręczeń;
 * zmiana formatu poniżej nie zmienia tej granicy ani nie dodaje tabeli.
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
        $spokojny = $stan === StanPolaczenBazy::SPOKOJNY;

        if (! $spokojny && ! in_array($stan, self::ALARMUJACE, true)) {
            return false;
        }

        $zapis = Cache::get(self::KLUCZ);
        // Bez kanału i bez wcześniejszego alarmu nie tworzymy pamięci.
        // Istniejący alarm nadal obserwujemy: spokój unieważnia jego ciszę
        // także wtedy, gdy wysłanie odwołania jest chwilowo wyłączone.
        if (! is_array($zapis) && ! $this->kanalWlaczony()) {
            return false;
        }

        $pamiec = $this->odczytajStan(is_array($zapis) ? $zapis : []);
        $zmiana = $pamiec['stan'] !== $stan;
        if ($zmiana) {
            $pamiec['cisza_do'] = 0;
        }
        $pamiec['stan'] = $stan;

        if ($spokojny && $pamiec['dostarczony_o'] === 0) {
            Cache::forget(self::KLUCZ);

            return false;
        }

        Cache::put(self::KLUCZ, $pamiec, $this->pamiec());
        if (! $this->kanalWlaczony()) {
            return false;
        }

        // Zmiana obserwowanego stanu jest nową informacją. Dla tego samego
        // stanu osobno sprawdzamy termin ciszy i krótką przerwę po próbie.
        if (! $zmiana && $pamiec['proba_stan'] === $stan && $this->teraz() < max(
            $pamiec['cisza_do'],
            $pamiec['proba_o'] + self::PONOWIENIE_PO_NIEUDANEJ_MINUT * 60,
        )) {
            return false;
        }

        $tresc = $spokojny
            ? sprintf('połączenia PostgreSQL wróciły do normy (poprzedni stan: %s).', $pamiec['przyjety_stan'])
            : $this->tresc($wynik);
        $przyjeto = $this->kanalPrzyjal($tresc);

        if ($spokojny && $przyjeto) {
            Cache::forget(self::KLUCZ);

            return true;
        }

        $pamiec['proba_stan'] = $stan;
        $pamiec['proba_o'] = $this->teraz();
        if ($przyjeto) {
            $pamiec['przyjety_stan'] = $stan;
            $pamiec['dostarczony_o'] = $this->teraz();
            $pamiec['cisza_do'] = $this->teraz() + max(1, (int) config('kuking.polaczenia.cisza_godzin')) * 3600;
        }
        // Porażka nie nadpisuje przyjętego alarmu ani nie odtwarza ciszy
        // zakończonego epizodu. Odwołujemy ostatni PRZYJĘTY stan.
        Cache::put(self::KLUCZ, $pamiec, $this->pamiec());

        return $przyjeto;
    }

    /**
     * @param  array<string, mixed>  $zapis
     * @return array{wersja: int, stan: string, proba_stan: string, proba_o: int, przyjety_stan: string, dostarczony_o: int, cisza_do: int}
     */
    private function odczytajStan(array $zapis): array
    {
        if (($zapis['wersja'] ?? null) === 2) {
            return [
                'wersja' => 2,
                'stan' => (string) ($zapis['stan'] ?? ''),
                'proba_stan' => (string) ($zapis['proba_stan'] ?? ''),
                'proba_o' => (int) ($zapis['proba_o'] ?? 0),
                'przyjety_stan' => (string) ($zapis['przyjety_stan'] ?? ''),
                'dostarczony_o' => (int) ($zapis['dostarczony_o'] ?? 0),
                'cisza_do' => (int) ($zapis['cisza_do'] ?? 0),
            ];
        }

        // Stare „o” oznacza tylko próbę. Nowszy dostarczony_o zachowuje
        // dowód przyjęcia, ale nie daje ciszy: wadliwy format nie pozwala
        // odróżnić ponownej awarii od nadal trwającego epizodu.
        $stan = (string) ($zapis['stan'] ?? '');
        $obserwowany = ($zapis['epizod_zamkniety'] ?? false) === true ? StanPolaczenBazy::SPOKOJNY : $stan;
        $przyjetoO = (int) ($zapis['dostarczony_o'] ?? 0);

        return [
            'wersja' => 2,
            'stan' => $obserwowany,
            'proba_stan' => ($zapis['odwolanie_nieudane'] ?? false) === true ? StanPolaczenBazy::SPOKOJNY : $stan,
            'proba_o' => (int) ($zapis['proba_o'] ?? $zapis['o'] ?? 0),
            'przyjety_stan' => $przyjetoO > 0 ? $stan : '',
            'dostarczony_o' => $przyjetoO,
            'cisza_do' => 0,
        ];
    }

    /** @param array<string, mixed> $wynik */
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
        return KanalyAlarmowe::jakikolwiekWlaczony();
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
        KanalyAlarmowe::zapomnijOstatnieWysylki();

        try {
            KanalyAlarmowe::zadzwon($tresc);
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
        return KanalyAlarmowe::ktorysPrzyjal() === true;
    }

    private function pamiec(): \DateInterval
    {
        $godziny = max(1, (int) config('kuking.polaczenia.cisza_godzin')) * 3;

        return new \DateInterval('PT'.$godziny.'H');
    }
}
