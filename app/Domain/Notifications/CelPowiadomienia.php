<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * DOKĄD PROWADZI POWIADOMIENIE (issue #1687, etap 2).
 *
 * Adres przycisku „Zobacz" dla jednego powiadomienia (`adres()`) i dla całej
 * strony naraz (`adresy()`, jeden odczyt komentarzy — #833). Do tej zmiany
 * obie metody stały w modelu Eloquenta razem z zapytaniem, które liczy
 * stronę wątku po widoczności komentarzy dla odbiorcy — czyli z regułą
 * innego modułu. Model zostawia sobie tylko wejście
 * `Notification::adresDocelowy()`; reguły przeniesione są 1:1, bez zmiany
 * zachowania.
 *
 * Czego ta klasa NIE robi: nie decyduje, czy odbiorca w ogóle widzi
 * powiadomienie — to `WidocznoscPowiadomien` (etap 1). Tutaj liczymy
 * wyłącznie cel dla powiadomienia, które już przeszło przez tamtą bramkę.
 */
final class CelPowiadomienia
{
    /**
     * Dokąd prowadzi przycisk „Zobacz" — albo `null`, gdy nie ma dokąd.
     *
     * DLACZEGO JEDNO MIEJSCE, A NIE WIDOK (bo tam stało do 8 września).
     * Od kiedy „Zobacz" oznacza powiadomienie jako przeczytane, adres liczą
     * DWA miejsca: widok, żeby zdecydować, czy w ogóle pokazać przycisk,
     * i kontroler, żeby wiedzieć, dokąd odesłać. Dwie kopie tego samego
     * `match` rozjechałyby się przy pierwszym nowym typie powiadomienia —
     * a rozjazd wyglądałby tak, że przycisk oznacza przeczytane i odsyła
     * gdzie indziej, niż zapowiadał. Jedno źródło, dwóch odbiorców.
     */
    public function adres(Notification $powiadomienie): ?string
    {
        $data = $powiadomienie->data ?? [];

        return match ($powiadomienie->type) {
            // Prowadzi do pełnoekranowego ekranu „Komuś wyszło" (issue #17),
            // nie od razu do zwykłego wpisu — to jest najcenniejszy moment
            // w produkcie i zasługuje na własną stronę, nie jeden wiersz
            // na liście. `celebrate()` sam się cofa do `cooked.show`,
            // kiedy ekran już był raz pokazany.
            //
            // ISSUE #771: usunięte wykonanie nie ma dokąd prowadzić. Link do
            // niego kończył się 404 — widok pokazuje wtedy uczciwy stan
            // („To ugotowanie zostało usunięte.") bez przycisku „Zobacz".
            Notification::TYPE_COOKED => isset($data['cooked_event_id']) && ! $powiadomienie->wykonanieUsuniete()
                ? route('cooked.celebrate', $data['cooked_event_id'])
                : null,
            Notification::TYPE_SAVED => isset($data['recipe_slug']) ? route('recipes.show', $data['recipe_slug']) : null,
            // ISSUE #734: po AKTUALNYM profilu sprawcy (`actor_id`), nie po
            // `data.username` zapamiętanym w chwili obserwowania. Po zmianie
            // nazwy stara prowadziła na 404 — albo, gdy ktoś ją potem zajął,
            // do INNEJ osoby niż ta, którą powiadomienie opisuje. Brak
            // profilu = brak celu, nigdy zgadywanie po starej nazwie.
            Notification::TYPE_FOLLOW => is_string($nazwa = $powiadomienie->actor?->profile?->username) && $nazwa !== ''
                ? route('profile.show', $nazwa)
                : null,
            Notification::TYPE_FIRST_POST => route('admin.unanswered'),
            // Wprost na kolejkę odwołań. Bez identyfikatora w adresie:
            // kolejka nie ma ekranu jednej sprawy, a odwołania otwarte stoją
            // na niej najstarsze na górze, czyli to z najbliższym terminem
            // jest pierwsze (`AppealController::index()`).
            Notification::TYPE_APPEAL_FILED => route('admin.appeals'),
            Notification::TYPE_WELCOME => route('posts.create'),
            // Obie drogi zgłaszającego (issue #10) prowadzą na kartę TEJ
            // sprawy, nie na listę: człowiek klika „Zobacz" przy konkretnym
            // powiadomieniu i ma zobaczyć konkretną sprawę. Trzymamy sam
            // identyfikator, nie gotowy adres — trasy się zmieniają,
            // a historia powiadomień zostaje na lata.
            Notification::TYPE_REPORT_RECEIVED, Notification::TYPE_REPORT_DECIDED => is_string($data['report_id'] ?? null) && $data['report_id'] !== ''
                ? route('reports.mine.show', $data['report_id'])
                : null,
            // ISSUE #759: komentarz/odpowiedź, nie tylko "gdzieś na tej treści".
            // Patrz `adresKomentarza()` niżej.
            Notification::TYPE_COMMENT, Notification::TYPE_REPLY => $this->adresKomentarza($powiadomienie, $data),
            default => is_string($data['url'] ?? null) && $data['url'] !== '' ? $data['url'] : null,
        };
    }

    /**
     * Adres KONKRETNEGO komentarza/odpowiedzi, nie tylko pierwszej strony
     * treści, pod którą stoi.
     *
     * CO BYŁO ZEPSUTE
     * `data.url` (`PublishComment::urlFor()`) niesie WYŁĄCZNIE
     * `$subject->url()` — bez numeru strony i bez kotwicy. Wątek pod
     * popularnym wpisem/przepisem jest stronicowany
     * (`config('kuking.comments.page_size')`, `PostController::show()`,
     * `RecipeController::show()`), więc przy odpowiedzi w korzeniu leżącym
     * poza pierwszą stroną „Zobacz" otwierał stronę bez tego wątku w ogóle —
     * a powiadomienie było już oznaczone jako przeczytane.
     *
     * DLACZEGO LICZYMY STRONĘ TERAZ, A NIE ZAPISUJEMY JEJ PRZY PUBLIKACJI
     * Numer strony zależy od tego, ILE wątków przed tym konkretnym jest
     * WIDOCZNYCH DLA ODBIORCY w chwili kliknięcia — a widoczność (blokady,
     * moderacja, inne komentarze skasowane w międzyczasie) zmienia się po
     * drodze. Zapisanie strony przy publikacji zamroziłoby ją na zawsze
     * błędną, gdy coś nad tym wątkiem zniknie albo się pojawi.
     *
     * KOTWICA WSKAZUJE SAM KOMENTARZ, NIE TYLKO KORZEŃ WĄTKU
     * `comment-thread.blade.php` ma `id="komentarz-{uuid}"` na artykule
     * korzenia — dla odpowiedzi (`TYPE_REPLY`) wskazujemy więc stronę
     * korzenia, ale kotwicę samej odpowiedzi, żeby przeglądarka przewinęła
     * dokładnie do niej, a nie tylko do góry wątku.
     *
     * NIEDOSTĘPNY/USUNIĘTY KOMENTARZ: BEZ UJAWNIANIA FRAGMENTU
     * Gdy komentarza już nie ma, nie jest widoczny dla tego odbiorcy albo
     * treść nadrzędna zniknęła spod niego, wracamy do zwykłego adresu treści
     * (`data.url`) zamiast błędu albo strony bez kontekstu — dokładnie tak,
     * jak przed tą poprawką dla WSZYSTKICH powiadomień o komentarzu. Sam
     * fakt niedostępności nie jest tu ujawniany bardziej, niż był wcześniej.
     */
    private function adresKomentarza(Notification $powiadomienie, array $data): ?string
    {
        $viewer = $powiadomienie->user;

        if ($viewer === null) {
            return self::adresZapasowy($data);
        }

        return $this->adresy([$powiadomienie], $viewer)[(string) $powiadomienie->getKey()];
    }

    private static function adresZapasowy(array $data): ?string
    {
        return is_string($data['url'] ?? null) && $data['url'] !== '' ? $data['url'] : null;
    }

    /**
     * Adresy całej strony, z jednym odczytem komentarzy (#833).
     * Odbiorca i jego widoczność obowiązują tylko podczas tego wywołania:
     * nie zapisujemy numerów stron ani nie buforujemy ich między żądaniami.
     *
     * @param  iterable<Notification>  $notifications  powiadomienia jednego odbiorcy
     * @return array<string, string|null>
     */
    public function adresy(iterable $notifications, User $viewer): array
    {
        $urls = [];
        $byComment = [];

        foreach ($notifications as $notification) {
            $id = (string) $notification->getKey();
            if (! in_array($notification->type, Notification::TYPY_Z_WYCINKIEM_KOMENTARZA, true)) {
                $urls[$id] = $this->adres($notification);

                continue;
            }

            $data = $notification->data ?? [];
            $urls[$id] = self::adresZapasowy($data);
            $commentId = $data['comment_id'] ?? null;
            if ((string) $notification->user_id === (string) $viewer->getKey()
                && is_string($commentId) && $commentId !== '') {
                $byComment[$commentId][] = $id;
            }
        }

        if ($byComment === []) {
            return $urls;
        }

        // Ten sam zakres co w relacjach comments() i na ekranie rozmowy.
        // Korelacja po korzeniu nie pobiera całych rozmów do pamięci PHP.
        $roots = Comment::query()->whereNull('comments.parent_id')->widoczneDla($viewer);
        $preceding = (clone $roots)->selectRaw('count(*)')
            ->where(function (Builder $subject): void {
                $subject->whereColumn('comments.post_id', 'root.post_id')
                    ->orWhereColumn('comments.recipe_id', 'root.recipe_id')
                    ->orWhereColumn('comments.cooked_event_id', 'root.cooked_event_id');
            })
            ->whereRaw('(comments.created_at, comments.id) < (root.created_at, root.id)');

        $comments = Comment::query()
            ->join('comments as root', function ($join): void {
                $join->whereRaw('root.id = coalesce(comments.parent_id, comments.id)');
            })
            ->leftJoin('posts', 'posts.id', '=', 'comments.post_id')
            ->leftJoin('recipes', 'recipes.id', '=', 'comments.recipe_id')
            ->leftJoin('cooked_events', 'cooked_events.id', '=', 'comments.cooked_event_id')
            ->whereIn('comments.id', array_keys($byComment))
            ->whereIn('root.id', (clone $roots)->select('comments.id'))
            ->where(function (Builder $subject): void {
                $subject->whereColumn('comments.post_id', 'root.post_id')
                    ->orWhereColumn('comments.recipe_id', 'root.recipe_id')
                    ->orWhereColumn('comments.cooked_event_id', 'root.cooked_event_id');
            })
            ->where(function (Builder $subject): void {
                $subject->where(fn (Builder $post) => $post->whereNotNull('posts.id')->whereNull('posts.deleted_at'))
                    ->orWhere(fn (Builder $recipe) => $recipe->whereNotNull('recipes.id')->whereNull('recipes.deleted_at'))
                    ->orWhereNotNull('cooked_events.id');
            })
            ->select(['comments.id', 'comments.post_id', 'comments.recipe_id', 'comments.cooked_event_id', 'posts.kind', 'recipes.slug'])
            ->selectSub($preceding, 'preceding_count')
            ->get();

        $pageSize = (int) config('kuking.comments.page_size');
        foreach ($comments as $comment) {
            if ($comment->cooked_event_id !== null) {
                $subject = (new CookedEvent)->forceFill(['id' => $comment->cooked_event_id]);
                $page = 1;
            } else {
                if ($pageSize < 1) {
                    continue;
                }
                $subject = $comment->post_id !== null
                    ? (new Post)->forceFill(['id' => $comment->post_id, 'kind' => $comment->kind])
                    : (new Recipe)->forceFill(['slug' => $comment->slug]);
                $page = intdiv((int) $comment->preceding_count, $pageSize) + 1;
            }

            $url = $subject->url();
            if ($page > 1) {
                $url .= (str_contains($url, '?') ? '&' : '?').'komentarze='.$page;
            }
            $url .= '#komentarz-'.$comment->getKey();
            foreach ($byComment[(string) $comment->getKey()] as $id) {
                $urls[$id] = $url;
            }
        }

        return $urls;
    }
}
