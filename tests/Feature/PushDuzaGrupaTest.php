<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Push\KanalPush;
use App\Domain\Notifications\Push\KodZamknieciaPush;
use App\Domain\Notifications\Push\StanWysylkiPush;
use App\Domain\Notifications\Push\TransportPush;
use App\Domain\Notifications\Push\WynikWysylkiPush;
use App\Jobs\WyslijPowiadomieniePush;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FalszywyTransportPush;
use Tests\TestCase;

/**
 * Duża grupa Web Push nie może rosnąć w pamięci ani w kolejce (issue #2021).
 *
 * Do 28 września 2026 `zaplanuj()` hydratowało WSZYSTKIE czekające
 * powiadomienia odbiorcy z 48 h (z `actor.profile`), filtrowało je w PHP,
 * wstawiało ich UUID do jednego `WHERE id IN (...)` i — przy błędzie
 * transportu — do payloadu ponowienia w `jobs`. Popularny autor po nocy
 * ciszy miał tysiące wierszy w jednym zadaniu z `$timeout = 60`.
 *
 * Tu grupa jest liczona i rezerwowana w SQL, hydratuje się JEDNO
 * (najnowsze) powiadomienie do treści, a retry niesie `push_grupa_id`
 * zamiast listy. Każda asercja „mało” stoi obok kontroli dodatniej, że
 * duża grupa naprawdę istniała i naprawdę została wysłana i zamknięta.
 */
final class PushDuzaGrupaTest extends TestCase
{
    use RefreshDatabase;

