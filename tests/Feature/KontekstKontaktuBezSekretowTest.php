<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contact\Actions\PrzyjmijWiadomosc;
use App\Domain\Contact\PageContext;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class KontekstKontaktuBezSekretowTest extends TestCase
{
    use RefreshDatabase;

    private function paths(): array
    {
        // Wyłącznie dane losowe testu, bez wypisywania ich w asercjach.
        $marker = bin2hex(random_bytes(24));

        return [
            ['/nowe-haslo/'.$marker, '/nowe-haslo'],
            ['/logowanie/link/'.$marker, '/logowanie/link'],
            ['/zaproszenie/'.$marker, '/zaproszenie'],
            ['/potwierdz-email/123/'.$marker, '/potwierdz-email'],
            ['/%6eowe-haslo/'.$marker, '/nowe-haslo'],
            // Podstawione ponad trasę — nie pasuje do żadnej, sekret niesie tak samo.
            ['/nowe-haslo/'.$marker.'/dalej', '/nowe-haslo'],
            ['/ustawienia/e-mail/potwierdz/'.$marker, '/ustawienia/e-mail/potwierdz'],
            ['/ustawienia/twoje-dane/pobierz/'.$marker, '/ustawienia/twoje-dane/pobierz'],
            ['/podsumowanie/wypisz/'.$marker, '/podsumowanie/wypisz'],
        ];
    }

    public function test_referer_i_stare_dane_daja_bezpieczne_pole(): void
    {
        foreach ($this->paths() as [$path, $expected]) {
            foreach ([false, true] as $old) {
                $this->withSession(['_old_input' => $old ? ['page_path' => $path] : []]);
                $html = $this->withHeader('referer', url($path))->get(route('kontakt'))->assertOk()->getContent();
                $this->assertTrue(str_contains($html, 'name="page_path" value="'.$expected.'"'), 'Pole musi zawierać tylko nazwę ekranu.');
                $this->assertFalse(str_contains($html, $this->marker($path)), 'W HTML pozostał wrażliwy segment.');
            }
        }
    }

    public function test_walidacja_nie_odklada_wrazliwego_kontekstu_w_sesji(): void
    {
        // Limit kontaktu (5/h) to osobna sprawa; tu liczy się kontekst.
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach ($this->paths() as [$path, $expected]) {
            $response = $this->from(route('kontakt'))->post(route('kontakt.store'), [
                'kind' => 'blad', 'message' => 'Treść, która ma pozostać w formularzu.',
                'contact_email' => 'niepoprawny', 'page_path' => $path,
            ])->assertSessionHasErrors('contact_email');
            $this->assertTrue(session()->getOldInput('page_path') === $expected, 'Sesja musi zawierać oczyszczony kontekst.');
            $response->assertSessionHasInput('message', 'Treść, która ma pozostać w formularzu.');
        }
    }

    public function test_http_zapisuje_bezpieczny_kontekst_i_panel_go_wyswietla(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($this->admin());
        foreach ($this->paths() as [$path, $expected]) {
            $this->post(route('kontakt.store'), ['kind' => 'blad', 'message' => 'Nie działa mi ekran konta.', 'page_path' => $path])
                ->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');
            $message = ContactMessage::query()->latest('id')->firstOrFail();
            $this->assertTrue($message->page_path === $expected, 'Baza musi zawierać tylko nazwę ekranu.');
            $html = $this->get(route('admin.contact.show', $message))->assertOk()->getContent();
            $this->assertTrue(str_contains($html, $expected));
            $this->assertFalse(str_contains($html, $this->marker($path)), 'Panel nie może pokazywać wrażliwego segmentu.');
        }
    }

    public function test_akcja_domenowa_tez_chroni_zapis(): void
    {
        foreach ($this->paths() as [$path, $expected]) {
            $message = app(PrzyjmijWiadomosc::class)->handle('blad', 'Nie działa mi ekran konta.', sciezka: $path);
            $this->assertTrue($message->fresh()->page_path === $expected, 'Akcja domenowa musi oczyszczać kontekst.');
        }
    }

    public function test_zwykla_sciezka_zostaje_a_obca_domena_odpada(): void
    {
        foreach ([url('/przepisy/rosol?q=tekst#opis') => '/przepisy/rosol', 'https://obca.example/przepisy/rosol' => null, '//obca.example/przepisy/rosol' => null] as $path => $expected) {
            $message = app(PrzyjmijWiadomosc::class)->handle('blad', 'Nie działa mi ekran przepisu.', sciezka: $path);
            $this->assertTrue($message->fresh()->page_path === $expected, 'Zachowaj lokalną ścieżkę, odrzuć obce źródło.');
        }
    }

    private function marker(string $path): string
    {
        preg_match('~[0-9a-f]{48}~', $path, $m);

        return $m[0];
    }

    /**
     * Strażnik dryfu: nowa trasa niosąca sekret w ścieżce (parametr `token`
     * albo podpis `signed`) musi trafić do `PageContext::SENSITIVE_ROUTES`.
     */
    public function test_kazda_trasa_z_sekretem_jest_maskowana(): void
    {
        $brakujace = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() === []) {
                continue;
            }
            $sekret = in_array('token', $route->parameterNames(), true)
                || collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'signed'));
            if ($sekret && ! in_array($route->getName(), PageContext::SENSITIVE_ROUTES, true)) {
                $brakujace[] = $route->getName() ?? $route->uri();
            }
        }

        $this->assertSame([], $brakujace, 'Dopisz te trasy do PageContext::SENSITIVE_ROUTES.');
        $this->assertContains('/nowe-haslo', PageContext::maskedScreens());
    }

    public function test_komenda_domyslnie_tylko_pokazuje_a_z_wykonaj_czysci_stare_wpisy(): void
    {
        $marker = bin2hex(random_bytes(24));
        $zTokenem = ContactMessage::factory()->create();
        $zakodowana = ContactMessage::factory()->create();
        $podwojnie = ContactMessage::factory()->create();
        $zwykla = ContactMessage::factory()->create();
        // Stan sprzed poprawki: zapis z pominięciem PageContext.
        DB::table('contact_messages')->where('id', $zTokenem->id)->update(['page_path' => '/nowe-haslo/'.$marker, 'updated_at' => '2026-01-01 10:00:00']);
        DB::table('contact_messages')->where('id', $zakodowana->id)->update(['page_path' => '/%6eowe-haslo/'.$marker]);
        // Podwójne kodowanie — nie da się rozstrzygnąć, co to za ekran.
        DB::table('contact_messages')->where('id', $podwojnie->id)->update(['page_path' => '/%256eowe-haslo/'.$marker]);
        DB::table('contact_messages')->where('id', $zwykla->id)->update(['page_path' => '/przepisy/rosol']);

        Artisan::call('kuking:oczysc-kontekst-kontaktu');
        $podglad = Artisan::output();
        $this->assertFalse(str_contains($podglad, $marker), 'Podgląd nie może wypisywać tokenu.');
        $this->assertStringContainsString('Do poprawienia: 3.', $podglad);
        $this->assertSame('/nowe-haslo/'.$marker, $zTokenem->fresh()->page_path, 'Bez --wykonaj nic się nie zmienia.');

        Artisan::call('kuking:oczysc-kontekst-kontaktu', ['--wykonaj' => true]);
        $this->assertFalse(str_contains(Artisan::output(), $marker), 'Zapis nie może wypisywać tokenu.');
        $this->assertSame('/nowe-haslo', $zTokenem->fresh()->page_path);
        $this->assertSame('2026-01-01 10:00:00', $zTokenem->fresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('/nowe-haslo', $zakodowana->fresh()->page_path);
        $this->assertNull($podwojnie->fresh()->page_path);
        $this->assertSame('/przepisy/rosol', $zwykla->fresh()->page_path);
        $this->assertSame(4, ContactMessage::query()->count(), 'Komenda niczego nie kasuje.');

        Artisan::call('kuking:oczysc-kontekst-kontaktu');
        $this->assertStringContainsString('Nie ma czego poprawiać.', Artisan::output());
    }
}
