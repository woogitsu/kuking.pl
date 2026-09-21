<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use App\Models\AuditLogEntry;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wgląd moderatora w zdjęcie, którego nie zobaczyłby bez uprawnienia
 * moderatorskiego, musi trafiać do dziennika audytu (decyzja właściciela
 * z 20.09.2026, audyt ADR / audyt bezpieczeństwa 2026-09-15).
 *
 * ZASADY:
 * 1. Uprawnienie moderatora zostaje — moderator ma widzieć każde zdjęcie,
 *    ale wgląd w zdjęcie prywatne (niebędące jego własnym) musi być rejestrowany.
 * 2. Otwarcie zdjęcia PUBLICZNEGO nie jest wglądem nadzwyczajnym — moderator
 *    ogląda to samo co każdy inny użytkownik, więc taki wgląd NIE trafia do audytu.
 * 3. Właściciel zdjęcia (nawet jeśli jest moderatorem) oglądający własne zdjęcie
 *    nie generuje wpisu w audycie.
 * 4. Dziennik audytu rejestruje FAKT (kto, które zdjęcie, kiedy), bez treści
 *    i bez danych pozwalających je odtworzyć.
 */
class DziennikWgladowModeratoraWZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_moderator_otwierajacy_prywatne_zdjecie_zostawia_wpis_w_dzienniku_audytu(): void
    {
        $autorka = $this->user('autorka_prywatnego');
        $moderator = $this->moderator();

        $zdjecie = $this->utworzZdjecie($autorka);
        $wpis = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $odpowiedz = $this->actingAs($moderator)->get($zdjecie->url('feed'));
        $odpowiedz->assertRedirect();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'admin.media_viewed',
            'actor_id' => $moderator->getKey(),
            'subject_type' => 'Media',
            'subject_id' => $zdjecie->getKey(),
        ]);

        $wpisAudytu = AuditLogEntry::query()
            ->where('action', 'admin.media_viewed')
            ->where('subject_id', $zdjecie->getKey())
            ->first();

        $this->assertNotNull($wpisAudytu);
        $this->assertSame($moderator->getKey(), $wpisAudytu->actor_id);
        $this->assertSame('Media', $wpisAudytu->subject_type);
        // Nie wolno zapisywać treści zdjęcia ani metadanych pozwalających je odtworzyć
        $this->assertEmpty($wpisAudytu->metadata ?? []);
    }

    public function test_moderator_otwierajacy_publiczne_zdjecie_nie_zostawia_wpisu_w_dzienniku_audytu(): void
    {
        $autorka = $this->user('autorka_publicznego');
        $moderator = $this->moderator();

        $zdjecie = $this->utworzZdjecie($autorka);
        $wpis = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $odpowiedz = $this->actingAs($moderator)->get($zdjecie->url('feed'));
        $odpowiedz->assertRedirect();

        $this->assertDatabaseMissing('audit_log', [
            'action' => 'admin.media_viewed',
        ]);
    }

    public function test_moderator_otwierajacy_wlasne_prywatne_zdjecie_nie_zostawia_wpisu_w_dzienniku_audytu(): void
    {
        $moderator = $this->moderator();

        $zdjecie = $this->utworzZdjecie($moderator);
        $wpis = Post::factory()->create([
            'author_id' => $moderator->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $odpowiedz = $this->actingAs($moderator)->get($zdjecie->url('feed'));
        $odpowiedz->assertRedirect();

        $this->assertDatabaseMissing('audit_log', [
            'action' => 'admin.media_viewed',
        ]);
    }

    public function test_zwykly_uzytkownik_nie_ma_dostepu_do_prywatnego_zdjecia_i_brak_wpisu_w_audycie(): void
    {
        $autorka = $this->user('autorka_prywatnego_2');
        $zwyklyUzytkownik = $this->user('zwykly_widz');

        $zdjecie = $this->utworzZdjecie($autorka);
        $wpis = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $odpowiedz = $this->actingAs($zwyklyUzytkownik)->get($zdjecie->url('feed'));
        $odpowiedz->assertNotFound();

        $this->assertDatabaseMissing('audit_log', [
            'action' => 'admin.media_viewed',
        ]);
    }

    public function test_bezposrednie_wywolanie_moze_dla_moderatora_i_prywatnego_zdjecia_zostawia_wpis(): void
    {
        $autorka = $this->user('autorka_przepisu');
        $moderator = $this->moderator();

        $zdjecie = $this->utworzZdjecie($autorka);
        Recipe::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => 'private',
            'hero_media_id' => $zdjecie->getKey(),
        ]);

        $dostep = app(DostepDoZdjecia::class);

        $this->assertTrue($dostep->moze($moderator, $zdjecie));

        $this->assertDatabaseHas('audit_log', [
            'action' => 'admin.media_viewed',
            'actor_id' => $moderator->getKey(),
            'subject_type' => 'Media',
            'subject_id' => $zdjecie->getKey(),
        ]);
    }

    public function test_bezposrednie_wywolanie_moze_dla_moderatora_i_publicznego_zdjecia_nie_zostawia_wpisu(): void
    {
        $autorka = $this->user('autorka_przepisu_pub');
        $moderator = $this->moderator();

        $zdjecie = $this->utworzZdjecie($autorka);
        Recipe::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => 'public',
            'hero_media_id' => $zdjecie->getKey(),
        ]);

        $dostep = app(DostepDoZdjecia::class);

        $this->assertTrue($dostep->moze($moderator, $zdjecie));

        $this->assertDatabaseMissing('audit_log', [
            'action' => 'admin.media_viewed',
        ]);
    }

    public function test_pomiar_liczby_zapytan_pojedynczego_wywolania_moze(): void
    {
        $autorka = $this->user('autorka_pomiar_single');
        $moderator = $this->moderator();

        $zdjeciePrywatne = $this->utworzZdjecie($autorka);
        $wpisPrywatny = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);
        $wpisPrywatny->media()->attach($zdjeciePrywatne->getKey(), ['position' => 0]);

        $zdjeciePubliczne = $this->utworzZdjecie($autorka);
        $wpisPubliczny = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $wpisPubliczny->media()->attach($zdjeciePubliczne->getKey(), ['position' => 0]);

        $dostep = app(DostepDoZdjecia::class);

        // 1. Pomiar dla pojedynczego zdjęcia publicznego
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue($dostep->moze($moderator, $zdjeciePubliczne));
        $zapytaniaPubliczne = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // 4 zapytania: 1 UNION rozpoznania tabel + 1 SELECT rodzica Post + 1 SELECT autor + 1 SELECT blokada
        $this->assertCount(4, $zapytaniaPubliczne);

        // 2. Pomiar dla pojedynczego zdjęcia prywatnego
        DB::enableQueryLog();
        $this->assertTrue($dostep->moze($moderator, $zdjeciePrywatne));
        $zapytaniaPrywatne = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // 5 zapytań: 1 UNION + 1 SELECT rodzica Post + 1 SELECT autor + 1 SELECT blokada + 1 INSERT do audit_log
        $this->assertCount(5, $zapytaniaPrywatne);
        $inserty = array_filter(
            $zapytaniaPrywatne,
            fn (array $q): bool => str_contains((string) $q['query'], 'insert into "audit_log"'),
        );
        $this->assertCount(1, $inserty);
    }

    public function test_pomiar_liczby_zapytan_w_petli_dwudziestu_zdjec_prywatnych(): void
    {
        $autorka = $this->user('autorka_pomiar_20_priv');
        $moderator = $this->moderator();
        $dostep = app(DostepDoZdjecia::class);

        $zdjecia = [];
        for ($i = 0; $i < 20; $i++) {
            $zdjecie = $this->utworzZdjecie($autorka);
            $wpis = Post::factory()->create([
                'author_id' => $autorka->getKey(),
                'visibility' => Post::VISIBILITY_PRIVATE,
            ]);
            $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
            $zdjecia[] = $zdjecie;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($zdjecia as $zdjecie) {
            $this->assertTrue($dostep->moze($moderator, $zdjecie));
        }

        $zapytania = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // 20 zdjęć prywatnych: każde to 1 UNION + 1 SELECT rodzica + 1 SELECT autor + 1 SELECT blokada + 1 INSERT = 5 zapytań.
        // Łącznie 100 zapytań. Przed zmianą: 0 zapytań (wlascicielLubModerator zwracało true natychmiast).
        $this->assertCount(100, $zapytania);

        $inserty = array_filter(
            $zapytania,
            fn (array $q): bool => str_contains((string) $q['query'], 'insert into "audit_log"'),
        );
        // Dokładnie 20 zapisów do dziennika audytu (jeden na każde prywatne zdjęcie)
        $this->assertCount(20, $inserty);
    }

    public function test_pomiar_liczby_zapytan_w_petli_dwudziestu_zdjec_publicznych(): void
    {
        $autorka = $this->user('autorka_pomiar_20_pub');
        $moderator = $this->moderator();
        $dostep = app(DostepDoZdjecia::class);

        $zdjecia = [];
        for ($i = 0; $i < 20; $i++) {
            $zdjecie = $this->utworzZdjecie($autorka);
            $wpis = Post::factory()->create([
                'author_id' => $autorka->getKey(),
                'visibility' => Post::VISIBILITY_PUBLIC,
            ]);
            $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
            $zdjecia[] = $zdjecie;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($zdjecia as $zdjecie) {
            $this->assertTrue($dostep->moze($moderator, $zdjecie));
        }

        $zapytania = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // 20 zdjęć publicznych: każde to 1 UNION + 1 SELECT rodzica + 1 SELECT autor + 1 SELECT blokada = 4 zapytania.
        // Łącznie 80 zapytań. Przed zmianą: 0 zapytań (wlascicielLubModerator zwracało true natychmiast).
        // Zero INSERT-ów do audit_log!
        $this->assertCount(80, $zapytania);

        $inserty = array_filter(
            $zapytania,
            fn (array $q): bool => str_contains((string) $q['query'], 'insert into "audit_log"'),
        );
        $this->assertCount(0, $inserty);
    }

    public function test_pomiar_liczby_zapytan_zadania_http_dla_moderatora(): void
    {
        $autorka = $this->user('autorka_pomiar_http');
        $moderator = $this->moderator();

        $zdjeciePrywatne = $this->utworzZdjecie($autorka);
        $wpisPrywatny = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);
        $wpisPrywatny->media()->attach($zdjeciePrywatne->getKey(), ['position' => 0]);

        $zdjeciePubliczne = $this->utworzZdjecie($autorka);
        $wpisPubliczny = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $wpisPubliczny->media()->attach($zdjeciePubliczne->getKey(), ['position' => 0]);

        $this->actingAs($moderator);

        // Rozgrzewka, aby pominąć jednorazowe koszty sesji/użytkownika
        $this->get($zdjeciePubliczne->url('feed'));

        // Pomiar HTTP dla zdjęcia publicznego
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($zdjeciePubliczne->url('feed'))->assertRedirect();
        $zapytaniaHttpPubliczne = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // Pomiar HTTP dla zdjęcia prywatnego
        DB::enableQueryLog();
        $this->get($zdjeciePrywatne->url('feed'))->assertRedirect();
        $zapytaniaHttpPrywatne = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // Sprawdzamy liczbę zapytań i obecność INSERT do audit_log
        $insertyPub = array_filter(
            $zapytaniaHttpPubliczne,
            fn (array $q): bool => str_contains((string) $q['query'], 'insert into "audit_log"'),
        );
        $insertyPriv = array_filter(
            $zapytaniaHttpPrywatne,
            fn (array $q): bool => str_contains((string) $q['query'], 'insert into "audit_log"'),
        );

        $this->assertCount(0, $insertyPub);
        $this->assertCount(1, $insertyPriv);

        // Różnica w liczbie zapytań HTTP między prywatnym a publicznym to dokładnie 1 zapytanie (INSERT do audit_log)
        $this->assertSame(
            count($zapytaniaHttpPubliczne) + 1,
            count($zapytaniaHttpPrywatne),
        );
        $this->assertSame(6, count($zapytaniaHttpPubliczne));
        $this->assertSame(7, count($zapytaniaHttpPrywatne));
    }

    private function utworzZdjecie(User $wlasciciel): Media
    {
        $identyfikator = Str::uuid()->toString();
        $warianty = [];

        foreach (config('kuking.media.variants') as $nazwa => $krawedz) {
            $klucz = 'media/'.$identyfikator.'_'.$nazwa.'.webp';
            $warianty[$nazwa] = ['key' => $klucz, 'width' => $krawedz, 'height' => $krawedz];
            Storage::disk('public')->put($klucz, 'udawane-bajty-'.$nazwa);
        }

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'incoming/'.$identyfikator.'.jpg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $warianty],
        ]);
    }
}
