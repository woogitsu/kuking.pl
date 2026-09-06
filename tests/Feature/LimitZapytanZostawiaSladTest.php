<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Odbicie przez limit zapytań zostawia w logu ślad, KTÓRA trasa go wywołała.
 *
 * PO CO TO JEST
 * `ThrottleRequestsException` dziedziczy po `HttpException`, a tej Laravel
 * z zasady nie raportuje — więc 429 nie zostawiał w logu ani jednej linijki.
 * Człowiek widział „Za dużo prób", a po naszej stronie nie było jak ustalić,
 * czy chodziło o publikację wpisu, o komentarz, czy o coś zupełnie innego.
 *
 * Zgłoszenie właściciela wyglądało dokładnie tak: 429 przy pierwszej próbie
 * dodania zdjęcia danego dnia, bez żadnego sposobu, żeby sprawdzić, który
 * limit zadziałał i dlaczego.
 *
 * DLACZEGO TO JEST TEST, A NIE „PRZECIEŻ DODALIŚMY LOGOWANIE"
 * Brak wpisu w logu nie boli od razu i nie widać go na żadnym ekranie —
 * czyli jest to dokładnie ten rodzaj regresji, który wraca po cichu.
 * Ktoś przestawi `render()` na `report()`, zobaczy zielone testy i pójdzie
 * dalej, a my znowu zostaniemy z 429 bez śladu.
 *
 * CZEGO W TYM WPISIE BYĆ NIE MOŻE
 * Adresu IP, identyfikatora konta ani ścieżki z prawdziwym slugiem
 * (AGENTS.md §7 — żadnych PII w logach). Test pilnuje jednego i drugiego:
 * że ślad jest ORAZ że nie ma w nim identyfikatora osoby.
 */
class LimitZapytanZostawiaSladTest extends TestCase
{
    use RefreshDatabase;

    public function test_odbicie_przez_limit_zapisuje_nazwe_trasy_do_logu(): void
    {
        $basia = $this->user('basia');

        $zapisane = [];

        Log::listen(function ($wiadomosc) use (&$zapisane): void {
            $zapisane[] = $wiadomosc;
        });

        // Limit komentarzy z config/kuking.php to 10 na minutę i jest liczony
        // po zalogowanym koncie. Bijemy w niego celowo tą trasą, a nie
        // logowaniem: logowanie ma WŁASNY komunikat w formularzu i nie kończy
        // się stroną 429, więc niczego by tu nie sprawdziło.
        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $odpowiedz = null;

        for ($i = 0; $i <= 10; $i++) {
            $odpowiedz = $this->actingAs($basia)
                ->post(route('posts.comment', $post), ['body' => "Komentarz numer {$i}."]);
        }

        $odpowiedz->assertStatus(429);

        $olimicie = array_values(array_filter(
            $zapisane,
            fn ($w) => $w->message === 'Limit zapytań zadziałał',
        ));

        $this->assertCount(
            1,
            $olimicie,
            'Odbicie przez limit zapytań nie zostawiło śladu w logu. Bez niego '
            .'nie da się ustalić, KTÓRA trasa odbiła człowieka — a dokładnie '
            .'tego brakowało przy zgłoszeniu „429 przy dodawaniu zdjęcia".',
        );

        $kontekst = $olimicie[0]->context;

        $this->assertSame('posts.comment', $kontekst['trasa']);
        $this->assertSame('koncie', $kontekst['liczony_po']);
        $this->assertIsNumeric($kontekst['ponow_za_s']);
    }

    public function test_slad_w_logu_nie_zawiera_danych_osobowych(): void
    {
        $basia = $this->user('basia');

        $zapisane = [];

        Log::listen(function ($wiadomosc) use (&$zapisane): void {
            $zapisane[] = $wiadomosc;
        });

        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        for ($i = 0; $i <= 10; $i++) {
            $this->actingAs($basia)
                ->post(route('posts.comment', $post), ['body' => "Komentarz numer {$i}."]);
        }

        $olimicie = array_values(array_filter(
            $zapisane,
            fn ($w) => $w->message === 'Limit zapytań zadziałał',
        ));

        $this->assertNotEmpty($olimicie);

        $caly = json_encode($olimicie[0]->context, JSON_THROW_ON_ERROR);

        // Identyfikator konta, identyfikator wpisu i adres IP nie mają prawa
        // trafić do logu. Wzorzec trasy (`/wpisy/{post}/komentarz`) tak —
        // to jest szablon, nie dane człowieka.
        $this->assertStringNotContainsString((string) $basia->getKey(), $caly);
        $this->assertStringNotContainsString((string) $post->getKey(), $caly);
        $this->assertStringNotContainsString('127.0.0.1', $caly);
    }
}
