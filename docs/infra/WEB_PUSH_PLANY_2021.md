# Web Push: plany zapytań dużej grupy przed i po #2021

Pomiar do przeglądu PR #2160, wykonany 28 września 2026: `EXPLAIN (ANALYZE, BUFFERS)` każdego zapytania joba
`WyslijPowiadomieniePush` o `notifications`, **na tych samych danych**. Porównuję kod bazy
`b8b61ab70` („przed”) z gałęzią `claude/2021-push-pamiec-payload`: pierwszą wersją
(„po, wersja 1”) i wersją po poprawce z tego pomiaru („po”).

## Dane i sposób pomiaru

- PostgreSQL 18.6 lokalnie, ustawienia domyślne (`jit = on`, `jit_above_cost = 100000`),
  po `ANALYZE notifications`.
- Tabela `notifications` ma 223 001 wierszy:
  - 200 000 wierszy tła: 100 innych osób × 2000 powiadomień, cztery typy, 2/3 przeczytanych,
    rozłożonych na 30 dni;
  - 20 000 wierszy historii odbiorcy: starsze niż 3 dni, przeczytane i wysłane;
  - **3001 czekających** `cooked_event.created` z ostatniej godziny, czyli pula.
- Odbiorca ma dwa urządzenia. Pierwsza próba: jedno urządzenie przyjmuje, drugie zwraca błąd
  transportu, więc powstaje retry. Ponowienie: drugie urządzenie przyjmuje.
  Kontrola dodatnia w obu wersjach: 3 pushe, 3001 wierszy potwierdzonych, 0 otwartych.
- Plan liczę w `SAVEPOINT` tuż przed właściwym zapytaniem (`DB::beforeExecuting`), potem
  `ROLLBACK TO SAVEPOINT`. Job wykonuje się więc na dokładnie tym stanie, który zmierzono.
  Harness jest w dodatku na końcu. Bufory to `shared hit + read` z węzła najwyższego.
  Czasy pochodzą z pojedynczego przebiegu na tej samej maszynie, więc liczą się rzędy wielkości,
  a nie milisekundy. Trzy zapytania kontrolne harnessu (`count(*)` po zakończeniu) pominąłem.

## Przed (b8b61ab70)

Payload retry w `jobs`: **152 584 B**, bo lista 3001 UUID. Hydratacja: 3001 modeli z `actor.profile`.

| Etap | Zapytanie | Parametrów | Koszt (plan) | Czas wykonania | Bufory (shared) | JIT |
|---|---|---:|---:|---:|---:|---:|
| pierwsza proba | hydratacja puli (`get()` + `actor.profile`) | 70 | 30 595 | 5.9 ms | 12 080 | — |
| pierwsza proba | limit dobowy: grupy | 5 | 5 399 | 2.4 ms | 618 | — |
| pierwsza proba | limit dobowy: dawne wiersze | 5 | 5 399 | 2.1 ms | 618 | — |
| pierwsza proba | rezerwacja (`UPDATE`) | 3003 | 5 611 | 45.1 ms | 56 192 | — |
| ponowienie | retry: grupa po liście ID | 3002 | 726 | 0.8 ms | 138 | — |
| ponowienie | retry: widoczne z grupy | 3070 | 24 030 | 6.3 ms | 3 327 | — |
| ponowienie | potwierdzenie (`UPDATE`) | 3002 | 5 705 | 69.8 ms | 54 622 | — |
| **suma** | | | | **132.3 ms** | **127 595** | |

## Po, wersja 1 (`512307aa5`)

Payload retry: **525 B**. Hydratacja: 1 model.

