<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Post;
use App\Models\Report;
use App\Moderacja\KlientOpenAI;
use App\Moderacja\ModelChwilowoNiedostepny;
use App\Moderacja\OcenaModelem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * CHWILOWA AWARIA MODELU TO NIE „OCENA BEZ TRAFIEŃ" (#1662).
 *
 * Do września 2026 timeout, 429 i 5xx kończyły `PrzeanalizujTresc` jak
 * pełny sukces: treść na zawsze zostawała bez oceny modelem, a w kolejce
 * nie zostawał żaden ślad. Te testy pilnują trzech wyników zamiast dwóch:
 * ocena wykonana, brak oceny bez sensu ponawiania, brak oceny do ponowienia.
 *
 * Próby prowadzimy ręcznie (`withFakeQueueInteractions()` i numer próby),
 * bo kolejka `sync` nie ponawia zadań — a sedno leży w tym, co zadanie robi
 * MIĘDZY próbami: nie zapisuje nic i przy następnej pyta o treść od nowa.
 */
class ModeracjaPonowienieModeluTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.moderation.sygnaly.wlaczone' => true,
            'kuking.moderation.model.klucz' => 'testowy-klucz',
            'kuking.moderation.model.alarm_email' => 'moderacja@example.test',
            'kuking.moderation.model.ocenia_zdjecia' => false,
        ]);

        Notification::fake();
    }

    private function wpis(string $tekst): Post
    {
        $autor = $this->user('ponawiany');
        $autor->forceFill(['created_at' => now()->subDays(400)])->save();

        return Post::create([
            'author_id' => $autor->getKey(),
            'body' => $tekst,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /** @param array<string, float> $wyniki */
    private static function ocena(array $wyniki): array
    {
        return ['results' => [['flagged' => false, 'category_scores' => $wyniki]]];
    }

    /** @param list<mixed> $odpowiedzi kolejne odpowiedzi dostawcy */
    private function dostawcaOdpowiada(array $odpowiedzi): void
    {
        $sekwencja = Http::sequence();

        foreach ($odpowiedzi as $odpowiedz) {
            $sekwencja->pushResponse($odpowiedz);
        }

        Http::fake(['*api.openai.com*' => $sekwencja]);
    }

    private function proba(Post $wpis, int $numer): PrzeanalizujTresc
    {
        $zadanie = (new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, (string) $wpis->getKey()))
            ->withFakeQueueInteractions();
        $zadanie->job->attempts = $numer;

        app()->call([$zadanie, 'handle']);

        return $zadanie;
    }

    /** @return Collection<int, Report> */
    private function oznaczenia(): Collection
    {
        return Report::query()->where('source', Report::SOURCE_AUTOMAT)->get();
    }

    private function opoznienie(PrzeanalizujTresc $zadanie): int
    {
        $zadanie->assertReleased();

        return (int) $zadanie->job->releaseDelay;
    }

    public function test_timeout_wraca_do_kolejki_a_nastepna_proba_ocenia(): void
    {
        $this->dostawcaOdpowiada([
            Http::failedConnection(),
            Http::response(self::ocena(['hate' => 0.91])),
        ]);
        $wpis = $this->wpis('Zwykły wpis o zupie, oceniany w czasie awarii sieci.');

        $pierwsza = $this->proba($wpis, 1);

        $this->assertThat($this->opoznienie($pierwsza), $this->logicalAnd($this->greaterThanOrEqual(30), $this->lessThanOrEqual(36)));
        $pierwsza->assertNotFailed();
        $this->assertCount(0, $this->oznaczenia(), 'Przy awarii zapisano ocenę, której nie było.');

        $druga = $this->proba($wpis, 2);

        $druga->assertNotReleased();
        $druga->assertNotFailed();
        $this->assertCount(1, $this->oznaczenia());
        $this->assertSame(OcenaModelem::KOD, $this->oznaczenia()->first()->reason);
    }

    public function test_429_czeka_co_najmniej_tyle_ile_kaze_retry_after(): void
    {
        $this->dostawcaOdpowiada([
            Http::response('', 429, ['Retry-After' => '90']),
            Http::response(self::ocena(['violence' => 0.95])),
        ]);
        $wpis = $this->wpis('Zwykły wpis o zupie, oceniany przy limicie zapytań.');

        $opoznienie = $this->opoznienie($this->proba($wpis, 1));

        $this->assertThat($opoznienie, $this->logicalAnd($this->greaterThanOrEqual(90), $this->lessThanOrEqual(108)));

        $this->proba($wpis, 2)->assertNotReleased();
        $this->assertCount(1, $this->oznaczenia());
    }

    public function test_retry_after_nie_odsuwa_oceny_w_nieskonczonosc(): void
    {
        $this->dostawcaOdpowiada([Http::response('', 429, ['Retry-After' => '86400'])]);

        $this->assertLessThanOrEqual(600, $this->opoznienie($this->proba($this->wpis('Wpis przy długim limicie.'), 1)));
    }

    public function test_500_wraca_do_kolejki_z_dluzszym_opoznieniem_przy_drugiej_probie(): void
    {
        $this->dostawcaOdpowiada([
            Http::response('', 500),
            Http::response('', 502),
            Http::response(self::ocena(['hate' => 0.91])),
        ]);
        $wpis = $this->wpis('Zwykły wpis o zupie, oceniany przy awarii dostawcy.');

        $this->assertLessThanOrEqual(36, $this->opoznienie($this->proba($wpis, 1)));
        $this->assertGreaterThanOrEqual(120, $this->opoznienie($this->proba($wpis, 2)));

        $this->proba($wpis, 3)->assertNotFailed();
        $this->assertCount(1, $this->oznaczenia());
    }

    public function test_400_nie_jest_ponawiany(): void
    {
        $this->dostawcaOdpowiada([Http::response('', 400), Http::response(self::ocena(['hate' => 0.91]))]);

        $zadanie = $this->proba($this->wpis('Zwykły wpis o zupie, odrzucony przez dostawcę.'), 1);

        $zadanie->assertNotReleased();
        $zadanie->assertNotFailed();
        Http::assertSentCount(1);
        $this->assertCount(0, $this->oznaczenia());
    }

    public function test_bez_klucza_nic_nie_wychodzi_i_nic_nie_jest_ponawiane(): void
    {
        config(['kuking.moderation.model.klucz' => null]);
        Http::fake();

        $zadanie = $this->proba($this->wpis('Zwykły wpis o zupie, bez skonfigurowanego modelu.'), 1);

        $zadanie->assertNotReleased();
        $zadanie->assertNotFailed();
        Http::assertNothingSent();
    }

    public function test_trzy_porazki_zapisuja_lokalny_sygnal_i_zostawiaja_slad_operacyjny(): void
    {
        $log = Log::spy();
        $this->dostawcaOdpowiada([Http::response('', 503), Http::response('', 503), Http::response('', 503)]);
        $wpis = $this->wpis('Ciasta na zamówienie, tel. 600 100 200.');

        $this->proba($wpis, 1)->assertReleased();
        $this->proba($wpis, 2)->assertReleased();

        // Lokalny sygnał CZEKA na ocenę modelu, bo oznaczenie postawione bez
        // niej zamknęłoby drogę ocenie z następnej próby.
        $this->assertCount(0, $this->oznaczenia());

        $ostatnia = $this->proba($wpis, 3);

        $ostatnia->assertNotReleased();
        $ostatnia->assertFailedWith(ModelChwilowoNiedostepny::class);

        $oznaczenia = $this->oznaczenia();
        $this->assertCount(1, $oznaczenia, 'Lokalny sygnał przepadł razem z oceną modelu.');
        $this->assertSame(WykrywaczSygnalow::KOD_WZORZEC, $oznaczenia->first()->reason);

        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $komunikat, array $kontekst = []): bool => ($kontekst['stage'] ?? null) === 'openai_retries_exhausted'
                && $kontekst['id'] === (string) $wpis->getKey()
                && ! str_contains(json_encode($kontekst), '600 100 200'),
        )->once();
    }

    public function test_ponowienie_po_porazce_nie_stawia_drugiego_oznaczenia_ani_alarmu(): void
    {
        $this->dostawcaOdpowiada([
            Http::response('', 503),
            Http::response('', 503),
            Http::response('', 503),
            // `queue:retry` po ostatecznej porażce: model tym razem odpowiada
            // kategorią pilną.
            Http::response(self::ocena(['sexual/minors' => 0.99])),
        ]);
        $wpis = $this->wpis('Ciasta na zamówienie, tel. 600 100 200.');

        foreach ([1, 2, 3, 1] as $numer) {
            $this->proba($wpis, $numer);
        }

        $this->assertCount(1, $this->oznaczenia());
        Notification::assertNothingSent();
    }

    public function test_kazda_proba_sprawdza_widocznosc_od_nowa(): void
    {
        $this->dostawcaOdpowiada([Http::failedConnection(), Http::response(self::ocena(['hate' => 0.91]))]);
        $wpis = $this->wpis('Wpis, który autor schował, zanim model odpowiedział.');

        $this->proba($wpis, 1)->assertReleased();

        $wpis->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save();

        $this->proba($wpis, 2)->assertNotReleased();

        Http::assertSentCount(1);
        $this->assertCount(0, $this->oznaczenia());
    }

    public function test_klient_odroznia_awarie_przejsciowa_od_trwalej(): void
    {
        $klient = app(KlientOpenAI::class);

        // Kolejne `Http::fake()` DOKŁADAJĄ wzorce, a wygrywa pierwszy —
        // jedna atrapa czyta więc bieżącą odpowiedź przez referencję.
        $odpowiedz = null;
        Http::fake(['*api.openai.com*' => function () use (&$odpowiedz) {
            return $odpowiedz;
        }]);

        foreach ([429, 500, 502, 503, 504] as $status) {
            $odpowiedz = Http::response('', $status);

            try {
                $klient->ocenTekst('Próba');
                $this->fail("HTTP {$status} potraktowany jak brak oceny bez sensu ponawiania.");
            } catch (ModelChwilowoNiedostepny) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ([400, 401, 403, 404, 422, 501] as $status) {
            $odpowiedz = Http::response('', $status);

            $this->assertNull($klient->ocenTekst('Próba'), "HTTP {$status} nie powinien być ponawiany.");
        }

        // Poprawna odpowiedź bez trafień to OCENA, nie jej brak.
        $odpowiedz = Http::response(self::ocena(['hate' => 0.01]));
        $wynik = $klient->ocenTekst('Próba');

        $this->assertNotNull($wynik);
        $this->assertFalse($wynik->costamZnalazl());
    }

    public function test_budzet_proby_nie_rosnie(): void
    {
        $zadanie = new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, 'post-id');

        $this->assertSame(PrzeanalizujTresc::PROBY, $zadanie->tries);
        $this->assertSame(3, $zadanie->tries);
        $this->assertSame(30, $zadanie->timeout);
    }

    /**
     * Na kolejce `sync` wydanie do kolejki niczego nie planuje, więc każda
     * próba jest ostatnia: lokalny sygnał nie może czekać na próbę, która
     * nie nadejdzie.
     */
    public function test_na_kolejce_sync_awaria_nie_gubi_lokalnego_sygnalu(): void
    {
        $this->dostawcaOdpowiada([Http::response('', 503)]);
        $wpis = $this->wpis('Ciasta na zamówienie, tel. 600 100 200.');

        dispatch_sync(new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, (string) $wpis->getKey()));

        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->refresh()->status);
        $this->assertCount(1, $this->oznaczenia());
        $this->assertSame(WykrywaczSygnalow::KOD_WZORZEC, $this->oznaczenia()->first()->reason);
    }
}
