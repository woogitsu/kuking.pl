<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Recipes\WpisWskazujacyPrzepis;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\CommentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TEST KONTRAKTOWY: filtr powiadomień o komentarzach odpowiada tak samo jak
 * Policy (issue #1687).
 *
 * `WidocznoscPowiadomien` + `WidocznoscTresciSql` liczą widoczność treści
 * jednym zapytaniem dla całej strony. `CommentPolicy::view()` (a przez nią
 * `PostPolicy`, `RecipePolicy`, `CookedEventPolicy`) liczy ją po jednym
 * rekordzie. To są dwie implementacje tej samej reguły i do tej zmiany ich
 * zgodność deklarował wyłącznie komentarz w modelu. Ten test porównuje obie
 * odpowiedzi na macierzy:
 *
 *   cel:        wpis, przepis, „Ugotowałem", zapowiedź przepisu (#1747 —
 *               wpis bez własnej treści, którego bramką jest przepis)
 *   widz:       właściciel treści, obserwujący autora, obcy
 *   stan:       publiczne, dla obserwujących, prywatne, blokada w obie
 *               strony, autor treści zbanowany, treść ukryta, szkic,
 *               treść usunięta, komentujący zbanowany, korzeń wątku ukryty,
 *               kucharz „Ugotowałem" w karencji usunięcia konta (#1746)
 *   komentarz:  główny i odpowiedź
 *
 * Każdy stan jest nakładany PO utworzeniu powiadomienia — dokładnie tak, jak
 * dzieje się to naprawdę: powiadomienie powstaje, potem świat się zmienia.
 *
 * DO CZEGO TO SŁUŻY. Nowa reguła widoczności dopisana do Policy treści (albo
 * zmiana istniejącej) bez obsługi w `WidocznoscTresciSql` daje tu czerwony
 * wynik z nazwą komórki macierzy, zamiast cichego rozjazdu listy i ekranu.
 * Nowy stan treści dopisuje się jako kolejną pozycję `STANY`.
 *
 * ZNANE ROZJAZDY — TOLEROWANE TYLKO W JEDNYM KIERUNKU I TYLKO TE DWA:
 *
 *   #1385  „Ugotowałem" oglądane przez kucharza: Policy wpuszcza go do
 *          własnego wykonania niezależnie od stanu przepisu, SQL tylko przez
 *          widoczny przepis. Tolerowane: Policy = tak, SQL = nie.
 *   #1378  odpowiedź pod korzeniem ukrytym przez moderację: Policy odmawia,
 *          SQL przepuszcza. Tolerowane: Policy = nie, SQL = tak.
 *
 * Naprawa każdego z tych zgłoszeń ma usunąć odpowiednią gałąź z
 * `znanyRozjazd()` — wtedy macierz pilnuje zgodności także w tych komórkach.
 *
 * ŚWIADOMIE POZA MACIERZĄ: moderator jako odbiorca (filtr celowo nie ma jego
 * furtki — jest ostrzejszy) oraz komentarz z usuniętą treścią
 * (`body_removed_at`, placeholder zostaje w wątku, a powiadomienie znika
 * celowo, bo nie ma już czego cytować).
 */
class PowiadomieniaZgodneZPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const CELE = ['wpis', 'przepis', 'wykonanie', 'zapowiedz'];

    private const WIDZOWIE = ['wlasciciel', 'obserwujacy', 'obcy'];

    private const STANY = [
        'publiczne',
        'dla_obserwujacych',
        'prywatne',
        'blokada_przez_widza',
        'blokada_przez_autora',
        'autor_zbanowany',
        'ukryte',
        'szkic',
        'usuniete',
        'komentujacy_zbanowany',
        'korzen_ukryty',
        'kucharz_do_usuniecia',
    ];

    private const KOMENTARZE = ['glowny', 'odpowiedz'];

    public function test_filtr_powiadomien_odpowiada_jak_policy_na_calej_macierzy(): void
    {
        $rozjazdy = [];
        $zgodneTak = 0;
        $zgodneNie = 0;

        foreach (self::CELE as $cel) {
            foreach (self::WIDZOWIE as $rola) {
                foreach (self::STANY as $stan) {
                    foreach (self::KOMENTARZE as $rodzaj) {
                        $wynik = $this->przypadek($cel, $rola, $stan, $rodzaj);

                        if ($wynik === null) {
                            continue;
                        }

                        [$policy, $sql] = $wynik;

                        if ($policy === $sql) {
                            $policy ? $zgodneTak++ : $zgodneNie++;

                            continue;
                        }

                        if ($this->znanyRozjazd($cel, $rola, $stan, $rodzaj, $policy, $sql)) {
                            continue;
                        }

                        $rozjazdy[] = sprintf(
                            '%s / widz: %s / stan: %s / komentarz: %s — Policy: %s, filtr powiadomień: %s',
                            $cel,
                            $rola,
                            $stan,
                            $rodzaj,
                            $policy ? 'widzi' : 'nie widzi',
                            $sql ? 'widzi' : 'nie widzi',
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $rozjazdy,
            "Filtr powiadomień (`WidocznoscTresciSql`) i Policy odpowiadają inaczej:\n".implode("\n", $rozjazdy),
        );

        // KONTROLA: macierz, w której wszystko wyszło „nie widzi" (albo
        // wszystko „widzi"), przeszłaby asercję wyżej z zupełnie innego
        // powodu — np. przez zepsuty `data.comment_id`.
        $this->assertGreaterThan(20, $zgodneTak, 'Za mało komórek, w których obie strony widzą — macierz jest podejrzana.');
        $this->assertGreaterThan(20, $zgodneNie, 'Za mało komórek, w których obie strony odmawiają — macierz jest podejrzana.');
    }

    /**
     * Buduje jeden przypadek i zwraca [odpowiedź Policy, odpowiedź filtra],
     * albo `null`, gdy kombinacja nie ma sensu (blokada samego siebie).
     *
     * @return array{0: bool, 1: bool}|null
     */
    private function przypadek(string $cel, string $rola, string $stan, string $rodzaj): ?array
    {
        $autor = $this->user();
        $komentujacy = $this->user();
        $rozmowca = $this->user();
        $kucharz = null;

        if ($cel === 'wykonanie') {
            // Właścicielem „Ugotowałem" jest KUCHARZ, nie autor przepisu —
            // i to jego dotyczy furtka „własne wykonanie widać zawsze".
            $kucharz = $this->user();
            $widz = $rola === 'wlasciciel' ? $kucharz : $this->user();
        } else {
            $widz = $rola === 'wlasciciel' ? $autor : $this->user();
        }

        $blokadaSamegoSiebie = in_array($stan, ['blokada_przez_widza', 'blokada_przez_autora'], true)
            && $widz->getKey() === $autor->getKey();

        if ($blokadaSamegoSiebie) {
            return null;
        }

        // Stan kucharza ma sens tylko tam, gdzie jest kucharz.
        if ($stan === 'kucharz_do_usuniecia' && $kucharz === null) {
            return null;
        }

        if ($rola === 'obserwujacy') {
            app(FollowUser::class)->handle($widz, $autor);
        }

        [$tabela, $idTresci, $kolumna, $idPodmiotu] = $this->tresc($cel, $autor, $kucharz);

        $korzen = Comment::create([
            'author_id' => ($rodzaj === 'glowny' ? $komentujacy : $rozmowca)->getKey(),
            $kolumna => $idPodmiotu,
            'body' => 'Korzeń wątku.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $komentarz = $rodzaj === 'glowny'
            ? $korzen
            : Comment::create([
                'author_id' => $komentujacy->getKey(),
                $kolumna => $idPodmiotu,
                'parent_id' => $korzen->getKey(),
                'body' => 'Odpowiedź w wątku.',
                'status' => Comment::STATUS_PUBLISHED,
            ]);

        $powiadomienie = Notification::create([
            'user_id' => $widz->getKey(),
            'actor_id' => $komentujacy->getKey(),
            'type' => $rodzaj === 'glowny' ? Notification::TYPE_COMMENT : Notification::TYPE_REPLY,
            'data' => ['comment_id' => (string) $komentarz->getKey()],
        ]);

        // Dopiero TERAZ zmienia się świat — powiadomienie już istnieje.
        match ($stan) {
            'publiczne' => null,
            'dla_obserwujacych' => DB::table($tabela)->where('id', $idTresci)->update(['visibility' => 'followers']),
            'prywatne' => DB::table($tabela)->where('id', $idTresci)->update(['visibility' => 'private']),
            'blokada_przez_widza' => app(BlockUser::class)->handle($widz, $autor),
            'blokada_przez_autora' => app(BlockUser::class)->handle($autor, $widz),
            'autor_zbanowany' => $autor->ban(),
            'ukryte' => DB::table($tabela)->where('id', $idTresci)->update(['status' => 'hidden']),
            'szkic' => DB::table($tabela)->where('id', $idTresci)->update(['status' => 'draft', 'published_at' => null]),
            'usuniete' => DB::table($tabela)->where('id', $idTresci)->update(['deleted_at' => now()]),
            'komentujacy_zbanowany' => $komentujacy->ban(),
            'korzen_ukryty' => DB::table('comments')->where('id', $korzen->getKey())->update(['status' => Comment::STATUS_HIDDEN]),
            'kucharz_do_usuniecia' => $kucharz?->markForDeletion(),
        };

        $widzTeraz = User::query()->findOrFail($widz->getKey());

        // Świeży odczyt bez zapamiętanych relacji — Policy ma widzieć stan
        // bazy PO zmianie, tak jak przy prawdziwym wejściu na adres.
        $policy = app(CommentPolicy::class)->view(
            $widzTeraz,
            Comment::withTrashed()->findOrFail($komentarz->getKey()),
        );

        $sql = Notification::query()
            ->visibleTo($widzTeraz)
            ->whereKey($powiadomienie->getKey())
            ->exists();

        return [$policy, $sql];
    }

    /**
     * Tworzy publiczną, opublikowaną treść autora.
     *
     * Zwraca: tabelę i id treści, na którą nakładamy stan, oraz kolumnę
     * i id podmiotu, pod którym stoi komentarz.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function tresc(string $cel, User $autor, ?User $kucharz): array
    {
        if ($cel === 'wpis') {
            $wpis = Post::factory()->for($autor, 'author')->create([
                'visibility' => Post::VISIBILITY_PUBLIC,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => now()->subHour(),
            ]);

            return ['posts', (string) $wpis->getKey(), 'post_id', (string) $wpis->getKey()];
        }

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
        ]);

        if ($cel === 'przepis') {
            return ['recipes', (string) $przepis->getKey(), 'recipe_id', (string) $przepis->getKey()];
        }

        if ($cel === 'zapowiedz') {
            // Zapowiedź powstaje drogą produkcyjną, nie fabryką — to
            // `WpisWskazujacyPrzepis` decyduje, jak wygląda taki wpis.
            // Stan nakładamy na PRZEPIS: to on jest bramką zapowiedzi.
            $zapowiedz = WpisWskazujacyPrzepis::dopisz($przepis);
            $this->assertNotNull($zapowiedz, 'Zapowiedź nie powstała — ta komórka macierzy nie miałaby czego mierzyć.');
            $this->assertTrue($zapowiedz->czyJestZapowiedziaPrzepisu(), 'Wpis nie jest zapowiedzią przepisu.');

            return ['recipes', (string) $przepis->getKey(), 'post_id', (string) $zapowiedz->getKey()];
        }

        // „Ugotowałem" nie ma własnej widoczności — stan nakładamy na PRZEPIS.
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz?->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        return ['recipes', (string) $przepis->getKey(), 'cooked_event_id', (string) $wykonanie->getKey()];
    }

    /**
     * Jedyne tolerowane rozjazdy — patrz docblock klasy. Każdy ma numer
     * zgłoszenia i JEDEN dozwolony kierunek.
     */
    private function znanyRozjazd(string $cel, string $rola, string $stan, string $rodzaj, bool $policy, bool $sql): bool
    {
        // #1385: kucharz a własne wykonanie pod przepisem, którego sam nie widzi.
        if ($cel === 'wykonanie' && $rola === 'wlasciciel' && $policy && ! $sql) {
            return true;
        }

        // #1378: odpowiedź pod korzeniem ukrytym przez moderację.
        if ($stan === 'korzen_ukryty' && $rodzaj === 'odpowiedz' && ! $policy && $sql) {
            return true;
        }

        return false;
    }
}
