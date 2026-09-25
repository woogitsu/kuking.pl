<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adres z publicznego zgłoszenia prawnego to dowód, nie zaufany cel (issue #1636).
 *
 * Do 25 września 2026 kontroler brał z `target_url` samą ścieżkę, więc
 * `https://obcy.example/przepis/<slug>` przypinał się do PRAWDZIWEGO przepisu
 * Kuking o tym slugu. A surowa wartość wracała w liście podpisanym przez
 * Kuking — `[pilne](https://obcy.example)` robiło z niego klikalny odnośnik.
 *
 * Każdy odrzucony adres ZOSTAJE przyjętym zgłoszeniem: DSA art. 16 nie pozwala
 * odmówić mechanizmu, bo nie umiemy rozpoznać adresu.
 */
class ZgloszenieAdresSpozaKukingTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(): Recipe
    {
        return Recipe::factory()->for($this->user('autor'), 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function zglos(string $adres): Report
    {
        Notification::fake();
        $przed = Report::pluck('id');

        $this->post(route('zglos.nielegalna.store'), [
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => $adres,
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki: '.$adres,
            'good_faith' => '1',
        ])->assertSessionHasNoErrors();

        return Report::where('source', Report::SOURCE_LEGAL_NOTICE)->whereNotIn('id', $przed)->sole();
    }

    /** KONTROLA DODATNIA: kanoniczny adres dalej rozpoznaje przepis, wpis i wykonanie. */
    public function test_kanoniczny_adres_przepisu_rozpoznaje_przepis(): void
    {
        $przepis = $this->przepis();

        $zgloszenie = $this->zglos('https://kuking.pl/przepis/'.$przepis->slug);

        $this->assertSame(['recipe', $przepis->getKey()], [$zgloszenie->target_type, $zgloszenie->target_id]);
    }

    public function test_adres_wpisu_na_www_rozpoznaje_wpis(): void
    {
        $uuid = '0199a0b0-1111-7222-8333-444455556666';

        $zgloszenie = $this->zglos('https://www.kuking.pl/wpisy/'.$uuid);

        $this->assertSame(['post', $uuid], [$zgloszenie->target_type, $zgloszenie->target_id]);
    }

    public function test_adres_wykonania_wielkimi_literami_z_portem_domyslnym_rozpoznaje_wykonanie(): void
    {
        $uuid = '0199a0b0-1111-7222-8333-444455556666';

        $zgloszenie = $this->zglos('HTTPS://KUKING.PL:443/ugotowane/'.$uuid);

        $this->assertSame(['cooked_event', $uuid], [$zgloszenie->target_type, $zgloszenie->target_id]);
    }

    /** Host i port TEGO środowiska (`APP_URL`) — staging i preview. */
    public function test_adres_z_app_url_rozpoznaje_przepis(): void
    {
        $przepis = $this->przepis();
        config(['app.url' => 'http://staging.kuking.test:8080']);

        $zgloszenie = $this->zglos('http://staging.kuking.test:8080/przepis/'.$przepis->slug);

        $this->assertSame(['recipe', $przepis->getKey()], [$zgloszenie->target_type, $zgloszenie->target_id]);
    }

    /** @return array<string, array{0: string}> */
    public static function adresyNieKuking(): array
    {
        return [
            'obca domena' => ['https://obcy.example/przepis/{slug}'],
            'host z naszym sufiksem' => ['https://kuking.pl.obcy.example/przepis/{slug}'],
            'host z naszym przedrostkiem' => ['https://obcykuking.pl/przepis/{slug}'],
            'userinfo przed obcym hostem' => ['https://kuking.pl@obcy.example/przepis/{slug}'],
            'userinfo przed naszym hostem' => ['https://ktos@kuking.pl/przepis/{slug}'],
            'inny port' => ['https://kuking.pl:8443/przepis/{slug}'],
            'schemat względny' => ['//kuking.pl/przepis/{slug}'],
            'sama ścieżka' => ['/przepis/{slug}'],
            'bez schematu' => ['kuking.pl/przepis/{slug}'],
            'schemat nie-HTTP' => ['ftp://kuking.pl/przepis/{slug}'],
            'javascript' => ['javascript://kuking.pl/przepis/{slug}'],
            'odwrotny ukośnik' => ['https://obcy.example\\@kuking.pl/przepis/{slug}'],
            'host healthchecku' => ['https://healthcheck.railway.app/przepis/{slug}'],
        ];
    }

    #[DataProvider('adresyNieKuking')]
    public function test_adres_spoza_kuking_jest_przyjety_ale_nie_przypiety_do_naszej_tresci(string $wzor): void
    {
        $przepis = $this->przepis();
        $adres = str_replace('{slug}', $przepis->slug, $wzor);

        $zgloszenie = $this->zglos($adres);

        $this->assertSame(Report::STATUS_OPEN, $zgloszenie->status, 'Zgłoszenie musi trafić do kolejki mimo nierozpoznanego adresu.');
        $this->assertSame($adres, $zgloszenie->target_url, 'Adres ma zostać zapisany dosłownie, jako dowód.');
        $this->assertSame('unknown', $zgloszenie->target_type);
        $this->assertNull($zgloszenie->target_id, "Adres {$adres} przypiął się do przepisu Kuking.");
    }

    private function zgloszenieZAdresem(string $adres): Report
    {
        return Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_id' => null,
            'target_url' => $adres,
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo tak.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan',
            'notifier_email' => 'jan@przyklad.test',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /** @return array<string, string> HTML i tekst obu listów */
    private function listy(Report $zgloszenie): array
    {
        $odbiorca = (new AnonymousNotifiable)->route('mail', 'jan@przyklad.test');

        return [
            'potwierdzenie' => (string) (new PotwierdzenieZgloszeniaNielegalnejTresci($zgloszenie))->toMail($odbiorca)->render(),
            'decyzja' => (string) (new DecyzjaWSprawieZgloszenia($zgloszenie, new ModerationAction(['action' => ModerationAction::ACTION_NONE])))->toMail($odbiorca)->render(),
        ];
    }

    public function test_markdown_w_adresie_nie_robi_linku_w_zadnym_liscie(): void
    {
        $zgloszenie = $this->zgloszenieZAdresem("[pilne](https://obcy.example/zaloguj) ![x](https://obcy.example/p.png)\n\n# Kuking prosi o hasło");

        foreach ($this->listy($zgloszenie) as $ktory => $html) {
            $this->assertStringNotContainsString('href="https://obcy.example', $html, "List „{$ktory}” zrobił z adresu klikalny odnośnik.");
            $this->assertStringNotContainsString('src="https://obcy.example', $html, "List „{$ktory}” zrobił z adresu obrazek.");
            $this->assertStringNotContainsString('<h1>Kuking prosi', $html, "List „{$ktory}” zrobił z adresu nagłówek.");
            $this->assertStringContainsString('[pilne](https://obcy.example/zaloguj)', html_entity_decode(strip_tags(str_replace('\\', '', $html))), "List „{$ktory}” zgubił treść adresu.");
        }
    }

    /** KONTROLA DODATNIA: zwykły adres Kuking jest w liście czytelny, bez śmieci. */
    public function test_zwykly_adres_kuking_jest_w_liscie_czytelny(): void
    {
        $zgloszenie = $this->zgloszenieZAdresem('https://kuking.pl/przepis/rosol-babci?zrodlo=mail&x=1');

        foreach ($this->listy($zgloszenie) as $ktory => $html) {
            $this->assertStringContainsString('Zgłoszona przez Ciebie strona: https://kuking.pl/przepis/rosol-babci?zrodlo=mail&amp;x=1', $html, "List „{$ktory}” zniekształcił zwykły adres.");
        }
    }

    public function test_panel_moderatora_mowi_jakiego_rodzaju_jest_adres(): void
    {
        $post = Post::factory()->create();
        $obcy = $this->zgloszenieZAdresem('https://obcy.example/wpisy/'.$post->getKey());
        // Zgłoszenie sprzed poprawki: obcy adres przypięty do naszej treści.
        $obcy->forceFill(['target_type' => 'post', 'target_id' => $post->getKey()])->save();
        $this->zgloszenieZAdresem('widziałam to wczoraj u was');
        $this->zgloszenieZAdresem('https://kuking.pl/cos/innego');

        $this->actingAs($this->moderator())->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('to adres spoza Kuking albo w nietypowej postaci', false)
            ->assertSee('to nie jest adres strony, tylko opis', false)
            ->assertSee('adres Kuking, ale nie rozpoznaliśmy', false);
    }
}
