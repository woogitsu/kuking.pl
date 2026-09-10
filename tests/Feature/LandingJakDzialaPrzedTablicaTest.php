<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\FeedController;
use App\Models\DailyPick;
use App\Models\Post;
use App\Support\Czas;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decyzja właściciela po audycie 60+ (`docs/research/AUDYT_60_PLUS.md`):
 * na landingu dla gościa „Jak działa" stoi PRZED tablicą „kuKINGi na dziś",
 * a sama tablica pokazuje gościowi mniej kart. Po zalogowaniu nic się nie
 * zmienia — to ogranicznie dotyczy wyłącznie `FeedController::landing()`.
 *
 * PUŁAPKA, KTÓREJ TEN PLIK SIĘ PILNUJE
 * `assertSee('Jak działa')` na CAŁYM dokumencie nie sprawdza kolejności —
 * złapałoby to samo słowo gdziekolwiek na stronie. Kolejność sprawdzamy
 * `assertSeeInOrder()` na surowym HTML-u, a liczbę kart LICZYMY wewnątrz
 * właściwego kontenera (`section.kuking-board`) przez DOMXPath, nie po
 * całym dokumencie — inaczej wpis, który pojawia się gdzie indziej na
 * stronie (np. w „Świeżo z Kuking"), fałszywie podbiłby licznik.
 */
class LandingJakDzialaPrzedTablicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_jak_dziala_stoi_przed_tablica_na_landingu_goscia(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey(), 'body' => 'Rosol na niedziele']);

        $this->get('/')->assertOk()->assertSeeInOrder(
            ['id="jak-dziala"', 'id="kuking-na-dzis"'],
            false,
        );
    }

    /**
     * KONTROLA UJEMNA (ręcznie wykonana, patrz opis PR-a): odwrócenie
     * kolejności sekcji w `landing.blade.php` oblewa ten test z komunikatem
     * PHPUnit „Failed asserting that ... contains ... in order", nie cichym
     * zielonym wynikiem.
     */
    public function test_naglowek_jak_dziala_ma_trzy_krotkie_kroki(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $sekcja = $this->wytnijSekcje($html, 'id="jak-dziala"', '</section>');

        $this->assertStringContainsString('Jak działa', $sekcja);
        $this->assertSame(3, substr_count($sekcja, 'class="rzecz"'), 'Sekcja „Jak działa" ma inną liczbę kroków niż trzy.');
    }

    /**
     * Gość nie widzi więcej niż ustaloną liczbę kart na tablicy — liczymy
     * WEWNĄTRZ `section.kuking-board`, nie na całej stronie.
     */
    public function test_gosc_widzi_najwyzej_ustalona_liczbe_kart_na_tablicy(): void
    {
        $gospodarz = $this->moderator();

        // Wybór redakcyjny nie ma górnej sumy w `Admin\DailyBoardController`
        // (do 6 osób I do 6 wpisów naraz) — bez obcięcia w kontrolerze
        // landingu gość zobaczyłby dziś więcej kart niż automat kiedykolwiek
        // pokazuje. Ustawiamy więcej niż limit gościa, żeby test naprawdę
        // sprawdzał obcinanie, a nie przypadkowo już mały automat.
        $osoby = collect(range(1, 5))->map(fn (int $i) => $this->user('board_osoba_'.$i)->getKey());
        $wpisy = collect(range(1, 5))->map(
            fn (int $i) => Post::factory()->create(['author_id' => $this->user('board_autor_'.$i)->getKey()])->getKey(),
        );

        foreach ($osoby as $pozycja => $userId) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_USER,
                'subject_id' => $userId,
                'position' => $pozycja,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }

        foreach ($wpisy as $pozycja => $postId) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_POST,
                'subject_id' => $postId,
                'position' => $pozycja,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }

        $tablica = $this->tablicaZDokumentu($this->get('/')->assertOk()->getContent());

        $this->assertSame(
            FeedController::GUEST_BOARD_PEOPLE,
            $tablica['osoby'],
            'Tablica na landingu gościa pokazuje inną liczbę osób niż ustalony limit dla gościa.',
        );
        $this->assertSame(
            FeedController::GUEST_BOARD_POSTS,
            $tablica['wpisy'],
            'Tablica na landingu gościa pokazuje inną liczbę dań niż ustalony limit dla gościa.',
        );
    }

    /**
     * Po zalogowaniu (`/home`) tablica NIE JEST dotknięta tym limitem —
     * ten sam wybór redakcyjny co w teście wyżej ma pokazać się w całości.
     */
    public function test_zalogowany_widzi_pelna_tablice_bez_limitu_gosia(): void
    {
        $gospodarz = $this->moderator();
        $widz = $this->user('widz');

        $osoby = collect(range(1, 5))->map(fn (int $i) => $this->user('home_osoba_'.$i)->getKey());
        $wpisy = collect(range(1, 5))->map(
            fn (int $i) => Post::factory()->create(['author_id' => $this->user('home_autor_'.$i)->getKey()])->getKey(),
        );

        foreach ($osoby as $pozycja => $userId) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_USER,
                'subject_id' => $userId,
                'position' => $pozycja,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }

        foreach ($wpisy as $pozycja => $postId) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_POST,
                'subject_id' => $postId,
                'position' => $pozycja,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }

        $tablica = $this->tablicaZDokumentu(
            $this->actingAs($widz)->get(route('home'))->assertOk()->getContent(),
        );

        $this->assertGreaterThan(
            FeedController::GUEST_BOARD_PEOPLE,
            $tablica['osoby'],
            'Ekran po zalogowaniu został ograniczony limitem gościa — to jest dokładnie to, czego nie wolno.',
        );
        $this->assertGreaterThan(
            FeedController::GUEST_BOARD_POSTS,
            $tablica['wpisy'],
            'Ekran po zalogowaniu został ograniczony limitem gościa — to jest dokładnie to, czego nie wolno.',
        );
        $this->assertSame(5, $tablica['osoby']);
        $this->assertSame(5, $tablica['wpisy']);
    }

    /**
     * Liczy karty osób i dań wyłącznie WEWNĄTRZ `section.kuking-board`.
     *
     * @return array{osoby: int, wpisy: int}
     */
    private function tablicaZDokumentu(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);

        $sekcja = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' kuking-board ')]")->item(0);

        $this->assertNotNull($sekcja, 'Nie znalazłem sekcji tablicy „kuKINGi na dziś" (`.kuking-board`) w dokumencie.');

        return [
            'osoby' => $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' kuking-board-person ')]", $sekcja)->length,
            'wpisy' => $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' kuking-board-post ')]", $sekcja)->length,
        ];
    }

    private function wytnijSekcje(string $html, string $od, string $doZnacznika): string
    {
        $start = strpos($html, $od);

        if ($start === false) {
            return '';
        }

        $koniec = strpos($html, $doZnacznika, $start);

        return $koniec === false ? substr($html, $start) : substr($html, $start, $koniec - $start);
    }
}
