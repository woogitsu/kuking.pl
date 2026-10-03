<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Domain\Compliance\PrzedawnioneUsunieteTresci;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use App\Support\KursorListy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Autor odzyskuje własny, omyłkowo usunięty przepis, dopóki trwa retencja
 * (issue #2620, decyzja właściciela z 2.10.2026, D-333).
 *
 * CO TO JEST
 * „Usuń przepis" robi `delete()` na modelu z `SoftDeletes` (`RecipeController`).
 * Treść leży w bazie przez `kuking.usuniete_tresci.retention_days` dni od
 * `deleted_at`, potem zabiera ją `PrzedawnioneUsunieteTresci` (ADR retencji
 * §5.7: okno „służy odkręceniu pomyłki"). Ta klasa daje autorowi drogę do
 * odkręcenia — w TYM SAMYM oknie. Niczego nie wydłuża i nie kopiuje.
 *
 * DO CZEGO WRACA
 * Do PRYWATNEGO SZKICU (`draft`, `published_at` puste, widoczność „tylko ja").
 * Odzyskanie nie publikuje niczego: przepis nie wraca do feedu, Atom,
 * wyników wyszukiwania ani cudzych zeszytów, dopóki autor nie sprawdzi go
 * w kreatorze i nie opublikuje świadomie. Widoczność ustawiamy na „tylko ja"
 * także wtedy, gdy była szersza — wariant najbezpieczniejszy dla
 * prywatności; autor wybiera ją ponownie przy publikacji.
 *
 * KTÓRE PRZEPISY MOŻNA ODZYSKAĆ (wszystkie warunki naraz, pod blokadą)
 *  - konto autora jest AKTYWNE (zawieszone, zbanowane, w trakcie usuwania i
 *    wymazane nie odzyskują — to jest pisanie);
 *  - przepis należy do tego autora i jest miękko usunięty;
 *  - nie minął termin retencji (`deleted_at` nowsze niż teraz minus dni);
 *  - to nie nagrobek (`PrzedawnioneUsunieteTresci::zamienWNagrobek`) — nagrobek
 *    jest opróżniony i nie ma czego oddać, a tytułu ani składników nikt nie
 *    zgaduje;
 *  - status to `draft` albo `published`. `hidden` i `removed` to decyzja
 *    moderacji, której autor nie cofa (`RecipeStatusTransitions`);
 *  - żaden wiersz `reports` ani `moderation_actions` nie wskazuje przepisu,
 *    jego komentarzy, wykonań, wersji ani zdjęć (ta sama definicja co w
 *    sprzątaniu: `PrzedawnioneUsunieteTresci::przepisZModeracja()`).
 *
 * SKĄD WIADOMO, ŻE USUNĄŁ AUTOR
 * `deleted_at` nie mówi, kto usuwał — moderacja usuwa tym samym miękkim
 * usunięciem. Moderacja ZAWSZE zostawia wiersz `moderation_actions`
 * (`ZdejmijZUrzedu`, `RozstrzygnijZgloszenie`, `DecyzjaPoOdwolaniu`), więc
 * brak jakiegokolwiek wiersza sprawy jest dowodem, że usunięcie było
 * zwykłym „Usuń" autora. Gdy sprawa jest, odmawiamy: konserwatywnie i bez
 * schematu, bez nowej kolumny z przyczyną.
 *
 * BLOKADA I WYŚCIGI (D-079: `users` przed `recipes`)
 * Wiersz konta, potem wiersz przepisu — ta sama kolejność co `PublishRecipe`
 * i `EraseAccountData`. Pod blokadą stan jest sprawdzany od nowa. Wymazanie
 * konta, które wygra, zostawia konto nieaktywne (odmowa); to, które
 * przegra, kasuje już odzyskany szkic zgodnie z wnioskiem właściciela.
 * Sprzątanie przedawnionych treści czyta ten sam wiersz pod blokadą i
 * pomija przepis, który przestał być usunięty. Dwa równoległe wysłania
 * dają jeden rezultat: drugie zastaje przepis już odzyskany i mówi to wprost.
 *
 * CO WRACA, A CO NIE
 * Wraca to, co nadal istnieje: tytuł, opis, składniki, kroki, źródło, historia
 * wersji i zdjęcia, które są w pełni gotowe i należą do autora. Cudze
 * „Ugotowałem" nigdy nie znikały i zostają. Zdjęcie odrzucone, przejęte do
 * skasowania albo cudze jest odpinane, a liczba odpiętych wraca w wyniku —
 * ekran mówi wprost, czego brakuje. Nic nie jest odtwarzane z domysłu.
 *
 * ADRES (SLUG)
 * `recipes.slug` jest UNIQUE razem z wierszami usuniętymi, a
 * `GenerateRecipeSlug` liczy `withTrashed()`. Nowy przepis o tym samym
 * tytule dostaje więc `-2`, a odzyskany wraca pod swój dawny adres — kolizja
 * nie może zajść. Szkic i tak dostaje nowy slug przy pierwszym zapisie
 * tytułu w kreatorze (reguła szkiców), a stary adres działa wtedy jako
 * przekierowanie.
 */
final class OdzyskajUsunietyPrzepis
{
    public function __construct(private readonly PrzedawnioneUsunieteTresci $retencja) {}

    /** Ile dni od usunięcia trwa okno — ta sama liczba co w sprzątaniu. */
    public static function dniOkna(): int
    {
        return max(1, (int) config('kuking.usuniete_tresci.retention_days'));
    }

    /** Ostatnia chwila, do której przepis da się odzyskać (potem czeka na nocne sprzątanie). */
    public static function termin(Recipe $przepis): CarbonInterface
    {
        /** @var CarbonInterface $usuniety */
        $usuniety = $przepis->deleted_at;

        return $usuniety->copy()->addDays(self::dniOkna());
    }

    /**
     * Przepisy tego autora, które mogą być do odzyskania: usunięte w oknie,
     * nie nagrobki, nie zdjęte decyzją moderacji. Sama lista — sprawy
     * moderacyjne odsiewa `dlaEkranu()`, a `handle()` sprawdza wszystko
     * ponownie pod blokadą.
     *
     * @return Builder<Recipe>
     */
    public function kandydaci(User $autor): Builder
    {
        return Recipe::onlyTrashed()
            ->where('author_id', $autor->getKey())
            ->where('deleted_at', '>', now()->subDays(self::dniOkna()))
            ->whereIn('status', [Recipe::STATUS_DRAFT, Recipe::STATUS_PUBLISHED])
            ->whereRaw("slug <> 'usuniety-przepis-' || replace(id::text, '-', '')")
            ->orderByDesc('deleted_at')
            ->orderByDesc('id');
    }

    /**
     * Lista dla ekranu „Usunięte przepisy": tylko to, co da się teraz odzyskać.
     * Przepisów objętych sprawą moderacyjną nie pokazujemy ani nie nazywamy —
     * ekran ma jedno zdanie o tym, dlaczego czegoś może brakować.
     *
     * Skanuje ograniczone porcje kandydatów, aż zbierze 51 dostępnych albo
     * dojdzie do końca. Limit strony działa PO filtrze moderacji; pamięć nie
     * rośnie wraz z liczbą chronionych przepisów (#2868).
     *
     * @return array{przepisy: Collection<int, Recipe>, nastepny: ?string, dalsza: bool}
     */
    public function dlaEkranu(User $autor, ?string $od = null): array
    {
        $po = self::odczytajKursor($od);
        $przepisy = new Collection;

        if (! $autor->isActive()) {
            return ['przepisy' => $przepisy, 'nastepny' => null, 'dalsza' => false];
        }

        $skan = $po;

        do {
            $partia = $this->kandydaci($autor)
                ->when($skan !== null, fn (Builder $q) => $q->whereRaw(
                    '(recipes.deleted_at, recipes.id) < (?::timestamptz, ?::uuid)',
                    $skan,
                ))
                ->limit(50)
                ->get();

            foreach ($partia as $przepis) {
                if ($this->wSprawieModeracyjnej($przepis)) {
                    continue;
                }

                if ($przepisy->count() === 50) {
                    /** @var Recipe $ostatni */
                    $ostatni = $przepisy->last();

                    return ['przepisy' => $przepisy, 'nastepny' => self::kursor($ostatni), 'dalsza' => $po !== null];
                }

                $przepisy->push($przepis);
            }

            $ostatniSkanowany = $partia->last();
            if ($ostatniSkanowany !== null) {
                $skan = [self::czasKursora($ostatniSkanowany), (string) $ostatniSkanowany->getKey()];
            }
        } while ($partia->count() === 50);

        return ['przepisy' => $przepisy, 'nastepny' => null, 'dalsza' => $po !== null];
    }

    private static function kursor(Recipe $przepis): string
    {
        return self::czasKursora($przepis).'_'.$przepis->getKey();
    }

    private static function czasKursora(Recipe $przepis): string
    {
        return $przepis->deleted_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @return array{0: string, 1: string}|null */
    private static function odczytajKursor(?string $od): ?array
    {
        if ($od === null || strlen($od) > 80
            || preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z)_([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/D', $od, $m) !== 1
            || ! KursorListy::czasPasuje($m[1])) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * @throws BladDlaCzlowieka gdy przepisu nie wolno albo nie da się odzyskać
     */
    public function handle(User $autor, string $przepisId): WynikOdzyskania
    {
        return DB::transaction(function () use ($autor, $przepisId): WynikOdzyskania {
            // Konto pod blokadą (`FOR NO KEY UPDATE`, jak w `PublishRecipe`):
            // wymazanie albo ban zatwierdzone w międzyczasie jest widoczne.
            $konto = User::query()->whereKey($autor->getKey())->lock('FOR NO KEY UPDATE')->first();

            if ($konto === null || ! $konto->isActive()) {
                throw new BladDlaCzlowieka(
                    'Stan Twojego konta zmienił się, więc nie odzyskamy teraz przepisu. '
                    .'Jeśli to pomyłka, napisz do nas przez „Napisz do nas”.',
                );
            }

            $przepis = Recipe::withTrashed()->whereKey($przepisId)->lockForUpdate()->first();

            if ($przepis === null || $przepis->author_id !== $konto->getKey()) {
                throw $this->nieDaSie();
            }

            // Drugie wysłanie tego samego formularza: pierwsze już odzyskało.
            if (! $przepis->trashed()) {
                return new WynikOdzyskania($przepis, juzOdzyskany: true, zdjeciaNieWrocily: 0);
            }

            if (self::termin($przepis)->lessThanOrEqualTo(now())
                || $this->jestNagrobkiem($przepis)
                || ! in_array($przepis->status, [Recipe::STATUS_DRAFT, Recipe::STATUS_PUBLISHED], true)
                || $this->wSprawieModeracyjnej($przepis)) {
                throw $this->nieDaSie();
            }

            $odpiete = $this->odepnijNiedostepneZdjecia($przepis);

            $przepis->forceFill([
                'status' => Recipe::STATUS_DRAFT,
                'published_at' => null,
                'visibility' => 'private',
            ]);
            $przepis->restore();

            return new WynikOdzyskania($przepis->refresh(), juzOdzyskany: false, zdjeciaNieWrocily: $odpiete);
        });
    }

    private function jestNagrobkiem(Recipe $przepis): bool
    {
        return $przepis->slug === PrzedawnioneUsunieteTresci::slugNagrobka((string) $przepis->getKey())
            || $przepis->title === PrzedawnioneUsunieteTresci::TYTUL_NAGROBKA;
    }

    private function wSprawieModeracyjnej(Recipe $przepis): bool
    {
        return $this->retencja->przepisZModeracja($przepis, $this->retencja->zdjeciaPrzepisu($przepis));
    }

    /**
     * Jeden komunikat na wszystkie powody odmowy: ekran nie mówi, że sprawa
     * moderacyjna istnieje, i nie podpowiada, który warunek zawiódł.
     */
    private function nieDaSie(): BladDlaCzlowieka
    {
        return new BladDlaCzlowieka(
            'Tego przepisu nie da się już odzyskać tym przyciskiem. '
            .'Mógł minąć termin albo przepis wymaga rozpatrzenia przez nas. '
            .'Jeśli chcesz o niego zapytać, napisz do nas przez „Napisz do nas”.',
        );
    }

    /**
     * Zdjęcia, których nie wolno już użyć (odrzucone, przejęte do skasowania
     * albo nienależące do autora), są odpinane. Zdjęcie, które nadal czeka na
     * przetworzenie, zostaje — samo dojdzie do stanu „gotowe".
     *
     * @return int ile zdjęć nie wróciło
     */
    private function odepnijNiedostepneZdjecia(Recipe $przepis): int
    {
        $odpiete = 0;
        $autorId = (string) $przepis->author_id;

        $niedostepne = static function (?string $mediaId) use ($autorId): bool {
            if ($mediaId === null) {
                return false;
            }

            $zdjecie = Media::query()->find($mediaId);

            return $zdjecie === null
                || in_array($zdjecie->status, [Media::STATUS_REJECTED, Media::STATUS_DELETED], true)
                || (string) $zdjecie->owner_id !== $autorId;
        };

        foreach (['hero_media_id', 'source_scan_media_id'] as $kolumna) {
            if ($niedostepne($przepis->{$kolumna})) {
                $przepis->forceFill([$kolumna => null]);
                $odpiete++;
            }
        }

        $kroki = DB::table('recipe_steps')
            ->where('recipe_id', $przepis->getKey())
            ->whereNotNull('media_id')
            ->get(['id', 'media_id']);

        foreach ($kroki as $krok) {
            if ($niedostepne((string) $krok->media_id)) {
                DB::table('recipe_steps')->where('id', $krok->id)->update(['media_id' => null]);
                $odpiete++;
            }
        }

        return $odpiete;
    }
}
