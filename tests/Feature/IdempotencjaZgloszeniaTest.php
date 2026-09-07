<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\NumerSprawy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jedno otwarte zgłoszenie na parę (osoba, treść) — egzekwowane w BAZIE
 * (ADR `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`, §1.3 i §3.4).
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ
 * Dedup w PHP (`ReportContent`, `SELECT` + `return $existing`) działał przy
 * zwykłym podwójnym kliknięciu — i ten test to potwierdza, bo obietnica
 * z komentarza tamtej klasy jest w tym zakresie prawdziwa. Ale baza nie
 * broniła NICZEGO: ręczny `INSERT` identycznego otwartego zgłoszenia
 * przechodził, a dwa równoległe połączenia wstawiały dwa wiersze bez
 * czekania. Seeder, komenda konsolowa i przyszły endpoint nie przechodzą
 * przez ten `SELECT` i o nim nie wiedzą.
 *
 * `lockForUpdate()` NIE JEST TU NAPRAWĄ i nie został dodany: `SELECT ... FOR
 * UPDATE`, który nie zwrócił wiersza, nie blokuje niczego (ADR §1.4.2,
 * POMIAR 3d).
 *
 * ZGŁOSZENIA BEZ KONTA (DSA art. 16 ust. 2 lit. c) indeks częściowy pomija —
 * `reporter_id` jest tam `NULL`. Dostają własną ochronę: klucz wysłania
 * w ukrytym polu formularza.
 */
class IdempotencjaZgloszeniaTest extends TestCase
{
    use RefreshDatabase;

    private function kluczZFormularza(string $html): ?string
    {
        return preg_match('/name="klucz_wyslania" value="([^"]+)"/', $html, $trafienia) === 1
            ? $trafienia[1]
            : null;
    }

