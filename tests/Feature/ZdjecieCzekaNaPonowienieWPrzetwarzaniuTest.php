<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Zdjęcie między próbami przetwarzania nie jest „odrzucone" (issue #1349).
 *
 * `ProcessUploadedImage` ma `$tries = 3`, ale `catch` w `handle()` po KAŻDYM
 * wyjątku od razu zapisywał `rejected`. Widok traktuje ten stan jako porażkę
 * ostateczną: „Nie udało się przygotować tego zdjęcia" i rada, żeby usunąć
 * wpis i dodać go ponownie. Chwilę później kolejka ponawiała zadanie, zdjęcie
 * przechodziło do `ready` — a osoba, która posłuchała rady, zdążyła już
 * skasować własny wpis.
 *
 * Mierzone na PRAWDZIWEJ kolejce bazodanowej i prawdziwym workerze
 * (`runNextJob`), jak w `EksportCzekaNaPonowienieJakoAktywnyTest` — przy
 * `sync` albo `Queue::fake()` nie ma ponowienia, czyli nie ma stanu,
 * o który chodzi.
 */
class ZdjecieCzekaNaPonowienieWPrzetwarzaniuTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config(['queue.default' => 'database']);
    }

    /** Jeden obrót workera na kolejce zdjęć — tak jak `queue:work --queue=media`. */
    private function workerBierzeZadanie(): void
    {
        app('queue.worker')->runNextJob('database', 'media', new WorkerOptions(sleep: 0));
    }

    private function zadaniaZdjec(): int
    {
        return DB::table('jobs')->where('queue', 'media')->count();
    }

    /**
     * Zdjęcie bez żadnego wariantu i BEZ pliku źródłowego w magazynie —
     * pierwsza próba pada („Brak pliku źródłowego"), czyli przejściowa
     * awaria magazynu, którą naprawia dołożenie pliku.
     *
     * @return array{0: User, 1: Media, 2: Post}
     */
    private function wpisZeZdjeciemBezPliku(): array
    {
        $basia = $this->user('basia');

        $zdjecie = Media::factory()->pending()->create(['owner_id' => $basia->getKey()]);

        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$zdjecie->getKey()], visibility: 'public');

        ProcessUploadedImage::dispatch($zdjecie->getKey());

        $this->assertSame(1, $this->zadaniaZdjec(), 'Zadanie nie weszło do kolejki — test nie zmierzy ponowienia.');

        return [$basia, $zdjecie, $post];
    }

    private function naprawMagazyn(Media $zdjecie): void
    {
        Storage::disk('public')->put(
            $zdjecie->object_key,
            (string) UploadedFile::fake()->image('obiad.jpg', 800, 600)->getContent(),
        );
    }

    private function ekranWpisu(User $kto, Post $post): string
    {
        return $this->trescEkranu(
            $this->actingAs($kto)->get(route('posts.show', $post))->assertOk()->getContent(),
        );
    }

    public function test_nieudana_pierwsza_proba_zostawia_zdjecie_w_przetwarzaniu(): void
    {
        [$basia, $zdjecie, $post] = $this->wpisZeZdjeciemBezPliku();

        $this->workerBierzeZadanie();

        // Próba naprawdę padła i naprawdę czeka na ponowienie — bez tego
        // asercje niżej nie mierzą stanu „między próbami".
        $zadanie = DB::table('jobs')->where('queue', 'media')->first();
        $this->assertNotNull($zadanie, 'Kolejka nie zaplanowała ponowienia.');
        $this->assertSame(1, (int) $zadanie->attempts);

        $zdjecie->refresh();

        // SEDNO #1349: przed poprawką stało tu `rejected`.
        $this->assertSame(Media::STATUS_PROCESSING, $zdjecie->status);
        $this->assertArrayNotHasKey('failure_reason', $zdjecie->metadata ?? []);

        $ekran = $this->ekranWpisu($basia, $post);

        $this->assertStringContainsString('Twoje zdjęcie się jeszcze przygotowuje.', $ekran);
        $this->assertStringNotContainsString('Nie udało się przygotować', $ekran);
        $this->assertStringNotContainsString('usunąć i dodać ponownie', $ekran);
    }

    /** KONTROLA DODATNIA: udane ponowienie kończy to samo zdjęcie jako gotowe. */
    public function test_udane_ponowienie_daje_gotowe_zdjecie_bez_komunikatu_o_porazce(): void
    {
        [$basia, $zdjecie, $post] = $this->wpisZeZdjeciemBezPliku();

        $this->workerBierzeZadanie();
        $this->naprawMagazyn($zdjecie);
        $this->workerBierzeZadanie();

        $zdjecie->refresh();

        $this->assertSame(Media::STATUS_READY, $zdjecie->status);
        $this->assertTrue($zdjecie->maWariant('feed'));
        $this->assertArrayNotHasKey('failure_reason', $zdjecie->metadata ?? []);
        $this->assertSame(0, $this->zadaniaZdjec());

        $ekran = $this->ekranWpisu($basia, $post);

        $this->assertStringNotContainsString('Nie udało się przygotować', $ekran);
        $this->assertStringNotContainsString('przygotowuje', $ekran);
    }

    /** Po wyczerpaniu prób: `rejected` i rada dla właściciela — dopiero teraz. */
    public function test_wyczerpanie_prob_daje_rejected_i_rade_dla_wlasciciela(): void
    {
        [$basia, $zdjecie, $post] = $this->wpisZeZdjeciemBezPliku();

        $this->workerBierzeZadanie();
        $this->assertSame(Media::STATUS_PROCESSING, $zdjecie->refresh()->status);

        $this->workerBierzeZadanie();
        $this->assertSame(Media::STATUS_PROCESSING, $zdjecie->refresh()->status);

        $this->workerBierzeZadanie();

        $zdjecie->refresh();

        $this->assertSame(Media::STATUS_REJECTED, $zdjecie->status);
        // Ostatnią próbę domyka `failed()` workera — to jego kod przyczyny.
        $this->assertSame('processing_failed_or_timeout', $zdjecie->metadata['failure_reason'] ?? null);
        $this->assertSame(0, $this->zadaniaZdjec());

        $ekran = $this->ekranWpisu($basia, $post);

        $this->assertStringContainsString('Nie udało się przygotować tego zdjęcia.', $ekran);
        $this->assertStringContainsString('Wpis możesz usunąć i dodać ponownie z innym zdjęciem.', $ekran);
    }

    /**
     * Timeout po nieudanej próbie: w `handle()` nie ma wtedy wyjątku, więc
     * stan przejściowy domyka wyłącznie `failed(null)` — i nadal go domyka.
     */
    public function test_timeout_po_nieudanej_probie_konczy_sie_rejected(): void
    {
        [, $zdjecie] = $this->wpisZeZdjeciemBezPliku();

        $this->workerBierzeZadanie();
        $this->assertSame(Media::STATUS_PROCESSING, $zdjecie->refresh()->status);

        (new ProcessUploadedImage($zdjecie->getKey()))->failed(null);

        $zdjecie->refresh();

        $this->assertSame(Media::STATUS_REJECTED, $zdjecie->status);
        $this->assertSame('processing_timeout', $zdjecie->metadata['failure_reason'] ?? null);
    }

    /** Zdjęcie przejęte do kasowania między próbami nie wraca do przetwarzania. */
    public function test_ponowienie_nie_wskrzesza_zdjecia_przejetego_do_kasowania(): void
    {
        [, $zdjecie] = $this->wpisZeZdjeciemBezPliku();

        $this->workerBierzeZadanie();

        // Tak `KasujZdjecie` przejmuje wiersz (issue #1003).
        $zdjecie->refresh()->update(['status' => Media::STATUS_DELETED]);
        $this->naprawMagazyn($zdjecie);

        $this->workerBierzeZadanie();

        $this->assertSame(Media::STATUS_DELETED, $zdjecie->refresh()->status);
        $this->assertSame(0, $this->zadaniaZdjec());
    }
}
