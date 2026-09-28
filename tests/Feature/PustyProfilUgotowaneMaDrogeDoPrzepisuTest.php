<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Puste „Ugotowane” podpowiada kolejny krok tylko właścicielowi (#2054). */
class PustyProfilUgotowaneMaDrogeDoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasciciel_pustej_zakladki_dostaje_link_do_szukania_przepisu(): void
    {
        $owner = $this->user('pusty_profil_ugotowane');
        $url = route('profile.show', $owner->profile->username).'?zakladka=ugotowane';

        $xpath = $this->dokument($this->actingAs($owner)->get($url)->assertOk()->getContent());
        $link = $this->linkPustegoStanu('Nie masz jeszcze żadnego wykonania');

        $this->assertSame(1, $xpath->query($link)->length);
        $this->assertSame(route('search'), $xpath->evaluate('string('.$link.'/@href)'));
        $this->assertSame('Znajdź przepis', trim($xpath->evaluate('string('.$link.')')));
    }

    public function test_cudzy_pusty_profil_nie_dostaje_akcji_przeznaczonej_dla_wlasciciela(): void
    {
        $owner = $this->user('pusty_profil_publiczny');
        $viewer = $this->user('czytelnik_profilu');
        $url = route('profile.show', $owner->profile->username).'?zakladka=ugotowane';

        $xpath = $this->dokument($this->actingAs($viewer)->get($url)->assertOk()->getContent());
        $state = $this->pustyStan('Brak wykonań');

        $this->assertSame(1, $xpath->query($state)->length);
        $this->assertSame(0, $xpath->query($state.'//a')->length);
    }

    private function dokument(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function linkPustegoStanu(string $title): string
    {
        return $this->pustyStan($title).'//a[contains(concat(" ", normalize-space(@class), " "), " btn-primary ")]';
    }

    private function pustyStan(string $title): string
    {
        return '//div[contains(concat(" ", normalize-space(@class), " "), " marka-profil-archiwum ")]'
            .'//div[contains(concat(" ", normalize-space(@class), " "), " empty-state ")]'
            .'[.//p[contains(concat(" ", normalize-space(@class), " "), " empty-state-title ") and normalize-space()="'.$title.'"]]';
    }
}
