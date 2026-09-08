<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;
use Throwable;

/**
 * Jedno zgłoszenie — jedna decyzja, także przy dwóch żądaniach naraz
 * (audyt W3-09, W6-01).
 *
 * CO BYŁO NIE TAK
 * `decide()` sprawdzał `if ($report->status !== open)` i miał nad tym
 * komentarz mówiący, że to chroni przed podwójną decyzją. Nie chroniło:
 * między odczytem a zapisem jest okno, w którym drugie żądanie widzi jeszcze
 * `open`. Dwie karty moderatora wykonywały więc dwie kary, tworzyły dwa wpisy
 * w logu i wysyłały dwa powiadomienia. Baza też tego nie łapała —
 * `moderation_actions.report_id` nie miało ograniczenia unikalności.
 *
 * To był komentarz pewniejszy niż kod. Boli tym bardziej, że przy odwołaniu
 * (DSA art. 17) log moderacji musi jednoznacznie mówić, JAKA decyzja zapadła
 * i dlaczego — a dwa sprzeczne wpisy tego nie mówią.
 *
 * DWIE WARSTWY, CELOWO
 * Transakcja z `lockForUpdate()` chroni ruch przez kontroler. Unikalny indeks
 * częściowy chroni WSZYSTKO — także komendę konsolową, seeder i przyszły
 * endpoint API, których autor o blokadzie nie będzie pamiętał.
 */
class JednaDecyzjaNaZgloszenieTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenie(): Report
    {
        $autor = $this->user('autor');
        $zglaszajacy = $this->user('zglaszajacy');

        $wpis = Post::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);

        return Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_druga_decyzja_dla_tego_samego_zgloszenia_nie_przechodzi(): void
    {
        $zgloszenie = $this->zgloszenie();
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam',
            ])
            ->assertSessionHasNoErrors();

        // Druga karta moderatora, wysłana po pierwszej.
        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_REMOVE,
                'reason_code' => 'spam',
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame(
            1,
            ModerationAction::where('report_id', $zgloszenie->getKey())->count(),
            'Powstały dwie decyzje dla jednego zgłoszenia — log moderacji przestał być jednoznaczny.',
        );
    }

    public function test_baza_odbija_druga_decyzje_nawet_z_pominieciem_kontrolera(): void
    {
        // NAJWAŻNIEJSZY TEST W TYM PLIKU. Blokada w kontrolerze chroni jedną
        // drogę; komenda konsolowa, seeder albo przyszły endpoint API pójdą
        // inną i nikt o tej blokadzie nie będzie pamiętał.
        $zgloszenie = $this->zgloszenie();
        $moderator = $this->moderator();

        $wiersz = [
            'moderator_id' => $moderator->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'target_type' => $zgloszenie->target_type,
            'target_id' => $zgloszenie->target_id,
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
            'created_at' => now(),
        ];

        DB::table('moderation_actions')->insert(['id' => (string) Str::uuid()] + $wiersz);

        $this->expectException(QueryException::class);

        DB::table('moderation_actions')->insert(['id' => (string) Str::uuid()] + $wiersz);
    }

    public function test_decyzje_z_wlasnej_inicjatywy_nie_blokuja_sie_wzajemnie(): void
    {
        // `report_id` bywa NULL: moderator może działać bez zgłoszenia.
        // Indeks jest CZĘŚCIOWY właśnie po to — inaczej druga taka decyzja
        // odbiłaby się o ograniczenie, które jej nie dotyczy.
        $moderator = $this->moderator();
        $autor = $this->user('autor');

        foreach ([1, 2] as $i) {
            $wpis = Post::factory()->for($autor, 'author')->create([
                'status' => 'published',
                'visibility' => 'public',
                'published_at' => now()->subHour(),
            ]);

            DB::table('moderation_actions')->insert([
                'id' => (string) Str::uuid(),
                'moderator_id' => $moderator->getKey(),
                'report_id' => null,
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'wlasna-inicjatywa',
                'created_at' => now(),
            ]);
        }

        $this->assertSame(2, ModerationAction::whereNull('report_id')->count());
    }

    /**
     * KONTROLA: udana decyzja dowozi WSZYSTKIE skutki naraz.
     *
     * Bez tej pary test niżej przechodziłby także wtedy, gdyby decyzja
     * przestała robić cokolwiek — „nic nie zostało" jest wtedy prawdą
     * z niewłaściwego powodu.
     */
    public function test_udana_decyzja_dowozi_wszystkie_skutki(): void
    {
        $zgloszenie = $this->zgloszenie();

        $this->assertSame(Report::STATUS_OPEN, $zgloszenie->status);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam',
            ])
            ->assertSessionHasNoErrors();

        $zgloszenie->refresh();

        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->status);
        $this->assertNotNull($zgloszenie->resolved_at);
        $this->assertSame(1, ModerationAction::where('report_id', $zgloszenie->getKey())->count());
    }

    /**
     * WŁAŚCIWY POMIAR TRANSAKCJI: awaria W ŚRODKU decyzji nie zostawia połowy
     * zmian.
     *
     * Poprzednia wersja tego testu nosiła tę samą nazwę, a wysyłała decyzję,
     * która się UDAWAŁA — czyli asertowała happy path drugi raz (jest wyżej,
     * jako kontrola) i o transakcji nie mówiła nic. Zmierzone: po wyjęciu
     * `DB::transaction()` z `ModerationController::decide()` tamta wersja
     * zostawała zielona (4/4).
     *
     * Awaria idzie z KROKU W ŚRODKU transakcji: wysyłki odpowiedzi do
     * zgłaszającego (DSA art. 16 ust. 5). Stoi ona po zapisaniu decyzji
     * do logu moderacji i po wykonaniu kary, a PRZED zamknięciem zgłoszenia
     * i przed wpisem do dziennika audytu — czyli dokładnie tam, gdzie
     * „połowa zmian" boli najbardziej: kara wykonana, zgłoszenie nadal
     * otwarte, a przy odwołaniu (DSA art. 17) nie ma z czego odtworzyć,
     * co właściwie zaszło.
     */
    public function test_awaria_w_srodku_decyzji_nie_zostawia_polowy_zmian(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);

        // Zgłoszenie PRAWNE z adresem do odpowiedzi (DSA art. 16 ust. 5):
        // tylko przy takim `decide()` wysyła list do zgłaszającego, a ten
        // krok stoi W ŚRODKU transakcji — po zapisie decyzji i po wykonaniu
        // kary, a PRZED zamknięciem zgłoszenia.
        $zgloszenie = Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'notifier_email' => 'zglaszajacy@example.test',
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'target_url' => 'https://kuking.pl/wpisy/'.$wpis->getKey(),
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]);

        // Awaria wysyłki: sterownik poczty, którego nie ma w konfiguracji.
        // Bez atrapy i bez podmieniania klas (`NotifyModerationDecision`
        // jest `final`, więc Mockery jej nie zastąpi), a jednocześnie NIE
        // przez błąd bazy — awaria SQL-a zatruwa transakcję `RefreshDatabase`
        // i asercje niżej nie miałyby jak się wykonać.
        config(['mail.default' => 'nie-ma-takiego-sterownika']);

        try {
            $this->withoutExceptionHandling()
                ->actingAs($this->moderator())
                ->post(route('admin.reports.decide', $zgloszenie), [
                    'action' => ModerationAction::ACTION_SUSPEND,
                    'reason_code' => 'spam',
                    'suspend_days' => '7',
                ]);

            $this->fail('Awaria w środku decyzji nie doszła do wywołującego.');
        } catch (Throwable $e) {
            // Wyjątek jest pożądany — moderator ma zobaczyć błąd, nie „zapisano".
            $this->assertNotInstanceOf(
                ExpectationFailedException::class,
                $e,
                'To nie awaria wysyłki przerwała decyzję, a asercja tego testu.',
            );
        }

        // ŻADEN ze skutków decyzji nie może zostać.
        $this->assertSame(
            0,
            ModerationAction::where('report_id', $zgloszenie->getKey())->count(),
            'Wpis w logu moderacji został po awarii, choć decyzja nie doszła do końca.',
        );
        $this->assertSame(
            User::STATUS_ACTIVE,
            $autor->refresh()->status,
            'Kara została wykonana, choć decyzja nie doszła do końca — a zgłoszenie '
            .'jest nadal otwarte, więc nic tego nie tłumaczy.',
        );
        $this->assertNull($autor->status_expires_at);
        $this->assertSame(Report::STATUS_OPEN, $zgloszenie->refresh()->status);
        $this->assertNull($zgloszenie->resolved_at);
        $this->assertNull($zgloszenie->decision_sent_at);
        $this->assertSame(
            0,
            AuditLogEntry::where('action', 'moderation.decided')->count(),
            'Dziennik audytu zapisał decyzję, której nie było.',
        );

        // ASERCJA KONTROLNA — bez niej „nic nie zostało" byłoby prawdą
        // z niewłaściwego powodu: żądanie mogłoby odpaść na walidacji albo
        // na macierzy `DOZWOLONE`, czyli PRZED transakcją, i test niczego
        // by nie mierzył. Ten sam wsad, poczta sprawna — decyzja przechodzi
        // w komplecie, więc jedyną przyczyną pustki wyżej była wstrzyknięta
        // awaria w środku transakcji.
        config(['mail.default' => 'array']);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_SUSPEND,
                'reason_code' => 'spam',
                'suspend_days' => '7',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ModerationAction::where('report_id', $zgloszenie->getKey())->count());
        $this->assertSame(User::STATUS_SUSPENDED, $autor->refresh()->status);
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->refresh()->status);
    }
}
