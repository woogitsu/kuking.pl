<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Strażnik migracji kasującej Tematy (D-021) ma własny test — bo strażnik,
 * którego nikt nie wywołuje, nie jest strażnikiem (D-132).
 *
 * CZYM TEN STRAŻNIK RÓŻNI SIĘ OD POZOSTAŁYCH W TYM KATALOGU
 * Siedzi w `up()`, nie w `down()`: to `up()` kasuje tu tabele i kolumnę,
 * więc to `up()` musi odmówić, gdy ktoś naprawdę obserwuje temat albo ma go
 * przypisanego do wpisu. D-021 zapisuje stan lokalnej bazy („0 tematów,
 * 0 wpisów z tematem") i WPROST zastrzega, że produkcji nie dało się wtedy
 * sprawdzić — migracja nie ufa więc temu zapisowi i liczy sama.
 *
 * CZEGO PILNUJE TEN PLIK
 *  1. odmowa naprawdę leci, i to zanim cokolwiek zniknie;
 *  2. komunikat podaje OBIE liczby w formie odpornej na odmianę przez liczbę
 *     („Liczba wierszy w `topic_follows`: 1", nie „ma 1 wierszy");
 *  3. kontrola dodatnia: na pustym stanie migracja przechodzi bez pytania.
 */
class UsuniecieTematowOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_07_300000_drop_topics.php');
    }

    /**
     * Odtwarza schemat SPRZED tej migracji.
     *
     * Baza testowa jest po pełnym `migrate`, więc tabel Tematów już nie ma —
     * a strażnik z `up()` da się wywołać dopiero wtedy, gdy jest co liczyć.
     * `down()` tej samej migracji jest jedyną uczciwą drogą do tego stanu:
     * odtwarza dokładnie ten kształt, który miała produkcja.
     */
    private function przywrocTematy(): void
    {
        $this->migracja()->down();
    }

    private function temat(): string
    {
        $id = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $id,
            'slug' => 'zupy',
            'name' => 'Zupy',
            'position' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    #[Test]
    public function test_migracja_odmawia_gdy_ktos_obserwuje_temat(): void
    {
        $this->przywrocTematy();

        $basia = $this->user('basia');
        $temat = $this->temat();

        // JEDEN wiersz, nie pięć. Jeden obserwowany temat jest stanem
        // znacznie prawdopodobniejszym na produkcji niż pięć, a stara forma
        // komunikatu („`topic_follows` ma 1 wierszy") była błędna po polsku
        // dokładnie przy tej jedynce — czyli w jedynym przypadku, którego
        // nikt nie mierzył.
        DB::table('topic_follows')->insert([
            'user_id' => $basia->getKey(),
            'topic_id' => $temat,
            'created_at' => now(),
        ]);

        $odmowa = $this->migracjaOdmawia();

        $this->assertStringContainsString('Liczba wierszy w `topic_follows`: 1.', $odmowa->getMessage());
        $this->assertStringContainsString('Liczba niepustych wartości w `posts.topic_id`: 0.', $odmowa->getMessage());

        // Stara, niegramatyczna forma nie ma prawa wrócić.
        $this->assertStringNotContainsString('ma 1 wierszy', $odmowa->getMessage());
        $this->assertStringNotContainsString('ma 1 niepustych wartości', $odmowa->getMessage());

        // NAJWAŻNIEJSZE: odmowa, która zdążyła już skasować, to tylko
        // ładniejszy komunikat o stracie.
        $this->assertSame(1, (int) DB::table('topic_follows')->count(), 'Obserwowanie tematu zniknęło mimo odmowy.');
        $this->assertSame(1, (int) DB::table('topics')->count(), 'Tabela `topics` została ruszona mimo odmowy.');
    }

    #[Test]
    public function test_migracja_odmawia_gdy_wpis_ma_przypisany_temat(): void
    {
        $this->przywrocTematy();

        $autor = $this->user('autor_tematu');
        $temat = $this->temat();

        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => 'Rosół jak zawsze.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
        ]);

        DB::table('posts')->where('id', $wpis->getKey())->update(['topic_id' => $temat]);

        $odmowa = $this->migracjaOdmawia();

        // DRUGA GAŁĄŹ WARUNKU, własny test: nikt tu niczego nie obserwuje,
        // więc pierwszy licznik stoi na zerze. Gdyby strażnik patrzył tylko
        // na `topic_follows`, ten przypadek przeszedłby po cichu.
        $this->assertStringContainsString('Liczba wierszy w `topic_follows`: 0.', $odmowa->getMessage());
        $this->assertStringContainsString('Liczba niepustych wartości w `posts.topic_id`: 1.', $odmowa->getMessage());

        $this->assertStringNotContainsString('ma 1 niepustych wartości', $odmowa->getMessage());

        $this->assertSame(
            $temat,
            (string) DB::table('posts')->where('id', $wpis->getKey())->value('topic_id'),
            'Przypisanie tematu zniknęło mimo odmowy.',
        );
    }

    #[Test]
    public function test_na_pustym_stanie_migracja_przechodzi_bez_pytania(): void
    {
        // KONTROLA DODATNIA. Odmowa musi być WĄSKA (D-088): tabele Tematów
        // mogą istnieć i mieć seed redakcyjny, a mimo to migracja ma przejść,
        // dopóki nikt tematu nie obserwuje i nikt go nie przypisał do wpisu.
        $this->przywrocTematy();
        $this->temat();

        $this->migracja()->up();

        $this->assertFalse(
            $this->tabelaIstnieje('topics'),
            'Migracja nie usunęła Tematów, choć nie było czego chronić.',
        );
        $this->assertFalse($this->tabelaIstnieje('topic_follows'));
    }

    private function migracjaOdmawia(): RuntimeException
    {
        try {
            $this->migracja()->up();
        } catch (RuntimeException $e) {
            return $e;
        }

        // `fail()` POZA blokiem `try` (D-133): `AssertionFailedError`
        // dziedziczy po `RuntimeException`, więc postawione wewnątrz wpadłoby
        // do własnego `catch`.
        $this->fail('Migracja przeszła, mimo że ktoś naprawdę korzystał z Tematów.');
    }

    private function tabelaIstnieje(string $tabela): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = current_schema()',
            [$tabela],
        ) !== [];
    }
}
