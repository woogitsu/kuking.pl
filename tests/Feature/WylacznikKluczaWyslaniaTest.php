<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * `config()`. Trzy formularze, jedno sprawdzenie — gdyby któryś czytał
     * własną stałą (tak było przed tą zmianą), ten test by go złapał.
     */
    public function test_przy_wlaczonym_mechanizmie_wszystkie_trzy_formularze_niosa_klucz(): void
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
    }

    public function test_wylaczony_mechanizm_zdejmuje_ukryte_pole_ze_wszystkich_trzech_formularzy(): void
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
     * Wyłącznik NIE cofa `reports_one_open_per_pair` — i to jest zapisane
     * w `docs/DATABASE.md` oraz w `config/kuking.php`. Gdyby ktoś kiedyś
     * „uprościł" wyłącznik tak, żeby zdejmował także tamten indeks, ten test
     * powie o tym od razu.
     */
    public function test_wylacznik_nie_cofa_ochrony_zgloszen_z_konta(): void
    {
        config([self::KLUCZ_KONFIGURACJI => false]);

        $zglaszajaca = $this->user('zglaszajaca');
        $wpis = Post::factory()->for($this->user('autorwpisu'), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $adres = route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]);
        $tresc = ['reason' => 'spam', 'details' => 'To jest reklama sklepu, nie przepis.'];

        $this->actingAs($zglaszajaca)->post($adres, $tresc);
        $drugie = $this->actingAs($zglaszajaca)->post($adres, $tresc);

        $drugie->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            Report::query()->count(),
            'Wyłącznik klucza wysłania zdjął także ochronę, która od niego nie zależy.',
        );
    }
}
