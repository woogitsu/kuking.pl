<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `BlockUser` wchodzi przez `App\Domain\Social\ZamekPary` — decyzja D-090,
 * dokończenie D-080.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CZEGO TEN PLIK PILNUJE, A CZEGO NIE PILNUJE
 * ══════════════════════════════════════════════════════════════════════
 *
 * PILNUJE KONTRAKTU: że „Zablokuj" bierze wiersze OBU kont pod blokadę,
 * że robi to ZANIM zapisze cokolwiek do `blocks`, i że kolejność jest ta
 * sama co przy „Obserwuj" — czyli wyliczona z danych, nie z argumentów.
 *
 * NIE PILNUJE SKUTKU, bo skutku nie da się tu wywołać. Złamanie kolejności
 * blokad nie objawia się złym wynikiem, tylko ZAKLESZCZENIEM przy dwóch
 * równoległych żądaniach na tej samej parze — a `RefreshDatabase` trzyma
 * cały test w jednej niezatwierdzonej transakcji na JEDNYM połączeniu, więc
 * drugiego uczestnika wyścigu po prostu nie ma. Zakleszczenie, które ta
 * zmiana usuwa, zostało zmierzone poza zestawem testów, na dwóch
 * połączeniach do PostgreSQL — opis pomiaru i powód, dla którego nie da się
 * go tu odtworzyć, są w `docs/research/2026-09-10-kolejnosc-blokad.md`.
 *
 * Dlatego kolejność jest sprawdzana WPROST, przez podejrzenie wykonanych
 * zapytań — tak samo jak w `ZamekParyTest`, z którego ten wzorzec pochodzi.
 */
class ZamekParyObejmujeBlokowanieTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ślad zapytań wykonanych przez `$co`: identyfikatory blokowanych
     * wierszy `users` (w kolejności blokowania) oraz numery zapytań, pod
     * którymi padła ostatnia blokada i pierwszy zapis do `blocks`.
     *
     * @return array{blokady: list<string>, ostatniaBlokada: ?int, zapisDoBlocks: ?int}
     */
    private function slad(callable $co): array
    {
        $blokady = [];
        $ostatniaBlokada = null;
        $zapisDoBlocks = null;
        $licznik = 0;

        DB::listen(function (QueryExecuted $zapytanie) use (&$blokady, &$ostatniaBlokada, &$zapisDoBlocks, &$licznik): void {
            $licznik++;

            if (str_contains($zapytanie->sql, 'for update')) {
                foreach ($zapytanie->bindings as $wiazanie) {
                    $blokady[] = (string) $wiazanie;
                }

                $ostatniaBlokada = $licznik;

                return;
            }

            if ($zapisDoBlocks === null && str_starts_with($zapytanie->sql, 'insert into "blocks"')) {
                $zapisDoBlocks = $licznik;
            }
        });

        $co();

        return ['blokady' => $blokady, 'ostatniaBlokada' => $ostatniaBlokada, 'zapisDoBlocks' => $zapisDoBlocks];
    }

    #[Test]
    public function test_blokowanie_bierze_oba_wiersze_kont_zanim_zapisze_blokade(): void
    {
        $basia = $this->user('basia');
        $niechciany = $this->user('niechciany');

        $slad = $this->slad(fn () => app(BlockUser::class)->handle($basia, $niechciany));

        $this->assertCount(
            2,
            $slad['blokady'],
            'Blokowanie nie wzięło dwóch wierszy `users` pod blokadę — nie idzie przez ZamekPary.',
        );

        $this->assertNotNull($slad['zapisDoBlocks'], 'Nie zapisano wiersza do `blocks` — test mierzy nie to, co trzeba.');
        $this->assertNotNull($slad['ostatniaBlokada']);

        // Blokada wzięta PO zapisie nie jest blokadą: drugie żądanie zdąży
        // wejść między sprawdzenie a `INSERT`.
        $this->assertLessThan(
            $slad['zapisDoBlocks'],
            $slad['ostatniaBlokada'],
            'Wiersze kont są blokowane dopiero po zapisie do `blocks` — to nie serializuje niczego.',
        );
    }

    #[Test]
    public function test_kolejnosc_blokad_przy_blokowaniu_nie_zalezy_od_kierunku(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $wPrzod = $this->slad(fn () => app(BlockUser::class)->handle($basia, $marek))['blokady'];

        // Druga strona tego samego konfliktu: te same dwie osoby, odwrotny
        // kierunek. Gdyby kolejność szła za argumentami, obie operacje
        // wzięłyby wiersze w przeciwnych kolejnościach i zakleszczyłyby się.
        $wTyl = $this->slad(fn () => app(BlockUser::class)->handle($marek, $basia))['blokady'];

        $this->assertSame($wPrzod, $wTyl, 'Kolejność blokad przy blokowaniu zależy od kierunku — to jest zakleszczenie.');

        $oczekiwana = [(string) $basia->getKey(), (string) $marek->getKey()];
        sort($oczekiwana, SORT_STRING);

        $this->assertSame($oczekiwana, $wPrzod, 'Blokady nie idą rosnąco po identyfikatorze.');
    }

    #[Test]
    public function test_blokowanie_i_obserwowanie_biora_te_same_wiersze_w_tej_samej_kolejnosci(): void
    {
        // TO JEST WŁAŚCIWA TREŚĆ D-090. Do tej pory „Obserwuj" brało wiersze
        // rosnąco po identyfikatorze, a „Zablokuj" nie brało ich jawnie
        // wcale — i to jest ta różnica, z której brało się zakleszczenie.
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $przyObserwowaniu = $this->slad(fn () => app(FollowUser::class)->handle($marek, $basia))['blokady'];
        $przyBlokowaniu = $this->slad(fn () => app(BlockUser::class)->handle($basia, $marek))['blokady'];

        $this->assertCount(2, $przyObserwowaniu, 'Obserwowanie nie wzięło dwóch wierszy — zmienił się ZamekPary.');

        $this->assertSame(
            $przyObserwowaniu,
            $przyBlokowaniu,
            'Obserwowanie i blokowanie biorą wiersze `users` w różnej kolejności — dwa równoległe żądania na tej parze zakleszczą się.',
        );
    }

    #[Test]
    public function test_blokada_nadal_zdejmuje_obserwowanie_w_obie_strony(): void
    {
        // Kontrakt z D-080 ma przeżyć przeniesienie akcji pod zamek.
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        app(FollowUser::class)->handle($basia, $marek);
        app(FollowUser::class)->handle($marek, $basia);

        app(BlockUser::class)->handle($basia, $marek);

        $this->assertDatabaseMissing('follows', ['follower_id' => $basia->getKey(), 'followed_id' => $marek->getKey()]);
        $this->assertDatabaseMissing('follows', ['follower_id' => $marek->getKey(), 'followed_id' => $basia->getKey()]);
        $this->assertDatabaseHas('blocks', ['blocker_id' => $basia->getKey(), 'blocked_id' => $marek->getKey()]);
    }

    #[Test]
    public function test_dziennik_audytu_powstaje_poza_transakcja_blokady(): void
    {
        // Wpis audytowy ma się zapisać dopiero wtedy, gdy blokada NAPRAWDĘ
        // weszła — czyli po zatwierdzeniu, a nie w środku transakcji, razem
        // z którą mógłby zostać wycofany.
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        // NIE PO POZIOMIE TRANSAKCJI W CHWILI WPISU. Od #1573 wpis idzie przez
        // `recordBezWywracania()`, który sam otwiera punkt zapisu — więc
        // „poza blokadą" to poziom 2 (RefreshDatabase + ten punkt), czyli
        // tyle samo, co gołe `record()` WEWNĄTRZ blokady. Mierzymy więc to,
        // o co naprawdę chodzi: czy transakcja, w której wszedł wiersz
        // `blocks`, była już zatwierdzona, zanim poszedł INSERT wpisu.
        // „Zatwierdzona" = poziom wrócił do tego sprzed akcji: `firstOrCreate`
        // otwiera własny punkt zapisu, więc samo zejście o jeden poziom niżej
        // niż przy INSERT-cie blokady niczego jeszcze nie zatwierdza.
        $poziomBazowy = DB::transactionLevel();
        $poziomBlokady = null;
        $blokadaZatwierdzona = false;
        $zatwierdzonaPrzedWpisem = null;

        Event::listen(TransactionCommitted::class, function () use ($poziomBazowy, &$poziomBlokady, &$blokadaZatwierdzona): void {
            if ($poziomBlokady !== null && DB::transactionLevel() <= $poziomBazowy) {
                $blokadaZatwierdzona = true;
            }
        });

        DB::listen(function (QueryExecuted $zapytanie) use (&$poziomBlokady, &$blokadaZatwierdzona, &$zatwierdzonaPrzedWpisem): void {
            if (str_starts_with($zapytanie->sql, 'insert into "blocks"')) {
                $poziomBlokady = DB::transactionLevel();
            }

            if (str_starts_with($zapytanie->sql, 'insert into "audit_log"')) {
                $zatwierdzonaPrzedWpisem = $blokadaZatwierdzona;
            }
        });

        app(BlockUser::class)->handle($basia, $marek);

        $this->assertNotNull($poziomBlokady, 'Nie zapisano blokady — test mierzy nie to, co trzeba.');
        $this->assertNotNull($zatwierdzonaPrzedWpisem, 'Nie zapisano wpisu audytowego — test mierzy nie to, co trzeba.');

        $this->assertTrue(
            $zatwierdzonaPrzedWpisem,
            'Wpis audytowy powstaje wewnątrz transakcji blokady — wycofanie blokady skasowałoby też ślad.',
        );
    }

    #[Test]
    public function test_blokowanie_samego_siebie_nadal_odrzucone_przed_zamkiem(): void
    {
        $basia = $this->user('basia');

        $slad = $this->slad(function () use ($basia): void {
            try {
                app(BlockUser::class)->handle($basia, User::query()->whereKey($basia->getKey())->firstOrFail());
            } catch (BladDlaCzlowieka) {
                // Oczekiwane.
            }
        });

        $this->assertSame([], $slad['blokady'], 'Blokowanie samego siebie weszło pod zamek — tania odmowa ma być przed nim.');
    }
}
