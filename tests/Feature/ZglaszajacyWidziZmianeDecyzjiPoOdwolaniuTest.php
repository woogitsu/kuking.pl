<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\NotifyReporterDecisionChanged;
use App\Domain\Moderation\Actions\RestoreContent;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Notification as Powiadomienie;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ZmianaDecyzjiWSprawieZgloszenia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PO COFNIĘCIU DECYZJI ZGŁASZAJĄCY WIDZI, ŻE TREŚĆ WRÓCIŁA (#1024).
 *
 * Zastane: `hide`/`remove` → odwołanie autora → `overturned` przywracało
 * wpis, ale lista „Twoje zgłoszenia", karta sprawy i jedyne powiadomienie
 * dalej mówiły „Zgłoszona treść nie jest już dostępna w serwisie" — bo
 * wszystkie trzy czytały wyłącznie pierwszą decyzję (`report_id`), a nic nie
 * mówiło zgłaszającemu o zmianie.
 *
 * Każdy krok idzie przez prawdziwe trasy: zgłoszenie, decyzja moderatora,
 * odwołanie autora, rozpatrzenie przez administratora.
 */
class ZglaszajacyWidziZmianeDecyzjiPoOdwolaniuTest extends TestCase
{
    use RefreshDatabase;

    private const NIEDOSTEPNA = 'Zgłoszona treść nie jest już dostępna w serwisie.';

    private const ZMIENIONA = 'Po ponownym sprawdzeniu zmieniliśmy decyzję.';

    private const WROCILA = 'Zgłoszona treść wróciła do serwisu.';

    private const SLOWA_AUTORA = 'To moje własne słowa, Barbara Odwołująca, nikogo nie obrażam.';

    private User $halina;

    private User $autor;

    private Post $wpis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->halina = $this->user('halina');
        $this->autor = $this->user('barbara', ['display_name' => 'Barbara Odwołująca']);
        $this->wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
        ]);
    }

    private function zglosIRozstrzygnij(string $akcja): array
    {
        $this->actingAs($this->halina)
            ->post(route('reports.store', ['type' => 'post', 'id' => $this->wpis->getKey()]), [
                'reason' => 'harassment',
                'details' => 'Obraża mnie w komentarzach.',
            ])
            ->assertSessionHasNoErrors();

        $zgloszenie = Report::query()->where('reporter_id', $this->halina->getKey())->sole();

        return [$zgloszenie, $this->rozstrzygnij($zgloszenie, $akcja)];
    }

    private function rozstrzygnij(Report $zgloszenie, string $akcja): ModerationAction
    {
        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => $akcja,
                'reason_code' => 'harassment',
                'user_message' => 'Zdejmujemy wpis, bo obraża konkretną osobę.',
            ])
            ->assertSessionHasNoErrors();

        return ModerationAction::query()->where('report_id', $zgloszenie->getKey())->sole();
    }

    private function odwolanieAutora(ModerationAction $decyzja, string $wynik): Appeal
    {
        $this->actingAs($this->autor)
            ->post(route('appeals.store', $decyzja), ['body' => self::SLOWA_AUTORA])
            ->assertSessionHasNoErrors();

        $odwolanie = Appeal::query()->where('moderation_action_id', $decyzja->getKey())->sole();

        $this->actingAs($this->admin())
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => $wynik,
                'decision_note' => 'Przeczytaliśmy jeszcze raz — nie ma tu obraźliwych słów.',
            ])
            ->assertSessionHasNoErrors();

        return $odwolanie->refresh();
    }

    /** @return list<Powiadomienie> */
    private function decyzjeDlaHaliny(): array
    {
        return Powiadomienie::query()
            ->where('user_id', $this->halina->getKey())
            ->where('type', Powiadomienie::TYPE_REPORT_DECIDED)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return array<string, array{string}> */
    public static function zdejmujace(): array
    {
        return [
            'ukrycie' => [ModerationAction::ACTION_HIDE],
            'usunięcie' => [ModerationAction::ACTION_REMOVE],
        ];
    }

    #[DataProvider('zdejmujace')]
    public function test_po_cofnieciu_lista_karta_i_powiadomienie_mowia_ze_tresc_wrocila(string $akcja): void
    {
        [$zgloszenie, $decyzja] = $this->zglosIRozstrzygnij($akcja);

        // Pierwsza instancja — ekran mówi prawdę o tamtej chwili.
        $this->actingAs($this->halina)->get(route('reports.mine'))->assertOk()->assertSee(self::NIEDOSTEPNA);

        $odwolanie = $this->odwolanieAutora($decyzja, Appeal::STATUS_OVERTURNED);

        // Treść naprawdę wróciła.
        $wpis = Post::withTrashed()->findOrFail($this->wpis->getKey());
        $this->assertFalse($wpis->trashed());
        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->status);

        // Lista: AKTUALNY skutek, bez starego zdania.
        $this->actingAs($this->halina)->get(route('reports.mine'))->assertOk()
            ->assertSee(self::ZMIENIONA)
            ->assertSee(self::WROCILA)
            ->assertDontSee(self::NIEDOSTEPNA);

        // Karta: pierwsza decyzja zostaje w historii, pod nią zmiana.
        $this->actingAs($this->halina)->get(route('reports.mine.show', $zgloszenie))->assertOk()
            ->assertSeeInOrder([self::NIEDOSTEPNA, 'Zmiana decyzji', self::ZMIENIONA, self::WROCILA])
            ->assertDontSee('Barbara Odwołująca')
            ->assertDontSee(self::SLOWA_AUTORA);

        // Dokładnie jedno nowe powiadomienie, prowadzące do tej samej sprawy;
        // pierwsze zostaje nietknięte.
        $powiadomienia = $this->decyzjeDlaHaliny();
        $this->assertCount(2, $powiadomienia);
        [$pierwsze, $korekta] = $powiadomienia;
        $this->assertSame(self::NIEDOSTEPNA, $pierwsze->data['reszta']);
        $this->assertSame(self::ZMIENIONA, $korekta->data['naglowek']);
        $this->assertSame(self::WROCILA, $korekta->data['reszta']);
        $this->assertSame((string) $zgloszenie->getKey(), $korekta->data['report_id']);
        $this->assertSame(route('reports.mine.show', $zgloszenie), $korekta->adresDocelowy());
        $this->assertSame((string) $odwolanie->getKey(), $korekta->data['zmiana_po_odwolaniu']);
        $this->assertStringNotContainsString('Barbara', json_encode($korekta->data, JSON_UNESCAPED_UNICODE));

        $this->actingAs($this->halina)->get(route('notifications.index'))->assertOk()
            ->assertSee(self::ZMIENIONA)
            ->assertDontSee(self::SLOWA_AUTORA);

        // Audyt: pierwotna decyzja nietknięta, przywrócenie osobnym wierszem.
        $this->assertSame($akcja, $decyzja->refresh()->action);
        $this->assertSame((string) $zgloszenie->getKey(), (string) $decyzja->report_id);
        $this->assertSame(1, ModerationAction::query()
            ->where('action', ModerationAction::ACTION_UNHIDE)
            ->where('target_id', $this->wpis->getKey())
            ->count());

        // Ponowienie korekty nie dokłada drugiej.
        app(NotifyReporterDecisionChanged::class)->handle($odwolanie);
        $this->assertCount(2, $this->decyzjeDlaHaliny());
    }

    /**
     * Zdanie „wróciła" ma opisywać stan, a nie samo odwołanie: gdy tę samą
     * treść zdjęła później INNA decyzja, karta nie twierdzi, że jest z powrotem.
     */
    public function test_kolejne_zdjecie_tresci_po_cofnieciu_nie_zostawia_zdania_ze_wrocila(): void
    {
        [$zgloszenie, $decyzja] = $this->zglosIRozstrzygnij(ModerationAction::ACTION_HIDE);
        $this->odwolanieAutora($decyzja, Appeal::STATUS_OVERTURNED);

        $inna = $this->user('drugazglaszajaca');
        $this->actingAs($inna)
            ->post(route('reports.store', ['type' => 'post', 'id' => $this->wpis->getKey()]), [
                'reason' => 'spam',
                'details' => 'Reklama.',
            ])
            ->assertSessionHasNoErrors();
        $this->rozstrzygnij(Report::query()->where('reporter_id', $inna->getKey())->sole(), ModerationAction::ACTION_HIDE);

        $this->actingAs($this->halina)->get(route('reports.mine.show', $zgloszenie))->assertOk()
            ->assertSee(self::NIEDOSTEPNA)
            ->assertDontSee(self::WROCILA);
        $this->actingAs($this->halina)->get(route('reports.mine'))->assertOk()
            ->assertDontSee(self::WROCILA);
    }

    public function test_podtrzymana_decyzja_nie_zmienia_skutku_ani_nie_daje_korekty(): void
    {
        [$zgloszenie, $decyzja] = $this->zglosIRozstrzygnij(ModerationAction::ACTION_HIDE);

        // Podtrzymanie przez tego samego moderatora ma karencję — tu
        // rozpatruje inny administrator, więc przechodzi od razu.
        $this->odwolanieAutora($decyzja, Appeal::STATUS_UPHELD);

        $this->actingAs($this->halina)->get(route('reports.mine.show', $zgloszenie))->assertOk()
            ->assertSee(self::NIEDOSTEPNA)
            ->assertDontSee('Zmiana decyzji')
            ->assertDontSee(self::ZMIENIONA);
        $this->assertCount(1, $this->decyzjeDlaHaliny());

        // Nawet gdy treść wróci później ręcznie, podtrzymane odwołanie nie
        // staje się „zmianą decyzji".
        app(RestoreContent::class)->handle(
            moderator: $this->moderator(),
            target: Post::withTrashed()->findOrFail($this->wpis->getKey()),
            reasonCode: 'poprawione_przez_autora',
        );

        $this->actingAs($this->halina)->get(route('reports.mine.show', $zgloszenie))->assertOk()
            ->assertDontSee('Zmiana decyzji');
    }

    /**
     * Ręczne „Przywróć treść" bez odwołania nie jest zmianą decyzji — zwykle
     * znaczy „autor poprawił treść". Zgłaszający nie dostaje zdania, że
     * się pomyliliśmy, i nic nie trafia do tej sprawy przypadkiem.
     */
    public function test_reczne_przywrocenie_bez_odwolania_nie_jest_przypisywane_do_zgloszenia(): void
    {
        [$zgloszenie] = $this->zglosIRozstrzygnij(ModerationAction::ACTION_HIDE);

        app(RestoreContent::class)->handle(
            moderator: $this->moderator(),
            target: Post::withTrashed()->findOrFail($this->wpis->getKey()),
            reasonCode: 'poprawione_przez_autora',
        );

        $this->actingAs($this->halina)->get(route('reports.mine.show', $zgloszenie))->assertOk()
            ->assertDontSee('Zmiana decyzji');
        $this->assertCount(1, $this->decyzjeDlaHaliny());
    }

    public function test_zglaszajacy_prawny_z_adresem_dostaje_korekte_listem(): void
    {
        Notification::fake();

        $zgloszenie = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => $this->wpis->getKey(),
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo zawiera cudze dane osobowe.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'status' => Report::STATUS_OPEN,
        ]);

        $decyzja = $this->rozstrzygnij($zgloszenie, ModerationAction::ACTION_HIDE);
        $this->odwolanieAutora($decyzja, Appeal::STATUS_OVERTURNED);

        Notification::assertSentOnDemandTimes(ZmianaDecyzjiWSprawieZgloszenia::class, 1);
        Notification::assertSentOnDemand(
            ZmianaDecyzjiWSprawieZgloszenia::class,
            function (ZmianaDecyzjiWSprawieZgloszenia $list, array $kanaly, object $odbiorca): bool {
                $mail = $list->toMail($odbiorca);
                $tresc = implode(' ', $mail->introLines);

                $this->assertStringContainsString(self::ZMIENIONA, $tresc);
                $this->assertStringContainsString(self::WROCILA, $tresc);
                $this->assertStringNotContainsString('Barbara', $tresc);
                $this->assertStringNotContainsString(self::SLOWA_AUTORA, $tresc);

                return $odbiorca->routes['mail'] === 'jan@przyklad.test';
            },
        );
    }

    public function test_zgloszenie_anonimowe_bez_adresu_nie_probuje_doreczenia(): void
    {
        Notification::fake();

        $zgloszenie = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => $this->wpis->getKey(),
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo zawiera cudze dane osobowe.',
            'good_faith_at' => now(),
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'status' => Report::STATUS_OPEN,
        ]);

        $decyzja = $this->rozstrzygnij($zgloszenie, ModerationAction::ACTION_HIDE);
        $this->odwolanieAutora($decyzja, Appeal::STATUS_OVERTURNED);

        Notification::assertSentOnDemandTimes(ZmianaDecyzjiWSprawieZgloszenia::class, 0);
        $this->assertSame(0, Powiadomienie::query()->where('type', Powiadomienie::TYPE_REPORT_DECIDED)->count());
    }
}
