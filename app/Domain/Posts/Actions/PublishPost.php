<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Domain\Community\HostUserResolver;
use App\Domain\Media\ZdjeciaDoPrzypiecia;
use App\Domain\Moderation\UnansweredContent;
use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Posts\PublicationAnalysisQueue;
use App\Domain\Tags\Actions\ResolvePostTags;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Notification;
use App\Models\Post;
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
        private readonly ResolvePostTags $resolveTags,
        private readonly PublicationAnalysisQueue $analysisQueue,
        private readonly HostUserResolver $hostUser,
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
        ?string $questionTitle = null,
    ): Post {
        $body = $this->cleanBody($body);
        $kind = $questionTitle === null ? Post::KIND_DISH : Post::KIND_QUESTION;
        if ($kind === Post::KIND_QUESTION) {
            if (! config('kuking.questions.enabled')) {
                throw new BladDlaCzlowieka('Dodawanie pytań jest teraz niedostępne.');
            }
            $questionTitle = trim($questionTitle);
            if (mb_strlen($questionTitle) < 10 || mb_strlen($questionTitle) > 180) {
                throw new BladDlaCzlowieka('Napisz pytanie w tytule — od 10 do 180 znaków.');
            }
            if (count(array_unique($mediaIds)) > 1) {
                throw new BladDlaCzlowieka('Do pytania możesz dodać jedno zdjęcie.');
            }
            if ($recipeId !== null || $visibility !== Post::VISIBILITY_PUBLIC) {
                throw new BladDlaCzlowieka('Pytanie publikujemy w dziale Poradźcie, dla wszystkich.');
            }
        }

        if ($kind === Post::KIND_DISH && $body === null && $mediaIds === []) {
            throw new BladDlaCzlowieka('Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego opublikować.');
        }

        // Nowe nazwy i pivoty powstają w tej samej transakcji co wpis.
        // Odrzucony limit ani ponowione wysłanie nie zostawiają tagów-sierot.
        $tags = [];

        $trybZadany = $displayMode;

        /** @var list<string> $orderedMedia zdjęcia, które NAPRAWDĘ trafiły do wpisu */
        $orderedMedia = [];
        $displayMode = Post::DISPLAY_NORMAL;

        $this->analysisQueue->assertCompatible();

        $zapisz = function (?string $klucz) use ($author, $body, $visibility, $recipeId, $mediaIds, $trybZadany, $tagNames, $kind, $questionTitle, $ip, &$tags, &$orderedMedia, &$displayMode): Post {
            return DB::transaction(function () use ($author, $body, $visibility, $recipeId, $mediaIds, $trybZadany, $tagNames, $klucz, $kind, $questionTitle, $ip, &$tags, &$orderedMedia, &$displayMode): Post {
                $tags = $this->resolveTags->handle($body, $tagNames);
                if ($kind === Post::KIND_QUESTION && count($tags) > 3) {
                    throw new BladDlaCzlowieka('Do pytania dodaj najwyżej 3 tagi, także te wpisane w opisie.');
                }
                /*
                 * WYBÓR ZDJĘĆ STOI W TEJ SAMEJ TRANSAKCJI CO PRZYPIĘCIE
                 * (issue #285, D-083).
                 *
                 * Przedtem to zapytanie było PRZED transakcją i bez blokady,
                 * więc między „te zdjęcia należą do tej osoby" a `attach()`
                 * mieściło się całe sprzątanie osieroconych zdjęć razem
                 * z kasowaniem plików w R2. Wpis powstawał, powiązanie
                 * znikało po cichu przez `ON DELETE CASCADE`, a jedyny
                 * egzemplarz zdjęcia był już nie do odzyskania.
                 *
                 * `ZdjeciaDoPrzypiecia::zablokuj()` bierze wiersze `media`
                 * `FOR UPDATE` w deterministycznej kolejności i sprawdza
                 * własność DOPIERO POD BLOKADĄ. Zdjęcie przejęte w tym czasie
                 * do skasowania po prostu nie wróci z tego zapytania: wpis
                 * powstaje bez niego, zamiast powstać z powiązaniem, które
                 * zaraz zniknie.
                 *
                 * Bramka własności zostaje tu bez zmian i jest ważniejsza niż
                 * wyścig: bez niej ktoś podstawiłby w formularzu cudze
                 * `media_id` (IDOR).
                 */
                $ownedMedia = ZdjeciaDoPrzypiecia::zablokuj((string) $author->getKey(), $mediaIds);

                // Media przed kontem (D-103), konto przed INSERT i rozstrzygnięciem pierwszeństwa.
                // NO KEY UPDATE serializuje publikacje, ale nie blokuje odczytów FK KEY SHARE.
                User::query()->whereKey($author->getKey())->lock('FOR NO KEY UPDATE')->firstOrFail();

                // Zachowujemy kolejność wybraną przez użytkownika —
                // `zablokuj()` oddaje kolejność blokowania, nie formularza.
                $orderedMedia = array_values(array_filter(
                    $mediaIds,
                    static fn (string $id): bool => in_array($id, $ownedMedia, true),
                ));

                $orderedMedia = array_slice($orderedMedia, 0, (int) config('kuking.media.max_per_post'));

                // Pierwsza kontrola wyżej widzi tylko identyfikatory z
                // żądania. Dopiero rewalidacja pod blokadą mówi, czy któreś
                // zdjęcie nadal wolno przypiąć (issue #1093). Jeśli wszystkie
                // odpadły, zwykły wpis bez tekstu nadal jest pusty — nie
                // wolno zapisać pustej karty i dopiero potem powiedzieć, że
                // publikacja się udała.
                if ($kind === Post::KIND_DISH && $body === null && $orderedMedia === []) {
                    throw new BladDlaCzlowieka(
                        'Wybrane zdjęcie nie jest już dostępne. Wybierz je ponownie albo napisz kilka słów.',
                    );
                }

                // Sposób wyświetlania zdjęć (issue #92). Przy jednym zdjęciu
                // wybór nie znaczy nic — karuzela z jednym slajdem i kolaż
                // z jednym polem to ten sam widok co „zwykle" — więc
                // zapisujemy `normal` zamiast trzymać w bazie deklarację,
                // której nie da się zobaczyć. Wartość spoza listy też schodzi
                // do `normal`: baza odrzuciłaby ją CHECK-iem, a wpis, który
                // nie zostaje opublikowany z powodu wyboru układu, to zła
                // zamiana.
                $displayMode = count($orderedMedia) < 2 || ! in_array($trybZadany, Post::dozwoloneTrybyWyswietlania(), true)
                    ? Post::DISPLAY_NORMAL
                    : $trybZadany;

                // `kind` i `title` NIE IDĄ przez tablicę: pole sterujące
                // ustawia nazwana metoda (`Post::oznaczJakoPytanie()`),
                // a tytuł jest z nim związany CHECK-iem w bazie.
                $post = new Post([
                    'author_id' => $author->getKey(),
                    'body' => $body,
                    'visibility' => $visibility,
                    'status' => Post::STATUS_PUBLISHED,
                    'display_mode' => $displayMode,
                    'recipe_id' => $recipeId,
                    'klucz_wyslania' => $klucz,
                    'published_at' => now(),
                ]);

                if ($kind === Post::KIND_QUESTION) {
                    $post->oznaczJakoPytanie((string) $questionTitle);
                }

                $post->save();

                foreach ($orderedMedia as $position => $mediaId) {
                    $post->media()->attach($mediaId, ['position' => $position]);
                }

                $post->tags()->attach($tags);

                AuditLogEntry::record(
                    action: 'post.published',
                    actor: $author,
                    subject: $post,
                    metadata: ['media_count' => count($orderedMedia), 'visibility' => $visibility, 'display_mode' => $displayMode, 'tag_count' => count($tags)],
                    ip: $ip,
                );
                $this->powiadomGospodarzaOPierwszymWpisie($author, $post);
                $this->analysisQueue->push($post);

                return $post;
            }, 3);
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

        $gospodarz = $this->hostUser->resolve();
        $eligible = $gospodarz === null
            ? Post::query()->publiclyVisible()->whereHas('author', fn ($query) => $query->widocznyJakoOsoba())
            : app(UnansweredContent::class)->eligiblePosts($gospodarz);
        if (! $eligible->whereKey($post->getKey())->exists()
            || DB::table('first_post_events')->where('author_id', $author->getKey())->exists()) {
            return;
        }

        DB::table('first_post_events')->insert(['author_id' => $author->getKey(), 'post_id' => $post->getKey()]);
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
