<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zdjęcie nigdy nie zostaje w `processing` na zawsze (issue #112).
 *
 * DLACZEGO SAM `catch` W `handle()` NIE WYSTARCZA
 * `$timeout = 120` w `ProcessUploadedImage` nie działa przez wyjątek PHP:
 * worker przerywa proces sygnałem w środku wykonania. Blok `catch` się wtedy
 * po prostu NIE WYKONUJE, więc `Media` zostaje w stanie `processing`, a widok
 * zdjęcia mówi „odśwież stronę za chwilę” — obietnica, która nigdy się nie
 * spełni, bo nikt już tego zadania nie podejmie. W interfejsie nie ma żadnej
 * drogi, którą właściciel zdjęcia mógłby to naprawić sam: dopiero stan
 * `rejected` mówi mu „usuń wpis i dodaj ponownie”.
 *
 * Dekodowanie zdjęcia 45 Mpx i budowa trzech wariantów w GD to jest realnie
 * ten kawałek serwisu, który potrafi nie zmieścić się w limicie czasu.
 * Bliźniaczy `GenerateUserExport` ma ten hook od początku — ten job go nie miał.
 */
class ZdjecieNieUtkniePrzetwarzaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_wyczerpanie_prob_przenosi_zdjecie_z_processing_do_rejected(): void
    {
        $media = Media::factory()->create(['status' => Media::STATUS_PROCESSING]);

        (new ProcessUploadedImage($media->getKey()))
            ->failed(new \RuntimeException('Zabrakło pamięci przy dekodowaniu.'));

        $media->refresh();

        $this->assertSame(
            Media::STATUS_REJECTED,
            $media->status,
            'Zdjęcie zostało w `processing` — dla właściciela to znaczy „przygotowuje się” bez końca.',
        );

        $this->assertSame(
            'processing_failed_or_timeout',
            $media->metadata['failure_reason'] ?? null,
        );
    }

    public function test_timeout_bez_wyjatku_tez_konczy_stan_przejsciowy(): void
    {
        // Przy przekroczeniu limitu czasu Laravel woła `failed(null)` —
        // wyjątku nie ma, bo proces został przerwany, nie zgłosił błędu.
        // To jest DOKŁADNIE ten przypadek, którego `catch` w `handle()`
        // nie łapie i dla którego ten hook powstał.
        $media = Media::factory()->create(['status' => Media::STATUS_PROCESSING]);

        (new ProcessUploadedImage($media->getKey()))->failed(null);

        $media->refresh();

        $this->assertSame(Media::STATUS_REJECTED, $media->status);
        $this->assertSame('processing_timeout', $media->metadata['failure_reason'] ?? null);
    }

    public function test_gotowe_zdjecie_nie_jest_cofane_przez_spozniona_probe(): void
    {
        // Kolejka potrafi zawołać `failed()` po próbie, która mimo wszystko
        // się udała. Cofnięcie `ready` do `rejected` skasowałoby z widoków
        // zdjęcie, które jest w porządku — czyli naprawa gorsza od błędu.
        $media = Media::factory()->create([
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => ['feed' => 'media/x-feed.webp']],
        ]);

        (new ProcessUploadedImage($media->getKey()))
            ->failed(new \RuntimeException('Spóźniona próba.'));

        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status);
        $this->assertArrayNotHasKey('failure_reason', $media->metadata ?? []);
        $this->assertSame(['feed' => 'media/x-feed.webp'], $media->metadata['variants'] ?? null);
    }

    public function test_brak_rekordu_nie_wywraca_hooka(): void
    {
        // Zdjęcie mogło zostać skasowane razem z wpisem, zanim kolejka
        // doszła do `failed()`. To nie jest błąd — hook ma po prostu wyjść.
        (new ProcessUploadedImage((string) Str::uuid()))->failed(null);

        $this->assertTrue(true);
    }
}
