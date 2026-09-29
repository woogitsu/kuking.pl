<?php

declare(strict_types=1);

namespace App\Domain\Posts;

use App\Domain\Collections\ZapisyWpisu;
use App\Domain\Questions\OdpowiedzNaPytanie;
use App\Domain\Reakcje\Smakowicie;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Policies\RecipePolicy;
use App\Support\OdpowiedziWatku;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Dane strony wpisu i pytania (`posts.show`, `questions.show`) — wyjęte
 * z `PostController::show()` bez zmiany zachowania (issue #970). Kontroler
 * zostaje przy autoryzacji (Policy) i przekierowaniach; tu jest ładowanie
 * relacji, ślad wglądu moderacji, odcięcie niedostępnego przepisu i złożenie
 * danych widoku.
 */
final class StronaWpisu
{
    public function __construct(
        private readonly SasiedniWpisAutora $sasiedniWpis,
        private readonly ZapisyWpisu $zapisy = new ZapisyWpisu,
    ) {}

    public function zapiszWgladModeracji(Post $post, ?User $widz, ?string $ip): void
    {
        // Wgląd obsługi w wpis ukryty przez moderację (#1018). Polityka
        // wpuszcza tu poza autorem wyłącznie moderatora z 2FA, więc każde
        // takie wejście zostawia ślad „kto to otworzył" — jak karta konta
        // w panelu (`admin.user_viewed`). Bez metadanych: `subject_id` mówi
        // wszystko, a treść wpisu nie ma trafiać do drugiej tabeli.
        $podgladModeracji = $post->status === Post::STATUS_HIDDEN
            && $widz?->getKey() !== $post->author_id;

        if ($podgladModeracji) {
            AuditLogEntry::record(
                action: 'moderation.hidden_post_viewed',
                actor: $widz,
                subject: $post,
                ip: $ip,
            );
        }
    }

    public function zaladuj(Post $post): void
    {
        // Wariant ROZSZERZONY kontraktu karty (#1037, `Post::scopeDlaKarty()`):
        // te same relacje co `Post::RELACJE_KARTY`, ale przepis w całości
        // i z autorem, bo niżej stoi `RecipePolicy::view()`.
        $post->load([
            'author.profile.avatar',
            'media',
            'tags:id,slug,name,status',
            /*
             * KOLUMNY, KTÓRYCH WIDOK NAPRAWDĘ UŻYWA — a nie te trzy, które
             * wyglądają na wystarczające (issue #447).
             *
             * Było `recipe:id,title,slug`. Zawężenie do trzech kolumn gubiło
             * dwie, których widok potrzebuje, i żadna z nich nie zgłaszała się
             * błędem:
             *
             *   `hero_media_id` — bez niej relacja `heroMedia` nie ma po czym
             *   trafić w wiersz i zwraca `null`. Karta pyta
             *   `$post->recipe?->heroMedia` i po cichu nie rysuje zdjęcia.
             *   Wpis z przepisu NIE MA własnych zdjęć z założenia (#368), więc
             *   tracił jedyne, jakie miał: strona wpisu „Bigos z cukinii”
             *   miała na produkcji ZERO obrazków, przy zdjęciu widocznym na tej
             *   samej karcie w strumieniu.
             *
             *   `visibility` — bez niej karta bierze widoczność WPISU, a ta
             *   dla wpisu z przepisu jest zawsze `public` (bramką jest przepis,
             *   `Post::scopeZWidocznymPrzepisem()`). Strona pisała więc
             *   autorowi „· publicznie” także pod przepisem, który widzą
             *   wyłącznie jego obserwujący. Przed tym ostrzega komentarz przy
             *   `post-card.blade.php:60` — karta była zabezpieczona, ten
             *   kontroler nie.
             *
             * Reguła na przyszłość: zawężenie kolumn musi obejmować KLUCZE OBCE
             * relacji, które będą dociągane dalej. Brak klucza nie jest błędem
             * — jest cichym `null`.
             *
             * 2026-09-21: ZAWĘŻENIA TU JUŻ NIE MA — I TO NIE JEST NIEDBALSTWO.
             * Poniżej stoi teraz `RecipePolicy::view()`, decydująca, czy ten
             * ekran w ogóle wolno mu pokazać przepis. Polityka czyta `status`,
             * `published_at`, `author_id` i relację `author`, a lista kolumn
             * ich nie miała. Skutek był dokładnie taki, jak każe się
             * spodziewać akapitowi wyżej: `isPublished()` czytało `status`
             * równy `null`, więc polityka odmawiała WSZYSTKIM i pasek
             * „Z przepisu" zniknął także pod przepisem w pełni publicznym.
             * Złapały to kontrole dodatnie w
             * `Tests\Feature\Visibility\StronaWpisuBramkaPrzepisuTest`, nie
             * człowiek na produkcji — i tylko dlatego, że są.
             *
             * Ręcznie utrzymywana lista kolumn POD POLITYKĄ to maszynka do
             * cichych awarii: polityka wolno rośnie o kolejny warunek,
             * a lista o nim nie wie. Przepis to jeden wiersz na jeden ekran,
             * więc oszczędność była i tak niemierzalna.
             */
            'recipe',
            'recipe.heroMedia',
            // `recipe.author` — bo `RecipePolicy::view()` niżej pyta o stan
            // konta autora przepisu (`jestDostepnyJakoAutor()`) i o blokadę
            // między nim a widzem. Bez tego byłoby to lazy load, czyli
            // zapytanie schowane przed każdym, kto liczy zapytania tego
            // ekranu (`StronyTresciBezWachlarzaZapytanTest`).
            'recipe.author',
            // Komentarze NIE SĄ tu ładowane (patrz niżej): rosną z popularnością
            // treści bez górnej granicy, więc idą osobnym, paginowanym
            // zapytaniem. `->load()` wciągał je wszystkie naraz.
        ]);
    }

    public function zdejmijNiedostepnyPrzepis(Post $post, ?User $widz): void
    {
        /*
         * WPIS ZOSTAJE, ODWOŁANIE DO PRZEPISU ZNIKA (czwarte miejsce z
         * przeglądu po #941).
         *
         * Tu trafia wpis, który ma coś WŁASNEGO: treść albo zdjęcia. Taki
         * wpis jest publiczny z własnych powodów i ma się otwierać — to jest
         * czyjeś „co dziś ugotowałem". Ale pasek „Z przepisu", zdjęcie główne
         * przepisu i przycisk „Ugotowałem" wypisywały tytuł i slug przepisu,
         * którego oglądający nie ma prawa zobaczyć; zmierzone dla gościa:
         * HTTP 200, tytuł w treści odnośnika, slug w `/przepisy/…` i
         * `alt="Zdjęcie do przepisu: …"`.
         *
         * Zdejmujemy więc RELACJĘ, a nie poszczególne pola w widoku. Karta
         * (`post-card.blade.php`) pyta o przepis w czterech miejscach —
         * zdjęcie zastępcze, pasek „Z przepisu", przycisk „Ugotowałem”
         * i odznaka widoczności — i piąte dopisze się kiedyś bez tej
         * poprawki. Jedno `setRelation()` zamyka wszystkie naraz, a odznaka
         * widoczności wraca wtedy do widoczności WPISU, czyli do jego
         * prawdziwej, własnej wartości.
         *
         * Zapowiedź przepisu tędy nie przechodzi — odcina ją wcześniej
         * `PostPolicy::view()`, bo po zdjęciu przepisu nie zostałoby z niej
         * nic poza nagłówkiem.
         */
        // `RecipePolicy` wprost, a nie `$widz->can()`: widzem bywa
        // GOŚĆ, a `?->can()` na `null` daje `null` — czyli warunek, który
        // odcinałby przepis także wtedy, gdy jest w pełni publiczny.
        // `RecipePolicy::view()` przyjmuje `?User` i to ona jest tu tabelą
        // prawdy, tą samą, co przy wejściu na sam przepis.
        if ($post->recipe !== null && ! app(RecipePolicy::class)->view($widz, $post->recipe)) {
            $post->setRelation('recipe', null);
        }
    }

    /**
     * Wątki komentarzy tej strony (pierwsza porcja). Kontroler dokłada do nich
     * `OdpowiedziWatku::uzupelnij()`, bo ta potrzebuje żądania, a domena nie
     * zna `Illuminate\Http` (#970).
     *
     * @return LengthAwarePaginator<int, Comment>
     */
    public function komentarze(Post $post, ?User $widz): LengthAwarePaginator
    {
        // Jak przy przepisie — te same dwa powody: blokady (issue #41)
        // i paginacja wątków.
        return $post->comments()
            ->widoczneDla($widz)
            ->with([
                'author.profile.avatar',
                // Odpowiedzi też porcjami (issue #939) — `OdpowiedziWatku`.
                'replies' => fn ($query) => OdpowiedziWatku::pierwszaPorcja($query, $widz),
                'replies.author.profile.avatar',
                // Ten sam powód co `recipe`/`replies.recipe` w
                // `RecipeController`: `Comment::subject()` pytany przy każdym
                // komentarzu (`notifiableUserId()`, „Zdejmij z urzędu”).
                'post',
                'replies.post',
            ])
            ->paginate((int) config('kuking.comments.page_size'), ['*'], 'komentarze');
    }

    /**
     * @param  LengthAwarePaginator<int, Comment>  $komentarze  już po `OdpowiedziWatku::uzupelnij()`
     * @return array{widok: string, dane: array<string, mixed>}
     */
    public function dane(Post $post, ?User $widz, LengthAwarePaginator $komentarze): array
    {
        // Liczba zapisów i stan „mam to w zeszycie" (issue #275, D-081).
        //
        // Tutaj JEDNYM ODDZIELNYM zapytaniem, a nie kolumną w SELECT-cie jak
        // w feedzie: ten ekran dostaje wpis z wiązania trasy, więc nie ma
        // zapytania, do którego dałoby się kolumnę dołożyć. Jeden wpis to
        // jeden ekran, więc to zapytanie jest STAŁE — nie jest to N+1.
        // Reguły są te same, bo `doliczDoWpisu()` woła to samo `dolicz()`,
        // co feed; gdyby ekran wpisu liczył po swojemu, ta sama liczba
        // znaczyłaby dwie różne rzeczy na dwóch ekranach.
        $this->zapisy->doliczDoWpisu($post, $widz);

        if ($post->kind === Post::KIND_QUESTION) {
            // `answerCount` w danych strukturalnych `QAPage` (JSON-LD) liczy
            // dokładnie to samo, co lista `/pytania` i kolejka gospodarza —
            // wspólna definicja `OdpowiedzNaPytanie::zawez()` (#372). Bez
            // dołączenia `posts` ten kontroler liczyłby TEŻ dopiski autora pod
            // własnym pytaniem jako odpowiedzi, czyli dokładnie usterkę, którą
            // ta definicja miała zamknąć wszędzie naraz.
            //
            // Widoczne komentarze idą jako PODZAPYTANIE: `widoczneDla()` pisze
            // kolumny bez tabeli (`status`), więc `join('posts')` na tym samym
            // poziomie dawał „column reference is ambiguous” (500 na każdej
            // stronie pytania).
            $widoczne = $post->comments()
                ->widoczneDla($widz)
                ->select('comments.*')
                ->getQuery();
            $odpowiedzi = DB::query()
                ->fromSub($widoczne, 'comments')
                ->join('posts', 'posts.id', '=', 'comments.post_id');
            OdpowiedzNaPytanie::zawez($odpowiedzi, 'comments', 'posts.author_id');
            $answerCount = $odpowiedzi->count();
            $post->setAttribute('comments_count', $answerCount);

            return ['widok' => 'pages.questions.show', 'dane' => [
                'komentarze' => $komentarze,
                'komentarzyRazem' => $answerCount,
                'post' => $post,
            ]];
        }

        return ['widok' => 'pages.posts.show', 'dane' => [
            'komentarze' => $komentarze,
            // Nagłówek rozmowy mówi tę samą liczbę co karta w strumieniu:
            // komentarze razem z odpowiedziami (#1801). `total()` stronicowania
            // liczy tylko wątki, więc tu zostaje wyłącznie do paginacji.
            'komentarzyRazem' => (int) $post->loadCount(Post::licznikWidocznychKomentarzy($widz))->comments_count,
            'post' => $post,
            // Zachęta do kolejnego zdjęcia brzmi inaczej przy pierwszym wpisie
            // (COLD_START.md). Liczymy TYLKO dla autora — dla kogokolwiek
            // innego to dodatkowe zapytanie bez żadnego zastosowania.
            'toPierwszyWpis' => $widz?->getKey() === $post->author_id
                && $post->author->posts()->published()->count() === 1,
            // „Kolejne zdjęcie" (issue: nawigacja jak w Garnku) — dwa proste
            // zapytania, oba po indeksie `posts_author_published_idx`.
            // Widoczność liczy `SasiedniWpisAutora`, nie ten kontroler.
            'poprzedniWpis' => $this->sasiedniWpis->poprzedni($post, $widz),
            'nastepnyWpis' => $this->sasiedniWpis->nastepny($post, $widz),
            // „Smakowicie wygląda" (#1813, D-280): KTO napisał — każdemu
            // widzowi (od 26.09), bez liczby, z filtrami blokad autora i widza.
            'smakowicie' => app(Smakowicie::class)->ktoDla($widz, $post),
        ]];
    }
}