| Etap | Zapytanie | Parametrów | Koszt (plan) | Czas wykonania | Bufory (shared) | JIT |
|---|---|---:|---:|---:|---:|---:|
| pierwsza proba | czy pula niepusta (`exists`) | 70 | 670 | 1.6 ms | 11 | — |
| pierwsza proba | limit dobowy: grupy | 5 | 5 437 | 2.6 ms | 618 | — |
| pierwsza proba | limit dobowy: dawne wiersze | 5 | 5 437 | 2.4 ms | 618 | — |
| pierwsza proba | rezerwacja (`UPDATE`) | 72 | 31 830 | 59.6 ms | 77 266 | — |
| pierwsza proba | najnowsze do treści | 2 | 5 404 | 2.9 ms | 746 | — |
| ponowienie | retry: wiek rezerwacji | 2 | 693 | 0.2 ms | 71 | — |
| ponowienie | retry: zamknięcie niekwalifikujących | 73 | 1 741 612 | 2518.4 ms | 18 861 | 2386 ms |
| ponowienie | retry: czy coś zostało | 2 | 693 | 0.1 ms | 5 | — |
| ponowienie | potwierdzenie (`UPDATE`) | 3 | 697 | 75.7 ms | 51 589 | — |
| **suma** | | | | **2663.6 ms** | **149 785** | |

**Regresja znaleziona tym pomiarem.** W retry wiersze, które przestały się kwalifikować, były
zamykane przez `… AND id NOT IN (SELECT notifications.id FROM notifications WHERE <pełny filtr widoczności>)`.
Podzapytanie drugi raz skanowało pulę 3001 wierszy z całym filtrem widoczności. Planer oszacował
koszt na 1,74 mln, co przekracza `jit_above_cost`. Samo wykonanie trwało kilkanaście milisekund,
ale kompilacja JIT zajęła 2,4 s:

```
Update on notifications  (cost=1740867.62..1741612.28 rows=0 width=0) (actual time=2327.383..2327.476 rows=0.00 loops=1)
  Buffers: shared hit=18861
  ->  Index Scan using notifications_push_nierozliczone_idx on notifications  (cost=1740867.62..1741612.28 rows=1 width=96) (actual time=2327.381..2327.474 rows=0.00 l…
        Index Cond: (push_proba_at IS NOT NULL)
        Filter: ((push_wyslano_at IS NULL) AND (push_zakonczono_at IS NULL) AND (NOT (ANY (id = (hashed SubPlan 170).col1))) AND (user_id = '01a0e7c2-35e3-7395-8f89-a3…
        Rows Removed by Filter: 3001
…
JIT:
  Functions: 764
  Timing: Generation 65.859 ms, Inlining 231.661 ms, Optimization 1131.313 ms, Emission 956.723 ms, Total 2385.556 ms
Execution Time: 2518.414 ms
```

## Po (poprawka tego pomiaru)

Zamknięcie liczy teraz warunek **na wierszu grupy**:
`NOT EXISTS (SELECT 1 WHERE <te same warunki na notifications.*>)`, bez `FROM`.
Kolumny wskazują na wiersz zewnętrznego `UPDATE`, więc drugi skan znika. Semantyka NULL jest
właściwa: warunek, który daje NULL (np. brak `question_answer`), nie kwalifikuje wiersza.
Pilnuje tego test `PushDuzaGrupaTest::test_ponowna_kwalifikacja_nie_skanuje_puli_drugi_raz`.
Kontrola ujemna: wersja z `NOT IN` ten test oblewa.

Payload retry: **525 B**. Hydratacja: 1 model.

