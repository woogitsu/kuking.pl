<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** #2815: import i zmiana stanu konta na dwóch rzeczywistych połączeniach PG18. */
#[Group('dwa-polaczenia')]
final class ImportPaczkiPoSankcjiKontaTest extends TestDwochPolaczen
{
    /** @return array<string, array{string}> */
    public static function rodzaje(): array
    {
        return ['przepis' => ['przepis'], 'wpis' => ['wpis'], 'zeszyt' => ['zeszyt']];
    }

    #[DataProvider('rodzaje')]
    public function test_sankcja_zatwierdzona_przed_blokada_pozycji_odmawia_bez_sladu(string $rodzaj): void
    {
        $konto = $this->konto();
        $znacznik = bin2hex(random_bytes(6));
        $klucz = random_int(1, 2_000_000_000);
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2815, ?)', [$klucz]);
        try {
            $proces = $this->wTle('wczytaj-paczke-2815', [
                'konto' => (string) $konto->getKey(), 'rodzaj' => $rodzaj,
                'tryb' => 'przed', 'bariera' => (string) $klucz, 'znacznik' => $znacznik,
            ]);
            $this->czekajNaZablokowane(1);
            $konto->suspend();
            $this->assertSame(User::STATUS_SUSPENDED, $konto->fresh()->status);
        } finally {
            $this->zwolnijBariere($bariera);
        }

        $wynik = $proces->wynik();
        $this->assertBezZakleszczenia($wynik, 'import po sankcji');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(0, $wynik['wartosc']['utworzone'][$rodzaj], 'IMPORT_2815_SANKCJA_PRZED_ZAPISEM');
        $this->assertCount(1, $wynik['wartosc']['niewczytane']);
        $this->assertSame(0, DB::table('wczytane_z_paczki')->where('user_id', $konto->getKey())->count());
        $tabela = match ($rodzaj) {
            'przepis' => 'recipes', 'wpis' => 'posts', 'zeszyt' => 'collections',
            default => throw new \LogicException('Nieznany rodzaj importu w teście.'),
        };
        $wlasciciel = $rodzaj === 'zeszyt' ? 'owner_id' : 'author_id';
        $this->assertSame(0, DB::table($tabela)->where($wlasciciel, $konto->getKey())->count());
    }

    public function test_pierwsza_pozycja_pozostaje_gdy_sankcja_wyprzedza_druga(): void
    {
        $konto = $this->konto();
        $klucz = random_int(1, 2_000_000_000);
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2815, ?)', [$klucz]);
        try {
            $proces = $this->wTle('wczytaj-paczke-2815', [
                'konto' => (string) $konto->getKey(), 'rodzaj' => 'partia',
                'tryb' => 'druga_pozycja', 'bariera' => (string) $klucz,
                'znacznik' => bin2hex(random_bytes(6)),
            ]);
            $this->czekajNaZablokowane(1);
            $this->assertSame(1, DB::table('posts')->where('author_id', $konto->getKey())->count());
            $this->assertSame(1, DB::table('wczytane_z_paczki')->where('user_id', $konto->getKey())->count());
            $konto->suspend();
        } finally {
            $this->zwolnijBariere($bariera);
        }

        $wynik = $proces->wynik();
        $this->assertBezZakleszczenia($wynik, 'druga pozycja po sankcji');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(1, $wynik['wartosc']['utworzone']['wpis']);
        $this->assertSame(0, $wynik['wartosc']['utworzone']['zeszyt'], 'IMPORT_2815_PIERWSZA_POZYCJA_ZOSTAJE');
        $this->assertCount(1, $wynik['wartosc']['niewczytane']);
        $this->assertSame(0, DB::table('collections')->where('owner_id', $konto->getKey())->count());
        $this->assertSame(1, DB::table('wczytane_z_paczki')->where('user_id', $konto->getKey())->count());
    }

    public function test_import_pod_blokada_konczy_sie_przed_pozniejsza_sankcja(): void
    {
        $konto = $this->konto();
        $klucz = random_int(1, 2_000_000_000);
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2815, ?)', [$klucz]);
        try {
            $import = $this->wTle('wczytaj-paczke-2815', [
                'konto' => (string) $konto->getKey(), 'rodzaj' => 'wpis',
                'tryb' => 'po_blokadzie', 'bariera' => (string) $klucz,
                'znacznik' => bin2hex(random_bytes(6)),
            ]);
            $this->czekajNaZablokowane(1);
            $sankcja = $this->wTle('status-konta', [
                'konto' => (string) $konto->getKey(), 'przejscie' => 'zawies',
            ]);
            $this->czekajNaZablokowane(2);
        } finally {
            $this->zwolnijBariere($bariera);
        }

        $wynik = $import->wynik();
        $wynikSankcji = $sankcja->wynik();
        $this->assertBezZakleszczenia($wynik, 'import przed sankcja');
        $this->assertBezZakleszczenia($wynikSankcji, 'sankcja po imporcie');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertTrue($wynikSankcji['ok'], $wynikSankcji['komunikat']);
        $this->assertSame(1, $wynik['wartosc']['utworzone']['wpis']);
        $this->assertSame(User::STATUS_SUSPENDED, $konto->fresh()->status);
        $this->assertSame(1, DB::table('posts')->where('author_id', $konto->getKey())->count());
        $this->assertSame(1, DB::table('wczytane_z_paczki')->where('user_id', $konto->getKey())->count());
    }
}
