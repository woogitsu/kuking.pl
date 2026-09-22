<?php

declare(strict_types=1);

namespace Tests\Measurements;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/** Pomiar ręcznie uruchamiany, poza suitą Feature: nie utrwala decyzji produktowej. */
class KontaktOdnajdywaniePomiarTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    public function test_zmierz_dostepne_drogi_na_stu_wiadomosciach(): void
    {
        $operator = $this->admin();
        $author = $this->user('autor_pomiaru', ['display_name' => 'Autor pomiaru']);
        for ($i = 1; $i <= 100; $i++) {
            $message = ContactMessage::create([
                'kind' => 'blad',
                'message' => str_repeat('Opis działania formularza. ', 22).($i === 88 ? 'Szukana końcówka wiadomości.' : 'Zwykłe zakończenie.'),
                'user_id' => $i === 76 ? $author->id : null,
                'contact_email' => $i === 76 ? null : 'osoba'.$i.'@example.test',
            ]);
            $message->forceFill(['created_at' => now()->subDays(10)->addSeconds($i)])->save();
            $message->oznaczJako(ContactMessage::STATUS_ZALATWIONA, $operator);
        }
        $this->assertSame(100, ContactMessage::count());
        $this->actingAs($operator);
        $results = [];
        foreach (['tresc' => 'Szukana końcówka wiadomości.', 'konto' => 'Autor pomiaru', 'gosc' => 'osoba93@example.test'] as $kind => $needle) {
            $start = hrtime(true);
            $queries = 0;
            DB::flushQueryLog();
            DB::enableQueryLog();
            $pages = $details = 0;
            $found = false;
            for ($page = 1; $page <= 4 && ! $found; $page++) {
                $response = $this->get(route('admin.contact', ['status' => 'done', 'page' => $page]))->assertOk();
                $pages++;
                $html = $this->trescEkranu($response->getContent());
                if (str_contains($html, $needle)) {
                    $found = true;
                    break;
                }
                if ($kind === 'konto') {
                    continue;
                }
                foreach ($response->viewData('wiadomosci') as $message) {
                    $details++;
                    $html = $this->trescEkranu($this->get(route('admin.contact.show', $message))->assertOk()->getContent());
                    if (str_contains($html, $needle)) {
                        $found = true;
                        break;
                    }
                }
            }
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $results[$kind] = ['found' => $found, 'list_requests' => $pages, 'detail_requests' => $details, 'sql_queries' => $queries, 'elapsed_ms' => round((hrtime(true) - $start) / 1000000, 1)];
        }
        fwrite(STDOUT, "\nPOMIAR_841 ".json_encode($results, JSON_UNESCAPED_UNICODE)."\n");
    }
}
