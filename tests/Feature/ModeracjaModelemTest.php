<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrzeanalizujTresc;
use App\Models\Notification as PowiadomienieWSerwisie;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\KlientOpenAI;
use App\Moderacja\OcenaModelem;
use App\Notifications\PilnyAlarmModeracyjny;
use App\Notifications\PodsumowanieKolejkiAutomatu;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * DRUGA PARA OCZU: MODEL OCENIAJĄCY TREŚĆ (D-055).
 *
 * CZEGO TE TESTY PILNUJĄ NAPRAWDĘ
 * Nie tego, że „AI działa" — tego z PHP sprawdzić się nie da i nie o to tu
 * chodzi. Pilnują GRANICY: model podnosi rękę, nigdy nie zamyka drzwi.
 * Wynik modelu kończy się jedną pozycją w kolejce moderatora, treść zostaje
 * widoczna, autor niczego nie zauważa, a awaria po stronie OpenAI nie ma
 * żadnego skutku poza brakiem tej jednej pozycji.
 *
 * Drugą pilnowaną rzeczą jest POCZTA. Przy setkach kont list na każde
 * oznaczenie zamieniłby skrzynkę moderatora w śmietnik — i to jest sposób,
 * w jaki ta funkcja może naprawdę zaszkodzić.
 */
class ModeracjaModelemTest extends TestCase
{
    use RefreshDatabase;

