<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji terminu kary nie zamienia zawieszenia czasowego
 * w bezterminowe (D-088).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Z przeglądu WSZYSTKICH 76 migracji pod kątem działającego `down()`
 * (`scripts/proba-wycofania.sh`). Sam schemat okazał się zdrowy: 76 migracji
 * schodzi do zera i wraca, a zrzut `pg_dump --schema-only` po cyklu jest
 * IDENTYCZNY ze wzorcem, na każdej z 76 głębokości. Usterki były piętro
 * wyżej — w ZNACZENIU wartości, których `up()` nie umie odtworzyć.
 *
 * `2026_09_05_001400_add_status_expires_at_to_users` nie jest wymieniona
 * w D-088 ani jako naprawiona, ani jako świadomie pominięta (tam stoją trzy:
 * `theme`, `posts.display_mode`, 2FA). Przegląd z #287 ją przeoczył — tak
 * samo jak przeoczył wspomnienia, dopisane później w PR #327.
 *
 * ZMIERZONE NA PRAWDZIWEJ BAZIE, PRZED NAPRAWĄ (nie w teorii), cyklem
 * `migrate:rollback` → `migrate`, czyli tym, co robi `migrate:refresh` w CI
 * i awaryjny rollback wdrożenia:
 *
 *     PRZED:    status=suspended  status_expires_at=2026-09-19
 *     PO CYKLU: status=suspended  status_expires_at=NULL
 *
 * Po ludzku: kara na siedem dni staje się karą na zawsze. Migracja mówi
 * o tym sama w sekcji „PROBLEM": przy jednym moderatorze (D-012) nikt kary
 * nie odklikuje ręcznie, więc kara bez terminu jest karą bezterminową.
 *
 * TRZY PRZYPADKI, BO STRAŻNIK MA DWIE GAŁĘZIE I JEDNĄ FURTKĘ
 * `PULAPKI_TESTOW.md` §3b: dwa testy trafiające w tę samą gałąź warunku dają
 * jedną kontrolę i złudzenie dwóch. Warunek brzmi
 * `$zTerminem > 0 && ! $this->wolnoSkasowacTerminyKar()`, więc osobno
 * sprawdzamy odmowę (pierwsza gałąź), przejście przy braku terminów (druga)
 * i przejście przy jawnie otwartej furtce (trzecia).
 *
 * Kontrola dodatnia nie jest formalnością (`PULAPKI_TESTOW.md` §4): sama
 * odmowa przechodziłaby także wtedy, gdyby strażnik odmawiał ZAWSZE —
 * a zablokowanie rollbacku na zawsze jest błędem tej samej wagi w drugą
 * stronę i AGENTS.md §6 odrzuca je wprost.
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Że po cyklu `rollback` → `migrate` termin wraca. Nie wraca i nie ma jak —
 * na tym polega cała usterka. Test dowodzi, że cykl się NIE ZACZNIE, dopóki
 * ktoś nie powie tego wprost.
 */
class CofniecieMigracjiNieRobiKaryBezterminowejTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_05_001400_add_status_expires_at_to_users.php';

    private const FURTKA = 'KUKING_ROLLBACK_KASUJ_TERMINY_KAR';

    protected function tearDown(): void
    {
        putenv(self::FURTKA);
        parent::tearDown();
    }

    #[Test]
    public function test_cofniecie_odmawia_gdy_ktos_ma_kare_z_terminem(): void
    {
        // Prawdziwa droga produkcyjna: `suspend()` z terminem, czyli ta sama
        // metoda, którą woła panel moderacji. `status` jest poza `$fillable`
        // (AGENTS.md §7), więc innej drogi i tak nie ma.
        $ukarany = $this->user('ukarany');
        $ukarany->suspend(now()->addDays(7));

        $this->assertNotNull($ukarany->fresh()->status_expires_at, 'Kara nie zapisała terminu — test mierzyłby nie to.');

        $wyjatek = $this->cofnijOczekujacOdmowy();

        // JEDNA kara, nie pięć: liczba stoi na końcu zdania, za rzeczownikiem
        // w mianowniku, więc jedynka jest tu poprawna po polsku i wolno ją
        // zamrozić w teście.
        $this->assertStringContainsString(
            'Liczba zawieszeń z zapisanym terminem końca w tabeli `users`: 1.',
            $wyjatek->getMessage(),
        );
        $this->assertStringContainsString('CZYM TO GROZI', $wyjatek->getMessage());
        $this->assertStringContainsString('kuking:zdejmij-wygasle-kary', $wyjatek->getMessage());
        $this->assertStringContainsString(self::FURTKA, $wyjatek->getMessage());

        // NAJWAŻNIEJSZA ASERCJA: odmowa nie zdążyła niczego zdjąć. Odmowa po
        // skasowaniu kolumny byłaby tylko ładniejszym komunikatem o tej samej
        // utracie.
        $this->assertSame(1, $this->iloscKolumn('status_expires_at'));
        $this->assertNotNull($ukarany->fresh()->status_expires_at, 'Termin kary zniknął mimo odmowy rollbacku.');
    }

    #[Test]
    public function test_cofniecie_przechodzi_gdy_zadna_kara_nie_ma_terminu(): void
    {
        // Kontrola dodatnia. Konta ISTNIEJĄ i jedno z nich jest nawet
        // ukarane — ale bezterminowo, czyli tak, jak wygląda 99,9% bazy.
        // Rollback musi tu przechodzić bez pytania.
        $zdrowy = $this->user('zdrowy');
        $zawieszony = $this->user('bezterminowo');
        $zawieszony->suspend();
        $zbanowany = $this->user('zbanowany');
        $zbanowany->ban();

        $this->assertNull($zawieszony->fresh()->status_expires_at, 'To zawieszenie MA być bezterminowe — inaczej trafiamy w pierwszą gałąź.');
        $this->assertNull($zbanowany->fresh()->status_expires_at);
        $this->assertSame('active', $zdrowy->fresh()->status);

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame(0, $this->iloscKolumn('status_expires_at'), 'Rollback nie przeszedł, choć żadna kara nie ma terminu.');

        // Odtwarzamy schemat, żeby nie zostawić bazy w połowie drogi dla
        // kolejnych testów w tym samym procesie.
        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn('status_expires_at'));
    }

    #[Test]
    public function test_cofniecie_przechodzi_gdy_ktos_otworzy_furtke_swiadomie(): void
    {
        // Druga kontrola dodatnia: ta sama baza co w teście odmowy, różnica
        // wyłącznie w jawnej zgodzie człowieka. Bez tego przypadku furtka
        // mogłaby nie działać wcale i nikt by się nie dowiedział — a wtedy
        // strażnik blokowałby rollback na zawsze.
        $ukarany = $this->user('ukarany');
        $ukarany->suspend(now()->addDays(7));

        putenv(self::FURTKA.'=true');

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame(0, $this->iloscKolumn('status_expires_at'), 'Furtka nie zadziałała — rollback nie przeszedł mimo jawnej zgody.');

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn('status_expires_at'));

        // I dowód, po co była odmowa: termin kary NIE wrócił.
        $this->assertNull($ukarany->fresh()->status_expires_at);
        $this->assertSame('suspended', $ukarany->fresh()->status);
    }

    private function cofnijOczekujacOdmowy(): RuntimeException
    {
        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('Cofnięcie migracji przeszło, mimo że w bazie jest kara z terminem, którego `up()` nie odtworzy.');
    }

    private function iloscKolumn(string $kolumna): int
    {
        return count(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'users' AND column_name = ?",
            [$kolumna],
        ));
    }
}
