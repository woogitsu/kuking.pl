<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Moderation\UzasadnienieDecyzji;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Appeal;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * „Zdejmij z urzędu” — treść bez zgłoszenia (G31, D-251).
 *
 * Kontrole ujemne (zepsuć → test oblewa → przywrócić):
 *  - `UserPolicy::takeDownContentOf()` bez `hasTwoFactorConfirmed()` —
 *    test bez 2FA widzi przycisk (`test_przycisk_przy_tresci…`);
 *  - `>` zamienione na `>=` w porównaniu rang — oblewa test treści
 *    administratora i drugiego moderatora;
 *  - `ZdejmijTresc` z powrotem na gołe `delete()` — oblewają oba testy
 *    komentarza z odpowiedziami (także droga ze zgłoszenia).
 */
class ZdejmijZUrzeduTest extends TestCase
{
    use RefreshDatabase;

    private const UZASADNIENIE = 'Wpis reklamuje sklep z suplementami i nie ma nic wspólnego z gotowaniem.';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /** @return array<string, array{0: string}> */
    public static function typy(): array
    {
        return [
            'wpis' => ['post'],
            'przepis' => ['recipe'],
            'komentarz' => ['comment'],
        ];
    }

    #[DataProvider('typy')]
    public function test_moderator_z_2fa_zdejmuje_niezgloszony_spam(string $typ): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('spamer');
        $tresc = $this->tresc($typ, $autor);

        $this->actingAs($moderator)
            ->get(route('admin.z-urzedu.create', ['typ' => $typ, 'id' => $tresc->getKey()]))
            ->assertOk()
            ->assertSee('Podstawa decyzji')
            ->assertSee('Uzasadnienie dla autora');

        $this->actingAs($moderator)
            ->post($this->adres($typ, $tresc), $this->dane())
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($tresc);
        $this->assertSame(0, Report::count(), 'Decyzja z urzędu nie tworzy zgłoszenia.');

