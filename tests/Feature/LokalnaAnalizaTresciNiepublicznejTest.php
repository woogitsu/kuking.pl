<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\WpisWskazujacyPrzepis;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TREŚĆ NIEPUBLICZNA: LOKALNIE TAK, DO OPENAI NIE (D-241).
 *
 * Decyzja właściciela z 22.09 (doprecyzowana o 23:30): OpenAI ocenia tylko
 * treść publiczną. Treść niepubliczna nie wychodzi poza serwer, ale lokalne
 * wzorce spamu (`WykrywaczSygnalow`, D-052) sprawdzają ją NADAL.
 *
 * D-240 (#1298) zamknęło wysyłkę, ale przy okazji zdjęło lokalne oznaczenie
 * z treści zbanowanych kont i z komentarzy pod zapowiedzią przepisu „dla
 * obserwujących”. Każdy test sprawdza więc DWIE rzeczy naraz: lokalne
 * oznaczenie jest, a żądania do OpenAI nie ma. Samo pierwsze nie
 * wystarczy: przywrócenie lokalnej analizy nie może otworzyć wysyłki.
 *
 * Wszystko idzie w atrapę (`Http::fake`). `TestCase` ma
 * `preventStrayRequests()`, więc do prawdziwego API nic wyjść nie może.
 */
class LokalnaAnalizaTresciNiepublicznejTest extends TestCase
{
    use RefreshDatabase;

    private const SPAM = 'Zarabiaj z domu, tel. 600 100 200';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config([
            'kuking.moderation.sygnaly.wlaczone' => true,
            // Klucz JEST ustawiony, jak dziś na produkcji — bez tego brak
            // wysyłki byłby zasługą braku klucza, a nie granicy.
            'kuking.moderation.model.klucz' => 'atrapa-klucza',
            'kuking.moderation.model.ocenia_zdjecia' => true,
        ]);

        Http::fake(fn (Request $r) => Http::response(['results' => [['category_scores' => ['hate' => 0.95]]]]));
    }

    public function test_wpis_zbanowanego_konta_dostaje_lokalne_oznaczenie_i_nie_wychodzi(): void
    {
        $autor = $this->user('zbanowany');
        $wpis = $this->wpis($autor, self::SPAM);
        $autor->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->analizuj(PrzeanalizujTresc::TYP_WPIS, $wpis);

        Http::assertNothingSent();
        $this->assertSame(1, $this->oznaczenia($wpis), 'Ban konta zdjął lokalną analizę wpisu.');
    }

    public function test_komentarz_pod_zapowiedzia_przepisu_dla_obserwujacych_dostaje_lokalne_oznaczenie_i_nie_wychodzi(): void
    {
        $zapowiedz = $this->zapowiedz($this->user('kucharka'), 'followers');
        $komentarz = $this->komentarz($this->user('komentuje'), $zapowiedz);

        $this->analizuj(PrzeanalizujTresc::TYP_KOMENTARZ, $komentarz);

        Http::assertNothingSent();
        $this->assertSame(1, $this->oznaczenia($komentarz), 'Zapowiedź „dla obserwujących” zdjęła lokalną analizę komentarza.');
    }

    public function test_komentarz_zbanowanego_autora_dostaje_lokalne_oznaczenie_i_nie_wychodzi(): void
    {
        $autor = $this->user('zbanowana');
        $komentarz = $this->komentarz($autor, $this->wpis($this->user('gospodarz'), 'Publiczny wpis.'));
        $autor->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->analizuj(PrzeanalizujTresc::TYP_KOMENTARZ, $komentarz);

        Http::assertNothingSent();
        $this->assertSame(1, $this->oznaczenia($komentarz));
    }

    public function test_komentarz_pod_wpisem_zbanowanego_konta_dostaje_lokalne_oznaczenie_i_nie_wychodzi(): void
    {
        $gospodarz = $this->user('gospodarz');
        $komentarz = $this->komentarz($this->user('komentuje'), $this->wpis($gospodarz, 'Wpis.'));
        $gospodarz->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->analizuj(PrzeanalizujTresc::TYP_KOMENTARZ, $komentarz);

        Http::assertNothingSent();
        $this->assertSame(1, $this->oznaczenia($komentarz));
    }

    /**
     * Granica przywrócenia: prywatne i karencja usunięcia zostają wycięte
     * także lokalnie, jak przed D-240 (D-052 pkt 3, #827). Ban to kara;
     * karencja to decyzja samego człowieka („konto zniknie od razu”).
     *
     * @return array<string, array{string}>
     */
    public static function nadalBezOznaczenia(): array
    {
        return [
            'zapowiedz przepisu prywatnego' => ['zapowiedz_prywatna'],
            'wpis konta w karencji usuniecia' => ['karencja'],
        ];
    }

    #[DataProvider('nadalBezOznaczenia')]
    public function test_prywatne_i_karencja_nadal_bez_oznaczenia_i_bez_wysylki(string $stan): void
    {
        $autor = $this->user('ktos');

        if ($stan === 'zapowiedz_prywatna') {
            $tresc = $this->komentarz($this->user('komentuje'), $this->zapowiedz($autor, 'private'));
            $this->analizuj(PrzeanalizujTresc::TYP_KOMENTARZ, $tresc);
        } else {
            $tresc = $this->wpis($autor, self::SPAM);
            $autor->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();
            $this->analizuj(PrzeanalizujTresc::TYP_WPIS, $tresc);
        }

        Http::assertNothingSent();
        $this->assertSame(0, $this->oznaczenia($tresc));
    }

    /**
     * Kontrola dodatnia: bez niej `assertNothingSent()` przechodziłoby także
     * przy zepsutej atrapie albo wyłączonym kluczu.
     */
    public function test_kontrola_publiczny_komentarz_wychodzi_i_dostaje_oznaczenie(): void
    {
        $komentarz = $this->komentarz($this->user('komentuje'), $this->wpis($this->user('gospodarz'), 'Wpis.'));

        $this->analizuj(PrzeanalizujTresc::TYP_KOMENTARZ, $komentarz);

        Http::assertSentCount(1);
        $this->assertSame(1, $this->oznaczenia($komentarz));
    }

    // ---------------------------------------------------------------
    // POMOCNICZE
    // ---------------------------------------------------------------

    private function wpis(User $autor, string $tekst): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tekst,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /** Zapowiedź przepisu tak, jak powstaje w serwisie: bez własnej treści, bramką jest przepis. */
    private function zapowiedz(User $autor, string $widocznoscPrzepisu): Post
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => $widocznoscPrzepisu,
        ]);

        $zapowiedz = Post::query()->where('recipe_id', $przepis->getKey())->first()
            ?? WpisWskazujacyPrzepis::dopisz($przepis);

        $this->assertInstanceOf(Post::class, $zapowiedz);
        $this->assertTrue($zapowiedz->czyJestZapowiedziaPrzepisu(), 'Test nie zbudował zapowiedzi przepisu.');

        return $zapowiedz;
    }

    private function komentarz(User $autor, Post $wpis): Comment
    {
        return Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => self::SPAM,
        ]);
    }

    private function analizuj(string $typ, Post|Comment $tresc): void
    {
        $this->app->call([new PrzeanalizujTresc($typ, (string) $tresc->getKey()), 'handle']);
    }

    private function oznaczenia(Post|Comment $tresc): int
    {
        return Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('target_id', $tresc->getKey())
            ->count();
    }
}
