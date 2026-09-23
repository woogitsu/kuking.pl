<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Komendy harmonogramu nie wypisują adresów e-mail (issue #1026).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TU MIERZYMY
 * ────────────────────────────────────────────────────────────────────────
 *
 * `kuking:zdejmij-wygasle-kary` tyka CO GODZINĘ (`routes/console.php`),
 * a wszystko, co wypisze, idzie do logu platformy hostingowej — czyli poza
 * kontrolę serwisu, bez ustalonej retencji i bez sposobu na skasowanie
 * (AGENTS.md §7). Do 22 września 2026 wypisywała pełny adres e-mail, i to
 * TAKŻE w trybie `--dry-run`, który z założenia niczego nie miał robić.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO SAMO „WYJŚCIE NIE ZAWIERA ADRESU" TO ZA MAŁO
 * ────────────────────────────────────────────────────────────────────────
 *
 * Asercja `assertStringNotContainsString($adres, $wyjscie)` przechodzi
 * na PUSTYM wyjściu. Przechodzi, gdy komenda w ogóle nie znalazła konta.
 * Przechodzi, gdy zapytanie wybrało zły wiersz. Przechodzi nawet wtedy, gdy
 * ktoś skasuje całą pętlę. Taki test pilnowałby niczego i świeciłby na
 * zielono nad wyciekiem, gdyby wyciek wrócił inną drogą.
 *
 * Dlatego KAŻDY test w tym pliku ma parę asercji:
 *
 *   1. wyjście ZAWIERA identyfikator konta — dowód, że pętla naprawdę się
 *      wykonała i naprawdę zobaczyła TO konto;
 *   2. wyjście NIE ZAWIERA adresu ani jego części przed `@` — dowód, że
 *      to, co zobaczyła, wypisała bez danych osobowych.
 *
 * Bez (1) asercja (2) nie ma prawa uchodzić za dowód. Sprawdzone ręcznie:
 * z przywróconym `{$user->email}` w `RestoreExpiredSuspensions` oba testy
 * są czerwone na asercji (2).
 *
 * Adresy pochodzą z fabryki (`fake()->safeEmail()`, domena zarezerwowana
 * do przykładów) — do tego pliku nie wchodzi żaden prawdziwy adres.
 */
class HarmonogramNieWypisujeDanychOsobowychTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Konto z karą, której termin właśnie minął — czyli dokładnie to, co
     * komenda ma znaleźć przy najbliższym tyknięciu.
     */
    private function ukaranyIWygasly(): User
    {
        $user = User::factory()->create();
        $user->suspend(now()->subDay());

        return $user->refresh();
    }

    /**
     * Część adresu przed `@` — to ona identyfikuje człowieka i to ona nie ma
     * prawa przejść. Samej domeny nie sprawdzamy: `example.org` z fabryki
     * potrafi trafić do wyjścia zupełnie inną drogą i czyniłoby test
     * kruchym bez żadnego zysku.
     */
    private function nazwaSkrzynki(User $user): string
    {
        $adres = (string) $user->email;

        return substr($adres, 0, (int) strrpos($adres, '@'));
    }

    public function test_dry_run_nie_wypisuje_adresu_e_mail(): void
    {
        $user = $this->ukaranyIWygasly();

        $kod = Artisan::call('kuking:zdejmij-wygasle-kary', ['--dry-run' => true]);
        $wyjscie = Artisan::output();

        $this->assertSame(0, $kod);

        // KONTROLA DODATNIA: bez tego cała reszta przechodzi na pustym
        // wyjściu i test nie mierzy niczego.
        $this->assertStringContainsString(
            (string) $user->getKey(),
            $wyjscie,
            'Dry-run nie wypisał w ogóle tego konta — reszta asercji nie ma o czym orzekać.',
        );

        $this->assertStringNotContainsString(
            (string) $user->email,
            $wyjscie,
            'Pełny adres e-mail w wyjściu komendy chodzącej co godzinę (issue #1026).',
        );

        $this->assertStringNotContainsString($this->nazwaSkrzynki($user), $wyjscie);

        // Dry-run naprawdę niczego nie zmienił — inaczej „nic nie zmieniam"
        // byłoby drugą nieprawdą w tej samej komendzie.
        $this->assertSame(User::STATUS_SUSPENDED, $user->refresh()->status);
    }

    public function test_zwykly_przebieg_nie_wypisuje_adresu_e_mail(): void
    {
        $user = $this->ukaranyIWygasly();

        $kod = Artisan::call('kuking:zdejmij-wygasle-kary');
        $wyjscie = Artisan::output();

        $this->assertSame(0, $kod);

        // KONTROLA DODATNIA — jak wyżej.
        $this->assertStringContainsString((string) $user->getKey(), $wyjscie);

        $this->assertStringNotContainsString((string) $user->email, $wyjscie);
        $this->assertStringNotContainsString($this->nazwaSkrzynki($user), $wyjscie);

        // I komenda nadal robi swoje — maskowanie nie może być okupione
        // tym, że kara przestaje wygasać.
        $this->assertSame(User::STATUS_ACTIVE, $user->refresh()->status);
    }

    /**
     * Ta sama rodzina, komenda ręczna: `kuking:nadaj-role` wypisywała pełny
     * adres konta, któremu zmienia rolę — a `findByLogin()` przyjmuje także
     * NAZWĘ konta, więc wyjście dopisywało do logu powiązanie
     * nazwa → pełny adres, którego w samym wywołaniu nie było.
     */
    public function test_nadanie_roli_wypisuje_adres_tylko_w_skrocie(): void
    {
        $user = User::factory()->create();

        $kod = Artisan::call('kuking:nadaj-role', [
            'login' => (string) $user->email,
            'rola' => User::ROLE_MODERATOR,
            '--tak' => true,
        ]);
        $wyjscie = Artisan::output();

        $this->assertSame(0, $kod);

        // KONTROLA DODATNIA: komenda naprawdę doszła do linii podsumowania.
        $this->assertStringContainsString('Rola konta', $wyjscie);
        $this->assertSame(User::ROLE_MODERATOR, $user->refresh()->role);

        // Wyjście: skrót, nie adres. `login` podany w wywołaniu bierzemy
        // z fabryki i sprawdzamy sam kształt wypisanego skrótu.
        $this->assertStringContainsString(
            mb_substr((string) $user->email, 0, 1).'***@',
            $wyjscie,
        );
        $this->assertStringNotContainsString($this->nazwaSkrzynki($user), $wyjscie);
    }
}
