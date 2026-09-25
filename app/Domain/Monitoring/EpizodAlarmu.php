<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

use Closure;
use DateInterval;
use Illuminate\Support\Carbon;

/**
 * Jedna maszyna epizodu alarmowego dla czujek z pamięcią (#972).
 *
 * Do #972 ten algorytm stał dwa razy — w `AlarmKolejki` i `AlarmPolaczen` —
 * i każdą poprawkę z #687 trzeba było nanosić i sabotować osobno w obu.
 * Czujka dostarcza tu wyłącznie dane: klucz pamięci, stan spokojny, stany
 * alarmowe, długość ciszy i dwie funkcje budujące BEZPIECZNĄ treść.
 * `StanKolejki`, `StanPolaczenBazy` i treści instrukcji zostają w czujkach —
 * te różnice są domenowe.
 *
 * OGRANICZENIE POWTÓRZEŃ I POWRÓT DO ZDROWIA (#599)
 * Czujki chodzą co kwadrans albo co godzinę, a awaria trwa godzinami. Bez
 * ciszy dałoby to dziesiątki identycznych wiadomości i nauczyło ignorować
 * kanał. Zmiana stanu dzwoni od razu (także eskalacja), ten sam stan nie
 * częściej niż raz na `ciszaGodzin`, a powrót do spokoju daje DOKŁADNIE
 * JEDNĄ wiadomość odwołującą.
 *
 * KOMPROMIS: „OCHRONA PRZED LAWINĄ" KONTRA „PORAŻKA TO NIE DOSTARCZENIE"
 * (poprawka do #599; usterkę zmierzył odbiór #676)
 *
 *   - CISZA (godziny) należy się wyłącznie wiadomości, którą kanał
 *     POTWIERDZIŁ (`KanalAlarmowy::przyjal()`). Inaczej zepsuty webhook
 *     kupuje ciszę o prawdziwej awarii;
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
 * CZEGO TA PRZERWA NIE ROBI: zmiana stanu omija OBA zegary, bo eskalacja ma
 * dochodzić natychmiast. Wartość migocząca wokół progu wyśle więc wiadomość
 * przy każdym przebiegu — zmierzone. To świadomy wybór na rzecz eskalacji.
 *
 * ZNANE OGRANICZENIE PAMIĘCI
 * Pamięć mieszka w cache, a `docker/entrypoint.sh` czyści cache przy każdym
 * starcie kontenera. Po wdrożeniu trwający alarm zadzwoni raz dodatkowo,
 * a niewysłane odwołanie przepadnie. To jest świadomie zaakceptowane:
 * nadmiarowa wiadomość o prawdziwej awarii jest tańsza niż tabela dla stanu,
 * który wolno zgubić. SAMA OCENA stanu w czujkach jest bezstanowa, więc
 * restart nie generuje fałszywej awarii — tylko ewentualne powtórzenie.
 */
final class EpizodAlarmu
{
    /**
     * Ile czekamy z PONOWIENIEM próby, której kanał nie potwierdził.
     *
     * To NIE jest cisza o awarii (ta liczy się w godzinach) — to jest
     * wyłącznie odstęp między ŻĄDANIAMI do kanału, który nie odpowiada jak
     * trzeba. Najczęstsza czujka chodzi co kwadrans, więc pięć minut nie
     * pomija żadnego przebiegu.
     */
    private const PONOWIENIE_PO_NIEUDANEJ_MINUT = 5;

    public function __construct(
        private readonly AlarmMemory $pamiec,
        private readonly KanalAlarmowy $kanal,
    ) {}

