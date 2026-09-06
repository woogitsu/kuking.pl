<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Decyzja moderacyjna musi DOTRZEĆ DO CZŁOWIEKA (audyt A16).
 *
 * CO SIĘ DZIAŁO
 * `moderation_actions.user_message` zapisywało treść napisaną przez moderatora
 * — i na tym się kończyło. Formularz moderatora pisze wprost „Wymóg DSA: autor
 * musi wiedzieć dlaczego i że może się odwołać", log moderacji twierdził, że
 * ostrzeżono, a autor nie dostawał niczego. Przy odwołaniu (DSA art. 17) to
 * jest najgorszy możliwy stan: papier mówi co innego niż rzeczywistość.
 *
 * Milczały WSZYSTKIE decyzje zapisujące `user_message`, nie tylko `warn`.
 *
 * DLACZEGO TESTY SĄ PO HTTP
 * Wiersz w `notifications` niczego jeszcze nie dowodzi — powiadomienie, którego
 * widok nie renderuje, jest tak samo niewidoczne jak jego brak. Dlatego testy
 * sprawdzają to, co człowiek naprawdę zobaczy na `/powiadomienia`.
 */
class PowiadomienieOModeracjiTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenie(string $typ, string $celId): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => $typ,
            'target_id' => $celId,
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /** @param  array<string, string>  $dane */
    private function decyzja(User $moderator, Report $report, array $dane): void
    {
        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), $dane)
            ->assertRedirect(route('admin.reports'));
    }

    public function test_ostrzezenie_dociera_do_autora_z_trescia_od_moderatora(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), [
            'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'harassment',
            'user_message' => 'Komentarz pod przepisem na żurek obraża inną osobę. Prosimy o zmianę tonu.',
        ]);

        $this->actingAs($basia)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Ostrzeżenie od moderacji Kuking.')
            // Treść NAPISANA PRZEZ MODERATORA — bez niej człowiek nie wie,
            // czego decyzja dotyczy.
            ->assertSee('Komentarz pod przepisem na żurek obraża inną osobę.')
            // Prawo do odwołania (DSA art. 17) ma być napisane wprost.
            ->assertSee('możesz się odwołać')
            ->assertSee(config('kuking.community.contact_email'));
    }

    public function test_powiadomienie_moderacyjne_nie_pokazuje_ktory_moderator_decydowal(): void
    {
        $moderator = $this->moderator();
        $moderator->profile->update(['display_name' => 'Krzysztof Moderator']);
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), [
            'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'harassment',
            'user_message' => 'Prosimy o zmianę tonu.',
        ]);

        // Zespół moderacji ma 1-2 osoby. Podpisanie decyzji nazwiskiem to
        // wskazanie palcem konkretnego człowieka przez kogoś, kto właśnie
        // dostał karę.
        $this->actingAs($basia)
            ->get(route('notifications.index'))
            ->assertOk()
            // Bez tej asercji test byłby fałszywie zielony także wtedy, gdyby
            // powiadomienie w ogóle nie powstało — pusta lista nie zawiera
            // nazwiska moderatora tak samo dobrze.
            ->assertSee('Prosimy o zmianę tonu.')
            ->assertDontSee('Krzysztof Moderator');
    }

    public function test_ukrycie_tresci_tez_powiadamia_autora(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), [
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam_link',
            'user_message' => 'We wpisie był link do sklepu. Ukryliśmy go.',
        ]);

        $this->actingAs($basia)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Moderacja Kuking ukryła Twoją treść.')
            ->assertSee('We wpisie był link do sklepu.');
    }

    public function test_usuniecie_tresci_powiadamia_mimo_ze_cel_przestal_istniec(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $comment = Comment::factory()->create(['author_id' => $basia->getKey()]);

        $this->decyzja($moderator, $this->zgloszenie('comment', $comment->getKey()), [
            'action' => ModerationAction::ACTION_REMOVE,
            'reason_code' => 'harassment',
            'user_message' => 'Ten komentarz łamie zasady serwisu.',
        ]);

        // Autora wyznaczamy PRZED skasowaniem celu — inaczej nie ma już kogo
        // powiadomić.
        $this->actingAs($basia)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Ten komentarz łamie zasady serwisu.');
    }

    public function test_zawieszony_widzi_powiadomienie_z_terminem_kary(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'reason_code' => 'harassment',
            'suspend_days' => '7',
            'user_message' => 'Zawieszamy konto za powtarzające się obraźliwe komentarze.',
        ]);

        $basia->refresh();
        $this->assertTrue($basia->isSuspended());

        // Zawieszone konto ma dostęp do ODCZYTU (EnsureAccountIsActive) —
        // właśnie po to, żeby dało się przeczytać, za co i na jak długo.
        $this->actingAs($basia)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Twoje konto jest zawieszone do '.now()->addDays(7)->translatedFormat('j F Y'))
            ->assertSee('Zawieszamy konto za powtarzające się obraźliwe komentarze.');
    }

    public function test_pusta_wiadomosc_moderatora_nie_daje_pustego_powiadomienia(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        // Pole „Wiadomość do użytkownika" jest nieobowiązkowe. Człowiek i tak
        // musi dostać odpowiedź na pytanie „co teraz mogę, a czego nie".
        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), [
            'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'harassment',
        ]);

        $this->actingAs($basia)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Ostrzeżenie od moderacji Kuking.')
            ->assertSee('Nic nie zostało ukryte ani usunięte.');
    }

    public function test_odrzucone_zgloszenie_nie_powiadamia_nikogo(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), [
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'bez_podstaw',
            'user_message' => 'To nie łamie zasad.',
        ]);

        // „Bez działania" znaczy, że tej osobie nic się nie stało.
        // Powiadomienie powiedziałoby jej tylko tyle, że ktoś ją zgłosił.
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_zablokowane_konto_ma_zapisana_wiadomosc_mimo_ze_jej_nie_przeczyta(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenie('user', $basia->getKey()), [
            'action' => ModerationAction::ACTION_BAN,
            'reason_code' => 'harassment',
            'user_message' => 'Konto zablokowane za nękanie innych osób.',
        ]);

        // Wiersz powstaje mimo blokady: to zapis tego, co powiedzieliśmy
        // (DSA art. 17), i trafia do eksportu danych (RODO art. 15).
        // `NotifyUser` odmówiłby go — konto nie jest już aktywne.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $basia->getKey(),
            'type' => Notification::TYPE_MODERATION,
            'actor_id' => null,
        ]);

        $this->assertSame(
            'Konto zablokowane za nękanie innych osób.',
            Notification::where('user_id', $basia->getKey())->firstOrFail()->data['message'],
        );

        // I uczciwie: do serwisu ta osoba nie wejdzie, więc tego powiadomienia
        // nie zobaczy. Kanałem, który naprawdę widzi, jest komunikat przy
        // logowaniu — pilnują go dwa testy niżej.
        $this->actingAs($basia->refresh())
            ->get(route('notifications.index'))
            ->assertRedirect(route('login'));
    }

    public function test_zablokowany_czyta_wiadomosc_moderatora_przy_logowaniu(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenie('user', $basia->getKey()), [
            'action' => ModerationAction::ACTION_BAN,
            'reason_code' => 'harassment',
            'user_message' => 'Konto zablokowane za nękanie innych osób w komentarzach.',
        ]);

        // Trasa logowania jest za `guest` — moderator, który przed chwilą
        // podejmował decyzję, musi zejść z sesji.
        Auth::logout();

        $this->post(route('login'), [
            'login' => $basia->email,
            'password' => 'haslo-testowe-123',
        ])->assertSessionHasErrors('login');

        $blad = session('errors')->getBag('default')->first('login');

        // Sam fakt „konto zablokowane" nie mówi za co. Odpowiedź na to
        // pytanie napisał moderator — i to jest jedyne miejsce, w którym
        // ta osoba ją przeczyta.
        $this->assertStringContainsString('Konto zablokowane za nękanie innych osób w komentarzach.', $blad);
        $this->assertStringContainsString(config('kuking.community.contact_email'), $blad);
    }

    public function test_zawieszony_moze_sie_zalogowac_i_przeczytac_decyzje(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'reason_code' => 'harassment',
            'suspend_days' => '7',
            'user_message' => 'Zawieszamy konto na tydzień za obraźliwe komentarze.',
        ]);

        // `suspend()` kasuje sesje, więc osoba wylatuje z serwisu od razu.
        // Jeśli nie może wrócić, kara „tylko do odczytu" zamienia się w
        // blokadę na zawsze — i nie ma jak przeczytać, za co i na jak długo.
        Auth::logout();

        $this->post(route('login'), [
            'login' => $basia->email,
            'password' => 'haslo-testowe-123',
        ])->assertRedirect(route('home'));

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Zawieszamy konto na tydzień za obraźliwe komentarze.');

        // Zawieszenie nadal zabiera prawo do publikowania — wpuszczenie
        // do środka nie może tego zdjąć.
        $this->assertTrue($basia->fresh()->isSuspended());
    }
}
