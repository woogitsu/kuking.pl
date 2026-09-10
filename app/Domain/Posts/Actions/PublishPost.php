<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\PrzeanalizujTresc;
use App\Models\AuditLogEntry;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Publikacja wpisu: zdjęcie + kilka słów.
 *
 * To najważniejsza operacja w produkcie i musi się udawać w mniej niż minutę.
 * Dlatego: brak wymaganego tekstu przy zdjęciu, brak wymaganej kategorii,
 * brak wymaganego opisu alternatywnego.
 *
 * Jedyny twardy warunek: wpis musi mieć CO NAJMNIEJ zdjęcie ALBO tekst.
 * Puste wpisy nie wnoszą nic i tylko zaśmiecają archiwum.
 *
 * JEDNO WYSŁANIE FORMULARZA TO JEDEN WPIS (ADR
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`, wariant A3).
 *
 * Zmierzone przed zmianą: dwa kliknięcia „Opublikuj" dawały dwa wpisy i dwa
 * różne adresy w `Location`, czyli serwis odsyłał człowieka do DRUGIEGO
 * wpisu, o którego istnieniu ten człowiek nie wiedział. W grupie 50+ drugie
 * kliknięcie nie jest pomyłką, tylko sposobem obsługi komputera: strona myśli
 * chwilę, więc klika się drugi raz.
 *
 * Formularz dostaje przy renderowaniu jednorazowy `klucz_wyslania` w ukrytym
 * polu, a tabela ma na parze (autor, klucz) częściowy indeks UNIQUE. Zapis
 * idzie „WSTAW I ZŁAP WYJĄTEK", nie „sprawdź, potem wstaw" — check-then-act
 * przepuszcza dwa równoległe żądania, bo między odczytem a zapisem jest okno
 * (zmierzone w ADR §1.3 na dwóch połączeniach).
 *
 * Mechanizm zawodzi OTWARCIE: brak albo nieznany klucz znaczy „opublikuj
 * normalnie", nigdy „odmawiam". Zduplikowany wpis jest dla odbiorcy 50+ mniej
 * szkodliwy niż wpis utracony (ADR §4.3).
 */
final class PublishPost
{
    public function __construct(
        private readonly NotifyUser $notify,
        private readonly ResolveTagsForPost $resolveTags,
    ) {}

    /**
     * @param  list<string>  $mediaIds  identyfikatory już wgranych zdjęć, w kolejności
     * @param  list<string>  $tagNames  to, co ktoś WPISAŁ jako tagi (wolny tekst, nie id) — D-021
     * @param  string|null  $kluczWyslania  tożsamość TEGO wysłania formularza; `null` znaczy
     *                                      „nie wiemy, wysyłaj normalnie" (ADR §4.3)
     */
    public function handle(
        User $author,
        ?string $body,
        array $mediaIds = [],
        string $visibility = Post::VISIBILITY_PUBLIC,
        ?string $recipeId = null,
        array $tagNames = [],
        ?string $ip = null,
        string $displayMode = Post::DISPLAY_NORMAL,
        ?string $kluczWyslania = null,
    ): Post {
        $body = $this->cleanBody($body);

        if ($body === null && $mediaIds === []) {
            throw new BladDlaCzlowieka('Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego opublikować.');
        }

        // Bierzemy tylko zdjęcia należące do tej osoby. Bez tego ktoś mógłby
        // podstawić cudze media_id w formularzu (IDOR).
        $ownedMedia = Media::query()
            ->where('owner_id', $author->getKey())
            ->whereIn('id', $mediaIds)
            ->pluck('id')
            ->all();

        // Zachowujemy kolejność wybraną przez użytkownika.
        $orderedMedia = array_values(array_filter(
            $mediaIds,
            static fn (string $id): bool => in_array($id, $ownedMedia, true),
        ));

        $orderedMedia = array_slice($orderedMedia, 0, (int) config('kuking.media.max_per_post'));

        // Tagi (D-021, zastępują usunięty już Temat/`topic_id` z issue #31)
        // — rozwiązywane PRZED transakcją tworzącą wpis, żeby
        // `BladDlaCzlowieka` za zbyt wiele tagów przerwało publikację, zanim
        // cokolwiek trafi do bazy (dokładnie tak samo jak sprawdzenie
        // pustego wpisu wyżej).
        $tags = $this->resolveTags->handle($tagNames);

        // Sposób wyświetlania zdjęć (issue #92). Przy jednym zdjęciu wybór nie
        // znaczy nic — karuzela z jednym slajdem i kolaż z jednym polem to ten
        // sam widok co „zwykle" — więc zapisujemy `normal` zamiast trzymać
        // w bazie deklarację, której nie da się zobaczyć. Wartość spoza listy
        // też schodzi do `normal`: baza odrzuciłaby ją CHECK-iem, a wpis, który
        // nie zostaje opublikowany z powodu wyboru układu, to zła zamiana.
        $displayMode = count($orderedMedia) < 2 || ! in_array($displayMode, Post::dozwoloneTrybyWyswietlania(), true)
            ? Post::DISPLAY_NORMAL
            : $displayMode;

        $zapisz = function (?string $klucz) use ($author, $body, $visibility, $recipeId, $orderedMedia, $displayMode, $tags): Post {
            return DB::transaction(function () use ($author, $body, $visibility, $recipeId, $orderedMedia, $displayMode, $tags, $klucz): Post {
                $post = Post::create([
                    'author_id' => $author->getKey(),
                    'body' => $body,
                    'visibility' => $visibility,
                    'status' => Post::STATUS_PUBLISHED,
                    'display_mode' => $displayMode,
                    'recipe_id' => $recipeId,
                    'klucz_wyslania' => $klucz,
                    'published_at' => now(),
                ]);

                foreach ($orderedMedia as $position => $mediaId) {
                    $post->media()->attach($mediaId, ['position' => $position]);
                }

                foreach ($tags as $position => $tag) {
                    $post->tags()->attach($tag->getKey(), ['position' => $position]);
                }

                return $post;
            });
        };

        try {
            $post = $zapisz($kluczWyslania);
        } catch (UniqueConstraintViolationException $e) {
            if ($kluczWyslania === null) {
                // Bez klucza nie ma jak odbić się o `posts_one_per_klucz_wyslania`
                // — więc to jest inne ograniczenie (np. dwa razy to samo
                // zdjęcie na tej samej pozycji) i nie wolno go tu wyciszyć.
                throw $e;
            }

            // Indeks `posts_one_per_klucz_wyslania` odbił wiersz: to wysłanie
            // już raz zapisało wpis. Cała transakcja jest wycofana, więc nie
            // ma tu połowy zmian — zdjęcia i tagi drugiego żądania nie
            // zostały podpięte do niczego (osierocone zdjęcia sprząta
            // `kuking:sprzataj-osierocone-zdjecia` po dobie karencji).
            $istniejacy = $this->wpisZTegoWyslania($author, $kluczWyslania);

            if ($istniejacy !== null) {
                // Drugie kliknięcie ma być NIEODRÓŻNIALNE od pierwszego:
                // oddajemy wpis, który wtedy powstał, i nie powtarzamy ani
                // audytu, ani powiadomienia gospodarza.
                return $istniejacy;
            }

            // Klucz jest zajęty, ale wpisu, którego dotyczył, już nie widać
            // (usunięty miękko — indeks obejmuje też takie wiersze). Nie
            // odmawiamy: publikujemy bez klucza, z ryzykiem duplikatu.
            // Utrata cudzego wpisu jest gorsza niż duplikat (ADR §4.3).
            $post = $zapisz(null);
        }

        AuditLogEntry::record(
            action: 'post.published',
            actor: $author,
            subject: $post,
            metadata: ['media_count' => count($orderedMedia), 'visibility' => $visibility, 'display_mode' => $displayMode, 'tag_count' => count($tags)],
            ip: $ip,
        );

        $this->powiadomGospodarzaOPierwszymWpisie($author, $post);

        /*
         * ANALIZA POD KĄTEM SYGNAŁÓW SPAMU (D-052) — W KOLEJCE, NIE TUTAJ.
         *
         * Wysłanie zadania to jeden `INSERT` do `jobs`; sama analiza (dwa
         * zapytania i porównanie tekstów) dzieje się później, na kolejce
         * `low`, za wszystkim, co robi człowiek. Publikacja wpisu nie czeka
         * na nią ani milisekundy i NIE ZALEŻY od jej wyniku — treść jest już
         * opublikowana i widoczna, a jedyne, co może się zdarzyć, to jedna
         * pozycja w kolejce moderatora.
         *
         * Stoi PO wyjściach idempotencji wyżej (`return $istniejacy`), więc
         * drugie kliknięcie „Opublikuj" nie zleca analizy drugi raz.
         */
        PrzeanalizujTresc::dlaWpisu($post);

        return $post;
    }

    /**
     * Wpis, który powstał z TEGO wysłania formularza — jeśli powstał.
     *
     * Pytanie jest zawężone do autora, a nie zadane samemu kluczowi:
     * `klucz_wyslania` przychodzi z żądania, a UUID w żądaniu nie jest
     * autoryzacją (`AGENTS.md` §7). Klucz podstawiony z cudzego formularza
     * nie może więc pokazać cudzego wpisu — indeks jest na parze
     * (autor, klucz), więc nawet nie zablokuje własnego wysłania.
     */
    private function wpisZTegoWyslania(User $author, ?string $kluczWyslania): ?Post
    {
        if ($kluczWyslania === null) {
            return null;
        }

        return Post::query()
            ->where('author_id', $author->getKey())
            ->where('klucz_wyslania', $kluczWyslania)
            ->first();
    }

    /**
     * Pierwszy wpis nowej osoby — powiadomienie dla gospodarza (issue #6).
     *
     * 55% osób 55-64 i 62% osób 65+ w mediach społecznościowych to WYŁĄCZNIE
     * odbiorcy treści. Kto opublikuje pierwszy raz, robi to wbrew własnemu
     * nawykowi — i jeśli nikt nie odpowie, drugi raz już nie spróbuje.
     *
     * Powiadomienie idzie NATYCHMIAST, a nie przy najbliższym zajrzeniu
     * do panelu: doba to cały budżet czasu, jaki mamy na odpowiedź.
     *
     * Wpis prywatny pomijamy — nikt poza autorem go nie widzi, więc nie ma
     * na co odpowiadać.
     */
    private function powiadomGospodarzaOPierwszymWpisie(User $author, Post $post): void
    {
        if ($post->visibility === Post::VISIBILITY_PRIVATE) {
            return;
        }

        // Liczymy DOKŁADNIE DO DWÓCH: przy autorze z dwustoma wpisami
        // pełne `count()` przelicza całą historię, żeby odpowiedzieć
        // na pytanie „czy to pierwszy".
        $ilePierwszych = Post::query()
            ->where('author_id', $author->getKey())
            ->published()
            ->limit(2)
            ->count();

        if ($ilePierwszych !== 1) {
            return;
        }

        $nazwaGospodarza = (string) config('kuking.community.host_username');

        if ($nazwaGospodarza === '') {
            return;
        }

        $gospodarz = Profile::poNazwie($nazwaGospodarza)?->user;

        // `NotifyUser` sam pomija sytuację, w której gospodarz jest autorem —
        // a to jest częsty przypadek przy pierwszych dwudziestu osobach.
        if ($gospodarz === null) {
            return;
        }

        $this->notify->handle(
            recipient: $gospodarz,
            type: Notification::TYPE_FIRST_POST,
            actor: $author,
            data: ['post_id' => $post->getKey(), 'display_name' => $author->displayName()],
        );
    }

    private function cleanBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $trimmed = trim($body);

        return $trimmed === '' ? null : $trimmed;
    }
}
