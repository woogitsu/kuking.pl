<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Test regresyjny audytu A31.
 *
 * Gdy ciało żądania przekracza `post_max_size` z `docker/php.ini`, PHP
 * odrzuca `$_POST` i `$_FILES` — RAZEM Z TOKENEM CSRF, bo token jest
 * częścią tego samego, odrzuconego ciała. Bez naprawy Laravel widzi
 * żądanie bez tokenu i odpowiada domyślną stroną błędu — nie stronem 419
 * (Laravel wykrywa TĘ SYTUACJĘ WCZEŚNIEJ, wbudowanym middleware
 * `ValidatePostSize`, i rzuca `PostTooLargeException`, zanim CSRF
 * w ogóle dostanie szansę), ale i tak ogólną, bezużyteczną stroną — nie
 * polskim komunikatem mówiącym, co zrobić.
 *
 * Test symuluje ten scenariusz jedynym sposobem, jaki jest do tego
 * dostępny w testach: ustawia `CONTENT_LENGTH` w `$server`, zamiast
 * naprawdę wysyłać dziesiątki megabajtów danych (to dokładnie odtwarza
 * warunek, na którym opiera się `ValidatePostSize` — porównanie
 * `Content-Length` z `post_max_size`, patrz `vendor/laravel/framework/
 * src/Illuminate/Http/Middleware/ValidatePostSize.php`).
 */
class PostTooLargeUploadTest extends TestCase
{
    public function test_za_duza_wysylka_dostaje_polski_komunikat_a_nie_ogolna_strone_bledu(): void
    {
        // `app.debug=false`: inaczej (w trybie debug) domyślna strona błędu
        // Laravela pokazuje podgląd kodu źródłowego z miejsca wyjątku —
        // co ZAWSZE "zawiera" szukany tekst, gdy ten sam tekst występuje
        // w tym pliku testowym, dając fałszywie zielony wynik niezależnie
        // od tego, czy naprawa naprawdę działa.
        config(['app.debug' => false]);

        $response = $this->call(
            'POST',
            route('posts.store'),
            server: ['CONTENT_LENGTH' => (string) (200 * 1024 * 1024)],
        );

        $response->assertStatus(413);
        $response->assertSee('za duże, żeby je wysłać', false);
        $response->assertSee('Wróć i spróbuj ponownie', false);
    }

    public function test_za_duza_wysylka_ktora_oczekuje_json_dostaje_json_nie_html(): void
    {
        config(['app.debug' => false]);

        $response = $this->call(
            'POST',
            route('posts.store'),
            server: [
                'CONTENT_LENGTH' => (string) (200 * 1024 * 1024),
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ],
        );

        $response->assertStatus(413);
        $response->assertHeader('Content-Type', 'application/json');
    }
}
