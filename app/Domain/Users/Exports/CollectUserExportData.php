<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Models\Collection;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Zbiera CAŁĄ treść jednego konta w jedną tablicę — to zawartość `dane.json`.
 *
 * Dwie reguły, których nie wolno tu naruszyć:
 *
 * 1. **Wszystko, co należy do użytkownika, wchodzi do paczki** — także wpisy
 *    prywatne i szkice przepisów. Eksport, który pomija szkice, jest gorszy
 *    niż brak eksportu, bo daje złudzenie kompletności. Dlatego czytamy
 *    relacje BEZ filtrów `published`.
 *
 * 2. **Cudze dane osobowe NIE wchodzą do paczki.** Komentarz innej osoby pod
 *    wpisem użytkownika jest danymi TEJ osoby. Podajemy treść, datę i nazwę
 *    wyświetlaną — nigdy e-maila i nigdy identyfikatora konta. To samo dotyczy
 *    powiadomień: `data` z bazy przepisujemy przez listę dozwolonych kluczy,
 *    więc nowy klucz dodany kiedyś w kodzie NIE wycieknie tu przez przypadek.
 *
 * Klucze są po polsku, bo ten plik czyta człowiek, a nie tylko program
 * (AGENTS.md, sekcja 11). Format zostaje maszynowy — JSON, UTF-8, daty ISO 8601.
 */
final class CollectUserExportData
{
    /**
     * Klucze z `notifications.data`, które wolno przepisać do eksportu.
     *
     * Świadomie NIE ma tu `username` ani żadnego `*_id` — to identyfikatory
     * (cudze albo wewnętrzne), a paczka ma być czytelna, nie technicznie pełna.
     */
    private const NOTIFICATION_DATA_KEYS = [
        'excerpt',
        'recipe_title',
        'recipe_slug',
        'has_photo',
        'url',
    ];

    /** @return array<string, mixed> */
    public function handle(User $user, ExportPhotoPlan $photos, Carbon $generatedAt): array
    {
        $user->loadMissing('profile.avatar');

        return [
            'o_tym_pliku' => [
                'serwis' => 'Kuking.pl',
                'wygenerowano' => $generatedAt->toIso8601String(),
                'format' => 'JSON, kodowanie UTF-8, daty w formacie ISO 8601',
                'co_zawiera' => 'Wszystkie treści tego konta — także wpisy prywatne i szkice przepisów.',
                'czego_nie_zawiera' => 'Danych kontaktowych innych osób. Komentarze innych ludzi mają treść, datę i nazwę wyświetlaną autora, bez adresu e-mail i bez identyfikatora konta.',
                'podstawa_prawna' => 'RODO art. 15 (dostęp do danych) i art. 20 (przenoszenie danych)',
                // Pole jest ZAWSZE, także gdy wynosi zero. Klucz pojawiający
                // się tylko przy brakach zmusiłby program czytający paczkę do
                // zgadywania, czy zera nie ma, bo braków nie było, czy dlatego,
                // że paczkę zbudowała starsza wersja serwisu (issue #113).
                'zdjec_jeszcze_w_przygotowaniu' => $photos->stillProcessingCount(),
            ],
            'konto' => $this->account($user),
            'profil' => $this->profile($user, $photos),
            'przepisy' => $this->recipes($user, $photos),
            'wpisy' => $this->posts($user, $photos),
            'ugotowalem' => $this->cookedEvents($user, $photos),
            'moje_komentarze' => $this->ownComments($user),
            'kolekcje' => $this->collections($user),
            'obserwuje' => $this->people($user->following()->with('profile')->get()),
            'obserwuja_mnie' => $this->people($user->followers()->with('profile')->get()),
            'zablokowane_osoby' => $this->people($user->blocking()->with('profile')->get()),
            'powiadomienia' => $this->notifications($user),
            'zdjecia' => $this->photos($photos),
        ];
    }