    private const ALARM = 'moderacja@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.moderation.model.klucz' => 'testowy-klucz',
            'kuking.moderation.model.alarm_email' => self::ALARM,
            // Zdjęcia mają własne testy; tutaj oceniamy sam tekst, żeby nie
            // mieszać dwóch rzeczy w jednej asercji.
            'kuking.moderation.model.ocenia_zdjecia' => false,
        ]);
    }

    private function osoba(string $login): User
    {
        $user = $this->user($login);
        $user->forceFill(['created_at' => now()->subDays(400)])->save();

        return $user->refresh();
    }

    private function wpis(User $autor, string $tekst): Post
    {
        return Post::create([
            'author_id' => $autor->getKey(),
            'body' => $tekst,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /** @param array<string, float> $wyniki */
    private function modelOdpowiada(array $wyniki): void
    {
        Http::fake([
            '*api.openai.com*' => Http::response([
                'results' => [[
                    // `flagged` celowo USTAWIONE NA ODWRÓT niż nasze progi:
                    // ten test ma pokazać, że czytamy `category_scores`,
                    // a nie cudzą decyzję.
                    'flagged' => false,
                    'category_scores' => $wyniki,
                ]],
            ]),
        ]);
    }

    private function analizuj(Post $wpis): void
    {
        dispatch_sync(new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, (string) $wpis->getKey()));
    }

    /** @return Collection<int, Report> */
    private function oznaczenia()
    {
        return Report::query()->where('source', Report::SOURCE_AUTOMAT)->get();
    }

    // ---------------------------------------------------------------
    // ŁAPIE
    // ---------------------------------------------------------------

    public function test_wynik_modelu_trafia_do_kolejki_z_powodem_po_polsku(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.91, 'violence' => 0.12]);

        $autor = $this->osoba('oceniany');
        $this->analizuj($this->wpis($autor, 'Zwykły wpis o zupie, którego treść ocenia model.'));

        $oznaczenia = $this->oznaczenia();

        $this->assertCount(1, $oznaczenia);
        $this->assertSame(OcenaModelem::KOD, $oznaczenia->first()->reason);

        // POWÓD JEST ZDANIEM, NIE SUROWYM WYNIKIEM. Moderator, który dostaje
        // `hate: 0.91`, musi sam zgadnąć, czego szukać w treści.
        $powod = (string) $oznaczenia->first()->details;
        $this->assertStringContainsString('mowa nienawiści', $powod);
        $this->assertStringContainsString('91%', $powod);

        // Kategoria PONIŻEJ progu nie ma prawa się tu pojawić.
        $this->assertStringNotContainsString('przemoc', $powod);
    }

    public function test_ocena_modelu_wyprzedza_w_kolejce_sygnal_spamowy(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.91]);

        $autor = $this->osoba('dwasygnaly');

        // Treść zapala JEDNOCZEŚNIE lokalny wzorzec (numer telefonu)
        // i ocenę modelu. Ma powstać JEDNA pozycja z obydwoma powodami,
        // zakwalifikowana według cięższego z nich.
        $this->analizuj($this->wpis($autor, 'Ciasta na zamówienie, tel. 600 100 200.'));

        $oznaczenia = $this->oznaczenia();

        $this->assertCount(1, $oznaczenia, 'Dwa źródła sygnałów dały dwie pozycje zamiast jednej.');
        $this->assertSame(OcenaModelem::KOD, $oznaczenia->first()->reason);

        $powod = (string) $oznaczenia->first()->details;
        $this->assertStringContainsString('mowa nienawiści', $powod);
        $this->assertStringContainsString('numer telefonu', $powod);
    }

    // ---------------------------------------------------------------
    // NIE ŁAPIE
    // ---------------------------------------------------------------

    public function test_wynik_ponizej_progu_nie_trafia_do_kolejki(): void
    {
        Notification::fake();

        // „Zabiłam kurę na rosół", „krwisty stek", „ubić pianę" — model przy
        // polszczyźnie i kuchni bywa hojny. Poniżej naszego progu nie robimy
        // z tego pozycji do przejrzenia.
        $this->modelOdpowiada(['violence' => 0.31, 'hate' => 0.08]);

        $autor = $this->osoba('kucharka');
        $this->analizuj($this->wpis($autor, 'Zabiłam kurę z podwórka i ugotowałam z niej rosół na niedzielę.'));

        $this->assertCount(
            0,
            $this->oznaczenia(),
            'Automat oznaczył przepis, przy którym sam model nie był przekonany.',
        );
    }

    public function test_awaria_openai_nie_ma_zadnego_skutku(): void
    {
        Notification::fake();
        Http::fake(['*api.openai.com*' => Http::response('', 503)]);

        $autor = $this->osoba('mimoawarii');
        $wpis = $this->wpis($autor, 'Zwykły wpis o zupie, opublikowany w czasie awarii u dostawcy.');

        $this->analizuj($wpis);

        $this->assertCount(0, $this->oznaczenia());

        // Najważniejsze: treść stoi w serwisie tak samo jak przedtem.
        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->refresh()->status);
        $this->get($wpis->url())->assertOk();
    }

    public function test_bez_klucza_nie_wychodzi_ani_jedno_zadanie(): void
    {
        Notification::fake();
        Http::fake();
        config(['kuking.moderation.model.klucz' => null]);

        $this->assertFalse(KlientOpenAI::oceniamy());

        $autor = $this->osoba('bezklucza');
        $this->analizuj($this->wpis($autor, 'Zwykły wpis o zupie, bez skonfigurowanego modelu.'));

        Http::assertNothingSent();
        $this->assertCount(0, $this->oznaczenia());
    }

    public function test_prywatny_wpis_nie_wychodzi_do_openai(): void
    {
        Notification::fake();
        Http::fake();

        $autor = $this->osoba('prywatnie');

        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => 'Treść, której autor świadomie nie pokazał nikomu.',
            'visibility' => Post::VISIBILITY_PRIVATE,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->analizuj($wpis);

        // To nie jest optymalizacja, tylko granica prywatności: treść, której
        // autor nie pokazał nikomu, nie ma prawa wyjść poza nasz serwer.
        Http::assertNothingSent();
    }

    public function test_do_openai_nie_wychodzi_nic_identyfikujacego_autora(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.91]);

        $autor = $this->osoba('anonimowo');
        $wpis = $this->wpis($autor, 'Zwykły wpis o zupie, po którym sprawdzamy, co poszło w żądaniu.');

        $this->analizuj($wpis);

        Http::assertSent(function ($request) use ($autor, $wpis): bool {
            $cialo = (string) $request->body();

            // Adres e-mail, login, identyfikator wpisu i identyfikator konta
            // — żadne z nich nie ma prawa opuścić serwera. To jest warunek
            // wpisu w polityce prywatności, nie ostrożność na zapas.
            foreach ([$autor->email, (string) $autor->getKey(), (string) $wpis->getKey()] as $czegoNieWolno) {
                if ($czegoNieWolno !== '' && str_contains($cialo, $czegoNieWolno)) {
                    return false;
                }
            }

            // KONTROLA METODY POMIARU: sama treść wpisu MA tam być, inaczej
            // ten test przechodziłby dla pustego żądania. Fragment bez polskich
            // znaków, bo `json_encode` zamienia je na sekwencje `\uXXXX`
            // i dosłowne porównanie po „którym" nic by nie znalazło.
            return str_contains($cialo, 'sprawdzamy');
        });
    }

    // ---------------------------------------------------------------
    // GRANICA: WYNIK MODELU NICZEGO NIE ZAMYKA
    // ---------------------------------------------------------------

    public function test_tresc_wskazana_przez_model_zostaje_widoczna_a_autor_nic_nie_wie(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.95, 'violence' => 0.88]);

        $autor = $this->osoba('wskazany');
        $obcy = $this->osoba('ktokolwiek');

        $wpis = $this->wpis($autor, 'Treść, którą model ocenił najwyżej, jak umie ocenić.');
        $this->analizuj($wpis);

        $this->assertCount(1, $this->oznaczenia(), 'Test sprawdzałby widoczność treści, której model wcale nie wskazał.');

        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->refresh()->status);
        $this->assertSame(Post::VISIBILITY_PUBLIC, $wpis->visibility);

        $this->actingAs($autor)->get($wpis->url())->assertOk()->assertSee('którą model ocenił');
        $this->actingAs($obcy)->get($wpis->url())->assertOk()->assertSee('którą model ocenił');
        $this->get($wpis->url())->assertOk()->assertSee('którą model ocenił');

        $this->assertSame(
            0,
            PowiadomienieWSerwisie::query()->where('user_id', $autor->getKey())->count(),
            'Autor dostał powiadomienie o ocenie modelu — a z jego treścią nic się nie stało.',
        );
    }

    // ---------------------------------------------------------------
    // POCZTA
    // ---------------------------------------------------------------

    public function test_zwykle_oznaczenie_nie_wysyla_listu(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.91]);

        $this->analizuj($this->wpis($this->osoba('zwykly'), 'Treść oceniona przez model jako mowa nienawiści.'));

        $this->assertCount(1, $this->oznaczenia());

        // TO JEST SEDNO PROJEKTU POCZTY: przy setkach kont list na każde
        // oznaczenie zamieniłby skrzynkę moderatora w śmietnik i skończyłoby
        // się tym, że przestałby je otwierać.
        Notification::assertNothingSent();
    }

    public function test_tresc_seksualna_wysyla_list_natychmiast(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['sexual/minors' => 0.44]);

        $this->analizuj($this->wpis($this->osoba('pilny'), 'Treść, przy której zwłoka jednego dnia jest realną szkodą.'));

        $this->assertCount(1, $this->oznaczenia());
        Notification::assertSentOnDemand(PilnyAlarmModeracyjny::class);
    }

    public function test_bez_adresu_alarmowego_poczta_nie_wychodzi_i_nic_nie_pada(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => null]);
        $this->modelOdpowiada(['sexual/minors' => 0.44]);

        $this->analizuj($this->wpis($this->osoba('bezadresu'), 'Treść pilna, ale nie ma dokąd wysłać listu.'));

        $this->assertCount(1, $this->oznaczenia(), 'Brak adresu alarmowego zabrał pozycję z kolejki — a miał zabrać tylko list.');
        Notification::assertNothingSent();
    }

    public function test_podsumowanie_dobowe_idzie_jednym_listem(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.91]);

        foreach (['a', 'b', 'c'] as $login) {
            $this->analizuj($this->wpis($this->osoba('konto'.$login), 'Treść numer '.$login.' oceniona przez model jako nienawiść.'));
        }

        $this->assertCount(3, $this->oznaczenia());

        $this->artisan('kuking:podsumowanie-automatu')->assertSuccessful();

        Notification::assertSentOnDemandTimes(PodsumowanieKolejkiAutomatu::class, 1);
    }

    public function test_podsumowanie_nie_wychodzi_gdy_nie_ma_o_czym_pisac(): void
    {
        Notification::fake();

        $this->artisan('kuking:podsumowanie-automatu')->assertSuccessful();

        // „0 nowych pozycji" codziennie przez trzy tygodnie to najlepszy
        // sposób, żeby czwarty list przeszedł niezauważony.
        Notification::assertNothingSent();
    }
}
