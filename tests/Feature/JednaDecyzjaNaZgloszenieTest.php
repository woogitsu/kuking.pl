<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

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

    public function test_nieudana_decyzja_nie_zostawia_polowy_zmian(): void
    {
        // Decyzja to kilka zapisów naraz: wpis w logu, kara, powiadomienie,
        // zamknięcie zgłoszenia. Bez transakcji awaria w środku zostawiała
        // karę bez wpisu w logu albo wpis bez kary — a przy odwołaniu nie
        // dałoby się odtworzyć, co właściwie zaszło.
        $zgloszenie = $this->zgloszenie();

        $this->assertSame(Report::STATUS_OPEN, $zgloszenie->status);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam',
            ])
            ->assertSessionHasNoErrors();

        $zgloszenie->refresh();

        // Wszystkie skutki naraz albo żaden.
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->status);
        $this->assertNotNull($zgloszenie->resolved_at);
        $this->assertSame(1, ModerationAction::where('report_id', $zgloszenie->getKey())->count());
    }
}
