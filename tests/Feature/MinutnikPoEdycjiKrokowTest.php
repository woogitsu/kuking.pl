<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MinutnikPoEdycjiKrokowTest extends TestCase
{
    use RefreshDatabase;

    public function test_rzeczywista_edycja_przestawia_kroki_ale_zachowuje_ich_tozsamosc_i_odcisk(): void
    {
        $autor = $this->user('autor2589');
        $kucharz = $this->user('kucharz2589');
        $akcja = app(PublishRecipe::class);
        $przepis = $akcja->handle(
            author: $autor,
            attributes: ['title' => 'Zupa domowa'],
            steps: [
                ['instruction' => 'Pokrój warzywa.', 'timer_minutes' => 5],
                ['instruction' => 'Gotuj zupę.', 'timer_minutes' => 10],
            ],
            publish: true,
        );
        $kroki = $przepis->steps()->orderBy('position')->get();
        $a = $kroki[0];
        $b = $kroki[1];
        $staryOdcisk = $b->timerFingerprint();

        $przepis = $akcja->handle(
            author: $autor,
            attributes: ['title' => 'Zupa na obiad'],
            steps: [
                ['id' => $b->getKey(), 'instruction' => 'Gotuj zupę.', 'timer_minutes' => 10],
                ['id' => $a->getKey(), 'instruction' => 'Pokrój warzywa.', 'timer_minutes' => 5],
            ],
            publish: true,
            existing: $przepis,
        );

        $html = $this->actingAs($kucharz)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertOk()
            ->getContent();
        $this->assertIsString($html);
        preg_match('/data-alarmy-kroki="([^"]+)"/', $html, $atrybut);
        $this->assertArrayHasKey(1, $atrybut, 'Brak mapy tożsamości kroków dla minutnika.');
        $mapa = json_decode(html_entity_decode($atrybut[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame((string) $b->getKey(), $mapa[0]['id'], 'TIMER_2589_ZAMIANA_KROKOW');
        $this->assertSame($staryOdcisk, $mapa[0]['fingerprint']);
        $this->assertSame((string) $a->getKey(), $mapa[1]['id']);
        $this->assertStringContainsString('data-timer-step-id="'.$a->getKey().'"', $html);
        $this->assertStringNotContainsString('data-timer-step-id="'.$b->getKey().'"', $html);

        $przepis = $akcja->handle(
            author: $autor,
            attributes: ['title' => 'Zupa na obiad'],
            steps: [
                ['id' => $b->getKey(), 'instruction' => 'Gotuj zupę jeszcze chwilę.', 'timer_minutes' => 20],
                ['id' => $a->getKey(), 'instruction' => 'Pokrój warzywa.', 'timer_minutes' => 5],
            ],
            publish: true,
            existing: $przepis,
        );

        $this->assertNotSame($staryOdcisk, $b->fresh()->timerFingerprint(),
            'Zmiana instrukcji i czasu ma odłączyć wcześniejszy minutnik od obecnej czynności.');

        $przepis = $akcja->handle(
            author: $autor,
            attributes: ['title' => 'Zupa na obiad'],
            steps: [['id' => $a->getKey(), 'instruction' => 'Pokrój warzywa.', 'timer_minutes' => 5]],
            publish: true,
            existing: $przepis,
        );
        $poUsunieciu = $this->actingAs($kucharz)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))
            ->assertOk()
            ->getContent();
        $this->assertIsString($poUsunieciu);
        $this->assertStringNotContainsString((string) $b->getKey(), $poUsunieciu,
            'Usunięty krok nie może pozostać aktualnym celem dawnego minutnika.');
    }
}