        $decyzja = ModerationAction::sole();
        $this->assertNull($decyzja->report_id);
        $this->assertSame($moderator->getKey(), $decyzja->moderator_id);
        $this->assertSame($autor->getKey(), $decyzja->subject_user_id);
        $this->assertSame($typ, $decyzja->target_type);
        $this->assertSame((string) $tresc->getKey(), (string) $decyzja->target_id);
        $this->assertSame(ModerationAction::ACTION_REMOVE, $decyzja->action);
        $this->assertSame('spam-reklama', $decyzja->reason_code);
        $this->assertSame(self::UZASADNIENIE, $decyzja->user_message);

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_MODERATION)
            ->sole();
        $this->assertSame(self::UZASADNIENIE, $powiadomienie->data['message']);
        $this->assertSame($decyzja->getKey(), $powiadomienie->data['action_id']);
        $this->assertTrue($powiadomienie->data['appeal']);

        $this->assertContains(
            'Nikt tego nie zgłosił — sprawę znaleźliśmy sami, przeglądając serwis.',
            UzasadnienieDecyzji::zdania($decyzja),
        );

        $this->assertDatabaseHas('audit_log', [
            'action' => 'moderation.ex_officio',
            'actor_id' => $moderator->getKey(),
            'subject_id' => $decyzja->getKey(),
        ]);
    }

    public function test_moderator_bez_2fa_nie_wchodzi_ani_nie_zdejmuje(): void
    {
        $bez2fa = $this->user(null, ['role' => User::ROLE_MODERATOR]);
        $post = $this->tresc('post', $this->user('autor'));

        $this->actingAs($bez2fa)
            ->get(route('admin.z-urzedu.create', ['typ' => 'post', 'id' => $post->getKey()]))
            ->assertForbidden();
        $this->actingAs($bez2fa)
            ->post($this->adres('post', $post), $this->dane())
            ->assertForbidden();

        $this->assertNotSoftDeleted($post);
        $this->assertSame(0, ModerationAction::count());
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_MODERATION)->count());
    }

    public function test_zwykly_uzytkownik_dostaje_404(): void
    {
        $post = $this->tresc('post', $this->user('autor'));

        $this->actingAs($this->user('ciekawski'))
            ->post($this->adres('post', $post), $this->dane())
            ->assertNotFound();

        $this->assertNotSoftDeleted($post);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rownaLubWyzszaRola(): array
    {
        return [
            'moderator → treść administratora' => ['moderator', User::ROLE_ADMIN],
            'moderator → treść innego moderatora' => ['moderator', User::ROLE_MODERATOR],
            'administrator → treść innego administratora' => ['admin', User::ROLE_ADMIN],
        ];
    }

    #[DataProvider('rownaLubWyzszaRola')]
    public function test_nie_zdejmuje_tresci_konta_rownej_lub_wyzszej_roli(string $kto, string $rolaAutora): void
    {
        $aktor = $kto === 'admin' ? $this->admin() : $this->moderator();
        $autor = $this->user('autor', ['role' => $rolaAutora]);
        $post = $this->tresc('post', $autor);

        $this->actingAs($aktor)
            ->get(route('admin.z-urzedu.create', ['typ' => 'post', 'id' => $post->getKey()]))
            ->assertForbidden();
        $this->actingAs($aktor)
            ->post($this->adres('post', $post), $this->dane())
            ->assertForbidden();

        $this->assertNotSoftDeleted($post);
        $this->assertSame(0, ModerationAction::count());
    }

    public function test_administrator_zdejmuje_tresc_moderatora(): void
    {
        // Kontrola dodatnia do testu wyżej: reguła rangi nie blokuje wszystkiego.
        $post = $this->tresc('post', $this->user('mod', ['role' => User::ROLE_MODERATOR]));

        $this->actingAs($this->admin())
            ->post($this->adres('post', $post), $this->dane())
            ->assertRedirect(route('admin.reports'));

        $this->assertSoftDeleted($post);
    }

    public function test_wlasnej_tresci_nie_zdejmuje_z_urzedu(): void
    {
        $moderator = $this->moderator();
        $post = $this->tresc('post', $moderator);

        $this->actingAs($moderator)->post($this->adres('post', $post), $this->dane())->assertForbidden();

        $this->assertNotSoftDeleted($post);
    }

    public function test_bez_uzasadnienia_i_podstawy_spoza_listy_nic_sie_nie_dzieje_a_dane_zostaja(): void
    {
        $moderator = $this->moderator();
        $post = $this->tresc('post', $this->user('autor'));
        $formularz = route('admin.z-urzedu.create', ['typ' => 'post', 'id' => $post->getKey()]);

        $this->actingAs($moderator)
            ->from($formularz)
            ->post($this->adres('post', $post), [
                'reason_code' => 'wymyslony-powod',
                'user_message' => '',
                'note' => 'Notatka, która ma przetrwać.',
            ])
            ->assertRedirect($formularz)
            ->assertSessionHasErrors(['reason_code', 'user_message'])
            ->assertSessionHasInput('note', 'Notatka, która ma przetrwać.');

        $this->assertNotSoftDeleted($post);
        $this->assertSame(0, ModerationAction::count());
    }

    public function test_tresc_z_otwartym_zgloszeniem_idzie_przez_kolejke(): void
    {
        $post = $this->tresc('post', $this->user('autor'));
        Report::create([
            'reporter_id' => $this->user('zglasza')->getKey(),
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($this->moderator())
            ->post($this->adres('post', $post), $this->dane())
            ->assertSessionHasErrors('reason_code');

        $this->assertNotSoftDeleted($post);
        $this->assertSame(0, ModerationAction::count());
    }

    public function test_ugotowania_nie_zdejmuje_sie_z_urzedu(): void
    {
        // `cooked_events` nie ma soft delete — „cofam” po odwołaniu nie
        // miałoby czego przywrócić (D-251).
        $event = CookedEvent::factory()->create(['user_id' => $this->user('kucharz')->getKey()]);
        $moderator = $this->moderator();

        $this->assertFalse($moderator->can('removeExOfficio', $event));
        $this->actingAs($moderator)
            ->post('/admin/z-urzedu/cooked_event/'.$event->getKey(), $this->dane())
            ->assertNotFound();

        $this->assertModelExists($event);
    }

    public function test_odwolanie_autora_dziala_i_cofniecie_przywraca_wpis(): void
    {
        $autor = $this->user('autor');
        $post = $this->tresc('post', $autor);

        $this->actingAs($this->moderator())->post($this->adres('post', $post), $this->dane());
        $decyzja = ModerationAction::sole();

        $this->actingAs($autor)
            ->get(route('appeals.show', $decyzja))
            ->assertOk();
        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), ['body' => 'To mój przepis na koktajl, nie reklama. Proszę o sprawdzenie.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $odwolanie = Appeal::sole();
        $this->assertSame($decyzja->getKey(), $odwolanie->moderation_action_id);

        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, to przepis. Wpis wraca na miejsce.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted($post);
        $this->assertSame(Post::STATUS_PUBLISHED, $post->fresh()->status);
    }

    public function test_komentarz_z_odpowiedziami_zostawia_napis_a_odwolanie_przywraca_tekst(): void
    {
        $autor = $this->user('autor');
        $komentarz = $this->tresc('comment', $autor);
        $tekst = $komentarz->body;
        $odpowiedz = Comment::factory()->create([
            'post_id' => $komentarz->post_id,
            'parent_id' => $komentarz->getKey(),
        ]);

        $this->actingAs($this->moderator())
            ->post($this->adres('comment', $komentarz), $this->dane())
            ->assertSessionHasNoErrors();

        $swiezy = $komentarz->fresh();
        $this->assertNotNull($swiezy, 'Komentarz z odpowiedziami zniknął — wątek się rozsypał.');
        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $swiezy->body);
        $this->assertNotNull($swiezy->body_removed_at);
        $this->assertNotSoftDeleted($odpowiedz);

        $decyzja = ModerationAction::sole();
        $this->assertSame($tekst, $decyzja->tresc_sprzed_zdjecia);

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), ['body' => 'To nie był spam, tylko pytanie o przepis.']);
        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, komentarz wraca.',
            ])
            ->assertSessionHasNoErrors();

        $przywrocony = $komentarz->fresh();
        $this->assertSame($tekst, $przywrocony->body);
        $this->assertNull($przywrocony->body_removed_at);
    }

    public function test_decyzja_usun_ze_zgloszenia_tez_nie_rozsypuje_watku(): void
    {
        // Regresja: `ModerationController::applyAction()` robił na
        // komentarzu z odpowiedziami zwykły soft delete.
        $komentarz = $this->tresc('comment', $this->user('autor'));
        $odpowiedz = Comment::factory()->create([
            'post_id' => $komentarz->post_id,
            'parent_id' => $komentarz->getKey(),
        ]);
        $report = Report::create([
            'reporter_id' => $this->user('zglasza')->getKey(),
            'target_type' => 'comment',
            'target_id' => $komentarz->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_REMOVE,
                'reason_code' => 'spam-reklama',
                'user_message' => 'Komentarz był reklamą.',
            ])
            ->assertSessionHasNoErrors();

        $swiezy = $komentarz->fresh();
        $this->assertNotNull($swiezy);
        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $swiezy->body);
        $this->assertNotSoftDeleted($odpowiedz);
    }

    public function test_przycisk_przy_tresci_widza_tylko_uprawnieni(): void
    {
        $autor = $this->user('autor');
        $post = $this->tresc('post', $autor);
        $wpisAdmina = $this->tresc('post', $this->user('szef', ['role' => User::ROLE_ADMIN]));
        $adres = route('admin.z-urzedu.create', ['typ' => 'post', 'id' => $post->getKey()]);

        $this->actingAs($this->moderator())
            ->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee($adres, false)
            ->assertSee('Zdejmij z urzędu');

        foreach ([
            'moderator bez 2FA' => $this->user(null, ['role' => User::ROLE_MODERATOR]),
            'zwykła osoba' => $this->user('czytelnik'),
            'autor' => $autor,
        ] as $kto => $widz) {
            $this->actingAs($widz)
                ->get(route('posts.show', $post))
                ->assertOk()
                ->assertDontSee('Zdejmij z urzędu', false);
        }

        $this->actingAs($this->moderator())
            ->get(route('posts.show', $wpisAdmina))
            ->assertOk()
            ->assertDontSee('Zdejmij z urzędu', false);
    }

    public function test_przycisk_stoi_tez_przy_przepisie_i_komentarzu(): void
    {
        $moderator = $this->moderator();
        $przepis = $this->tresc('recipe', $this->user('kucharka'));
        $komentarz = $this->tresc('comment', $this->user('gadula'));

        $this->actingAs($moderator)
            ->get($przepis->url())
            ->assertOk()
            ->assertSee(route('admin.z-urzedu.create', ['typ' => 'recipe', 'id' => $przepis->getKey()]), false);

        $this->actingAs($moderator)
            ->get(route('posts.show', $komentarz->post_id))
            ->assertOk()
            ->assertSee(route('admin.z-urzedu.create', ['typ' => 'comment', 'id' => $komentarz->getKey()]), false);
    }

    public function test_usuniecie_konta_z_zakresem_wszystko_czysci_kopie_tekstu(): void
    {
        $autor = $this->user('odchodzi');
        $komentarz = $this->tresc('comment', $autor);
        Comment::factory()->create(['post_id' => $komentarz->post_id, 'parent_id' => $komentarz->getKey()]);

        $this->actingAs($this->moderator())->post($this->adres('comment', $komentarz), $this->dane());
        $this->assertNotNull(ModerationAction::sole()->tresc_sprzed_zdjecia);

        $autor->forceFill([
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_EVERYTHING,
        ])->save();

        $this->assertTrue(app(EraseAccountData::class)->handle($autor->fresh()));

        $this->assertNull(ModerationAction::sole()->tresc_sprzed_zdjecia, 'Kopia tekstu przetrwała „usuń wszystko”.');
    }

    public function test_cofniecie_migracji_odmawia_przy_zapisanym_tekscie_a_bez_niego_przechodzi(): void
    {
        $sciezka = 'database/migrations/2026_09_23_200000_add_tresc_sprzed_zdjecia_to_moderation_actions.php';

        // Kontrola dodatnia: bez zapisanych tekstów rollback przechodzi.
        Artisan::call('migrate:rollback', ['--path' => $sciezka, '--realpath' => false]);
        $this->assertFalse(\Schema::hasColumn('moderation_actions', 'tresc_sprzed_zdjecia'));
        Artisan::call('migrate', ['--path' => $sciezka, '--realpath' => false]);

        $komentarz = $this->tresc('comment', $this->user('autor'));
        Comment::factory()->create(['post_id' => $komentarz->post_id, 'parent_id' => $komentarz->getKey()]);
        $this->actingAs($this->moderator())->post($this->adres('comment', $komentarz), $this->dane());

        try {
            Artisan::call('migrate:rollback', ['--path' => $sciezka, '--realpath' => false]);
            $this->fail('Rollback przeszedł mimo zapisanego tekstu zdjętego komentarza.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_TRESC_ZDJETYCH_KOMENTARZY', $e->getMessage());
        }

        $this->assertNotNull(ModerationAction::sole()->tresc_sprzed_zdjecia);
    }

    /** @return array<string, string> */
    private function dane(): array
    {
        return [
            'reason_code' => 'spam-reklama',
            'user_message' => self::UZASADNIENIE,
            'note' => 'Znalezione przy przeglądzie świeżych wpisów.',
        ];
    }

    private function adres(string $typ, Post|Recipe|Comment $tresc): string
    {
        return route('admin.z-urzedu.store', ['typ' => $typ, 'id' => $tresc->getKey()]);
    }

    private function tresc(string $typ, User $autor): Post|Recipe|Comment
    {
        return match ($typ) {
            'post' => Post::factory()->create(['author_id' => $autor->getKey()]),
            'recipe' => Recipe::factory()->create(['author_id' => $autor->getKey()]),
            'comment' => Comment::factory()->create([
                'author_id' => $autor->getKey(),
                'post_id' => Post::factory()->create()->getKey(),
            ]),
        };
    }
}
