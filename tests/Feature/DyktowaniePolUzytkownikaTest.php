<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/** #2377: dyktowanie jest jawnym dodatkiem tylko do edytowalnego pola osoby zalogowanej. */
final class DyktowaniePolUzytkownikaTest extends TestCase
{
    use RefreshDatabase;

    public function test_zalogowana_osoba_dostaje_pusty_host_przy_wlasciwym_polu_takze_w_petli(): void
    {
        $this->actingAs($this->user());
        view()->share('errors', new ViewErrorBag);

        $html = Blade::render(<<<'BLADE'
            <x-field name="body" :wiersz="'popraw-a'" label="Popraw komentarz" type="textarea" dyktowanie />
            <x-field name="body" :wiersz="'popraw-b'" label="Popraw komentarz" type="textarea" dyktowanie />
            BLADE);

        foreach (['f-body-popraw-a', 'f-body-popraw-b'] as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'));
            $this->assertSame(1, substr_count($html, 'data-cel="'.$id.'"'));
        }
        $this->assertSame(2, substr_count($html, 'data-wstaw-napis="Wstaw do pola"'));
        $this->assertStringContainsString('data-separator="spacja"', $html);
        $this->assertStringNotContainsString('<button', $html, 'Bez obsługi API nie ma martwego przycisku.');
    }

    public function test_kontrola_ujemna_sam_typ_pola_nie_wlacza_mikrofonu(): void
    {
        $this->actingAs($this->user());
        view()->share('errors', new ViewErrorBag);

        $html = Blade::render(<<<'BLADE'
            <x-field name="opis" label="Opis" type="textarea" />
            <x-field name="tytul" label="Tytuł" dyktowanie />
            <x-field name="dowod" label="Dowód" type="textarea" dyktowanie readonly />
            <x-field name="zablokowane" label="Zablokowane" type="textarea" dyktowanie disabled />
            BLADE);

        $this->assertStringNotContainsString('data-dyktowanie', $html);
        $this->assertStringContainsString('id="f-dowod"', $html);
        $this->assertStringContainsString('readonly', $html);
        $this->assertStringContainsString('disabled', $html);

        // Ta sama implementacja z jawnie włączoną edytowalną textarea MUSI
        // dać host; inaczej powyższa kontrola ujemna byłaby pusta.
        $wlasne = Blade::render('<x-field name="opis" label="Opis" type="textarea" dyktowanie />');
        $this->assertStringContainsString('data-cel="f-opis"', $wlasne);
    }

    public function test_gosc_nie_dostaje_hosta_nawet_z_jawnym_propem(): void
    {
        view()->share('errors', new ViewErrorBag);
        $html = Blade::render('<x-field name="body" label="Komentarz" type="textarea" dyktowanie />');

        $this->assertStringContainsString('id="f-body"', $html);
        $this->assertStringNotContainsString('data-dyktowanie', $html);
    }
}
