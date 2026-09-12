<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * PODWÓJNE WYSŁANIE KOMENTARZA NA DWÓCH POŁĄCZENIACH — pomiar, nie
 * rozumowanie (audyt podwójnego wysłania z 12 września 2026, D-079).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  PO CO TEN TEST, SKORO JEST `IdempotencjaKomentarzaTest`
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bo tamten NICZEGO NIE MÓWI O DWÓCH POŁĄCZENIACH i ma to napisane
 * w docblocku (`docs/PULAPKI_TESTOW.md` §6). `RefreshDatabase` trzyma oba
 * żądania w jednej, niezatwierdzonej transakcji — więc drugie żądanie widzi
 * komentarz pierwszego i wychodzi na rewalidacji. Sama BLOKADA nie ma tam
 * czego serializować: blokada doradcza jest w obrębie sesji wznawialna.
 *
 * Czyli: test jednopołączeniowy mierzy POŁOWĘ mechanizmu (rewalidację)
 * i przeszedłby także wtedy, gdyby blokady nie było wcale. A blokada bez
 * rewalidacji nie pilnuje niczego i rewalidacja bez blokady też nie —
 * to są dwie połowy jednej rzeczy (D-079 §2).
 *
 * ── PRZEPLOT, KTÓRY TO ROZSTRZYGA ──
 *
 * Bariera trzyma wiersz `posts` pod `FOR UPDATE`. `INSERT INTO comments`
 * musi sprawdzić klucz obcy `post_id`, czyli wziąć na tym wierszu
 * `FOR KEY SHARE` — i staje w kolejce za barierą. Dzięki temu wiadomo, że
 * pierwszy uczestnik NA PEWNO jeszcze nie zatwierdził, gdy drugi rusza.
 *
 *   dziś (blokada + rewalidacja)          bez blokady (stan sprzed zmiany)
 *   ────────────────────────────────      ────────────────────────────────
 *   A: bierze blokadę wysłania,           A: sprawdza — nic nie ma,
 *      sprawdza — nic nie ma,                czeka na wiersz `posts`
 *      czeka na wiersz `posts`            B: sprawdza — NIC NIE MA, bo A
 *   B: czeka na BLOKADĘ WYSŁANIA             jeszcze nie zatwierdził,
 *      (nie zdążył nawet sprawdzić)          czeka na wiersz `posts`
 *   zwolnienie bariery:                   zwolnienie bariery:
 *   A wstawia i zatwierdza,               A wstawia, B wstawia
 *   B dostaje blokadę, WIDZI wiersz A     → DWA komentarze
 *   → JEDEN komentarz                        i DWA powiadomienia
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Że komentarze są wolne od wyścigów w ogóle. Mierzy JEDEN przeplot — ten,
 * który odpowiada podwójnemu kliknięciu „Wyślij" na wolnym łączu, czyli
 * usterce zmierzonej w audycie.
 */
#[Group('dwa-polaczenia')]
final class KomentarzNiePowielaSieNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    private const TRESC = 'Wygląda przepięknie, muszę spróbować.';

    public function test_dwa_rownolegle_wyslania_tego_samego_komentarza_daja_jeden_wiersz(): void
    {
        $autor = $this->konto();
        $komentujacy = $this->konto();
        $wpis = $this->wpis($autor);

        // Bariera na wierszu wpisu: `INSERT INTO comments` sprawdza klucz
        // obcy `post_id` i staje tu w kolejce. Bez niej oba procesy mogłyby
        // się rozminąć w czasie i test mierzyłby dwa wysłania po kolei,
        // czyli to samo, co test jednopołączeniowy.
        $bariera = $this->bariera('SELECT 1 FROM posts WHERE id = ? FOR UPDATE', [(string) $wpis->getKey()]);

        $argumenty = [
            'kto' => (string) $komentujacy->getKey(),
            'wpis' => (string) $wpis->getKey(),
            'tresc' => self::TRESC,
        ];

        $pierwszy = $this->wTle('komentarz', $argumenty);
        $this->czekajNaZablokowane(1);

        $drugi = $this->wTle('komentarz', $argumenty);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikPierwszego = $pierwszy->wynik();
        $wynikDrugiego = $drugi->wynik();

        $this->assertBezZakleszczenia($wynikPierwszego, 'pierwsze wysłanie komentarza');
        $this->assertBezZakleszczenia($wynikDrugiego, 'drugie wysłanie komentarza');

        // KONTROLA DODATNIA: oba procesy MUSIAŁY dojść do końca. Bez tego
        // test przechodzi także wtedy, gdy któryś wywrócił się przed
        // pierwszym zapytaniem — a wtedy nie było żadnego wyścigu.
        $this->assertTrue($wynikPierwszego['ok'], 'Pierwsze wysłanie padło: '.$wynikPierwszego['komunikat']);
        $this->assertTrue($wynikDrugiego['ok'], 'Drugie wysłanie padło: '.$wynikDrugiego['komunikat']);

        // I OBA ZWRÓCIŁY TEN SAM KOMENTARZ — to jest właśnie ta różnica,
        // której nie widać przy jednym połączeniu.
        $this->assertSame(
            $wynikPierwszego['wartosc'],
            $wynikDrugiego['wartosc'],
            'Dwa równoległe wysłania zwróciły dwa różne komentarze.',
        );

        $this->assertSame(
            1,
            DB::table('comments')->where('post_id', $wpis->getKey())->count(),
            'Dwa równoległe wysłania zapisały dwa komentarze pod jednym wpisem.',
        );

        $this->assertSame(
            1,
            DB::table('notifications')
                ->where('user_id', $autor->getKey())
                ->where('type', Notification::TYPE_COMMENT)
                ->count(),
            'Autor wpisu dostał dwa powiadomienia za jeden komentarz.',
        );
    }

    private function wpis(User $autor): Post
    {
        return Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }
}
