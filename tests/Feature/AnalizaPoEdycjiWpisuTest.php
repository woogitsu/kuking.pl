<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\RestoreContent;
use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Domain\Posts\Actions\EditPost;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\PrzeanalizujTresc;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EDYCJA WPISU PO PUBLIKACJI I PO DECYZJI MODERACJI (issue #936, D-258).
 *
 * 1. Rzeczywista zmiana treści opublikowanego wpisu zleca analizę NOWEJ
 *    wersji (w kolejce, po zatwierdzeniu zapisu). Testy sprawdzają wynik —
 *    pozycję w kolejce automatu z powodem pasującym do nowego tekstu.
 * 2. Wpis ukryty albo usunięty przez moderację nie jest edytowalny, więc
 *    przywrócenie publikuje dokładnie tę treść, o której zdecydowano.
 *
 * Fixture „tel. 600 100 200" to syntetyczny wzorzec lokalnego wykrywacza
 * (D-052). Ocena modelem nie jest tu wołana: bez klucza zwraca pustą listę.
 */
class AnalizaPoEdycjiWpisuTest extends TestCase
{
    use RefreshDatabase;

    private const NEUTRALNY = 'Rosół z kury zagrodowej, gotowany trzy godziny.';

    private const WZORZEC = 'Ciasta na zamówienie, tel. 600 100 200, odbiór osobisty.';

    /** @return array{0: User, 1: Post} */
    private function opublikowanyWpis(string $widocznosc = Post::VISIBILITY_PUBLIC): array
    {
        $autor = $this->user('gospodyni');
        $autor->forceFill(['created_at' => now()->subDays(400)])->save();

        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => self::NEUTRALNY,
            'visibility' => $widocznosc,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return [$autor->refresh(), $wpis];
    }

    private function zadanieDla(Post $wpis): PrzeanalizujTresc
    {
        return new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, (string) $wpis->getKey());
    }

    private function oznaczenia()
    {
        return Report::query()->where('source', Report::SOURCE_AUTOMAT)->get();
    }

    /** @param  array<string, mixed>  $zmiany */
    private function zapisz(User $autor, Post $wpis, array $zmiany = [])
    {
        return $this->actingAs($autor)->put(route('posts.update', $wpis), $zmiany + [
            'body' => $wpis->body,
            'visibility' => $wpis->visibility,
        ]);
    }

    public function test_edycja_po_zakonczonej_analizie_oznacza_nowa_tresc(): void
    {
        [$autor, $wpis] = $this->opublikowanyWpis();

        dispatch_sync($this->zadanieDla($wpis));
        $this->assertCount(0, $this->oznaczenia(), 'Neutralny tekst nie powinien dać oznaczenia.');

        // Kolejka w testach jest `sync`: zadanie zlecone po zatwierdzeniu
        // zapisu wykonuje się w tym żądaniu i widać jego wynik od razu.
        $this->zapisz($autor, $wpis, ['body' => self::WZORZEC])
            ->assertSessionHasNoErrors()
            ->assertRedirect($wpis->url());

        $oznaczenia = $this->oznaczenia();
        $this->assertCount(1, $oznaczenia, 'Nowa treść wpisu nie przeszła przez analizę.');
        $this->assertSame(ModeratedContent::typ($wpis), $oznaczenia->first()->target_type);
        $this->assertSame((string) $wpis->getKey(), (string) $oznaczenia->first()->target_id);
        $this->assertSame(WykrywaczSygnalow::KOD_WZORZEC, $oznaczenia->first()->reason);

        // D-052, D-055: tylko sygnał. Wpis zostaje opublikowany z nową
        // treścią, autor o niczym się nie dowiaduje.
        $wpis->refresh();
        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->status);
        $this->assertSame(self::WZORZEC, $wpis->body);
        $this->assertSame(0, Notification::query()->where('user_id', $autor->getKey())->count());
    }

    public function test_zmiana_tresci_i_tagow_idzie_do_kolejki_low(): void
    {
        Queue::fake();
        [$autor, $wpis] = $this->opublikowanyWpis();

        $this->zapisz($autor, $wpis, ['body' => 'Rosół poprawiony.'])->assertSessionHasNoErrors();
        $this->zapisz($autor, $wpis->fresh(), ['tag_names' => ['zupy']])->assertSessionHasNoErrors();

        Queue::assertPushedOn('low', PrzeanalizujTresc::class, fn (PrzeanalizujTresc $z): bool => $z->typ === PrzeanalizujTresc::TYP_WPIS
            && $z->id === (string) $wpis->getKey());
        Queue::assertPushed(PrzeanalizujTresc::class, 2);
        $this->assertCount(0, $this->oznaczenia(), 'Analiza policzyła się w żądaniu HTTP zamiast w kolejce.');
    }

    public function test_zapis_bez_zmiany_i_zmiana_samej_widocznosci_nie_zlecaja_analizy(): void
    {
        Queue::fake();
        [$autor, $wpis] = $this->opublikowanyWpis();

        // Spacje na końcu obcina akcja — w bazie nic się nie zmienia.
        $this->zapisz($autor, $wpis, ['body' => self::NEUTRALNY.'   '])->assertSessionHasNoErrors();
        $this->zapisz($autor, $wpis, ['visibility' => Post::VISIBILITY_FOLLOWERS])->assertSessionHasNoErrors();

        $this->assertSame(Post::VISIBILITY_FOLLOWERS, $wpis->fresh()->visibility);
        Queue::assertNotPushed(PrzeanalizujTresc::class);
    }

    public function test_wpis_wychodzacy_z_prywatnych_jest_analizowany_pierwszy_raz(): void
    {
        [$autor, $wpis] = $this->opublikowanyWpis(Post::VISIBILITY_PRIVATE);
        $wpis->forceFill(['body' => self::WZORZEC])->save();

        // Analiza przy publikacji nie ogląda prywatnego wpisu.
        dispatch_sync($this->zadanieDla($wpis));
        $this->assertCount(0, $this->oznaczenia());

        $this->zapisz($autor, $wpis->fresh(), ['visibility' => Post::VISIBILITY_PUBLIC])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $this->oznaczenia(), 'Wpis pokazany ludziom po raz pierwszy nie przeszedł analizy.');
    }

    public function test_zadanie_z_publikacji_czekajace_w_kolejce_i_szybkie_poprawki_daja_jedno_oznaczenie_najnowszej_wersji(): void
    {
        Queue::fake();
        [$autor, $wpis] = $this->opublikowanyWpis();
        $zPublikacji = $this->zadanieDla($wpis);

        $this->zapisz($autor, $wpis, ['body' => 'Rosół na niedzielę.']);
        $this->zapisz($autor, $wpis->fresh(), ['body' => self::WZORZEC]);

        $zEdycji = [];
        Queue::assertPushed(PrzeanalizujTresc::class, function (PrzeanalizujTresc $z) use (&$zEdycji): bool {
            $zEdycji[] = $z;

            return true;
        });
        $this->assertCount(2, $zEdycji, 'Jedno zadanie na każdą rzeczywistą zmianę.');

        // Najstarsze zadanie wykonuje się jako pierwsze, a mimo to ocenia
        // NAJNOWSZY tekst — czyta wpis po ID w chwili wykonania.
        app()->call([$zPublikacji, 'handle']);
        $this->assertSame(WykrywaczSygnalow::KOD_WZORZEC, $this->oznaczenia()->first()?->reason);

        collect($zEdycji)->each(fn (PrzeanalizujTresc $z) => app()->call([$z, 'handle']));
        $this->assertCount(1, $this->oznaczenia(), 'Kilka zadań dla jednego wpisu nie może zasypać kolejki moderatora.');
    }

    /** @return array<string, array{0: string}> */
    public static function decyzjeModeracji(): array
    {
        return ['ukryty' => [Post::STATUS_HIDDEN], 'usunięty' => [Post::STATUS_REMOVED]];
    }

    #[DataProvider('decyzjeModeracji')]
    public function test_wpisu_pod_decyzja_moderacji_nie_da_sie_poprawic(string $status): void
    {
        Queue::fake();
        [$autor, $wpis] = $this->opublikowanyWpis();
        $wpis->forceFill(['status' => $status])->save();

        $this->actingAs($autor)->get(route('posts.edit', $wpis))
            ->assertRedirect($wpis->url())
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'odwołaj się'));

        // Przekierowanie nie może skończyć się 403 ani zgubić komunikatu:
        // autor widzi własny wpis pod decyzją razem ze zdaniem, co zrobić.
        $this->actingAs($autor)->followingRedirects()->get(route('posts.edit', $wpis))
            ->assertOk()
            ->assertSee('odwołaj się od decyzji', false);

        // Tekst wpisany mimo to nie znika — wraca do skopiowania.
        $this->zapisz($autor, $wpis, ['body' => 'Moja poprawka po decyzji.'])
            ->assertForbidden()
            ->assertSee('Moja poprawka po decyzji.')
            ->assertSee('odwołaj się');

        $this->assertSame(self::NEUTRALNY, $wpis->fresh()->body);
        $this->assertSame($status, $wpis->fresh()->status);
        $this->assertFalse($autor->can('update', $wpis->fresh()));
        Queue::assertNotPushed(PrzeanalizujTresc::class);
    }

    public function test_ukrycie_miedzy_policy_a_blokada_wiersza_zatrzymuje_zapis(): void
    {
        [$autor, $wpis] = $this->opublikowanyWpis();

        // Model przeczytany przed decyzją — jak w żądaniu, które weszło
        // do akcji, zanim moderator kliknął „Ukryj".
        $sprzedDecyzji = $wpis->fresh();
        Post::query()->whereKey($wpis->getKey())->update(['status' => Post::STATUS_HIDDEN]);

        try {
            app(EditPost::class)->handle($autor, $sprzedDecyzji, self::WZORZEC, Post::VISIBILITY_PUBLIC);
            $this->fail('Zapis przeszedł mimo decyzji moderacji widocznej pod blokadą.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(EditPost::KOMUNIKAT_POD_DECYZJA, $e->getMessage());
        }

        $this->assertSame(self::NEUTRALNY, $wpis->fresh()->body);
    }

    public function test_przywrocenie_publikuje_tresc_z_chwili_decyzji(): void
    {
        $moderator = $this->moderator();
        [$autor, $wpis] = $this->opublikowanyWpis();
        $report = Report::create([
            'reporter_id' => $this->user('zglasza')->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);
        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam-reklama',
                'user_message' => 'Wpis wygląda na reklamę.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(Post::STATUS_HIDDEN, $wpis->fresh()->status);

        // Autor próbuje podmienić ocenioną treść przed przywróceniem.
        $this->zapisz($autor, $wpis->fresh(), ['body' => self::WZORZEC])->assertForbidden();

        app(RestoreContent::class)->handle($moderator, $wpis->fresh(), 'autor_poprawil');

        $wpis->refresh();
        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->status);
        $this->assertSame(self::NEUTRALNY, $wpis->body);
    }

    public function test_kontrola_dodatnia_autor_opublikowanego_wpisu_nadal_go_poprawia(): void
    {
        [$autor, $wpis] = $this->opublikowanyWpis();

        $this->assertTrue($autor->can('update', $wpis));
        $this->actingAs($autor)->get(route('posts.edit', $wpis))->assertOk();
        $this->zapisz($autor, $wpis, ['body' => 'Rosół, poprawka.'])->assertRedirect($wpis->url());
        $this->assertSame('Rosół, poprawka.', $wpis->fresh()->body);
    }
}
