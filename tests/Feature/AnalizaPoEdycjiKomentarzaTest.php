<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ANALIZA NOWEJ TREŚCI PO EDYCJI KOMENTARZA (issue #909, D-256).
 *
 * Luka: publikacja → zakończona analiza → poprawka w 15-minutowym oknie.
 * Automat oceniał tylko pierwszy tekst. Te testy sprawdzają WYNIK
 * (pozycję w kolejce automatu z powodem pasującym do nowego tekstu),
 * a nie sam fakt wysłania zadania.
 *
 * Fixture „tel. 600 100 200" to syntetyczny wzorzec lokalnego wykrywacza
 * (D-052). Ocena modelem nie jest tu wołana: bez klucza zwraca pustą listę.
 */
class AnalizaPoEdycjiKomentarzaTest extends TestCase
{
    use RefreshDatabase;

    private const NEUTRALNY = 'Pyszny rosół, dziękuję za przepis.';

    private const WZORZEC = 'Ciasta na zamówienie, tel. 600 100 200, odbiór osobisty.';

    private function osoba(string $login): User
    {
        $user = $this->user($login);
        $user->forceFill(['created_at' => now()->subDays(400)])->save();

        return $user->refresh();
    }

    /** @return array{0: User, 1: Comment} */
    private function opublikowanyKomentarz(): array
    {
        $gospodyni = $this->osoba('gospodyni');
        $autor = $this->osoba('komentujacy');

        $post = Post::create([
            'author_id' => $gospodyni->getKey(),
            'body' => 'Rosół z kury zagrodowej.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $komentarz = Comment::create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
            'body' => self::NEUTRALNY,
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        return [$autor, $komentarz];
    }

    private function zadanieDla(Comment $komentarz): PrzeanalizujTresc
    {
        return new PrzeanalizujTresc(PrzeanalizujTresc::TYP_KOMENTARZ, (string) $komentarz->getKey());
    }

    private function oznaczenia()
    {
        return Report::query()->where('source', Report::SOURCE_AUTOMAT)->get();
    }

    public function test_edycja_po_zakonczonej_analizie_oznacza_nowa_tresc(): void
    {
        [$autor, $komentarz] = $this->opublikowanyKomentarz();

        // Pierwsza analiza skończyła się, zanim autor poprawił tekst.
        dispatch_sync($this->zadanieDla($komentarz));
        $this->assertCount(0, $this->oznaczenia(), 'Neutralny tekst nie powinien dać oznaczenia.');

        // Kolejka w testach jest `sync`: zadanie zlecone przez edycję
        // wykonuje się w tym żądaniu i widać jego wynik od razu.
        $this->actingAs($autor)
            ->put(route('comments.update', $komentarz), ['body' => self::WZORZEC])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $oznaczenia = $this->oznaczenia();
        $this->assertCount(1, $oznaczenia, 'Nowa treść komentarza nie przeszła przez analizę.');
        $this->assertSame(ModeratedContent::typ($komentarz), $oznaczenia->first()->target_type);
        $this->assertSame((string) $komentarz->getKey(), (string) $oznaczenia->first()->target_id);
        $this->assertSame(WykrywaczSygnalow::KOD_WZORZEC, $oznaczenia->first()->reason);

        // D-052, D-055: to tylko sygnał dla moderatora. Komentarz zostaje
        // opublikowany z nową treścią, autor o niczym się nie dowiaduje.
        $komentarz->refresh();
        $this->assertSame(Comment::STATUS_PUBLISHED, $komentarz->status);
        $this->assertSame(self::WZORZEC, $komentarz->body);
        $this->assertSame(0, Notification::query()->where('user_id', $autor->getKey())->count());
    }

    public function test_edycja_idzie_do_kolejki_a_nie_do_zadania_http(): void
    {
        Queue::fake();
        [$autor, $komentarz] = $this->opublikowanyKomentarz();

        $this->actingAs($autor)
            ->put(route('comments.update', $komentarz), ['body' => self::WZORZEC])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            PrzeanalizujTresc::class,
            static fn (PrzeanalizujTresc $zadanie): bool => $zadanie->connection !== 'sync'
                && $zadanie->typ === PrzeanalizujTresc::TYP_KOMENTARZ
                && $zadanie->id === (string) $komentarz->getKey(),
        );
        Queue::assertPushed(PrzeanalizujTresc::class, 1);
        $this->assertCount(0, $this->oznaczenia(), 'Analiza policzyła się w żądaniu HTTP zamiast w kolejce.');
    }

    public function test_pierwsze_zadanie_jeszcze_czeka_i_oba_daja_jedno_oznaczenie_nowej_tresci(): void
    {
        Queue::fake();
        [$autor, $komentarz] = $this->opublikowanyKomentarz();

        // Zadanie z publikacji stoi w kolejce; autor poprawia tekst dwa razy
        // pod rząd, zanim worker do niego dojdzie.
        $zPublikacji = $this->zadanieDla($komentarz);

        $this->actingAs($autor)->put(route('comments.update', $komentarz), ['body' => 'Pyszny rosół!']);
        $this->actingAs($autor)->put(route('comments.update', $komentarz), ['body' => self::WZORZEC]);

        // Jedno zadanie na każdą rzeczywistą zmianę — liczba ograniczona
        // oknem 15 minut i limitem trasy.
        $zEdycji = [];
        Queue::assertPushed(PrzeanalizujTresc::class, function (PrzeanalizujTresc $z) use (&$zEdycji): bool {
            $zEdycji[] = $z;

            return true;
        });
        $this->assertCount(2, $zEdycji);

        // Worker wykonuje wszystko po kolei. Każde zadanie czyta komentarz
        // po ID, więc każde widzi NAJNOWSZY tekst. `handle()` przez
        // kontener, bo `dispatch_sync()` przy `Queue::fake()` tylko się
        // zapisuje i niczego nie wykonuje.
        collect([$zPublikacji])
            ->concat($zEdycji)
            ->each(fn (PrzeanalizujTresc $z) => app()->call([$z, 'handle']));

        $oznaczenia = $this->oznaczenia();
        $this->assertCount(1, $oznaczenia, 'Kilka zadań dla jednego komentarza nie może zasypać kolejki moderatora.');
        $this->assertSame(WykrywaczSygnalow::KOD_WZORZEC, $oznaczenia->first()->reason);
    }

    public function test_zapis_bez_zmiany_tresci_nie_zleca_analizy(): void
    {
        Queue::fake();
        [$autor, $komentarz] = $this->opublikowanyKomentarz();

        // Spacje na końcu obcina kontroler — w bazie nic się nie zmienia.
        $this->actingAs($autor)
            ->put(route('comments.update', $komentarz), ['body' => self::NEUTRALNY.'   '])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Queue::assertNotPushed(PrzeanalizujTresc::class);
    }

    public function test_wylacznik_automatu_zatrzymuje_tez_analize_po_edycji(): void
    {
        config(['kuking.moderation.sygnaly.wlaczone' => false]);
        [$autor, $komentarz] = $this->opublikowanyKomentarz();

        $this->actingAs($autor)
            ->put(route('comments.update', $komentarz), ['body' => self::WZORZEC])
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $this->oznaczenia());
        $this->assertSame(self::WZORZEC, $komentarz->fresh()->body);
    }

    public function test_odrzucone_oznaczenie_nie_wraca_po_edycji(): void
    {
        $moderator = $this->moderator();
        [$autor, $komentarz] = $this->opublikowanyKomentarz();

        $komentarz->forceFill(['body' => self::WZORZEC])->save();
        dispatch_sync($this->zadanieDla($komentarz));
        $this->assertCount(1, $this->oznaczenia());

        $this->actingAs($moderator)
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey()])
            ->assertSessionHasNoErrors();

        $this->actingAs($autor)
            ->put(route('comments.update', $komentarz), ['body' => 'Zarabiaj z domu, tel. 600 100 201.'])
            ->assertSessionHasNoErrors();

        // „To nic takiego" zamyka sprawę na zawsze (D-052). Edycja nie
        // otwiera jej drugi raz i nie stawia drugiej pozycji.
        $this->assertCount(1, $this->oznaczenia());
        $this->assertSame(Report::STATUS_REJECTED, $this->oznaczenia()->first()->status);
    }
}
