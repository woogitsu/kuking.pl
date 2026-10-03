<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Domain\Posts\KontoNieMozePublikowac;
use App\Domain\Posts\PublicationAnalysisQueue;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Support\ZabezpieczoneDowody;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Autor publikuje ponownie TEN SAM wpis, który moderacja przywróciła jako szkic
 * (#2461).
 *
 * PO CO TO ISTNIEJE
 * Przywrócenie ukrytego wpisu bez zapisanego stanu sprzed ukrycia kończy się
 * szkicem (`ModeratedContent::DOMYSLNY_PO_PRZYWROCENIU`): moderacja nie wie,
 * czym wpis był, więc nie upublicznia go za autora. Komentarz w kodzie
 * obiecywał „jedno kliknięcie Opublikuj", ale takiej akcji nie było:
 * `EditPost` zachowuje status, a `PublishPost` zakłada NOWY rekord, czyli
 * gubi adres, komentarze, zapisy do zeszytów i powiązania oryginału.
 *
 * DECYZJE WŁAŚCICIELA Z 2.10.2026 (docs/decyzje/D-333-…, wiersz #2461)
 *  - publikuje sam AUTOR, nie moderator; fallback przywrócenia zostaje
 *    szkicem;
 *  - wpis wraca na SWOJE DAWNE MIEJSCE: `published_at` zostaje oryginalne,
 *    a obserwujący NIE dostają żadnego powiadomienia — to naprawa, nie nowa
 *    publikacja. Tak samo nie powstaje wpis „pierwszy wpis" dla gospodarza
 *    (`first_post_events`), bo to nie jest pierwsza publikacja tej osoby;
 *  - widoczność zostaje taka, jaka jest. Ta akcja jej nie przyjmuje i nie
 *    zmienia — zmiana to zwykła edycja (`EditPost`).
 *
 * CO ROBI TAK SAMO JAK ZWYKŁA PUBLIKACJA
 * Zleca analizę treści (`PublicationAnalysisQueue`, ta sama co w
 * `PublishPost`). Treść mogła się zmienić, gdy wpis leżał jako szkic, a
 * analiza daje moderatorowi sygnał (D-052, D-055), nie decyzję.
 *
 * CO SPRAWDZA POD BLOKADĄ (konto, potem wiersz wpisu)
 *  - aktywne konto autora (kara mogła wygasnąć albo zostać nałożona w
 *    międzyczasie — jak w `PublishPost`);
 *  - własność, stan `draft` i to, że wpis był kiedyś opublikowany;
 *  - że OSTATNIĄ decyzją moderacji o tym wpisie jest `unhide`. Ponowne
 *    ukrycie ustawia status `hidden`, więc nowa decyzja zatrzymuje to już na
 *    stanie wpisu; ten warunek dodatkowo zamyka szkice, które nigdy nie były
 *    przywracane moderacją (ich losu ta akcja nie rozstrzyga);
 *  - że treść nie jest zabezpieczona jako dowód;
 *  - że powiązany przepis jest opublikowany, a wpis nie jest pusty.
 *
 * IDEMPOTENCJA: wpis już opublikowany to brak skutku (`false`), bez drugiego
 * audytu i bez drugiej analizy. Dwa równoległe żądania szeregują się na
 * `FOR UPDATE`.
 */
final class PublishRestoredDraft
{
    public const KOMUNIKAT_NIE_TEN_STAN = 'Tego wpisu nie da się teraz opublikować tą drogą. Wejdź w „Moje wpisy” i sprawdź, w jakim jest stanie.';

    public const KOMUNIKAT_POD_DECYZJA = 'Moderacja ukryła albo zdjęła ten wpis, więc nie da się go opublikować. '
        .'Jeśli uważasz, że to pomyłka, odwołaj się od decyzji — znajdziesz ją w powiadomieniach.';

    public function __construct(private readonly PublicationAnalysisQueue $analysisQueue) {}

    /**
     * @return bool `true` — wpis został opublikowany teraz; `false` — był już
     *              opublikowany i nic się nie zmieniło (drugie żądanie)
     *
     * @throws KontoNieMozePublikowac gdy konto autora nie jest aktywne
     * @throws BladDlaCzlowieka gdy wpis nie może wrócić (komunikat po polsku)
     */
    public function handle(User $actor, Post $post, ?string $ip = null): bool
    {
        // Jawny aktor zamyka granicę także przed zadaniem, komendą i testem.
        Gate::forUser($actor)->authorize('publishRestored', $post);

        $this->analysisQueue->assertCompatible();

        return DB::transaction(function () use ($actor, $post, $ip): bool {
            // Konto przed wpisem — ta sama kolejność co w `PublishPost`.
            $autor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            if ($autor->punishmentHasExpired()) {
                $autor->reinstate();
            }
            if (! $autor->isActive()) {
                throw new KontoNieMozePublikowac(
                    'Stan Twojego konta nie pozwala teraz publikować. Odśwież stronę, aby zobaczyć aktualną informację.',
                );
            }

            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->author_id !== $autor->getKey()) {
                throw new BladDlaCzlowieka(self::KOMUNIKAT_NIE_TEN_STAN);
            }

            if ($locked->jestPodDecyzjaModeracji()) {
                throw new BladDlaCzlowieka(self::KOMUNIKAT_POD_DECYZJA);
            }

            if ($locked->status === Post::STATUS_PUBLISHED) {
                return false;
            }

            $powod = self::powodOdmowy($locked);
            if ($powod !== null) {
                throw new BladDlaCzlowieka($powod);
            }

            // Status to pole sterujące (nie ma go w `$fillable`). `published_at`
            // i `visibility` zostają dokładnie takie, jakie wpis ma.
            $locked->forceFill(['status' => Post::STATUS_PUBLISHED])->save();

            AuditLogEntry::record(
                action: 'post.republished',
                actor: $autor,
                subject: $locked,
                metadata: ['visibility' => $locked->visibility, 'published_at_kept' => true],
                ip: $ip,
            );

            $this->analysisQueue->push($locked);

            return true;
        }, 3);
    }

    /**
     * Czy wpis w TYM stanie może wrócić do publikacji — `null`, gdy tak,
     * a w przeciwnym razie zdanie po polsku. Jedno źródło prawdy dla akcji
     * (pod blokadą) i dla ekranu potwierdzenia (bez blokady).
     */
    public static function powodOdmowy(Post $post): ?string
    {
        if ($post->jestPodDecyzjaModeracji()) {
            return self::KOMUNIKAT_POD_DECYZJA;
        }

        if ($post->status !== Post::STATUS_DRAFT || $post->published_at === null) {
            return self::KOMUNIKAT_NIE_TEN_STAN;
        }

        $typ = (string) ModeratedContent::typ($post);
        $ostatnia = ModerationAction::query()
            ->where('target_type', $typ)
            ->where('target_id', $post->getKey())
            ->whereIn('action', [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE, ModerationAction::ACTION_UNHIDE])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('action');

        if ($ostatnia !== ModerationAction::ACTION_UNHIDE) {
            return self::KOMUNIKAT_NIE_TEN_STAN;
        }

        if (ZabezpieczoneDowody::dotyczy($typ, (string) $post->getKey())) {
            return self::KOMUNIKAT_POD_DECYZJA;
        }

        if ($post->kind === Post::KIND_QUESTION) {
            if (! config('kuking.questions.enabled')) {
                return 'Pytania są teraz niedostępne, więc tego wpisu nie da się opublikować. Spróbuj później.';
            }
        } elseif ($post->recipe_id === null && blank($post->body) && $post->media()->count() === 0) {
            return 'Wpis nie ma zdjęcia ani tekstu. Dodaj kilka słów w edycji wpisu, a potem opublikuj.';
        }

        if ($post->recipe_id !== null) {
            $przepis = Recipe::query()->whereKey($post->recipe_id)->first();
            if ($przepis === null || ! $przepis->isPublished()) {
                return 'Przepis powiązany z tym wpisem nie jest teraz dostępny, więc wpisu nie da się opublikować.';
            }
        }

        return null;
    }
}