    /** @return array<string, mixed> */
    private function account(User $user): array
    {
        return [
            'email' => $user->email,
            'konto_utworzone' => $this->date($user->created_at),
            'email_potwierdzony' => $this->date($user->email_verified_at),
            'wiek_potwierdzony' => $this->date($user->age_confirmed_at),
            'status_konta' => $user->status,
            'jezyk' => $user->locale,
            'rozmiar_tekstu_procent' => $user->text_scale,
            'chce_podsumowania_tygodnia' => (bool) $user->wants_weekly_digest,
            'usuniecie_konta_zgloszone' => $this->date($user->delete_requested_at),
        ];
    }

    /** @return array<string, mixed>|null */
    private function profile(User $user, ExportPhotoPlan $photos): ?array
    {
        $profile = $user->profile;

        if ($profile === null) {
            return null;
        }

        return [
            'nazwa_uzytkownika' => $profile->username,
            'nazwa_wyswietlana' => $profile->display_name,
            'o_mnie' => $profile->bio,
            'okolica' => $profile->region,
            'w_czym_jestem_dobra' => $profile->speciality,
            'zdjecie_profilowe' => $photos->pathFor($profile->avatar_media_id),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recipes(User $user, ExportPhotoPlan $photos): array
    {
        // Bez `published()` — szkic to też przepis użytkownika.
        // Sortujemy po dacie, którą użytkownik WIDZI w paczce (publikacji,
        // a dla szkicu — utworzenia), żeby „po kolei” zgadzało się z datami.
        $recipes = $user->recipes()
            ->with(['ingredients.ingredient', 'ingredients.unit', 'steps', 'comments.replies.author.profile', 'comments.author.profile'])
            ->orderByRaw('coalesce(published_at, created_at)')
            ->get();

        return $recipes->map(fn (Recipe $recipe): array => [
            'tytul' => $recipe->title,
            'adres_w_serwisie' => $recipe->slug,
            'plik_do_czytania' => 'przepisy/'.ExportFileNames::recipeFile($recipe),
            'krotki_opis' => $recipe->summary,
            'porcje' => $recipe->servings,
            'przygotowanie_minuty' => $recipe->prep_minutes,
            'gotowanie_minuty' => $recipe->cook_minutes,
            'trudnosc' => $recipe->difficulty,
            'widocznosc' => $recipe->visibility,
            'status' => $recipe->status,
            'skad_przepis' => $recipe->source_type,
            'skad_przepis_opis' => Recipe::SOURCE_LABELS[$recipe->source_type] ?? null,
            'zrodlo_adres' => $recipe->source_url,
            'od_kogo' => $recipe->source_person,
            'notatka_o_zrodle' => $recipe->source_note,
            'w_rodzinie_od_roku' => $recipe->family_since_year,
            'zdjecie_glowne' => $photos->pathFor($recipe->hero_media_id),
            'skan_zeszytu' => $photos->pathFor($recipe->source_scan_media_id),
            'utworzono' => $this->date($recipe->created_at),
            'opublikowano' => $this->date($recipe->published_at),
            'skladniki' => $recipe->ingredients->map(fn ($item): array => [
                'grupa' => $item->group_name,
                // `ingredient_text` to dokładnie to, co wpisał człowiek
                // („2 szklanki mąki”). Rozbite pola są obok, dla programów.
                'zapis' => $item->ingredient_text,
                'ile' => $item->quantity,
                'jednostka' => $item->unit?->name,
                'skladnik_ze_slownika' => $item->ingredient?->canonical_name,
                'uwaga' => $item->note,
            ])->all(),
            'kroki' => $recipe->steps->map(fn ($step): array => [
                // W bazie `position` liczy się od zera — w eksporcie numerujemy
                // kroki tak, jak czyta je człowiek: od jedynki.
                'numer' => $step->position + 1,
                'opis' => $step->instruction,
                'minutnik_sekundy' => $step->timer_seconds,
                'zdjecie' => $photos->pathFor($step->media_id),
            ])->all(),
            'ile_razy_ugotowany_przez_innych' => $recipe->cookedEvents()->count(),
            'komentarze' => $this->foreignComments($recipe->comments),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function posts(User $user, ExportPhotoPlan $photos): array
    {
        // Bez `published()` i bez filtra widoczności — wpis prywatny należy
        // do użytkownika dokładnie tak samo jak publiczny.
        $posts = $user->posts()
            ->with(['media', 'recipe', 'comments.replies.author.profile', 'comments.author.profile'])
            ->orderByRaw('coalesce(published_at, created_at)')
            ->get();

        return $posts->map(fn (Post $post): array => [
            'tresc' => $post->body,
            'widocznosc' => $post->visibility,
            'status' => $post->status,
            'utworzono' => $this->date($post->created_at),
            'opublikowano' => $this->date($post->published_at),
            'dotyczy_przepisu' => $post->recipe?->title,
            'zdjecia' => $post->media
                ->map(fn ($photo) => $photos->pathFor((string) $photo->getKey()))
                ->filter()
                ->values()
                ->all(),
            'komentarze' => $this->foreignComments($post->comments),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function cookedEvents(User $user, ExportPhotoPlan $photos): array
    {
        // `reorder` zamiast `orderBy`: relacja `cookedEvents()` ma już własne
        // sortowanie malejące, a dopisanie kolejnej kolumny by go nie zmieniło.
        $events = $user->cookedEvents()
            ->with(['media', 'recipe.author.profile', 'comments.replies.author.profile', 'comments.author.profile'])
            ->reorder('cooked_at')
            ->get();

        return $events->map(fn (CookedEvent $event): array => [
            'przepis' => $event->recipe?->title,
            'autor_przepisu' => $event->recipe?->author?->displayName(),
            'kiedy' => $this->date($event->cooked_at),
            'notatka' => $event->note,
            'zrobie_jeszcze_raz' => $event->would_make_again,
            'moja_ocena_trudnosci' => $event->perceived_difficulty,
            'ile_zajelo_minut' => $event->actual_minutes,
            'co_zmienilam' => $event->changes_note,
            'zdjecia' => $event->media
                ->map(fn ($photo) => $photos->pathFor((string) $photo->getKey()))
                ->filter()
                ->values()
                ->all(),
            'komentarze' => $this->foreignComments($event->comments),
        ])->all();
    }

    /**
     * Komentarze napisane PRZEZ użytkownika — także te pod cudzymi treściami.
     *
     * @return list<array<string, mixed>>
     */
    private function ownComments(User $user): array
    {
        $comments = Comment::query()
            ->where('author_id', $user->getKey())
            ->with(['post.author.profile', 'recipe.author.profile', 'cookedEvent.recipe', 'cookedEvent.user.profile'])
            ->orderBy('created_at')
            ->get();

        return $comments->map(function (Comment $comment) use ($user): array {
            $subject = $comment->subject();

            return [
                'tresc' => $comment->body,
                'napisano' => $this->date($comment->created_at),
                'status' => $comment->status,
                'pod_czym' => match (true) {
                    $subject instanceof Post => 'wpis: '.($subject->recipe?->title ?? mb_substr((string) $subject->body, 0, 60)),
                    $subject instanceof Recipe => 'przepis: '.$subject->title,
                    $subject instanceof CookedEvent => 'wykonanie przepisu: '.($subject->recipe?->title ?? '—'),
                    default => null,
                },
                'czyje_to_bylo' => $this->subjectOwnerName($subject, $user),
            ];
        })->all();
    }

    /**
     * Zeszyty — i OBIE rzeczy, które w nich stoją.
     *
     * Zeszyt przyjmuje dwa rodzaje pozycji, nie jeden: przepisy ORAZ wpisy
     * (migracja `2026_09_06_150000_collection_items_accept_posts`, akcja
     * `SavePostToCollection`, `docs/DATABASE.md` — „collection_items —
     * przepisy ORAZ wpisy"). Do 8 września paczka niosła wyłącznie przepisy
     * i robiła to po cichu: w `dane.json` nie było ani pozycji, ani liczby,
     * ani zdania o tym, że połowy zeszytu brakuje. Człowiek, który odłożył
     * czterdzieści cudzych zdjęć „na kiedyś", dostawał plik wyglądający na
     * kompletny — a taki jest gorszy niż brak eksportu.
     *
     * ZAPISANE WPISY PRZECHODZĄ PRZEZ TĘ SAMĄ GRANICĘ CO EKRAN ZESZYTU
     * `widoczneDla()` plus `dostepnyJakoAutor()` — dokładnie ten sam filtr,
     * który stoi w `CollectionController::show()`, i z tego samego powodu co
     * `visibleTo()` przy powiadomieniach niżej: **to jest ta sama granica,
     * nie druga jej wersja.** Zeszyt to pojemnik na CUDZE treści. Wpis
     * zapisany wtedy, gdy autor pokazywał go obserwującym, przestaje być
     * widoczny po zaprzestaniu obserwowania — a paczka ZIP zostaje na dysku
     * na zawsze i da się ją komuś wysłać. Wypisanie treści, której w serwisie
     * już nie widać, byłoby trwałym wyjęciem cudzego tekstu z jego ustawień.
     *
     * Własne wpisy przechodzą przez ten filtr ZAWSZE, także prywatne i szkice
     * (`Post::scopeWidoczneDla` zaczyna od `posts.author_id = widz`), więc
     * nic swojego nikomu tu nie ubywa.
     *
     * ILE FILTR SCHOWAŁ — MÓWIMY WPROST, TAK JAK EKRAN
     * `wpisow_juz_niewidocznych` jest ZAWSZE, także gdy wynosi zero — ta sama
     * reguła co przy `zdjec_jeszcze_w_przygotowaniu` (issue #113): klucz
     * pojawiający się tylko przy brakach zmusza czytającego do zgadywania,
     * czy zera nie ma, bo braków nie było, czy dlatego, że paczkę zbudowała
     * starsza wersja serwisu. Ekran zeszytu mówi „ile, nie czego" — paczka
     * mówi to samo.
     *
     * CZEGO TU NIE MA: ZDJĘĆ Z ZAPISANYCH WPISÓW
     * `ExportPhotoPlan` chodzi wyłącznie po `$user->media()`, więc zdjęcie
     * z cudzego wpisu nie ma w paczce nazwy pliku i `pathFor()` zwróciłby dla
     * niego `null`. To jest wybór, nie przeoczenie: paczka RODO oddaje dane
     * TEJ osoby, a cudze zdjęcie nią nie jest — pobranie go do archiwum
     * użytkownika wyjęłoby je z serwisu na stałe.
     *
     * @return list<array<string, mixed>>
     */
    private function collections(User $user): array
    {
        $collections = $user->collections()
            ->with([
                'recipes.author.profile',
                'posts' => fn ($zapytanie) => $zapytanie
                    ->widoczneDla($user)
                    ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
                    ->with('author.profile'),
            ])
            // Liczba WSZYSTKICH zapisanych wpisów, bez filtra widoczności.
            // Różnica między nią a liczbą wypisanych pozycji to dokładnie to,
            // co filtr schował — i to jest liczba, którą paczka podaje.
            ->withCount('posts')
            ->orderBy('created_at')
            ->get();

        return $collections->map(fn (Collection $collection): array => [
            'nazwa' => $collection->name,
            'opis' => $collection->description,
            'widocznosc' => $collection->visibility,
            'domyslna' => (bool) $collection->is_default,
            'utworzono' => $this->date($collection->created_at),
            'przepisy' => $collection->recipes->map(fn (Recipe $recipe): array => [
                'tytul' => $recipe->title,
                'autor' => $recipe->author?->displayName(),
                'moja_notatka' => $recipe->pivot->note ?? null,
                'zapisano' => $this->date($recipe->pivot->created_at ?? null),
            ])->all(),
            'wpisy' => $collection->posts->map(fn (Post $post): array => [
                // Wpis nie ma tytułu — jego treść JEST jego tożsamością,
                // więc skrócenie jej zostawiłoby pozycję nie do rozpoznania.
                // Zakres jest ten sam co przy cudzych komentarzach niżej:
                // treść, data i nazwa wyświetlana. Nigdy e-mail, nigdy
                // identyfikator konta.
                'tresc' => $post->body,
                'autor' => $post->author?->displayName() ?? 'Konto usunięte',
                'opublikowano' => $this->date($post->published_at),
                'moja_notatka' => $post->pivot->note ?? null,
                'zapisano' => $this->date($post->pivot->created_at ?? null),
            ])->all(),
            'wpisow_juz_niewidocznych' => max(
                0,
                (int) ($collection->posts_count ?? 0) - $collection->posts->count(),
            ),
        ])->all();
    }

    /**
     * Lista innych osób (obserwowani, obserwujący, zablokowani).
     *
     * Tylko nazwa wyświetlana i publiczna nazwa użytkownika — bez e-maila
     * i bez identyfikatora konta. Bez nazwy wyświetlanej lista byłaby
     * bezużyteczna („obserwujesz 34 osoby, nie powiemy które”).
     *
     * @param  iterable<User>  $users
     * @return list<array<string, mixed>>
     */
    private function people(iterable $users): array
    {
        $out = [];

        foreach ($users as $person) {
            $out[] = [
                'nazwa_wyswietlana' => $person->displayName(),
                'nazwa_uzytkownika' => $person->profile?->username,
            ];
        }

        return $out;
    }

    /**
     * Komentarze INNYCH osób pod treścią użytkownika.
     *
     * To jedyne miejsce, w którym do paczki trafiają cudze wypowiedzi.
     * Przepisujemy dokładnie trzy rzeczy: treść, datę i nazwę wyświetlaną.
     * Nic więcej — żadnego e-maila, żadnego identyfikatora.
     *
     * @param  iterable<Comment>  $comments
     * @return list<array<string, mixed>>
     */
    private function foreignComments(iterable $comments): array
    {
        $out = [];

        foreach ($comments as $comment) {
            $out[] = [
                'autor' => $comment->author?->displayName() ?? 'Konto usunięte',
                'tresc' => $comment->body,
                'napisano' => $this->date($comment->created_at),
                'odpowiedzi' => $this->foreignComments($comment->replies),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function notifications(User $user): array
    {
        // `visibleTo()` TAK SAMO JAK NA EKRANIE — to jest ta sama granica,
        // nie druga jej wersja.
        //
        // Bez tego paczka niosła powiadomienia, których człowiek w serwisie
        // NIE WIDZI: od kont zbanowanych, od kont po prośbie o usunięcie,
        // od osób wzajemnie zablokowanych, oraz o komentarzach pod treścią,
        // która zniknęła. Razem z nimi wychodziło pole `excerpt` — 120
        // znaków CUDZEGO tekstu, w tym tekstu z konta, które prosiło
        // o usunięcie.
        //
        // Paczka RODO ma oddać człowiekowi to, co jego — nie wszystko, co
        // o nim leży w bazie. Powiadomienie ukryte w serwisie nie staje się
        // jego danymi przez to, że kiedyś powstał na nie wiersz.
        $notifications = $user->notifications()
            ->visibleTo($user)
            ->with('actor.profile')
            ->reorder('created_at')
            ->get();

        return $notifications->map(function ($notification): array {
            $data = is_array($notification->data) ? $notification->data : [];

            return [
                'rodzaj' => $notification->type,
                'kiedy' => $this->date($notification->created_at),
                'przeczytane' => $notification->read_at !== null,
                'od_kogo' => $notification->actor?->displayName(),
                'szczegoly' => array_intersect_key($data, array_flip(self::NOTIFICATION_DATA_KEYS)),
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function photos(ExportPhotoPlan $plan): array
    {
        return $plan->photos()->map(fn ($photo): array => [
            'plik' => $plan->pathFor((string) $photo->getKey()),
            'opis_alternatywny' => $photo->alt_text,
            'wgrano' => $this->date($photo->created_at),
            'szerokosc' => $photo->width,
            'wysokosc' => $photo->height,
            'rozmiar_bajty' => $photo->bytes,
            'typ' => $photo->mime_type,
        ])->all();
    }

    private function subjectOwnerName(Post|Recipe|CookedEvent|null $subject, User $user): ?string
    {
        $owner = match (true) {
            $subject instanceof Post, $subject instanceof Recipe => $subject->author,
            $subject instanceof CookedEvent => $subject->user,
            default => null,
        };

        if ($owner === null) {
            return null;
        }

        return $owner->getKey() === $user->getKey() ? 'moje' : $owner->displayName();
    }

    /**
     * Data w ISO 8601. Przyjmuje też łańcuch, bo kolumny z tabel pośrednich
     * (`collection_items.created_at`) nie przechodzą przez casty modelu.
     */
    private function date(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date->toIso8601String();
        }

        try {
            return Carbon::parse((string) $date)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
