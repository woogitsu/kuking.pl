<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Pantry\CoMamWDomu;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1958 na poziomie tego, co widzi człowiek: dwa PRAWDZIWE żądania
 * `POST /co-mam-w-domu` w dwóch procesach, na dwóch połączeniach do
 * PostgreSQL, przy granicy limitu `CoMamWDomu::MAKS_PRODUKTOW` (150).
 *
 * Uzupełnia `PantryLimitNaDwochPolaczeniachTest` (akcja domenowa) o:
 * - kryterium „polski komunikat, bez błędu 500” — mierzone kodem
 *   odpowiedzi i błędem pola `nazwa` z sesji, po całym stosie middleware
 *   i kontrolera;
 * - KONTROLĘ DODATNIĄ przy 148: oba równoległe żądania mają przejść.
 *   Bez niej test przy 149 byłby zielony także wtedy, gdy blokada
 *   odrzucałaby drugie żądanie zawsze, a nie tylko po przekroczeniu limitu.
 *
 * BARIERA. `SELECT … FROM users … FOR UPDATE` na wierszu właściciela listy.
 * Z poprawką oba żądania czekają na nią w `lockForUpdate()` PRZED
 * policzeniem listy. Bez poprawki też stają w kolejce — ale dopiero przy
 * `INSERT INTO pantry_items`, bo sprawdzenie klucza obcego bierze
 * `FOR KEY SHARE` na tym samym wierszu `users`, a to koliduje z `FOR UPDATE`.
 * Wtedy oba mają już policzone 149 i po zwolnieniu bariery dopisują dwa
 * wiersze — dokładnie usterka z issue. Przeplot ustawia się więc w obu
 * wersjach kodu, a test rozstrzyga wynikiem, nie przekroczeniem czasu.
 */