| Etap | Zapytanie | Parametrów | Koszt (plan) | Czas wykonania | Bufory (shared) | JIT |
|---|---|---:|---:|---:|---:|---:|
| pierwsza proba | czy pula niepusta (`exists`) | 70 | 674 | 1.3 ms | 11 | — |
| pierwsza proba | limit dobowy: grupy | 5 | 5 427 | 2.5 ms | 618 | — |
| pierwsza proba | limit dobowy: dawne wiersze | 5 | 5 427 | 2.2 ms | 618 | — |
| pierwsza proba | rezerwacja (`UPDATE`) | 72 | 31 365 | 55.5 ms | 77 268 | — |
| pierwsza proba | najnowsze do treści | 2 | 5 395 | 2.8 ms | 746 | — |
| ponowienie | retry: wiek rezerwacji | 2 | 690 | 0.2 ms | 71 | — |
| ponowienie | retry: zamknięcie niekwalifikujących | 73 | 940 | 7.3 ms | 18 078 | — |
| ponowienie | retry: czy coś zostało | 2 | 690 | 0.0 ms | 5 | — |
| ponowienie | potwierdzenie (`UPDATE`) | 3 | 693 | 54.7 ms | 51 575 | — |
| **suma** | | | | **126.5 ms** | **148 990** | |

```
Update on notifications  (cost=0.28..939.81 rows=0 width=0) (actual time=6.763..6.772 rows=0.00 loops=1)
  Buffers: shared hit=18078
  ->  Nested Loop Anti Join  (cost=0.28..939.81 rows=1 width=96) (actual time=6.762..6.771 rows=0.00 loops=1)
        Join Filter: ((notifications.read_at IS NULL) AND (notifications.user_id = '01a0e7c4-97bb-719b-a700-1c1accba14a0'::uuid) AND ((notifications.type)::text <> 'ap…
        Buffers: shared hit=18078
        ->  Index Scan using notifications_push_nierozliczone_idx on notifications  (cost=0.28..693.26 rows=1 width=96) (actual time=0.013..0.555 rows=3001.00 loops=1)
```

## Porównanie

| | Przed | Po |
|---|---:|---:|
| Payload retry w `jobs` | 152 584 B | 525 B |
| Modele `Notification` w pamięci PHP | 3001 (+ aktorzy i profile) | 1 |
| Parametrów w największym zapytaniu | 3070 | 73 |
| Czas wykonania zapytań o `notifications` (suma) | 132,3 ms | 126,5 ms (powtórka: 156,8 ms) |
| Bufory (suma) | 127 595 | 148 990 |
| Najwyższy szacowany koszt planu | 30 595 | 31 365 |
| Zapytania z JIT | 0 | 0 |

Wnioski:

1. **Czas bazy jest ten sam w granicach rozrzutu.** Powtórka „po” na tym samym kodzie dała 156,8 ms, przy identycznym SQL i buforach. Na tej skali oba warianty kosztują łącznie około 130 ms. Zysk
   #2021 leży poza bazą: PHP nie trzyma 3001 modeli z aktorami i profilami, a retry nie niesie
   152 KB w `jobs`. Stary retry deserializował tę listę i wysyłał ją jako 3000 parametrów
   w trzech zapytaniach.
2. **Bufory rosną o 17%**, z 128 tys. do 149 tys. Rezerwacja stosuje filtr widoczności wewnątrz
   `UPDATE` (77 tys. zamiast 12 tys. na `SELECT` i 56 tys. na `UPDATE` przed zmianą). Zamknięcie
   w retry sprawdza widoczność wiersz po wierszu (18 tys. zamiast 3,3 tys.). Wszystko to trafienia
   w cache (`hit`), bez odczytów z dysku. Świadomie płacę za to, że kwalifikacja i rezerwacja są
   jednym zapytaniem pod blokadą odbiorcy.
3. **Potwierdzenie i zamknięcie idą po częściowym indeksie** `notifications_push_nierozliczone_idx`
   (`push_proba_at` dla nierozliczonych), a nie po `user_id`. Ryzyko „brak indeksu na
   `push_grupa_id`” z opisu PR nie występuje. Bez migracji.
4. **Próg JIT.** Rezerwacja ma koszt około 31 tys., bo filtr widoczności liczy się na 3001
   wierszach. Stary `SELECT` z tym samym filtrem kosztował tyle samo. Przy pulach około 3 razy
   większych oba warianty przekroczą `jit_above_cost` w tym samym miejscu. To nie jest skutek
   #2021. Gdyby taki rozmiar pojawił się na produkcji, decyzja dotyczy ustawienia `jit` całej bazy,
   a nie tego joba.

