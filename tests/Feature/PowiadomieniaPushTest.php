<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Notifications\Push\TransportPush;
use App\Domain\Notifications\Push\WynikWysylkiPush;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Jobs\WyslijPowiadomieniePush;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PushSubscription;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FalszywyTransportPush;
use Tests\TestCase;

/**
 * Web Push (issue #35, D-303): kto dostaje push, kiedy i ile — z fałszywym
 * transportem. Żaden test nie wysyła prawdziwego pushu.
 *
 * Każda asercja „pushu nie ma" stoi obok kontroli dodatniej: powiadomienie
 * w serwisie JEST (pułapka 4 z `docs/PULAPKI_TESTOW.md`) — bo „push
 * nie wyszedł" przechodzi też wtedy, gdy nie powstało nic.
 *
 * Momenty są w UTC; komentarz mówi, która to godzina w Warszawie.
 */
final class PowiadomieniaPushTest extends TestCase
{
    use RefreshDatabase;

    private FalszywyTransportPush $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.strefa' => 'Europe/Warsaw',
            'kuking.push.vapid_public_key' => 'BTestowyKluczPublicznyNieDoWysylki',
            'kuking.push.vapid_private_key' => 'testowy-klucz-prywatny',
            'kuking.notifications.zewnetrzne.wlaczone' => true,
            'kuking.notifications.zewnetrzne.cisza_od_godziny' => 21,
            'kuking.notifications.zewnetrzne.cisza_do_godziny' => 8,
            'kuking.notifications.zewnetrzne.dzienny_limit' => 1,
        ]);

        $this->transport = new FalszywyTransportPush;
        $this->app->instance(TransportPush::class, $this->transport);

        // 12:00 w Warszawie — poza ciszą nocną.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));
    }

    public function test_ugotowalem_idzie_pushem_na_kazde_urzadzenie_autora(): void
    {
        $autor = $this->user('autor_push');
        $kucharz = $this->user('kucharz_push', ['display_name' => 'Marek']);
        $telefon = $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/telefon');
        $komputer = $this->subskrypcja($autor, 'https://updates.push.services.mozilla.com/wpush/v2/komputer');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Pierogi z kaszą']);

        app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->assertSame(1, $autor->notifications()->where('type', Notification::TYPE_COOKED)->count());
        $this->assertEqualsCanonicalizing([$telefon->endpoint, $komputer->endpoint], array_column($this->transport->wyslane, 'endpoint'));

        $tresc = $this->transport->wyslane[0]['tresc'];
        $this->assertSame('Marek — ugotowane z Twojego przepisu „Pierogi z kaszą”.', $tresc['body']);
        $this->assertSame('/powiadomienia', $tresc['url']);
        $this->assertNotNull($autor->notifications()->first()->push_wyslano_at);
    }

    public function test_bez_subskrypcji_nie_ma_pushu_a_powiadomienie_w_serwisie_jest(): void
    {
        Queue::fake();
        $autor = $this->user('autor_bez_urzadzen');

        app(NotifyUser::class)->handle($autor, Notification::TYPE_COOKED, $this->user('kucharz_x'), ['recipe_title' => 'Rosół']);

        $this->assertSame(1, $autor->notifications()->count(), 'Kontrola dodatnia: powiadomienie w serwisie ma powstać zawsze.');
        Queue::assertNotPushed(WyslijPowiadomieniePush::class);
    }

    public function test_bez_klucza_vapid_nie_ma_pushu_ani_ekranu(): void
    {
        $autor = $this->user('autor_bez_klucza');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/bez-klucza');

        config(['kuking.push.vapid_public_key' => null]);

        Queue::fake();
        app(NotifyUser::class)->handle($autor, Notification::TYPE_COOKED, $this->user('kucharz_y'), ['recipe_title' => 'Rosół']);
        $this->assertSame(1, $autor->notifications()->count());
        Queue::assertNotPushed(WyslijPowiadomieniePush::class);

        $this->actingAs($autor)->get(route('settings.notifications'))->assertNotFound();
        $this->actingAs($autor)->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee(route('settings.notifications'));

        // Kontrola dodatnia: z kluczem ekran i pozycja w spisie są.
        config(['kuking.push.vapid_public_key' => 'BTestowyKluczPublicznyNieDoWysylki']);
        $this->actingAs($autor)->get(route('settings.notifications'))->assertOk();
        $this->actingAs($autor)->get(route('settings.index'))->assertSee(route('settings.notifications'));
    }

    public function test_awaryjny_wylacznik_gasi_push_mimo_kluczy(): void
    {
        $autor = $this->user('autor_wylacznik');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/wylacznik');
        config(['kuking.notifications.zewnetrzne.wlaczone' => false]);

        app(NotifyUser::class)->handle($autor, Notification::TYPE_COOKED, $this->user('kucharz_w'), ['recipe_title' => 'Rosół']);

        $this->assertSame(1, $autor->notifications()->count());
        $this->assertSame([], $this->transport->wyslane);
    }

    public function test_odpowiedz_na_komentarz_i_na_pytanie_ida_pushem_a_obserwowanie_i_zwykly_komentarz_nie(): void
    {
        $odbiorca = $this->user('odbiorca_typy');
        $this->subskrypcja($odbiorca, 'https://fcm.googleapis.com/fcm/send/typy');
        config(['kuking.notifications.zewnetrzne.dzienny_limit' => 10]);
        $komentarze = app(PublishComment::class);

        $wpis = Post::factory()->for($odbiorca, 'author')->create();
        app(NotifyUser::class)->handle($odbiorca, Notification::TYPE_FOLLOW, $this->user('obserwujacy'));
        $komentarze->handle($this->user('komentujacy'), $wpis, 'Pięknie wygląda.');

        $this->assertSame(2, $odbiorca->notifications()->count(), 'Kontrola dodatnia: oba powiadomienia są w serwisie.');
        $this->assertSame([], $this->transport->wyslane, 'Obserwowanie i zwykły komentarz nie idą pushem.');

        // Odpowiedź na MÓJ komentarz pod cudzym wpisem.
        $cudzyWpis = Post::factory()->for($this->user('ktos_inny'), 'author')->create();
        $moj = $komentarze->handle($odbiorca, $cudzyWpis, 'U mnie też wyszło.');
        $komentarze->handle($this->user('odpowiadajacy', ['display_name' => 'Ola']), $cudzyWpis, 'A ile mąki?', $moj);

        // Odpowiedź na MOJE pytanie (dział „Poradźcie” jest za flagą).
        config(['kuking.questions.enabled' => true]);
        $pytanie = Post::factory()->question()->for($odbiorca, 'author')->create();
        $komentarze->handle($this->user('odpowiadajacy2', ['display_name' => 'Jan']), $pytanie, 'Dodaj majeranek.');

        $this->assertSame(
            ['Ola — nowa odpowiedź.', 'Jan — odpowiedź na Twoje pytanie.'],
            array_map(fn (array $w): string => $w['tresc']['body'], $this->transport->wyslane),
        );
    }

    public function test_cisza_nocna_odklada_do_rana_i_nie_kasuje(): void
    {
        $autor = $this->user('autor_noc');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/noc');

        // 23:00 w Warszawie.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 21:00:00', 'UTC'));
        $this->powiadomienie($autor, 'Bigos');

        Queue::fake();
        $this->uruchomZadanie($autor);

        $this->assertSame([], $this->transport->wyslane, 'W ciszy nocnej nic nie wychodzi.');
        Queue::assertPushed(WyslijPowiadomieniePush::class, fn (WyslijPowiadomieniePush $job): bool => $job->userId === $autor->getKey()
            && CarbonImmutable::instance($job->delay)->utc()->format('Y-m-d H:i') === '2026-09-26 06:00');

        // 8:00 rano — to samo powiadomienie wychodzi.
        $this->travelTo(CarbonImmutable::parse('2026-09-26 06:00:00', 'UTC'));
        $this->uruchomZadanie($autor);

        $this->assertCount(1, $this->transport->wyslane);
        $this->assertStringContainsString('Bigos', $this->transport->wyslane[0]['tresc']['body']);
    }

    public function test_cisza_nocna_wybrana_przez_czlowieka_zastepuje_domyslna(): void
    {
        $autor = $this->user('autor_wlasna_cisza');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/wlasna');
        $autor->ustawieniaPowiadomienZewnetrznych()->create(['cisza_od' => 23, 'cisza_do' => 7, 'dzienny_limit' => 1]);

        // 22:00 w Warszawie: w domyślnej ciszy, ale ta osoba wybrała 23–7.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 20:00:00', 'UTC'));
        $this->powiadomienie($autor, 'Żurek');
        $this->uruchomZadanie($autor);

        $this->assertCount(1, $this->transport->wyslane);
    }

    public function test_limit_dobowy_odklada_nadmiar_i_grupuje_go_w_jeden_push(): void
    {
        $autor = $this->user('autor_limit');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/limit');

        $this->powiadomienie($autor, 'Pierwsze');
        $this->uruchomZadanie($autor);
        $this->assertCount(1, $this->transport->wyslane, 'Pierwszy push w dobie wychodzi od razu.');

        $this->travelTo(CarbonImmutable::parse('2026-09-25 11:00:00', 'UTC'));
        $this->powiadomienie($autor, 'Drugie');
        $this->travelTo(CarbonImmutable::parse('2026-09-25 11:05:00', 'UTC'));
        $this->powiadomienie($autor, 'Trzecie');

        Queue::fake();
        $this->uruchomZadanie($autor);
        $this->assertCount(1, $this->transport->wyslane, 'Limit 1 dziennie: drugi push czeka.');
        Queue::assertPushed(WyslijPowiadomieniePush::class, fn (WyslijPowiadomieniePush $job): bool => CarbonImmutable::instance($job->delay)->utc()->format('Y-m-d H:i') === '2026-09-26 06:00');

        $this->travelTo(CarbonImmutable::parse('2026-09-26 06:00:00', 'UTC'));
        $this->uruchomZadanie($autor);

        $this->assertCount(2, $this->transport->wyslane, 'Rano oba czekające powiadomienia idą JEDNYM pushem.');
        $this->assertSame(
            'Testowa osoba — ugotowane z Twojego przepisu „Trzecie”. Do tego 1 inne powiadomienie.',
            $this->transport->wyslane[1]['tresc']['body'],
        );
        $this->assertSame(0, $autor->notifications()->whereNull('push_wyslano_at')->count());
    }

    /**
     * Drugi worker startuje, gdy pierwszy jest już w transporcie (po COMMIT
     * rezerwacji). Dotychczasowy licznik sukcesów widzi wtedy zero — to
     * kontrola ujemna odtwarzająca #1992 bez zewnętrznej wysyłki.
     */
    public function test_rownolegly_transport_nie_przydziela_drugiego_slotu(): void
    {
        $autor = $this->user('autor_wyscig_push');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/wyscig');
        $pierwsze = $this->powiadomienie($autor, 'Pierwsze');
        Queue::fake();

        $drugiTransport = new FalszywyTransportPush;
        $wstrzymano = false;
        $transport = new class(function () use ($autor, $drugiTransport, &$wstrzymano): void {
            $wstrzymano = true;
            $this->assertNull($autor->notifications()->first()->push_wyslano_at);
            $this->assertNotNull($autor->notifications()->first()->push_proba_at);
            $this->powiadomienie($autor, 'Drugie');
            (new WyslijPowiadomieniePush((string) $autor->getKey()))->handle($drugiTransport);
        }) implements TransportPush
        {
            public int $wywolania = 0;

            public function __construct(private readonly \Closure $wTrakcieWysylki) {}

            public function wyslij(PushSubscription $subskrypcja, string $tresc): WynikWysylkiPush
            {
                $this->wywolania++;
                ($this->wTrakcieWysylki)();

                return WynikWysylkiPush::Wyslano;
            }
        };

        (new WyslijPowiadomieniePush((string) $autor->getKey()))->handle($transport);

        $this->assertTrue($wstrzymano, 'Kontrola dodatnia: transport pierwszej grupy nie ruszył.');
        $this->assertSame(1, $transport->wywolania);
        $this->assertSame([], $drugiTransport->wyslane, 'Równoległy worker przekroczył limit 1.');
        $this->assertNotNull($pierwsze->refresh()->push_wyslano_at);
        $this->assertSame(1, $autor->notifications()->whereNull('push_proba_at')->count());
        Queue::assertPushed(WyslijPowiadomieniePush::class, 1);
    }

    public function test_rezerwacja_z_wczoraj_zajmuje_slot_podczas_trwajacego_retry(): void
    {
        $autor = $this->user('autor_retry_przez_polnoc');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/polnoc');
        $pierwsze = $this->powiadomienie($autor, 'Pierwsze');
        $pierwsze->forceFill(['push_proba_at' => now()])->save();

        $this->travelTo(CarbonImmutable::parse('2026-09-26 06:00:00', 'UTC'));
        $drugie = $this->powiadomienie($autor, 'Drugie');
        Queue::fake();
        $this->uruchomZadanie($autor);

        $this->assertSame([], $this->transport->wyslane);
        $this->assertNull($drugie->refresh()->push_proba_at);
        Queue::assertPushed(WyslijPowiadomieniePush::class, 1);
    }

    public function test_przeczytane_w_serwisie_nie_idzie_pushem(): void
    {
        $autor = $this->user('autor_przeczytane');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/przeczytane');
        $this->powiadomienie($autor, 'Rosół')->forceFill(['read_at' => now()])->save();

        $this->uruchomZadanie($autor);
        $this->assertSame([], $this->transport->wyslane);

        // Kontrola dodatnia: nieprzeczytane wychodzi.
        $this->powiadomienie($autor, 'Barszcz');
        $this->uruchomZadanie($autor);
        $this->assertCount(1, $this->transport->wyslane);
    }

    public function test_nic_sprzed_wlaczenia_pushu(): void
    {
        $autor = $this->user('autor_stare');
        $this->powiadomienie($autor, 'Sprzed włączenia');

        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:30:00', 'UTC'));
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/stare');
        $this->uruchomZadanie($autor);

        $this->assertSame([], $this->transport->wyslane);
    }

    public function test_wygasla_subskrypcja_znika_i_nie_jest_ponawiana(): void
    {
        $autor = $this->user('autor_wygasla');
        $martwa = $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/martwa');
        $zywa = $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/zywa');
        $this->transport->odpowiadaj($martwa->endpoint, WynikWysylkiPush::Wygasla);
        config(['kuking.notifications.zewnetrzne.dzienny_limit' => 5]);

        $this->powiadomienie($autor, 'Raz');
        $this->uruchomZadanie($autor);

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $martwa->getKey()]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $zywa->getKey()]);

        $this->powiadomienie($autor, 'Dwa');
        $this->uruchomZadanie($autor);

        $this->assertSame(
            [$martwa->endpoint, $zywa->endpoint, $zywa->endpoint],
            array_column($this->transport->wyslane, 'endpoint'),
            'Na skasowaną subskrypcję nie ma drugiej próby.',
        );
    }

    public function test_blad_uslugi_nie_kasuje_subskrypcji(): void
    {
        $autor = $this->user('autor_blad');
        $sub = $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/blad');
        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Blad);

        Queue::fake();
        $this->powiadomienie($autor, 'Rosół');
        $this->uruchomZadanie($autor);

        $this->assertCount(1, $this->transport->wyslane);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $sub->getKey()]);
    }

    /**
     * SEDNO ISSUE #1960. Błąd transportu NIE ustawia znacznika sukcesu —
     * do 26 września 2026 `push_wyslano_at` stawało PRZED próbą wysyłki,
     * więc awaria transportu i tak zostawiała kolumnę twierdzącą „wysłano”.
     */
    public function test_blad_transportu_nie_ustawia_znacznika_wyslania(): void
    {
        $autor = $this->user('autor_blad_znacznik');
        $sub = $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/blad-znacznik');
        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Blad);

        Queue::fake();
        $powiadomienie = $this->powiadomienie($autor, 'Rosół');
        $this->uruchomZadanie($autor);

        $this->assertCount(1, $this->transport->wyslane, 'Próba wysyłki ma się odbyć, mimo że się nie uda.');
        $this->assertNull(
            $powiadomienie->refresh()->push_wyslano_at,
            'Nieudana wysyłka nie ma prawa twierdzić, że powiadomienie wyszło (#1960).',
        );
        $this->assertNotNull($powiadomienie->refresh()->push_proba_at, 'Grupa ma zostać zarezerwowana, żeby nie wybrało jej drugie zdarzenie.');

        Queue::assertPushed(WyslijPowiadomieniePush::class, fn (WyslijPowiadomieniePush $job): bool => $job->userId === $autor->getKey()
            && $job->probaTransportu === 2
            && $job->notificationIds === [(string) $powiadomienie->getKey()]
            && $job->pominieteSubskrypcje === []);
    }

    /**
     * Ponowienie (kolejna próba transportu) faktycznie WYSYŁA, gdy usługa
     * wraca do życia — i dopiero WTEDY stawia znacznik.
     */
    public function test_ponowienie_po_bledzie_transportu_wysyla_i_stawia_znacznik(): void
    {
        $autor = $this->user('autor_ponowienie');
        $sub = $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/ponowienie');
        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Blad);

        Queue::fake();
        $powiadomienie = $this->powiadomienie($autor, 'Rosół');
        $this->uruchomZadanie($autor);
        Queue::assertPushed(WyslijPowiadomieniePush::class, 1);

        // Usługa wraca do życia; symulujemy PONOWIENIE zadania wprost —
        // ten sam wzorzec co `ponowKolejke()` w tym pliku.
        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Wyslano);
        (new WyslijPowiadomieniePush(
            userId: $autor->getKey(),
            notificationIds: [(string) $powiadomienie->getKey()],
            tresc: (string) json_encode(['body' => 'Rosół', 'url' => '/powiadomienia']),
            probaTransportu: 2,
        ))->handle($this->transport);

        $this->assertCount(2, $this->transport->wyslane, 'Druga próba ma faktycznie spróbować wysłać.');
        $this->assertNotNull($powiadomienie->refresh()->push_wyslano_at, 'Udane ponowienie ma postawić znacznik.');
    }

    /**
     * Dwa urządzenia, jedno pada: ponowienie idzie WYŁĄCZNIE do tego, które
     * jeszcze nie dostało pushu. Urządzenie, które już go przyjęło, nie
     * dostaje drugiej kopii.
     */
    public function test_czesciowa_awaria_ponawia_tylko_nieudane_urzadzenie(): void
    {
        $autor = $this->user('autor_czesciowa');
        $dziala = $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/dziala');
        $pada = $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/pada');
        $this->transport->odpowiadaj($pada->endpoint, WynikWysylkiPush::Blad);

        Queue::fake();
        $powiadomienie = $this->powiadomienie($autor, 'Rosół');
        $this->uruchomZadanie($autor);

        $this->assertEqualsCanonicalizing(
            [$dziala->endpoint, $pada->endpoint],
            array_column($this->transport->wyslane, 'endpoint'),
        );
        $this->assertNull($powiadomienie->refresh()->push_wyslano_at);

        Queue::assertPushed(WyslijPowiadomieniePush::class, fn (WyslijPowiadomieniePush $job): bool => $job->pominieteSubskrypcje === [(string) $dziala->getKey()]);
    }

    /**
     * Trwała porażka: po wyczerpaniu prób transportu przestajemy ponawiać
     * automatycznie. Znacznik wysyłki zostaje TRWALE pusty — mierzalny,
     * bez kłamstwa o sukcesie.
     */
    public function test_trwala_porazka_transportu_przestaje_ponawiac_i_nie_klamie_o_wysylce(): void
    {
        config(['kuking.notifications.zewnetrzne.push_maks_prob_transportu' => 2]);
        $autor = $this->user('autor_trwala_porazka');
        $sub = $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/trwala-porazka');
        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Blad);
        $powiadomienie = $this->powiadomienie($autor, 'Rosół');

        Queue::fake();
        (new WyslijPowiadomieniePush(userId: $autor->getKey()))->handle($this->transport);
        $this->assertCount(1, $this->transport->wyslane);

        Queue::fake();
        (new WyslijPowiadomieniePush(
            userId: $autor->getKey(),
            notificationIds: [(string) $powiadomienie->getKey()],
            tresc: (string) json_encode(['body' => 'Rosół', 'url' => '/powiadomienia']),
            probaTransportu: 2,
        ))->handle($this->transport);

        $this->assertCount(2, $this->transport->wyslane);
        Queue::assertNotPushed(WyslijPowiadomieniePush::class, 'Po wyczerpaniu prób nie ma trzeciej.');
        $this->assertNull(
            $powiadomienie->refresh()->push_wyslano_at,
            'Trwała porażka nie ma prawa twierdzić, że wysłano (#1960).',
        );
        $this->assertNotNull($powiadomienie->refresh()->push_zakonczono_at);

        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Wyslano);
        (new WyslijPowiadomieniePush(
            userId: $autor->getKey(),
            notificationIds: [(string) $powiadomienie->getKey()],
            tresc: (string) json_encode(['body' => 'Rosół', 'url' => '/powiadomienia']),
            probaTransportu: 3,
        ))->handle($this->transport);
        $this->assertCount(2, $this->transport->wyslane, 'Spóźnione retry nie wysyła zakończonej grupy.');

        // Zakończona grupa zostaje poza pulą, a nowe zdarzenie czeka do jutra.
        $drugie = $this->powiadomienie($autor, 'Drugie');
        Queue::fake();
        $this->uruchomZadanie($autor);
        $this->assertNull($drugie->refresh()->push_proba_at);
        Queue::assertPushed(WyslijPowiadomieniePush::class, 1);

        $this->travelTo(CarbonImmutable::parse('2026-09-26 06:00:00', 'UTC'));
        $this->uruchomZadanie($autor);
        $this->assertNotNull($drugie->refresh()->push_wyslano_at);
        $this->assertNull($powiadomienie->refresh()->push_wyslano_at);
    }

    public function test_zbanowany_odbiorca_nie_dostaje_pushu(): void
    {
        $autor = $this->user('autor_ban');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/ban');
        $this->powiadomienie($autor, 'Rosół');
        $autor->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->uruchomZadanie($autor);

        $this->assertSame([], $this->transport->wyslane);
    }

    public function test_bez_klucza_prywatnego_worker_nic_nie_wysyla(): void
    {
        $autor = $this->user('autor_bez_prywatnego');
        $this->subskrypcja($autor, 'https://fcm.googleapis.com/fcm/send/bez-prywatnego');
        $this->powiadomienie($autor, 'Rosół');
        config(['kuking.push.vapid_private_key' => '']);

        $this->uruchomZadanie($autor);

        $this->assertSame([], $this->transport->wyslane);
        $this->assertSame(1, $autor->notifications()->whereNull('push_wyslano_at')->count());
    }

    private function subskrypcja(User $user, string $endpoint): PushSubscription
    {
        $sub = new PushSubscription;
        $sub->forceFill([
            'user_id' => $user->getKey(),
            'endpoint' => $endpoint,
            'klucz_p256dh' => str_repeat('A', 87),
            'klucz_auth' => str_repeat('B', 22),
            'kodowanie' => 'aes128gcm',
        ])->save();

        return $sub;
    }

    /** Powiadomienie wprost, bez `NotifyUser` — zadanie uruchamia test sam. */
    private function powiadomienie(User $odbiorca, string $przepis): Notification
    {
        return Notification::create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => $this->user('kucharz_'.Str::lower(Str::random(8)))->getKey(),
            'type' => Notification::TYPE_COOKED,
            'data' => ['recipe_title' => $przepis],
        ]);
    }

    private function uruchomZadanie(User $odbiorca): void
    {
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);
    }
}