    private const DUZO = 3000;

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
            'kuking.notifications.zewnetrzne.push_maks_prob_transportu' => 3,
            'kuking.notifications.zewnetrzne.push_osierocenie_minut' => 30,
        ]);

        $this->transport = new FalszywyTransportPush;
        $this->app->instance(TransportPush::class, $this->transport);

        Cache::flush();
        // 12:00 w Warszawie — poza ciszą nocną.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));
    }

    public function test_duza_grupa_hydratuje_stala_liczbe_modeli_i_wysyla_jeden_push(): void
    {
        [$odbiorca] = $this->odbiorcaZDuzaPula('odbiorca_duzo_2021', self::DUZO, 'Marek Najnowszy');

        // Nie do pushu: zwykły komentarz, „prawda” jako tekst, przeczytane.
        $zwykly = $this->wiersz($odbiorca, Notification::TYPE_COMMENT, ['question_answer' => false]);
        $tekstowy = $this->wiersz($odbiorca, Notification::TYPE_COMMENT, ['question_answer' => 'true']);
        $przeczytane = $this->wiersz($odbiorca, Notification::TYPE_COOKED, ['recipe_title' => 'Stare']);
        $przeczytane->forceFill(['read_at' => now()])->save();
        $wszystkich = self::DUZO + 1; // pula + najnowsze

        $hydratowane = $this->liczHydratowane();
        $idWParametrach = $this->liczIdPowiadomienWParametrach($odbiorca);

        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);

        $this->assertLessThanOrEqual(2, $hydratowane(), 'Treść potrzebuje jednego wiersza — nie całej puli.');
        $this->assertSame(0, $idWParametrach(), 'Żadne zapytanie nie niesie listy UUID powiadomień grupy.');

        // Kontrola dodatnia: jeden push, najnowsze zdanie, poprawna liczba reszty.
        $this->assertCount(1, $this->transport->wyslane);
        $this->assertSame(
            'Marek Najnowszy — ugotowane z Twojego przepisu „Najnowsza zupa”. Do tego 3000 innych powiadomień.',
            $this->transport->wyslane[0]['tresc']['body'],
        );
        $this->assertSame($wszystkich, $odbiorca->notifications()->whereNotNull('push_wyslano_at')->count(), 'Cała grupa potwierdzona.');
        $this->assertSame(1, $odbiorca->notifications()->whereNotNull('push_grupa_id')->distinct()->count('push_grupa_id'), 'Jedna rezerwacja = jedna grupa.');
        foreach ([$zwykly, $tekstowy, $przeczytane] as $poza) {
            $this->assertNull($poza->refresh()->push_proba_at, 'Wiersz spoza kanału push nie wchodzi do rezerwacji.');
        }
    }

    /**
     * Filtr kanału przeszedł z PHP (`KanalPush::dotyczy()`) do SQL
     * (`KanalPush::zawez()`). Obie wersje muszą wybrać te same wiersze.
     */
    public function test_filtr_kanalu_w_sql_zgadza_sie_z_php(): void
    {
        $odbiorca = $this->user('odbiorca_filtr_2021');
        $przypadki = [
            [Notification::TYPE_COOKED, ['recipe_title' => 'Zupa']],
            [Notification::TYPE_REPLY, []],
            [Notification::TYPE_COMMENT, ['question_answer' => true]],
            [Notification::TYPE_COMMENT, ['question_answer' => false]],
            [Notification::TYPE_COMMENT, ['question_answer' => 'true']],
            [Notification::TYPE_COMMENT, ['question_answer' => 1]],
            [Notification::TYPE_COMMENT, []],
            [Notification::TYPE_FOLLOW, []],
        ];
        $oczekiwane = [];
        foreach ($przypadki as [$typ, $data]) {
            $n = $this->wiersz($odbiorca, $typ, $data);
            if (KanalPush::dotyczy($typ, $data)) {
                $oczekiwane[] = (string) $n->getKey();
            }
        }

        $zSql = KanalPush::zawez(Notification::query()->where('notifications.user_id', $odbiorca->getKey()))
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();

        $this->assertCount(3, $oczekiwane, 'Kontrola dodatnia: są wiersze po obu stronach.');
        $this->assertEqualsCanonicalizing($oczekiwane, $zSql);
    }

    public function test_payload_ponowienia_nie_rosnie_z_liczba_powiadomien(): void
    {
        config(['queue.default' => 'database']);

        $rozmiary = [];
        foreach (['maly' => 5, 'duzy' => self::DUZO] as $nazwa => $ile) {
            [$odbiorca, $sub] = $this->odbiorcaZDuzaPula('odbiorca_'.$nazwa.'_2021', $ile, 'Ala');
            $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Blad);

            (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);

            $payload = (string) DB::table('jobs')->where('payload', 'like', '%'.$odbiorca->getKey().'%')->value('payload');
            $this->assertNotSame('', $payload, 'Kontrola dodatnia: retry stoi w kolejce.');
            $rozmiary[$nazwa] = strlen($payload);

            $idGrupy = (string) $odbiorca->notifications()->whereNotNull('push_grupa_id')->value('push_grupa_id');
            $this->assertStringContainsString($idGrupy, $payload, 'Retry niesie ID rezerwacji — po nim czujka #2053 rozpoznaje osłonę.');
            $this->assertSame($ile + 1, $odbiorca->notifications()->where('push_grupa_id', $idGrupy)->whereNull('push_wyslano_at')->count());
        }

        $this->assertLessThan(4096, $rozmiary['duzy'], 'Retry dużej grupy: '.$rozmiary['duzy'].' B.');
        $this->assertLessThanOrEqual(64, abs($rozmiary['duzy'] - $rozmiary['maly']), 'Payload nie zależy od liczby powiadomień: '.json_encode($rozmiary));
    }

    public function test_retry_duzej_grupy_wysyla_tylko_na_nieudane_urzadzenie_i_zamyka_cala_grupe(): void
    {
        [$odbiorca, $dziala] = $this->odbiorcaZDuzaPula('odbiorca_retry_2021', self::DUZO, 'Ala');
        $pada = $this->subskrypcja($odbiorca, 'https://fcm.googleapis.com/fcm/send/pada-2021');
        $this->transport->odpowiadaj($pada->endpoint, WynikWysylkiPush::Blad);

        $kolejka = Queue::fake();
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);
        $retry = $kolejka->pushed(WyslijPowiadomieniePush::class)->sole();
        $this->assertSame([(string) $dziala->getKey()], $retry->pominieteSubskrypcje);
        $this->assertSame([], $retry->notificationIds, 'Bez listy UUID w payloadzie.');
        $this->assertNotNull($retry->grupaId);
        $this->assertSame(0, $odbiorca->notifications()->whereNotNull('push_wyslano_at')->count(), 'Częściowy sukces to jeszcze nie sukces grupy.');

        // Po rezerwacji: jedno przeczytane i jedno nowe zdarzenie.
        $przeczytane = $odbiorca->notifications()->where('push_grupa_id', $retry->grupaId)->reorder('created_at')->first();
        $przeczytane->forceFill(['read_at' => now()])->save();
        $this->travel(1)->minutes();
        $nowe = $this->wiersz($odbiorca, Notification::TYPE_COOKED, ['recipe_title' => 'Po rezerwacji']);

        $this->transport->odpowiadaj($pada->endpoint, WynikWysylkiPush::Wyslano);
        $hydratowane = $this->liczHydratowane();
        $retry->handle($this->transport);

        $this->assertSame(0, $hydratowane(), 'Retry nie hydratuje grupy.');
        $adresy = array_column($this->transport->wyslane, 'endpoint');
        $this->assertSame([$dziala->endpoint, $pada->endpoint, $pada->endpoint], $adresy, 'Obsłużone urządzenie nie dostaje drugiej kopii (#2131).');
        $this->assertSame('Masz nowe powiadomienie.', $this->transport->wyslane[2]['tresc']['body']);

        $this->assertSame(self::DUZO, $odbiorca->notifications()->where('push_grupa_id', $retry->grupaId)->whereNotNull('push_wyslano_at')->count());
        $this->assertNull($przeczytane->refresh()->push_wyslano_at);
        $this->assertSame(KodZamknieciaPush::Anulowano->value, $przeczytane->push_wynik);
        $this->assertNull($nowe->refresh()->push_proba_at, 'Nowe zdarzenie nie dołącza do cudzego retry.');
        $this->assertNotNull($przeczytane->push_proba_at, 'Rezerwacja zostaje — grupa nie wraca do puli.');
    }

    /**
     * Przegląd PR #2160 (plany EXPLAIN ANALYZE): zamknięcie wierszy, które
     * w retry przestały się kwalifikować, liczy warunek NA WIERSZU grupy
     * (`NOT EXISTS (SELECT 1 WHERE …)` bez `FROM`). Wersja
     * `NOT IN (SELECT id FROM notifications …)` skanowała pulę drugi raz
     * z pełnym filtrem widoczności; przy 3001 wierszach szacowany koszt
     * 1,74 mln włączał JIT i sama kompilacja trwała 2,4 s
     * (`docs/infra/WEB_PUSH_PLANY_2021.md`).
     */
    public function test_ponowna_kwalifikacja_nie_skanuje_puli_drugi_raz(): void
    {
        [$odbiorca] = $this->odbiorcaZDuzaPula('odbiorca_plan_2021', 50, 'Ala');
        $pada = $this->subskrypcja($odbiorca, 'https://fcm.googleapis.com/fcm/send/pada-plan-2021');
        $this->transport->odpowiadaj($pada->endpoint, WynikWysylkiPush::Blad);

        $kolejka = Queue::fake();
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);
        $retry = $kolejka->pushed(WyslijPowiadomieniePush::class)->sole();

        $przeczytane = $odbiorca->notifications()->where('push_grupa_id', $retry->grupaId)->reorder('created_at')->first();
        $przeczytane->forceFill(['read_at' => now()])->save();

        $zamkniecia = [];
        DB::listen(function ($zapytanie) use (&$zamkniecia): void {
            if (str_starts_with($zapytanie->sql, 'update "notifications" set "push_zakonczono_at"')) {
                $zamkniecia[] = $zapytanie->sql;
            }
        });
        $this->transport->odpowiadaj($pada->endpoint, WynikWysylkiPush::Wyslano);
        Queue::fake();
        $retry->handle($this->transport);

        $this->assertCount(1, $zamkniecia, 'Kontrola dodatnia: retry zamyka wiersze, które przestały się kwalifikować.');
        $this->assertStringNotContainsString('from "notifications"', $zamkniecia[0], 'Zamknięcie nie może skanować puli drugi raz.');
        $this->assertStringContainsString('not exists (select 1 where', $zamkniecia[0]);
        // Semantyka bez zmian: przeczytany zamknięty jako anulowany, reszta wysłana.
        $this->assertSame(KodZamknieciaPush::Anulowano->value, $przeczytane->refresh()->push_wynik);
        $this->assertSame(50, $odbiorca->notifications()->where('push_grupa_id', $retry->grupaId)->whereNotNull('push_wyslano_at')->count());
    }

    public function test_trwala_porazka_duzej_grupy_zamyka_ja_kodem_przez_id_rezerwacji(): void
    {
        config(['kuking.notifications.zewnetrzne.push_maks_prob_transportu' => 2]);
        [$odbiorca, $sub] = $this->odbiorcaZDuzaPula('odbiorca_porazka_2021', self::DUZO, 'Ala');
        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Blad);

        $kolejka = Queue::fake();
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);
        $retry = $kolejka->pushed(WyslijPowiadomieniePush::class)->sole();
        Queue::fake();
        $retry->handle($this->transport);
        Queue::assertNothingPushed();

        $this->assertSame(self::DUZO + 1, $odbiorca->notifications()
            ->where('push_wynik', KodZamknieciaPush::PorazkaTransportu->value)
            ->whereNull('push_wyslano_at')->whereNotNull('push_proba_at')->count());
        $wynik = app(StanWysylkiPush::class)->sprawdz();
        $this->assertSame(1, $wynik['trwale_porazki'], 'Jedna grupa, nie tysiące wierszy.');
    }

    /**
     * Czujka #2053 szuka w `jobs` ponowienia niosącego tę rezerwację. Od #2021
     * retry niesie `push_grupa_id`, nie listę ID — i dalej musi osłaniać.
     */
    public function test_czujka_widzi_retry_niosace_id_grupy(): void
    {
        config(['queue.default' => 'database']);
        [$odbiorca, $sub] = $this->odbiorcaZDuzaPula('odbiorca_czujka_2021', 20, 'Ala');
        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Blad);

        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);
        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringNotContainsString((string) $odbiorca->notifications()->value('id'), $payload, 'W payloadzie nie ma już ID powiadomień.');

        $this->travel(2)->hours();
        $wynik = app(StanWysylkiPush::class)->sprawdz();
        $this->assertSame(StanWysylkiPush::SPOKOJNY, $wynik['stan'], 'Retry w kolejce to „w toku”, nie utrata.');

        // Kontrola dodatnia: bez zadania ta sama rezerwacja to sierota.
        DB::table('jobs')->delete();
        $wynik = app(StanWysylkiPush::class)->sprawdz();
        $this->assertSame(StanWysylkiPush::NIEROZLICZONE, $wynik['stan']);
        $this->assertSame(1, $wynik['utracone_ponowienia']);
    }

    /** Retry innej grupy tego samego odbiorcy nie osłania cudzej sieroty. */
    public function test_retry_innej_grupy_nie_zaslania_sieroty(): void
    {
        config(['queue.default' => 'database']);
        $odbiorca = $this->user('odbiorca_dwie_grupy_2021');
        $sierota = $this->wiersz($odbiorca, Notification::TYPE_COOKED, ['recipe_title' => 'Sierota']);
        $sierota->forceFill(['push_proba_at' => now()->subHours(2), 'push_grupa_id' => (string) Str::uuid()])->save();

        WyslijPowiadomieniePush::dispatch((string) $odbiorca->getKey(), [], null, [], 2, (string) Str::uuid())
            ->delay(now()->addSeconds(30));
        $this->assertSame(1, DB::table('jobs')->count(), 'Kontrola: w kolejce stoi retry tego odbiorcy.');

        $this->assertSame(1, app(StanWysylkiPush::class)->sprawdz()['utracone_ponowienia']);
    }

    /**
     * Pula: jedna subskrypcja, potem `$ile` starszych „Ugotowałem” wstawionych
     * jednym INSERT-em i jedno najnowsze od `$aktor`.
     *
     * @return array{0: User, 1: PushSubscription}
     */
    private function odbiorcaZDuzaPula(string $nazwa, int $ile, string $aktor): array
    {
        $odbiorca = $this->user($nazwa);
        $sub = $this->subskrypcja($odbiorca, 'https://fcm.googleapis.com/fcm/send/'.$nazwa);
        $kucharz = $this->user('kucharz_'.Str::lower(Str::random(8)));
        $this->travel(2)->hours();

        DB::insert(<<<'SQL'
            INSERT INTO notifications (user_id, actor_id, type, data, created_at)
            SELECT ?, ?, ?, jsonb_build_object('recipe_title', 'Zupa ' || g), ?::timestamptz - make_interval(secs => g)
            FROM generate_series(1, ?) AS g
            SQL, [$odbiorca->getKey(), $kucharz->getKey(), Notification::TYPE_COOKED, now()->toIso8601String(), $ile]);

        $najnowsze = $this->wiersz($odbiorca, Notification::TYPE_COOKED, ['recipe_title' => 'Najnowsza zupa'], null, $this->user('aktor_'.Str::lower(Str::random(8)), ['display_name' => $aktor]));
        $this->assertSame($ile + 1, $odbiorca->notifications()->count(), 'Kontrola dodatnia: pula naprawdę jest duża.');
        $this->assertNotNull($najnowsze);

        return [$odbiorca, $sub];
    }

    /** @param  array<string, mixed>  $data */
    private function wiersz(User $odbiorca, string $typ, array $data, ?\DateTimeInterface $kiedy = null, ?User $aktor = null): Notification
    {
        $n = Notification::create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => ($aktor ?? $this->user('aktor_'.Str::lower(Str::random(8))))->getKey(),
            'type' => $typ,
            'data' => $data,
        ]);
        if ($kiedy !== null) {
            $n->forceFill(['created_at' => $kiedy])->save();
        }

        return $n;
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

    /** @return callable(): int */
    private function liczHydratowane(): callable
    {
        $licznik = 0;
        Event::listen('eloquent.retrieved: '.Notification::class, function () use (&$licznik): void {
            $licznik++;
        });

        return function () use (&$licznik): int {
            return $licznik;
        };
    }

    /**
     * Ile razy ID powiadomień odbiorcy pojawiło się w parametrach zapytań —
     * `WHERE id IN (...)` z całą grupą dałby tu tysiące.
     *
     * @return callable(): int
     */
    private function liczIdPowiadomienWParametrach(User $odbiorca): callable
    {
        $id = array_flip($odbiorca->notifications()->pluck('id')->map(fn ($v): string => (string) $v)->all());
        $licznik = 0;
        DB::listen(function ($zapytanie) use (&$licznik, $id): void {
            foreach ($zapytanie->bindings as $parametr) {
                if (is_string($parametr) && isset($id[$parametr])) {
                    $licznik++;
                }
            }
        });

        return function () use (&$licznik): int {
            return $licznik;
        };
    }
}
