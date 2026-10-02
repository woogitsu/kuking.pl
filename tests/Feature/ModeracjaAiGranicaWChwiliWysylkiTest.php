<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * GRANICA PUBLICZNOŚCI W CHWILI WYSYŁKI DO OPENAI (#2708, pytanie 9 analizy
 * prawnej z 2.10.2026).
 *
 * Zadanie `PrzeanalizujTresc` niesie WYŁĄCZNIE typ i identyfikator, a
 * `GranicaWysylki` czyta stan z bazy tuż przed każdym żądaniem (D-240). Ten
 * plik dopisuje przypadki graniczne, których nie ma w
 * `GranicaWysylkiDoOpenAiTest`: zadanie przechodzi serializację jak w workerze,
 * szkic i wpis zdjęty, autor w karencji usunięcia, zapowiedź przepisu, który
 * stał się prywatny, oraz to, że treść przepisu nie dołącza się do wpisu,
 * który go wskazuje.
 *
 * Przepisy udostępniane jednej osobie (`recipe_shares`) w tej gałęzi nie
 * istnieją — gdy się pojawią, ich stan trzeba dopisać do `GranicaWysylki`
 * i do tego pliku.
 */
class ModeracjaAiGranicaWChwiliWysylkiTest extends TestCase
{
    use RefreshDatabase;

    private const ZNACZNIK = 'ZNACZNIKWYSYLKI';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config([
            'kuking.moderation.sygnaly.wlaczone' => true,
            'kuking.moderation.model.klucz' => 'atrapa-klucza',
            'kuking.moderation.model.ocenia_zdjecia' => false,
        ]);

