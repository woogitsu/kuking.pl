<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWersjePrzepisow;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Ukrywanie pojedynczej wersji przepisu (issue #2270, D-333).
 *
 * Dowód problemu na bazie (przed zmianą): autorka poprawia opis z numerem
 * telefonu babci, a gość pod `/przepisy/{slug}/historia/1` dalej dostaje 200
 * z tym numerem — i nie ma narzędzia, żeby tę jedną wersję schować.
 * `test_gosc_nie_widzi_ukrytej_wersji_a_autor_widzi_ja_z_oznaczeniem` jest
 * testem regresyjnym tego znaleziska (S-03).
 *
 * Kontrole dodatnie (PULAPKI_TESTOW.md §4): każde „nie widać" stoi obok
 * „widać" — przed ukryciem gość dostaje 200 i widzi numer telefonu, autor
 * po ukryciu nadal go widzi.
 *
 * @bez-kontroli-dodatniej Plik migracji jest ładowany wyłącznie po to, by wykonać up()/down() na PostgreSQL; każda asercja mierzy stan bazy albo odpowiedź HTTP, nie tekst źródła.
 */
final class UkrywanieWersjiPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFON = 'tel. 600 100 200';

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function wersja(Recipe $przepis, int $numer, string $opis): RecipeVersion
    {
        return RecipeVersion::create([
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $przepis->author_id,
            'version_number' => $numer,
            'change_note' => $numer === 1 ? 'Pierwsza publikacja' : 'Aktualizacja przepisu',
            'snapshot' => [
                'title' => $przepis->title,
                'summary' => $opis,
                'ingredients' => [],
                'steps' => [['position' => 0, 'instruction' => 'Krok wersji '.$numer, 'timer_seconds' => null]],
            ],
        ]);
    }

    /**
     * Przepis z wersjami 1..$ile. Wersja 1 ma w opisie numer telefonu,
     * każda późniejsza — już nie.
     */
    private function przepis(int $ile = 3, ?User $autor = null): Recipe
    {
        $przepis = Recipe::factory()->create($autor === null ? [] : ['author_id' => $autor->getKey()]);
        for ($n = 1; $n <= $ile; $n++) {
            $this->wersja($przepis, $n, $n === 1 ? 'Od babci Jadwigi, '.self::TELEFON.'.' : "Od babci, wersja {$n}.");
        }

        return $przepis;
    }

    private function wersjaNr(Recipe $przepis, int $numer): RecipeVersion
    {
        return RecipeVersion::where('recipe_id', $przepis->getKey())->where('version_number', $numer)->firstOrFail();
    }

    private function ukryjJako(User $kto, Recipe $przepis, int $numer): void
    {
        $this->actingAs($kto)
            ->post(route('recipes.history.hide.store', [$przepis->slug, $numer]))
            ->assertRedirect(route('recipes.history', $przepis->slug));
        auth()->logout();
    }

    public function test_gosc_nie_widzi_ukrytej_wersji_a_autor_widzi_ja_z_oznaczeniem(): void
    {
        $przepis = $this->przepis(3);
        $adresWersji = route('recipes.history.version', [$przepis->slug, 1]);

        // Kontrola dodatnia: przed ukryciem gość widzi stary numer telefonu.
        $this->get($adresWersji)->assertOk()->assertSee(self::TELEFON);

        $this->actingAs($przepis->author)
            ->get(route('recipes.history.hide', [$przepis->slug, 1]))
            ->assertOk()
            ->assertSee('Ukryć wersję 1?')
            ->assertSee('Tak, ukryj wersję 1')
            ->assertSee(route('recipes.history.hide.store', [$przepis->slug, 1]), false);

        $this->actingAs($przepis->author)
            ->post(route('recipes.history.hide.store', [$przepis->slug, 1]))
            ->assertRedirect(route('recipes.history', $przepis->slug))
            ->assertSessionHas('status', 'Wersja 1 jest ukryta. Widzisz ją tylko Ty i moderacja; w każdej chwili możesz ją przywrócić.');
        auth()->logout();

        $wersja = $this->wersjaNr($przepis, 1);
        $this->assertNotNull($wersja->hidden_at);
        $this->assertSame(RecipeVersion::UKRYL_AUTOR, $wersja->hidden_by_role);

        // Gość: adres wersji to 404 (jak wersja, której nie ma), lista jej nie pokazuje.
        $this->get($adresWersji)->assertNotFound();
        $this->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertDontSee($adresWersji, false)
            ->assertSee(route('recipes.history.version', [$przepis->slug, 2]), false);

        // Autor: dalej widzi, z oznaczeniem i przyciskiem przywrócenia.
        $this->actingAs($przepis->author)->get($adresWersji)
            ->assertOk()
            ->assertSee(self::TELEFON)
            ->assertSee('Ta wersja jest ukryta')
            ->assertSee('przez autora')
            ->assertSee(route('recipes.history.restore', [$przepis->slug, 1]), false);
        $this->actingAs($przepis->author)->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertSee($adresWersji, false)
            ->assertSee('Przywróć wersję 1');
    }

    public function test_ukrycie_i_przywrocenie_zostawiaja_wpis_w_dzienniku_bez_tresci(): void
    {
        $przepis = $this->przepis(3);
        $this->ukryjJako($przepis->author, $przepis, 1);

        $wpis = AuditLogEntry::where('action', 'recipe_version.hidden')->sole();
        $this->assertSame($przepis->author_id, $wpis->actor_id);
        $this->assertSame('RecipeVersion', $wpis->subject_type);
        $this->assertSame($this->wersjaNr($przepis, 1)->getKey(), $wpis->subject_id);
        // `assertEquals`: jsonb nie trzyma kolejności kluczy.
        $this->assertEquals(['recipe_id' => $przepis->getKey(), 'version_number' => 1, 'strona' => 'author'], $wpis->metadata);
        $this->assertNotNull($wpis->ip_hash);
        $this->assertStringNotContainsString('600 100 200', (string) json_encode($wpis->metadata));

        $this->actingAs($przepis->author)
            ->get(route('recipes.history.restore', [$przepis->slug, 1]))
            ->assertOk()
            ->assertSee('Tak, przywróć wersję 1');
        $this->actingAs($przepis->author)
            ->post(route('recipes.history.restore.store', [$przepis->slug, 1]))
            ->assertRedirect(route('recipes.history', $przepis->slug));
        auth()->logout();

        $this->assertNull($this->wersjaNr($przepis, 1)->hidden_at);
        $this->assertNull($this->wersjaNr($przepis, 1)->hidden_by_role);
        $przywrocenie = AuditLogEntry::where('action', 'recipe_version.restored')->sole();
        $this->assertSame('author', $przywrocenie->metadata['ukryl']);
        $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertOk()->assertSee(self::TELEFON);
    }

    public function test_obcy_nie_ukrywa_cudzej_wersji_i_nie_dowiaduje_sie_o_ukrytej(): void
    {
        $przepis = $this->przepis(3);
        $obcy = $this->user();

        $this->actingAs($obcy)->get(route('recipes.history.hide', [$przepis->slug, 1]))->assertForbidden();
        $this->actingAs($obcy)->post(route('recipes.history.hide.store', [$przepis->slug, 1]))->assertForbidden();
        $this->assertNull($this->wersjaNr($przepis, 1)->hidden_at);
        // Obcy nie widzi też przycisku.
        $this->actingAs($obcy)->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertDontSee(route('recipes.history.hide', [$przepis->slug, 1]), false);

        auth()->logout();
        $this->ukryjJako($przepis->author, $przepis, 1);

        // Pod numerem ukrytej wersji obcy dostaje 404, a nie 403 — odpowiedź
        // nie zdradza, że taka wersja istnieje.
        $this->actingAs($obcy)->get(route('recipes.history.version', [$przepis->slug, 1]))->assertNotFound();
        $this->actingAs($obcy)->get(route('recipes.history.restore', [$przepis->slug, 1]))->assertNotFound();
        $this->actingAs($obcy)->post(route('recipes.history.restore.store', [$przepis->slug, 1]))->assertNotFound();
        $this->assertNotNull($this->wersjaNr($przepis, 1)->hidden_at);
    }

    public function test_gosc_jest_odsylany_do_logowania_i_niczego_nie_ukrywa(): void
    {
        $przepis = $this->przepis(3);

        $this->get(route('recipes.history.hide', [$przepis->slug, 1]))->assertRedirect(route('login'));
        $this->post(route('recipes.history.hide.store', [$przepis->slug, 1]))->assertRedirect(route('login'));
        $this->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertDontSee(route('recipes.history.hide', [$przepis->slug, 1]), false);

        $this->assertNull($this->wersjaNr($przepis, 1)->hidden_at);
        $this->assertSame(0, AuditLogEntry::where('action', 'recipe_version.hidden')->count());
    }

    public function test_moderator_ukrywa_a_autor_widzi_oznaczenie_i_nie_przywraca_sam(): void
    {
        $przepis = $this->przepis(3);
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get(route('recipes.history.hide', [$przepis->slug, 1]))
            ->assertOk()
            ->assertSee('Ukrywasz ją jako moderacja');
        $this->ukryjJako($moderator, $przepis, 1);

        $wersja = $this->wersjaNr($przepis, 1);
        $this->assertSame(RecipeVersion::UKRYLA_MODERACJA, $wersja->hidden_by_role);
        $this->assertSame($moderator->getKey(), AuditLogEntry::where('action', 'recipe_version.hidden')->sole()->actor_id);

        $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertNotFound();

        $this->actingAs($przepis->author)->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertSee('Ukryta przez moderację')
            ->assertSee('Przywrócić ją może tylko moderacja')
            ->assertDontSee(route('recipes.history.restore', [$przepis->slug, 1]), false);
        $this->actingAs($przepis->author)->post(route('recipes.history.restore.store', [$przepis->slug, 1]))->assertForbidden();
        $this->assertNotNull($this->wersjaNr($przepis, 1)->hidden_at);

        // Moderacja przywraca swoje ukrycie.
        $this->actingAs($moderator)
            ->post(route('recipes.history.restore.store', [$przepis->slug, 1]))
            ->assertRedirect(route('recipes.history', $przepis->slug));
        $this->assertNull($this->wersjaNr($przepis, 1)->hidden_at);
    }

    public function test_moderator_nie_odslania_wersji_ukrytej_przez_autora_ale_ja_widzi_i_zostawia_slad(): void
    {
        $przepis = $this->przepis(3);
        $moderator = $this->moderator();
        $this->ukryjJako($przepis->author, $przepis, 1);

        $this->actingAs($moderator)->post(route('recipes.history.restore.store', [$przepis->slug, 1]))->assertForbidden();
        $this->assertNotNull($this->wersjaNr($przepis, 1)->hidden_at);

        $this->actingAs($moderator)->get(route('recipes.history.version', [$przepis->slug, 1]))
            ->assertOk()
            ->assertSee(self::TELEFON)
            ->assertSee('Ta wersja jest ukryta')
            ->assertDontSee(route('recipes.history.restore', [$przepis->slug, 1]), false);

        $wglad = AuditLogEntry::where('action', 'moderation.hidden_recipe_version_viewed')->sole();
        $this->assertSame($moderator->getKey(), $wglad->actor_id);
        $this->assertSame($this->wersjaNr($przepis, 1)->getKey(), $wglad->subject_id);

        // Autor oglądający własną ukrytą wersję śladu wglądu nie zostawia.
        $this->actingAs($przepis->author)->get(route('recipes.history.version', [$przepis->slug, 1]))->assertOk();
        $this->assertSame(1, AuditLogEntry::where('action', 'moderation.hidden_recipe_version_viewed')->count());
    }

    public function test_moderator_bez_2fa_widzi_ukryte_ale_nie_ukrywa(): void
    {
        $przepis = $this->przepis(3);
        $bez2fa = $this->user(null, ['role' => User::ROLE_MODERATOR]);

        $this->actingAs($bez2fa)->post(route('recipes.history.hide.store', [$przepis->slug, 1]))->assertForbidden();
        $this->assertNull($this->wersjaNr($przepis, 1)->hidden_at);

        auth()->logout();
        $this->ukryjJako($przepis->author, $przepis, 1);
        $this->actingAs($bez2fa)->get(route('recipes.history.version', [$przepis->slug, 1]))->assertOk();
    }

    public function test_porownanie_pomija_ukryta_wersje_i_mowi_to_jasno(): void
    {
        $przepis = $this->przepis(4);
        $this->ukryjJako($przepis->author, $przepis, 2);

        // Gość: poprzednik wersji 3 to wersja 1 (2 jest ukryta) i ekran o tym mówi.
        $this->get(route('recipes.history.changes', [$przepis->slug, 3]))
            ->assertOk()
            ->assertSee('Porównanie pomija jedną ukrytą wersję')
            ->assertSee('między wersją 1 a 3', false)
            ->assertSee('Krok wersji 1')
            ->assertSee('Krok wersji 3')
            ->assertDontSee('Krok wersji 2')
            ->assertDontSee(route('recipes.history.version', [$przepis->slug, 2]), false);
        // Lista gościa: przy wersji 3 porównanie „względem wersji 1".
        $this->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertSee('Co się zmieniło względem wersji 1');
        // Porównanie samej ukrytej wersji — 404.
        $this->get(route('recipes.history.changes', [$przepis->slug, 2]))->assertNotFound();

        // Autor porównuje po kolei, z ukrytą, i widzi o tym zdanie.
        $this->actingAs($przepis->author)->get(route('recipes.history.changes', [$przepis->slug, 3]))
            ->assertOk()
            ->assertSee('Krok wersji 2')
            ->assertSee('W tym porównaniu jest wersja ukryta')
            ->assertDontSee('Porównanie pomija');
    }

    public function test_porownanie_gdy_wszystkie_starsze_sa_ukryte(): void
    {
        $przepis = $this->przepis(3);
        $this->ukryjJako($przepis->author, $przepis, 1);
        $this->ukryjJako($przepis->author, $przepis, 2);

        $this->get(route('recipes.history.changes', [$przepis->slug, 3]))
            ->assertOk()
            ->assertSee('To najstarsza wersja, którą możesz zobaczyć')
            ->assertSee('Starsze wersje ukrył autor albo moderacja');

        // Kontrola: bez ukryć najstarsza wersja mówi zwykłe zdanie.
        $inny = $this->przepis(2);
        $this->get(route('recipes.history.changes', [$inny->slug, 1]))
            ->assertOk()
            ->assertSee('To najstarsza zapisana wersja');
    }

    public function test_najnowszej_wersji_nie_da_sie_ukryc(): void
    {
        $przepis = $this->przepis(3);

        $this->actingAs($przepis->author)
            ->post(route('recipes.history.hide.store', [$przepis->slug, 3]))
            ->assertRedirect(route('recipes.history', $przepis->slug))
            ->assertSessionHas('status', fn (string $s): bool => str_starts_with($s, 'Najnowszej wersji nie da się ukryć'));
        $this->assertNull($this->wersjaNr($przepis, 3)->hidden_at);
        $this->assertSame(0, AuditLogEntry::where('action', 'recipe_version.hidden')->count());

        $this->actingAs($przepis->author)
            ->get(route('recipes.history.hide', [$przepis->slug, 3]))
            ->assertRedirect(route('recipes.history', $przepis->slug));

        // Przy najnowszej nie ma przycisku, przy starszej jest.
        $this->actingAs($przepis->author)->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertDontSee(route('recipes.history.hide', [$przepis->slug, 3]), false)
            ->assertSee(route('recipes.history.hide', [$przepis->slug, 2]), false);

        // Moderacja też nie ukrywa najnowszej.
        $this->actingAs($this->moderator())->post(route('recipes.history.hide.store', [$przepis->slug, 3]));
        $this->assertNull($this->wersjaNr($przepis, 3)->hidden_at);
    }

    public function test_link_historii_pod_przepisem_liczy_tylko_wersje_widoczne_dla_widza(): void
    {
        $przepis = $this->przepis(2);
        $this->get(route('recipes.show', $przepis->slug))->assertOk()->assertSee(route('recipes.history', $przepis->slug), false);

        $this->ukryjJako($przepis->author, $przepis, 1);

        $this->get(route('recipes.show', $przepis->slug))->assertOk()->assertDontSee(route('recipes.history', $przepis->slug), false);
        $this->actingAs($przepis->author)->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee(route('recipes.history', $przepis->slug), false);
    }

    public function test_eksport_autora_niesie_ukryta_wersje_z_oznaczeniem(): void
    {
        $przepis = $this->przepis(3);
        $this->ukryjJako($przepis->author, $przepis, 1);
        $autor = $przepis->author->fresh();

        $paczka = app(CollectUserExportData::class)->handle($autor, new ExportPhotoPlan($autor), Carbon::now());
        $wersje = collect($paczka['wersje_przepisow'])->keyBy('numer_wersji');

        $this->assertSame('autor', $wersje[1]['ukryl']);
        $this->assertNotNull($wersje[1]['ukryto']);
        $this->assertStringContainsString('600 100 200', $wersje[1]['tresc_wersji']['summary']);
        $this->assertNull($wersje[2]['ukryl']);
        $this->assertNull($wersje[2]['ukryto']);
    }

    public function test_retencja_kasuje_takze_stara_ukryta_wersje(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        $przepis = $this->przepis(5);
        DB::table('recipe_versions')->where('recipe_id', $przepis->getKey())->where('version_number', '<=', 2)
            ->update(['created_at' => Carbon::parse('2020-01-10 10:00:00', 'UTC')]);
        $this->wersjaNr($przepis, 1)->ukryj(RecipeVersion::UKRYL_AUTOR);

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $this->assertSame(2, $wynik['skasowano']);
        $this->assertSame([5, 4, 3], RecipeVersion::where('recipe_id', $przepis->getKey())->orderByDesc('version_number')->pluck('version_number')->all());
    }

    public function test_tresci_ukrytej_wersji_dalej_nie_wolno_zmienic(): void
    {
        $przepis = $this->przepis(2);
        $wersja = $this->wersjaNr($przepis, 1);
        $wersja->ukryj(RecipeVersion::UKRYL_AUTOR);

        $this->expectException(LogicException::class);
        $wersja->fresh()->forceFill(['change_note' => 'podmiana', 'hidden_at' => null])->save();
    }

    public function test_pola_ukrycia_sa_poza_masowym_przypisaniem_i_pilnuje_ich_baza(): void
    {
        $this->assertNotContains('hidden_at', (new RecipeVersion)->getFillable());
        $this->assertNotContains('hidden_by_role', (new RecipeVersion)->getFillable());

        $wersja = $this->wersjaNr($this->przepis(2), 1);

        $this->expectException(QueryException::class);
        DB::table('recipe_versions')->where('id', $wersja->getKey())->update(['hidden_at' => now(), 'hidden_by_role' => null]);
    }

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_30_201700_add_hidden_at_to_recipe_versions.php');
    }

    public function test_rollback_odmawia_gdy_jest_ukryta_wersja(): void
    {
        $przepis = $this->przepis(2);
        $this->wersjaNr($przepis, 1)->ukryj(RecipeVersion::UKRYL_AUTOR);

        try {
            self::wykonajMigracje($this->migracja(), 'down');
            $this->fail('Rollback przeszedł mimo ukrytej wersji — zdjęcie kolumny pokazałoby ją publicznie.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cofnięcie odmówione (D-088)', $e->getMessage());
            $this->assertStringContainsString('1 wersji przepisu', $e->getMessage());
        }

        $this->assertNotNull($this->wersjaNr($przepis, 1)->hidden_at, 'Odmowa nie może niczego zmienić.');
    }

    public function test_rollback_przechodzi_bez_ukrytych_wersji_i_up_wraca(): void
    {
        $this->przepis(2);

        self::wykonajMigracje($this->migracja(), 'down');
        $this->assertSame(0, (int) DB::scalar("select count(*) from information_schema.columns where table_name = 'recipe_versions' and column_name in ('hidden_at','hidden_by_role')"));

        self::wykonajMigracje($this->migracja(), 'up');
        $this->assertSame(2, (int) DB::scalar("select count(*) from information_schema.columns where table_name = 'recipe_versions' and column_name in ('hidden_at','hidden_by_role')"));
        $this->assertSame(1, (int) DB::scalar("select count(*) from pg_constraint where conname = 'recipe_versions_hidden_spojny_check' and convalidated"));
    }
}
