<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Z-2 / D-093 NA DWÓCH POŁĄCZENIACH: dwie równoległe egzekucje kasowania
 * konta na parze, która obserwuje się wzajemnie, nie zakleszczają się.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO DOKŁADNIE TEN TEST MIERZY
 * ══════════════════════════════════════════════════════════════════════
 *
 * Para wzajemna ma w `follows` DWA wiersze: `(P,Q)` i `(Q,P)`. Przed D-093
 * `EraseAccountData` kasowało relacje dwoma hurtowymi `detach()`
 * w kolejności RÓL — najpierw „moje" wiersze, potem „cudze" — więc
 * egzekucja konta P brała `(P,Q)` przed `(Q,P)`, a egzekucja konta Q
 * dokładnie odwrotnie. Każda trzymała to, na co czekała druga.
 *
 * Po D-093 obie egzekucje sortują wiersze po KLUCZU GŁÓWNYM, czyli po
 * danych, nie po roli — i wychodzi im ta sama kolejność.
 *
 * ── PRZEPLOT, KTÓRY TO ROZSTRZYGA (i dlaczego akurat taki) ──
 *
 * Bariera trzyma wiersz `(Q,P)`, czyli ten, który w kolejności PO DANYCH
 * jest DRUGI (Q ma większy identyfikator). Dalej wszystko wynika już z kodu:
 *
 *   przed D-093 (kolejność ról)          po D-093 (kolejność danych)
 *   ─────────────────────────────        ─────────────────────────────
 *   Erase(Q): (Q,P) → czeka na barierę   Erase(Q): (P,Q) → trzyma
 *                                                  (Q,P) → czeka na barierę
 *   Erase(P): (P,Q) → trzyma             Erase(P): (P,Q) → czeka na Erase(Q)
 *             (Q,P) → czeka za Q
 *   zwolnienie bariery:                  zwolnienie bariery:
 *   Q bierze (Q,P), sięga po (P,Q)       Q kończy i zwalnia wszystko,
 *   → CYKL → 40P01                       P przechodzi po nim → bez cyklu
 *
 * Kolejność wchodzenia uczestników do kolejki jest wymuszona
 * (`czekajNaZablokowane()`), a nie wylosowana przez `sleep` — dlatego ten
 * test jest powtarzalny, a nie „zwykle zielony".
 *
 * ── KONTROLA UJEMNA (wykonana, nie zaplanowana) ──
 *
 * Cofnięcie `usunRelacjeWKolejnosciDanych()` do dwóch hurtowych `detach()`
 * w kolejności ról daje tu:
 *
 *     SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected
 *     DETAIL:  Process 20924 waits for ShareLock on transaction 1038031;
 *              blocked by process 20875.
 *     CONTEXT:  while deleting tuple (0,8) in relation "follows"
 *     (SQL: delete from "follows" where "follows"."followed_id" = 01a08dd2-…)
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Że kasowanie konta jest wolne od zakleszczeń w ogóle. Mierzy JEDEN
 * przeplot — ten, który był zmierzoną usterką Z-2. Inne tabele ruszane
 * przez tę akcję (`usunTresci()` przy zakresie `everything`) mają własny,
 * podobny kształt, są opisane w D-093 jako znalezisko z czytania i nie są
 * tu mierzone.
 */
#[Group('dwa-polaczenia')]
final class KasowanieKontaNieZakleszczaSieTest extends TestDwochPolaczen
{
    public function test_dwie_egzekucje_na_parze_wzajemnej_nie_zakleszczaja_sie(): void
    {
        [$mniejsze, $wieksze] = $this->paraPosortowana();

        // Para wzajemna: DWA wiersze, każdy dotyczy obu egzekucji naraz.
        DB::table('follows')->insert([
            ['follower_id' => $mniejsze->getKey(), 'followed_id' => $wieksze->getKey(), 'created_at' => now()],
            ['follower_id' => $wieksze->getKey(), 'followed_id' => $mniejsze->getKey(), 'created_at' => now()],
        ]);

        $mniejsze->markForDeletion();
        $wieksze->markForDeletion();

        // Bariera na wierszu DRUGIM w kolejności po danych.
        $bariera = $this->bariera(
            'SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ? FOR UPDATE',
            [(string) $wieksze->getKey(), (string) $mniejsze->getKey()],
        );

        $egzekucjaWiekszego = $this->wTle('kasowanie', ['konto' => (string) $wieksze->getKey()]);
        $this->czekajNaZablokowane(1);

        $egzekucjaMniejszego = $this->wTle('kasowanie', ['konto' => (string) $mniejsze->getKey()]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikWiekszego = $egzekucjaWiekszego->wynik();
        $wynikMniejszego = $egzekucjaMniejszego->wynik();

        $this->assertBezZakleszczenia($wynikWiekszego, 'egzekucja konta o WIĘKSZYM identyfikatorze');
        $this->assertBezZakleszczenia($wynikMniejszego, 'egzekucja konta o MNIEJSZYM identyfikatorze');

        // KONTROLA DODATNIA (`docs/PULAPKI_TESTOW.md` §4). Bez niej ten test
        // przechodzi także wtedy, gdy obie egzekucje nic nie zrobiły — bo
        // konto miało zły stan, bo akcja od razu zwróciła `false`, bo proces
        // padł na czymś innym. Brak zakleszczenia w przebiegu, w którym nic
        // się nie wydarzyło, nie jest wynikiem.
        $this->assertTrue($wynikWiekszego['ok'], 'Egzekucja większego konta padła: '.$wynikWiekszego['komunikat']);
        $this->assertTrue($wynikMniejszego['ok'], 'Egzekucja mniejszego konta padła: '.$wynikMniejszego['komunikat']);
        $this->assertTrue($wynikWiekszego['wartosc'] === true, 'Egzekucja większego konta niczego nie wymazała.');
        $this->assertTrue($wynikMniejszego['wartosc'] === true, 'Egzekucja mniejszego konta niczego nie wymazała.');

        $this->assertSame(
            0,
            DB::table('follows')
                ->whereIn('follower_id', [$mniejsze->getKey(), $wieksze->getKey()])
                ->orWhereIn('followed_id', [$mniejsze->getKey(), $wieksze->getKey()])
                ->count(),
            'Po obu egzekucjach nie może zostać ani jeden wiersz `follows` tej pary.',
        );

        foreach ([$mniejsze, $wieksze] as $konto) {
            $this->assertNotNull(
                DB::table('users')->where('id', $konto->getKey())->value('data_erased_at'),
                'Konto nie zostało wymazane, więc test nie zmierzył egzekucji, tylko jej brak.',
            );
        }
    }
}
