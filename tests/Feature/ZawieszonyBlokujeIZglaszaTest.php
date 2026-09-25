<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zawieszona osoba może się obronić: zablokować natręta i zgłosić treść
 * (audyt B2-03, D-258).
 *
 * CO SIĘ DZIAŁO
 * Zawieszone konto dalej czyta serwis, więc widzi komentarze i profil osoby,
 * która je nęka. `POST /@natret/blokuj` i `POST /zglos/post/{id}` kończyły się
 * jednak komunikatem „Twoje konto jest zawieszone…”, bo tych tras nie było
 * na liście wyjątków `EnsureAccountIsActive`. Przyciski stały na ekranie
 * i były martwe.
 *
 * Kontrola dodatnia w każdym teście: ta sama czynność DALEJ jest odbijana
 * przy obserwowaniu — wyjątek jest wąski, nie „zawieszenie przestało działać”.
 */
class ZawieszonyBlokujeIZglaszaTest extends TestCase
{
    use RefreshDatabase;

    private function zawieszona(): User
    {
        $osoba = $this->user('zawieszona');
        $osoba->suspend();

        return $osoba->refresh();
    }

    public function test_zawieszona_osoba_blokuje_i_odblokowuje_natreta(): void
    {
        $natret = $this->user('natret');
        $osoba = $this->zawieszona();
        $this->assertTrue($osoba->isSuspended());

        $this->actingAs($osoba)
            ->post(route('social.block', 'natret'), ['oczekiwany_id' => $natret->getKey()])
            ->assertSessionHasNoErrors();

        $this->assertTrue($osoba->fresh()->hasBlocked($natret), 'Zawieszona osoba nie mogła zablokować natręta.');

        $this->actingAs($osoba)
            ->delete(route('social.unblock', 'natret'), ['oczekiwany_id' => $natret->getKey()])
            ->assertSessionHasNoErrors();

        $this->assertFalse($osoba->fresh()->hasBlocked($natret));

        // Kontrola: obserwowanie dalej jest zablokowane (D-253).
        $this->actingAs($osoba)
            ->post(route('social.follow', 'natret'))
            ->assertSessionHasErrors('konto');
        $this->assertFalse($osoba->fresh()->isFollowing($natret));
    }

    public function test_zawieszona_osoba_zglasza_tresc(): void
    {
        $natret = $this->user('natret');
        $wpis = Post::factory()->create(['author_id' => $natret->getKey()]);
        $osoba = $this->zawieszona();

        $this->actingAs($osoba)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), [
                'reason' => 'harassment',
                'details' => 'Ta osoba pisze o mnie obraźliwe rzeczy.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $osoba->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_zawieszona_osoba_wysyla_zgloszenie_prawne(): void
    {
        $osoba = $this->zawieszona();

        $formularz = $this->actingAs($osoba)->get(route('zglos.nielegalna'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="klucz_wyslania"\s+value="([^"]+)"/', $formularz, $m), 'Formularz nie ma klucza wysłania.');

        $this->actingAs($osoba)
            ->post(route('zglos.nielegalna.store'), [
                'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
                'reason' => 'copyright',
                'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
                'good_faith' => '1',
                'klucz_wyslania' => $m[1],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Report::query()->where('source', 'legal_notice')->count());
    }

    public function test_publikacja_dalej_zablokowana(): void
    {
        // Kontrola dodatnia dla samego middleware: lista wyjątków nie
        // przepuściła niczego poza nazwanymi trasami.
        $natret = $this->user('natret');
        $wpis = Post::factory()->create(['author_id' => $natret->getKey()]);
        $osoba = $this->zawieszona();

        $this->actingAs($osoba)
            ->post(route('posts.comment', $wpis), ['body' => 'Nie powinno wyjść.'])
            ->assertSessionHasErrors('konto');

        $this->assertDatabaseMissing('comments', ['body' => 'Nie powinno wyjść.']);
    }
}
