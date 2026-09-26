<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #1975: zainstalowana aplikacja nie może zaczynać się od ekranu logowania.
 *
 * Manifest (`public/manifest.webmanifest`) jest podlinkowany w każdym
 * układzie, także dla gościa, więc aplikację da się zainstalować przed
 * rejestracją. Do 26 września 2026 `start_url` wskazywał `/home`, który stoi
 * w grupie `auth` — gość po instalacji przy każdym uruchomieniu lądował na
 * logowaniu i aplikacja wyglądała na zepsutą przy pierwszym kontakcie.
 *
 * Teraz `start_url` to `/`: gość dostaje stronę powitalną z drogą do
 * rejestracji i logowania, a zalogowany — swój Start (`FeedController::landing`
 * oddaje zalogowanemu ten sam ekran co `/home`).
 *
 * Test czyta `start_url` Z PLIKU, a nie przepisuje go z palca — powrót do
 * adresu za logowaniem oblewa niezależnie od tego, jaki to będzie adres.
 */
final class ManifestStartujeDlaGosciaIZalogowanegoTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_uruchamiajacy_aplikacje_nie_trafia_na_logowanie(): void
    {
        $odpowiedz = $this->get($this->startUrl());

        $odpowiedz->assertOk();
        $odpowiedz->assertViewIs('pages.landing');
        // Droga dalej jest widoczna: rejestracja i logowanie.
        $odpowiedz->assertSee(route('register'), false);
        $odpowiedz->assertSee(route('login'), false);
    }

    public function test_zalogowany_uruchamiajacy_aplikacje_widzi_swoj_start(): void
    {
        $basia = $this->user('basia_pwa');

        $odpowiedz = $this->actingAs($basia)->get($this->startUrl());

        $odpowiedz->assertOk();
        $odpowiedz->assertViewIs('pages.home');
    }

    /** Kontrola ujemna trasy: `/home` naprawdę jest za logowaniem, więc nie nadaje się na start. */
    public function test_home_dla_goscia_prowadzi_do_logowania(): void
    {
        $this->get('/home')->assertRedirect(route('login'));
    }

    public function test_start_url_miesci_sie_w_zakresie_aplikacji(): void
    {
        $manifest = $this->manifest();

        $this->assertStringStartsWith($manifest['scope'], $this->startUrl());
    }

    private function startUrl(): string
    {
        $start = $this->manifest()['start_url'] ?? null;

        $this->assertIsString($start, 'Manifest nie ma „start_url”.');
        $this->assertStringStartsWith('/', $start, '„start_url” ma być ścieżką w obrębie serwisu.');

        return $start;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertIsArray($manifest, 'public/manifest.webmanifest nie jest poprawnym JSON-em.');

        return $manifest;
    }
}
