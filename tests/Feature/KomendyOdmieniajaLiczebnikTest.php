<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Komendy konsolowe odmieniają rzeczownik przez liczbę (`App\Support\Odmiana`).
 *
 * SKĄD TO SIĘ WZIĘŁO. Właściciel uruchomił na produkcji komendę uzupełniającą
 * wpisy przepisów i zobaczył:
 *
 *     Dopisano 1 wpisów dla przepisów opublikowanych wcześniej.
 *
 * To jest ta sama choroba, którą naprawiono już w komunikatach odmowy
 * w migracjach (#386) i w tekstach ekranów (#38) — tylko w trzecim miejscu.
 * Polski ma TRZY formy, nie dwie, plus wyjątek na nastki: 2, 3, 4 biorą
 * „wpisy", ale 12, 13, 14 już nie.
 *
 * DLACZEGO TO NIE JEST DROBIAZG W LOGU. Zdanie, które komenda wypisuje, jest
 * JEDYNYM, co o sobie mówi — nie ma tu ekranu, na którym widać wynik. Przy
 * komendzie ruszanej ręcznie na produkcji człowiek czyta tę jedną linijkę
 * i na jej podstawie decyduje, czy uruchomić przebieg na ostro.
 *
 * PO CO TEST NA TRZECH KOMENDACH, SKORO POPRAWKA JEST JEDNOLINIJKOWA.
 * Bo poprawka jednolinijkowa wraca. Każda z tych komend wypisuje liczbę
 * w innym miejscu zdania i z innym rzeczownikiem, a „{$ile} zdjęć" pisze się
 * szybciej niż wywołanie `Odmiana::rzeczownik()`.
 */
class KomendyOdmieniajaLiczebnikTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cztery liczby, które razem pokrywają wszystkie trzy formy ORAZ wyjątek
     * na nastki. 12 jest tu najważniejsze: naiwne „ostatnia cyfra 2, 3 albo 4
     * → forma mnoga" daje na nim „12 wpisy".
     *
     * @return array<string, array{int, string}>
     */
    public static function liczbyWpisowProvider(): array
    {
        return [
            'jeden' => [1, '1 wpis dla'],
            'kilka (2)' => [2, '2 wpisy dla'],
            'wiele (5)' => [5, '5 wpisów dla'],
            'nastka (12) bierze formę „wiele”' => [12, '12 wpisów dla'],
        ];
    }

    #[DataProvider('liczbyWpisowProvider')]
    public function test_komenda_dopisujaca_wpisy_odmienia_rzeczownik(int $ile, string $oczekiwane): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->count($ile)->create(['author_id' => $basia->getKey()]);

        $this->artisan('kuking:dopisz-wpisy-przepisow')
            ->expectsOutputToContain('Dopisano '.$oczekiwane)
            ->assertSuccessful();
    }

    #[DataProvider('liczbyWpisowProvider')]
    public function test_ta_sama_odmiana_w_przebiegu_na_sucho(int $ile, string $oczekiwane): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->count($ile)->create(['author_id' => $basia->getKey()]);

        // Przebieg „na sucho" to ten, na podstawie którego człowiek decyduje,
        // czy puścić przebieg na ostro — więc on tym bardziej ma mówić po
        // polsku.
        $this->artisan('kuking:dopisz-wpisy-przepisow', ['--na-sucho' => true])
            ->expectsOutputToContain('Do dopisania: '.$oczekiwane)
            ->assertSuccessful();
    }

    /**
     * Tu odmienia się nie tylko rzeczownik, ale i przymiotnik po nim —
     * dlatego w kodzie przez `Odmiana` przechodzi CAŁA fraza.
     *
     * @return array<string, array{int, string}>
     */
    public static function liczbyZdjecProvider(): array
    {
        return [
            'jeden' => [1, '1 zdjęcie nieprzypięte'],
            'kilka (2)' => [2, '2 zdjęcia nieprzypięte'],
            'wiele (5)' => [5, '5 zdjęć nieprzypiętych'],
        ];
    }

    #[DataProvider('liczbyZdjecProvider')]
    public function test_komenda_sprzatajaca_osierocone_zdjecia_odmienia_cala_fraze(int $ile, string $oczekiwane): void
    {
        Media::factory()->count($ile)->create(['created_at' => now()->subDays(2)]);

        // Na sucho: sprawdzamy zdanie, nie kasowanie — kasowanie ma własny
        // test (`KasowanieZdjeciaOdpornoscNaAwarieTest`).
        $this->artisan('kuking:sprzataj-osierocone-zdjecia', ['--na-sucho' => true])
            ->expectsOutputToContain('Do skasowania: '.$oczekiwane.' od co najmniej 24 h.')
            ->assertSuccessful();
    }
}
