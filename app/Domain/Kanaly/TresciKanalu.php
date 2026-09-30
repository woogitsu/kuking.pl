<?php

declare(strict_types=1);

namespace App\Domain\Kanaly;

use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Domain\Media\DostepDoZdjecia;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Co trafia do kanału Atom profilu, tagu i zeszytu (#2227, D-333).
 *
 * KANAŁ POKAZUJE DOKŁADNIE TO, CO GOŚĆ NA TEJ SAMEJ STRONIE HTML
 * Czytnik kanałów nie ma konta, więc widz jest zawsze `null` — także wtedy,
 * gdy kanał otwiera zalogowana osoba (trasy stoją poza grupą `web`, bez
 * sesji). Zakresy są TE SAME, którymi liczy się strona dla gościa:
 *
 *  - profil: zakładka „Wszystko” (`ProfileController::postsFor()` dla
 *    obcego widza — `tylkoWidoczneWpisy()` z widzem `null`);
 *  - tag: lista `TagController::show()` (`tylkoPubliczne()` + `widoczneDla`);
 *  - zeszyt: `WidocznaZawartoscZeszytu` — ta sama klasa, którą liczy ekran.
 *
 * Zakresy profilu i tagu są prywatne w kontrolerach, więc stoją tu drugi
 * raz. Rozjazd łapie `KanalyAtomZgodneZeStronaTest`: dla tej samej macierzy
 * treści porównuje identyfikatory z kanału z tym, co gość widzi na stronie.
 *
 * Wejście do pojemnika (profil, zeszyt) rozstrzyga Policy w kontrolerze
 * kanału — ta klasa dostaje już pojemnik, który gość może otworzyć.
 *
 * ZDJĘCIE TYLKO PRZEZ `DostepDoZdjecia::moze(null, …)` — ta sama bramka co
 * trasa `/zdjecia/…`. Adres to trasa aplikacji (`Media::url()`), nigdy plik
 * w buckecie.
 */
final class TresciKanalu
{
    /** Najwyżej tyle wpisów w jednym kanale. Czytnik pamięta starsze sam. */
    public const LIMIT = 30;

    public function __construct(
        private readonly WidocznaZawartoscZeszytu $zawartosc = new WidocznaZawartoscZeszytu,
        private readonly DostepDoZdjecia $zdjecia = new DostepDoZdjecia,
    ) {}

    public function profil(User $wlasciciel): Kanal
    {
        $profil = $wlasciciel->profile;

        $wpisy = $wlasciciel->posts()
            ->published()
            ->enabledKinds()
            // Obcy widz, a gość tym bardziej, widzi tylko `public`
            // (`ProfileController::tylkoWidoczne()`).
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->zWidocznymPrzepisemAlboWlasnaTrescia(null)
            // Autor PRZEPISU, osobno od autora wpisu (W5-08) — jak na profilu.
            ->where(fn ($w) => $w->whereNull('posts.recipe_id')
                ->orWhere(fn ($tresc) => $tresc->zWlasnaTrescia())
                ->orWhereHas('recipe.author', fn ($autor) => $autor->dostepnyJakoAutor()))
            ->with(Post::RELACJE_KAFELKA)
            ->latest('published_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        Post::ukryjNiedostepnePrzepisy($wpisy, null);

        $nazwa = $wlasciciel->displayName();

        return new Kanal(
            id: 'urn:uuid:'.$wlasciciel->getKey(),
            tytul: $nazwa.' (@'.$profil?->username.') — Kuking',
            podtytul: 'Nowe wpisy od '.$nazwa.' w Kuking.',
            adresStrony: route('profile.show', $profil?->username),
            adresKanalu: route('kanaly.profil', $profil?->username),
            autorNazwa: $nazwa,
            autorAdres: route('profile.show', $profil?->username),
            zmieniono: $this->najpozniej([$profil?->updated_at], $wpisy->all()),
            pozycje: $wpisy->map(fn (Post $wpis) => $this->zWpisu($wpis))->all(),
        );
    }

    public function tag(Tag $tag): Kanal
    {
        $wpisy = Post::query()
            ->whereHas('tags', fn ($q) => $q->whereKey($tag->getKey()))
            // `TagController::tylkoPubliczne()` + `widoczneDla(null)`.
            ->publiclyVisible()
            ->tylkoOdAktywnychAutorow()
            ->zWidocznymPrzepisemAlboWlasnaTrescia(null)
            ->widoczneDla(null)
            ->with(Post::RELACJE_KAFELKA)
            ->latest('published_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        Post::ukryjNiedostepnePrzepisy($wpisy, null);

        return new Kanal(
            id: 'urn:uuid:'.$tag->getKey(),
            tytul: 'Tag „'.$tag->name.'” — Kuking',
            podtytul: 'Nowe wpisy z tagiem „'.$tag->name.'” w Kuking.',
            adresStrony: route('tags.show', $tag),
            adresKanalu: route('kanaly.tag', $tag->slug),
            autorNazwa: 'Kuking',
            autorAdres: route('landing'),
            zmieniono: $this->najpozniej([$tag->updated_at], $wpisy->all()),
            pozycje: $wpisy->map(fn (Post $wpis) => $this->zWpisu($wpis))->all(),
        );
    }

    /**
     * Zeszyt ma dwie listy (przepisy i wpisy), obie ułożone od ostatnio
     * dodanego do zeszytu — tak jak ekran. Kanał skleja je w jedną oś
     * po dacie dodania (`collection_items.created_at`) i tnie do limitu.
     * Notatka przy pozycji i to, kto ją dodał, NIE wychodzą: to dane
     * osób z dostępem (#978, D-302), nie treść zeszytu dla gościa.
     */
    public function zeszyt(Collection $zeszyt): Kanal
    {
        $przepisy = $this->zawartosc->przepisy($zeszyt, null)
            ->with(['author.profile', 'heroMedia'])
            ->limit(self::LIMIT)
            ->get();

        $wpisy = $this->zawartosc->wpisy($zeszyt, null)
            ->with(Post::RELACJE_KAFELKA)
            ->limit(self::LIMIT)
            ->get();

        Post::ukryjNiedostepnePrzepisy($wpisy, null);

        $pozycje = collect([...$przepisy->all(), ...$wpisy->all()])
            ->sortByDesc(fn (Recipe|Post $pozycja): string => $this->dodanoDoZeszytu($pozycja)->format('Y-m-d H:i:s.u').'|'.$pozycja->getKey())
            ->take(self::LIMIT)
            ->values();

        $wlasciciel = $zeszyt->owner;
        $opis = trim((string) $zeszyt->description);

        return new Kanal(
            id: 'urn:uuid:'.$zeszyt->getKey(),
            tytul: 'Zeszyt „'.$zeszyt->name.'” — Kuking',
            podtytul: $opis !== ''
                ? $opis
                : 'Przepisy i wpisy zebrane przez '.$wlasciciel?->displayName().' w Kuking.',
            adresStrony: route('collections.show', $zeszyt),
            adresKanalu: route('kanaly.zeszyt', $zeszyt),
            autorNazwa: (string) $wlasciciel?->displayName(),
            autorAdres: $this->adresOsoby($wlasciciel),
            zmieniono: $this->najpozniej(
                [$zeszyt->updated_at, ...$pozycje->map(fn ($p) => $this->dodanoDoZeszytu($p))->all()],
                $pozycje->all(),
            ),
            pozycje: $pozycje->map(fn (Recipe|Post $p) => $p instanceof Recipe ? $this->zPrzepisu($p) : $this->zWpisu($p))->all(),
        );
    }

    private function zWpisu(Post $wpis): PozycjaKanalu
    {
        // Relację `recipe` zdjął już `ukryjNiedostepnePrzepisy()`, gdy gość
        // nie może zobaczyć przepisu — wtedy nie ma ani tytułu, ani zdjęcia.
        $przepis = $wpis->recipe;
        $tresc = trim((string) $wpis->body);
        $autor = $wpis->author;

        $tytul = match (true) {
            $wpis->kind === Post::KIND_QUESTION && filled($wpis->title) => (string) $wpis->title,
            $tresc !== '' => Str::limit((string) Str::of($tresc)->explode("\n")->first(), 90),
            $przepis !== null => $przepis->title,
            default => 'Wpis od '.$autor?->displayName(),
        };

        $zdjecie = $wpis->media->first(fn (Media $m) => $this->zdjecia->moze(null, $m))
            ?? ($przepis?->heroMedia !== null && $this->zdjecia->moze(null, $przepis->heroMedia) ? $przepis->heroMedia : null);

        return new PozycjaKanalu(
            id: 'urn:uuid:'.$wpis->getKey(),
            tytul: $tytul,
            adres: $wpis->url(),
            opublikowano: $wpis->published_at,
            zmieniono: $this->pozniej($wpis->published_at, $wpis->updated_at),
            autorNazwa: (string) $autor?->displayName(),
            autorAdres: $this->adresOsoby($autor),
            streszczenie: $tresc !== '' ? $tresc : ($przepis !== null ? 'Przepis: '.$przepis->title : null),
            zdjecie: $zdjecie?->url('feed'),
        );
    }

    private function zPrzepisu(Recipe $przepis): PozycjaKanalu
    {
        $autor = $przepis->author;
        $zdjecie = $przepis->heroMedia !== null && $this->zdjecia->moze(null, $przepis->heroMedia)
            ? $przepis->heroMedia
            : null;

        return new PozycjaKanalu(
            id: 'urn:uuid:'.$przepis->getKey(),
            tytul: $przepis->title,
            adres: route('recipes.show', $przepis->slug),
            opublikowano: $przepis->published_at,
            zmieniono: $this->pozniej($przepis->published_at, $przepis->dataZmianyTresci()),
            autorNazwa: (string) $autor?->displayName(),
            autorAdres: $this->adresOsoby($autor),
            streszczenie: filled($przepis->summary) ? trim((string) $przepis->summary) : null,
            zdjecie: $zdjecie?->url('feed'),
        );
    }

    /**
     * Adres profilu tylko osoby, którą wolno pokazać jako osobę (D-022):
     * pod treścią konta wymazanego zostaje podpis bez odnośnika.
     */
    private function adresOsoby(?User $osoba): ?string
    {
        if ($osoba === null || ! $osoba->jestWidocznyJakoOsoba() || $osoba->profile === null) {
            return null;
        }

        return route('profile.show', $osoba->profile->username);
    }

    private function dodanoDoZeszytu(Recipe|Post $pozycja): CarbonInterface
    {
        return Carbon::parse($pozycja->getRelation('pivot')->getAttribute('created_at'));
    }

    private function pozniej(?CarbonInterface $a, ?CarbonInterface $b): CarbonInterface
    {
        return collect([$a, $b])->filter()->max() ?? now();
    }

    /**
     * Data zmiany kanału: najpóźniejsza z dat pojemnika i pozycji. Pusty
     * kanał dostaje datę pojemnika, żeby `<updated>` (obowiązkowe w Atom)
     * i `Last-Modified` nie skakały przy każdym odświeżeniu.
     *
     * @param  list<CarbonInterface|null>  $daty
     * @param  list<Post|Recipe>  $pozycje
     */
    private function najpozniej(array $daty, array $pozycje): CarbonInterface
    {
        foreach ($pozycje as $pozycja) {
            $daty[] = $pozycja->published_at;
            $daty[] = $pozycja instanceof Recipe ? $pozycja->dataZmianyTresci() : $pozycja->updated_at;
        }

        return collect($daty)->filter()->max() ?? now()->startOfDay();
    }
}