    /**
     * @param  list<string>  $alarmujace  stany, które są ALARMEM; każdy inny
     *                                    niż `$spokojny` jest ignorowany
     * @param  Closure(): string  $trescAlarmu
     * @param  Closure(string): string  $trescOdwolania  dostaje ostatni PRZYJĘTY stan
     * @return bool czy kanał PRZYJĄŁ wiadomość (odpowiedź 2xx). To NIE jest
     *              to samo, co „ktoś ją zobaczył" — patrz `KanalAlarmowy`.
     */
    public function zadzwonJesliTrzeba(
        string $klucz,
        string $stan,
        string $spokojny,
        array $alarmujace,
        int $ciszaGodzin,
        Closure $trescAlarmu,
        Closure $trescOdwolania,
    ): bool {
        $jestSpokojny = $stan === $spokojny;

        if (! $jestSpokojny && ! in_array($stan, $alarmujace, true)) {
            return false;
        }

        $ciszaGodzin = max(1, $ciszaGodzin);
        $zapis = $this->pamiec->get($klucz);
        // Bez kanału i bez wcześniejszego alarmu nie tworzymy pamięci.
        // Istniejący alarm nadal obserwujemy: spokój unieważnia jego ciszę
        // także wtedy, gdy wysłanie odwołania jest chwilowo wyłączone.
        if (! is_array($zapis) && ! $this->kanal->wlaczony()) {
            return false;
        }

        $pamiec = $this->odczytajStan(is_array($zapis) ? $zapis : [], $spokojny);
        $zmiana = $pamiec['stan'] !== $stan;
        if ($zmiana) {
            $pamiec['cisza_do'] = 0;
        }
        $pamiec['stan'] = $stan;

        if ($jestSpokojny && $pamiec['dostarczony_o'] === 0) {
            $this->pamiec->forget($klucz);

            return false;
        }

        $this->pamiec->put($klucz, $pamiec, $this->waznosc($ciszaGodzin));
        if (! $this->kanal->wlaczony()) {
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

        $tresc = $jestSpokojny ? $trescOdwolania($pamiec['przyjety_stan']) : $trescAlarmu();
        $przyjeto = $this->kanal->przyjal($tresc);

        if ($jestSpokojny && $przyjeto) {
            $this->pamiec->forget($klucz);

            return true;
        }

        $pamiec['proba_stan'] = $stan;
        $pamiec['proba_o'] = $this->teraz();
        if ($przyjeto) {
            $pamiec['przyjety_stan'] = $stan;
            $pamiec['dostarczony_o'] = $this->teraz();
            $pamiec['cisza_do'] = $this->teraz() + $ciszaGodzin * 3600;
        }
        // Porażka nie nadpisuje przyjętego alarmu ani nie odtwarza ciszy
        // zakończonego epizodu. Odwołujemy ostatni PRZYJĘTY stan.
        $this->pamiec->put($klucz, $pamiec, $this->waznosc($ciszaGodzin));

        return $przyjeto;
    }

    /**
     * @param  array<string, mixed>  $zapis
     * @return array{wersja: int, stan: string, proba_stan: string, proba_o: int, przyjety_stan: string, dostarczony_o: int, cisza_do: int}
     */
    private function odczytajStan(array $zapis, string $spokojny): array
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
        $obserwowany = ($zapis['epizod_zamkniety'] ?? false) === true ? $spokojny : $stan;
        $przyjetoO = (int) ($zapis['dostarczony_o'] ?? 0);

        return [
            'wersja' => 2,
            'stan' => $obserwowany,
            'proba_stan' => ($zapis['odwolanie_nieudane'] ?? false) === true ? $spokojny : $stan,
            'proba_o' => (int) ($zapis['proba_o'] ?? $zapis['o'] ?? 0),
            'przyjety_stan' => $przyjetoO > 0 ? $stan : '',
            'dostarczony_o' => $przyjetoO,
            'cisza_do' => 0,
        ];
    }

    /** Zegar przez Carbona, nie `time()` — inaczej okien czasowych nie da się zmierzyć testem. */
    private function teraz(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /** Pamięć żyje trzy okna ciszy — dość, żeby doczekać odwołania. */
    private function waznosc(int $ciszaGodzin): DateInterval
    {
        return new DateInterval('PT'.($ciszaGodzin * 3).'H');
    }
}
