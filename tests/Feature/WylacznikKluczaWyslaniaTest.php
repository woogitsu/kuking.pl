<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use App\Support\NumerSprawy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wyłącznik awaryjny idempotencji formularzy działa — dla wszystkich trzech
 * formularzy naraz (D-027, `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` §8.4,
 * „Wyjście 1").
 *
 * PO CO TEN PLIK ISTNIEJE
 * Wyłącznik jest jedyną drogą wycofania mechanizmu, która NIE wymaga
 * wdrożenia migracji — a wycofuje się go w środku awarii, kiedy nikt nie ma
 * czasu sprawdzać, czy naprawdę cokolwiek robi. Wyłącznik bez testu jest
 * obietnicą bez pokrycia w kodzie, czyli dokładnie tym rodzajem błędu, który
 * to repozytorium znalazło u siebie już kilka razy (martwy limit `'upload'`,
 * `kuking.media_disk`, placeholder retencji z D-024).
 *
 * AWARIA, PRZED KTÓRĄ WYŁĄCZNIK CHRONI, opisana wprost: gdyby klucz przestał
 * poprawnie przechodzić przez `old()`, drugie — POPRAWIONE — wysłanie
 * zostałoby uznane za duplikat pierwszego. Poprawiony wpis nie powstaje,
 * a serwis odsyła człowieka do wpisu, którego nie ma.
 *
 * CZEGO TEN PLIK NIE SPRAWDZA
 * Że przy wyłączniku `false` znika indeks w bazie — bo nie znika i nie ma
 * znikać. Kolumna dostaje `NULL`, a indeks jest CZĘŚCIOWY
 * (`WHERE klucz_wyslania IS NOT NULL`), więc takich wierszy nie obejmuje.
 * To jest właśnie powód, dla którego wycofanie nie potrzebuje migracji —
 * i dlatego każdy test niżej asercjonuje, że kolumna jest `NULL`.
 */
class WylacznikKluczaWyslaniaTest extends TestCase
{
    use RefreshDatabase;

    private const KLUCZ_KONFIGURACJI = 'kuking.formularze.klucz_wyslania_wlaczony';

    private function kluczZFormularza(string $html): ?string
    {
        return preg_match('/name="klucz_wyslania" value="([^"]+)"/', $html, $trafienia) === 1
            ? $trafienia[1]
            : null;
    }

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function wpisDoZgloszenia(User $autor): Post
    {
        return Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * Identyfikatory, które przy surowym `INSERT` musi podać test.
     *
     * Surowy `INSERT` omija model, więc nie działa ani `HasUuids`, ani hak
     * nadający numer sprawy — a `reports.numer_sprawy` jest `NOT NULL`
     * i UNIQUE (D-029). Numer jest tu za każdym razem inny celowo: przy dwóch
     * wierszach z tym samym numerem odbiłby się indeks numeru sprawy, a test
     * niżej sprawdza zupełnie inne ograniczenie i przeszedłby z niewłaściwego
     * powodu. Ten sam wzorzec, co w `IdempotencjaZgloszeniaTest`.
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

    /**
     * ASERCJA KONTROLNA CAŁEGO PLIKU.
     *
     * Bez niej każdy test niżej przechodziłby także wtedy, gdyby mechanizm
     * był wyłączony na stałe — a wtedy „wyłącznik działa" znaczyłoby
     * „nic nie działa". Domyślną wartością jest `true`, bo domyślnym stanem
     * serwisu jest ochrona, a nie jej brak.
     */
    public function test_domyslnie_mechanizm_jest_wlaczony(): void
    {
        $this->assertTrue(
            (bool) config(self::KLUCZ_KONFIGURACJI),
            'Domyślną wartością wyłącznika musi być `true` — inaczej serwis wychodzi na produkcję bez ochrony.',
        );
    }

    /**
     * I druga połowa kontroli: że tę wartość naprawdę czyta kod, a nie tylko
     * `config()`. Cztery formularze, jedno sprawdzenie — gdyby któryś czytał
     * własną stałą (tak było przed tą zmianą), ten test by go złapał.
     */
    public function test_przy_wlaczonym_mechanizmie_wszystkie_cztery_formularze_niosa_klucz(): void
    {
        config([self::KLUCZ_KONFIGURACJI => true]);

        $osoba = $this->user('kucharka');
        $przepis = $this->przepis($this->user('autorka'));

        $this->assertNotNull(
            $this->kluczZFormularza($this->actingAs($osoba)->get(route('posts.create'))->getContent()),
            'Formularz „Dodaj zdjęcie" nie wystawił ukrytego pola, choć mechanizm jest włączony.',
        );
        $this->assertNotNull(
            $this->kluczZFormularza($this->actingAs($osoba)->get(route('cooked.create', $przepis->slug))->getContent()),
            'Formularz „Ugotowałem" nie wystawił ukrytego pola, choć mechanizm jest włączony.',
        );
        $this->assertNotNull(
            $this->kluczZFormularza($this->get(route('zglos.nielegalna'))->getContent()),
            'Formularz zgłoszenia nie wystawił ukrytego pola, choć mechanizm jest włączony.',
        );
        $this->assertNotNull(
            $this->kluczZFormularza($this->actingAs($osoba)->get(route('recipes.create'))->getContent()),
            'Formularz „Dodaj przepis" nie wystawił ukrytego pola, choć mechanizm jest włączony.',
        );
    }

    public function test_wylaczony_mechanizm_zdejmuje_ukryte_pole_ze_wszystkich_czterech_formularzy(): void
    {
        config([self::KLUCZ_KONFIGURACJI => false]);

        $osoba = $this->user('kucharka');
        $przepis = $this->przepis($this->user('autorka'));

        $this->assertNull(
            $this->kluczZFormularza($this->actingAs($osoba)->get(route('posts.create'))->getContent()),
            'Formularz „Dodaj zdjęcie" dalej wystawia ukryte pole przy wyłączonym mechanizmie.',
        );
        $this->assertNull(
            $this->kluczZFormularza($this->actingAs($osoba)->get(route('cooked.create', $przepis->slug))->getContent()),
            'Formularz „Ugotowałem" dalej wystawia ukryte pole przy wyłączonym mechanizmie.',
        );
        $this->assertNull(
            $this->kluczZFormularza($this->get(route('zglos.nielegalna'))->getContent()),
            'Formularz zgłoszenia dalej wystawia ukryte pole przy wyłączonym mechanizmie.',
        );
        $this->assertNull(
            $this->kluczZFormularza($this->actingAs($osoba)->get(route('recipes.create'))->getContent()),
            'Formularz „Dodaj przepis" dalej wystawia ukryte pole przy wyłączonym mechanizmie.',
        );
    }

    public function test_wylaczony_mechanizm_przywraca_zachowanie_sprzed_d027_dla_przepisu(): void
    {
        config([self::KLUCZ_KONFIGURACJI => false]);

        $osoba = $this->user('przepisujaca');

        // Formularz nie ma już ukrytego pola, więc przeglądarka nie ma czego
        // odesłać — wysyłamy dokładnie to, co wysłałaby ona.
        $tresc = [
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'skladniki_tekst' => "kura\nmarchew",
            'przygotowanie_tekst' => 'Zagotuj wodę.',
        ];

        $this->actingAs($osoba)->post(route('recipes.store'), $tresc);
        $drugie = $this->actingAs($osoba)->post(route('recipes.store'), $tresc);

        $drugie->assertSessionHasNoErrors();

        // DWA przepisy, i to jest tu POPRAWNY wynik — wyłącznik ma przywrócić
        // zachowanie sprzed ochrony, z duplikatami włącznie.
        $this->assertSame(2, Recipe::query()->count(), 'Wyłącznik nie przywrócił zachowania sprzed ochrony przepisu.');

        $this->assertSame(
            0,
            Recipe::query()->whereNotNull('klucz_wyslania')->count(),
            'Kolumna dostała wartość, choć formularz nie wysyłał klucza.',
        );
    }

    public function test_wylaczony_mechanizm_przywraca_zachowanie_sprzed_d027_dla_wpisu(): void
    {
        config([self::KLUCZ_KONFIGURACJI => false]);

        $osoba = $this->user('publikujaca');

        // Formularz nie ma już ukrytego pola, więc przeglądarka nie ma czego
        // odesłać — wysyłamy dokładnie to, co wysłałaby ona.
        $tresc = [
            'body' => 'Rosół na niedzielę, z kaczki od sąsiada.',
            'visibility' => 'public',
        ];

        $this->actingAs($osoba)->post(route('posts.store'), $tresc);
        $drugie = $this->actingAs($osoba)->post(route('posts.store'), $tresc);

        $drugie->assertSessionHasNoErrors();

        // DWA wpisy, i to jest tu POPRAWNY wynik: wyłącznik ma przywrócić
        // zachowanie sprzed D-027 — z duplikatami, ale bez ryzyka
        // zablokowanej wysyłki.
        $this->assertSame(2, Post::query()->count(), 'Wyłącznik nie przywrócił zachowania sprzed D-027.');

        $this->assertSame(
            0,
            Post::query()->whereNotNull('klucz_wyslania')->count(),
            'Kolumna dostała wartość, choć formularz nie wysyłał klucza — indeks częściowy zaczyna wtedy obowiązywać.',
        );
    }

    public function test_wylaczony_mechanizm_przywraca_zachowanie_sprzed_d027_dla_ugotowalem(): void
    {
        config([self::KLUCZ_KONFIGURACJI => false]);

        $kucharz = $this->user('gotujaca');
        $przepis = $this->przepis($this->user('autorka'));

        $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), []);
        $drugie = $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), []);

        $drugie->assertSessionHasNoErrors();

        $this->assertSame(2, CookedEvent::query()->count(), 'Wyłącznik nie przywrócił zachowania sprzed D-027.');

        $this->assertSame(
            0,
            CookedEvent::query()->whereNotNull('klucz_wyslania')->count(),
            'Kolumna dostała wartość, choć formularz nie wysyłał klucza.',
        );
    }

    public function test_wylaczony_mechanizm_przywraca_zachowanie_sprzed_d027_dla_zgloszenia_bez_konta(): void
    {
        config([self::KLUCZ_KONFIGURACJI => false]);

        $tresc = [
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
        ];

        $this->post(route('zglos.nielegalna.store'), $tresc);
        $drugie = $this->post(route('zglos.nielegalna.store'), $tresc);

        $drugie->assertSessionHasNoErrors();

        // Zgłoszenie bez konta jest poza indeksem `reports_one_open_per_pair`
        // (`reporter_id IS NULL`), więc po wyłączeniu klucza nie chroni go już
        // NIC — i to jest cena tego wyłącznika, zapisana tu wprost, a nie
        // odkrywana w trakcie awarii.
        $this->assertSame(2, Report::query()->count(), 'Wyłącznik nie przywrócił zachowania sprzed D-027.');

        $this->assertSame(
            0,
            Report::query()->whereNotNull('klucz_wyslania')->count(),
            'Kolumna dostała wartość, choć formularz nie wysyłał klucza.',
        );
    }

    /**
     * ASERCJA KONTROLNA DLA TESTU NIŻEJ.
     *
     * Zanim sprawdzimy, że wyłączony mechanizm IGNORUJE klucz przysłany ze
     * starej karty, trzeba pokazać, że przy włączonym ten sam klucz naprawdę
     * scala oba wysłania w jeden wpis. Bez tego test niżej przechodziłby
     * także wtedy, gdyby idempotencja w ogóle nie działała — czyli mierzyłby
     * pustkę.
     */
    public function test_przy_wlaczonym_mechanizmie_klucz_ze_starej_karty_scala_wyslania(): void
    {
        config([self::KLUCZ_KONFIGURACJI => true]);

        $osoba = $this->user('publikujaca');
        $klucz = (string) Str::uuid7();

        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => 'Rosół na niedzielę, z kaczki od sąsiada.',
            'visibility' => 'public',
            'klucz_wyslania' => $klucz,
        ]);

        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => 'Rosół na niedzielę, z kaczki od sąsiada. Poprawiona literówka.',
            'visibility' => 'public',
            'klucz_wyslania' => $klucz,
        ]);

        $this->assertSame(
            1,
            Post::query()->count(),
            'Ten sam klucz dał dwa wpisy — idempotencja nie działa i test niżej nic by nie mierzył.',
        );
    }

    /**
     * Wyłącznik obejmuje także klucz PRZYSŁANY, nie tylko renderowany.
     *
     * SKĄD TO SIĘ WZIĘŁO
     * Pierwsza wersja wyłącznika bramkowała wyłącznie stronę renderowania:
     * formularz przestawał nieść ukryte pole, ale `kluczZZadania()` nadal
     * przyjmował klucz z żądania. W awarii, dla której ten wyłącznik istnieje,
     * to jest połowa, która nie działa — bo karty są już otwarte. Basia ma
     * „Dodaj zdjęcie" otwarte sprzed przełączenia (albo wraca na nie przyciskiem
     * Wstecz), ukryte pole wciąż siedzi w DOM-ie, przeglądarka je odsyła,
     * kolumna dostaje wartość, częściowy indeks znów obowiązuje — i POPRAWIONE
     * wysłanie zostaje uznane za duplikat pierwszego. Czyli operator przełączył
     * wyłącznik, a awaria trwa dalej.
     *
     * `config/kuking.php` obiecuje wprost: po wyłączeniu „kolumna dostaje
     * `NULL`" i serwis wraca „dokładnie do zachowania sprzed D-027". Ten test
     * jest tym, co pilnuje, żeby to zdanie miało pokrycie w kodzie.
     */
    public function test_wylaczony_mechanizm_ignoruje_klucz_przyslany_ze_starej_karty(): void
    {
        config([self::KLUCZ_KONFIGURACJI => false]);

        $klucz = (string) Str::uuid7();

        // WPIS — tu strata jest najbardziej dotkliwa: drugie wysłanie niesie
        // POPRAWIONĄ treść, więc uznanie go za duplikat kasuje poprawkę.
        $osoba = $this->user('publikujaca');
        $pierwsza = 'Rosół na niedzielę, z kaczki od sąsiada.';
        $druga = 'Rosół na niedzielę, z kaczki od sąsiada. Poprawiona literówka.';

        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => $pierwsza, 'visibility' => 'public', 'klucz_wyslania' => $klucz,
        ]);
        $this->actingAs($osoba)->post(route('posts.store'), [
            'body' => $druga, 'visibility' => 'public', 'klucz_wyslania' => $klucz,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Post::query()->count(), 'Klucz ze starej karty nadal scala wysłania.');
        $this->assertSame(
            0,
            Post::query()->whereNotNull('klucz_wyslania')->count(),
            'Kolumna dostała wartość mimo wyłącznika — częściowy indeks znów obowiązuje.',
        );

        // Poprawiona treść ma NAPRAWDĘ istnieć. Dwa wiersze to za mało:
        // liczyłyby się także dwie kopie pierwszej wersji.
        $this->assertSame(
            1,
            Post::query()->where('body', $druga)->count(),
            'Poprawiona treść nie powstała — to jest ta strata, przed którą chroni wyłącznik.',
        );

        // UGOTOWAŁEM
        $kucharz = $this->user('gotujaca');
        $przepis = $this->przepis($this->user('autorka'));
        $adresUgotowalem = route('cooked.store', $przepis->slug);

        $this->actingAs($kucharz)->post($adresUgotowalem, ['klucz_wyslania' => $klucz]);
        $this->actingAs($kucharz)->post($adresUgotowalem, ['klucz_wyslania' => $klucz])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, CookedEvent::query()->count(), 'Klucz ze starej karty nadal scala „Ugotowałem".');
        $this->assertSame(
            0,
            CookedEvent::query()->whereNotNull('klucz_wyslania')->count(),
            'Kolumna dostała wartość mimo wyłącznika.',
        );

        // ZGŁOSZENIE BEZ KONTA
        $zgloszenie = [
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
            'klucz_wyslania' => $klucz,
        ];

        $this->post(route('zglos.nielegalna.store'), $zgloszenie);
        $this->post(route('zglos.nielegalna.store'), $zgloszenie)->assertSessionHasNoErrors();

        $this->assertSame(2, Report::query()->count(), 'Klucz ze starej karty nadal scala zgłoszenia.');
        $this->assertSame(
            0,
            Report::query()->whereNotNull('klucz_wyslania')->count(),
            'Kolumna dostała wartość mimo wyłącznika.',
        );
    }

    /**
     * Wyłącznik NIE cofa indeksu `reports_one_open_per_pair` — i to jest
     * zapisane w `docs/DATABASE.md` oraz w `config/kuking.php`. Gdyby ktoś
     * kiedyś „uprościł" wyłącznik tak, żeby zdejmował także tamten indeks,
     * ten test powie o tym od razu.
     *
     * DLACZEGO NIE PRZEZ KONTROLER — ZMIERZONE
     * Poprzednia wersja tego testu wysyłała dwa razy `reports.store`
     * i asertowała jeden wiersz w `reports`. Ta droga nie dotyka indeksu ANI
     * RAZU: `ReportContent::handle()` NAJPIERW robi `SELECT`
     * (`otwarteZgloszenie()`) i przy drugim żądaniu oddaje istniejący wiersz,
     * w ogóle nie próbując `INSERT`-a. Po `DROP INDEX reports_one_open_per_pair`
     * tamten test dalej byłby zielony — czyli obiecywał ochronę, której nie
     * mierzył. Dokładnie ten rodzaj obietnicy bez pokrycia, przed którym
     * ostrzega nagłówek tego pliku.
     *
     * Dlatego drugi wiersz wstawiamy SUROWO, z pominięciem akcji — tak samo
     * jak `IdempotencjaZgloszeniaTest`. To jest zresztą ta sama droga, dla
     * której ten indeks w ogóle powstał: seeder, komenda konsolowa i przyszły
     * endpoint nie przechodzą przez `SELECT` w PHP i o nim nie wiedzą
     * (migracja `2026_09_07_900000_one_open_report_per_pair`).
     *
     * Sprawdzamy NAZWĘ indeksu w komunikacie, a nie sam typ wyjątku: tabela
     * `reports` ma dziś kilka ograniczeń unikalności i „jakiś wyjątek" nie
     * dowodzi niczego.
     */
    public function test_wylacznik_nie_cofa_indeksu_jednego_otwartego_zgloszenia_na_pare(): void
    {
        config([self::KLUCZ_KONFIGURACJI => false]);

        $zglaszajaca = $this->user('zglaszajaca');
        $wpis = $this->wpisDoZgloszenia($this->user('autorwpisu'));

        // Bez `klucz_wyslania` — i to jest celowe. Wiersz z `NULL` w tej
        // kolumnie jest poza indeksem `reports_one_per_klucz_wyslania`
        // (częściowy, `WHERE klucz_wyslania IS NOT NULL`), więc odbić się tu
        // może wyłącznie indeks pary. Tak też wygląda wiersz po wyłączeniu
        // mechanizmu.
        $wiersz = [
            'reporter_id' => $zglaszajaca->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('reports')->insert($this->identyfikatory() + $wiersz);

        // ASERCJE KONTROLNE. Bez nich test przechodziłby także wtedy, gdyby
        // pierwszy wiersz w ogóle nie wszedł albo wszedł POZA warunek indeksu
        // częściowego (`reporter_id IS NOT NULL AND status IN
        // ('open','triage','reviewing')`) — a wtedy wyjątek niżej miałby
        // zupełnie inne źródło niż to, co ten test nazywa.
        $this->assertSame(
            1,
            Report::query()->whereNotNull('reporter_id')->where('status', Report::STATUS_OPEN)->count(),
            'Pierwszy wiersz nie wszedł albo wszedł poza warunek indeksu — test niżej nic by nie mierzył.',
        );
        $this->assertSame(
            0,
            Report::query()->whereNotNull('klucz_wyslania')->count(),
            'Wiersz niesie klucz wysłania, więc odbić się może indeks klucza, a nie indeks pary.',
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/reports_one_open_per_pair/');

        DB::table('reports')->insert($this->identyfikatory() + $wiersz);
    }

    /**
     * Wyłącznik nie cofa też DEDUPLIKACJI W AKCJI — to osobna, słabsza
     * ochrona niż indeks z testu wyżej i warto wiedzieć, że przeżywa
     * przełączenie wyłącznika.
     *
     * UWAGA NA ZAKRES, ŻEBY NIKT NIE PRZECZYTAŁ TEGO SZERZEJ, NIŻ JEST:
     * ten test idzie przez `reports.store`, więc mierzy `SELECT`
     * w `ReportContent::handle()` (`otwarteZgloszenie()`), a NIE indeks
     * `reports_one_open_per_pair`. Drugie żądanie dostaje istniejący wiersz,
     * zanim w ogóle dojdzie do `INSERT`-a, więc po `DROP INDEX` ten test dalej
     * byłby zielony. Indeksu pilnuje test wyżej i tylko on.
     */
    public function test_wylacznik_nie_cofa_deduplikacji_zgloszen_w_akcji(): void
    {
        config([self::KLUCZ_KONFIGURACJI => false]);

        $zglaszajaca = $this->user('zglaszajaca');
        $wpis = $this->wpisDoZgloszenia($this->user('autorwpisu'));

        $adres = route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]);
        $tresc = ['reason' => 'spam', 'details' => 'To jest reklama sklepu, nie przepis.'];

        $pierwsze = $this->actingAs($zglaszajaca)->post($adres, $tresc);
        $drugie = $this->actingAs($zglaszajaca)->post($adres, $tresc);

        // ASERCJA KONTROLNA (1): oba żądania naprawdę zostały przyjęte. Bez
        // tego jeden wiersz znaczyłby także „drugie żądanie odbiła walidacja
        // albo bramka widoczności" — czyli coś zupełnie innego niż dedup.
        $pierwsze->assertSessionHasNoErrors();
        $drugie->assertSessionHasNoErrors();

        // ASERCJA KONTROLNA (2): wyłącznik naprawdę jest wyłączony, więc
        // jeden wiersz to zasługa `SELECT`-a w akcji, a nie klucza wysłania.
        $this->assertSame(
            0,
            Report::query()->whereNotNull('klucz_wyslania')->count(),
            'Kolumna dostała wartość mimo wyłącznika — jeden wiersz tłumaczyłby wtedy klucz, nie dedup w akcji.',
        );

        $this->assertSame(
            1,
            Report::query()->count(),
            'Wyłącznik klucza wysłania zdjął także deduplikację w akcji, która od niego nie zależy.',
        );

        // I że to jest sprawa TEJ pary, a nie przypadkowy wiersz.
        $sprawa = Report::query()->firstOrFail();

        $this->assertSame($zglaszajaca->getKey(), $sprawa->reporter_id);
        $this->assertSame('post', $sprawa->target_type);
        $this->assertSame($wpis->getKey(), $sprawa->target_id);
    }
}
