<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contact\Actions\PrzyjmijWiadomosc;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ];
    }

    public function test_referer_i_stare_dane_daja_bezpieczne_pole(): void
    {
        foreach ($this->paths() as [$path, $expected]) {
            foreach ([false, true] as $old) {
                $this->withSession(['_old_input' => $old ? ['page_path' => $path] : []]);
                $html = $this->withHeader('referer', url($path))->get(route('kontakt'))->assertOk()->getContent();
                $this->assertTrue(str_contains($html, 'name="page_path" value="'.$expected.'"'), 'Pole musi zawierać tylko nazwę ekranu.');
                $this->assertFalse(str_contains($html, basename($path)), 'W HTML pozostał wrażliwy segment.');
            }
        }
    }

    public function test_walidacja_nie_odklada_wrazliwego_kontekstu_w_sesji(): void
    {
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
        $this->actingAs($this->admin());
        foreach ($this->paths() as [$path, $expected]) {
            $this->post(route('kontakt.store'), ['kind' => 'blad', 'message' => 'Nie działa mi ekran konta.', 'page_path' => $path])
                ->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');
            $message = ContactMessage::query()->latest('id')->firstOrFail();
            $this->assertTrue($message->page_path === $expected, 'Baza musi zawierać tylko nazwę ekranu.');
            $html = $this->get(route('admin.contact.show', $message))->assertOk()->getContent();
            $this->assertTrue(str_contains($html, $expected));
            $this->assertFalse(str_contains($html, basename($path)), 'Panel nie może pokazywać wrażliwego segmentu.');
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
}
