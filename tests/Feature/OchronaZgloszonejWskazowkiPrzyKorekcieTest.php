<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\Report;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** #2884: wycofanie zgody nie podmienia tekstu nadal otwartej sprawy wskazówki. */
final class OchronaZgloszonejWskazowkiPrzyKorekcieTest extends TestCase
{
    use RefreshDatabase;

    private const UWAGA = 'Oryginalna uwaga do rozpatrzenia.';

    private const ZMIENIONA = 'Inna uwaga po zgłoszeniu.';

    private User $kucharz;

    private CookedEvent $wykonanie;

    private RecipeHint $wskazowka;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kucharz = $this->user('kucharz2884');
        $przepis = Recipe::factory()->create([
            'author_id' => $this->user('autor2884')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
        $this->wykonanie = CookedEvent::factory()->create([
            'user_id' => $this->kucharz->getKey(), 'recipe_id' => $przepis->getKey(),
            'note' => self::UWAGA, 'changes_note' => 'Pierwotny opis.', 'actual_minutes' => 120,
        ]);
        $this->wskazowka = RecipeHint::factory()->dlaWykonania($this->wykonanie, RecipeHint::STATUS_ACCEPTED)->create();
    }

    /** @return array<string, array{string}> */
    public static function otwarte(): array
    {
        return ['open' => [Report::STATUS_OPEN], 'triage' => [Report::STATUS_TRIAGE], 'reviewing' => [Report::STATUS_REVIEWING]];
    }

    #[DataProvider('otwarte')]
    public function test_zgloszenie_wycofanie_i_pelna_korekta_nie_podmieniaja_uwagi_w_panelu(string $status): void
    {
        $widz = $this->user('widz2884');
        $this->actingAs($widz)->post(route('reports.store', ['type' => 'recipe_hint', 'id' => $this->wskazowka->getKey()]), ['reason' => 'spam'])
            ->assertSessionHasNoErrors();
        $zgloszenie = Report::query()->sole();
        $zgloszenie->forceFill(['status' => $status])->save();
        $this->assertSame('recipe_hint', $zgloszenie->target_type);
        $this->assertSame(0, Report::query()->where('target_type', 'cooked_event')->count());

        $moderator = $this->moderator();
        $this->assertSame(self::UWAGA, $this->cytat($this->actingAs($moderator)->get(route('admin.reports', ['status' => 'wszystkie']))->assertOk()));
        $przepis = $this->wykonanie->recipe;
        $this->assertNotNull($przepis);
        $strona = $this->actingAs($this->kucharz)->get(route('recipes.show', $przepis->slug))->assertOk();
        $this->assertSame(1, $this->xpath($strona)->query('//section[@aria-labelledby="wskazowki-gotujacych"]//blockquote')->length);
        $this->wycofaj();
        $odpowiedz = $this->popraw(podazajZaPowrotem: true);

        // Pierwsza asercja po rzeczywistym PUT: mutant musi oblać zapis uwagi,
        // nie jedynie kształt formularza albo niedostępne środowisko.
        $po = $this->wykonanie->fresh();
        $this->assertSame(self::UWAGA, $po->note, 'UWAGA_2884_ZGLOSZONY_TEKST');
        $this->assertSame('Poprawiony opis.', $po->changes_note, 'UWAGA_2884_NIECHRONIONY_OPIS');
        $this->assertSame(20, $po->actual_minutes, 'UWAGA_2884_NIECHRONIONY_CZAS');
        $odpowiedz->assertOk();

        $formularz = $odpowiedz;
        $formularz->assertSee('Tej uwagi nie można teraz zmienić. Czas i opis zmian nadal możesz poprawić.')
            ->assertSee('Resztę poprawki zapisaliśmy.')
            ->assertDontSee('name="note"', false)
            ->assertDontSee('Ktoś zgłosił to wykonanie')
            ->assertDontSee($zgloszenie->getKey())
            ->assertDontSee('widz2884');
        $this->get(route('admin.reports'))->assertNotFound();
        $this->assertSame($status, $zgloszenie->fresh()->status);
        $this->assertSame(self::UWAGA, $this->cytat($this->actingAs($moderator)->get(route('admin.reports', ['status' => 'wszystkie']))->assertOk()), 'UWAGA_2884_CYTAT_MODERATORA');
    }

    /** @return array<string, array{string}> */
    public static function zakonczone(): array
    {
        return ['resolved' => [Report::STATUS_RESOLVED], 'rejected' => [Report::STATUS_REJECTED]];
    }

    #[DataProvider('zakonczone')]
    public function test_zakonczenie_sprawy_odblokowuje_uwage(string $status): void
    {
        $zgloszenie = $this->zgloszenie($this->wskazowka);
        $this->wycofaj();
        $zgloszenie->forceFill(['status' => $status, 'resolved_at' => now()])->save();
        $this->popraw()->assertSessionHasNoErrors();
        $this->assertSame(self::ZMIENIONA, $this->wykonanie->fresh()->note);
    }

    public function test_obce_zgloszenie_wskazowki_nie_blokuje_innego_wykonania(): void
    {
        $inne = CookedEvent::factory()->create(['user_id' => $this->kucharz->getKey(), 'recipe_id' => $this->wykonanie->recipe_id]);
        $wskazowka = RecipeHint::factory()->dlaWykonania($inne, RecipeHint::STATUS_ACCEPTED)->create();
        $this->zgloszenie($wskazowka);
        $wskazowka->wycofaj();
        $wskazowka->save();
        $this->wycofaj();
        $this->popraw()->assertSessionHasNoErrors();
        $this->assertSame(self::ZMIENIONA, $this->wykonanie->fresh()->note);
    }

    public function test_ukrycie_moderacyjne_nie_odbiera_wycofania_ani_ochrony_uwagi(): void
    {
        $this->zgloszenie($this->wskazowka);
        $this->wskazowka->ukryjPrzezModeracje();
        $this->wskazowka->save();
        $this->wycofaj();
        $this->popraw()->assertSessionHasErrors('wersja');
        $this->assertSame(self::UWAGA, $this->wykonanie->fresh()->note);
        $this->assertNotNull($this->wskazowka->fresh()->moderation_hidden_at);
    }

    private function zgloszenie(RecipeHint $wskazowka): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(), 'target_type' => 'recipe_hint',
            'target_id' => $wskazowka->getKey(), 'status' => Report::STATUS_OPEN, 'reason' => 'spam',
        ]);
    }

    private function wycofaj(): void
    {
        $this->actingAs($this->kucharz)->post(route('hints.withdraw', $this->wskazowka))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $this->wskazowka->fresh()->status);
        $przepis = $this->wykonanie->recipe;
        $this->assertNotNull($przepis);
        $strona = $this->get(route('recipes.show', $przepis->slug))->assertOk();
        $this->assertSame(0, $this->xpath($strona)->query('//section[@aria-labelledby="wskazowki-gotujacych"]//blockquote')->length);
    }

    private function popraw(bool $podazajZaPowrotem = false): TestResponse
    {
        $this->actingAs($this->kucharz);
        $formularz = $this->get(route('cooked.edit', $this->wykonanie))->assertOk();
        $wersja = $this->xpath($formularz)->query('//input[@name="wersja"]/@value')->item(0);
        $this->assertNotNull($wersja);
        $formularz->assertSee('name="changes_note"', false)->assertSee('name="actual_minutes"', false);

        if ($podazajZaPowrotem) {
            $this->followingRedirects();
        }

        return $this->from(route('cooked.edit', $this->wykonanie))->put(route('cooked.update', $this->wykonanie), [
            'wersja' => $wersja->nodeValue, 'note' => self::ZMIENIONA,
            'changes_note' => 'Poprawiony opis.', 'actual_minutes' => '20',
        ]);
    }

    private function cytat(TestResponse $odpowiedz): string
    {
        $cytat = $this->xpath($odpowiedz)->query('//blockquote[contains(@class,"wskazowka-cytat")]')->item(0);
        $this->assertNotNull($cytat);

        return $cytat->textContent;
    }

    private function xpath(TestResponse $odpowiedz): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.(string) $odpowiedz->getContent());

        return new DOMXPath($dom);
    }
}
