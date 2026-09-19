<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EksportNieObiecujeTerminuTest extends TestCase
{
    public function test_render_uzaleznia_nowa_paczke_od_zakonczenia_zdjec(): void
    {
        DB::shouldReceive('connection')->never();
        foreach (['exports.index', 'exports.readme'] as $template) {
            foreach ([0, 2] as $pending) {
                $html = view($template, [
                    'generatedAt' => now(), 'displayName' => 'Próba eksportu',
                    'recipes' => [], 'postCount' => 0, 'cookedCount' => 0,
                    'photoCount' => 0, 'photosStillProcessing' => $pending,
                    'recipeCount' => 0, 'contactEmail' => 'test@example.test',
                    // Zeszyt bez cudzych przepisów — zdanie o ich ograniczeniu
                    // ma wtedy nie wychodzić. Obie gałęzie tego warunku mierzy
                    // `EksportWygladObietnicePaczkiTest` na prawdziwej paczce;
                    // tu chodzi wyłącznie o to, żeby render miał komplet danych.
                    'savedOtherRecipeCount' => 0,
                ])->render();
                $this->assertStringNotContainsString('za kilka minut', $html);
                $this->assertStringNotContainsString('będzie w niej komplet', $html);
                if ($pending > 0) {
                    $this->assertStringContainsString('Poproś o nową paczkę, gdy przygotowywanie zdjęć się zakończy', $html);
                    $this->assertStringContainsString('sprawdź, czy zawiera wszystkie Twoje zdjęcia', $html);
                } else {
                    $this->assertStringNotContainsString('Poproś o nową paczkę', $html);
                }
                if ($template === 'exports.index') {
                    $this->assertStringContainsString('@media screen and (prefers-color-scheme: dark)', $html);
                    $this->assertStringNotContainsString('@media (prefers-color-scheme: dark)', $html);
                }
            }
        }
    }
}
