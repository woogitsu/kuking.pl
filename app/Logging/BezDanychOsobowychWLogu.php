<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\QueryException;
use JsonSerializable;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use PDOException;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Procesor Monologa dla logów SERWERA (stderr → Railway, pliki lokalnie):
 * usuwa z rekordu adresy e-mail, hashe haseł i komunikat bazy z wartościami.
 *
 * SKĄD SIĘ WZIĄŁ (audyt prywatności, 23 września 2026)
 * A6-01 naprawił webhook błędów, a `WebhookBleduHandler` pisał wtedy uczciwie:
 * „pełny komunikat zostaje w logu serwera, który nigdzie nie wychodzi". To
 * drugie było nieprawdą: produkcja ma `LOG_CHANNEL=stderr`
 * z `JsonFormatter` (`.railway/railway.ts`), a stderr czyta i PRZECHOWUJE
 * Railway — czyli zewnętrzny dostawca. `JsonFormatter` serializuje wyjątek
 * z komunikatem, a `QueryException` (i jego `previous`, `PDOException`) niesie
 * SQL razem z wartościami: e-mail i hash hasła człowieka. Laravel dokłada ten
 * sam komunikat jeszcze raz jako treść rekordu (`report()` loguje
 * `$e->getMessage()`). AGENTS.md §7: żadnego PII w logach.
 *
 * CO ROBI
 * 1. Każdy wyjątek w kontekście zamienia na tablicę o kształcie, jaki
 *    produkuje `NormalizerFormatter` (klasa, komunikat, kod, plik:linia,
 *    ślad jako plik:linia, `previous`) — z oczyszczonym komunikatem. Obiektu
 *    wyjątku nie da się „poprawić" w miejscu, a zostawiony trafiłby do
 *    formatera w całości. UWAGA: ślad jest tu ZAWSZE — `JsonFormatter`
 *    z produkcji ma `includeStacktraces = false` i dla obiektu wyjątku śladu
 *    nie wypisywał, ale gotowej tablicy już nie przycina. Wpis jest więc
 *    dłuższy niż przed tym procesorem (ślad to same plik:linia, bez danych).
 * 2. Komunikatu `QueryException`/`PDOException` NIE czyścimy wyrażeniem
 *    regularnym, tylko budujemy od nowa z pól bez wartości: SQLSTATE, rodzaj
 *    operacji i tabela (z SQL-a z `?`, nie z komunikatu), nazwa ograniczenia.
 *    Ta sama zasada listy dozwolonych pól, co w `WebhookBleduHandler`.
 * 3. Wszędzie indziej (treść rekordu, łańcuchy w `context` i `extra`,
 *    komunikaty zwykłych wyjątków) zamienia rzeczy wyglądające na e-mail
 *    albo hash hasła (`$2y$…`, `$argon2id$…`) na znacznik. To siatka
 *    bezpieczeństwa, nie gwarancja — gwarancją jest punkt 2.
 *
 * 4. Obiekty, które nie są wyjątkiem (model w `['user' => $user]`), są
 *    serializowane TU (`toArray()`/`jsonSerialize()`/`__toString()`) i dopiero
 *    wynik jest czyszczony — inaczej formater wypisałby je w całości za
 *    plecami procesora. Obiekt bez żadnej z tych dróg → sama nazwa klasy.
 *    Klucze tablic są czyszczone jak wartości. Głębiej niż `GLEBOKOSC`
 *    zamiast wartości idzie znacznik `ZA_GLEBOKO`, nie surowa tablica.
 * 5. Gdy wyrażenie regularne zawiedzie (błąd PCRE), cały tekst zamienia się
 *    na `BLAD_FILTRA` — ani pusty łańcuch, ani oryginał. Wzorce są pisane
 *    tak, żeby do tego nie dochodziło (patrz `WZORZEC_EMAIL`); znacznik to
 *    bezpiecznik na tekst rzędu megabajtów, nie na złośliwy komentarz.
 *
 * CZEGO NIE RUSZA: rekord bez PII przechodzi bajt w bajt (test kontroli
 * dodatniej); liczby, daty i enumy zostają obiektami. Nie jest podpięty pod
 * `blad_webhook` — tamten handler nie czyta komunikatu w ogóle, a POTRZEBUJE
 * prawdziwego obiektu wyjątku w `context['exception']` (klasa, plik:linia,
 * odcisk). Dlatego `FiltrDanychOsobowych` wiesza ten procesor na HANDLERZE,
 * nie na loggerze: kanał `stack` zbiera procesory loggerów kanałów
 * składowych i puściłby je także na handler webhooka.
 *
 * Komunikatów obcych wyjątków w logach operacyjnych nie należy tu „ratować"
 * — do tego jest `BezpiecznyBlad::kontekst()` (#973): lista dozwolonych pól
 * zamiast wyrażeń regularnych.
 */
final class BezDanychOsobowychWLogu implements ProcessorInterface
{
    public const EMAIL = '[e-mail usunięty]';

    public const HASH = '[hash hasła usunięty]';

    /**
     * Wstawiany ZAMIAST całego tekstu, gdy wyrażenie regularne zawiedzie
     * (np. „Backtrack limit exhausted" na tekście rzędu megabajtów).
     * `preg_replace()` oddaje wtedy `null` — a `(string) null` to pusty
     * łańcuch, czyli wpis po cichu znikał z logu. Oryginału w tym miejscu
     * NIE zostawiamy: nie wiemy, czy niesie e-mail, bo sprawdzenie padło.
     */
    public const BLAD_FILTRA = '[treść usunięta z logu: filtr danych osobowych nie dał rady]';

    public const ZA_GLEBOKO = '[pominięte: zagnieżdżenie głębsze niż filtr sprawdza]';

    /**
     * Adres e-mail BEZ katastrofalnego nawracania. Dawny wzorzec
     * (`…+@[…]+(?:\.[…]+)*\.[A-Za-z]{2,}`) na ~20 KB tekstu `x@a.a.a.…`
     * wyczerpywał stos JIT — a wtedy CAŁA wartość zamieniała się w
     * `BLAD_FILTRA`, czyli ktoś jednym komentarzem wycinał z logu wpis.
     *
     * - `(?<!…)` — lokalną część zaczynamy tylko na początku ciągu jej znaków
     *   (start w środku dałby ten sam przyrostek, więc nic nie gubimy), zamiast
     *   próbować od każdej pozycji — koniec z kosztem kwadratowym;
     * - kwantyfikatory posesywne (`++`, `*+`) — domena nie oddaje raz
     *   zjedzonych etykiet, więc nie ma stosu nawrotów;
     * - wymóg „kropka i dwie litery" w domenie jest w lookahead, a nie na końcu
     *   wzorca. Różnica wobec dawnego: znacznik obejmuje całą domenę
     *   (`a@b.com1` → cały znacznik), co jest nadmiarem ostrożności, nie luką.
     */
    private const WZORZEC_EMAIL = '/(?<![A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]++@(?=[A-Za-z0-9\-.]*?\.[A-Za-z]{2})[A-Za-z0-9\-]++(?:\.[A-Za-z0-9\-]++)*+/';

    private const WZORZEC_HASH = '/\$2[abxy]?\$\d{2}\$[.\/A-Za-z0-9]{53}|\$argon2(?:id|i|d)\$[^\s,)\'"]+/';

    private const WZORZEC_SUROWEGO_SQL = '/SQLSTATE\[([A-Z0-9]{5})\]:.*/s';

    private const ZNACZNIK_BAZY = ' (treść komunikatu bazy z wartościami usunięta z logu)';

    /** Ograniczenie głębokości — kontekst logu bywa dowolnie zagnieżdżony. */
    private const GLEBOKOSC = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $record->message;

        foreach ($record->context as $wartosc) {
            if ($wartosc instanceof Throwable) {
                // Laravel loguje `$e->getMessage()` jako treść rekordu —
                // podmieniamy ją na tę samą bezpieczną wersję, co w kontekście.
                $message = $this->podmienKomunikat($message, $wartosc);
            }
        }

        $message = $this->oczyscTekst($message);
        $context = $this->oczysc($record->context, 0);
        $extra = $this->oczysc($record->extra, 0);

        if ($message === $record->message && $context === $record->context && $extra === $record->extra) {
            return $record;
        }

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    private function podmienKomunikat(string $message, Throwable $e): string
    {
        do {
            $surowy = $e->getMessage();

            if ($surowy !== '' && str_contains($message, $surowy)) {
                $message = str_replace($surowy, $this->komunikat($e), $message);
            }

            $e = $e->getPrevious();
        } while ($e !== null);

        return $message;
    }

    private function oczysc(mixed $wartosc, int $glebokosc): mixed
    {
        if ($wartosc instanceof Throwable) {
            return $this->wyjatek($wartosc, $glebokosc);
        }

        if (is_string($wartosc)) {
            return $this->oczyscTekst($wartosc);
        }

        if ($wartosc === null || is_scalar($wartosc) || $wartosc instanceof UnitEnum || $wartosc instanceof DateTimeInterface) {
            // Liczby, daty i enumy nie niosą e-maila ani hasha — a zostawione
            // w oryginale formatują się dokładnie tak jak dotąd.
            return $wartosc;
        }

        if ($glebokosc >= self::GLEBOKOSC) {
            // Dawniej: tablica głębiej niż limit szła do formatera SUROWA,
            // razem z tym, co niosła. Znacznik zamiast wartości — nikt nie
            // loguje dziewięciu poziomów tablic w dobrej wierze.
            return self::ZA_GLEBOKO;
        }

        if (is_array($wartosc)) {
            $czysta = [];

            foreach ($wartosc as $klucz => $element) {
                // Klucze też: `['basia@wp.pl' => 3]` to ten sam adres.
                if (is_string($klucz)) {
                    $klucz = $this->oczyscTekst($klucz);

                    while (array_key_exists($klucz, $czysta)) {
                        $klucz .= '*';
                    }
                }

                $czysta[$klucz] = $this->oczysc($element, $glebokosc + 1);
            }

            return $czysta;
        }

        if (is_object($wartosc)) {
            return $this->obiekt($wartosc, $glebokosc);
        }

        // Zasób (resource) — formater i tak wypisze tylko jego rodzaj.
        return $wartosc;
    }

    /**
     * Obiekt, który nie jest wyjątkiem — np. model w `['user' => $user]`.
     * Zostawiony formaterowi zostałby zserializowany Z CAŁĄ ZAWARTOŚCIĄ
     * (`JsonSerializable`/`toArray()`: e-mail użytkownika), a procesor by go
     * nie zobaczył. Więc serializujemy go tu i czyścimy wynik; obiekt bez
     * żadnej z tych dróg zastępujemy samą nazwą klasy — formater wypisałby
     * jego publiczne pola, których nie znamy.
     */
    private function obiekt(object $obiekt, int $glebokosc): mixed
    {
        try {
            $dane = match (true) {
                $obiekt instanceof Arrayable => $obiekt->toArray(),
                $obiekt instanceof JsonSerializable => $obiekt->jsonSerialize(),
                $obiekt instanceof Stringable => (string) $obiekt,
                default => null,
            };
        } catch (Throwable) {
            $dane = null;
        }

        if ($dane === null) {
            return '['.$obiekt::class.']';
        }

        return [$obiekt::class => $this->oczysc($dane, $glebokosc + 1)];
    }

    /**
     * @return array<string, mixed>
     */
    private function wyjatek(Throwable $e, int $glebokosc): array
    {
        $dane = [
            'class' => $e::class,
            'message' => $this->komunikat($e),
            'code' => $this->kod($e),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => array_values(array_filter(array_map(
                static fn (array $ramka): ?string => isset($ramka['file'])
                    ? $ramka['file'].':'.($ramka['line'] ?? 0)
                    : null,
                $e->getTrace(),
            ))),
        ];

        if ($e->getPrevious() !== null && $glebokosc < self::GLEBOKOSC) {
            $dane['previous'] = $this->wyjatek($e->getPrevious(), $glebokosc + 1);
        }

        return $dane;
    }

    private function komunikat(Throwable $e): string
    {
        if ($e instanceof QueryException || $e instanceof PDOException) {
            return $this->komunikatBazy($e);
        }

        return $this->oczyscTekst($e->getMessage());
    }

    /**
     * Komunikat bazy zbudowany od zera, bez ani jednego znaku z oryginału
     * poza SQLSTATE i nazwą ograniczenia o bezpiecznym kształcie.
     */
    private function komunikatBazy(QueryException|PDOException $e): string
    {
        $czesci = ['SQLSTATE['.($this->sqlstate($e) ?? '?????').']'];

        if ($e instanceof QueryException) {
            $operacja = $this->operacja($e->getSql());

            if ($operacja !== null) {
                $czesci[] = $operacja;
            }

            $czesci[] = 'połączenie: '.$e->getConnectionName();
        }

        if (preg_match('/constraint "([A-Za-z0-9_]{1,63})"/', $e->getMessage(), $m) === 1) {
            $czesci[] = 'ograniczenie: '.$m[1];
        }

        return implode(', ', $czesci).self::ZNACZNIK_BAZY;
    }

    private function sqlstate(Throwable $e): ?string
    {
        $kod = $e instanceof PDOException && is_array($e->errorInfo) && isset($e->errorInfo[0])
            ? $e->errorInfo[0]
            : $e->getCode();

        return is_string($kod) && preg_match('/^[A-Z0-9]{5}$/', $kod) === 1 ? $kod : null;
    }

    /**
     * „insert into users" z SQL-a z symbolami `?` — BEZ bindingów. Bierzemy
     * wyłącznie słowo kluczowe i nazwę tabeli, nigdy resztę zapytania: kod
     * może wstawić literał wprost w `whereRaw()`.
     */
    private function operacja(string $sql): ?string
    {
        $wzorzec = '/^\s*(?:(insert\s+into|update|delete\s+from)|select\b.*?\bfrom)\s+"?([A-Za-z0-9_]{1,63})"?/is';

        if (preg_match($wzorzec, $sql, $m) !== 1) {
            return null;
        }

        $slowo = $m[1] !== '' ? strtolower((string) preg_replace('/\s+/', ' ', $m[1])) : 'select from';

        return $slowo.' '.$m[2];
    }

    private function kod(Throwable $e): int|string
    {
        $kod = $e->getCode();

        return is_int($kod) || preg_match('/^[A-Za-z0-9_]{1,16}$/', (string) $kod) === 1 ? $kod : 0;
    }

    private function oczyscTekst(string $tekst): string
    {
        // Szybka ścieżka: bez „@", „$" i „SQLSTATE[" nie ma czego szukać —
        // zwykły log przechodzi bez żadnego wyrażenia regularnego.
        if (! str_contains($tekst, '@') && ! str_contains($tekst, '$') && ! str_contains($tekst, 'SQLSTATE[')) {
            return $tekst;
        }

        $czysty = preg_replace(
            // Surowy komunikat sterownika wklejony jako tekst (np. ktoś
            // zalogował `$e->getMessage()` bez obiektu wyjątku): po
            // „SQLSTATE[xxxxx]:" idzie DETAIL i SQL z wartościami, więc ucinamy
            // całą resztę. Nasz przebudowany komunikat ma po nawiasie
            // przecinek albo spację, nie dwukropek — tego wzorzec nie rusza.
            [self::WZORZEC_SUROWEGO_SQL, self::WZORZEC_HASH, self::WZORZEC_EMAIL],
            ['SQLSTATE[$1]'.self::ZNACZNIK_BAZY, self::HASH, self::EMAIL],
            $tekst,
        );

        // `null` = błąd PCRE (limit stosu JIT, backtracking). Ani pusty
        // łańcuch (wpis znikał po cichu), ani oryginał (nie wiemy, co niesie).
        return $czysty ?? self::BLAD_FILTRA.' (długość: '.strlen($tekst).' B)';
    }
}
