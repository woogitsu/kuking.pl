<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class InstrukcjeChroniaSrodowiskoTest extends TestCase
{
    public function test_polecenia_instalacji_nie_cofaja_cudzych_plikow_ani_globalnej_konfiguracji(): void
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/AGENTS.md');
        $this->assertDoesNotMatchRegularExpression('/git\s+(?:checkout|restore)[^\n]*composer\.(?:json|lock)/', $text);
        $this->assertDoesNotMatchRegularExpression('/composer\s+config\s+(?:-g|--global)\b/', $text);
        $this->assertStringContainsString('MD5 oraz mtime', $text);
    }

    public function test_skroty_zasady_ikon_odsylaja_do_jawnych_wyjatkow(): void
    {
        foreach (['CLAUDE.md', 'GEMINI.md', '.github/copilot-instructions.md'] as $file) {
            $text = file_get_contents(dirname(__DIR__, 2).'/'.$file);
            $this->assertStringContainsString('AGENTS.md', $text, $file);
            $this->assertStringNotContainsString('ikona nigdy sama', $text, $file);
            $this->assertStringContainsString('trzech kropek', $text, $file);
        }
    }

    public function test_ograniczenia_przegladarki_i_zoom_sa_opisane_we_wlasciwych_sekcjach(): void
    {
        $agents = file_get_contents(dirname(__DIR__, 2).'/AGENTS.md');
        $browser = explode('### Pull Request zawiera', explode('### Przeglądarka w środowisku agenta', $agents, 2)[1] ?? '', 2)[0];
        $this->assertStringContainsString('Najpierw sprawdź aktualny dostęp', $browser);
        $this->assertDoesNotMatchRegularExpression('/Chromium\s+\*\*nie przejdzie|Dotyczy to każdego hosta/', $browser);

        $constitution = file_get_contents(dirname(__DIR__, 2).'/docs/brand/KONSTYTUCJA_MARKI.md');
        $typography = explode('## Zakres przeniesienia', explode('### Typografia i czytelność', $constitution, 2)[1] ?? '', 2)[0];
        $typography = preg_replace('/\s+/u', ' ', $typography);
        $this->assertStringContainsString('Oddzielnie sprawdzamy rzeczywisty zoom przeglądarki', $typography);
        $this->assertStringContainsString('Zmiana samego fontu nie zastępuje zoomu.', $typography);
        $this->assertStringContainsString('tekstem aplikacji **140%**', $typography);
    }

    public function test_wskazniki_zachowuja_d053_i_izolacje_bazy(): void
    {
        foreach (['CLAUDE.md', 'GEMINI.md', '.cursor/rules/kuking.mdc', '.windsurfrules'] as $file) {
            $text = file_get_contents(dirname(__DIR__, 2).'/'.$file);
            $this->assertStringContainsString('AGENTS.md i D-053', $text, 'D053_ODSYLACZ '.$file);
            $this->assertStringContainsString('newralgiczne formularze mogą wymagać JS', $text, 'D053_FORMULARZE '.$file);
            $this->assertStringContainsString('nie zostawiaj martwych przycisków', $text, 'D053_PRZYCISKI '.$file);
            $this->assertStringNotContainsString('Ważne funkcje działają bez JavaScriptu', $text, 'D053_STARY_SKROT '.$file);
        }
        $cursor = file_get_contents(dirname(__DIR__, 2).'/.cursor/rules/kuking.mdc');
        $this->assertStringNotContainsString('createdb kuking_test', $cursor, 'IZOLACJA_BAZY');
        $this->assertStringContainsString('jawny host i port', $cursor, 'IZOLACJA_BAZY');
    }
}
