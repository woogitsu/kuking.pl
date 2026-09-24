<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Komentarze pod WYKONANIEM idą stronami, tak jak pod wpisem i przepisem
 * (issue #938).
 *
 * CO BYŁO ZŁE
 * `CookedEventController::show()` został na dawnym `->load(['comments',
 * 'comments.replies', ...])` bez limitu, kiedy wpis i przepis dostały już
 * paginację (`KomentarzeStronamiTest`). Koszt jednego wejścia rósł z całą
 * historią rozmowy: wiersze, autorzy, awatary i HTML. A powiadomienie
 * o komentarzu pod wykonaniem zawsze prowadziło na stronę 1
 * (`Notification::destinationUrls()`), co było prawdą tylko dlatego, że
 * strona 1 była wszystkim.
 *
 * Ten test liczy MODELE i WIERSZE, a nie sam link „Pokaż więcej": przycisk
 * pod pełną listą też by się wyrenderował, gdyby ktoś podał widokowi
 * paginator zbudowany z kolekcji już wczytanej w całości.
 */
class KomentarzeWykonaniaStronamiTest extends TestCase
{
    use RefreshDatabase;

    private User $widz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->widz = $this->user('widz938');
    }

    private function wykonanieZKomentarzami(int $ile, string $kto = ''): CookedEvent
    {
        $autor = $this->user('autorka938'.$kto);
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $this->user('gotujaca938'.$kto)->getKey(),
        ]);

        $piszacy = $this->user('piszacy938'.$kto);
        for ($i = 1; $i <= $ile; $i++) {
            Comment::create([
                'cooked_event_id' => $wykonanie->getKey(),
                'author_id' => $piszacy->getKey(),
                'body' => sprintf('WATEK-938-%03d', $i),
                'status' => Comment::STATUS_PUBLISHED,
                // Jawny czas: kolejność stron ma być tą, w której pisano.
                'created_at' => now()->subMinutes(1000 - $i),
            ]);
        }

        return $wykonanie;
    }

    /** @return list<string> */
    private function watkiNaStronie(string $html): array
    {
        preg_match_all('/WATEK-938-\d{3}/', $html, $trafienia);

        return array_values(array_unique($trafienia[0]));
    }

    private function assertNaglowekMowi(int $ile, string $html): void
    {
        $this->assertMatchesRegularExpression('/Komentarze\s*\('.$ile.'\)/u', $html);
    }

    /**
     * KONTROLA DODATNIA. Przy kilku komentarzach widać wszystkie i nie ma
     * przycisku — bez tego pomiar niżej przechodziłby także na stronie,
     * która nie pokazuje komentarzy wcale.
     */
    public function test_kontrola_kilka_komentarzy_widac_wszystkie_bez_przycisku(): void
    {
        $wykonanie = $this->wykonanieZKomentarzami(3);

        $odpowiedz = $this->actingAs($this->widz)->get(route('cooked.show', $wykonanie))->assertOk();
        $html = (string) $odpowiedz->getContent();

        $this->assertSame(['WATEK-938-001', 'WATEK-938-002', 'WATEK-938-003'], $this->watkiNaStronie($html));
        $this->assertNaglowekMowi(3, $html);
        $odpowiedz->assertDontSee('Pokaż więcej komentarzy', escape: false);
    }

    public function test_pierwsza_strona_wczytuje_tylko_limit_watkow(): void
    {
        $limit = (int) config('kuking.comments.page_size');
        $this->assertGreaterThan(0, $limit);
        $razem = $limit * 3;

        $wykonanie = $this->wykonanieZKomentarzami($razem);

        $modele = 0;
        Event::listen('eloquent.retrieved: '.Comment::class, function () use (&$modele): void {
            $modele++;
        });
        $wiersze = 0;
        DB::listen(function ($zapytanie) use (&$wiersze): void {
            if (str_contains($zapytanie->sql, 'from "comments"') && ! str_contains($zapytanie->sql, 'count(*)')) {
                $wiersze++;
            }
        });

        $odpowiedz = $this->actingAs($this->widz)->get(route('cooked.show', $wykonanie))->assertOk();
        $html = (string) $odpowiedz->getContent();

        $oczekiwane = array_map(fn (int $i) => sprintf('WATEK-938-%03d', $i), range(1, $limit));
        $this->assertSame($oczekiwane, $this->watkiNaStronie($html));
        $this->assertSame($limit, $modele, "Wczytano {$modele} komentarzy zamiast {$limit}.");
        $this->assertGreaterThan(0, $wiersze, 'Kontrola: komentarze w ogóle nie były czytane z bazy.');

        // Nagłówek mówi o całej rozmowie, nie o stronie.
        $this->assertNaglowekMowi($razem, $html);
        $odpowiedz->assertSee('Pokaż więcej komentarzy', escape: false);
    }

    /** Liczba zapytań nie rośnie z długością rozmowy (bez N+1). */
    public function test_liczba_zapytan_nie_zalezy_od_dlugosci_rozmowy(): void
    {
        $limit = (int) config('kuking.comments.page_size');

        $policz = function (CookedEvent $wykonanie): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->widz)->get(route('cooked.show', $wykonanie))->assertOk();
            $ile = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $ile;
        };

        $krotka = $this->wykonanieZKomentarzami($limit + 1);
        $dluga = $this->wykonanieZKomentarzami($limit * 4, 'b');

        $this->assertSame($policz($krotka), $policz($dluga));
    }

    public function test_druga_strona_pokazuje_dalsze_watki(): void
    {
        $limit = (int) config('kuking.comments.page_size');
        $wykonanie = $this->wykonanieZKomentarzami($limit + 2);

        $html = (string) $this->actingAs($this->widz)
            ->get(route('cooked.show', $wykonanie).'?komentarze=2')
            ->assertOk()
            ->getContent();

        $this->assertSame(
            [sprintf('WATEK-938-%03d', $limit + 1), sprintf('WATEK-938-%03d', $limit + 2)],
            $this->watkiNaStronie($html),
        );
        $this->assertNaglowekMowi($limit + 2, $html);
    }

    public function test_paginacja_nie_gubi_blokad_ani_odpowiedzi(): void
    {
        $wykonanie = $this->wykonanieZKomentarzami(2);
        $watek = Comment::query()->where('body', 'WATEK-938-001')->firstOrFail();

        Comment::create([
            'cooked_event_id' => $wykonanie->getKey(),
            'parent_id' => $watek->getKey(),
            'author_id' => $this->user('odpowiada938')->getKey(),
            'body' => 'ODPOWIEDZ-938',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
        $niechciana = $this->user('niechciana938');
        Comment::create([
            'cooked_event_id' => $wykonanie->getKey(),
            'author_id' => $niechciana->getKey(),
            'body' => 'OD-ZABLOKOWANEJ-938',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
        $this->widz->blocking()->attach($niechciana->getKey(), ['created_at' => now()]);

        $this->actingAs($this->widz)
            ->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('ODPOWIEDZ-938', escape: false)
            ->assertSee('WATEK-938-002', escape: false)
            ->assertDontSee('OD-ZABLOKOWANEJ-938', escape: false);
    }

    /**
     * Powiadomienie o odpowiedzi w wątku z DRUGIEJ strony prowadzi na drugą
     * stronę — a ta strona naprawdę ma ten wątek i tę odpowiedź. Kotwica
     * `id` stoi dziś tylko na korzeniu wątku (`comment-thread.blade.php`),
     * tak samo pod wpisem i przepisem.
     */
    public function test_powiadomienie_prowadzi_na_strone_z_komentarzem(): void
    {
        $limit = (int) config('kuking.comments.page_size');
        $wykonanie = $this->wykonanieZKomentarzami($limit);

        $korzen = $this->user('korzen938');
        $watek = Comment::create([
            'cooked_event_id' => $wykonanie->getKey(),
            'author_id' => $korzen->getKey(),
            'body' => 'KORZEN-938',
            'status' => Comment::STATUS_PUBLISHED,
            'created_at' => now()->subMinute(),
        ]);

        app(PublishComment::class)->handle($this->user('odpisuje938'), $wykonanie, 'ODPOWIEDZ-Z-DRUGIEJ-938', $watek);

        $powiadomienie = Notification::query()
            ->where('user_id', $korzen->getKey())
            ->where('type', Notification::TYPE_REPLY)
            ->firstOrFail();
        $odpowiedz = Comment::query()->where('body', 'ODPOWIEDZ-Z-DRUGIEJ-938')->firstOrFail();

        $cel = $wykonanie->url().'?komentarze=2#komentarz-'.$odpowiedz->getKey();
        $this->actingAs($korzen)->post(route('notifications.open', $powiadomienie))->assertRedirect($cel);

        $this->actingAs($korzen)
            ->get($wykonanie->url().'?komentarze=2')
            ->assertOk()
            ->assertSee('id="komentarz-'.$watek->getKey().'"', escape: false)
            ->assertSee('ODPOWIEDZ-Z-DRUGIEJ-938', escape: false);

        // KONTROLA: pierwsza strona tego wątku NIE ma — inaczej adres ze
        // stroną 2 niczego by nie dowodził.
        $this->actingAs($korzen)
            ->get($wykonanie->url())
            ->assertOk()
            ->assertDontSee('KORZEN-938', escape: false);
    }
}
