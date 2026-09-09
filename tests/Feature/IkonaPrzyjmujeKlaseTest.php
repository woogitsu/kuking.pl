<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Klasa podana przy wywołaniu `<x-ikona>` musi trafić do HTML-u.
 *
 * CO BYŁO ŹLE
 * Komponent miał w znaczniku `class="ikona"` NA SZTYWNO i obok wypisywał
 * `{{ $attributes }}`. Przy `<x-ikona nazwa="home" class="bottom-nav-icon" />`
 * wychodziły z tego DWA atrybuty `class` w jednym `<svg>`, a wtedy
 * przeglądarka honoruje pierwszy i po cichu pomija drugi. Skutek: klasa
 * podana przy wywołaniu nie stosowała się NIGDY.
 *
 * Siedem wywołań w `layout.blade.php` podawało taką klasę, a w `app.css`
 * stały pod nie dwie reguły — `.bottom-nav-icon` i `.topbar-szukaj-ikona` —
 * które nie miały do czego się przyczepić. Wyglądało to, jakby coś robiły.
 * To ta sama klasa błędu co martwy limit `upload` i `MAIL_MAILER=log`
 * na produkcji: kod melduje sukces, nie robiąc nic.
 *
 * Poprawka to `$attributes->class(['ikona'])`, czyli scalenie klas w jeden
 * atrybut — a nie dwa atrybuty obok siebie.
 */
class IkonaPrzyjmujeKlaseTest extends TestCase
{
    private function ikona(string $blade): string
    {
        return trim(Blade::render($blade));
    }

    /**
     * Zawartość PIERWSZEGO atrybutu `class` — jedynego, który przeglądarka
     * czyta. Sprawdzanie samą obecnością napisu w kodzie strony nie mierzy
     * niczego: przy starej, zepsutej wersji komponentu klasa też „była
     * w HTML-u" — tylko w drugim, ignorowanym atrybucie.
     */
    private function pierwszaKlasa(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/\sclass="([^"]*)"/', $html, $dopasowanie),
            'W `<svg>` nie ma ani jednego atrybutu `class`.',
        );

        return $dopasowanie[1];
    }

    #[Test]
    public function klasa_z_wywolania_trafia_do_znacznika(): void
    {
        $klasy = $this->pierwszaKlasa($this->ikona('<x-ikona nazwa="home" class="bottom-nav-icon" />'));

        $this->assertStringContainsString(
            'bottom-nav-icon',
            $klasy,
            'Klasa podana przy `<x-ikona>` nie weszła do atrybutu, który przeglądarka czyta. '
            .'Komponent znowu wypisuje `class` osobno od `$attributes` — patrz nagłówek testu.',
        );

        $this->assertStringContainsString(
            'ikona',
            $klasy,
            'Zniknęła własna klasa komponentu — scalanie zjadło `ikona` zamiast ją dołożyć.',
        );
    }

    #[Test]
    public function w_znaczniku_jest_dokladnie_jeden_atrybut_class(): void
    {
        $html = $this->ikona('<x-ikona nazwa="search" class="topbar-szukaj-ikona" />');

        // To jest sedno usterki: nie „czy klasa jest w kodzie strony", ale
        // „czy nie jest w drugim, ignorowanym atrybucie".
        $this->assertSame(
            1,
            preg_match_all('/\sclass=/', $html),
            'W `<svg>` jest więcej niż jeden atrybut `class`. Przeglądarka weźmie pierwszy '
            .'i pominie resztę, więc jedna z tych klas nie zadziała — mimo że w kodzie strony '
            ."ją widać.\nHTML: ".$html,
        );
    }

    #[Test]
    public function bez_klasy_z_wywolania_zostaje_sama_klasa_komponentu(): void
    {
        $html = $this->ikona('<x-ikona nazwa="bell" />');

        $this->assertSame(1, preg_match_all('/\sclass=/', $html));
        $this->assertStringContainsString('class="ikona"', $html);
    }
}
