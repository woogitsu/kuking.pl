<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KomunikatUgotowalemMowiPrawdeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{bool, string, int}> */
    public static function autorzy(): array
    {
        return [
            'wlasny-przepis' => [true, 'active', 0],
            'inny-aktywny-autor' => [false, 'active', 1],
            'wymazany-autor' => [false, 'erased', 0],
            'zawieszony-autor-nadal-czyta' => [false, 'suspended', 1],
        ];
    }

    #[DataProvider('autorzy')]
    public function test_instrukcja_i_potwierdzenia_odpowiadaja_zapisowi_i_powiadomieniom(
        bool $wlasnyPrzepis,
        string $statusAutora,
        int $liczbaPowiadomien,
    ): void {
        $autor = $this->user('autor_komunikatu');
        $kucharz = $wlasnyPrzepis ? $autor : $this->user('kucharz_komunikatu');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => $wlasnyPrzepis ? 'private' : 'public',
            'published_at' => now()->subDay(),
        ]);

        if ($statusAutora === 'erased') {
            $autor->markDataErased();
        } elseif ($statusAutora === 'suspended') {
            $autor->suspend(now()->addDay());
        }
        $this->assertSame($statusAutora, $autor->fresh()->status);

        $formularz = $this->actingAs($kucharz)->get(route('cooked.create', $przepis->slug));
        $formularz->assertOk();
        if ($liczbaPowiadomien === 0) {
            $formularz->assertSeeText('Zapisz wykonanie tego przepisu.')
                ->assertDontSeeText('dowie się, że ktoś ugotował z tego przepisu.');
        } else {
            $formularz->assertSeeText('dowie się, że ktoś ugotował z tego przepisu.')
                ->assertDontSeeText('Zapisz wykonanie tego przepisu.');
        }

        $this->assertSame(1, preg_match('/name="klucz_wyslania" value="([^"]+)"/', $formularz->getContent(), $klucz));
        $dane = ['note' => 'Ugotowane przy odbiorze komunikatu.', 'klucz_wyslania' => $klucz[1]];
        $pierwsze = $this->post(route('cooked.store', $przepis->slug), $dane);
        $pierwsze->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Wykonanie zapisane.');
        $this->assertSame(1, CookedEvent::query()->where('recipe_id', $przepis->getKey())->count());
        $this->assertSame($liczbaPowiadomien, Notification::query()
            ->where('user_id', $autor->getKey())->where('type', Notification::TYPE_COOKED)->count());

        $powtorzenie = $this->post(route('cooked.store', $przepis->slug), $dane);
        $powtorzenie->assertRedirect($pierwsze->headers->get('Location'))
            ->assertSessionHasNoErrors()->assertSessionHas('status',
                'To wykonanie już zapisaliśmy. '
                .'Gotujesz ten przepis drugi raz? Otwórz „Ugotowałem” jeszcze raz — każde wykonanie zapisujemy osobno.',
            );
        $this->assertSame(1, CookedEvent::query()->where('recipe_id', $przepis->getKey())->count());
        $this->assertSame($liczbaPowiadomien, Notification::query()
            ->where('user_id', $autor->getKey())->where('type', Notification::TYPE_COOKED)->count());
    }
}
