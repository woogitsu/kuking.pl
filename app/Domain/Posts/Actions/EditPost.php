<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Domain\Posts\KonfliktEdycjiWpisu;
use App\Domain\Tags\Actions\ResolvePostTags;
use App\Domain\Tags\TagMutationLock;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Edycja wpisu: tekst, widoczność, tagi (issue: menu „…" pokazywało tylko
 * „Otwórz wpis" dla autora — luka MVP, `docs/FEATURES.md` sekcja „Wpis").
 *
 * ZDJĘCIA ZOSTAJĄ POZA TĄ AKCJĄ. Kolejność i sposób wyświetlania zdjęć mają
 * już swój ekran („Zdjęcia w tym wpisie", `ArrangePostMedia`) — powielanie
 * tego tutaj dałoby dwie drogi do tego samego stanu i dwie okazje do rozjazdu.
 *
 * DLACZEGO OSOBNA AKCJA, A NIE `PublishPost::handle()` DRUGI RAZ
 * `PublishPost` tworzy NOWY wpis: liczy „czy to pierwszy wpis" i wysyła
 * powiadomienie do gospodarza. Wywołanie jej na istniejącym wpisie
 * wysłałoby to powiadomienie drugi raz przy zwykłej poprawce literówki.
 *
 * DWIE KARTY NA RAZ (issue #981)
 * Formularz niesie `wersja()` z chwili otwarcia. Jeśli pod blokadą wiersza
 * wpis ma już inną wersję, zapis jest odrzucany (`KonfliktEdycjiWpisu`),
 * zamiast po cichu nadpisać tekst, widoczność i tagi z drugiej karty.
 * Wersja to odcisk TREŚCI, którą ten ekran edytuje — nie `updated_at`:
 * ten ma sekundową dokładność (`timestampsTz()`), więc dwa zapisy w tej
 * samej sekundzie wyglądałyby na jedną wersję, a zmienia się też przy
 * rzeczach spoza tego formularza (zdjęcia, moderacja), co dawałoby
 * fałszywe ostrzeżenia. Odcisk nie potrzebuje też nowej kolumny.
 *
 * ZNANA GRANICA: scalenie tagów (`MergeTags`) przepina `post_tags` na tag
 * docelowy, więc formularz otwarty przed scaleniem ma już inną wersję.
 * Odcisk z nazw albo slugów tego nie naprawi — cel ma inną nazwę i slug
 * niż źródło. Zapis bez zmian przechodzi (nazwa źródła jest aliasem celu,
 * więc stan docelowy równa się zapisanemu), a zapis ze zmianą pokazuje
 * ekran konfliktu z zapisaną wersją — nic nie ginie, to rzadki przypadek.
 */
final class EditPost
{
    public function __construct(private readonly ResolvePostTags $resolveTags) {}

    /** @param  list<string>  $tagNames  to, co ktoś WPISAŁ jako tagi (wolny tekst, nie id) — D-021 */
    public function handle(
        User $actor,
        Post $post,
        ?string $body,
        string $visibility,
        array $tagNames = [],
        ?string $questionTitle = null,
        ?string $wersjaFormularza = null,
    ): Post {
        // Kontroler nie jest jedyną drogą do akcji domenowej. Jawny aktor
        // zamyka tę samą granicę także przed zadaniem, komendą albo testem,
        // zanim odczytamy zdjęcia lub rozpoczniemy jakąkolwiek zmianę.
        Gate::forUser($actor)->authorize('update', $post);

        $body = $this->cleanBody($body);

        // Ten sam twardy warunek co przy publikacji (PublishPost): wpis musi
        // mieć CO NAJMNIEJ zdjęcie ALBO tekst. Zdjęć ten ekran nie dotyka,
        // więc liczy się to, co wpis ma już przypięte.
        if ($post->kind !== Post::KIND_QUESTION && $body === null && $post->media()->count() === 0) {
            throw new BladDlaCzlowieka('Wpis nie może być całkiem pusty. Napisz kilka słów.');
        }

        return DB::transaction(function () use ($post, $body, $visibility, $tagNames, $questionTitle, $wersjaFormularza): Post {
            TagMutationLock::forPost();
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            // Porównanie POD blokadą: dwa równoległe zapisy z tą samą wersją
            // startową szeregują się na `FOR UPDATE`, więc drugi widzi już
            // wersję zapisaną przez pierwszy. `null` = wołający bez formularza
            // (zadanie, komenda, test) — nie ma czego porównywać.
            // Niezgodność nie jest jeszcze konfliktem — patrz niżej.
            $wersjaZapisana = $this->wersja($locked);
            $innaWersja = $wersjaFormularza !== null && ! hash_equals($wersjaZapisana, $wersjaFormularza);
            if ($locked->kind === Post::KIND_QUESTION) {
                if (! config('kuking.questions.enabled')) {
                    throw new BladDlaCzlowieka('Edycja pytań jest teraz niedostępna.');
                }
                $title = trim($questionTitle ?? $locked->title);
                if (mb_strlen($title) < 10 || mb_strlen($title) > 180) {
                    throw new BladDlaCzlowieka('Napisz pytanie w tytule — od 10 do 180 znaków.');
                }
                // Ta sama nazwana metoda co przy publikacji: tytuł pytania
                // i `kind` to dla bazy jedna wartość (CHECK
                // `posts_kind_title_check`), więc ustawiamy je razem —
                // także wtedy, gdy `kind` już jest właściwy.
                $locked->oznaczJakoPytanie($title);
            }
            $tags = $this->resolveTags->handle($body, $tagNames, $locked);
            if ($locked->kind === Post::KIND_QUESTION && count($tags) > 3) {
                throw new BladDlaCzlowieka('Do pytania dodaj najwyżej 3 tagi, także te wpisane w opisie.');
            }
            if ($innaWersja) {
                // PODWÓJNE KLIKNIĘCIE „Zapisz zmiany" (przegląd #981): drugie
                // żądanie niesie tę samą wersję startową co pierwsze, które
                // już zapisało. Przeglądarka pokazuje odpowiedź na DRUGIE,
                // więc konflikt przy identycznej treści straszyłby „inną
                // kartą", choć zapis się udał. Gdy to, co ktoś chce zapisać,
                // jest dokładnie tym, co już jest w bazie, nie ma czego
                // chronić: sukces bez zapisu. Przy niezgodności wyjątek
                // cofa transakcję, także ewentualnie utworzone tagi.
                $wersjaDocelowa = $this->odcisk($locked->kind, $locked->title, $body, $visibility, array_map(
                    fn (int|string $tagId, array $pivot): array => [$tagId, $pivot['dodany_recznie']],
                    array_keys($tags),
                    $tags,
                ));
                if (! hash_equals($wersjaZapisana, $wersjaDocelowa)) {
                    throw new KonfliktEdycjiWpisu;
                }

                return $locked->refresh();
            }
            $locked->forceFill(['body' => $body, 'visibility' => $visibility])->save();

            // Cały pivot ma tylko pozycję i pochodzenie. Odtworzenie go
            // atomowo unika kolizji UNIQUE(post_id, position) przy zamianie
            // kolejności tagów. Media, status i published_at pozostają.
            $locked->tags()->detach();
            $locked->tags()->attach($tags);

            return $locked;
        }, 3);
    }

    /**
     * Odcisk tego, co edytuje ten ekran: tytuł pytania, tekst, widoczność
     * i tagi (z pochodzeniem, w kolejności). Tagi czytane zapytaniem, nie
     * z załadowanej relacji — wewnątrz transakcji liczy się stan bazy.
     */
    public function wersja(Post $post): string
    {
        $tagi = $post->tags()->get()
            ->map(fn ($tag): array => [$tag->getKey(), (bool) $tag->pivot->dodany_recznie])
            ->all();

        return $this->odcisk($post->kind, $post->title, $post->body, $post->visibility, $tagi);
    }

    /** @param  list<array{0: int|string, 1: bool}>  $tagi  id tagu i pochodzenie, w kolejności */
    private function odcisk(?string $kind, ?string $title, ?string $body, ?string $visibility, array $tagi): string
    {
        return hash('sha256', json_encode([$kind, $title, $body, $visibility, $tagi], JSON_THROW_ON_ERROR));
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
