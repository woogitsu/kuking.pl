<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\DlugoscZawieszenia;
use App\Domain\Moderation\OdpowiedzDlaZglaszajacego;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EKRAN ODWOŁANIA ZGŁASZAJĄCEGO NIE MÓWI, JAKĄ KARĘ DOSTAŁ AUTOR
 * (issue #800, DSA art. 16 ust. 5; Luka 3 z `docs/research/DSA-LUKI.md`).
 *
 * CO ZMIERZONO PRZED ZMIANĄ — sonda na nietkniętym `534e0a51`, PostgreSQL
 * 55439, prawdziwy podpisany link z `DecyzjaWSprawieZgloszenia`: dla `warn`,
 * `suspend` i `ban` w HTML-u strony odwołania stała dokładna etykieta
 * z panelu moderatora — „Ostrzeżenie dla autora", „Zawieś konto autora",
 * „Zablokuj konto autora na stałe". Ta sama informacja jest świadomie
 * pominięta w liście z decyzją i na karcie sprawy, bo rodzaj kary wymierzonej
 * osobie trzeciej to jej dane osobowe.
 *
 * DLACZEGO TEST NA RENDEROWANYM HTML, A NIE NA KLASIE FORMATUJĄCEJ
 * `OdpowiedzDlaZglaszajacego` była poprawna przez cały czas — to WIDOK
 * chodził obok niej, po `ModerationAction::label()`. Test samej klasy
 * przechodziłby na zepsutym ekranie i niczego by nie pilnował.
 */
class OdwolanieZglaszajacegoNieUjawniaKaryTest extends TestCase
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
     * Zgłoszenie PRAWNE (od osoby bez konta) wraz z decyzją podjętą przez
     * panel — czyli dokładnie tą drogą, która generuje podpisany link
     * do odwołania dla zgłaszającego.
     *
     * @return array{0: Report, 1: ModerationAction}
     */
    private function sprawaZDecyzja(string $akcja): array
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

        $formularz = [
            'action' => $akcja,
            'reason_code' => 'brak_naruszenia',
            'user_message' => 'Wyjaśnienie dla autora treści.',
        ];

        if ($akcja === ModerationAction::ACTION_SUSPEND) {
            $formularz['suspend_days'] = (string) DlugoscZawieszenia::GOTOWE[1];
        }

        $this->actingAs($this->moderator())
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), $formularz)
            ->assertRedirect(route('admin.reports'));

        return [
            $zgloszenie->refresh(),
            ModerationAction::query()->where('report_id', $zgloszenie->getKey())->firstOrFail(),
        ];
    }

    /** Podpisany link DOKŁADNIE taki, jaki dostaje zgłaszający w liście. */
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

        $this->assertNotNull($link, 'List z decyzją powinien nieść podpisany link do odwołania.');

        return (string) $link;
    }

    /** @return array<string, list<string>> */
    public static function karyKonta(): array
    {
        return [
            'ostrzeżenie' => [ModerationAction::ACTION_WARN],
            'zawieszenie' => [ModerationAction::ACTION_SUSPEND],
            'ban' => [ModerationAction::ACTION_BAN],
        ];
    }

    /**
     * SEDNO #800: kara konta nie ma prawa pokazać się zgłaszającemu, mimo że
     * podpis jest prawdziwy, a sprawa jego własna. Ważny podpis potwierdza
     * TOŻSAMOŚĆ, nie jest zgodą na ujawnienie wszystkich pól modelu.
     */
    #[DataProvider('karyKonta')]
    public function test_kara_konta_nie_wychodzi_na_ekran_zglaszajacego(string $akcja): void
    {
        Notification::fake();

        [, $decyzja] = $this->sprawaZDecyzja($akcja);
        $link = $this->podpisanyLinkZMaila();

        $odpowiedz = $this->get($link)->assertOk();

        // Nie sama etykieta tej decyzji: ŻADEN z trzech wariantów nie ma
        // prawa tu być. Inaczej test przechodziłby dlatego, że akurat ten
        // rodzaj kary wypadł z widoku, a sąsiedni nie.
        foreach (self::karyKonta() as [$kara]) {
            $odpowiedz->assertDontSee(ModerationAction::ETYKIETY[$kara]);
        }

        // KONTROLA DODATNIA: blok nie jest pusty — zgłaszający dostaje
        // zdanie z kontraktu odpowiedzi dla swojej roli, a nie ciszę.
        $skutek = OdpowiedzDlaZglaszajacego::skutek($decyzja);
        $odpowiedz->assertSee($skutek['naglowek'])->assertSee($skutek['reszta']);
    }

    /**
     * Decyzje dotyczące TREŚCI nadal mówią zgłaszającemu zrozumiale, co się
     * stało — i nadal mają prawo to mówić. Bez tego poprawka #800 mogłaby
     * „naprawić" wyciek, zabierając przy okazji uzasadnienie potrzebne
     * do odwołania.
     */
    public function test_decyzje_o_tresci_daja_zrozumialy_opis_a_nie_pusty_blok(): void
    {
        foreach ([
            ModerationAction::ACTION_NONE,
            ModerationAction::ACTION_HIDE,
            ModerationAction::ACTION_REMOVE,
        ] as $akcja) {
            Notification::fake();

            [, $decyzja] = $this->sprawaZDecyzja($akcja);
            $skutek = OdpowiedzDlaZglaszajacego::skutek($decyzja);

            $this->get($this->podpisanyLinkZMaila())
                ->assertOk()
                ->assertSee($skutek['naglowek'])
                ->assertSee($skutek['reszta']);
        }
    }

    /**
     * Nagłówek decyzji stoi POZA gałęziami `@if` widoku, więc każdy stan
     * ekranu trzeba sprawdzić osobno: formularz, odwołanie oczekujące
     * i odwołanie rozstrzygnięte.
     */
    public function test_zaden_stan_ekranu_nie_pokazuje_kary(): void
    {
        Notification::fake();

        [$zgloszenie, $decyzja] = $this->sprawaZDecyzja(ModerationAction::ACTION_SUSPEND);
        $link = $this->podpisanyLinkZMaila();

        // 1. Formularz — odwołania jeszcze nie ma.
        $this->get($link)->assertOk()
            ->assertSee('Wyślij odwołanie')
            ->assertDontSee($decyzja->label());

        // 2. Odwołanie złożone i oczekujące.
        $this->post($link, ['body' => 'Uważam, że ta decyzja była błędna i proszę o ponowne sprawdzenie.'])
            ->assertRedirect();

        $this->get($link)->assertOk()
            ->assertSee('Twoje odwołanie')
            ->assertDontSee($decyzja->label());

        // 3. Odwołanie rozstrzygnięte.
        Appeal::query()->where('report_id', $zgloszenie->getKey())->firstOrFail()
            ->forceFill([
                'status' => Appeal::STATUS_UPHELD,
                'decided_at' => now(),
                'decision_note' => 'Podtrzymujemy decyzję — wyjaśnienie dla Ciebie.',
            ])->save();

        $this->get($link)->assertOk()
            ->assertSee('Nasza odpowiedź')
            ->assertDontSee($decyzja->label());
    }

    /**
     * KONTROLA DODATNIA DLA DRUGIEJ ROLI: autor treści ma prawo wiedzieć,
     * jaką karę dostał NA WŁASNYM koncie — i nadal ją widzi. Bez tego testu
     * poprawka #800 mogłaby po cichu odciąć informację także jemu.
     */
    public function test_autor_tresci_nadal_widzi_swoja_kare(): void
    {
        Notification::fake();

        [, $decyzja] = $this->sprawaZDecyzja(ModerationAction::ACTION_SUSPEND);

        $autor = User::query()->findOrFail($decyzja->subject_user_id);

        $this->actingAs($autor)
            ->get(route('appeals.show', $decyzja))
            ->assertOk()
            ->assertSee($decyzja->label());
    }
}