## Jak powtórzyć

1. Skopiuj harness z dodatku do `tests/Feature/PomiarPlanowPush2021Test.php`. Nie commituj go:
   to pomiar, a nie test regresyjny.
2. Uruchom:
   `POMIAR_WYJSCIE=/tmp/plany.json vendor/bin/phpunit --filter=PomiarPlanowPush2021`
3. Dla wariantu „przed” podstaw w worktree cztery pliki z `b8b61ab70`:
   - `app/Jobs/WyslijPowiadomieniePush.php`;
   - `app/Domain/Notifications/Push/{KanalPush,TrescPush,StanWysylkiPush}.php`.

   Po pomiarze przywróć je.

## Dodatek: harness

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Push\TransportPush;
use App\Domain\Notifications\Push\WynikWysylkiPush;
use App\Jobs\WyslijPowiadomieniePush;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FalszywyTransportPush;
use Tests\TestCase;

/**
 * POMIAR (nie test regresyjny) do przeglądu PR #2160 / issue #2021:
 * EXPLAIN (ANALYZE, BUFFERS) każdego zapytania joba o `notifications`
 * na tej samej, reprezentatywnej skali danych — przed i po zmianie.
 * Plan liczony w SAVEPOINT tuż przed właściwym zapytaniem i cofany,
 * więc job wykonuje się na tym samym stanie, który zmierzono.
 */
final class PomiarPlanowPush2021Test extends TestCase
{
    use RefreshDatabase;

