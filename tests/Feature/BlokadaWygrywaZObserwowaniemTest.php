<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SOCIAL-01 z audytu trzeciej warstwy (10.09.2026): BLOKADA I OBSERWOWANIE
 * NIE MOGĄ WSPÓŁISTNIEĆ — także wtedy, gdy obie strony klikają naraz.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO BYŁO ZŁAMANE
 * ══════════════════════════════════════════════════════════════════════
 *
 * `FollowUser` sprawdzał blokadę zwykłym `exists()` i dopisywał wiersz
 * `follows` BEZ transakcji i bez żadnej blokady wiersza. `BlockUser`
 * natomiast robił obie rzeczy (zapis blokady i `detach` obserwowania
 * w obie strony) w jednej transakcji. Między odczytem w `FollowUser`
 * a jego zapisem było więc okno:
 *
 *   1. żądanie A pyta „czy jest blokada" → nie ma;
 *   2. żądanie B zakłada blokadę i zdejmuje obserwowanie w obie strony;
 *   3. żądanie A dopina obserwowanie, bo pracuje na odpowiedzi z punktu 1.
 *
 * Po blokadzie zostaje obserwowanie. To nie jest kosmetyka: człowiek
 * blokuje kogoś zwykle W MOMENCIE KONFLIKTU, czyli dokładnie wtedy, gdy
 * druga strona jest aktywna i klika. Skutkiem jest zablokowana osoba, która
 * dalej dostaje moje wpisy w swoim feedzie obserwowanych, i cała reszta
 * filtrów widoczności (`visibleTo`, wyszukiwarka ludzi) stojąca na
 * założeniu, że relacja blokady jest OSTATECZNA.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO TEST WSTRZYKUJE BLOKADĘ PRZEZ `DB::listen`
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bo przeplot ma być WYMUSZONY, a nie wyproszony od zegara. Test
 * sekwencyjny („zablokuj, potem obserwuj") tego błędu nie pokaże — akcja
 * czyta blokadę świeżo i po prostu odmówi. Błąd żył wyłącznie w okienku
 * MIĘDZY odczytem a zapisem w obrębie jednego wywołania `FollowUser`, więc
 * test musi wejść dokładnie w to okienko.
 *
 * `DB::listen` daje na to szew, który już istnieje we frameworku i nie
 * wymaga żadnej furtki testowej w kodzie produkcyjnym: nasłuch odpala się
 * PO wykonaniu zapytania o blokady, czyli w chwili, w której żądanie A zna
 * już odpowiedź „nie ma blokady", ale jeszcze nic nie zapisało. Wtedy
 * wykonujemy CAŁĄ akcję `BlockUser` — tę samą, którą woła przycisk
 * „Zablokuj", nie ręczny `insert` do tabeli.
 *
 * CZEGO TE TESTY NIE DOWODZĄ, ŻEBY NIE BYŁO NIEPOROZUMIENIA. Chodzą na
 * jednym połączeniu do PostgreSQL, więc nie dowodzą, że dwa RÓWNOLEGŁE
 * połączenia ustawiają się w kolejce, ani że nie zakleszczą się nawzajem.
 * Do tego trzeba dwóch procesów. Dowodzą rzeczy węższej i akurat tej,
 * która była złamana: że akcja NIE UFA odczytowi sprzed zapisu i sprawdza
 * stan jeszcze raz, już pod blokadą. Determinizm samej kolejności blokad
 * jest sprawdzany osobno — `ZamekParyTest`.
 */
class BlokadaWygrywaZObserwowaniemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wykonuje `$co`, wstrzykując `BlockUser` w chwilę po PIERWSZYM pytaniu
     * o tabelę `blocks`. Zwraca informację, czy wstrzyknięcie się udało —
     * test ma prawo wiedzieć, że naprawdę odtworzył przeplot, a nie tylko
     * wywołał akcję i nic się nie stało.
     */
    private function zPrzeplotem(User $blokujacy, User $blokowany, callable $co): bool
    {
        $wstrzyknieto = false;

        DB::listen(function (QueryExecuted $zapytanie) use (&$wstrzyknieto, $blokujacy, $blokowany): void {
            // Flaga PRZED wywołaniem akcji, bo `BlockUser` sam pyta
            // o `blocks` — bez tego nasłuch wpadłby w nieskończoność.
            if ($wstrzyknieto || ! str_contains($zapytanie->sql, '"blocks"')) {
                return;
            }

            $wstrzyknieto = true;

            app(BlockUser::class)->handle($blokujacy, $blokowany);
        });

        $co();

        return $wstrzyknieto;
    }

    #[Test]
    public function test_blokada_w_trakcie_obserwowania_nie_zostawia_obserwowania(): void
    {
        $basia = $this->user('basia');
        $niechciany = $this->user('niechciany');

        $wstrzyknieto = $this->zPrzeplotem($basia, $niechciany, function () use ($basia, $niechciany): void {
            try {
                app(FollowUser::class)->handle($niechciany, $basia);
            } catch (BladDlaCzlowieka) {
                // Po naprawie akcja odmawia — to jest oczekiwane zachowanie.
                // Przed naprawą wyjątku nie ma i wiersz `follows` zostaje.
            }
        });

        $this->assertTrue($wstrzyknieto, 'Przeplot nie został odtworzony — test nie sprawdził niczego.');

        $this->assertFalse(
            $niechciany->fresh()->isFollowing($basia),
            'Po blokadzie zostało obserwowanie — zablokowana osoba dalej ma wpisy w swoim feedzie.',
        );
        $this->assertFalse(
            $basia->fresh()->isFollowing($niechciany),
            'Po blokadzie zostało obserwowanie w drugą stronę.',
        );
        $this->assertSame(
            0,
            DB::table('follows')->count(),
            'W tabeli `follows` został wiersz między osobami, między którymi jest blokada.',
        );
    }

    /**
     * Ta sama para, to samo okno, tylko powiadomienie. Serwis nie ma prawa
     * zameldować „X zaczyna Cię obserwować" o kimś, kogo człowiek właśnie
     * odciął — to jest kontakt od osoby, przed którą blokada miała chronić.
     */
    #[Test]
    public function test_przeplot_nie_zostawia_powiadomienia_o_nowym_obserwujacym(): void
    {
        $basia = $this->user('basia');
        $niechciany = $this->user('niechciany');

        $wstrzyknieto = $this->zPrzeplotem($basia, $niechciany, function () use ($basia, $niechciany): void {
            try {
                app(FollowUser::class)->handle($niechciany, $basia);
            } catch (BladDlaCzlowieka) {
                // patrz wyżej
            }
        });

        $this->assertTrue($wstrzyknieto, 'Przeplot nie został odtworzony — test nie sprawdził niczego.');

        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $basia->getKey())
                ->where('type', Notification::TYPE_FOLLOW)
                ->count(),
            'Serwis zameldował nowego obserwującego po tym, jak człowiek go zablokował.',
        );
    }

    /**
     * Kolejność odwrotna: blokada pierwsza, obserwowanie drugie. To działało
     * przed zmianą i musi działać dalej — z komunikatem BEZ ZMIAN, bo
     * człowiek nie ma prawa zobaczyć innego zdania niż dotychczas.
     */
    #[Test]
    public function test_obserwowanie_po_blokadzie_jest_odrzucone(): void
    {
        $basia = $this->user('basia');
        $niechciany = $this->user('niechciany');

        app(BlockUser::class)->handle($basia, $niechciany);

        try {
            app(FollowUser::class)->handle($niechciany, $basia);
            $this->fail('Obserwowanie przeszło mimo istniejącej blokady.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame('Nie można obserwować tej osoby.', $e->getMessage());
        }

        $this->assertFalse($niechciany->fresh()->isFollowing($basia));
        $this->assertSame(0, DB::table('follows')->count());
    }

    /**
     * KONTROLA DODATNIA. Bez niej wszystkie testy wyżej przechodziłyby
     * również wtedy, gdyby obserwowanie przestało działać CAŁKOWICIE — bo
     * one sprawdzają, że czegoś NIE MA.
     */
    #[Test]
    public function test_zwykle_obserwowanie_bez_blokady_dziala_i_powiadamia(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $this->assertTrue(app(FollowUser::class)->handle($marek, $basia));

        $this->assertTrue($marek->fresh()->isFollowing($basia));
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $basia->getKey())
                ->where('actor_id', $marek->getKey())
                ->where('type', Notification::TYPE_FOLLOW)
                ->count(),
            'Zwykłe obserwowanie przestało powiadamiać autora — to jest gorsze niż wyścig.',
        );
    }

    /** Drugie kliknięcie „Obserwuj" nie tworzy drugiego wiersza ani powiadomienia. */
    #[Test]
    public function test_powtorne_obserwowanie_nie_dubluje_wiersza(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $this->assertTrue(app(FollowUser::class)->handle($marek, $basia));
        $this->assertFalse(app(FollowUser::class)->handle($marek, $basia));

        $this->assertSame(1, DB::table('follows')->count());
    }

    /**
     * Zachowanie, które działało przed zmianą i którego nie wolno zepsuć:
     * blokada zdejmuje ISTNIEJĄCE obserwowanie w obie strony.
     */
    #[Test]
    public function test_blokada_nadal_zdejmuje_istniejace_obserwowanie_w_obie_strony(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        app(FollowUser::class)->handle($basia, $marek);
        app(FollowUser::class)->handle($marek, $basia);
        $this->assertSame(2, DB::table('follows')->count());

        app(BlockUser::class)->handle($basia, $marek);

        $this->assertSame(0, DB::table('follows')->count());
        $this->assertFalse($basia->fresh()->isFollowing($marek));
        $this->assertFalse($marek->fresh()->isFollowing($basia));
    }
}
