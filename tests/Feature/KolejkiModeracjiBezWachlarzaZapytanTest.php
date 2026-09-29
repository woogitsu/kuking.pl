<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\Tag;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kolejki spraw — czy liczba zapytań ROŚNIE z liczbą wierszy.
 *
 * DLACZEGO TE EKRANY, SKORO ŻADEN Z NICH NIE BYŁ ZEPSUTY
 * Bo do tej pory żadnego z nich NIKT NIE ZMIERZYŁ, a dwa z nich miały
 * w kodzie komentarz obiecujący, że wachlarza tam nie ma:
 *
 *  - `SygnalyController::podglady()` tłumaczy, czemu adresy oznaczonych
 *    treści idą dwoma zapytaniami na stronę zamiast dwustu, i kończy
 *    zdaniem „Pilnuje tego `KolejkaSygnalowBezWachlarzaZapytanTest`".
 *    Klasy o tej nazwie w repozytorium NIE BYŁO — ani jednego pliku, ani
 *    jednego przebiegu. Zdanie o teście, którego nie ma, jest gorsze niż
 *    brak zdania: czyta się je jako pomiar, a jest deklaracją zamiaru.
 *  - `SocialController::connections()` opisuje zdjętą usterkę („około
 *    czterdziestu zapytań na stronę") i też nie zostawił po sobie testu.
 *
 * Ten plik zamyka pierwszą połowę tej listy (kolejki moderacji i sprawy
 * zgłaszającego); listy osób i strony treści pilnuje
 * `StronyTresciBezWachlarzaZapytanTest`.
 *
 * METODA jest ta sama co w `MiniaturyBezWachlarzaZapytanTest` i nie jest
 * to wybór stylistyczny: „ile zapytań wypada" trzeba by poprawiać przy
 * każdej uzasadnionej zmianie ekranu, a „czy liczba rośnie z liczbą wierszy"
 * przeżyje każdą z nich i dalej będzie mówić o tej samej usterce.
 *
 * ZMIERZONE (małe → duże, wszystkie pięć kolejek płaskie):
 * `/zgloszenia` 5 → 5, `/admin/zgloszenia` 11 → 11, `/admin/sygnaly` 12 → 12,
 * `/admin/odwolania` 12 → 12, `/admin/bez-odpowiedzi` 9 → 9.
 */
class KolejkiModeracjiBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    /** Pełna strona kolejki zgłoszeń to 25 wierszy; 20 mieści się w każdej z pięciu. */
    private const DUZO = 20;

    private const MALO = 2;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    public function test_moje_zgloszenia_nie_maja_wachlarza_zapytan(): void
    {
        $zglaszajacy = $this->user('zglaszajaca');

        $this->zgloszeniaOdCzlowieka($zglaszajacy, self::MALO);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($zglaszajacy)->get(route('reports.mine'))->assertOk(),
        );

        $this->zgloszeniaOdCzlowieka($zglaszajacy, self::DUZO - self::MALO);

        $this->assertSame(self::DUZO, Report::query()->count());

        // KONTROLA DODATNIA (docs/PULAPKI_TESTOW.md §4): płaska liczba zapytań
        // przechodzi także wtedy, gdy lista jest PUSTA — a wtedy nie mierzy
        // niczego. Numer sprawy stoi przy każdym wierszu tej listy i nigdzie
        // indziej na ekranie.
        $html = $this->actingAs($zglaszajacy)->get(route('reports.mine'))->assertOk()->getContent();
        $this->assertStringContainsString(
            (string) Report::query()->orderByDesc('created_at')->firstOrFail()->numer_sprawy,
            $html,
            'Na ekranie nie ma ani jednej sprawy — pomiar niżej nie mierzy listy, tylko samą obudowę strony.',
        );

        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($zglaszajacy)->get(route('reports.mine'))->assertOk(),
        );

        $this->assertSame($malo, $duzo, $this->komunikat('/zgloszenia', $malo, $duzo));
    }

    public function test_kolejka_zgloszen_moderatora_nie_ma_wachlarza_zapytan(): void
    {
        $moderator = $this->moderator();
        $zglaszajacy = $this->user('zglaszajacy_do_panelu', ['display_name' => 'Zgłaszająca do panelu']);

        $this->zgloszeniaOdCzlowieka($zglaszajacy, self::MALO);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($moderator)->get(route('admin.reports'))->assertOk(),
        );

        $this->zgloszeniaOdCzlowieka($zglaszajacy, self::DUZO - self::MALO);

        $html = $this->actingAs($moderator)->get(route('admin.reports'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'Zgłaszająca do panelu',
            $html,
            'Kolejka nie pokazuje, kto zgłosił — a to właśnie relacja `reporter.profile`, '
            .'czyli ta, która w razie usterki dawałaby zapytanie na wiersz.',
        );

        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($moderator)->get(route('admin.reports'))->assertOk(),
        );

        $this->assertSame($malo, $duzo, $this->komunikat('/admin/zgloszenia', $malo, $duzo));
    }

    /**
     * Kolejka oznaczeń automatu — ekran, przy którym stało zdanie o teście,
     * którego nie było.
     *
     * Dane są tu celowo cięższe niż na innych kolejkach: każde oznaczenie
     * dotyczy wpisu ZE ZDJĘCIEM i należy do INNEGO konta. Bez zdjęcia
     * `podglady()` nie sięga po miniaturę, a przy jednym autorze wszystkie
     * wiersze wpadłyby do jednej grupy — i strona pokazywałaby jedną kartę
     * niezależnie od tego, ile jest oznaczeń.
     */
    public function test_kolejka_sygnalow_automatu_nie_ma_wachlarza_zapytan(): void
    {
        $moderator = $this->moderator();

        $this->oznaczeniaAutomatu(self::MALO);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk(),
        );

        $this->oznaczeniaAutomatu(self::DUZO - self::MALO, od: self::MALO);

        $html = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk()->getContent();

        // Kontrola dodatnia celuje w PODGLĄD, nie w samą obecność wiersza:
        // to podglądy są tym, co przy usterce kosztowałoby zapytanie na
        // pozycję (`SygnalyController::podglady()`).
        $this->assertStringContainsString(
            'Zupa pomidorowa numer '.(self::DUZO - 1),
            $html,
            'Kolejka nie pokazuje treści oznaczonego wpisu — pomiar nie dotyczy podglądów.',
        );

        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk(),
        );

        $this->assertSame($malo, $duzo, $this->komunikat('/admin/sygnaly', $malo, $duzo));
    }

    public function test_kolejka_odwolan_nie_ma_wachlarza_zapytan(): void
    {
        $admin = $this->admin();

        $this->odwolania($admin, self::MALO);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($admin)->get(route('admin.appeals'))->assertOk(),
        );

        $this->odwolania($admin, self::DUZO - self::MALO, od: self::MALO);

        $html = $this->actingAs($admin)->get(route('admin.appeals'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'To pomyłka, numer '.(self::DUZO - 1),
            $html,
            'Kolejka nie pokazuje treści odwołań — pomiar nie dotyczy wierszy, tylko obudowy strony.',
        );

        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($admin)->get(route('admin.appeals'))->assertOk(),
        );

        $this->assertSame($malo, $duzo, $this->komunikat('/admin/odwolania', $malo, $duzo));
    }

    public function test_lista_wpisow_bez_odpowiedzi_nie_ma_wachlarza_zapytan(): void
    {
        $moderator = $this->moderator();

        $this->wpisyBezOdpowiedzi(self::MALO);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($moderator)->get(route('admin.unanswered'))->assertOk(),
        );

        $this->wpisyBezOdpowiedzi(self::DUZO - self::MALO, od: self::MALO);

        $html = $this->actingAs($moderator)->get(route('admin.unanswered'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'Pierwszy obiad numer '.(self::DUZO - 1),
            $html,
            'Lista nie pokazuje wpisów czekających na odpowiedź — pomiar nie dotyczy wierszy.',
        );

        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($moderator)->get(route('admin.unanswered'))->assertOk(),
        );

        $this->assertSame($malo, $duzo, $this->komunikat('/admin/bez-odpowiedzi', $malo, $duzo));
    }

    // -----------------------------------------------------------------
    // Dane
    // -----------------------------------------------------------------

    /** Zgłoszenia od człowieka — każde na treść INNEJ osoby, tak jak w prawdziwej kolejce. */
    private function zgloszeniaOdCzlowieka(User $zglaszajacy, int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $autor = $this->user();
            $wpis = Post::factory()->for($autor, 'author')->create();

            Report::create([
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'reporter_id' => $zglaszajacy->getKey(),
                'source' => Report::SOURCE_COMMUNITY,
                'status' => Report::STATUS_OPEN,
                'reason' => 'spam',
            ]);
        }
    }

    /** Oznaczenia automatu — każde na wpisie innego konta, ze zdjęciem. */
    private function oznaczeniaAutomatu(int $ile, int $od = 0): void
    {
        for ($i = $od; $i < $od + $ile; $i++) {
            $autor = $this->user();
            $wpis = Post::factory()->for($autor, 'author')->create([
                'body' => 'Zupa pomidorowa numer '.$i.', tak jak robiła moja mama.',
            ]);
            $wpis->media()->attach(
                Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
                ['position' => 0],
            );

            Report::create([
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'autor_tresci_id' => $autor->getKey(),
                'source' => Report::SOURCE_AUTOMAT,
                'status' => Report::STATUS_OPEN,
                'reason' => OcenaModelem::KOD,
            ]);
        }
    }

    /** Otwarte odwołania od decyzji — każde od innej osoby i od innej decyzji. */
    private function odwolania(User $moderator, int $ile, int $od = 0): void
    {
        for ($i = $od; $i < $od + $ile; $i++) {
            $autor = $this->user();
            $wpis = Post::factory()->for($autor, 'author')->create();

            $zgloszenie = Report::create([
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'reporter_id' => $this->user()->getKey(),
                'source' => Report::SOURCE_COMMUNITY,
                'status' => Report::STATUS_RESOLVED,
                'resolved_at' => now(),
                'reason' => 'spam',
            ]);

            $decyzja = ModerationAction::create([
                'moderator_id' => $moderator->getKey(),
                'report_id' => $zgloszenie->getKey(),
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam',
            ]);

            Appeal::create([
                'moderation_action_id' => $decyzja->getKey(),
                'user_id' => $autor->getKey(),
                'appellant' => Appeal::APPELLANT_AUTHOR,
                'body' => 'To pomyłka, numer '.$i.'. Proszę o ponowne przeczytanie tego wpisu.',
                'status' => Appeal::STATUS_OPEN,
            ]);
        }
    }

    /** Wpisy bez ani jednego komentarza — każdy od innej osoby, ze zdjęciem i tagiem. */
    private function wpisyBezOdpowiedzi(int $ile, int $od = 0): void
    {
        for ($i = $od; $i < $od + $ile; $i++) {
            $autor = $this->user();
            $wpis = Post::factory()->for($autor, 'author')->create([
                'body' => 'Pierwszy obiad numer '.$i.' — ugotowany dziś po południu.',
                'published_at' => now()->subHours($i + 1),
            ]);
            $wpis->media()->attach(
                Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
                ['position' => 0],
            );
            $wpis->tags()->attach(Tag::factory()->create()->getKey(), ['position' => 0]);
        }
    }

    private function komunikat(string $ekran, int $malo, int $duzo): string
    {
        return "Liczba zapytań rośnie z liczbą wierszy (N+1) na {$ekran}: {$malo} przy "
            .self::MALO.' wierszach, '."{$duzo} przy ".self::DUZO.'.';
    }
}
