<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Domain\Media\KasujZdjecie;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Support\ZabezpieczoneDowody;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Twarde usunięcie treści skasowanych przez autora (audyt B5, znalezisko 1).
 *
 * PO CO
 * „Usuń wpis”, „Usuń przepis” i „Usuń komentarz” robią `delete()` na modelu
 * z `SoftDeletes`. Człowiek widzi „Wpis usunięty.”, a w bazie zostaje pełny
 * tekst, a w R2 oryginał zdjęcia i wszystkie warianty. Do 25.09.2026 nic tych
 * wierszy nie kasowało — znikały dopiero przy wymazaniu konta z zakresem
 * „wszystko”, a sprzątacz osieroconych zdjęć ich nie zbierał, bo wiersz
 * `post_media` miękko usuniętego wpisu liczy się jako użycie
 * (`KasujZdjecie::jestUzywane()`).
 *
 * CO ROBI
 * Po `config('kuking.usuniete_tresci.retention_days')` dniach od `deleted_at`:
 *  - wpis: `forceDelete()` — kaskady zabierają `post_media`, komentarze pod
 *    nim, tagi, pozycje zeszytów i kafel kolażu;
 *  - komentarz: `forceDelete()`, gdy nie ma pod sobą żadnej odpowiedzi (także
 *    usuniętej) — odpowiedzi idą pierwsze, rodzic w kolejnym przebiegu;
 *  - przepis bez cudzych „Ugotowałem”: `forceDelete()`;
 *  - przepis, który ugotował ktoś inny: NAGROBEK (niżej).
 * Na koniec każde zdjęcie, które wskazywała usunięta treść, idzie przez
 * `KasujZdjecie::jesliNieuzywane()` — oryginał i warianty znikają z dysków
 * tej samej nocy. Gdy dysk zawiedzie, wiersz `media` zostaje i dobierze go
 * `kuking:sprzataj-osierocone-zdjecia` (to jego zwykły mechanizm ponowienia).
 *
 * CZEGO NIE RUSZA: TREŚCI Z MODERACJĄ
 * Moderacja zdejmuje treść tym samym miękkim usunięciem
 * (`ZdejmijZUrzedu`, decyzja ze zgłoszenia). Taka treść jest celem sprawy:
 * odwołanie i „cofam” (`RestoreContent`) muszą mieć co przywrócić, a
 * zgłaszający — do czego wrócić. Dlatego kandydatem nie jest treść, na którą
 * (albo na której komentarz, zdjęcie czy wykonanie) wskazuje JAKIKOLWIEK
 * wiersz `reports` albo `moderation_actions`. Retencja spraw
 * (`kuking:sprzataj-sprawy-moderacyjne`, 36 miesięcy) zabiera je po czasie
 * i wtedy treść sama wraca do tej kolejki — bez drugiej listy wyjątków.
 * Sprawdzamy szeroko, a nie tylko decyzję `remove`: zgłoszenie otwarte
 * w chwili, gdy autor skasował wpis, też potrzebuje celu.
 *
 * NAGROBEK PRZEPISU
 * `cooked_events.recipe_id` ma `ON DELETE CASCADE`. `forceDelete()` przepisu
 * zabrałby cudze „Ugotowałem” razem z ich notatkami i zdjęciami — dorobek
 * kucharza (AGENTS.md §1), o którego usunięcie nikt nie prosił. Takiego
 * przepisu nie kasujemy, tylko opróżniamy: tytuł zastępuje „Przepis
 * usunięty”, adres dostaje stały, pusty znacznik, a opis, źródło, zdjęcia,
 * składniki, kroki, wersje, stare adresy, komentarze i pozycje zeszytów
 * znikają. Zostaje wiersz z `deleted_at` — sam klucz, na który wskazują
 * cudze wykonania. Gdy ostatnie cudze wykonanie zniknie, kolejny przebieg
 * zrobi już zwykłe `forceDelete()`.
 *
 * BUDŻET
 * Najwyżej `BUDZET_PRZEBIEGU` treści każdego rodzaju na noc, najstarsze
 * pierwsze. Liczą się tylko treści rozpatrzone do usunięcia — chronione
 * moderacją nie zjadają budżetu (#2250, `stronami()`). Każda treść w osobnej transakcji: błąd jednej zostawia ją na
 * następną noc i nie blokuje reszty.
 */
final class PrzedawnioneUsunieteTresci
{
    public const BUDZET_PRZEBIEGU = 500;

    public const TYTUL_NAGROBKA = 'Przepis usunięty';

    public function __construct(private readonly KasujZdjecie $kasujZdjecie) {}

    /**
     * @return array{wpisy: int, przepisy: int, nagrobki: int, komentarze: int, zdjecia: int, dni: int}
     */
    public function posprzataj(int $dni, bool $naSucho = false): array
    {
        $dni = max(1, $dni);
        $prog = now()->subDays($dni);

        $wynik = ['wpisy' => 0, 'przepisy' => 0, 'nagrobki' => 0, 'komentarze' => 0, 'zdjecia' => 0, 'dni' => $dni];
        $zdjecia = [];

        $rozpatrzone = 0;
        foreach ($this->stronami($this->kandydaciKomentarzy($prog)) as $komentarz) {
            if ($this->zModeracja('comment', [$komentarz->getKey()])) {
                continue;
            }

            if (++$rozpatrzone > self::BUDZET_PRZEBIEGU) {
                break;
            }

            if (! $naSucho && ! $this->bezpiecznie(fn () => $komentarz->forceDelete(), 'comment', $komentarz->getKey(), fn () => $this->zModeracja('comment', [$komentarz->getKey()]))) {
                continue;
            }

            $wynik['komentarze']++;
        }

        $rozpatrzone = 0;
        foreach ($this->stronami($this->kandydaciWpisow($prog)) as $wpis) {
            $media = DB::table('post_media')->where('post_id', $wpis->getKey())->pluck('media_id')->all();

            if ($this->wpisZModeracja($wpis, $media)) {
                continue;
            }

            if (++$rozpatrzone > self::BUDZET_PRZEBIEGU) {
                break;
            }

            if (! $naSucho && ! $this->bezpiecznie(fn () => $wpis->forceDelete(), 'post', $wpis->getKey(), fn () => $this->wpisZModeracja($wpis, $media))) {
                continue;
            }

            $wynik['wpisy']++;
            array_push($zdjecia, ...$media);
        }

        $rozpatrzone = 0;
        foreach ($this->stronami($this->kandydaciPrzepisow($prog)) as $przepis) {
            $media = $this->zdjeciaPrzepisu($przepis);

            if ($this->przepisZModeracja($przepis, $media)) {
                continue;
            }

            if (++$rozpatrzone > self::BUDZET_PRZEBIEGU) {
                break;
            }

            $cudzeWykonania = $przepis->cookedEvents()->where('user_id', '!=', $przepis->author_id)->exists();

            if (! $naSucho) {
                $udane = $this->bezpiecznie(
                    fn () => $cudzeWykonania ? $this->zamienWNagrobek($przepis) : $przepis->forceDelete(),
                    'recipe',
                    $przepis->getKey(),
                    fn () => $this->przepisZModeracja($przepis, $media),
                );

                if (! $udane) {
                    continue;
                }
            }

            $wynik[$cudzeWykonania ? 'nagrobki' : 'przepisy']++;
            array_push($zdjecia, ...$media);
        }

        if (! $naSucho) {
            foreach (Media::query()->whereKey(array_values(array_unique($zdjecia)))->get() as $zdjecie) {
                if ($this->kasujZdjecie->jesliNieuzywane($zdjecie)) {
                    $wynik['zdjecia']++;
                }
            }
        }

        return $wynik;
    }

    /** Stały, pusty adres nagrobka — nie niesie ani słowa z tytułu. */
    public static function slugNagrobka(string $id): string
    {
        return 'usuniety-przepis-'.str_replace('-', '', $id);
    }

    /** @return Builder<Comment> */
    private function kandydaciKomentarzy(\DateTimeInterface $prog): Builder
    {
        return Comment::onlyTrashed()
            ->where('deleted_at', '<', $prog)
            // Rodzic z odpowiedziami czeka: kaskada `parent_id` zabrałaby
            // odpowiedź, której termin jeszcze nie minął.
            ->whereNotExists(fn ($q) => $q->from('comments as dzieci')->whereColumn('dzieci.parent_id', 'comments.id'))
            ->orderBy('deleted_at')
            ->orderBy('id');
    }

    /** @return Builder<Post> */
    private function kandydaciWpisow(\DateTimeInterface $prog): Builder
    {
        return Post::onlyTrashed()
            ->where('deleted_at', '<', $prog)
            ->orderBy('deleted_at')
            ->orderBy('id');
    }

    /** @return Builder<Recipe> */
    private function kandydaciPrzepisow(\DateTimeInterface $prog): Builder
    {
        return Recipe::onlyTrashed()
            ->where('deleted_at', '<', $prog)
            // Nagrobek, pod którym wciąż są cudze wykonania, nie ma już czego
            // oddać — nie zajmuje miejsca w budżecie każdej nocy.
            ->where(fn (Builder $q) => $q
                ->whereRaw("slug <> 'usuniety-przepis-' || replace(id::text, '-', '')")
                ->orWhereNotExists(fn ($w) => $w->from('cooked_events')
                    ->whereColumn('cooked_events.recipe_id', 'recipes.id')
                    ->whereColumn('cooked_events.user_id', '!=', 'recipes.author_id')))
            ->orderBy('deleted_at')
            ->orderBy('id');
    }

    /**
     * Kandydaci stronami po `(deleted_at, id)`, aż zabraknie wierszy (#2250).
     *
     * Do poprawki zapytanie kończyło się `limit(500)` PRZED filtrem
     * moderacji. 500 najstarszych treści objętych sprawą zajmowało cały
     * budżet każdej nocy i nic za nimi nie było już pobierane. Teraz budżet
     * liczy wyłącznie treści faktycznie rozpatrzone do usunięcia (licznik
     * w `posprzataj()`), a chronione są tylko przewijane. Filtr ochrony
     * zostaje w PHP — jedna definicja „z moderacją", bez kopii w SQL.
     *
     * Kursor, nie `offset`: usunięcie wierszy ze strony nie przesuwa
     * następnej. `deleted_at` bierzemy surowo z bazy, z mikrosekundami —
     * Carbon obciąłby je do sekund i strona wróciłaby do tych samych wierszy.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $zapytanie
     * @return \Generator<int, T>
     */
    private function stronami(Builder $zapytanie): \Generator
    {
        $po = null;

        do {
            $strona = (clone $zapytanie)
                ->when($po !== null, fn (Builder $q) => $q->whereRaw('(deleted_at, id) > (?::timestamptz, ?::uuid)', $po))
                ->limit(self::BUDZET_PRZEBIEGU)
                ->get();

            foreach ($strona as $wiersz) {
                yield $wiersz;
            }

            $ostatni = $strona->last();
            $po = $ostatni === null ? null : [(string) $ostatni->getRawOriginal('deleted_at'), (string) $ostatni->getKey()];
        } while ($strona->count() === self::BUDZET_PRZEBIEGU);
    }

    /** @return list<string> */
    private function zdjeciaPrzepisu(Recipe $przepis): array
    {
        return array_values(array_filter([
            $przepis->hero_media_id,
            $przepis->source_scan_media_id,
            ...DB::table('recipe_steps')->where('recipe_id', $przepis->getKey())->whereNotNull('media_id')->pluck('media_id')->all(),
            // Własne wykonania autora odchodzą kaskadą razem z przepisem.
            // Przy nagrobku zostają, a wtedy `jesliNieuzywane()` ich zdjęć
            // nie ruszy — wskazuje na nie wciąż `cooked_event_media`.
            ...DB::table('cooked_event_media')
                ->join('cooked_events', 'cooked_events.id', '=', 'cooked_event_media.cooked_event_id')
                ->where('cooked_events.recipe_id', $przepis->getKey())
                ->where('cooked_events.user_id', $przepis->author_id)
                ->pluck('cooked_event_media.media_id')->all(),
        ]));
    }

    /** @param list<string> $media */
    private function wpisZModeracja(Post $wpis, array $media): bool
    {
        return $this->zModeracja('post', [$wpis->getKey()])
            || $this->zModeracja('comment', Comment::withTrashed()->where('post_id', $wpis->getKey())->pluck('id')->all())
            || $this->zModeracja('media', $media);
    }

    /** @param list<string> $media */
    private function przepisZModeracja(Recipe $przepis, array $media): bool
    {
        return $this->zModeracja('recipe', [$przepis->getKey()])
            || $this->zModeracja('comment', Comment::withTrashed()
                ->where('recipe_id', $przepis->getKey())
                ->orWhereIn('cooked_event_id', $przepis->cookedEvents()->pluck('id')->all())
                ->pluck('id')->all())
            || $this->zModeracja('cooked_event', $przepis->cookedEvents()->pluck('id')->all())
            // `zamienWNagrobek()` kasuje wersje przepisu, a zgłoszenie albo
            // decyzja o jednej wersji (#2390, #2270) jest dowodem w sprawie.
            || $this->zModeracja('recipe_version', DB::table('recipe_versions')->where('recipe_id', $przepis->getKey())->pluck('id')->all())
            // Wskazówki od gotujących znikają kaskadą razem z przepisem, a ich
            // zgłoszenie albo ukrycie (#2352) jest dowodem w sprawie.
            || $this->zModeracja('recipe_hint', DB::table('recipe_hints')->where('recipe_id', $przepis->getKey())->pluck('id')->all())
            || $this->zModeracja('media', $media);
    }

    /** @param list<string> $id */
    private function zModeracja(string $typ, array $id): bool
    {
        if ($id === []) {
            return false;
        }

        // ZABEZPIECZONY DOWÓD (ścieżka CSAM, D-333) nie zależy od retencji
        // spraw: gdy `kuking:sprzataj-sprawy-moderacyjne` zabierze wiersze
        // `reports`/`moderation_actions`, rejestr dalej trzyma treść.
        if (ZabezpieczoneDowody::dotyczy($typ, $id)) {
            return true;
        }

        foreach (['reports', 'moderation_actions'] as $tabela) {
            if (DB::table($tabela)->where('target_type', $typ)->whereIn('target_id', $id)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function zamienWNagrobek(Recipe $przepis): void
    {
        DB::transaction(function () use ($przepis): void {
            $id = $przepis->getKey();

            DB::table('recipe_ingredients')->where('recipe_id', $id)->delete();
            DB::table('recipe_steps')->where('recipe_id', $id)->delete();
            DB::table('recipe_versions')->where('recipe_id', $id)->delete();
            DB::table('recipe_slug_redirects')->where('recipe_id', $id)->delete();
            DB::table('collection_items')->where('recipe_id', $id)->delete();
            Comment::withTrashed()->where('recipe_id', $id)->forceDelete();

            $przepis->forceFill([
                'title' => self::TYTUL_NAGROBKA,
                'slug' => self::slugNagrobka($id),
                'summary' => null,
                'servings' => null,
                'prep_minutes' => null,
                'cook_minutes' => null,
                'difficulty' => null,
                'hero_media_id' => null,
                'source_type' => 'own',
                'source_url' => null,
                'source_person' => null,
                'source_note' => null,
                'family_since_year' => null,
                'source_scan_media_id' => null,
                'klucz_wyslania' => null,
            ])->saveQuietly();
        });
    }

    /**
     * Jedna treść w jednej transakcji — z PONOWNYM sprawdzeniem ochrony pod
     * blokadą wiersza treści.
     *
     * Sprawdzenie w pętli (`zModeracja()`) odbywa się BEZ blokady, a potem
     * idzie `forceDelete()`. W tym oknie moderator może zabezpieczyć treść
     * jako dowód (`ZabezpieczDowodCsam` bierze ten sam wiersz `FOR UPDATE`
     * i w tej samej transakcji dopisuje rejestr) albo ktoś może ją zgłosić.
     * Dlatego: najpierw wiersz `FOR UPDATE`, dopiero potem
     * `$chronione()` — widzi zatwierdzone zabezpieczenie, a gdy to my byliśmy
     * pierwsi, zabezpieczenie zastanie treść już usuniętą i odmówi.
     *
     * @param  (callable(): bool)|null  $chronione  `true` = treść objęta sprawą lub dowodem
     */
    private function bezpiecznie(callable $krok, string $typ, string $id, ?callable $chronione = null): bool
    {
        try {
            return DB::transaction(function () use ($krok, $typ, $id, $chronione): bool {
                $tabela = ['comment' => 'comments', 'post' => 'posts', 'recipe' => 'recipes'][$typ] ?? null;

                if ($tabela !== null) {
                    DB::table($tabela)->where('id', $id)->lockForUpdate()->value('id');
                }

                if ($chronione !== null && $chronione()) {
                    return false;
                }

                $krok();

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('Twarde usunięcie treści nie powiodło się; spróbujemy następnej nocy.', [
                'typ' => $typ,
                'id' => $id,
                'wyjatek' => $e::class,
            ]);

            return false;
        }
    }
}
