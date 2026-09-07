<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tags\Actions\MergeTags;
use App\Domain\Tags\FiltrWulgaryzmow;
use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Początkowa baza tagów (SPEC §1.4, D-021, D-026).
 *
 * DANE SĄ W PLIKACH JSON, NIE W TEJ KLASIE
 * `dane/slownik-tagow.json` — 1250 nazw kanonicznych i 2366 aliasów,
 * ułożonych pod polską kuchnię domową i pod grupę 50+ (kategorie `pamiec`:
 * „przepis po babci", „z rodzinnego zeszytu", i `okolicznosci`: „dla wnuków",
 * „z czerstwego chleba"). Plik ma pole `uwagi` — 44 świadome rozstrzygnięcia
 * autora słownika, w tym te z odsyłaczami do WSJP PAN i Listy Produktów
 * Tradycyjnych MRiRW. NIE KASOWAĆ tego pola przy aktualizacji: ono jest
 * jedynym miejscem, w którym zapisano, dlaczego „żur" i „żurek" NIE są
 * aliasami, a „pyzy" nie są aliasem „klusek na parze".
 *
 * `dane/slownik-tagow-uzupelnienia.json` — 169 nazw: 159 z poprzedniej,
 * wpisanej tutaj na sztywno bazy redakcyjnej (651 nazw), zawężonej do
 * pojęć, których duży słownik nie zawiera ANI jako nazwy kanonicznej, ANI
 * jako aliasu: podstawowe składniki („kapusta", „seler", „fasola", „olej"),
 * części mięsa, klasyki bez odpowiednika („zrazy", „tatar", „sękacz").
 * Z tamtej listy świadomie NIE przeniesiono: nazw angielskich i modnych
 * („cookies", „smoothie bowl", „chia pudding"), fraz zamiast pojęć („obiad
 * w piętnaście minut", „deser dla dzieci"), nazw urządzeń w formie
 * rzeczownika, gdy słownik ma tę samą rzecz w swojej formie („piekarnik" →
 * „z piekarnika", „blender" → „miksowane"), NAZWY MARKI („termomix" —
 * słownik ma potoczne „w termomiksie" małą literą i to jest cała różnica)
 * oraz — najważniejsze — tagów „fit", „dieta odchudzająca" i „dieta
 * sportowca", które łamią tę samą regułę o języku dietetycznym, jaką
 * postawiono słownikowi.
 *
 * DWA POWODY, DLA KTÓRYCH TO SĄ PLIKI, A NIE TABLICE W PHP
 *   1. Kolejna wersja słownika podmienia jeden plik, bez ruszania kodu
 *      i bez scalania cudzych zmian w środku tablicy.
 *   2. Zawartość da się sprawdzić maszynowo BEZ uruchamiania seedera —
 *      `tests/Feature/SlownikTagowTest.php` czyta te same pliki i pilnuje
 *      kolizji, których nie widać okiem (alias równy nazwie kanonicznej
 *      innego tagu to najczęstszy błąd w takich listach; przy pierwszej
 *      wersji uzupełnień było ich pięć).
 *
 * PIĘĆ KROKÓW WALIDACJI Z SPEC §1.4, W TEJ KOLEJNOŚCI
 *   1. normalizacja (`Tag::znormalizujNazwe` — BEZ unaccent, patrz D-021),
 *   2. wykrycie duplikatu (ta sama znormalizowana nazwa dwa razy na liście),
 *   3. lokalna baza wulgaryzmów (`FiltrWulgaryzmow`) — tag odrzucony tu
 *      nigdy nie trafia do bazy, niezależnie od tego, skąd przyszedł,
 *   4. kolizja slugu (rozwiązywana numerycznym sufiksem, jak
 *      `GenerateRecipeSlug` dla przepisów),
 *   5. raport na końcu — liczba tagów, aliasów, scaleń i odrzuceń,
 *      z powodem każdego odrzucenia.
 *
 * IDEMPOTENCJA. Seeder chodzi też na istniejącej bazie (`db:seed` bez
 * `migrate:fresh`). Tag rozpoznajemy po `normalized_name` i ŚWIADOMIE NIE
 * nadpisujemy `slug` (redakcyjnie ustalony raz, bo adres strony tagu mógł
 * już zostać komuś wysłany) ani `status`/`merged_into_tag_id` (poza
 * `$fillable` — patrz `Tag`). Aktualizujemy wyłącznie `internal_category`.
 *
 * ALIASY: DWA ŹRÓDŁA
 *   - Z PLIKU — liczba mnoga, potoczna nazwa, częsty synonim.
 *   - AUTOMATYCZNE, transliterowane (`Str::ascii`) — dla „żurek" powstaje
 *     „zurek". SPEC §1.2 dozwala to wprost: „wariant bez znaków może być
 *     aliasem przypiętym do kanonicznego »żurek«, ale ta relacja musi
 *     pochodzić z seedów" — pochodzi stąd. Nigdy, jeśli transliteracja
 *     pokrywa się z nazwą kanoniczną innego tagu (wtedy to nie alias,
 *     tylko kolizja — odrzucona i zaraportowana).
 *
 * KOLIZJA ALIASU Z ISTNIEJĄCYM TAGIEM: SCALAMY, ZAMIAST ODRZUCAĆ (D-026)
 * Poprzednia baza redakcyjna miała 43 nazwy, które nowy słownik traktuje
 * jako alias czegoś innego („marchewka" → „marchew", „schabowy" → „kotlet
 * schabowy", „pieczenie" → „pieczone"). Samo odrzucenie takiego aliasu nie
 * jest neutralne: w bazie, na której stary seeder już chodził, zostawałyby
 * DWA żywe tagi na jedno pojęcie — czyli dokładnie to rozsypanie, przed
 * którym cała ta baza ma chronić. Dlatego seeder scala stary tag w nowy
 * kanoniczny (`MergeTags`, SPEC §1.8) — ale TYLKO gdy stary tag jest
 * pusty i redakcyjny: `is_seeded`, `active`, bez wpisów, bez obserwujących,
 * bez promocji i sam nieobecny w słowniku. Tag, który ktoś już użył albo
 * utworzył ręcznie, NIE JEST RUSZANY — wtedy alias jest odrzucany
 * i zgłaszany, a decyzja zostaje przy człowieku.
 */
class TagSeeder extends Seeder
{
    /** Pliki danych w kolejności ważności — pierwszy wpis dla danej nazwy wygrywa. */
    private const PLIKI = [
        'slownik-tagow.json',
        'slownik-tagow-uzupelnienia.json',
    ];

    /** @var array<string, string> normalized_name => slug (istniejące + świeżo policzone) */
    private array $zajeteSlugi = [];

    /** @var array<string, int> slug => 1 */
    private array $zajeteSlugiOdwrotnie = [];

    /**
     * @var array{tagi: int, kategorie: int, aliasy: int, scalenia: list<string>, odrzucone_tagi: list<string>, odrzucone_aliasy: list<string>}
     */
    private array $raport = [
        'tagi' => 0,
        'kategorie' => 0,
        'aliasy' => 0,
        'scalenia' => [],
        'odrzucone_tagi' => [],
        'odrzucone_aliasy' => [],
    ];

    public function run(): void
    {
        $wpisy = $this->wczytajSlowniki();

        $this->utworzBrakujaceTagi($wpisy);
        $this->zaktualizujKategorie($wpisy);
        $this->utworzAliasy($wpisy);

        $this->zgloscRaport();
    }

    /**
     * Krok 1–3: wczytanie, normalizacja, odsiew duplikatów i wulgaryzmów.
     *
     * @return list<array{nazwa: string, znormalizowana: string, kategoria: string, aliasy: list<string>}>
     */
    private function wczytajSlowniki(): array
    {
        /** @var array<string, array{nazwa: string, znormalizowana: string, kategoria: string, aliasy: list<string>}> $wpisy */
        $wpisy = [];

        foreach (self::PLIKI as $plik) {
            foreach ($this->wczytajPlik($plik) as $tag) {
                $nazwa = trim((string) ($tag['nazwa'] ?? ''));
                $znormalizowana = Tag::znormalizujNazwe($nazwa);

                if ($znormalizowana === '') {
                    $this->raport['odrzucone_tagi'][] = "(pusta nazwa w {$plik})";

                    continue;
                }

                if (isset($wpisy[$znormalizowana])) {
                    $this->raport['odrzucone_tagi'][] = "„{$nazwa}” ({$plik}: ta nazwa już jest w słowniku)";

                    continue;
                }

                if (FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($znormalizowana)) {
                    $this->raport['odrzucone_tagi'][] = "„{$nazwa}” (lokalna baza wulgaryzmów)";

                    continue;
                }

                /** @var list<string> $aliasy */
                $aliasy = array_values(array_filter(
                    (array) ($tag['aliasy'] ?? []),
                    static fn ($alias): bool => is_string($alias) && trim($alias) !== '',
                ));

                $wpisy[$znormalizowana] = [
                    'nazwa' => $nazwa,
                    'znormalizowana' => $znormalizowana,
                    'kategoria' => (string) ($tag['kategoria'] ?? ''),
                    'aliasy' => $aliasy,
                ];
            }
        }

        return array_values($wpisy);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wczytajPlik(string $plik): array
    {
        $sciezka = database_path('seeders/dane/'.$plik);
        $surowe = file_get_contents($sciezka);

        if ($surowe === false) {
            // Brak pliku danych to nie jest sytuacja, którą wolno przemilczeć:
            // seeder bez słownika utworzyłby zero tagów i zaraportował sukces.
            throw new \RuntimeException("Nie da się wczytać słownika tagów: {$sciezka}");
        }

        $dane = json_decode($surowe, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($dane) || ! isset($dane['tagi']) || ! is_array($dane['tagi'])) {
            throw new \RuntimeException("Słownik tagów {$plik} nie ma tablicy `tagi`.");
        }

        /** @var list<array<string, mixed>> $tagi */
        $tagi = array_values(array_filter($dane['tagi'], 'is_array'));

        return $tagi;
    }

    /**
     * Krok 4: slug i zapis. Wstawianie hurtowe (`insert` po 500 wierszy),
     * bo 1409 tagów × `Tag::create()` to 1409 osobnych `INSERT`-ów plus
     * tyle samo `SELECT`-ów — na tej liczbie wierszy to już jest różnica
     * między sekundą i minutą, także w testach.
     *
     * @param  list<array{nazwa: string, znormalizowana: string, kategoria: string, aliasy: list<string>}>  $wpisy
     */
    private function utworzBrakujaceTagi(array $wpisy): void
    {
        $this->zajeteSlugi = Tag::query()->pluck('slug', 'normalized_name')->all();
        $this->zajeteSlugiOdwrotnie = array_fill_keys(array_values($this->zajeteSlugi), 1);

        $istniejace = Tag::query()->pluck('normalized_name')->all();
        $istniejace = array_fill_keys($istniejace, true);

        $teraz = now();
        $doWstawienia = [];

        foreach ($wpisy as $wpis) {
            if (isset($istniejace[$wpis['znormalizowana']])) {
                continue;
            }

            $slug = $this->wolnySlug(Tag::slugDlaNazwy($wpis['nazwa']));

            $doWstawienia[] = [
                'id' => (string) Str::uuid(),
                'name' => $wpis['nazwa'],
                'normalized_name' => $wpis['znormalizowana'],
                'slug' => $slug,
                'status' => Tag::STATUS_ACTIVE,
                'is_seeded' => true,
                'internal_category' => $wpis['kategoria'],
                'created_at' => $teraz,
                'updated_at' => $teraz,
            ];

            $this->zajeteSlugi[$wpis['znormalizowana']] = $slug;
        }

        foreach (array_chunk($doWstawienia, 500) as $paczka) {
            DB::table('tags')->insert($paczka);
        }

        $this->raport['tagi'] = count($doWstawienia);
    }

    /**
     * Kategoria techniczna jest jedynym polem, które seeder aktualizuje na
     * istniejącym wierszu (patrz komentarz klasy). Jedno `UPDATE ... WHERE
     * normalized_name IN (...)` na kategorię, nie jedno na tag.
     *
     * @param  list<array{nazwa: string, znormalizowana: string, kategoria: string, aliasy: list<string>}>  $wpisy
     */
    private function zaktualizujKategorie(array $wpisy): void
    {
        /** @var array<string, list<string>> $poKategorii */
        $poKategorii = [];

        foreach ($wpisy as $wpis) {
            $poKategorii[$wpis['kategoria']][] = $wpis['znormalizowana'];
        }

        foreach ($poKategorii as $kategoria => $nazwy) {
            foreach (array_chunk($nazwy, 500) as $paczka) {
                $this->raport['kategorie'] += Tag::query()
                    ->whereIn('normalized_name', $paczka)
                    ->where(function ($query) use ($kategoria): void {
                        $query->where('internal_category', '!=', $kategoria)
                            ->orWhereNull('internal_category');
                    })
                    ->update(['internal_category' => $kategoria]);
            }
        }
    }

    /**
     * Aliasy z pliku ORAZ automatyczne transliteracje, jednym przebiegiem po
     * pełnym, świeżo wstawionym stanie bazy — inaczej sprawdzenie kolizji
     * widziałoby tylko część tagów.
     *
     * @param  list<array{nazwa: string, znormalizowana: string, kategoria: string, aliasy: list<string>}>  $wpisy
     */
    private function utworzAliasy(array $wpisy): void
    {
        /** @var array<string, string> $tagiPoNazwie normalized_name => id */
        $tagiPoNazwie = Tag::query()->pluck('id', 'normalized_name')->all();

        /** @var array<string, string> $wlascicieleAliasow normalized_alias => tag_id */
        $wlascicieleAliasow = TagAlias::query()->pluck('tag_id', 'normalized_alias')->all();

        // Nazwy ze słownika — potrzebne, żeby NIGDY nie scalić tagu, który
        // sam jest w słowniku nazwą kanoniczną (patrz komentarz klasy).
        $nazwyZeSlownika = [];
        foreach ($wpisy as $wpis) {
            $nazwyZeSlownika[$wpis['znormalizowana']] = true;
        }

        $teraz = now();
        $doWstawienia = [];

        foreach ($wpisy as $wpis) {
            $idTagu = $tagiPoNazwie[$wpis['znormalizowana']] ?? null;

            if ($idTagu === null) {
                // Tag odrzucony wyżej (wulgaryzm) — jego aliasy nie mają
                // do czego się przypiąć.
                continue;
            }

            $kandydaci = $wpis['aliasy'];

            // Automatyczna transliteracja nazwy kanonicznej, jeśli coś zmienia.
            $bezZnakow = Str::ascii($wpis['nazwa']);
            if (Tag::znormalizujNazwe($bezZnakow) !== $wpis['znormalizowana']) {
                $kandydaci[] = $bezZnakow;
            }

            foreach ($kandydaci as $alias) {
                $wynik = $this->rozstrzygnijAlias(
                    $alias,
                    $idTagu,
                    $wpis,
                    $tagiPoNazwie,
                    $wlascicieleAliasow,
                    $nazwyZeSlownika,
                );

                if ($wynik === null) {
                    continue;
                }

                $doWstawienia[] = $wynik + ['created_at' => $teraz];
                $wlascicieleAliasow[(string) $wynik['normalized_alias']] = $idTagu;
            }
        }

        foreach (array_chunk($doWstawienia, 500) as $paczka) {
            DB::table('tag_aliases')->insert($paczka);
        }

        $this->raport['aliasy'] = count($doWstawienia);
    }

    /**
     * @param  array{nazwa: string, znormalizowana: string, kategoria: string, aliasy: list<string>}  $wpis
     * @param  array<string, string>  $tagiPoNazwie
     * @param  array<string, string>  $wlascicieleAliasow
     * @param  array<string, true>  $nazwyZeSlownika
     * @return array{tag_id: string, alias: string, normalized_alias: string, source: string}|null
     */
    private function rozstrzygnijAlias(
        string $alias,
        string $idTagu,
        array $wpis,
        array $tagiPoNazwie,
        array $wlascicieleAliasow,
        array $nazwyZeSlownika,
    ): ?array {
        $alias = trim($alias);
        $znormalizowany = Tag::znormalizujNazwe($alias);

        if ($znormalizowany === '' || $znormalizowany === $wpis['znormalizowana']) {
            // Transliteracja, która nic nie zmieniła — nie błąd, po prostu
            // nie ma czego dodawać.
            return null;
        }

        if (FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($znormalizowany)) {
            $this->raport['odrzucone_aliasy'][] = "„{$alias}” (lokalna baza wulgaryzmów)";

            return null;
        }

        // KOLIZJA Z NAZWĄ KANONICZNĄ IDZIE PIERWSZA, PRZED sprawdzeniem, czy
        // alias już istnieje. Kolejność nie jest kosmetyczna: gdy alias
        // „marchewka” już wisi na „marchew”, a tag „marchewka” nadal żyje
        // z poprzedniej bazy (dowolna kolejność uruchomień to wytwarza),
        // wyjście na samym „alias już istnieje” zostawiłoby DWA żywe tagi na
        // jedno pojęcie na zawsze — czyli dokładnie to rozsypanie, przed
        // którym ta baza ma chronić. Scalenie musi więc dostać szansę
        // niezależnie od tego, czy alias jest już w bazie.
        $kolidujacyTag = $tagiPoNazwie[$znormalizowany] ?? null;

        if ($kolidujacyTag !== null && $kolidujacyTag !== $idTagu) {
            if (! $this->scalKolidujacyTag($znormalizowany, $alias, $idTagu, $nazwyZeSlownika)) {
                return null;
            }
        }

        $wlascicielAliasu = $wlascicieleAliasow[$znormalizowany] ?? null;

        if ($wlascicielAliasu !== null) {
            // Ten sam tag — nieszkodliwe powtórzenie (seeder chodzi na
            // istniejącej bazie). Inny tag — prawdziwa kolizja.
            if ($wlascicielAliasu !== $idTagu) {
                $this->raport['odrzucone_aliasy'][] = "„{$alias}” (już przypięty do innego tagu)";
            }

            return null;
        }

        return [
            'tag_id' => $idTagu,
            'alias' => $alias,
            'normalized_alias' => $znormalizowany,
            'source' => TagAlias::SOURCE_SEED,
        ];
    }

    /**
     * Kolizja aliasu z ISTNIEJĄCYM tagiem kanonicznym (D-026, patrz
     * komentarz klasy). Zwraca `true`, gdy po scaleniu wolno dopisać alias.
     *
     * @param  array<string, true>  $nazwyZeSlownika
     */
    private function scalKolidujacyTag(
        string $znormalizowany,
        string $alias,
        string $idTagu,
        array $nazwyZeSlownika,
    ): bool {
        $stary = Tag::query()->where('normalized_name', $znormalizowany)->first();

        if ($stary === null) {
            return true;
        }

        if ($stary->isMerged()) {
            // Już scalony — jeśli w TEN tag, to jest to drugie uruchomienie
            // seedera i alias wolno dopisać. Jeśli w inny, człowiek podjął
            // inną decyzję i nie ruszamy jej.
            if ($stary->merged_into_tag_id === $idTagu) {
                return true;
            }

            $this->raport['odrzucone_aliasy'][] = "„{$alias}” (tag o tej nazwie jest scalony w inny tag)";

            return false;
        }

        if (isset($nazwyZeSlownika[$znormalizowany])) {
            // Słownik sam ma ten tag jako nazwę kanoniczną — wtedy to nie
            // jest stara pozostałość, tylko sprzeczność WEWNĄTRZ słownika.
            // `SlownikTagowTest` pilnuje, żeby taka nie powstała; tutaj jest
            // druga bramka, bo plik można podmienić bez uruchomienia testów.
            $this->raport['odrzucone_aliasy'][] = "„{$alias}” (słownik ma tag i alias o tej samej nazwie)";

            return false;
        }

        $powod = $this->dlaczegoNieWolnoScalic($stary);

        if ($powod !== null) {
            $this->raport['odrzucone_aliasy'][] = "„{$alias}” ({$powod})";

            return false;
        }

        /** @var Tag $cel */
        $cel = Tag::query()->findOrFail($idTagu);

        app(MergeTags::class)->handle($stary, $cel);

        $this->raport['scalenia'][] = "„{$stary->name}” → „{$cel->name}”";

        // `MergeTags` samo dopisuje nazwę źródła jako alias celu, więc
        // dokładnie ten alias już istnieje — nie wstawiamy go drugi raz.
        return false;
    }

    /** Powód, dla którego stary tag zostaje nietknięty, albo `null`, gdy wolno scalić. */
    private function dlaczegoNieWolnoScalic(Tag $stary): ?string
    {
        if (! $stary->is_seeded) {
            return 'tag o tej nazwie utworzył człowiek, nie seeder';
        }

        if (! $stary->isActive()) {
            return 'tag o tej nazwie jest ukryty przez moderację';
        }

        if (DB::table('post_tags')->where('tag_id', $stary->getKey())->exists()) {
            return 'tag o tej nazwie ma oznaczone wpisy';
        }

        if (DB::table('tag_follows')->where('tag_id', $stary->getKey())->exists()) {
            return 'tag o tej nazwie ma obserwujących';
        }

        if (DB::table('tag_promotions')->where('tag_id', $stary->getKey())->exists()) {
            return 'tag o tej nazwie jest na liście promowanych';
        }

        return null;
    }

    private function wolnySlug(string $baza): string
    {
        $slug = $baza;
        $sufiks = 2;

        while (isset($this->zajeteSlugiOdwrotnie[$slug])) {
            $slug = $baza.'-'.$sufiks;
            $sufiks++;
        }

        $this->zajeteSlugiOdwrotnie[$slug] = 1;

        return $slug;
    }

    private function zgloscRaport(): void
    {
        $this->command?->info(sprintf(
            'TagSeeder: %d nowych tagów, %d zaktualizowanych kategorii, %d nowych aliasów, '
            .'%d scaleń, %d odrzuconych tagów, %d odrzuconych aliasów.',
            $this->raport['tagi'],
            $this->raport['kategorie'],
            $this->raport['aliasy'],
            count($this->raport['scalenia']),
            count($this->raport['odrzucone_tagi']),
            count($this->raport['odrzucone_aliasy']),
        ));

        foreach ($this->raport['scalenia'] as $scalenie) {
            $this->command?->line('  scalono: '.$scalenie);
        }

        foreach ([...$this->raport['odrzucone_tagi'], ...$this->raport['odrzucone_aliasy']] as $powod) {
            $this->command?->warn('  odrzucono: '.$powod);
        }
    }
}
