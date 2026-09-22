<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\TagFollowWindow;
use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pomiar dla zgłoszenia #858:
 * Odnajdywanie konkretnego tematu na długiej liście „Twoje tagi”.
 *
 * CO SIĘ ZMIENIŁO OD PIERWSZEJ WERSJI TEGO PLIKU
 * Pierwsza wersja mierzyła stan sprzed poprawki i sprawdzała, że dla 100
 * tagów widok rysuje 100 pól wyboru. Po wprowadzeniu okna listy (porcja
 * 20 pozycji, reszta przyciskiem) to zdanie przestało być prawdziwe —
 * i o to chodziło. Test mierzy więc teraz DWA stany naraz:
 *
 * - pierwsze otwarcie (jedna porcja) — to widzi człowiek, który wszedł;
 * - listę otwartą do końca (`?ile=`) — to jest górna granica, czyli stan
 *   równoważny temu, co ekran rysował przed poprawką.
 *
 * Różnica między nimi jest treścią pomiaru. Liczby wypisane na końcu są
 * rozmiarem HTML-u, nie wysokością ekranu: wysokość mierzy się
 * w przeglądarce, a nie w PHPUnit, i stoi w raporcie
 * `docs/research/TAGI_FILTR_2026-09-20.md`.
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
            $pelny = $this->actingAs($user)->get(route('settings.tags', ['ile' => $liczbaTagow]))->getContent();

            $wyniki[$liczbaTagow] = [
                'tagi' => $liczbaTagow,
                'html_kb' => round(strlen($html) / 1024, 1),
                'html_kb_pelna' => round(strlen($pelny) / 1024, 1),
                'checkboxy' => $this->polaWyboru($html),
                'checkboxy_pelna' => $this->polaWyboru($pelny),
                'czas_ms' => round($czasMs, 2),
            ];

            // Czyszczenie bazy przed kolejnym krokiem
            $user->followedTags()->detach();
            Tag::query()->delete();
        }

        // Pierwsze otwarcie NIGDY nie rysuje więcej niż jednej porcji —
        // to jest cała poprawka #858 wyrażona jedną liczbą.
        $this->assertSame(10, $wyniki[10]['checkboxy']);
        $this->assertSame(TagFollowWindow::PORCJA, $wyniki[40]['checkboxy']);
        $this->assertSame(TagFollowWindow::PORCJA, $wyniki[100]['checkboxy']);

        // …a otwarta do końca lista nadal pokazuje wszystko, co było.
        // Bez tej pary asercji „krótko” dałoby się spełnić gubieniem tagów.
        $this->assertSame(10, $wyniki[10]['checkboxy_pelna']);
        $this->assertSame(40, $wyniki[40]['checkboxy_pelna']);
        $this->assertSame(100, $wyniki[100]['checkboxy_pelna']);

        fwrite(STDOUT, "\n=== WYNIKI POMIARU #858 (Twoje tagi) ===\n");
        foreach ($wyniki as $w) {
            fwrite(STDOUT, sprintf(
                "Tagów: %3d | pierwsze otwarcie: %5.1f KB / %3d pól | lista otwarta do końca: %5.1f KB / %3d pól | czas: %6.2f ms\n",
                $w['tagi'],
                $w['html_kb'],
                $w['checkboxy'],
                $w['html_kb_pelna'],
                $w['checkboxy_pelna'],
                $w['czas_ms'],
            ));
        }
        fwrite(STDOUT, "========================================\n\n");
    }

    /** Pola WYBORU, nie pola ukryte niosące wybór spoza widoku. */
    private function polaWyboru(string $html): int
    {
        return preg_match_all('/<input type="checkbox" name="tags\[\]"/', $html);
    }
}
