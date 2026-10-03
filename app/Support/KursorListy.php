<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator as KursorLaravela;
use Illuminate\Support\Str;
use LogicException;

/**
 * KURSOR Z ADRESU — JEDNO MIEJSCE, W KTÓRYM GO SPRAWDZAMY (#2308).
 *
 * `?cursor=` przychodzi od klienta. `Cursor::fromEncoded()` Laravela uznaje
 * za poprawny KAŻDY JSON z kluczem `_pointsToNextItems`, a dopiero potem
 * `cursorPaginate()` sięga po wartość kolumny sortowania:
 *
 *  - brak kolumny (`{"_pointsToNextItems":true}`) → `UnexpectedValueException`;
 *  - napis, który nie jest UUID-em ani czasem (`{"id":"abc",…}`) → Postgres
 *    `SQLSTATE[22P02]`/`[22007]`;
 *  - tablica zamiast wartości → błąd wiązania parametru.
 *
 * Każde z nich to HTTP 500 i alarm na Discordzie z adresu, który każdy może
 * wpisać albo dostać w zepsutym odnośniku. Tu kursor przechodzi dalej tylko
 * wtedy, gdy ma DOKŁADNIE kolumny sortowania tej listy i każda wartość ma
 * typ swojej kolumny. W każdym innym razie lista zaczyna się od pierwszej
 * strony — tak samo jak przy kursorze nieczytelnym, który Laravel odrzuca sam.
 *
 * Kolumny podaje wywołujący, bo tylko on wie, co znaczą. `strona()` sprawdza
 * przy tym, że podane kolumny to naprawdę sortowanie zapytania — rozjazd
 * (dopisany `orderBy` bez dopisanej kolumny) jest błędem programisty
 * i wychodzi w pierwszym teście listy, a nie na produkcji.
 *
 * Strażnik: `KursorZAdresuNieDajeBledu500Test` (każda lista kursorowa,
 * każdy zły kursor) i skan, że `cursorPaginate(` nie woła nikt poza tą klasą.
 */
final class KursorListy
{
    public const UUID = 'uuid';

    public const CZAS = 'czas';

    public const LICZBA = 'liczba';

    /**
     * Strona listy z kursorem z adresu — albo pierwsza strona, gdy kursor
     * nie pasuje do tej listy.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>|Relation<TModel, *, *>  $zapytanie
     * @param  array<string, self::UUID|self::CZAS|self::LICZBA>  $kolumny  kolumna sortowania => typ, w kolejności ORDER BY
     * @param  bool  $zAdresu  `false` = pierwsza strona bez względu na adres (lista, która
     *                         odrzuca kursor z własnych powodów, jak Odkrywanie bez `stan`)
     * @return KursorLaravela<int, TModel>
     */
    public static function strona(Builder|Relation $zapytanie, int $naStronie, array $kolumny, string $nazwa = 'cursor', bool $zAdresu = true): KursorLaravela
    {
        // Te same pozycje sortowania, które Laravel zapisuje w kursorze
        // (`Builder::ensureOrderForCursorPagination()`): z kierunkiem,
        // a przy UNION — sortowanie całości.
        $baza = $zapytanie->toBase();
        $sortowanie = [];
        foreach (($baza->unionOrders ?: $baza->orders) ?? [] as $order) {
            if (isset($order['direction'])) {
                $sortowanie[] = $order['column'] instanceof Expression
                    ? (string) $order['column']->getValue($baza->getGrammar())
                    : (string) $order['column'];
            }
        }

        if ($sortowanie !== array_keys($kolumny)) {
            throw new LogicException(sprintf(
                'KursorListy: kolumny [%s] nie są sortowaniem zapytania [%s].',
                implode(', ', array_keys($kolumny)),
                implode(', ', $sortowanie),
            ));
        }

        // Pusty napis, nie `null`: na `null` Laravel sięga po kursor z adresu
        // jeszcze raz — ten sam, który właśnie odrzuciliśmy.
        /** @var KursorLaravela<int, TModel> $strona */
        $strona = $zapytanie->cursorPaginate($naStronie, ['*'], $nazwa, ($zAdresu ? self::zAdresu($kolumny, $nazwa) : null) ?? '');

        return $strona;
    }

    /**
     * Kursor z adresu, jeśli pasuje do kolumn listy; inaczej `null`.
     *
     * @param  array<string, self::UUID|self::CZAS|self::LICZBA>  $kolumny
     */
    public static function zAdresu(array $kolumny, string $nazwa = 'cursor'): ?Cursor
    {
        $kursor = KursorLaravela::resolveCurrentCursor($nazwa);

        return $kursor instanceof Cursor && self::pasuje($kursor, $kolumny) ? $kursor : null;
    }

    /**
     * @param  array<string, self::UUID|self::CZAS|self::LICZBA>  $kolumny
     */
    public static function pasuje(Cursor $kursor, array $kolumny): bool
    {
        $parametry = $kursor->toArray();
        unset($parametry['_pointsToNextItems']);

        $oczekiwane = array_keys($kolumny);
        $podane = array_keys($parametry);
        sort($oczekiwane);
        sort($podane);

        if ($podane !== $oczekiwane) {
            return false;
        }

        foreach ($kolumny as $kolumna => $typ) {
            if (! self::wartoscPasuje($parametry[$kolumna], $typ)) {
                return false;
            }
        }

        return true;
    }

    private static function wartoscPasuje(mixed $wartosc, string $typ): bool
    {
        // `null` nie psuje zapytania (porównanie z NULL-em jest puste), a lista
        // po kolumnie z pustą wartością mogłaby go uczciwie wydać.
        if ($wartosc === null) {
            return true;
        }

        return match ($typ) {
            self::UUID => is_string($wartosc) && Str::isUuid($wartosc),
            self::LICZBA => is_int($wartosc)
                || (is_string($wartosc) && preg_match('/^-?\d{1,18}$/D', $wartosc) === 1),
            self::CZAS => is_string($wartosc) && self::czasPasuje($wartosc),
            default => false,
        };
    }

    /**
     * Czas w postaci, którą Laravel zapisuje w kursorze (`Y-m-d H:i:s`,
     * czasem z ułamkiem sekundy i strefą) — i który Postgres przyjmie.
     * Sam wzorzec nie wystarcza: `2026-02-31 25:00:00` pasuje do niego,
     * a Postgres odpowiada na to błędem `22008`.
     */
    public static function czasPasuje(string $wartosc): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?(Z|[+-](\d{2})(?::?(\d{2}))?)?$/D', $wartosc, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60
            && self::strefaPasuje(isset($m[9]) ? (int) $m[9] : null, isset($m[10]) ? (int) $m[10] : 0);
    }

    /**
     * Strefa jawna w kursorze: Laravel zapisuje `Z`, a przesunięcia spoza
     * zakresu Postgres odrzuca błędem `22009` (+99, +16, +00:99 …). Nie
     * zgadujemy jego granicy — bierzemy zakres rzeczywistych stref świata:
     * godziny do 14, minuty 00/15/30/45.
     */
    private static function strefaPasuje(?int $godziny, int $minuty): bool
    {
        return $godziny === null || ($godziny <= 14 && in_array($minuty, [0, 15, 30, 45], true));
    }
}
