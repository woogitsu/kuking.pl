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
 */
final class BezpiecznyBlad
{
    /** Ile przyczyn (`getPrevious()`) wypisać — łańcuch bywa długi. */
    private const PRZYCZYNY = 4;

    /**
     * @return array{wyjatek: class-string<Throwable>, kod?: int|string, przyczyny?: list<string>, odcisk: string}
     */
    public static function kontekst(Throwable $e): array
    {
        $opis = ['wyjatek' => $e::class];

        $kod = self::kod($e);

        if ($kod !== null) {
            $opis['kod'] = $kod;
        }

        $przyczyny = [];

        for ($p = $e->getPrevious(); $p !== null && count($przyczyny) < self::PRZYCZYNY; $p = $p->getPrevious()) {
            $kodPrzyczyny = self::kod($p);
            $przyczyny[] = $p::class.($kodPrzyczyny !== null ? ' ('.$kodPrzyczyny.')' : '');
        }

        if ($przyczyny !== []) {
            $opis['przyczyny'] = $przyczyny;
        }

        $opis['odcisk'] = substr(sha1($e::class.'|'.$e->getFile().'|'.$e->getLine()), 0, 8);

        return $opis;
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