    public function test_pomiar(): void
    {
        config([
            'kuking.strefa' => 'Europe/Warsaw',
            'kuking.push.vapid_public_key' => 'BTestowyKluczPublicznyNieDoWysylki',
            'kuking.push.vapid_private_key' => 'testowy-klucz-prywatny',
            'kuking.notifications.zewnetrzne.wlaczone' => true,
            'kuking.notifications.zewnetrzne.cisza_od_godziny' => 21,
            'kuking.notifications.zewnetrzne.cisza_do_godziny' => 8,
            'kuking.notifications.zewnetrzne.dzienny_limit' => 1,
            'kuking.notifications.zewnetrzne.push_maks_prob_transportu' => 3,
        ]);
        $transport = new FalszywyTransportPush;
        $this->app->instance(TransportPush::class, $transport);
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));

        // Tło: 100 innych osób × 2000 powiadomień (200 000 wierszy, 30 dni).
        $inni = User::factory()->count(100)->create();
        $aktor = $this->user("aktor_pomiar_2021", ["display_name" => "Marek"]);
        DB::insert(<<<'SQL'
            INSERT INTO notifications (user_id, actor_id, type, data, created_at, read_at)
            SELECT u.id, ?, (ARRAY['cooked_event.created','comment.replied','comment.created','follow.created'])[1 + (g % 4)],
                   jsonb_build_object('recipe_title', 'Tło ' || g), ?::timestamptz - make_interval(secs => g * 13),
                   CASE WHEN g % 3 = 0 THEN NULL ELSE ?::timestamptz END
            FROM unnest(?::uuid[]) AS u(id), generate_series(1, 2000) AS g
            SQL, [$aktor->getKey(), now()->toIso8601String(), now()->toIso8601String(), '{'.$inni->pluck('id')->implode(',').'}']);

        $odbiorca = $this->user("odbiorca_pomiar_2021");
        $sub = new PushSubscription;
        $sub->forceFill(['user_id' => $odbiorca->getKey(), 'endpoint' => 'https://fcm.googleapis.com/fcm/send/pomiar-ok',
            'klucz_p256dh' => str_repeat('A', 87), 'klucz_auth' => str_repeat('B', 22), 'kodowanie' => 'aes128gcm'])->save();
        $pada = new PushSubscription;
        $pada->forceFill(['user_id' => $odbiorca->getKey(), 'endpoint' => 'https://fcm.googleapis.com/fcm/send/pomiar-pada',
            'klucz_p256dh' => str_repeat('A', 87), 'klucz_auth' => str_repeat('B', 22), 'kodowanie' => 'aes128gcm'])->save();
        $this->travel(2)->hours();

        // Historia odbiorcy: 20 000 starszych, przeczytanych i wysłanych.
        DB::insert(<<<'SQL'
            INSERT INTO notifications (user_id, actor_id, type, data, created_at, read_at, push_proba_at, push_wyslano_at)
            SELECT ?, ?, 'cooked_event.created', jsonb_build_object('recipe_title', 'Stara ' || g),
                   ?::timestamptz - interval '3 days' - make_interval(secs => g * 60),
                   ?::timestamptz - interval '3 days', ?::timestamptz - interval '3 days', ?::timestamptz - interval '3 days'
            FROM generate_series(1, 20000) AS g
            SQL, [$odbiorca->getKey(), $aktor->getKey(), now()->toIso8601String(), now()->toIso8601String(), now()->toIso8601String(), now()->toIso8601String()]);
        // Pula: 3001 czekających z ostatnich godzin.
        DB::insert(<<<'SQL'
            INSERT INTO notifications (user_id, actor_id, type, data, created_at)
            SELECT ?, ?, 'cooked_event.created', jsonb_build_object('recipe_title', 'Zupa ' || g), ?::timestamptz - make_interval(secs => g)
            FROM generate_series(1, 3001) AS g
            SQL, [$odbiorca->getKey(), $aktor->getKey(), now()->toIso8601String()]);
        DB::statement('ANALYZE notifications');

        $plany = [];
        $wPlanie = false;
        $etap = 'pierwsza_proba';
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $c) use (&$plany, &$wPlanie, &$etap): void {
            if ($wPlanie || ! preg_match('/^\s*(select|update)\b/i', $sql) || ! str_contains($sql, '"notifications"')) {
                return;
            }
            $wPlanie = true;
            $pdo = $c->getPdo();
            $pdo->exec('SAVEPOINT pomiar_2021');
            $st = $pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT) '.$sql);
            $c->bindValues($st, $c->prepareBindings($bindings));
            $st->execute();
            $plan = implode("\n", $st->fetchAll(\PDO::FETCH_COLUMN));
            $pdo->exec('ROLLBACK TO SAVEPOINT pomiar_2021');
            $wPlanie = false;
            $plany[] = ['etap' => $etap, 'sql' => mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 400),
                'parametrow' => count($bindings), 'plan' => $plan];
        });

        $transport->odpowiadaj($pada->endpoint, WynikWysylkiPush::Blad);
        $kolejka = Queue::fake();
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($transport);
        $retry = $kolejka->pushed(WyslijPowiadomieniePush::class)->sole();
        $payload = strlen(serialize($retry));

        $etap = 'ponowienie';
        $transport->odpowiadaj($pada->endpoint, WynikWysylkiPush::Wyslano);
        Queue::fake();
        $retry->handle($transport);

        $kontrola = ['wyslane_pushe' => count($transport->wyslane),
            'pula_wyslana' => Notification::query()->where('user_id', $odbiorca->getKey())->whereNull('read_at')->whereNotNull('push_wyslano_at')->count(),
            'pula_zamknieta' => Notification::query()->where('user_id', $odbiorca->getKey())->whereNull('read_at')->whereNotNull('push_zakonczono_at')->count(),
            'pula_otwarta' => Notification::query()->where('user_id', $odbiorca->getKey())->whereNull('read_at')->whereNull('push_wyslano_at')->count()];
        file_put_contents((string) getenv('POMIAR_WYJSCIE'), json_encode(['kontrola' => $kontrola, 'payload_retry_bajtow' => $payload, 'plany' => $plany],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
```
