<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Ikony nawigacji: SVG zamiast emoji (UI kit v2, etap B).
 *
 * DLACZEGO TO NIE JEST ZMIANA KOSMETYCZNA
 * Emoji wygląda INACZEJ na każdym systemie — 🏠 na Androidzie, na iPhonie
 * i w Windowsie to trzy różne obrazki. Część z nich jest wyraźnie zabawkowa,
 * a `docs/BRAND.md` mówi wprost, że „toy-like" jest odbierane przez naszą
 * grupę jako infantylizujące.
 *
 * Do tego emoji NIE PRZYJMUJE koloru z motywu (w trybie ciemnym zostaje
 * kolorową plamą) i skaluje się inaczej niż napis obok przy dużym tekście.
 *
 * CZEGO PILNUJE TEN PLIK
 * Nie tego, że ikona „jest ładna" — tego się nie sprawdzi asercją. Pilnuje
 * niezmienników, które łatwo złamać przy następnej zmianie w nawigacji:
 * ikona nigdy nie stoi sama, jest niema dla czytnika ekranu, a nieznana
 * nazwa nie renderuje się jako pusty kwadrat.
 */
class IkonyWNawigacjiTest extends TestCase
{
    use RefreshDatabase;

    private function nawigacja(): string
    {
        return $this->actingAs($this->user('basia'))
            ->get(route('home'))
            ->assertOk()
            ->getContent();
    }

    public function test_nawigacja_uzywa_ikon_svg(): void
    {
        $html = $this->nawigacja();

        $this->assertStringContainsString('<svg class="ikona"', $html);
    }

    public function test_w_nawigacji_nie_ma_juz_emoji(): void
    {
        $html = $this->nawigacja();

        // Konkretne znaki, których używała poprzednia wersja. Pytamy o nie
        // wprost, a nie o „jakiekolwiek emoji": treść od użytkowników może
        // zawierać emoji i ma prawo je zawierać — zakaz dotyczy INTERFEJSU.
        foreach (['🏠', '🔍', '➕', '📒', '👤', '🍲', '⚙️'] as $emoji) {
            $this->assertStringNotContainsString(
                $emoji,
                $html,
                "W nawigacji został emoji {$emoji} — na każdym systemie wygląda inaczej.",
            );
        }
    }

    public function test_ikona_jest_niema_dla_czytnika_ekranu(): void
    {
        $html = $this->nawigacja();

        // Ikona jest DODATKIEM do napisu. Gdyby miała własną etykietę,
        // czytnik ekranu czytałby każdą pozycję menu dwa razy.
        preg_match_all('~<svg class="ikona"[^>]*>~', $html, $ikony);

        $this->assertNotEmpty($ikony[0]);

        foreach ($ikony[0] as $ikona) {
            $this->assertStringContainsString('aria-hidden="true"', $ikona);
            // Bez `focusable="false"` starsze przeglądarki wpuszczają SVG
            // do kolejności Taba — i człowiek dostaje kilkanaście pustych
            // przystanków po drodze do treści.
            $this->assertStringContainsString('focusable="false"', $ikona);
        }
    }

    public function test_kazda_pozycja_menu_ma_podpis_tekstowy(): void
    {
        $html = $this->nawigacja();

        // AGENTS.md i docs/UX_50_PLUS.md: IKONA NIGDY NIE JEST SAMA.
        // Ten test jest tu po to, żeby przy następnym „uporządkowaniu"
        // nawigacji ktoś nie usunął podpisów, bo „ikony są czytelne".
        foreach (['Start', 'Szukaj', 'Dodaj', 'Zeszyt', 'Mój profil', 'Ustawienia'] as $podpis) {
            $this->assertStringContainsString($podpis, $html);
        }
    }

    public function test_ikony_dziedzicza_kolor_z_otoczenia(): void
    {
        $html = $this->nawigacja();

        // `currentColor` to cała różnica wobec emoji: bieżąca pozycja
        // dostaje kolor marki, reszta kolor tekstu, a w trybie ciemnym
        // wszystko przestawia się samo. Kolor wpisany na sztywno
        // wymagałby drugiego kompletu ikon.
        $this->assertStringContainsString('stroke="currentColor"', $html);
    }

    public function test_nieznana_nazwa_ikony_zostawia_slad(): void
    {
        // Cicha pustka jest najgorszym możliwym zachowaniem: zostaje sam
        // napis, wszystko wygląda „prawie dobrze" i nikt nie zauważa,
        // że ikony brakuje. Komentarz w kodzie strony daje szansę
        // na zauważenie przy pierwszym spojrzeniu w źródło.
        $html = Blade::render(
            '<x-ikona nazwa="nie-ma-takiej" />',
        );

        $this->assertStringContainsString('brak ikony o nazwie "nie-ma-takiej"', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }
}
