<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\ZamekPary;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `App\Domain\Social\ZamekPary` — kolejność blokad i twarda bariera w bazie
 * (decyzja D-080).
 *
 * NAJWAŻNIEJSZY TEST W TYM PLIKU jest ten o KOLEJNOŚCI. Reszta zmiany
 * (transakcja, świeże modele, rewalidacja w `FollowUser`) daje się
 * sprawdzić skutkiem; kolejność blokad — nie. Jej złamanie nie objawia się
 * niepoprawnym wynikiem, tylko zakleszczeniem przy dwóch równoległych
 * żądaniach na tej samej parze w przeciwnych kierunkach — czyli rzeczą,
 * której nie widać w żadnym teście sekwencyjnym i której nikt nie zauważy do
 * pierwszego błędu serwera na produkcji. Dlatego jest sprawdzana WPROST,
 * przez podejrzenie wykonanych zapytań, a nie przez skutek.
 */
class ZamekParyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Identyfikatory kont zablokowanych przez `$co`, w kolejności blokowania.
     *
     * @return list<string>
     */
    private function kolejnoscBlokad(callable $co): array
    {
        $klucze = [];

        DB::listen(function (QueryExecuted $zapytanie) use (&$klucze): void {
            if (! str_contains($zapytanie->sql, 'for update')) {
                return;
            }

            foreach ($zapytanie->bindings as $wiazanie) {
                $klucze[] = (string) $wiazanie;
            }
        });

        $co();

        return $klucze;
    }

    #[Test]
    public function test_kolejnosc_blokad_nie_zalezy_od_kolejnosci_argumentow(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $wPrzod = $this->kolejnoscBlokad(fn () => ZamekPary::zablokuj($basia, $marek, fn () => null));
        $wTyl = $this->kolejnoscBlokad(fn () => ZamekPary::zablokuj($marek, $basia, fn () => null));

        $this->assertCount(2, $wPrzod, 'Zamek nie zablokował dwóch wierszy.');

        // Gdyby kolejność szła za argumentami, „Basia blokuje Marka"
        // i „Marek obserwuje Basię" w tej samej sekundzie wzięłyby wiersze
        // w przeciwnych kolejnościach i zakleszczyłyby się nawzajem.
        $this->assertSame(
            $wPrzod,
            $wTyl,
            'Kolejność blokad zależy od kolejności argumentów — dwie równoległe operacje na tej samej parze zakleszczą się.',
        );

        $oczekiwana = [(string) $basia->getKey(), (string) $marek->getKey()];
        sort($oczekiwana, SORT_STRING);

        $this->assertSame($oczekiwana, $wPrzod, 'Blokady nie idą rosnąco po identyfikatorze.');
    }

    #[Test]
    public function test_wywolanie_zwrotne_dostaje_swieze_modele_w_kolejnosci_argumentow(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        // Stan modelu z zewnątrz jest nieaktualny; zamek ma podać stan
        // z chwili, w której naprawdę trzyma blokadę.
        $nieaktualny = User::query()->whereKey($basia->getKey())->first();
        DB::table('users')->where('id', $basia->getKey())->update(['status' => User::STATUS_SUSPENDED]);

        [$pierwszy, $drugi] = ZamekPary::zablokuj(
            $nieaktualny,
            $marek,
            fn (?User $a, ?User $b) => [$a, $b],
        );

        $this->assertSame((string) $basia->getKey(), (string) $pierwszy?->getKey(), 'Modele przyszły w innej kolejności niż argumenty.');
        $this->assertSame((string) $marek->getKey(), (string) $drugi?->getKey());
        $this->assertSame(User::STATUS_SUSPENDED, $pierwszy?->status, 'Zamek podał model sprzed blokady, nie stan pod blokadą.');
    }

    #[Test]
    public function test_znikniete_konto_dochodzi_jako_null_a_nie_wybucha(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $usuniety = $marek->replicate();
        $usuniety->id = $marek->getKey();
        $marek->forceDelete();

        [$pierwszy, $drugi] = ZamekPary::zablokuj(
            $basia,
            $usuniety,
            fn (?User $a, ?User $b) => [$a, $b],
        );

        $this->assertNotNull($pierwszy);
        $this->assertNull($drugi, 'Konto, którego już nie ma, ma dojść jako null — decyzja należy do akcji.');
    }

    /**
     * TWARDA BARIERA W BAZIE. Wyzwalacz `follows_blokada_ma_pierwszenstwo_trg`
     * pilnuje inwariantu dla KAŻDEJ drogi zapisu, także takiej, która omija
     * `FollowUser` — bo `exists()` w PHP jest dobre na ładny komunikat,
     * a gwarancję daje constraint albo lock (D-079 §4).
     */
    #[Test]
    public function test_baza_nie_przyjmuje_obserwowania_gdy_blokade_zalozyl_obserwowany(): void
    {
        $basia = $this->user('basia');
        $niechciany = $this->user('niechciany');

        $basia->blocking()->attach($niechciany->getKey(), ['created_at' => now()]);

        try {
            // Zapis w zagnieżdżonej transakcji, czyli pod punktem powrotu:
            // odrzucenie przez bazę unieważnia transakcję w PostgreSQL, a bez
            // punktu powrotu przewróciłoby cały test razem z `RefreshDatabase`
            // („current transaction is aborted"). To pułapka samego testu,
            // nie zachowanie produkcyjne.
            DB::transaction(fn () => DB::table('follows')->insert([
                'follower_id' => $niechciany->getKey(),
                'followed_id' => $basia->getKey(),
                'created_at' => now(),
            ]));
            $this->fail('Baza przyjęła obserwowanie mimo blokady — bariera nie działa.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Blokada ma pierwszenstwo', $e->getMessage());
        }

        $this->assertSame(0, DB::table('follows')->count());
    }

    /**
     * DRUGI KIERUNEK BLOKADY. Test wyżej sprawdza sytuację „blokadę założyła
     * osoba obserwowana"; ten — „blokadę założył ten, kto próbuje
     * obserwować". W wyzwalaczu to są dwie różne gałęzie warunku i trzeba
     * ich obu: pierwsza wersja tego pliku miała dwa testy, które
     * niezauważenie trafiały w tę samą gałąź, więc drugi z nich nie
     * sprawdzał niczego (przechodził też po usunięciu połowy warunku).
     */
    #[Test]
    public function test_bariera_dziala_takze_gdy_blokade_zalozyl_obserwujacy(): void
    {
        $basia = $this->user('basia');
        $niechciany = $this->user('niechciany');

        $niechciany->blocking()->attach($basia->getKey(), ['created_at' => now()]);

        $this->expectException(QueryException::class);

        DB::transaction(fn () => DB::table('follows')->insert([
            'follower_id' => $niechciany->getKey(),
            'followed_id' => $basia->getKey(),
            'created_at' => now(),
        ]));
    }

    /**
     * KONTROLA DODATNIA dla bariery. Bez niej dwa testy wyżej przeszłyby też
     * wtedy, gdyby wyzwalacz odrzucał KAŻDY zapis do `follows` — czyli gdyby
     * obserwowanie przestało działać w ogóle.
     */
    #[Test]
    public function test_bariera_nie_przeszkadza_zwyklemu_obserwowaniu(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        DB::table('follows')->insert([
            'follower_id' => $marek->getKey(),
            'followed_id' => $basia->getKey(),
            'created_at' => now(),
        ]);

        $this->assertSame(1, DB::table('follows')->count());
    }
}
