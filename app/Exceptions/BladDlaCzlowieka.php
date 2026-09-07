<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Wyjątek, którego treść JEST napisana dla człowieka i wolno ją pokazać
 * na ekranie („Nie można obserwować samego siebie.", „Dodaj przynajmniej
 * jeden składnik…").
 *
 * PO CO OSOBNA KLASA — TO NIE JEST PORZĄDKOWANIE NAZW
 * Warstwa domenowa rzucała dotąd zwykłym `RuntimeException`, a kontrolery
 * łapały `RuntimeException` i wstawiały `$e->getMessage()` do worka błędów
 * formularza. Problem w tym, że `PDOException` DZIEDZICZY po
 * `RuntimeException` — więc ten sam `catch` łapał także `QueryException`
 * z dowolnego zapytania wykonanego w środku bloku `try`.
 *
 * Zmierzone, nie wydedukowane. Po ukryciu tabeli `follows` żądanie
 * „obserwuj" pokazało człowiekowi w formularzu dokładnie to:
 *
 *   SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "follows" does not
 *   exist … (Connection: pgsql, Host: 127.0.0.1, Port: 5432, Database:
 *   kuking_test, SQL: select exists(select * from "users" inner join
 *   "follows" … "follows"."follower_id" = 01a079be-… and "users"."id" =
 *   01a079be-…) as "exists")
 *
 * Czyli adres bazy, port, jej nazwę, schemat zapytania oraz identyfikatory
 * obu kont — na ekranie osoby, która kliknęła „Obserwuj". To ta sama usterka
 * co W7-07 (`failure_reason` eksportu) i ta sama co w `/health`, tylko
 * w kilkunastu miejscach naraz.
 *
 * DLACZEGO ZNACZNIK, A NIE CZARNA LISTA W `catch`
 * Można było dopisać w kontrolerach „przepuść dalej, jeśli to `PDOException`
 * albo `FilesystemException`". To jest lista rzeczy, o których dziś
 * pamiętamy — czyli konstrukcja, która milczy dokładnie wtedy, gdy pojawi
 * się coś, o czym nie pomyśleliśmy. Znacznik odwraca domyślną odpowiedź:
 * na ekran idzie WYŁĄCZNIE to, co ktoś świadomie napisał dla człowieka,
 * a wszystko inne leci do procedury obsługi błędów i kończy się stroną 500
 * — bez treści wyjątku.
 *
 * KIEDY GO UŻYWAĆ
 * Gdy reguła domenowa mówi „nie" i człowiek ma się dowiedzieć dlaczego.
 * NIE do awarii technicznych (brak pliku w storage, odmowa z Cloudflare,
 * nieudany ZIP) — te zostają zwykłym `RuntimeException`, bo ich treść jest
 * dla nas, nie dla niego.
 */
class BladDlaCzlowieka extends RuntimeException {}
