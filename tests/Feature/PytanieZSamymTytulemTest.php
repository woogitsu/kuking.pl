<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Jobs\GenerateUserExport;
use App\Jobs\PrzeanalizujTresc;
use App\Models\DataExport;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * PYTANIE BEZ OPISU: TYTUŁ JEST CAŁĄ TREŚCIĄ (#831, #832, #869).
 *
 * Pytanie w „Poradźcie" wymaga tytułu, a opis może zostać pusty. Kilka
 * miejsc czytało wyłącznie `body` — i dla takiego pytania widziało pustkę:
 * moderacja nie miała czego ocenić, eksport gubił wypowiedź autora,
 * a „Moje" nazywało je „Zdjęcie bez opisu". Każdy test niżej używa
 * pytania z tytułem i BEZ opisu, bo tylko ten kształt odsłania usterkę.
 */
class PytanieZSamymTytulemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.questions.enabled' => true]);
    }

    private function osoba(string $login): User
    {
        $user = $this->user($login);
        $user->forceFill(['created_at' => now()->subDays(400)])->save();

        return $user->refresh();
    }

    private function pytanie(User $autor, string $tytul, string $widocznosc = Post::VISIBILITY_PUBLIC): Post
    {
        return Post::factory()->question()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'body' => null,
            'visibility' => $widocznosc,
        ]);
    }

    private function analizuj(Post $wpis): void
    {
        dispatch_sync(new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, (string) $wpis->getKey()));
    }

    // ---------------------------------------------------------------
    // #831 — moderacja
    // ---------------------------------------------------------------

    public function test_lokalny_sygnal_czyta_tytul_pytania_bez_opisu(): void
    {
        $pytanie = $this->pytanie($this->osoba('pytajacy_spam'), 'Napiszcie na t.me/tanie_garnki po przepis?');

        $this->analizuj($pytanie);

        $oznaczenie = Report::query()->where('source', Report::SOURCE_AUTOMAT)->sole();
        $this->assertSame((string) $pytanie->getKey(), (string) $oznaczenie->target_id);
        $this->assertSame(WykrywaczSygnalow::KOD_WZORZEC, $oznaczenie->reason);
    }

    public function test_powtorzone_pytanie_bez_opisu_jest_sygnalem_powtorzenia(): void
    {
        $autor = $this->osoba('pytajacy_dwa_razy');
        $tytul = 'Dlaczego mój sernik zawsze pęka na środku w czasie pieczenia?';

        $this->pytanie($autor, $tytul);
        $drugie = $this->pytanie($autor, $tytul);

        $this->analizuj($drugie);

        $this->assertSame(
            WykrywaczSygnalow::KOD_POWTORZENIE,
            Report::query()->where('source', Report::SOURCE_AUTOMAT)->where('target_id', $drugie->getKey())->sole()->reason,
        );
    }

    public function test_model_dostaje_tytul_publicznego_pytania_bez_opisu(): void
    {
        Notification::fake();
        config([
            'kuking.moderation.model.klucz' => 'testowy-klucz',
            'kuking.moderation.model.alarm_email' => 'moderacja@example.test',
            'kuking.moderation.model.ocenia_zdjecia' => false,
        ]);
        Http::preventStrayRequests();
        Http::fake(['*api.openai.com*' => Http::response([
            'results' => [['flagged' => false, 'category_scores' => ['hate' => 0.91]]],
        ])]);

        $pytanie = $this->pytanie($this->osoba('pytajacy_model'), 'Znacznik-tytulu-831: jak upiec chleb na zakwasie?');

        $this->analizuj($pytanie);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $zadanie): bool => str_contains(
            json_encode($zadanie->data(), JSON_UNESCAPED_UNICODE),
            'Znacznik-tytulu-831',
        ));
        $this->assertSame(
            OcenaModelem::KOD,
            Report::query()->where('source', Report::SOURCE_AUTOMAT)->sole()->reason,
        );
    }

    public function test_tytul_prywatnego_pytania_nie_wychodzi_do_modelu(): void
    {
        config([
            'kuking.moderation.model.klucz' => 'testowy-klucz',
            'kuking.moderation.model.ocenia_zdjecia' => false,
        ]);
        Http::preventStrayRequests();
        Http::fake();

        $pytanie = $this->pytanie($this->osoba('pytajacy_prywatnie'), 'Prywatne pytanie o przepis babci na pierogi?', Post::VISIBILITY_FOLLOWERS);

        app(OcenaModelem::class)->dla($pytanie);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // #832 — eksport danych
    // ---------------------------------------------------------------

    public function test_eksport_zachowuje_tytul_pytania_bez_opisu_w_json_i_html(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local', 'kuking.exports.ttl_days' => 7]);

        $autor = $this->user('pytajacy_eksport');
        $this->pytanie($autor, 'Znacznik-eksportu-832: czym zastąpić drożdże?');

        $export = DataExport::create(['user_id' => $autor->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        (new GenerateUserExport((string) $export->getKey()))->handle();
        $export->refresh();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) $export->object_key)) === true);
        $json = json_decode((string) $zip->getFromName('dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $html = (string) $zip->getFromName('wpisy.html');
        $zip->close();

        $wpis = $json['wpisy'][0];
        $this->assertSame(Post::KIND_QUESTION, $wpis['rodzaj']);
        $this->assertSame('Znacznik-eksportu-832: czym zastąpić drożdże?', $wpis['tytul']);
        $this->assertNull($wpis['tresc']);
        $this->assertStringContainsString('Znacznik-eksportu-832: czym zastąpić drożdże?', $html);
    }

    // ---------------------------------------------------------------
    // #869 — „Moje", ostatnio odłożone
    // ---------------------------------------------------------------

    public function test_zapisane_pytanie_bez_opisu_nazywa_sie_swoim_tytulem(): void
    {
        $czytelnik = $this->user('zapisujacy_pytanie');
        $pytanie = $this->pytanie($this->user('pytajaca_moje'), 'Znacznik-moje-869: ile soli do kiszonek?');

        $this->actingAs($czytelnik)->post(route('collections.save-post', $pytanie))->assertRedirect();

        $this->actingAs($czytelnik)->get(route('collections.index'))
            ->assertOk()
            ->assertSee('Ostatnio odłożone')
            ->assertSee('<h3 class="m-0">Znacznik-moje-869: ile soli do kiszonek?</h3>', false)
            ->assertSee('aria-label="Zobacz: Znacznik-moje-869: ile soli do kiszonek?"', false)
            ->assertSee('Pytanie · ')
            ->assertDontSee('Zdjęcie bez opisu');
    }
}
