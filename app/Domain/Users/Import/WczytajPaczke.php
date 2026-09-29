<?php

declare(strict_types=1);

namespace App\Domain\Users\Import;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WczytanaZPaczki;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Zapis treści wybranych w podglądzie paczki (issue #1985, etap 2).
 *
 * Wejściem jest `PodgladPaczki` — wynik `PodgladPaczkiEksportu`, czyli treść już
 * sprawdzona (granice pól, moderacja, własność). Ta akcja niczego z pliku nie
 * czyta drugi raz i niczego nie interpretuje: tylko tworzy.
 *
 * ZASADY
 *
 * - Właścicielem jest `$user` — nie ma w tej akcji żadnego miejsca, w które
 *   wpadłby identyfikator autora z paczki. Wejście przez Policy
 *   (`WczytanaZPaczkiPolicy::create`).
 * - Wszystko jest PRYWATNE: przepis to szkic z widocznością „private” (przez
 *   `PublishRecipe`, z `publish: false`, jak każdy szkic), wpis — „private”
 *   (widzi go tylko autor; o publikacji decyduje potem edycja wpisu), zeszyt —
 *   „private”. Widoczność i status są wpisane tu na sztywno, nie z paczki.
 * - Tworzone są wyłącznie pozycje w stanie `NOWA` i wskazane przez `odcisk`.
 *   Pozycja „już jest”, powtórzona w paczce albo odrzucona nie zostanie
 *   utworzona, choćby ktoś podsunął jej odcisk w żądaniu.
 * - IDEMPOTENCJA: `wczytane_z_paczki` ma `UNIQUE (user_id, odcisk)`. Drugie
 *   wczytanie tej samej paczki — także dwa żądania naraz — niczego nie dubluje.
 *   Ślad po treści skasowanej miękko nie blokuje ponownego wczytania.
 * - PARTIE: jedno wywołanie tworzy najwyżej `config('kuking.import_paczki.max_naraz')`
 *   pozycji, każdą we własnej transakcji. Reszta zostaje w podglądzie „nowa”,
 *   więc kolejne wczytanie kończy robotę, a przerwane w połowie niczego nie psuje.
 * - Do dziennika trafiają WYŁĄCZNIE liczby, nigdy treść paczki.
 */
final class WczytajPaczke
{
    public function __construct(private readonly PublishRecipe $publishRecipe) {}

    /**
     * @param  list<string>  $odciski  odciski pozycji, które człowiek zaznaczył w podglądzie
     */
    public function handle(User $user, PodgladPaczki $podglad, array $odciski, ?string $ip = null): WynikWczytania
    {
        Gate::forUser($user)->authorize('create', WczytanaZPaczki::class);

        $wybrane = array_fill_keys($odciski, true);
        $limit = max(1, (int) config('kuking.import_paczki.max_naraz'));

        $doZrobienia = array_values(array_filter(
            [...$podglad->przepisy, ...$podglad->wpisy, ...$podglad->zeszyty],
            static fn (PozycjaPodgladu $p): bool => isset($wybrane[$p->odcisk]) && $p->mozeBycUtworzona(),
        ));

        $zostalo = max(0, count($doZrobienia) - $limit);
        $utworzone = [WczytanaZPaczki::RODZAJ_PRZEPIS => 0, WczytanaZPaczki::RODZAJ_WPIS => 0, WczytanaZPaczki::RODZAJ_ZESZYT => 0];
        $jusByly = 0;
        /** @var list<string> $niewczytane */
        $niewczytane = [];

        foreach (array_slice($doZrobienia, 0, $limit) as $pozycja) {
            try {
                $wczytana = DB::transaction(fn (): bool => $this->wczytaj($user, $pozycja));
            } catch (UniqueConstraintViolationException) {
                // Drugie równoległe żądanie było szybsze albo człowiek w międzyczasie
                // założył zeszyt o tej nazwie — transakcja pozycji jest wycofana.
                $jusByly++;

                continue;
            } catch (BladDlaCzlowieka $e) {
                $niewczytane[] = $pozycja->tytul.' — '.$e->getMessage();

                continue;
            }

            if ($wczytana) {
                $utworzone[$pozycja->rodzaj]++;
            } else {
                $jusByly++;
            }
        }

        $wynik = new WynikWczytania($utworzone, $jusByly, $niewczytane, $zostalo);

        // Ślad pomocniczy (D-249, klasa 2): autorytatywny jest wiersz w
        // `wczytane_z_paczki` przy każdej treści. Same liczby, bez treści.
        AuditLogEntry::recordBezWywracania('data.import_completed', $user, $user, [
            'wersja_formatu' => $podglad->wersjaFormatu,
            'przepisy' => $utworzone[WczytanaZPaczki::RODZAJ_PRZEPIS],
            'wpisy' => $utworzone[WczytanaZPaczki::RODZAJ_WPIS],
            'zeszyty' => $utworzone[WczytanaZPaczki::RODZAJ_ZESZYT],
            'juz_bylo' => $jusByly,
            'niewczytane' => count($niewczytane),
            'zostalo' => $zostalo,
        ], $ip);

        return $wynik;
    }

    /**
     * @return bool `true` — utworzono; `false` — ta pozycja z tej paczki już żyje na koncie
     */
    private function wczytaj(User $user, PozycjaPodgladu $pozycja): bool
    {
        // Serializuje wczytania jednej osoby: dwa żądania naraz ustawiają się w
        // kolejce, a drugie widzi ślad pierwszego. `UNIQUE` zostaje siatką pod spodem.
        User::query()->whereKey($user->getKey())->lock('FOR NO KEY UPDATE')->firstOrFail();

        if ($this->maZywySlad($user, $pozycja->odcisk)) {
            return false;
        }

        DB::table('wczytane_z_paczki')
            ->where('user_id', $user->getKey())
            ->where('odcisk', $pozycja->odcisk)
            ->delete();

        $wczytana = new WczytanaZPaczki;

        match ($pozycja->rodzaj) {
            'przepis' => $wczytana->recipe_id = (string) $this->utworzPrzepis($user, $pozycja->dane)->getKey(),
            'wpis' => $wczytana->post_id = (string) $this->utworzWpis($user, $pozycja->dane)->getKey(),
            default => $wczytana->collection_id = (string) $this->utworzZeszyt($user, $pozycja->dane)->getKey(),
        };

        $wczytana->user_id = (string) $user->getKey();
        $wczytana->rodzaj = $pozycja->rodzaj;
        $wczytana->odcisk = $pozycja->odcisk;
        $wczytana->save();

        return true;
    }

    /** Ślad, za którym stoi żywa treść (nieskasowana miękko). */
    private function maZywySlad(User $user, string $odcisk): bool
    {
        return DB::table('wczytane_z_paczki as w')
            ->leftJoin('recipes as r', 'r.id', '=', 'w.recipe_id')
            ->leftJoin('posts as p', 'p.id', '=', 'w.post_id')
            ->where('w.user_id', $user->getKey())
            ->where('w.odcisk', $odcisk)
            ->where(static function ($q): void {
                $q->where(static fn ($z) => $z->whereNotNull('w.recipe_id')->whereNull('r.deleted_at'))
                    ->orWhere(static fn ($z) => $z->whereNotNull('w.post_id')->whereNull('p.deleted_at'))
                    ->orWhereNotNull('w.collection_id');
            })
            ->exists();
    }

    /** @param  array<string, mixed>  $dane */
    private function utworzPrzepis(User $user, array $dane): Recipe
    {
        return $this->publishRecipe->handle(
            author: $user,
            attributes: [
                'title' => $dane['tytul'],
                'summary' => $dane['opis'],
                'visibility' => 'private',
                'source_type' => Recipe::SOURCE_OWN,
            ],
            ingredients: $dane['skladniki'],
            steps: $dane['kroki'],
            publish: false,
        );
    }

    /** @param  array<string, mixed>  $dane */
    private function utworzWpis(User $user, array $dane): Post
    {
        $post = new Post([
            'author_id' => $user->getKey(),
            'body' => $dane['tresc'],
            'visibility' => Post::VISIBILITY_PRIVATE,
            'status' => Post::STATUS_PUBLISHED,
            'display_mode' => Post::DISPLAY_NORMAL,
            'published_at' => now(),
        ]);

        // Pytań nie tworzymy (podgląd je odrzuca): pytanie w Poradźcie jest zawsze publiczne.
        $post->save();

        return $post;
    }

    /** @param  array<string, mixed>  $dane */
    private function utworzZeszyt(User $user, array $dane): Collection
    {
        return Collection::query()->create([
            'owner_id' => $user->getKey(),
            'name' => $dane['nazwa'],
            'description' => $dane['opis'],
            'visibility' => 'private',
            'is_default' => false,
        ]);
    }
}
