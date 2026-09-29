<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\OdczytPowiadomien;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Domknięcie kryteriów #1687, które etapy 1–7 zostawiły bez dowodu:
 *
 *  - „Liczba zapytań dla strony 30 powiadomień pozostaje ograniczona
 *    i niezależna od liczby wierszy" — mierzona przez HTTP, na PEŁNEJ stronie
 *    (`OdczytPowiadomien::NA_STRONE`) i z powiadomieniami o komentarzach
 *    (najdroższa gałąź: widoczność wątku, wycinek, adres celu);
 *  - „Lista, licznik, eksport i pojedyncze otwarcie używają jednego kontraktu
 *    widoczności" — eksport RODO niesie dokładnie te powiadomienia, które
 *    człowiek widzi na liście.
 *
 * Metoda pomiaru jak w `PowiadomieniaBezWachlarzaZapytanTest`: nie próg na
 * sztywno, tylko „czy liczba ROŚNIE z liczbą wierszy".
 */
class PowiadomieniaJedenKontraktWidocznosciTest extends TestCase
{
    use RefreshDatabase;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    /** Publiczny wpis autora — cel komentarzy. */
    private function wpis(User $autor): Post
    {
        return Post::factory()->for($autor, 'author')->create([
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
        ]);
    }

    /** Powiadomienie o komentarzu (albo odpowiedzi) pod wpisem. */
    private function oKomentarzu(User $odbiorca, User $komentujacy, Post $wpis, bool $odpowiedz): Notification
    {
        $korzen = Comment::create([
            'author_id' => $komentujacy->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Korzeń wątku.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $komentarz = $odpowiedz
            ? Comment::create([
                'author_id' => $komentujacy->getKey(),
                'post_id' => $wpis->getKey(),
                'parent_id' => $korzen->getKey(),
                'body' => 'Odpowiedź w wątku.',
                'status' => Comment::STATUS_PUBLISHED,
            ])
            : $korzen;

        return Notification::create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => $komentujacy->getKey(),
            'type' => $odpowiedz ? Notification::TYPE_REPLY : Notification::TYPE_COMMENT,
            'data' => ['comment_id' => (string) $komentarz->getKey()],
        ]);
    }

    /** Po $ile wierszy: komentarz, odpowiedź i obserwowanie. */
    private function wiersze(User $odbiorca, Post $wpis, int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $nadawca = $this->user();
            $this->oKomentarzu($odbiorca, $nadawca, $wpis, false);
            $this->oKomentarzu($odbiorca, $nadawca, $wpis, true);
            Notification::create([
                'user_id' => $odbiorca->getKey(),
                'actor_id' => $nadawca->getKey(),
                'type' => Notification::TYPE_FOLLOW,
                'data' => ['username' => 'x'],
            ]);
        }
    }

    public function test_pelna_strona_30_powiadomien_ma_te_same_zapytania_co_strona_z_trzema(): void
    {
        $adresat = $this->user('adresat30');
        $wpis = $this->wpis($adresat);
        $lista = fn () => $this->actingAs($adresat)->get(route('notifications.index'))->assertOk();

        $this->wiersze($adresat, $wpis, 1);
        $lista(); // rozgrzewka: sesja, uprawnienia, pierwsze wczytanie konfiguracji
        $malo = $this->policzZapytania($lista);

        $this->wiersze($adresat, $wpis, (int) (OdczytPowiadomien::NA_STRONE / 3) - 1);
        $duzo = $this->policzZapytania($lista);

        // KONTROLA DODATNIA: mierzymy naprawdę pełną stronę, a komentarze
        // faktycznie się renderują (bez tego stała liczba zapytań
        // przechodziłaby także przy pustym ekranie).
        $this->assertSame(OdczytPowiadomien::NA_STRONE, $adresat->notifications()->count());
        $html = $lista()->getContent();
        $this->assertStringContainsString('Korzeń wątku.', $html);
        $this->assertStringContainsString('Odpowiedź w wątku.', $html);

        $this->assertSame(
            $malo,
            $duzo,
            "Zapytania listy rosną z liczbą powiadomień: {$malo} przy 3, {$duzo} przy ".OdczytPowiadomien::NA_STRONE.'.',
        );
    }

    public function test_eksport_niesie_dokladnie_te_powiadomienia_co_lista(): void
    {
        $adresat = $this->user('adresateksport');
        $wpis = $this->wpis($adresat);
        // Wpis INNEGO autora niż adresat, później ukryty. Gdyby należał do adresata,
        // ten widziałby go mimo ukrycia (właściciel własnej treści), więc
        // powiadomienie o komentarzu pod nim nie zniknęłoby z listy i eksportu.
        $ukrytyWpis = $this->wpis($this->user());

        $zwykly = $this->user(null, ['display_name' => 'Zwykla Osoba']);
        $zablokowany = $this->user(null, ['display_name' => 'Zablokowana Osoba']);
        $zbanowany = $this->user(null, ['display_name' => 'Zbanowana Osoba']);
        $podUkrytym = $this->user(null, ['display_name' => 'Pod Ukrytym Wpisem']);

        $this->oKomentarzu($adresat, $zwykly, $wpis, false);
        $this->oKomentarzu($adresat, $zwykly, $wpis, true);
        $this->oKomentarzu($adresat, $zablokowany, $wpis, false);
        $this->oKomentarzu($adresat, $zbanowany, $wpis, true);
        $this->oKomentarzu($adresat, $podUkrytym, $ukrytyWpis, false);

        // Świat zmienia się PO utworzeniu powiadomień.
        app(BlockUser::class)->handle($adresat, $zablokowany);
        $zbanowany->ban();
        DB::table('posts')->where('id', $ukrytyWpis->getKey())->update(['status' => 'hidden']);

        $naLiscie = collect(app(OdczytPowiadomien::class)->strona($adresat)->items())
            ->map(fn (Notification $n): ?string => $n->actor?->displayName())
            ->sort()->values()->all();

        // KONTROLA DODATNIA: zostają dwa widoczne wiersze, trzy odpadły.
        $this->assertSame(['Zwykla Osoba', 'Zwykla Osoba'], $naLiscie);

        $dane = (new CollectUserExportData)->handle(
            $adresat->fresh(),
            new ExportPhotoPlan($adresat->fresh()),
            Carbon::now(),
        );

        $wEksporcie = collect($dane['powiadomienia'])->pluck('od_kogo')->sort()->values()->all();

        $this->assertSame($naLiscie, $wEksporcie, 'Eksport niesie inne powiadomienia niż lista.');
    }
}
