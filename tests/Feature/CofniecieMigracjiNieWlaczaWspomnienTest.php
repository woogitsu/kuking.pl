<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Rollback migracji wspomnień nie włącza z powrotem wyłącznika osoby
 * w żałobie i nie wyciąga na stronę główną wpisu, który ktoś świadomie
 * schował (issue #287 / MIG-01, D-088 — dokończenie tamtej naprawy).
 *
 * DLACZEGO TEN PLIK ISTNIEJE OBOK `CofniecieMigracjiNiePodmieniaZakresuUsunieciaTest`
 * MIG-01 zostało naprawione dla `users.delete_scope`, a przegląd migracji
 * zrobiony wtedy „pod tym samym kątem" wymienił trzy pliki do świadomego
 * pominięcia (motyw, `display_mode`, 2FA) i TĘ MIGRACJĘ PRZEOCZYŁ — mimo że
 * reguła D-088 nazywa **widoczność** wprost. Czyli reguła była zapisana,
 * a jedno z jej złamań chodziło dalej po `main`.
 *
 * ODTWORZONE NA PRAWDZIWEJ BAZIE, PRZED NAPRAWĄ (nie w teorii):
 *
 *     PRZED:    memories_enabled=false  hide_as_memory=true
 *     PO CYKLU: memories_enabled=true   hide_as_memory=false
 *
 * gdzie „CYKL" to `migrate:rollback` → `migrate`, czyli dokładnie to, co CI
 * robi jako `migrate:refresh`, i dokładnie to, co robi awaryjny rollback
 * wdrożenia. Kolumny są `NOT NULL DEFAULT`, więc `up()` przy ponownym
 * uruchomieniu nadaje wszystkim wartość domyślną — odwrotną do obu decyzji.
 * Żaden test w tym repozytorium tego nie łapał: istniejące testy migracji
 * patrzyły na KSZTAŁT schematu (`information_schema.columns`), a
 * `WspomnieniaTest` na zachowanie mechaniki przy niezmienionym schemacie.
 *
 * CZTERY PRZYPADKI, BO WARUNEK STRAŻNIKA MA DWIE GAŁĘZIE
 * `PULAPKI_TESTOW.md` #3b: dwa testy trafiające w tę samą gałąź warunku
 * dają jedną kontrolę i złudzenie dwóch. Wyłącznik konta i schowany wpis to
 * dwa osobne liczniki w `down()`, więc mają tu dwa osobne testy i dwa osobne
 * sabotaże. Do tego dwie kontrole dodatnie (`PULAPKI_TESTOW.md` #4): sama
 * odmowa nie dowodzi, że rollback przy domyślnych wartościach nadal
 * przechodzi — a musi, bo zablokowanie rollbacku na zawsze jest błędem tej
 * samej wagi w drugą stronę (#287 wprost to odrzuca).
 */
class CofniecieMigracjiNieWlaczaWspomnienTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_06_140000_add_memories_to_users_and_posts.php';

    #[Test]
    public function test_cofniecie_odmawia_gdy_ktos_wylaczyl_wspomnienia(): void
    {
        // Prawdziwa droga produkcyjna: ustawienia prywatności, nie ręczny
        // UPDATE. `PrivacySettingsController` jest jedynym miejscem, które
        // zapisuje ten wyłącznik, więc test przechodzi tę samą drogę, co
        // człowiek odklikujący pole na `/ustawienia/prywatnosc`.
        $basia = $this->user('basia');
        $this->actingAs($basia)->put(route('settings.privacy'), [
            // Brak `memories_enabled` w żądaniu = odznaczone pole. Tak działa
            // checkbox w HTML-u i tak wraca z formularza (ten sam kształt
            // żądania co w `WspomnieniaTest`).
            'wants_weekly_digest' => '1',
        ])->assertRedirect();

        $this->assertFalse($basia->fresh()->memories_enabled, 'Wyłącznik nie zapisał się — test mierzyłby nie to.');

        $wyjatek = $this->cofnijOczekujacOdmowy();

        $this->assertStringContainsString('1 kont ma wyłączone wspomnienia', $wyjatek->getMessage());
        $this->assertStringContainsString('CO ZROBIĆ', $wyjatek->getMessage());

        // NAJWAŻNIEJSZA ASERCJA: decyzja człowieka jest NADAL wyłączeniem.
        // Odmowa, która zdążyła już zdjąć kolumnę, byłaby tylko ładniejszym
        // komunikatem o tej samej utracie.
        $this->assertFalse($basia->fresh()->memories_enabled, 'Wyłącznik wspomnień wrócił do „pokazuj" mimo odmowy rollbacku.');
        $this->assertSame(1, $this->iloscKolumn('users', 'memories_enabled'));
        $this->assertSame(1, $this->iloscKolumn('posts', 'hide_as_memory'));
    }

    #[Test]
    public function test_cofniecie_odmawia_gdy_ktos_schowal_pojedynczy_wpis(): void
    {
        // DRUGA GAŁĄŹ WARUNKU, własny test: konto ma wspomnienia WŁĄCZONE
        // (domyślnie), więc pierwszy licznik strażnika jest zerem. Gdyby
        // strażnik liczył tylko konta, ten przypadek przeszedłby po cichu
        // i schowany wpis wróciłby na stronę główną.
        $marek = $this->user('marek');
        $wpis = $this->wpis($marek);

        $this->actingAs($marek)->post(route('wspomnienia.ukryj', $wpis))->assertRedirect();

        $this->assertTrue($wpis->fresh()->hide_as_memory, 'Wpis się nie schował — test mierzyłby nie to.');
        $this->assertTrue($marek->fresh()->memories_enabled, 'To konto MA mieć wspomnienia włączone — inaczej trafiamy w pierwszą gałąź.');

        $wyjatek = $this->cofnijOczekujacOdmowy();

        $this->assertStringContainsString('1 wpisów jest schowanych', $wyjatek->getMessage());
        $this->assertStringContainsString('0 kont ma wyłączone wspomnienia', $wyjatek->getMessage());

        $this->assertTrue($wpis->fresh()->hide_as_memory, 'Schowany wpis wrócił do „pokazuj" mimo odmowy rollbacku.');
        $this->assertSame(1, $this->iloscKolumn('posts', 'hide_as_memory'));
    }

    #[Test]
    public function test_cofniecie_przechodzi_gdy_wszyscy_siedza_na_domyslnych(): void
    {
        // Kontrola dodatnia: konto i wpis ISTNIEJĄ, ale nikt niczego nie
        // zmienił. To jest stan 99,9% wierszy w serwisie i rollback musi tu
        // przechodzić bez pytania.
        $zofia = $this->user('zofia');
        $this->wpis($zofia);

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame(0, $this->iloscKolumn('users', 'memories_enabled'), 'Rollback nie przeszedł, choć nikt nic nie zmienił.');
        $this->assertSame(0, $this->iloscKolumn('posts', 'hide_as_memory'));

        // Odtwarzamy schemat, żeby nie zostawić bazy w połowie drogi dla
        // kolejnych testów w tym samym procesie.
        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn('users', 'memories_enabled'));
    }

    #[Test]
    public function test_cofniecie_przechodzi_na_swiezej_bazie_bez_wpisow_i_kont(): void
    {
        // Kontrola dodatnia numer dwa: świeży staging po `migrate:fresh` nie
        // może zostać zablokowany pustymi tabelami.
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame(0, $this->iloscKolumn('users', 'memories_enabled'));
        $this->assertSame(0, $this->iloscKolumn('posts', 'hide_as_memory'));

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
    }

    private function cofnijOczekujacOdmowy(): RuntimeException
    {
        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('Cofnięcie migracji przeszło, mimo że w bazie jest decyzja, której DEFAULT nie odtworzy.');
    }

    private function wpis(User $autor): Post
    {
        return Post::create([
            'author_id' => $autor->getKey(),
            'body' => 'Rosół jak zawsze.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subYear(),
        ])->fresh();
    }

    private function iloscKolumn(string $tabela, string $kolumna): int
    {
        return count(DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$tabela, $kolumna],
        ));
    }
}