    /**
     * Identyfikatory, które przy surowym `INSERT` musi podać test.
     *
     * Surowy `INSERT` omija model, więc nie działa ani `HasUuids`, ani hak
     * nadający numer sprawy — a `reports.numer_sprawy` jest `NOT NULL`
     * i UNIQUE (D-029). Numer jest tu ZA KAŻDYM RAZEM inny celowo: przy
     * dwóch wierszach z tym samym numerem odbiłby się indeks numeru sprawy,
     * a testy niżej sprawdzają zupełnie inne ograniczenia i przechodziłyby
     * z niewłaściwego powodu.
     *
     * @return array{id: string, numer_sprawy: string}
     */
    private function identyfikatory(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'numer_sprawy' => NumerSprawy::wygeneruj(),
        ];
    }

    private function wpis(?User $autor = null): Post
    {
        return Post::factory()->for($autor ?? $this->user(), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
    }

    public function test_dwa_klikniecia_zglos_daja_jedno_zgloszenie(): void
    {
        $zglaszajaca = $this->user('zglaszajaca');
        $wpis = $this->wpis();

        $tresc = ['reason' => 'spam', 'details' => 'To jest reklama.'];
        $adres = route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]);

        $this->actingAs($zglaszajaca)->post($adres, $tresc)->assertSessionHasNoErrors();
        $this->actingAs($zglaszajaca)->post($adres, $tresc)->assertSessionHasNoErrors();

        $this->assertSame(1, Report::query()->count(), 'Podwójne kliknięcie „Zgłoś" utworzyło dwie sprawy moderacyjne.');
    }

    public function test_baza_odbija_drugie_otwarte_zgloszenie_tej_samej_pary(): void
    {
        // NAJWAŻNIEJSZY TEST W TYM PLIKU — ten sam argument, co przy
        // `moderation_actions_one_per_report`: `SELECT` w PHP chroni jedną
        // drogę, ograniczenie w bazie chroni wszystkie.
        $zglaszajacy = $this->user('zglaszajacybaza');
        $wpis = $this->wpis();

        $wiersz = [
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('reports')->insert($this->identyfikatory() + $wiersz);

        $this->expectException(QueryException::class);
        // Nazwa indeksu w komunikacie, a nie sam typ wyjątku: tabela ma dziś
        // trzy ograniczenia unikalności i test, który tego nie sprawdza,
        // przeszedłby także wtedy, gdyby odbiło się któreś z pozostałych.
        $this->expectExceptionMessageMatches('/reports_one_open_per_pair/');

        DB::table('reports')->insert($this->identyfikatory() + $wiersz);
    }

    public function test_nowe_zgloszenie_po_zamknieciu_poprzedniego_przechodzi(): void
    {
        // WYMÓG PRODUKTOWY, nie szczegół: ktoś zgłosił, dostał decyzję,
        // a treść znów jest nie w porządku — musi móc zgłosić ponownie.
        $zglaszajacy = $this->user('zglaszajacyponownie');
        $wpis = $this->wpis();

        $pierwsze = Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $pierwsze->forceFill(['status' => Report::STATUS_RESOLVED, 'resolved_at' => now()])->save();

        $this->actingAs($zglaszajacy)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), [
                'reason' => 'harassment',
                'details' => 'Znów to samo, po decyzji.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Report::query()->count(), 'Po zamknięciu sprawy nie da się zgłosić treści ponownie.');
        $this->assertSame(1, Report::query()->where('status', Report::STATUS_OPEN)->count());
    }

    public function test_dwie_rozne_osoby_zglaszaja_ta_sama_tresc_niezaleznie(): void
    {
        // Indeks jest na PARZE (osoba, treść), nie na treści — dwa
        // niezależne zgłoszenia tej samej treści to dwie sprawy.
        $wpis = $this->wpis();

        foreach (['pierwszaosoba', 'drugaosoba'] as $nazwa) {
            $this->actingAs($this->user($nazwa))
                ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), [
                    'reason' => 'spam',
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Report::query()->count());
    }

    public function test_dwa_klikniecia_zgloszenia_bez_konta_daja_jedna_sprawe(): void
    {
        // Zgłoszenie bez konta to zgłoszenie o najwyższej stawce, a duplikat
        // tworzy drugą sprawę moderacyjną z własnym terminem odpowiedzi
        // z DSA art. 16. Indeks częściowy tych wierszy nie widzi
        // (`reporter_id IS NULL`), więc ochroną jest klucz wysłania.
        $formularz = $this->get(route('zglos.nielegalna'));
        $klucz = $this->kluczZFormularza($formularz->getContent());

        $tresc = [
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
            'klucz_wyslania' => $klucz,
        ];

        $pierwsze = $this->post(route('zglos.nielegalna.store'), $tresc);

        // Numer trzeba odczytać ZARAZ po żądaniu: sesja testowa jest jedna
        // i drugie żądanie nadpisze w niej tę wartość.
        $numerPierwszy = $pierwsze->getSession()->get('numer');

        $drugie = $this->post(route('zglos.nielegalna.store'), $tresc);
        $numerDrugi = $drugie->getSession()->get('numer');

        $drugie->assertSessionHasNoErrors();

        $this->assertSame(1, Report::query()->count(), 'Podwójne kliknięcie utworzyło dwie sprawy z osobnymi terminami z DSA.');

        // Człowiek widzi TEN SAM numer sprawy co przy pierwszym kliknięciu —
        // inaczej zapisałby numer, którego nie ma w kolejce moderacji.
        $this->assertNotNull($numerPierwszy);
        $this->assertSame($numerPierwszy, $numerDrugi, 'Drugie kliknięcie pokazało inny numer sprawy niż pierwsze.');
    }

    public function test_zgloszenie_bez_konta_z_nowego_formularza_jest_nowa_sprawa(): void
    {
        // NIE SCALAMY DUPLIKATÓW zgłoszeń prawnych: dwa zgłoszenia tej samej
        // treści mogą pochodzić od dwóch osób, z dwóch podstaw prawnych,
        // i każdej należy się osobna odpowiedź (`ZglosNielegalnaTresc`).
        foreach ([1, 2] as $ktore) {
            $klucz = $this->kluczZFormularza($this->get(route('zglos.nielegalna'))->getContent());

            $this->post(route('zglos.nielegalna.store'), [
                'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
                'reason' => 'copyright',
                'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
                'good_faith' => '1',
                'klucz_wyslania' => $klucz,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Report::query()->count(), 'Drugie, świadome zgłoszenie tej samej treści zostało wyciszone.');
    }

    public function test_zgloszenie_bez_konta_bez_klucza_dalej_przechodzi(): void
    {
        // Zawodzenie otwarte (ADR §4.3). Odmowa uderzyłaby najmocniej
        // w osoby z najstarszymi przeglądarkami — a to jest droga, którą
        // przepis nakazuje udostępnić każdemu.
        $this->post(route('zglos.nielegalna.store'), [
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'minor',
            'illegality_explanation' => 'Na tym zdjęciu jest moje dziecko, bez mojej zgody.',
            'good_faith' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Report::query()->count());
    }

    public function test_klucz_z_cudzego_formularza_nie_pokazuje_cudzej_sprawy(): void
    {
        // UUID w żądaniu nie jest autoryzacją (AGENTS.md §7). Klucz podstawiony
        // z cudzego formularza nie może ani zablokować własnego zgłoszenia,
        // ani pokazać numeru cudzej sprawy.
        $klucz = $this->kluczZFormularza($this->get(route('zglos.nielegalna'))->getContent());

        $pierwsze = $this->post(route('zglos.nielegalna.store'), [
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'Pierwsze zgłoszenie, moje własne uzasadnienie.',
            'good_faith' => '1',
            'klucz_wyslania' => $klucz,
        ]);

        $numerPierwszy = $pierwsze->getSession()->get('numer');

        // Ta sama wartość klucza, ale zupełnie inne zgłoszenie i inna osoba.
        $obce = $this->post(route('zglos.nielegalna.store'), [
            'target_url' => 'https://kuking.pl/wpisy/'.Str::uuid7(),
            'reason' => 'harassment',
            'illegality_explanation' => 'Zupełnie inna sprawa, zupełnie inna osoba.',
            'good_faith' => '1',
            'klucz_wyslania' => $klucz,
        ]);

        $obce->assertSessionHasNoErrors();

        $this->assertSame(2, Report::query()->count(), 'Cudzy klucz zablokował przyjęcie osobnej sprawy.');

        // Sprawa pierwszej osoby jest nietknięta i wciąż trzyma swój klucz;
        // druga powstała jako WŁASNY wiersz, bez klucza (zawodzenie otwarte).
        $pierwszaSprawa = Report::query()->where('illegality_explanation', 'Pierwsze zgłoszenie, moje własne uzasadnienie.')->firstOrFail();
        $obcaSprawa = Report::query()->where('illegality_explanation', 'Zupełnie inna sprawa, zupełnie inna osoba.')->firstOrFail();

        $this->assertNotSame($pierwszaSprawa->getKey(), $obcaSprawa->getKey(), 'Cudzy klucz oddał wiersz innej osoby.');
        $this->assertNotNull($pierwszaSprawa->klucz_wyslania);
        $this->assertNull($obcaSprawa->klucz_wyslania, 'Klucz podstawiony z cudzego formularza został zapisany przy obcej sprawie.');
        $this->assertNotNull($numerPierwszy);
    }

    public function test_baza_odbija_drugie_zgloszenie_z_tym_samym_kluczem(): void
    {
        $wiersz = [
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/cos',
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
            'illegality_explanation' => 'Uzasadnienie, którego baza wymaga.',
            'good_faith_at' => now(),
            'klucz_wyslania' => (string) Str::uuid7(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('reports')->insert($this->identyfikatory() + $wiersz);

        $this->expectException(QueryException::class);
        // Ten sam powód co wyżej: sprawdzamy, że odbił się indeks KLUCZA
        // WYSŁANIA, a nie numeru sprawy ani pary (zgłaszający, treść).
        $this->expectExceptionMessageMatches('/reports_one_per_klucz_wyslania/');

        DB::table('reports')->insert($this->identyfikatory() + $wiersz);
    }
}
