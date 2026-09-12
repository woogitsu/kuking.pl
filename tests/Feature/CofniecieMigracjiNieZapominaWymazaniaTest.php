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
 * Cofnięcie migracji znacznika wymazania nie każe serwisowi zapomnieć, że
 * dane konta zostały już bezpowrotnie skasowane (D-088).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Z przeglądu wszystkich 76 migracji pod kątem działającego `down()`
 * (`scripts/proba-wycofania.sh`). Schemat przeszedł: 76 migracji schodzi do
 * zera i wraca, a zrzut `pg_dump --schema-only` po cyklu jest IDENTYCZNY ze
 * wzorcem na każdej z 76 głębokości. Usterka jest piętro wyżej — w tym, czego
 * `up()` nie umie odtworzyć.
 *
 * `2026_09_06_110000_add_data_erased_at_to_users` nie jest wymieniona w D-088
 * ani jako naprawiona, ani jako świadomie pominięta, choć reguła D-088 nazywa
 * **zakres usunięcia danych** wprost — to samo przeoczenie, co przy
 * wspomnieniach (dopisane dopiero w PR #327).
 *
 * ZMIERZONE NA PRAWDZIWEJ BAZIE, PRZED NAPRAWĄ (nie w teorii), cyklem
 * `migrate:rollback` → `migrate`:
 *
 *     PRZED:    status=erased          data_erased_at=2026-09-11
 *     PO CYKLU: status=pending_delete  data_erased_at=NULL
 *
 * Po ludzku: konto, którego dane wymazano bezpowrotnie, wraca do stanu
 * „czeka w karencji" — czyli do stanu, z którego `CancelAccountDeletion`
 * pozwala je WSKRZESIĆ, a `kuking:usun-wygasle-konta` bierze je do
 * anonimizacji po raz drugi. Sam znacznik jest jedynym śladem, że karencja
 * się wykonała.
 *
 * DLACZEGO TEN PLIK PATRZY TEŻ NA `status`
 * Bo baza nie pozwala rozdzielić tych dwóch kolumn: CHECK z migracji
 * `2026_09_07_500000` wymaga równoważności
 * `(data_erased_at IS NOT NULL) = (status = 'erased')`. Test sprawdzający
 * samą kolumnę przechodziłby także wtedy, gdyby strażnik puszczał konta
 * w stanie końcowym.
 *
 * TRZY PRZYPADKI, BO STRAŻNIK MA DWIE GAŁĘZIE I JEDNĄ FURTKĘ
 * `PULAPKI_TESTOW.md` §3b i §4: odmowa, przejście przy braku wymazanych kont
 * i przejście przy jawnie otwartej furtce. Bez dwóch ostatnich test
 * przechodziłby także wtedy, gdyby strażnik odmawiał ZAWSZE — a to jest błąd
 * tej samej wagi w drugą stronę.
 */
class CofniecieMigracjiNieZapominaWymazaniaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_06_110000_add_data_erased_at_to_users.php';

    private const FURTKA = 'KUKING_ROLLBACK_KASUJ_ZNACZNIKI_WYMAZANIA';

    protected function tearDown(): void
    {
        putenv(self::FURTKA);
        parent::tearDown();
    }

    #[Test]
    public function test_cofniecie_odmawia_gdy_jakiemus_kontu_wymazano_dane(): void
    {
        // Prawdziwa droga: `markDataErased()` jest jedyną nazwaną metodą,
        // która ten znacznik ustawia (AGENTS.md §7 — zmiana stanu konta nigdy
        // przez `$fillable`), i woła ją wyłącznie `EraseAccountData`.
        $wymazany = $this->user('wymazany');
        $wymazany->markForDeletion();
        $wymazany->markDataErased();

        $this->assertNotNull($wymazany->fresh()->data_erased_at, 'Znacznik się nie zapisał — test mierzyłby nie to.');
        $this->assertSame('erased', $wymazany->fresh()->status);

        $wyjatek = $this->cofnijOczekujacOdmowy();

        // JEDNO konto, nie pięć: liczba stoi na końcu zdania, za rzeczownikiem
        // w mianowniku, więc jedynka jest tu poprawna po polsku.
        $this->assertStringContainsString(
            'Liczba kont z wykonanym wymazaniem danych (data_erased_at) w tabeli `users`: 1.',
            $wyjatek->getMessage(),
        );
        $this->assertStringContainsString('CZYM TO GROZI', $wyjatek->getMessage());
        $this->assertStringContainsString(self::FURTKA, $wyjatek->getMessage());

        // NAJWAŻNIEJSZA ASERCJA: odmowa nie zdążyła niczego zdjąć.
        $this->assertSame(1, $this->iloscKolumn('data_erased_at'));
        $this->assertNotNull($wymazany->fresh()->data_erased_at, 'Znacznik wymazania zniknął mimo odmowy rollbacku.');
        $this->assertSame('erased', $wymazany->fresh()->status);
    }

    #[Test]
    public function test_cofniecie_przechodzi_gdy_nikomu_jeszcze_nie_wymazano_danych(): void
    {
        // Kontrola dodatnia. Konta ISTNIEJĄ, jedno nawet CZEKA w karencji —
        // ale karencja się jeszcze nie wykonała, więc znacznika nie ma i nie
        // ma czego zgubić. To jest stan zdecydowanej większości baz.
        $zdrowy = $this->user('zdrowy');
        $wKarencji = $this->user('wkarencji');
        $wKarencji->markForDeletion();

        $this->assertNull($wKarencji->fresh()->data_erased_at, 'To konto MA być tylko w karencji — inaczej trafiamy w pierwszą gałąź.');
        $this->assertSame('pending_delete', $wKarencji->fresh()->status);
        $this->assertSame('active', $zdrowy->fresh()->status);

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame(0, $this->iloscKolumn('data_erased_at'), 'Rollback nie przeszedł, choć nikomu nie wymazano jeszcze danych.');

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn('data_erased_at'));
    }

    #[Test]
    public function test_cofniecie_przechodzi_gdy_ktos_otworzy_furtke_swiadomie(): void
    {
        // Druga kontrola dodatnia: ta sama baza co w teście odmowy, różnica
        // wyłącznie w jawnej zgodzie człowieka. Furtka jest tu konieczna,
        // bo tego znacznika — inaczej niż terminu kary — nie da się
        // „przeczekać": raz ustawiony zostaje na zawsze.
        $wymazany = $this->user('wymazany');
        $wymazany->markForDeletion();
        $wymazany->markDataErased();

        putenv(self::FURTKA.'=true');

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame(0, $this->iloscKolumn('data_erased_at'), 'Furtka nie zadziałała — rollback nie przeszedł mimo jawnej zgody.');

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn('data_erased_at'));

        // I dowód, po co była odmowa: ślad po wymazaniu NIE wrócił.
        $this->assertNull($wymazany->fresh()->data_erased_at);
    }

    private function cofnijOczekujacOdmowy(): RuntimeException
    {
        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('Cofnięcie migracji przeszło, mimo że w bazie jest konto z wykonanym wymazaniem danych.');
    }

    private function iloscKolumn(string $kolumna): int
    {
        return count(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'users' AND column_name = ?",
            [$kolumna],
        ));
    }
}
