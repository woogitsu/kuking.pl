<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\WierszFormularza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/admin/zgloszenia` STAWIA JEDEN FORMULARZ DECYZJI NA KAŻDE OTWARTE
 * ZGŁOSZENIE — do dwudziestu pięciu na stronie (issue #243).
 *
 * Ten sam kształt usterki co w `KolejkaSygnalowFormularzeNiezalezneTest`,
 * tylko więcej pól naraz: `action`, `suspend_days`, `suspend_days_custom`,
 * `reason_code`, `note`, `user_message` — wszystkie powtórzone w KAŻDYM
 * formularzu na stronie pod tą samą nazwą.
 */
class KolejkaZgloszenFormularzeNiezalezneTest extends TestCase
{
    use RefreshDatabase;

    private function otwarteZgloszenie(User $autor, string $powod = 'harassment'): Report
    {
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        return Report::create([
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => $powod,
            'status' => Report::STATUS_OPEN,
        ]);
    }

    #[Test]
    public function test_odrzucona_decyzja_jednego_zgloszenia_nie_wypelnia_pol_innego(): void
    {
        $moderator = $this->moderator();

        $zgloszenieA = $this->otwarteZgloszenie($this->user('autora'));
        $zgloszenieB = $this->otwarteZgloszenie($this->user('autorb'));

        $notatkaA = 'Notatka wewnętrzna wyłącznie o zgłoszeniu A.';
        $wiadomoscA = 'Wiadomość do autora, dotyczy wyłącznie sprawy A.';

        // Formularz zgłoszenia A wraca z błędem: brak `reason_code` przy
        // decyzji „Zawieś konto" (`ModerationController::decide` wymaga go
        // zawsze). `_wiersz` = id zgłoszenia A, tak jak wysyła prawdziwy
        // formularz (`App\Support\WierszFormularza`).
        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenieA), [
                WierszFormularza::POLE => (string) $zgloszenieA->id,
                'action' => ModerationAction::ACTION_BAN,
                'note' => $notatkaA,
                'user_message' => $wiadomoscA,
            ])
            ->assertSessionHasErrors('reason_code');

        $strona = (string) $this->actingAs($moderator)
            ->get(route('admin.reports'))->assertOk()->getContent();

        // Kontrola pozytywna: sprawa A naprawdę wróciła z tym, co moderator
        // wpisał (AGENTS.md §5 — poprawne dane nigdy nie znikają).
        $this->assertStringContainsString($notatkaA, $strona);
        $this->assertStringContainsString($wiadomoscA, $strona);
        $this->assertMatchesRegularExpression(
            '/name="action" value="ban"[^>]*checked/',
            $strona,
        );

        // Sedno usterki: sprawa B nie ma NIC wspólnego z formularzem, który
        // się nie powiódł, a mimo to bez naprawy dostawała tę samą treść.
        // Prostsze i pewniejsze niż wycinanie fragmentu HTML: treść sprawy A
        // nie ma prawa wystąpić na stronie WIĘCEJ niż raz (dokładnie tam,
        // gdzie stoi formularz sprawy A) — sprawa B ma zerową.
        $this->assertSame(1, substr_count($strona, $notatkaA), 'Notatka sprawy A wyciekła do innego formularza na tej samej stronie.');
        $this->assertSame(1, substr_count($strona, $wiadomoscA), 'Wiadomość do autora sprawy A wyciekła do innego formularza na tej samej stronie.');

        // Radio „Zablokuj konto na stałe" ma być zaznaczone DOKŁADNIE raz —
        // przy sprawie A. Zaznaczenie przy sprawie B byłoby drugim `checked`.
        $this->assertSame(
            1,
            preg_match_all('/name="action" value="ban"[^>]*checked/', $strona),
            'Wybór „Zablokuj konto" pojawił się zaznaczony więcej niż raz — wyciekł do innego zgłoszenia.',
        );

        // Pole notatki SPRAWY B, po jego WŁASNYM `id`, zostaje puste.
        $poczatekB = strpos($strona, 'id="f-note-'.$zgloszenieB->id.'"');
        $this->assertIsInt($poczatekB, 'Brak pola notatki dla sprawy B.');
        $this->assertStringNotContainsString(
            $notatkaA,
            substr($strona, $poczatekB, 400),
            'Pole notatki sprawy B pokazuje treść napisaną o sprawie A.',
        );
    }

    #[Test]
    public function test_zadne_id_pola_notatki_nie_powtarza_sie_na_stronie_z_dwoma_zgloszeniami(): void
    {
        $moderator = $this->moderator();

        $a = $this->otwarteZgloszenie($this->user('idautora'));
        $b = $this->otwarteZgloszenie($this->user('idautorb'));

        $html = (string) $this->actingAs($moderator)
            ->get(route('admin.reports'))->assertOk()->getContent();

        preg_match_all('/\sid="([^"]+)"/', $html, $dopasowania);
        $identyfikatory = $dopasowania[1];

        $this->assertNotEmpty($identyfikatory);
        $this->assertSame(
            count($identyfikatory),
            count(array_unique($identyfikatory)),
            'Na stronie z dwoma zgłoszeniami powtarza się jakiś `id`.',
        );

        foreach ([$a, $b] as $zgloszenie) {
            $this->assertStringContainsString('id="f-note-'.$zgloszenie->id.'"', $html);
            $this->assertStringContainsString('id="f-user_message-'.$zgloszenie->id.'"', $html);
            $this->assertStringContainsString('for="f-note-'.$zgloszenie->id.'"', $html);
            $this->assertSame(1, substr_count($html, 'id="f-note-'.$zgloszenie->id.'"'));
        }
    }
}
