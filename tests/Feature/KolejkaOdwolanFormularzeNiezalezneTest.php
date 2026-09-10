<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use App\Support\WierszFormularza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/admin/odwolania` STAWIA JEDEN FORMULARZ ODPOWIEDZI NA KAŻDE OTWARTE
 * ODWOŁANIE — ten sam kształt usterki co w `/admin/sygnaly` i
 * `/admin/zgloszenia` (issue #243): pola `outcome` i `decision_note`
 * powtórzone pod tą samą nazwą w każdym formularzu na stronie.
 */
class KolejkaOdwolanFormularzeNiezalezneTest extends TestCase
{
    use RefreshDatabase;

    /** Odwołanie OTWARTE od decyzji ukrycia przepisu — przez prawdziwe formularze. */
    private function otwarteOdwolanie(User $administrator, string $login): Appeal
    {
        $autor = $this->user($login);
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED]);

        $report = Report::create([
            'reporter_id' => $this->user($login.'zglaszajacy')->getKey(),
            'target_type' => 'recipe',
            'target_id' => $recipe->getKey(),
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($administrator)
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'copyright',
                'user_message' => 'Przepis wygląda na skopiowany.',
            ])
            ->assertRedirect();

        $decyzja = ModerationAction::where('report_id', $report->getKey())->firstOrFail();

        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), [
                'body' => 'To mój własny przepis, gotuję go od lat — odwołanie '.$login.'.',
            ])
            ->assertRedirect();

        return Appeal::where('moderation_action_id', $decyzja->getKey())->firstOrFail();
    }

    #[Test]
    public function test_odrzucona_odpowiedz_na_jedno_odwolanie_nie_wypelnia_uzasadnienia_przy_innym(): void
    {
        $administrator = $this->admin();

        $pierwsze = $this->otwarteOdwolanie($administrator, 'wiktoria');
        $drugie = $this->otwarteOdwolanie($administrator, 'zenon');

        // Odpowiedź na PIERWSZE odwołanie wraca z błędem: uzasadnienie musi
        // mieć co najmniej 10 znaków (`AppealController::resolve`).
        $tresc = 'Krótko.';

        $this->actingAs($administrator)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $pierwsze), [
                WierszFormularza::POLE => (string) $pierwsze->id,
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => $tresc,
            ])
            ->assertSessionHasErrors('decision_note');

        $strona = (string) $this->actingAs($administrator)
            ->get(route('admin.appeals'))->assertOk()->getContent();

        // Kontrola pozytywna: PIERWSZE odwołanie naprawdę dostaje z powrotem
        // to, co administrator wpisał.
        $this->assertStringContainsString($tresc, $strona);
        $this->assertMatchesRegularExpression(
            '/name="outcome" value="upheld"[^>]*checked/',
            $strona,
        );

        // Sedno usterki: treść wpisana przy PIERWSZYM odwołaniu ma wystąpić
        // na stronie dokładnie raz — nie drugi raz przy DRUGIM.
        $this->assertSame(
            1,
            substr_count($strona, $tresc),
            'Uzasadnienie z odrzuconej odpowiedzi na pierwsze odwołanie wyciekło do formularza drugiego.',
        );
        $this->assertSame(
            1,
            preg_match_all('/name="outcome" value="upheld"[^>]*checked/', $strona),
            '„Podtrzymuję decyzję" zaznaczone więcej niż raz — wyciekło do innego odwołania.',
        );

        // Pole uzasadnienia DRUGIEGO odwołania, po jego WŁASNYM `id`, jest puste.
        $poczatekDrugiego = strpos($strona, 'id="f-decision_note-'.$drugie->id.'"');
        $this->assertIsInt($poczatekDrugiego, 'Brak pola uzasadnienia dla drugiego odwołania.');
        $this->assertStringNotContainsString(
            $tresc,
            substr($strona, $poczatekDrugiego, 400),
        );
    }

    #[Test]
    public function test_zadne_id_pola_uzasadnienia_nie_powtarza_sie_na_stronie_z_dwoma_odwolaniami(): void
    {
        $administrator = $this->admin();

        $a = $this->otwarteOdwolanie($administrator, 'gustaw');
        $b = $this->otwarteOdwolanie($administrator, 'helena');

        $html = (string) $this->actingAs($administrator)
            ->get(route('admin.appeals'))->assertOk()->getContent();

        preg_match_all('/\sid="([^"]+)"/', $html, $dopasowania);
        $identyfikatory = $dopasowania[1];

        $this->assertNotEmpty($identyfikatory);
        $this->assertSame(
            count($identyfikatory),
            count(array_unique($identyfikatory)),
            'Na stronie z dwoma odwołaniami powtarza się jakiś `id`.',
        );

        foreach ([$a, $b] as $odwolanie) {
            $this->assertStringContainsString('id="f-decision_note-'.$odwolanie->id.'"', $html);
            $this->assertStringContainsString('for="f-decision_note-'.$odwolanie->id.'"', $html);
            $this->assertSame(1, substr_count($html, 'id="f-decision_note-'.$odwolanie->id.'"'));
        }
    }
}
