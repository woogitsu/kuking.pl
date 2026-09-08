<?php

declare(strict_types=1);

namespace Tests\Audit;

use App\Domain\Analytics\CookRetentionCohorts;
use App\Domain\Media\DostepDoZdjecia;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tymczasowe sondy audytowe: nie sa poprawkami ani zamiennikiem testow projektu. */
class AuditProbeTest extends TestCase
{
    use RefreshDatabase;

    private function pomiar(string $nazwa, array $dane): void
    {
        file_put_contents(base_path('audit-evidence/probes.jsonl'), json_encode(['probe' => $nazwa, 'data' => $dane], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
    }

    public function test_kontrola_zwykle_ugotowanie_powiadamia_autora(): void
    {
        $autor = $this->user('audit_autor');
        $kucharz = $this->user('audit_kucharz');
        $przepis = Recipe::factory()->create(['author_id' => $autor->id]);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $przepis, note: 'Proba kontrolna');
        $n = Notification::where('user_id', $autor->id)->where('type', Notification::TYPE_COOKED)->count();
        $this->pomiar('cooked_healthy_control', ['events' => CookedEvent::whereKey($event->id)->count(), 'notifications' => $n]);
        $this->assertSame(1, $n);
    }

    public function test_powtorzenie_po_awarii_powiadomienia_nie_gubi_petli(): void
    {
        $autor = $this->user('audit_autor');
        $kucharz = $this->user('audit_kucharz');
        $przepis = Recipe::factory()->create(['author_id' => $autor->id]);
        $klucz = (string) Str::uuid();
        $uzbrojona = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$uzbrojona): void {
            if ($uzbrojona && str_starts_with(strtolower($sql), 'insert into "notifications"')) {
                $uzbrojona = false;
                throw new \RuntimeException('AUDYT: jednorazowa awaria przed zapisem powiadomienia');
            }
        });
        $blad = null;
        try {
            app(RecordCookedEvent::class)->handle($kucharz, $przepis, note: 'To ugotowalem', kluczWyslania: $klucz);
        } catch (\RuntimeException $e) {
            $blad = $e->getMessage();
        } finally {
            $uzbrojona = false;
        }
        $przed = CookedEvent::where('user_id', $kucharz->id)->count();
        app(RecordCookedEvent::class)->handle($kucharz, $przepis, note: 'To ugotowalem', kluczWyslania: $klucz);
        $n = Notification::where('user_id', $autor->id)->where('type', Notification::TYPE_COOKED)->count();
        $this->pomiar('cooked_failure_then_retry', ['injected_failure_seen' => $blad !== null, 'events_before_retry' => $przed, 'events_after_retry' => CookedEvent::where('user_id', $kucharz->id)->count(), 'notifications_after_retry' => $n]);
        $this->assertNotNull($blad, 'Sonda musi dotknac zapisu powiadomienia');
        $this->assertSame(1, $n, 'Ponowienie musi doprowadzic do powiadomienia autora');
    }

    public function test_usuniecie_konta_usuwa_relacje_i_sekrety_2fa(): void
    {
        $user = $this->user('audit_usuwany');
        $b = $this->user('audit_obserwowany');
        $c = $this->user('audit_blokowany');
        DB::table('follows')->insert(['follower_id' => $user->id, 'followed_id' => $b->id, 'created_at' => now()]);
        DB::table('blocks')->insert(['blocker_id' => $user->id, 'blocked_id' => $c->id, 'created_at' => now()]);
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_backup_codes' => ['audit-hash'], 'two_factor_confirmed_at' => now()])->save();
        $user->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
        $this->assertTrue(app(EraseAccountData::class)->handle($user));
        $user->refresh();
        $dane = ['status' => $user->status, 'follows' => DB::table('follows')->where('follower_id', $user->id)->count(), 'blocks' => DB::table('blocks')->where('blocker_id', $user->id)->count(), 'two_factor_secret_present' => $user->two_factor_secret !== null, 'backup_codes_present' => $user->two_factor_backup_codes !== null];
        $this->pomiar('account_erasure_residue', $dane);
        $this->assertSame(0, $dane['follows'] + $dane['blocks'], 'Polityka obiecuje koniec retencji relacji przy usunieciu konta');
        $this->assertFalse($dane['two_factor_secret_present']);
    }

    public function test_pending_delete_nie_pozostawia_wykonania_i_zdjecia_publicznego(): void
    {
        $autor = $this->user('audit_autor');
        $cook = $this->user('audit_kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->id]);
        $event = CookedEvent::factory()->create(['user_id' => $cook->id, 'recipe_id' => $recipe->id, 'note' => 'AUDYT-prywatna-notatka']);
        $media = Media::factory()->create(['owner_id' => $cook->id]);
        $event->media()->attach($media->id, ['position' => 0]);
        $cook->markForDeletion();
        $response = $this->get(route('cooked.show', $event));
        $publiczne = app(DostepDoZdjecia::class)->moze(null, $media);
        $this->pomiar('pending_delete_cooked', ['status' => $cook->fresh()->status, 'guest_http' => $response->getStatusCode(), 'note_in_html' => str_contains($response->getContent(), 'AUDYT-prywatna-notatka'), 'guest_media_allowed' => $publiczne, 'listed' => CookedEvent::widoczneDla(null)->whereKey($event->id)->exists()]);
        $this->assertFalse($publiczne, 'Sprawdzamy roznice miedzy lista a bezposrednim adresem w karencji');
    }

    public function test_akcja_domenowa_respektuje_policy_prywatnego_przepisu(): void
    {
        $autor = $this->user('audit_autor');
        $cook = $this->user('audit_kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->id, 'visibility' => 'private']);
        $allowed = Gate::forUser($cook)->allows('cook', $recipe);
        $exception = null;
        try {
            app(RecordCookedEvent::class)->handle($cook, $recipe);
        } catch (\Throwable $e) {
            $exception = get_class($e);
        }
        $n = CookedEvent::where('user_id', $cook->id)->count();
        $this->pomiar('domain_private_recipe', ['policy_allows' => $allowed, 'created' => $n, 'exception' => $exception]);
        $this->assertFalse($allowed);
        $this->assertSame(0, $n, 'To sonda granicy wewnetrznej, nie dowod publicznego IDOR');
    }

    public function test_retencja_wedlug_zadeklarowanego_wzoru_nie_przekracza_stu_procent(): void
    {
        config(['kuking.community.host_username' => '', 'kuking.account.test_usernames' => []]);
        $a = $this->user('audit_a', ['created_at' => '2026-06-01 12:00:00']);
        $b = $this->user('audit_b', ['created_at' => '2026-06-01 12:00:00']);
        $c = $this->user('audit_c', ['created_at' => '2026-06-01 12:00:00']);
        Post::factory()->create(['author_id' => $a->id, 'published_at' => '2026-06-02 12:00:00']);
        Post::factory()->create(['author_id' => $b->id, 'published_at' => '2026-06-30 12:00:00']);
        Post::factory()->create(['author_id' => $c->id, 'published_at' => '2026-06-30 12:00:00']);
        $rows = app(CookRetentionCohorts::class)->weekly();
        $w0 = (int) $rows->firstWhere('week_offset', 0)->active_users;
        $w4 = (int) $rows->firstWhere('week_offset', 4)->active_users;
        $this->pomiar('cohort_denominator', ['registered' => 3, 'rows' => $rows->all(), 'week4_divided_by_week0_percent' => 100 * $w4 / $w0]);
        $this->assertLessThanOrEqual($w0, $w4, 'Wzor z komentarza klasy i dokumentacji nie opisuje retencji kohorty');
    }

    public function test_429_przechowuje_poprawny_tekst_i_zdjecie(): void
    {
        Queue::fake();
        Storage::fake('public');
        $user = $this->user('audit_limit');
        $this->actingAs($user);
        $limit = (int) explode(',', (string) config('kuking.limits.post'))[0];
        for ($i = 0; $i < $limit; $i++) {
            $response = $this->post(route('posts.store'), ['body' => 'Proba '.$i, 'visibility' => 'public']);
            $this->assertNotSame(429, $response->getStatusCode());
        }
        $marker = 'AUDYT-NIE-TRAC-TEGO-TEKSTU';
        $response = $this->from('/dodaj/zdjecie')->post(route('posts.store'), ['body' => $marker, 'visibility' => 'public', 'photos' => [UploadedFile::fake()->image('obiad.jpg', 32, 32)]]);
        $this->assertSame(429, $response->getStatusCode());
        $dane = ['http' => $response->getStatusCode(), 'old_body' => session()->getOldInput('body'), 'text_in_response' => str_contains($response->getContent(), $marker), 'media_rows' => Media::where('owner_id', $user->id)->count(), 'posts_containing_marker' => Post::where('body', $marker)->count()];
        $this->pomiar('throttle_preserves_input', $dane);
        file_put_contents(base_path('audit-evidence/429.html'), $response->getContent());
        $this->assertSame($marker, $dane['old_body'], 'Poprawne dane maja przezyc odmowe limitera');
    }
}
