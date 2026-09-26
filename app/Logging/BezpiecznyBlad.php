<?php

declare(strict_types=1);

namespace App\Logging;

use Aws\Exception\AwsException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Opis wyjątku do logu OPERACYJNEGO — zamiast `$e->getMessage()` (#973).
 *
 * DLACZEGO NIE KOMUNIKAT
 * Komunikat wyjątku z cudzej biblioteki buduje ta biblioteka: sterownik bazy
 * wkłada SQL z wartościami (e-mail, hash hasła — audyt A6-01), klient
 * storage pełny klucz obiektu i adres żądania, klient HTTP fragment
 * odpowiedzi dostawcy, a każde z nich może nieść CR/LF. Stderr czyta
 * i przechowuje Railway. `BezDanychOsobowychWLogu` wycina tylko to, co umie
 * rozpoznać (e-mail, hash, SQL) — to siatka, nie granica.
 *
 * Tu działa LISTA DOZWOLONYCH PÓL, ta sama zasada co w `WebhookBleduHandler`
 * i w poprawce #828 (`PrzestawZgodeNaDigest`: klasa i SQLSTATE, nigdy
 * `getMessage()`): klasa, kod o bezpiecznym kształcie, klasy przyczyn
 * (`previous`) i odcisk. Ani jednego znaku z komunikatu.
 *
 * ODCISK liczy się tak samo jak w `WebhookBleduHandler::odcisk()` (klasa,
 * plik, linia), więc wpis z logu da się zestawić z dzwonkiem na webhooku.
 * Identyfikator encji (media_id, data_export_id, własny klucz obiektu) każde
 * wywołanie dokłada samo — to jest to, czego potrzeba do ręcznej naprawy.
 *
 * MIEJSCE, BO WEBHOOK TU NIE DZWONI
 * Wywołania tej klasy połykają wyjątek (sprzątanie, warianty, kasowanie) —
 * handler wyjątków go nie zobaczy, więc nie ma dzwonka z plikiem i linią,
 * a sam odcisk nie mówi, GDZIE szukać. Stąd `miejsce`: plik względem
 * katalogu projektu i linia rzutu, a gdy rzut padł w bibliotece (vendor/),
 * dodatkowo `miejsce_w_app` — pierwsza ramka z naszego `app/`. Przyczyny
 * dostają swoje miejsca w `miejsca_przyczyn` (ta sama kolejność co
 * `przyczyny`). Ścieżka pliku w repozytorium to nie dana osobowa; pliki spoza
 * katalogu projektu (np. kod z `eval`, katalog tymczasowy) idą samą nazwą.
 */
final class BezpiecznyBlad
{
    /** Ile przyczyn (`getPrevious()`) wypisać — łańcuch bywa długi. */
    private const PRZYCZYNY = 4;

    /**
     * @return array{wyjatek: class-string<Throwable>, kod?: int|string, miejsce: string, miejsce_w_app?: string, przyczyny?: list<string>, miejsca_przyczyn?: list<string|null>, odcisk: string}
     */
    public static function kontekst(Throwable $e): array
    {
        $opis = ['wyjatek' => $e::class];

        $kod = self::kod($e);

        if ($kod !== null) {
            $opis['kod'] = $kod;
        }

        $opis['miejsce'] = self::sciezka($e->getFile()).':'.$e->getLine();

        $wApp = self::miejsceWApp($e);

        if ($wApp !== null && $wApp !== $opis['miejsce']) {
            $opis['miejsce_w_app'] = $wApp;
        }

        $przyczyny = [];
        $miejscaPrzyczyn = [];

        for ($p = $e->getPrevious(); $p !== null && count($przyczyny) < self::PRZYCZYNY; $p = $p->getPrevious()) {
            $kodPrzyczyny = self::kod($p);
            $przyczyny[] = $p::class.($kodPrzyczyny !== null ? ' ('.$kodPrzyczyny.')' : '');
            $miejscaPrzyczyn[] = self::miejsceWApp($p);
        }

        if ($przyczyny !== []) {
            $opis['przyczyny'] = $przyczyny;
            $opis['miejsca_przyczyn'] = $miejscaPrzyczyn;
        }

        $opis['odcisk'] = substr(sha1($e::class.'|'.$e->getFile().'|'.$e->getLine()), 0, 8);

        return $opis;
    }

    /**
     * Pierwsza ramka z `app/`: sam rzut, jeśli padł w naszym kodzie, inaczej
     * najpłytsza ramka stosu z `app/`. Null, gdy stos nie dotyka `app/`.
     */
    private static function miejsceWApp(Throwable $e): ?string
    {
        $app = rtrim(app_path(), '/').'/';

        if (str_starts_with($e->getFile(), $app)) {
            return self::sciezka($e->getFile()).':'.$e->getLine();
        }

        foreach ($e->getTrace() as $ramka) {
            if (isset($ramka['file'], $ramka['line']) && str_starts_with($ramka['file'], $app)) {
                return self::sciezka($ramka['file']).':'.$ramka['line'];
            }
        }

        return null;
    }

    /** Ścieżka względem katalogu projektu; spoza niego — sama nazwa pliku. */
    private static function sciezka(string $plik): string
    {
        $baza = rtrim(base_path(), '/').'/';

        return str_starts_with($plik, $baza) ? substr($plik, strlen($baza)) : basename($plik);
    }

    /**
     * Kod, ale tylko o zamkniętym kształcie: SQLSTATE bazy, kod błędu AWS/R2
     * z HTTP (`NoSuchKey 404`) albo krótki kod liczbowo-literowy. Cokolwiek
     * innego pomijamy zamiast przycinać — przycięty tekst nadal mógłby nieść
     * fragment danych.
     */
    private static function kod(Throwable $e): int|string|null
    {
        if ($e instanceof QueryException || $e instanceof PDOException) {
            $sqlstate = $e instanceof PDOException && is_array($e->errorInfo) && isset($e->errorInfo[0])
                ? $e->errorInfo[0]
                : $e->getCode();

            return is_string($sqlstate) && preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1 ? $sqlstate : null;
        }

        if ($e instanceof AwsException) {
            $kodAws = (string) $e->getAwsErrorCode();
            $status = $e->getStatusCode();
            $czesci = array_filter([
                preg_match('/^[A-Za-z0-9_.]{1,40}$/', $kodAws) === 1 ? $kodAws : null,
                is_int($status) ? (string) $status : null,
            ]);

            return $czesci !== [] ? implode(' ', $czesci) : null;
        }

        $kod = $e->getCode();

        if (is_int($kod)) {
            return $kod === 0 ? null : $kod;
        }

        return preg_match('/^[A-Za-z0-9_]{1,20}$/', (string) $kod) === 1 ? (string) $kod : null;
    }
}
