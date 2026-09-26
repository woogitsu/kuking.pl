<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Policies\ReportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Zgłoszenie prawne z adresem profilu albo komentarza nie trafia do osoby,
 * której dotyczy (audyt B2-02).
 *
 * CO SIĘ DZIAŁO
 * Formularz DSA rozpoznawał tylko `/wpisy`, `/przepis(y)` i `/ugotowane`.
 * `https://kuking.pl/@moderator` dawało `target_type = unknown`, więc reguła
 * „sprawa o Ciebie” nie miała kogo porównać i ten sam moderator zamykał
 * sprawę jako „Bez działania”. Adres z `#komentarz-{uuid}` wskazywał wpis
 * nadrzędny, więc moderator rozstrzygał o własnym komentarzu pod cudzym wpisem.
 */
class ZgloszeniePrawneProfilIKomentarzTest extends TestCase
{
    use RefreshDatabase;

    private function zglos(string $adres): Report
    {
        $formularz = $this->get(route('zglos.nielegalna'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="klucz_wyslania"\s+value="([^"]+)"/', $formularz, $m));

        $this->post(route('zglos.nielegalna.store'), [
            'target_url' => $adres,
            'reason' => 'harassment',
            'illegality_explanation' => 'Ta treść znieważa mnie z powodu pochodzenia.',
            'good_faith' => '1',
            'klucz_wyslania' => $m[1],
        ])->assertSessionHasNoErrors();

        return Report::query()->where('source', Report::SOURCE_LEGAL_NOTICE)->latest('created_at')->firstOrFail();
    }

    private function mozeRozstrzygnac(User $kto, Report $report): bool
    {
        return Gate::forUser($kto)->inspect('decide', $report)->allowed();
    }

    public function test_adres_profilu_wskazuje_konto_i_jego_wlasciciel_nie_rozstrzyga(): void
    {
        $moderator = $this->moderator();
        $login = $moderator->profile->username;
        $inny = $this->moderator();

        $report = $this->zglos('https://kuking.pl/@'.$login.'/obserwujacy');

        $this->assertSame('user', $report->target_type);
        $this->assertSame((string) $moderator->getKey(), (string) $report->target_id);
        $this->assertFalse($this->mozeRozstrzygnac($moderator, $report), 'Moderator rozstrzyga zgłoszenie o sobie.');
        $this->assertTrue($this->mozeRozstrzygnac($inny, $report), 'Kontrola dodatnia: ktoś inny z moderacji musi móc rozstrzygnąć.');

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), ['action' => 'none', 'reason_code' => 'brak_naruszenia'])
            ->assertSessionHasErrors();
        $this->assertSame(Report::STATUS_OPEN, $report->refresh()->status);
    }

    public function test_adres_z_fragmentem_komentarza_wskazuje_komentarz(): void
    {
        $moderator = $this->moderator();
        $post = Post::factory()->create(['author_id' => $this->user()->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $moderator->getKey(), 'post_id' => $post->getKey()]);

        $report = $this->zglos(route('posts.show', $post).'#komentarz-'.$komentarz->getKey());

        $this->assertSame('comment', $report->target_type);
        $this->assertSame((string) $komentarz->getKey(), (string) $report->target_id);
        $this->assertFalse($this->mozeRozstrzygnac($moderator, $report));
        $this->assertTrue($this->mozeRozstrzygnac($this->moderator(), $report));
    }

    public function test_nieistniejacy_komentarz_we_fragmencie_zostawia_wpis(): void
    {
        $post = Post::factory()->create(['author_id' => $this->user()->getKey()]);

        $report = $this->zglos(route('posts.show', $post).'#komentarz-0190b3c2-0000-7000-8000-000000000000');

        $this->assertSame('post', $report->target_type);
        $this->assertSame((string) $post->getKey(), (string) $report->target_id);
    }

    public function test_stare_zgloszenie_bez_celu_z_adresem_profilu_tez_nie_trafia_do_wlasciciela(): void
    {
        // Zgłoszenia przyjęte przed tą zmianą leżą w bazie jako `unknown`.
        $moderator = $this->moderator();

        $report = Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_id' => null,
            'target_url' => 'https://kuking.pl/@'.$moderator->profile->username,
            'reason' => 'harassment',
            'illegality_explanation' => 'Znieważa mnie.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]);

        $odpowiedz = Gate::forUser($moderator)->inspect('decide', $report);
        $this->assertFalse($odpowiedz->allowed());
        $this->assertSame(ReportPolicy::SPRAWA_O_CIEBIE, $odpowiedz->message());
        $this->assertTrue($this->mozeRozstrzygnac($this->moderator(), $report));
    }

    public function test_stare_zgloszenie_wpisu_z_fragmentem_komentarza_moderatora(): void
    {
        $moderator = $this->moderator();
        $post = Post::factory()->create(['author_id' => $this->user()->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $moderator->getKey(), 'post_id' => $post->getKey()]);

        $report = Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'target_url' => route('posts.show', $post).'#komentarz-'.$komentarz->getKey(),
            'reason' => 'harassment',
            'illegality_explanation' => 'Znieważa mnie.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]);

        $this->assertFalse($this->mozeRozstrzygnac($moderator, $report));
        $this->assertTrue($this->mozeRozstrzygnac($this->moderator(), $report));
    }

    public function test_zwykle_adresy_dalej_rozpoznawane(): void
    {
        $post = Post::factory()->create(['author_id' => $this->user()->getKey()]);

        $report = $this->zglos(route('posts.show', $post));

        $this->assertSame('post', $report->target_type);
        $this->assertSame((string) $post->getKey(), (string) $report->target_id);
    }
}
