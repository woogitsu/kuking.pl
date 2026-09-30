<?php

declare(strict_types=1);

namespace App\Support\Baza;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use PDO;
use Throwable;

/**
 * GÓRNA GRANICA CZASU ZAPYTANIA — TYLKO W ŻĄDANIU HTTP (issue #2290, audyt
 * docs/audyt/2026-09-30-wydajnosc-baza.md, F3).
 *
 * PO CO
 * Bez `statement_timeout` jedno patologiczne zapytanie (brak statystyk po
 * odtworzeniu bazy, JIT jak w #2288, zła ścieżka planu) trzyma jeden z czterech
 * wątków repliki FrankenPHP (D-312) i jedno połączenie z bazą bez końca,
 * a osoba widzi kręcącą się stronę. Z limitem baza przerywa zapytanie
 * (SQLSTATE 57014), a osoba dostaje polski ekran „spróbuj za chwilę”.
 *
 * DLACZEGO NIE W `config/database.php`
 * Opcja połączenia objęłaby też worker kolejki, harmonogram i migracje:
 * eksport danych, przeliczenia i `VALIDATE CONSTRAINT` mają prawo trwać długo
 * (migracje mają własny `lock_timeout`, `LimitBlokadMigracji`). Dlatego limit
 * włącza middleware `UstawLimitCzasuZapytan` — tylko na czas żądania HTTP —
 * a proces konsoli nigdy go nie widzi.
 *
 * JAK
 * `SET statement_timeout` (sesyjne) na każdym połączeniu PostgreSQL, które
 * w czasie żądania już istnieje albo dopiero powstaje (`ConnectionEstablished`,
 * także po ponownym połączeniu). Na końcu żądania `RESET` — do wartości
 * domyślnej sesji, czyli tej z `ALTER ROLE`/`ALTER DATABASE`, jeśli ktoś ją
 * ustawi. Przez surowe PDO, nie `statement()`: `SET` nie jest zapytaniem
 * aplikacji i nie ma liczyć się do sumy czasu SQL żądania (`CzasZapytan`) ani
 * do liczników zapytań w testach.
 *
 * PGBOUNCER (D-312). W trybie transakcyjnym `SET` sesyjne przeciekałoby do
 * cudzych żądań — przy jego wdrożeniu to ustawienie trzeba przenieść na rolę
 * albo na `SET LOCAL`.
 */
final class LimitCzasuZapytanHttp
{
    /** SQLSTATE `query_canceled` — tak PostgreSQL kończy zapytanie po `statement_timeout`. */
    public const SQLSTATE_PRZERWANE = '57014';

    private bool $wlaczony = false;

    /** @var array<string, Connection> połączenia, na których ustawiliśmy limit */
    private array $ustawione = [];

    public function __construct(private readonly DatabaseManager $bazy) {}

    /** Limit w milisekundach z konfiguracji; 0 = bez limitu. */
    public function milisekundy(): int
    {
        return max(0, (int) config('kuking.polaczenia.limit_zapytania_http_ms', 0));
    }

    /** Początek żądania HTTP: limit na połączeniach, które już są, i na każdym nowym. */
    public function wlacz(): void
    {
        if ($this->milisekundy() === 0) {
            return;
        }

        $this->wlaczony = true;

        foreach ($this->bazy->getConnections() as $polaczenie) {
            $this->naPolaczenie($polaczenie);
        }
    }

    /** Koniec żądania: limit zdjęty, żeby nie przeciekł do kodu po odpowiedzi (np. w testach). */
    public function wylacz(): void
    {
        $this->wlaczony = false;

        foreach ($this->ustawione as $polaczenie) {
            $pdo = $this->otwartePdo($polaczenie);
            if ($pdo === null) {
                continue;
            }

            try {
                $pdo->exec('RESET statement_timeout');
            } catch (Throwable) {
                // Transakcja przerwana tym samym limitem odrzuca każde polecenie
                // do czasu ROLLBACK — a ROLLBACK i tak cofa `SET` z tej transakcji.
            }
        }

        $this->ustawione = [];
    }

    /** Słuchacz `ConnectionEstablished` i pętla z `wlacz()`. */
    public function naPolaczenie(Connection $polaczenie): void
    {
        if (! $this->wlaczony || $polaczenie->getDriverName() !== 'pgsql') {
            return;
        }

        $polaczenie->getPdo()->exec('SET statement_timeout = '.$this->milisekundy());
        $this->ustawione[spl_object_id($polaczenie)] = $polaczenie;
    }

    /** Czy wyjątek to zapytanie przerwane przez ten limit (albo inne anulowanie po stronie bazy). */
    public static function toPrzerwaneZapytanie(Throwable $e): bool
    {
        return $e instanceof QueryException && (string) $e->getCode() === self::SQLSTATE_PRZERWANE;
    }

    /** PDO połączenia, jeśli jest już otwarte — bez otwierania nowego tylko po to, żeby je wyzerować. */
    private function otwartePdo(Connection $polaczenie): ?PDO
    {
        $pdo = $polaczenie->getRawPdo();

        return $pdo instanceof PDO ? $pdo : null;
    }
}
