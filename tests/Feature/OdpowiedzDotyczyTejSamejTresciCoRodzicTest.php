<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #954: baza — nie tylko `PublishComment` — pilnuje, że odpowiedź
 * dotyczy tej samej treści co rodzic i wisi pod komentarzem głównym.
 *
 * Wszystkie zapisy idą przez Query Builder z pominięciem akcji domenowej,
 * bo dowodzimy wyzwalacza `comments_odpowiedz_zgodna_z_rodzicem_trg`,
 * a nie kodu PHP. Kontrola dodatnia stoi w tym samym pliku: po zdjęciu
 * wyzwalacza ten sam zapis przechodzi.
 *
 * @bez-kontroli-dodatniej Plik nie asertuje na tekście źródeł — migrację wykonuje, a kontrola dodatnia (zdjęcie wyzwalacza) stoi w samym teście.
 */
class OdpowiedzDotyczyTejSamejTresciCoRodzicTest extends TestCase
{
    use RefreshDatabase;

    private const WYZWALACZ = 'comments_odpowiedz_zgodna_z_rodzicem_trg';

    private const MIGRACJA = 'migrations/2026_09_24_100000_odpowiedz_dotyczy_tej_samej_tresci_co_rodzic.php';

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = User::factory()->create();
    }

    /** @return iterable<string, array{string}> */
    public static function rodzajeTresci(): iterable
    {
        yield 'wpis' => ['post_id'];
        yield 'przepis' => ['recipe_id'];
        yield 'wykonanie' => ['cooked_event_id'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function paryRoznychRodzajow(): iterable
    {
        foreach (['post_id', 'recipe_id', 'cooked_event_id'] as $rodzica) {
            foreach (['post_id', 'recipe_id', 'cooked_event_id'] as $odpowiedzi) {
                if ($rodzica !== $odpowiedzi) {
                    yield "{$rodzica} → {$odpowiedzi}" => [$rodzica, $odpowiedzi];
                }
            }
        }
    }

    #[DataProvider('rodzajeTresci')]
    public function test_poprawny_komentarz_glowny_i_odpowiedz_przechodza(string $kolumna): void
    {
        $tresc = $this->tresc($kolumna);
        $korzen = $this->wstaw([$kolumna => $tresc]);
        $odpowiedz = $this->wstaw([$kolumna => $tresc, 'parent_id' => $korzen]);

        $this->assertSame($korzen, DB::table('comments')->where('id', $odpowiedz)->value('parent_id'));
    }

    public function test_baza_odrzuca_odpowiedz_pod_komentarzem_z_innego_wpisu(): void
    {
        $korzen = $this->wstaw(['post_id' => $this->tresc('post_id')]);

        $this->odrzucone(fn () => $this->wstaw(['post_id' => $this->tresc('post_id'), 'parent_id' => $korzen]));
    }

    #[DataProvider('paryRoznychRodzajow')]
    public function test_baza_odrzuca_odpowiedz_innego_rodzaju_niz_rodzic(string $rodzica, string $odpowiedzi): void
    {
        $korzen = $this->wstaw([$rodzica => $this->tresc($rodzica)]);

        $this->odrzucone(fn () => $this->wstaw([$odpowiedzi => $this->tresc($odpowiedzi), 'parent_id' => $korzen]));
    }

    public function test_baza_odrzuca_odpowiedz_na_odpowiedz(): void
    {
        $wpis = $this->tresc('post_id');
        $a = $this->wstaw(['post_id' => $wpis]);
        $b = $this->wstaw(['post_id' => $wpis, 'parent_id' => $a]);

        $this->odrzucone(fn () => $this->wstaw(['post_id' => $wpis, 'parent_id' => $b]));
    }

    public function test_baza_odrzuca_komentarz_bedacy_odpowiedzia_na_samego_siebie(): void
    {
        $wpis = $this->tresc('post_id');
        $id = (string) Str::uuid();

        $this->odrzucone(fn () => $this->wstaw(['id' => $id, 'post_id' => $wpis, 'parent_id' => $id]));

        $korzen = $this->wstaw(['post_id' => $wpis]);
        $this->odrzucone(fn () => DB::table('comments')->where('id', $korzen)->update(['parent_id' => $korzen]));
    }

    public function test_komentarz_z_odpowiedziami_nie_zmienia_celu_ani_nie_staje_sie_odpowiedzia(): void
    {
        $wpis = $this->tresc('post_id');
        $korzen = $this->wstaw(['post_id' => $wpis]);
        $this->wstaw(['post_id' => $wpis, 'parent_id' => $korzen]);
        $innyKorzen = $this->wstaw(['post_id' => $wpis]);

        $this->odrzucone(fn () => DB::table('comments')->where('id', $korzen)->update(['post_id' => $this->tresc('post_id')]));
        $this->odrzucone(fn () => DB::table('comments')->where('id', $korzen)->update(['parent_id' => $innyKorzen]));
    }

    public function test_odpowiedz_moze_przejsc_pod_inny_korzen_tej_samej_tresci(): void
    {
        $wpis = $this->tresc('post_id');
        $korzen = $this->wstaw(['post_id' => $wpis]);
        $odpowiedz = $this->wstaw(['post_id' => $wpis, 'parent_id' => $korzen]);
        $innyKorzen = $this->wstaw(['post_id' => $wpis]);

        DB::table('comments')->where('id', $odpowiedz)->update(['parent_id' => $innyKorzen]);

        $this->assertSame($innyKorzen, DB::table('comments')->where('id', $odpowiedz)->value('parent_id'));
    }

    public function test_komentarz_bez_odpowiedzi_moze_zmienic_cel(): void
    {
        $korzen = $this->wstaw(['post_id' => $this->tresc('post_id')]);
        $nowyWpis = $this->tresc('post_id');

        DB::table('comments')->where('id', $korzen)->update(['post_id' => $nowyWpis]);

        $this->assertSame($nowyWpis, DB::table('comments')->where('id', $korzen)->value('post_id'));
    }

    public function test_usuniecie_rodzica_nadal_kaskadowo_usuwa_odpowiedzi(): void
    {
        $wpis = $this->tresc('post_id');
        $korzen = $this->wstaw(['post_id' => $wpis]);
        $odpowiedz = $this->wstaw(['post_id' => $wpis, 'parent_id' => $korzen]);

        DB::table('comments')->where('id', $korzen)->delete();

        $this->assertFalse(DB::table('comments')->where('id', $odpowiedz)->exists());
    }

    /**
     * Kontrola dodatnia: bez wyzwalacza ten sam zapis przechodzi — więc
     * odrzucenia wyżej zawdzięczamy wyzwalaczowi, a nie np. FK albo CHECK.
     * DDL cofa transakcja RefreshDatabase.
     */
    public function test_kontrola_dodatnia_bez_wyzwalacza_baza_przyjmuje_zakazany_stan(): void
    {
        DB::unprepared('DROP TRIGGER '.self::WYZWALACZ.' ON comments');
        $this->assertFalse($this->wyzwalaczIstnieje());

        $wpis = $this->tresc('post_id');
        $a = $this->wstaw(['post_id' => $wpis]);
        $b = $this->wstaw(['post_id' => $this->tresc('post_id'), 'parent_id' => $a]);
        $c = $this->wstaw(['post_id' => $wpis, 'parent_id' => $b]);

        $this->assertSame(3, DB::table('comments')->whereIn('id', [$a, $b, $c])->count());
    }

    public function test_migracja_odmawia_przy_zastanych_niespojnych_danych_i_ich_nie_rusza(): void
    {
        $migracja = require database_path(self::MIGRACJA);
        $migracja->down();
        $this->assertFalse($this->wyzwalaczIstnieje());

        $wpis = $this->tresc('post_id');
        $a = $this->wstaw(['post_id' => $wpis]);
        $zlyWpis = $this->tresc('post_id');
        $b = $this->wstaw(['post_id' => $zlyWpis, 'parent_id' => $a]);
        $c = $this->wstaw(['post_id' => $zlyWpis, 'parent_id' => $b]);

        try {
            DB::transaction(fn () => $migracja->up());
            $this->fail('Migracja powinna odmówić przy niespójnych odpowiedziach.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Odpowiedzi pod komentarzem, który sam jest odpowiedzią: 1.', $e->getMessage());
            $this->assertStringContainsString('Odpowiedzi dotyczące innej treści niż rodzic: 1.', $e->getMessage());
        }

        $this->assertFalse($this->wyzwalaczIstnieje());
        $this->assertSame($a, DB::table('comments')->where('id', $b)->value('parent_id'));
        $this->assertSame($zlyWpis, DB::table('comments')->where('id', $b)->value('post_id'));
        $this->assertSame($b, DB::table('comments')->where('id', $c)->value('parent_id'));

        DB::table('comments')->whereIn('id', [$b, $c])->delete();
        $migracja->up();
        $this->assertTrue($this->wyzwalaczIstnieje());
    }

    private function tresc(string $kolumna): string
    {
        return match ($kolumna) {
            'post_id' => Post::factory()->create()->id,
            'recipe_id' => Recipe::factory()->create()->id,
            'cooked_event_id' => CookedEvent::factory()->create()->id,
        };
    }

    /** @param array<string, string> $atrybuty */
    private function wstaw(array $atrybuty): string
    {
        $id = $atrybuty['id'] ?? (string) Str::uuid();

        DB::table('comments')->insert($atrybuty + [
            'id' => $id,
            'author_id' => $this->autor->id,
            'body' => 'Komentarz testowy.',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function odrzucone(callable $zapis): void
    {
        try {
            DB::transaction($zapis);
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertStringContainsString('comments_odpowiedz_zgodna_z_rodzicem', $e->getMessage());

            return;
        }

        $this->fail('Baza przyjęła odpowiedź niezgodną z rodzicem.');
    }

    private function wyzwalaczIstnieje(): bool
    {
        return DB::table('pg_trigger')
            ->where('tgname', self::WYZWALACZ)
            ->whereRaw("tgrelid = 'comments'::regclass")
            ->exists();
    }
}