        Http::fake(fn (Request $r) => Http::response(['results' => [['category_scores' => ['hate' => 0.1]]]]));
    }

    private function zadanie(string $typ, Post|Comment $tresc): PrzeanalizujTresc
    {
        // Serializacja w jedną stronę i z powrotem, jak przy prawdziwej kolejce.
        $odtworzone = unserialize(serialize(new PrzeanalizujTresc($typ, (string) $tresc->getKey())));
        $this->assertInstanceOf(PrzeanalizujTresc::class, $odtworzone);

        return $odtworzone;
    }

    private function uruchom(PrzeanalizujTresc $zadanie): void
    {
        $this->app->call([$zadanie, 'handle']);
    }

    /** @param array<string, mixed> $atrybuty */
    private function wpis(User $autor, string $tekst = self::ZNACZNIK.' zwykła zupa', array $atrybuty = []): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tekst,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
            ...$atrybuty,
        ]);
    }

    /**
     * Zadanie nie zawiera ani treści, ani widoczności, więc nie ma skąd
     * wziąć stanu z chwili dodania do kolejki.
     */
    public function test_zadanie_niesie_tylko_typ_i_identyfikator(): void
    {
        $parametry = array_map(
            fn (\ReflectionParameter $p): string => $p->getName(),
            (new ReflectionClass(PrzeanalizujTresc::class))->getConstructor()?->getParameters() ?? [],
        );
        $this->assertSame(['typ', 'id'], $parametry);

        $wpis = $this->wpis($this->user('autor'));

        $this->assertStringNotContainsString(self::ZNACZNIK, serialize($this->zadanie(PrzeanalizujTresc::TYP_WPIS, $wpis)));
    }

    public function test_dodanie_komentarza_do_kolejki_niczego_nie_wysyla(): void
    {
        Queue::fake();

        app(PublishComment::class)->handle($this->user('komentuje'), $this->wpis($this->user('wlasciciel')), self::ZNACZNIK.' komentarz');

        Queue::assertPushed(PrzeanalizujTresc::class);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function stanyPoDodaniuDoKolejki(): array
    {
        return [
            'prywatny' => ['prywatny'],
            'dla obserwujacych' => ['obserwujacy'],
            'zdjety przez moderacje' => ['zdjety'],
            'cofniety do szkicu' => ['szkic'],
            'autor w karencji usuniecia' => ['karencja'],
            'przepis zapowiedzi stal sie prywatny' => ['zapowiedz_prywatna'],
        ];
    }

    /** Między dodaniem do kolejki a wysyłką wpis traci publiczność. */
    #[DataProvider('stanyPoDodaniuDoKolejki')]
    public function test_wpis_zmieniony_po_dodaniu_do_kolejki_nie_wychodzi(string $stan): void
    {
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $wpis = $stan === 'zapowiedz_prywatna'
            ? $this->wpis($autor, '', ['recipe_id' => $przepis->getKey()])
            : $this->wpis($autor);

        if ($stan === 'zapowiedz_prywatna') {
            $this->assertTrue($wpis->czyJestZapowiedziaPrzepisu(), 'Test nie zbudował zapowiedzi przepisu.');
        }

        $zadanie = $this->zadanie(PrzeanalizujTresc::TYP_WPIS, $wpis);

        match ($stan) {
            'prywatny' => $wpis->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save(),
            'obserwujacy' => $wpis->forceFill(['visibility' => Post::VISIBILITY_FOLLOWERS])->save(),
            'zdjety' => $wpis->forceFill(['status' => Post::STATUS_REMOVED])->save(),
            'szkic' => $wpis->forceFill(['status' => Post::STATUS_DRAFT])->save(),
            'karencja' => $autor->forceFill(['status' => User::STATUS_PENDING_DELETE])->save(),
            'zapowiedz_prywatna' => $przepis->forceFill(['visibility' => 'private'])->save(),
            default => throw new \LogicException('Nieobsłużony wariant w match.'),
        };

        $this->uruchom($zadanie);

        Http::assertNothingSent();
    }

    /**
     * OSTATNIA BRAMKA, sama: `OcenaModelem` dostaje w pamięci STARY obiekt
     * (sprzed zmiany), a przed żądaniem pyta bazę od nowa. Dzięki temu test
     * nie zależy od wcześniejszych sit w zadaniu (`tresc()`, `pozaAutorem()`).
     *
     * @return array<string, array{string}>
     */
    public static function stanyPrzedSamaWysylka(): array
    {
        return [
            'prywatny' => ['prywatny'],
            'dla obserwujacych' => ['obserwujacy'],
            'zdjety przez moderacje' => ['zdjety'],
            'ukryty' => ['ukryty'],
            'cofniety do szkicu' => ['szkic'],
            'usuniety' => ['usuniety'],
            'autor zbanowany' => ['zbanowany'],
            'autor w karencji usuniecia' => ['karencja'],
        ];
    }

    #[DataProvider('stanyPrzedSamaWysylka')]
    public function test_ocena_modelem_pyta_baze_tuz_przed_zadaniem_http(string $stan): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor);
        $stary = Post::query()->findOrFail($wpis->getKey());

        match ($stan) {
            'prywatny' => $wpis->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save(),
            'obserwujacy' => $wpis->forceFill(['visibility' => Post::VISIBILITY_FOLLOWERS])->save(),
            'zdjety' => $wpis->forceFill(['status' => Post::STATUS_REMOVED])->save(),
            'ukryty' => $wpis->forceFill(['status' => Post::STATUS_HIDDEN])->save(),
            'szkic' => $wpis->forceFill(['status' => Post::STATUS_DRAFT])->save(),
            'usuniety' => $wpis->delete(),
            'zbanowany' => $autor->forceFill(['status' => User::STATUS_BANNED])->save(),
            'karencja' => $autor->forceFill(['status' => User::STATUS_PENDING_DELETE])->save(),
            default => throw new \LogicException('Nieobsłużony wariant w match.'),
        };

        $this->assertSame(Post::VISIBILITY_PUBLIC, $stary->visibility, 'Obiekt w pamięci ma pamiętać stan sprzed zmiany.');

        app(OcenaModelem::class)->dla($stary);

        Http::assertNothingSent();
    }

    /** Kontrola dodatnia dla tej samej drogi: publiczny wpis, ten sam stary obiekt, wysyłka jest. */
    public function test_ocena_modelem_wysyla_publiczny_wpis(): void
    {
        $wpis = $this->wpis($this->user('autor'));

        app(OcenaModelem::class)->dla(Post::query()->findOrFail($wpis->getKey()));

        Http::assertSentCount(1);
    }

    /** Kontrola dodatnia: bez zmiany ten sam obieg KOŃCZY się wysyłką. */
    public function test_ten_sam_obieg_bez_zmiany_wysyla_tekst(): void
    {
        $wpis = $this->wpis($this->user('autor'));

        $this->uruchom($this->zadanie(PrzeanalizujTresc::TYP_WPIS, $wpis));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), self::ZNACZNIK));
    }

    /** Liczy stan z chwili wysyłki, nie z dodania do kolejki. */
    public function test_decyduje_stan_z_chwili_wysylki_takze_gdy_wpis_stal_sie_publiczny(): void
    {
        $wpis = $this->wpis($this->user('autor'), self::ZNACZNIK.' tekst', ['visibility' => Post::VISIBILITY_PRIVATE]);
        $zadanie = $this->zadanie(PrzeanalizujTresc::TYP_WPIS, $wpis);

        $this->uruchom($zadanie);
        Http::assertNothingSent();

        $wpis->forceFill(['visibility' => Post::VISIBILITY_PUBLIC])->save();
        $this->uruchom($zadanie);

        Http::assertSentCount(1);
    }

    public function test_komentarz_pod_wpisem_zdjetym_po_dodaniu_do_kolejki_nie_wychodzi(): void
    {
        Queue::fake();
        $wpis = $this->wpis($this->user('wlasciciel'));
        $komentarz = app(PublishComment::class)->handle($this->user('komentuje'), $wpis, self::ZNACZNIK.' komentarz');
        $this->assertSame(Comment::STATUS_PUBLISHED, $komentarz->fresh()?->status);

        $zadanie = $this->zadanie(PrzeanalizujTresc::TYP_KOMENTARZ, $komentarz);
        $wpis->forceFill(['status' => Post::STATUS_REMOVED])->save();

        $this->uruchom($zadanie);

        Http::assertNothingSent();
    }

    /**
     * Wpis publiczny z własnym tekstem, który wskazuje przepis prywatny:
     * wychodzi tekst WPISU (jest publiczny), ale nic z przepisu.
     */
    public function test_tresc_przepisu_nie_dolacza_sie_do_publicznego_wpisu(): void
    {
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'private',
            'title' => 'TAJNYPRZEPISTYTUL',
            'summary' => 'TAJNYPRZEPISOPIS',
        ]);
        $wpis = $this->wpis($autor, self::ZNACZNIK.' tekst wpisu', ['recipe_id' => $przepis->getKey()]);

        $this->uruchom($this->zadanie(PrzeanalizujTresc::TYP_WPIS, $wpis));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), self::ZNACZNIK)
            && ! str_contains($r->body(), 'TAJNYPRZEPIS'));
    }
}