#[Group('dwa-polaczenia')]
final class PantryLimitPrzezHttpNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<ProcesRownolegly> */
    private array $uczestnicy = [];

    protected function tearDown(): void
    {
        foreach ($this->uczestnicy as $proces) {
            $proces->zabij();
        }
        $this->uczestnicy = [];

        // `pantry_items.user_id` ma `ON DELETE CASCADE` — listy znikają
        // razem z kontami w `posprzatajKonta()` klasy bazowej.
        parent::tearDown();
    }

    public function test_przy_149_jedno_zadanie_dodaje_150_produkt_a_drugie_dostaje_polski_komunikat(): void
    {
        $user = $this->konto();
        $this->wypelnijListe($user, CoMamWDomu::MAKS_PRODUKTOW - 1);

        [$pierwszy, $drugi] = $this->dwaRownoczesneDodania($user, 'brukselka', 'topinambur');

        $statusy = [$pierwszy['status'], $drugi['status']];
        $this->assertSame(
            [302, 302],
            $statusy,
            'Oba żądania mają wrócić przekierowaniem na listę (302) — nigdy błędem 500. Było: '.implode(', ', $statusy),
        );

        $odrzucone = array_values(array_filter([$pierwszy, $drugi], static fn (array $w): bool => $w['blad'] !== null));
        $udane = array_values(array_filter([$pierwszy, $drugi], static fn (array $w): bool => $w['blad'] === null));

        $this->assertCount(
            1,
            $odrzucone,
            'Przy 149 produktach dokładnie jedno z dwóch równoległych żądań ma dostać komunikat o limicie — '
            .'zero odrzuconych znaczy, że limit 150 znowu dało się przekroczyć.',
        );
        $this->assertSame(
            'Na liście jest już 150 produktów. Usuń te, których już nie masz, i dodaj nowy.',
            $odrzucone[0]['blad'],
        );
        $this->assertCount(1, $udane);
        $this->assertIsString($udane[0]['zapisane']);
        $this->assertStringStartsWith('Dodano „', $udane[0]['zapisane']);

        $this->assertSame(
            CoMamWDomu::MAKS_PRODUKTOW,
            DB::table('pantry_items')->where('user_id', $user->getKey())->count(),
            'Po dwóch równoległych żądaniach przy 149 produktach lista ma mieć dokładnie 150 wierszy.',
        );
    }

    public function test_kontrola_dodatnia_przy_148_oba_rownolegle_zadania_przechodza(): void
    {
        $user = $this->konto();
        $this->wypelnijListe($user, CoMamWDomu::MAKS_PRODUKTOW - 2);

        [$pierwszy, $drugi] = $this->dwaRownoczesneDodania($user, 'brukselka', 'topinambur');

        foreach ([$pierwszy, $drugi] as $numer => $wynik) {
            $this->assertSame(302, $wynik['status'], 'Żądanie '.($numer + 1).' nie wróciło przekierowaniem.');
            $this->assertNull(
                $wynik['blad'],
                'Przy 148 produktach oba dodania mieszczą się w limicie, a żądanie '.($numer + 1).' dostało: '.$wynik['blad'],
            );
            $this->assertIsString($wynik['zapisane']);
            $this->assertStringStartsWith('Dodano „', $wynik['zapisane']);
        }

        $this->assertSame(
            CoMamWDomu::MAKS_PRODUKTOW,
            DB::table('pantry_items')->where('user_id', $user->getKey())->count(),
        );
    }

    /**
     * Dwa żądania ustawione w kolejce na tej samej barierze, puszczone razem.
     *
     * @return array{0: array{status: int, blad: ?string, zapisane: ?string}, 1: array{status: int, blad: ?string, zapisane: ?string}}
     */
    private function dwaRownoczesneDodania(User $user, string $nazwaPierwsza, string $nazwaDruga): array
    {
        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $user->getKey()]);
        $pierwszy = $this->uczestnik($user, $nazwaPierwsza);
        $this->czekajNaZablokowane(1);
        $drugi = $this->uczestnik($user, $nazwaDruga);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [];
        foreach ([$pierwszy, $drugi] as $numer => $proces) {
            $wynik = $proces->wynik();
            $this->assertBezZakleszczenia($wynik, 'równoległe dodanie '.($numer + 1));
            $this->assertTrue(
                $wynik['ok'],
                'Uczestnik '.($numer + 1).' nie doszedł do odpowiedzi HTTP: '.($wynik['wyjatek'] ?? '').' '.$wynik['komunikat'],
            );
            $this->assertIsArray($wynik['wartosc']);
            $wyniki[] = $wynik['wartosc'];
        }

        /** @var array{0: array{status: int, blad: ?string, zapisane: ?string}, 1: array{status: int, blad: ?string, zapisane: ?string}} $wyniki */
        return $wyniki;
    }

    private function uczestnik(User $user, string $nazwa): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(
            __DIR__.'/bin/pantry-limit.php',
            'dodaj-http',
            ['kto' => (string) $user->getKey(), 'nazwa' => $nazwa],
            [
                'DB_DATABASE' => $this->baza,
                'APP_ENV' => 'testing',
                'BCRYPT_ROUNDS' => '4',
                'MAIL_MAILER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'KUKING_LOCK_TIMEOUT' => self::LOCK_TIMEOUT,
                'KUKING_STATEMENT_TIMEOUT' => self::STATEMENT_TIMEOUT,
            ],
        );

        $this->uczestnicy[] = $proces;

        return $proces;
    }

    private function wypelnijListe(User $user, int $ile): void
    {
        // Numer przyklejony do słowa: rdzeń porównania obcina końcówki,
        // więc „produkt 0” i „produkt 1” mogłyby dać ten sam klucz.
        $wiersze = [];
        for ($i = 0; $i < $ile; $i++) {
            $wiersze[] = ['user_id' => $user->getKey(), 'name' => 'produktxyz'.$i];
        }
        DB::table('pantry_items')->insert($wiersze);

        $this->assertSame($ile, DB::table('pantry_items')->where('user_id', $user->getKey())->count());
    }
}
