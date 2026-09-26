<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Models\Post;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `kind` NIE WCHODZI DO WPISU HURTEM — dowód na ZACHOWANIE, nie na kształt
 * tablicy `$fillable`.
 *
 * Test, który sprawdza `assertNotContains('kind', $fillable)`, pilnuje zapisu
 * w pliku, a nie skutku: przeżyje `Model::unguard()`, `forceFill()` w złym
 * miejscu i każdą inną drogę, którą danie mimo wszystko stałoby się pytaniem.
 * Dlatego wszystkie asercje niżej pytają o to, co NAPRAWDĘ wylądowało
 * w wierszu `posts`.
 *
 * FABRYKA NIE JEST TU POWIERZCHNIĄ ATAKU i dlatego nie ma jej w asercjach:
 * `Illuminate\Database\Eloquent\Factories\Factory::makeInstance()` buduje
 * model w `Model::unguarded()`, więc `Post::factory()->create(['kind' => …])`
 * przejdzie z definicji frameworka i żadna zmiana w `$fillable` tego nie
 * ruszy. Fabryka i tak robi pytania przez `oznaczJakoPytanie()` — po to, żeby
 * testy budowały je TĄ SAMĄ drogą co produkcja, a nie dlatego, że to bramka.
 *
 * Druga warstwa obrony — walidacja w `PostController`, która odrzuca `kind`
 * z żądania HTTP — ma własny dowód
 * (`PostQuestionSchemaTest::test_existing_http_publisher_does_not_accept_question_fields`)
 * i nie zastępuje tego testu: tamta broni jednej trasy, ten broni modelu.
 */
class RodzajWpisuPozaMasowymPrzypisaniemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Cała klasa mierzy ciche odrzucenie pola z produkcji, nie wyjątek
        // trybu ścisłego (#976) — patrz `TestCase::mierzMasowePrzypisanieJakWProdukcji()`.
        $this->mierzMasowePrzypisanieJakWProdukcji();
    }

    public function test_mass_assigned_kind_does_not_create_a_question(): void
    {
        config(['kuking.questions.enabled' => true]);
        $autor = $this->user('autor');

        $post = Post::create([
            'kind' => Post::KIND_QUESTION,
            'author_id' => $autor->getKey(),
            'body' => 'To miało być danie.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->assertSame(Post::KIND_DISH, $post->kind);
        $this->assertSame(Post::KIND_DISH, DB::table('posts')->where('id', $post->getKey())->value('kind'));
        $this->assertNull(DB::table('posts')->where('id', $post->getKey())->value('title'));
        $this->assertSame(0, Post::query()->where('kind', Post::KIND_QUESTION)->count());
    }

    /**
     * `kind` + `title` naraz — czyli komplet, który BAZA by przyjęła.
     *
     * To jest wariant groźny: gdyby `kind` przeszedł hurtem, CHECK
     * `posts_kind_title_check` byłby spełniony i pytanie powstałoby po cichu.
     * Po odcięciu `kind` zostaje sam tytuł przy daniu, którego baza nie
     * przyjmuje — więc zapis odbija się o ograniczenie zamiast wejść.
     */
    public function test_mass_assigned_kind_with_title_is_refused_and_writes_nothing(): void
    {
        config(['kuking.questions.enabled' => true]);
        $autor = $this->user('autor');

        // Wstawienie w PODTRANSAKCJI (SAVEPOINT): odbicie się o CHECK
        // przerywa transakcję Postgresa, a `RefreshDatabase` trzyma cały test
        // w jednej. Bez tego kolejne zapytanie dostałoby 25P02 i test mówiłby
        // o swoim własnym rusztowaniu, a nie o wpisie.
        $blad = null;
        try {
            DB::transaction(function () use ($autor): void {
                Post::create([
                    'kind' => Post::KIND_QUESTION,
                    'title' => 'Jak uratować przesoloną zupę?',
                    'author_id' => $autor->getKey(),
                    'body' => 'Podrzucone pytanie.',
                    'visibility' => Post::VISIBILITY_PUBLIC,
                    'status' => Post::STATUS_PUBLISHED,
                    'published_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            $blad = $e;
        }

        $this->assertNotNull($blad, 'Tytuł przy daniu musi odbić się o CHECK w bazie.');
        $this->assertSame('23514', $blad->errorInfo[0]);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_mass_assignment_cannot_turn_an_existing_dish_into_a_question(): void
    {
        config(['kuking.questions.enabled' => true]);
        $danie = Post::factory()->create();

        $danie->fill(['kind' => Post::KIND_QUESTION])->save();
        $danie->update(['kind' => Post::KIND_QUESTION]);

        $this->assertSame(Post::KIND_DISH, $danie->refresh()->kind);
        $this->assertSame(Post::KIND_DISH, DB::table('posts')->where('id', $danie->getKey())->value('kind'));
    }

    /**
     * Zamknięcie drogi hurtowej ma sens tylko wtedy, gdy droga jawna działa.
     * Bez tej asercji zielony test dałoby się osiągnąć, psując pytania.
     */
    public function test_named_method_is_the_working_way_to_create_a_question(): void
    {
        config(['kuking.questions.enabled' => true]);

        $pytanie = app(PublishPost::class)->handle(
            author: $this->user('pytajacy'),
            body: 'Zupa wyszła słona.',
            questionTitle: 'Jak uratować przesoloną zupę?',
        );

        $this->assertSame(Post::KIND_QUESTION, $pytanie->refresh()->kind);
        $this->assertSame('Jak uratować przesoloną zupę?', $pytanie->title);
        $this->assertSame(Post::KIND_QUESTION, DB::table('posts')->where('id', $pytanie->getKey())->value('kind'));
    }
}
