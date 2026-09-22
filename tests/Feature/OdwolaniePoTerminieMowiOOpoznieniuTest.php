<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * OTWARTE ODWOŁANIE PO TERMINIE NIE POWTARZA OBIETNICY (issue #799).
 *
 * CO ZMIERZONO PRZED ZMIANĄ — sonda na nietkniętym `534e0a51`, PostgreSQL
 * 55439: odwołanie złożone 40 dni wcześniej miało `isOverdue() === true`,
 * a strona sprawy nadal mówiła „Odpowiadamy w ciągu 7 dni roboczych"
 * i nie zawierała ani słowa o opóźnieniu. Panel moderatora już wtedy
 * pokazywał „termin odpowiedzi minął" — wiedzę miała wyłącznie
 * administracja.
 *
 * CZEGO TEN TEST NIE PILNUJE
 * Wysokości progu. Próg to `Appeal::responseDeadline()`, który istniał
 * wcześniej i jest liczony przez `addWeekdays()` bez kalendarza świąt —
 * świadomie, zgodnie z komentarzem modelu. Ta zmiana nie ustanawia nowego
 * okresu i nie obiecuje nowej daty odpowiedzi.
 */
class OdwolaniePoTerminieMowiOOpoznieniuTest extends TestCase
{
    use RefreshDatabase;

    private int $licznik = 0;

    private function wpis(): Post
    {
        return Post::factory()->for($this->user('autor'.(++$this->licznik)), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
    }

    /**
     * Sprawa prawna z decyzją podjętą przez panel — droga, która generuje
     * podpisany link dla zgłaszającego.
     *
     * @return array{0: Report, 1: ModerationAction}
     */
    private function sprawaZDecyzja(string $akcja = ModerationAction::ACTION_NONE): array
    {
        $wpis = $this->wpis();

        $zgloszenie = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo zawiera cudze dane osobowe.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($this->moderator())
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => $akcja,
                'reason_code' => 'brak_naruszenia',
                'user_message' => 'Wyjaśnienie dla autora treści.',
            ])
            ->assertRedirect(route('admin.reports'));

        return [
            $zgloszenie->refresh(),
            ModerationAction::query()->where('report_id', $zgloszenie->getKey())->firstOrFail(),
        ];
    }

    private function podpisanyLinkZMaila(): string
    {
        $link = null;

        Notification::assertSentOnDemand(
            DecyzjaWSprawieZgloszenia::class,
            function (DecyzjaWSprawieZgloszenia $powiadomienie) use (&$link): bool {
                $link ??= $powiadomienie->toMail((object) [])->actionUrl;

                return true;
            },
        );

        return (string) $link;
    }

    /** Cofa datę złożenia odwołania tak, żeby termin odpowiedzi minął. */
    private function postarz(Appeal $odwolanie, int $dni): Appeal
    {
        $odwolanie->forceFill(['created_at' => now()->subDays($dni)])->save();

        return $odwolanie->refresh();
    }

    // ------------------------------------------------------------------
    // Droga ZGŁASZAJĄCEGO
    // ------------------------------------------------------------------

    public function test_zglaszajacy_przed_terminem_widzi_zwykly_stan_oczekiwania(): void
    {
        Notification::fake();

        $this->sprawaZDecyzja();
        $link = $this->podpisanyLinkZMaila();

        $this->post($link, ['body' => 'Uważam, że powinniście byli usunąć tę treść.'])->assertRedirect();

        $this->assertFalse(Appeal::firstOrFail()->isOverdue());

        $this->get($link)->assertOk()
            ->assertSee('Czekamy na rozpatrzenie')
            ->assertSee('Odpowiadamy w ciągu')
            ->assertDontSee('Odpowiedź się opóźnia');
    }

    public function test_zglaszajacy_po_terminie_slyszy_o_opoznieniu_a_nie_obietnice(): void
    {
        Notification::fake();

        [$zgloszenie] = $this->sprawaZDecyzja();
        $link = $this->podpisanyLinkZMaila();

        $this->post($link, ['body' => 'Uważam, że powinniście byli usunąć tę treść.'])->assertRedirect();

        $odwolanie = $this->postarz(Appeal::firstOrFail(), 40);
        $this->assertTrue($odwolanie->isOverdue(), 'Sprawa miała być po terminie — inaczej test nie mierzy tego, co obiecuje.');

        $odpowiedz = $this->get($link)->assertOk();

        $odpowiedz->assertSee('Odpowiedź się opóźnia');
        // SEDNO: bezwarunkowa obietnica znika. Zostaje zdanie o tym, że
        // termin BYŁ i minął — bez nowej daty, której nie znamy.
        $odpowiedz->assertDontSee('Czekamy na rozpatrzenie');
        $odpowiedz->assertDontSee('Odpowiadamy w ciągu');

        // Dotychczasowe wyjaśnienie i droga dalej zostają: człowiek wie,
        // co ma zrobić, jeśli chce o sprawę zapytać.
        $odpowiedz->assertSee('nadal czeka');
        $odpowiedz->assertSee((string) config('kuking.community.contact_email'));
        $odpowiedz->assertSee($zgloszenie->numer_sprawy);

        // NIE SUGERUJEMY ZŁOŻENIA ODWOŁANIA DRUGI RAZ.
        $odpowiedz->assertDontSee('Wyślij odwołanie');
    }

    public function test_rozstrzygniete_odwolanie_po_terminie_pokazuje_odpowiedz_a_nie_ostrzezenie(): void
    {
        Notification::fake();

        $this->sprawaZDecyzja();
        $link = $this->podpisanyLinkZMaila();

        $this->post($link, ['body' => 'Uważam, że powinniście byli usunąć tę treść.'])->assertRedirect();

        $odwolanie = $this->postarz(Appeal::firstOrFail(), 40);
        $odwolanie->forceFill([
            'status' => Appeal::STATUS_UPHELD,
            'decided_at' => now(),
            'decision_note' => 'Podtrzymujemy decyzję — oto dlaczego.',
        ])->save();

        $this->get($link)->assertOk()
            ->assertSee('Nasza odpowiedź')
            ->assertSee('Podtrzymujemy decyzję')
            ->assertDontSee('Odpowiedź się opóźnia')
            ->assertDontSee('Czekamy na rozpatrzenie');
    }

    // ------------------------------------------------------------------
    // Droga AUTORA TREŚCI — ta sama reguła, drugi kanał odpowiedzi
    // ------------------------------------------------------------------

    /** @return array{0: User, 1: ModerationAction} */
    private function decyzjaDotykajacaAutora(): array
    {
        Notification::fake();

        [, $decyzja] = $this->sprawaZDecyzja(ModerationAction::ACTION_HIDE);

        return [User::query()->findOrFail($decyzja->subject_user_id), $decyzja];
    }

    public function test_autor_przed_terminem_widzi_zwykly_stan_oczekiwania(): void
    {
        [$autor, $decyzja] = $this->decyzjaDotykajacaAutora();

        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), ['body' => 'Uważam, że ta decyzja była błędna.'])
            ->assertRedirect();

        $this->actingAs($autor)->get(route('appeals.show', $decyzja))->assertOk()
            ->assertSee('Czekamy na rozpatrzenie')
            ->assertDontSee('Odpowiedź się opóźnia');
    }

    public function test_autor_po_terminie_slyszy_o_opoznieniu_a_nie_obietnice(): void
    {
        [$autor, $decyzja] = $this->decyzjaDotykajacaAutora();

        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), ['body' => 'Uważam, że ta decyzja była błędna.'])
            ->assertRedirect();

        $odwolanie = $this->postarz(Appeal::query()->where('appellant', Appeal::APPELLANT_AUTHOR)->firstOrFail(), 40);
        $this->assertTrue($odwolanie->isOverdue());

        $this->actingAs($autor)->get(route('appeals.show', $decyzja))->assertOk()
            ->assertSee('Odpowiedź się opóźnia')
            ->assertDontSee('Czekamy na rozpatrzenie')
            ->assertDontSee('Odpowiadamy w ciągu')
            ->assertSee((string) config('kuking.community.contact_email'));
    }

    /**
     * CUDZE SPRAWY NADAL NIEWIDOCZNE: opóźnienie jest informacją o WŁASNEJ
     * sprawie, a nie nowym wejściem do cudzej.
     */
    public function test_opoznienie_nie_otwiera_drogi_do_cudzej_sprawy(): void
    {
        [$autor, $decyzja] = $this->decyzjaDotykajacaAutora();

        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), ['body' => 'Uważam, że ta decyzja była błędna.'])
            ->assertRedirect();

        $this->postarz(Appeal::query()->where('appellant', Appeal::APPELLANT_AUTHOR)->firstOrFail(), 40);

        $this->actingAs($this->user('ktosobcy'))
            ->get(route('appeals.show', $decyzja))
            ->assertForbidden();
    }
}
