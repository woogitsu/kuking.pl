<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DemoSeeder jest powtarzalny i atomowy (issue #2389).
 *
 * Drugi `db:seed --class=DemoSeeder` kończył się naruszeniem unikalności
 * `recipes.slug` po zapisaniu części danych.
 */
class DemoSeederIdempotentnyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        Storage::fake('public');
        $this->withoutMockingConsoleOutput();
    }

    private function zasiej(): string
    {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true, '--no-interaction' => true]);

        return Artisan::output();
    }

    /**
     * @return array<string, int>
     */
    private function stan(): array
    {
        return [
            'users' => User::query()->count(),
            'posts' => Post::query()->count(),
            'recipes' => Recipe::query()->count(),
            'media' => Media::query()->count(),
            'comments' => Comment::query()->count(),
            'wykonania' => CookedEvent::query()->count(),
            'powiadomienia' => Notification::query()->count(),
        ];
    }

    #[Test]
    public function test_drugie_uruchomienie_nie_rzuca_i_nie_zmienia_danych(): void
    {
        $this->zasiej();
        $pierwszy = $this->stan();
        $idyPrzepisow = Recipe::query()->orderBy('slug')->pluck('id')->all();

        // Kontrola dodatnia: pierwszy przebieg naprawdę coś zasiał,
        // w tym wykonania przez RecordCookedEvent.
        $this->assertGreaterThan(0, $pierwszy['recipes']);
        $this->assertSame(2, $pierwszy['wykonania']);
        $this->assertGreaterThan(0, $pierwszy['powiadomienia']);

        $wyjscie = $this->zasiej();

        $this->assertSame($pierwszy, $this->stan(), 'Drugi zasiew zmienił dane demo.');
        $this->assertSame($idyPrzepisow, Recipe::query()->orderBy('slug')->pluck('id')->all());
        $this->assertStringContainsString('Dane demo już istnieją', $wyjscie);
    }

    #[Test]
    public function test_nieudany_zasiew_nie_zostawia_stanu_czesciowego(): void
    {
        // Slug drugiego przepisu zajęty cudzym wierszem: seeder zapisze już
        // użytkowników, wpisy i pierwszy przepis, a potem trafi na kolizję.
        $obcy = User::factory()->create();
        Recipe::factory()->create(['author_id' => $obcy->getKey(), 'slug' => 'chleb-pszenno-zytni-na-zakwasie']);
        $przed = $this->stan();

        try {
            $this->zasiej();
            $this->fail('Kolizja sluga powinna przerwać zasiew.');
        } catch (UniqueConstraintViolationException) {
            // oczekiwane
        }

        $this->assertSame($przed, $this->stan(), 'Nieudany zasiew zostawił dane częściowe.');
        $this->assertFalse(Recipe::query()->where('slug', 'rosol-babci-zofii')->exists());
    }
}
