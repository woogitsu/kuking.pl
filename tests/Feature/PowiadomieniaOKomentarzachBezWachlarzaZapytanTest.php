<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/powiadomienia` — wachlarz zapytań (N+1) na powiadomieniach o KOMENTARZACH.
 *
 * CO JEST ZEPSUTE
 * `Notification::urlDoKomentarza()` (issue #759) liczy adres konkretnego
 * komentarza przy KAŻDYM wyświetleniu wiersza, bo numer strony wątku zależy
 * od widoczności dla odbiorcy w chwili kliknięcia. Liczy to jednak w całości
 * per wiersz: odbiorca (`$this->user`), sam komentarz, jego treść nadrzędna
 * (`subject()`), korzeń wątku przy odpowiedzi oraz DWA zapytania na widoczność
 * i pozycję korzenia. Strona mieści trzydzieści powiadomień.
 *
 * METODA (ta sama co w `PowiadomieniaBezWachlarzaZapytanTest` i
 * `MiniaturyBezWachlarzaZapytanTest`): nie „ile zapytań wypada", tylko
 * „czy liczba ROŚNIE z liczbą wierszy". Próg na sztywno zestarzałby się
 * przy pierwszej uzasadnionej zmianie tego ekranu.
 */
class PowiadomieniaOKomentarzachBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    private const PELNA_STRONA = 20;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    public function test_lista_powiadomien_nie_ma_wachlarza_zapytan_na_adresy_komentarzy(): void
    {
        // Jedna strona wątku = jeden komentarz, więc KAŻDY komentarz docelowy
        // leży poza pierwszą stroną i przechodzi przez liczenie pozycji —
        // najdroższą gałąź `urlDoKomentarza()`.
        config(['kuking.comments.page_size' => 1]);

        $adresat = $this->user('adresatka_komentarzy');

        $this->powiadomieniaOKomentarzach($adresat, 2);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($adresat)->get(route('notifications.index'))->assertOk(),
        );

        $this->powiadomieniaOKomentarzach($adresat, self::PELNA_STRONA - 2);

        $html = $this->actingAs($adresat)->get(route('notifications.index'))->assertOk()->getContent();

        // KONTROLA DODATNIA (docs/PULAPKI_TESTOW.md §4): stała liczba zapytań
        // przechodziłaby także wtedy, gdyby adresy przestały się w ogóle
        // liczyć. Docelowy adres NIE trafia do HTML-a — „Zobacz" jest
        // formularzem POST (issue #276) — więc dowodzimy go dwustronnie:
        // (a) każdy wiersz ma przycisk, czyli `adresDocelowy()` zwrócił adres,
        // (b) ten adres naprawdę wskazuje stronę wątku i sam komentarz.
        $this->assertSame(
            self::PELNA_STRONA,
            substr_count($html, '/zobacz"'),
            'Nie każdy wiersz ma „Zobacz" — pomiar niżej nie mierzy tego, o czym mówi.',
        );

        $this->assertSame(
            self::PELNA_STRONA,
            $adresat->notifications()->count(),
            'asercja kontrolna: powiadomień musi naprawdę być tyle, ile mierzymy',
        );

        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($adresat)->get(route('notifications.index'))->assertOk(),
        );

        // Druga połowa kontroli dodatniej: adres, którego lista nie pokazuje,
        // sprawdzamy tam, gdzie jest widoczny — w przekierowaniu „Zobacz".
        // Numer strony i kotwica dowodzą, że policzona została pozycja
        // korzenia, a nie tylko zwrócony gołe `data.url`.
        $ostatnie = $adresat->notifications()->latest()->firstOrFail();

        $this->actingAs($adresat)
            ->post(route('notifications.open', $ostatnie))
            ->assertRedirectContains('komentarze=2#komentarz-');

        $this->assertSame(
            $malo,
            $duzo,
            "Liczba zapytań rośnie z liczbą powiadomień o komentarzach (N+1): {$malo} przy 2, "
            ."{$duzo} przy ".self::PELNA_STRONA.'.',
        );
    }

    /**
     * `$ile` powiadomień o komentarzu, KAŻDE pod innym wpisem.
     *
     * Osobny wpis dla każdego powiadomienia jest tu warunkiem pomiaru:
     * dwadzieścia komentarzy pod jednym wpisem dałoby jedno dociągnięcie
     * treści nadrzędnej na całą stronę i test nie wykryłby usterki, której
     * pilnuje.
     */
    private function powiadomieniaOKomentarzach(User $adresat, int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $komentator = $this->user();
            $wpis = Post::factory()->for($adresat, 'author')->create();

            // Wcześniejszy korzeń wątku — spycha komentarz docelowy na drugą
            // stronę przy `page_size = 1`.
            Comment::factory()->for($wpis, 'post')->for($komentator, 'author')->create();

            $komentarz = Comment::factory()->for($wpis, 'post')->for($komentator, 'author')->create();

            $adresat->notifications()->create([
                'type' => Notification::TYPE_COMMENT,
                'actor_id' => $komentator->getKey(),
                'data' => [
                    'comment_id' => (string) $komentarz->getKey(),
                    'url' => $wpis->url(),
                ],
            ]);
        }
    }
}
