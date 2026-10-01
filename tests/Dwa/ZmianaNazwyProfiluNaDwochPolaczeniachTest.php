<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regresja #887: dwie osoby zmieniają nazwę na tę samą, wolną nazwę.
 *
 * Oba żądania przechodzą `UsernameNotTaken` (bariera stoi PO jej zapytaniu),
 * więc o zwycięzcy decyduje dopiero unikalny indeks. Przegrany ma dostać
 * zwykły błąd pola z zachowanymi danymi, a nie błąd serwera.
 */
#[Group('dwa-polaczenia')]
final class ZmianaNazwyProfiluNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_dwie_zmiany_na_te_sama_nazwe_daja_jednego_zwyciezce_i_blad_pola_bez_500(): void
    {
        $pierwsza = $this->konto();
        $druga = $this->konto();
        $nazwaPierwszej = $pierwsza->profile()->value('username');
        $nazwaDrugiej = $druga->profile()->value('username');
        $nazwa = 'n887_'.bin2hex(random_bytes(4));

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(887, 1)', []);
        $a = $this->wTle('zmien-profil', ['kto' => (string) $pierwsza->getKey(), 'nazwa' => $nazwa]);
        $this->czekajNaZablokowane(1);
        $b = $this->wTle('zmien-profil', ['kto' => (string) $druga->getKey(), 'nazwa' => $nazwa]);
        // Kontrola dodatnia przeplotu: OBA żądania stoją za barierą, czyli
        // oba już przeczytały „wolna" — inaczej drugie odbiłoby się od
        // zwykłej walidacji i test nie mierzyłby wyścigu.
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$a->wynik(), $b->wynik()];

        foreach ($wyniki as $i => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'zmiana profilu '.($i + 1));
            $this->assertTrue($wynik['ok'], 'Proces zmiany profilu '.($i + 1).' padł: '.$wynik['komunikat']);
            $this->assertSame(302, $wynik['wartosc']['status'], 'Zmiana profilu '.($i + 1).' nie wróciła na formularz: '.json_encode($wynik, JSON_UNESCAPED_UNICODE));
        }

        $zwyciezcy = array_values(array_filter($wyniki, fn (array $w): bool => $w['wartosc']['zapisane'] === 'Zapisane.'));
        $przegrani = array_values(array_filter($wyniki, fn (array $w): bool => $w['wartosc']['blad'] !== null));
        $this->assertCount(1, $zwyciezcy, 'Oczekiwano dokładnie jednego zapisu: '.json_encode($wyniki, JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $przegrani, 'Oczekiwano dokładnie jednego błędu pola: '.json_encode($wyniki, JSON_UNESCAPED_UNICODE));

        $przegrany = $przegrani[0]['wartosc'];
        $this->assertStringStartsWith('Ta nazwa jest już zajęta — wybierz inną.', $przegrany['blad']);
        $this->assertSame('Barbara', $przegrany['stare']['display_name'] ?? null);
        $this->assertSame('Gotuję od czterdziestu lat.', $przegrany['stare']['bio'] ?? null);
        $this->assertSame('Podkarpacie', $przegrany['stare']['region'] ?? null);
        $this->assertSame('zupy i kiszonki', $przegrany['stare']['speciality'] ?? null);

        // Baza: jedna osoba z nową nazwą, druga bez żadnej części zapisu.
        $this->assertSame(1, Profile::query()->whereRaw('lower(username) = ?', [$nazwa])->count());
        $profile = Profile::query()->whereIn('user_id', [$pierwsza->getKey(), $druga->getKey()])->get()->keyBy('user_id');
        $wygrala = $profile[$pierwsza->getKey()]->username === $nazwa ? $pierwsza : $druga;
        $przegrala = $wygrala->is($pierwsza) ? $druga : $pierwsza;
        $this->assertSame($przegrala->is($pierwsza) ? $nazwaPierwszej : $nazwaDrugiej, $profile[$przegrala->getKey()]->username);
        $this->assertNull($profile[$przegrala->getKey()]->bio);
        $this->assertSame('Barbara', $profile[$wygrala->getKey()]->display_name);

        // Dawne nazwy: przegrany rolback wycofał też swój wiersz, zwycięzca
        // zostawił dokładnie jeden — i żaden nie wskazuje na nową nazwę.
        $nazwaWygranej = $wygrala->is($pierwsza) ? $nazwaPierwszej : $nazwaDrugiej;
        $nazwaPrzegranej = $wygrala->is($pierwsza) ? $nazwaDrugiej : $nazwaPierwszej;
        $this->assertSame(
            [strtolower($nazwaWygranej) => (string) $wygrala->getKey()],
            DB::table('profile_username_redirects')->whereIn('user_id', [$pierwsza->getKey(), $druga->getKey()])->pluck('user_id', 'username')->all(),
            'Zwycięzca ma mieć jedno przekierowanie ze swojej dawnej nazwy, przegrany — żadnego.',
        );
        $this->assertFalse(DB::table('profile_username_redirects')->where('username', strtolower($nazwaPrzegranej))->exists(), 'Przegrany nie zmienił nazwy, więc nic po nim nie przekierowuje.');
        $this->assertFalse(DB::table('profile_username_redirects')->where('username', $nazwa)->exists(), 'Zajęta nazwa nie może być jednocześnie dawną.');
        $this->assertSame(0, DB::table('profile_username_redirects')->whereNotIn('user_id', DB::table('users')->select('id'))->count(), 'Sierocy wiersz przekierowania.');
    }

    public function test_dwie_zmiany_na_cudza_dawna_nazwe_kasuja_przekierowanie_i_nie_zostawiaja_sieroty(): void
    {
        $dawny = $this->konto();
        $pierwsza = $this->konto();
        $druga = $this->konto();
        $nazwa = 'd887_'.bin2hex(random_bytes(4));
        // Trzecia osoba kiedyś nazywała się $nazwa: jest po niej przekierowanie.
        DB::table('profile_username_redirects')->insert(['username' => $nazwa, 'user_id' => $dawny->getKey(), 'created_at' => now()]);

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(887, 1)', []);
        $a = $this->wTle('zmien-profil', ['kto' => (string) $pierwsza->getKey(), 'nazwa' => $nazwa]);
        $this->czekajNaZablokowane(1);
        $b = $this->wTle('zmien-profil', ['kto' => (string) $druga->getKey(), 'nazwa' => $nazwa]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$a->wynik(), $b->wynik()];

        foreach ($wyniki as $i => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'zmiana profilu '.($i + 1));
            $this->assertTrue($wynik['ok'], 'Proces zmiany profilu '.($i + 1).' padł: '.$wynik['komunikat']);
            $this->assertSame(302, $wynik['wartosc']['status'], 'Brak 500 ani innego kodu niż powrót na formularz: '.json_encode($wynik, JSON_UNESCAPED_UNICODE));
        }

        $this->assertCount(1, array_filter($wyniki, fn (array $w): bool => $w['wartosc']['zapisane'] === 'Zapisane.'));
        $this->assertCount(1, array_filter($wyniki, fn (array $w): bool => $w['wartosc']['blad'] !== null));
        $this->assertSame(1, Profile::query()->whereRaw('lower(username) = ?', [$nazwa])->count());

        // Nazwę zajął żywy profil, więc cudze przekierowanie zniknęło
        // (decyzja z 1.10.2026) — nie ożyje po kolejnej zmianie nazwy.
        $this->assertFalse(DB::table('profile_username_redirects')->where('username', $nazwa)->exists(), 'Przekierowanie z zajętej nazwy ma zniknąć.');
        $this->assertSame(0, DB::table('profile_username_redirects')->whereNotIn('user_id', DB::table('users')->select('id'))->count());
        // Każdy wiersz tej pary wskazuje osobę, która nazwę faktycznie opuściła.
        $this->assertSame(
            1,
            DB::table('profile_username_redirects')->whereIn('user_id', [$pierwsza->getKey(), $druga->getKey()])->count(),
        );
    }
}
