<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pomiar dla zgłoszenia #858:
 * Odnajdywanie konkretnego tematu na długiej liście „Twoje tagi”.
 * Pomiar rozmiaru DOM, struktury siatki i czasów renderu dla 10, 40 i 100 tagów.
 */
class PomiarTagow858Test extends TestCase
{
    use RefreshDatabase;

    public function test_858_pomiar_rozmiaru_i_struktury_listy_tagow(): void
    {
        $user = $this->user('anna');

        $wyniki = [];

        foreach ([10, 40, 100] as $liczbaTagow) {
            $tags = collect();
            for ($i = 1; $i <= $liczbaTagow; $i++) {
                $t = Tag::create([
                    'slug' => sprintf('tag-%03d', $i),
                    'name' => sprintf('Temat Kulinarny %03d', $i),
                    'normalized_name' => sprintf('temat kulinarny %03d', $i),
                ]);
                TagPromotion::create([
                    'tag_id' => $t->getKey(),
                    'position' => $i,
                ]);
                $tags->push($t);
            }

            // 1/3 tagów dodatkowo obserwowane przez użytkownika
            $user->followedTags()->attach(
                $tags->take((int) ($liczbaTagow / 3))->pluck('id')->all(),
                ['created_at' => now()],
            );

            $start = microtime(true);
            $odpowiedz = $this->actingAs($user)->get(route('settings.tags'));
            $czasMs = (microtime(true) - $start) * 1000;

            $html = $odpowiedz->getContent();
            $rozmiarBajt = strlen($html);
            $liczbaCheckboxow = substr_count($html, 'type="checkbox"');
            $liczbaEtykiet = substr_count($html, 'class="choice');

            $wyniki[$liczbaTagow] = [
                'tagi' => $liczbaTagow,
                'html_kb' => round($rozmiarBajt / 1024, 1),
                'checkboxy' => $liczbaCheckboxow,
                'etykiety' => $liczbaEtykiet,
                'czas_ms' => round($czasMs, 2),
            ];

            // Czyszczenie bazy przed kolejnym krokiem
            Tag::query()->delete();
        }

        // Asercje bazowe sprawdzające spójność struktury DOM
        $this->assertCount(3, $wyniki);
        $this->assertSame(10, $wyniki[10]['checkboxy']);
        $this->assertSame(40, $wyniki[40]['checkboxy']);
        $this->assertSame(100, $wyniki[100]['checkboxy']);

        // Wypisanie wyników do raportu
        fwrite(STDOUT, "\n=== WYNIKI POMIARU #858 (Twoje tagi) ===\n");
        foreach ($wyniki as $n => $w) {
            fwrite(STDOUT, sprintf(
                "Liczba tagów: %3d | HTML: %5.1f KB | Checkboxy: %3d | Czas odpowiedzi: %6.2f ms\n",
                $w['tagi'],
                $w['html_kb'],
                $w['checkboxy'],
                $w['czas_ms'],
            ));
        }
        fwrite(STDOUT, "========================================\n\n");
    }
}
