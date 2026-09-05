<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decyzje moderacyjne — co wolno zrobić na jakim zgłoszeniu (audyt A16 + NOWE).
 *
 * NAJPOWAŻNIEJSZA RZECZ, KTÓREJ TU PILNUJEMY
 * Przycisk podpisany „Usuń treść" przy zgłoszeniu OSOBY wywoływał
 * `$user->delete()`. `User` nie ma SoftDeletes, a klucze obce mają
 * `cascadeOnDelete`, więc jedno kliknięcie kasowało konto, profil, wszystkie
 * wpisy, przepisy, zdjęcia, komentarze, zeszyty i wykonania — bezpowrotnie,
 * bez potwierdzenia, bez logu treści.
 *
 * Przy jednym moderatorze i archiwum przepisów po babci to jest najgorsza
 * możliwa awaria tego serwisu: nie „coś nie działa", tylko „czyjeś dane
 * przestały istnieć".
 *
 * DRUGA RZECZ
 * „Zawieś konto" na zgłoszonym WPISIE tylko ukrywało wpis. Konto autora
 * zostawało aktywne, a zgłoszenie dostawało status „rozstrzygnięte" —
 * moderator był przekonany, że zawiesił kogoś, kogo nie zawiesił.
 */
class DecyzjeModeracyjneTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenie(string $typ, string $celId): Report
    {
        return Report::create([
            'reporter_id' => $this->user('zglaszajaca')->getKey(),
            'target_type' => $typ,
            'target_id' => $celId,
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_nie_da_sie_skasowac_konta_decyzja_usun_tresc(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $report = $this->zgloszenie('user', $basia->getKey());

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_REMOVE,
                'reason_code' => 'nekanie',
            ])
            ->assertSessionHasErrors('action');

        // Konto i cała jego zawartość mają PRZETRWAĆ.
        $this->assertNotNull(User::find($basia->getKey()), 'Konto zostało skasowane.');
        $this->assertSame(1, Post::where('author_id', $basia->getKey())->count(), 'Wpisy zniknęły razem z kontem.');

        // Nieudana decyzja nie może zamykać zgłoszenia.
        $this->assertSame(Report::STATUS_OPEN, $report->refresh()->status);
        $this->assertSame(0, ModerationAction::where('report_id', $report->getKey())->count());
    }

    public function test_formularz_nie_pokazuje_usun_tresc_przy_zgloszeniu_osoby(): void
    {
        $basia = $this->user('basia');
        $this->zgloszenie('user', $basia->getKey());

        // Zabezpieczenie po stronie serwera to za mało: przycisk, który kasuje
        // konto, nie powinien się w ogóle pokazać.
        $this->actingAs($this->moderator())
            ->get(route('admin.reports'))
            ->assertOk()
            ->assertDontSee('Usuń treść');
    }

    public function test_zawieszenie_na_zgloszonym_wpisie_zawiesza_autora(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->actingAs($moderator)->post(route('admin.reports.decide', $report), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'reason_code' => 'nekanie',
            'suspend_days' => '7',
        ])->assertRedirect();

        // Decyzja nazywa się „Zawieś konto autora" — więc konto ma być zawieszone.
        $this->assertSame(
            User::STATUS_SUSPENDED,
            $basia->refresh()->status,
            'Konto autora zostało nietknięte, mimo decyzji o zawieszeniu.',
        );

        $this->assertNotNull($basia->status_expires_at, 'Zawieszenie nie dostało terminu.');
    }

    public function test_blokada_na_zgloszonym_wpisie_blokuje_autora_a_nie_kasuje_wpisu(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->actingAs($moderator)->post(route('admin.reports.decide', $report), [
            'action' => ModerationAction::ACTION_BAN,
            'reason_code' => 'nekanie',
        ])->assertRedirect();

        $this->assertSame(User::STATUS_BANNED, $basia->refresh()->status);

        // Wcześniej `ban` na treści kasował TREŚĆ zamiast blokować autora.
        // Zablokowanie konta i tak odcina jego treści, a skasowanie jest
        // nieodwracalne — więc nie robimy go przy okazji innej decyzji.
        $this->assertNotNull(Post::find($post->getKey()), 'Wpis zniknął przy blokadzie autora.');
    }

    public function test_rozstrzygniete_zgloszenie_nie_przyjmuje_drugiej_decyzji(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->actingAs($moderator)->post(route('admin.reports.decide', $report), [
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'nekanie',
        ])->assertRedirect();

        // Druga decyzja — np. z drugiej otwartej zakładki.
        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_BAN,
                'reason_code' => 'nekanie',
            ])
            ->assertSessionHasErrors('action');

        // Przy odwołaniu (DSA art. 17) log musi być jednoznaczny.
        $this->assertSame(
            1,
            ModerationAction::where('report_id', $report->getKey())->count(),
            'Jedno zgłoszenie dostało dwie decyzje — log przestaje być dowodem.',
        );

        $this->assertSame(User::STATUS_ACTIVE, $basia->refresh()->status);
    }

    public function test_macierz_nie_pozwala_na_usuniecie_konta_zadna_droga(): void
    {
        // Niezmiennik, nie pojedynczy przypadek: gdyby ktoś dopisał nowy typ
        // zgłoszenia albo nową akcję, ta asercja ma to złapać.
        $this->assertNotContains(
            ModerationAction::ACTION_REMOVE,
            ModerationAction::DOZWOLONE['user'],
            'Macierz dopuszcza „usuń" na koncie. To kasuje je bezpowrotnie '
            .'razem z całym archiwum użytkownika.',
        );
    }
}
