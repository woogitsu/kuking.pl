<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Z-1 / D-090 NA DWÓCH POŁĄCZENIACH: „Zablokuj" i „Obserwuj" puszczone
 * w PRZECIWNYCH kierunkach na tej samej parze osób nie zakleszczają się.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO DOKŁADNIE TEN TEST MIERZY
 * ══════════════════════════════════════════════════════════════════════
 *
 * Do D-090 przez `ZamekPary` wchodził tylko `FollowUser`. `BlockUser` brał
 * wiersze `users` NIEJAWNIE — przez sprawdzenie kluczy obcych przy
 * `INSERT INTO blocks` — czyli w kolejności WYWOŁANIA: najpierw blokujący,
 * potem blokowany. Dla pary, w której obie strony klikają naraz w przeciwne
 * przyciski, wychodził cykl:
 *
 *   „Obserwuj" (ZamekPary):      bierze wiersz NIŻSZY, czeka na WYŻSZY
 *   „Zablokuj" (przed D-090):    bierze wiersz WYŻSZY (blokujący),
 *                                czeka na NIŻSZY (blokowany)
 *
 * Po D-090 obie akcje wchodzą przez `ZamekPary`, czyli obie biorą wiersze
 * rosnąco po identyfikatorze i ustawiają się w kolejce zamiast wyprzedzać.
 *
 * A to jest scenariusz zupełnie zwyczajny, nie wyścig o milisekundy:
 * człowiek blokuje kogoś zwykle wtedy, gdy ta druga osoba jest aktywna
 * i klika.
 *
 * ── PRZEPLOT, KTÓRY TO ROZSTRZYGA ──
 *
 * Bariera trzyma wiersz `users` osoby o NIŻSZYM identyfikatorze — czyli
 * ten, który w kolejności po danych jest PIERWSZY. Uczestnicy ustawiają się
 * w kolejce po niego w wymuszonej kolejności („Obserwuj" pierwszy), a dalej
 * wszystko wynika już z kodu:
 *
 *   przed D-090                          po D-090
 *   ─────────────────────────────        ─────────────────────────────
 *   Obserwuj(A→B): czeka na A            Obserwuj(A→B): czeka na A
 *   Zablokuj(B→A): TRZYMA B (przez       Zablokuj(B→A): czeka na A,
 *                  klucz obcy),                         za Obserwuj
 *                  czeka na A
 *   zwolnienie bariery:                  zwolnienie bariery:
 *   Obserwuj bierze A, sięga po B        Obserwuj kończy i zwalnia oba,
 *   → CYKL → 40P01                       Zablokuj przechodzi po nim
 *
 * ── KONTROLA UJEMNA (wykonana, nie zaplanowana) ──
 *
 * Cofnięcie `BlockUser` do stanu sprzed D-090 — `DB::transaction()` zamiast
 * `ZamekPary::zablokuj()` — daje tu:
 *
 *     SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected
 *     DETAIL:  Process 16625 waits for ShareLock on transaction 1038116;
 *              blocked by process 16579.
 *     CONTEXT:  while locking tuple (0,70) in relation "users"
 *     SQL statement "SELECT 1 FROM ONLY "public"."users" x
 *                    WHERE "id" OPERATOR(pg_catalog.=) $1 FOR KEY SHARE OF x"
 *     (SQL: insert into "blocks" …)
 *
 * Ten cytat mówi przy okazji rzecz, której z samego kodu nie widać i która
 * była najważniejszym ustaleniem audytu: blokadę wiersza `users` bierze tu
 * SPRAWDZENIE KLUCZA OBCEGO przy `INSERT`, a nie żadne jawne `FOR UPDATE`
 * w `BlockUser`. Dlatego „ta akcja przecież nic nie blokuje" było
 * nieprawdą — i dlatego nie da się tego zobaczyć na jednym połączeniu.
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Że te dwie akcje są wolne od zakleszczeń w ogóle — mierzy JEDEN przeplot,
 * ten zmierzony jako Z-1. Nie dowodzi też niczego o samej treści blokady
 * („blokada wygrywa z obserwowaniem"): tego pilnują testy jednopołączeniowe
 * z D-080 i bariera w bazie, którą mierzy
 * `WyzwalaczFollowsWidziZatwierdzonaBlokadeTest`.
 */
#[Group('dwa-polaczenia')]
final class ZablokujIObserwujNieZakleszczajaSieTest extends TestDwochPolaczen
{
    public function test_obserwowanie_i_blokada_w_przeciwnych_kierunkach_nie_zakleszczaja_sie(): void
    {
        [$nizsza, $wyzsza] = $this->paraPosortowana();

        // Bariera na wierszu PIERWSZYM w kolejności po danych — tym, po
        // który obie akcje sięgają najpierw, gdy obie idą przez `ZamekPary`.
        $bariera = $this->bariera(
            'SELECT 1 FROM users WHERE id = ? FOR UPDATE',
            [(string) $nizsza->getKey()],
        );

        // KOLEJNOŚĆ USTAWIANIA SIĘ W KOLEJCE JEST CZĘŚCIĄ TESTU. „Obserwuj"
        // ma stać w niej pierwszy, bo to on po zwolnieniu bariery dostanie
        // wiersz i sięgnie po drugi — czyli domknie cykl, jeśli „Zablokuj"
        // zdążył tymczasem wziąć ten drugi wiersz po swojemu.
        $obserwowanie = $this->wTle('obserwuj', [
            'kto' => (string) $nizsza->getKey(),
            'kogo' => (string) $wyzsza->getKey(),
        ]);
        $this->czekajNaZablokowane(1);

        $blokada = $this->wTle('zablokuj', [
            'kto' => (string) $wyzsza->getKey(),
            'kogo' => (string) $nizsza->getKey(),
        ]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikObserwowania = $obserwowanie->wynik();
        $wynikBlokady = $blokada->wynik();

        $this->assertBezZakleszczenia($wynikObserwowania, 'Obserwuj (niższy → wyższy)');
        $this->assertBezZakleszczenia($wynikBlokady, 'Zablokuj (wyższy → niższy)');

        // KONTROLA DODATNIA NR 1: blokada MUSIAŁA się udać. Jest to jedyna
        // czynność, którą człowiek ma, gdy ktoś staje się dla niego
        // problemem — i dlatego ma prawo przejść zawsze (uzasadnienie przy
        // wyzwalaczu w migracji `obserwowanie_nie_wspolistnieje_z_blokada`).
        $this->assertTrue(
            $wynikBlokady['ok'],
            'Blokada nie przeszła, więc test nie zmierzył wyścigu, tylko jego brak: '
            .$wynikBlokady['komunikat'],
        );

        // KONTROLA DODATNIA NR 2: „Obserwuj" też musiało się WYKONAĆ —
        // albo dopiąć obserwowanie, albo odmówić po polsku, bo zdążyła
        // wejść blokada. Czego nie wolno: żeby padło na czymkolwiek innym.
        // Bez tej asercji test przechodzi także wtedy, gdy proces wywrócił
        // się przed pierwszym zapytaniem — a wtedy nie było żadnego wyścigu.
        $this->assertTrue(
            $wynikObserwowania['ok'] || str_contains($wynikObserwowania['komunikat'], 'Nie można obserwować tej osoby.'),
            'Obserwowanie padło z powodu innego niż istniejąca blokada: '
            .$wynikObserwowania['wyjatek'].' '.$wynikObserwowania['komunikat'],
        );

        // STAN KOŃCOWY JEST JEDEN, NIEZALEŻNIE OD TEGO, KTO WYGRAŁ WYŚCIG:
        // blokada stoi, a obserwowania nie ma w ŻADNĄ stronę. Jeśli
        // „Obserwuj" zdążyło pierwsze, `BlockUser` zdjęło je pod blokadą
        // wierszy; jeśli nie zdążyło, odmówił mu `hasBlockRelationWith()`
        // pod tą samą blokadą. To jest ta własność, po którą człowiek
        // sięga, klikając „Zablokuj".
        $this->assertSame(
            1,
            DB::table('blocks')
                ->where('blocker_id', $wyzsza->getKey())
                ->where('blocked_id', $nizsza->getKey())
                ->count(),
            'Po wyścigu nie ma wiersza blokady — a blokada musi się udać zawsze.',
        );

        $this->assertSame(
            0,
            DB::table('follows')
                ->whereIn('follower_id', [$nizsza->getKey(), $wyzsza->getKey()])
                ->whereIn('followed_id', [$nizsza->getKey(), $wyzsza->getKey()])
                ->count(),
            'Po wyścigu została relacja obserwowania mimo blokady — blokada ma pierwszeństwo.',
        );
    }
}
