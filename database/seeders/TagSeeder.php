<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tags\FiltrWulgaryzmow;
use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Początkowa baza tagów (SPEC §1.4, D-021).
 *
 * PO CO TAKA DUŻA BAZA NA START
 * `TopicSeeder` (którego ta klasa zastępuje razem z całym mechanizmem
 * Tematu) miał w komentarzu ostrzeżenie: „wolne tagi rozsypują się
 * natychmiast: zakwas / na zakwasie / chleb zakwas / ZAKWAS — po miesiącu
 * nie ma czego obserwować, bo każdy wpis ma własny tag". Duża, gotowa baza
 * kanonicznych nazw + aliasów jest jedyną obroną przed tym scenariuszem BEZ
 * przywracania zamkniętej listy: gdy ktoś zacznie pisać „sernik", podpowiedź
 * (`App\Domain\Tags\TagSuggester`) ma szansę zaproponować już istniejący tag,
 * zanim powstanie jego trzecia, czwarta i piąta wersja.
 *
 * DLACZEGO `updateOrCreate` PO `normalized_name`, A NIE `insert`
 * Seeder chodzi też na istniejącej bazie (`db:seed` bez `migrate:fresh`),
 * dokładnie jak `TopicSeeder`. Aktualizacja po znormalizowanej nazwie
 * pozwala poprawić `internal_category` przy kolejnym uruchomieniu bez
 * dublowania wiersza — ale ŚWIADOMIE NIE nadpisuje `slug` (redakcyjnie
 * ustalony raz, bo adres strony tagu mógł już zostać komuś wysłany) ani
 * `status`/`merged_into_tag_id` (poza `$fillable` na modelu — patrz `Tag`).
 *
 * PIĘĆ KROKÓW WALIDACJI Z SPEC §1.4, W TEJ KOLEJNOŚCI
 *   1. normalizacja (`Tag::znormalizujNazwe` — BEZ unaccent, patrz D-021),
 *   2. wykrycie duplikatu (ta sama znormalizowana nazwa dwa razy na liście),
 *   3. lokalna baza wulgaryzmów (`FiltrWulgaryzmow`) — tag odrzucony tu
 *      nigdy nie trafia do bazy, niezależnie od tego, skąd przyszedł;
 *   4. kolizja slugu (rozwiązywana numerycznym sufiksem, jak
 *      `GenerateRecipeSlug` dla przepisów);
 *   5. raport na końcu (`$this->command?->info(...)`) — liczba tagów,
 *      aliasów i odrzuconych rekordów, z powodem odrzucenia każdego.
 *
 * ALIASY: DWA ŹRÓDŁA
 *   - AUTOMATYCZNE, transliterowane (`Str::ascii`) — dla „żurek" powstaje
 *     alias „zurek". To jest dokładnie przypadek dozwolony przez SPEC §1.2:
 *     „wariant bez znaków może być aliasem przypiętym do kanonicznego
 *     „żurek", ale ta relacja musi pochodzić z seedów" — pochodzi stąd.
 *     NIGDY automatycznie, jeśli transliteracja pokrywa się z normalized_name
 *     innego kanonicznego tagu (wtedy to nie jest alias, tylko kolizja —
 *     odrzucone i zaraportowane).
 *   - RĘCZNE, z `ALIASY` niżej — liczba mnoga, częsty synonim, potoczna
 *     nazwa („kotlet schabowy" / „schabowy"). Te nie dają się wyprowadzić
 *     żadną regułą, więc są wypisane wprost — dokładnie tak, jak
 *     `TopicSeeder::TEMATY` był ręcznie ułożoną listą, a nie zrzutem.
 *
 * CZEGO ŚWIADOMIE NIE MA: pełnej fleksji polskiej (przypadki, zdrobnienia)
 * ani reguł ortograficznych do generowania wariantów. R1 §8 nazywa to wprost
 * jako przerost inżynierski przy tej skali — dokładnie ten sam powód, dla
 * którego lista wulgaryzmów niżej jest krótka i ręczna, a nie „odporna na
 * obejścia".
 */
class TagSeeder extends Seeder
{
    /**
     * Kanoniczne nazwy pogrupowane kategorią TECHNICZNĄ (SPEC §1.3:
     * `internal_category`, niewidoczną dla użytkownika — służy wyłącznie
     * do raportu z importu i do przyszłego sortowania panelu administratora).
     *
     * @var array<string, list<string>>
     */
    private const KATEGORIE = [
        // --- Dania obiadowe i główne ---
        'danie' => [
            'obiad', 'kotlet schabowy', 'kotlet mielony', 'kotlet de volaille',
            'schabowy', 'gulasz', 'bigos', 'pierogi', 'pierogi ruskie',
            'pierogi z mięsem', 'pierogi z kapustą i grzybami', 'pierogi z jagodami',
            'naleśniki', 'placki ziemniaczane', 'kopytka', 'kluski śląskie',
            'kluski leniwe', 'knedle', 'gołąbki', 'zrazy', 'roladki wołowe',
            'żeberka', 'karkówka', 'pieczeń', 'schab pieczony', 'udka z kurczaka',
            'kurczak pieczony', 'kurczak w sosie', 'kaczka pieczona', 'gęś pieczona',
            'indyk pieczony', 'stek', 'befsztyk', 'flaki', 'zapiekanka ziemniaczana',
            'zapiekanka makaronowa', 'lasagne', 'spaghetti', 'spaghetti bolognese',
            'makaron z sosem', 'makaron carbonara', 'risotto', 'pizza domowa',
            'kasza gryczana z mięsem', 'ryż z warzywami', 'kurczak curry',
            'chili con carne', 'burger domowy', 'hot dog domowy', 'tortilla',
            'burrito', 'kebab domowy', 'kotlet z kurczaka', 'kotlet sojowy',
            'kotlet z ciecierzycy', 'pyzy', 'kluski z serem', 'racuchy',
            'placki z cukinii', 'placki drożdżowe', 'jajecznica', 'omlet',
            'frytki domowe', 'purée ziemniaczane', 'ziemniaki pieczone',
            'zapiekanka warzywna', 'gulasz wegetariański', 'curry warzywne',
            'kluski francuskie', 'dania jednogarnkowe', 'obiad w piętnaście minut',
        ],

        // --- Zupy ---
        'danie_zupa' => [
            'rosół', 'żurek', 'barszcz czerwony', 'zupa pomidorowa', 'zupa ogórkowa',
            'zupa grzybowa', 'zupa jarzynowa', 'zupa krem z dyni', 'zupa krem z brokuła',
            'zupa krem z pomidorów', 'zupa cebulowa', 'zupa czosnkowa', 'zupa fasolowa',
            'zupa grochowa', 'kapuśniak', 'zupa szczawiowa', 'zupa koperkowa',
            'zupa mleczna', 'zupa rybna', 'zupa krupnik', 'chłodnik', 'zupa gulaszowa',
            'zupa z soczewicy', 'zupa minestrone', 'zupa tajska', 'zupa miso',
            'zupa cebulowa francuska', 'zupa z zielonego groszku', 'zupa z pieczarek',
            'zupa neapolitanka', 'zupa krem z kalafiora', 'żur', 'flaczki',
            'zupa na wywarze warzywnym', 'zupa na maślance', 'zupa ziemniaczana',
            'zupa buraczkowa', 'zupa z kurczaka', 'zupa z indyka',
        ],

        // --- Wypieki i ciasta ---
        'danie_wypiek' => [
            'chleb', 'chleb na zakwasie', 'zakwas', 'bułki drożdżowe', 'bagietka',
            'focaccia', 'chałka', 'bułka maślana', 'ciasto drożdżowe', 'drożdżówki',
            'sernik', 'sernik na zimno', 'sernik wiedeński', 'szarlotka', 'jabłecznik',
            'makowiec', 'piernik', 'placek ucierany', 'ciasto marchewkowe',
            'ciasto czekoladowe', 'brownie', 'murzynek', 'ciasto cytrynowe',
            'ciasto orzechowe', 'ciasto biszkoptowe', 'biszkopt', 'rolada biszkoptowa',
            'kruche ciasteczka', 'pierniczki', 'rogaliki', 'croissanty', 'eklerki',
            'pączki', 'faworki', 'chrust', 'tarta', 'tarta z owocami', 'tarta cytrynowa',
            'ciasto bez pieczenia', 'ciasto na zimno', 'kremówka', 'napoleonka',
            'mazurek', 'keks', 'babka wielkanocna', 'babka piaskowa', 'muffinki',
            'babeczki', 'cupcakes', 'ciasto marmurkowe', 'ciasto orzechowo-czekoladowe',
            'strudel', 'ciasto bezowe', 'beza', 'pavlova', 'tort urodzinowy',
            'tort weselny', 'tort czekoladowy', 'tort owocowy', 'wafle domowe',
            'gofry', 'ciasteczka owsiane', 'ciasteczka czekoladowe', 'cookies',
            'ciasto jogurtowe', 'ciasto z rabarbarem', 'ciasto ze śliwkami',
            'ciasto z owocami sezonowymi', 'sękacz', 'keks świąteczny', 'pierniki lukrowane',
        ],

        // --- Desery i słodkości (poza pieczeniem) ---
        'danie_deser' => [
            'lody domowe', 'sorbet', 'mus czekoladowy', 'panna cotta', 'tiramisu',
            'budyń', 'kisiel', 'kompot', 'galaretka', 'krem waniliowy', 'krem czekoladowy',
            'deser bez pieczenia', 'deser z owocami', 'sałatka owocowa', 'owoce w czekoladzie',
            'trufle czekoladowe', 'domowe cukierki', 'chałwa', 'krówki', 'ptasie mleczko',
            'deser jogurtowy', 'smoothie bowl', 'granola domowa', 'deser z granolą',
            'lody na patyku', 'sernik w słoiku', 'deser w słoiku', 'creme brulee',
            'deser czekoladowy', 'deser dla dzieci', 'wafle ryżowe z czekoladą',
        ],

        // --- Śniadania ---
        'danie_sniadanie' => [
            'śniadanie', 'kanapki', 'kanapki na słodko', 'jajka w koszulkach',
            'jajka sadzone', 'jajka po wiedeńsku', 'owsianka', 'płatki owsiane',
            'jogurt z owocami', 'smoothie', 'koktajl owocowy', 'kasza jaglana na śniadanie',
            'tosty', 'tosty francuskie', 'pankejki', 'placuszki na słodko',
            'chia pudding', 'awokado na kanapce', 'szakszuka', 'śniadanie do pracy',
            'śniadanie na słodko', 'śniadanie na słono', 'jajecznica na maśle',
            'twarożek na śniadanie', 'musli domowe',
        ],

        // --- Kolacje i przekąski ---
        'danie_kolacja' => [
            'kolacja', 'kanapki na kolację', 'sałatka na kolację', 'lekka kolacja',
            'przekąski na imprezę', 'paluszki serowe', 'nachos', 'domowe chipsy',
            'pasta jajeczna', 'pasta z tuńczyka', 'pasta z awokado', 'hummus',
            'tzatziki', 'guacamole', 'dip czosnkowy', 'krakersy domowe', 'sałatka jarzynowa',
            'tatar', 'carpaccio', 'przystawki', 'deska serów', 'deska wędlin',
            'koreczki', 'tapas', 'quiche', 'placek z serem na słono',
        ],

        // --- Sałatki i surówki ---
        'danie_salatka' => [
            'sałatka', 'surówka z kapusty', 'surówka z marchewki', 'coleslaw',
            'sałatka grecka', 'sałatka cezar', 'sałatka z tuńczykiem', 'sałatka z kurczakiem',
            'sałatka ziemniaczana', 'sałatka jarzynowa świąteczna', 'sałatka z buraczków',
            'sałatka z rukolą', 'sałatka caprese', 'sałatka z quinoa', 'sałatka śledziowa',
            'sałatka z fetą', 'surówka z selera', 'mizeria', 'sałatka z awokado',
            'sałatka z komosą ryżową',
        ],

        // --- Mięsa ---
        'skladnik_mieso' => [
            'kurczak', 'indyk', 'wołowina', 'wieprzowina', 'schab', 'boczek',
            'kiełbasa', 'kiełbasa domowa', 'baranina', 'jagnięcina', 'cielęcina',
            'kaczka', 'gęś', 'królik', 'dziczyzna', 'mielone mięso', 'polędwica',
            'żeberka wieprzowe', 'karkówka wieprzowa', 'udziec z kurczaka', 'pierś z kurczaka',
            'skrzydełka z kurczaka', 'wątróbka', 'ozorki', 'boczek wędzony',
            'kabanosy', 'parówki domowe', 'pasztet', 'salceson', 'smalec',
        ],

        // --- Ryby i owoce morza ---
        'skladnik_ryba' => [
            'łosoś', 'dorsz', 'śledź', 'pstrąg', 'makrela', 'karp', 'tuńczyk',
            'krewetki', 'małże', 'kalmary', 'ryba po grecku', 'ryba w cieście',
            'ryba pieczona', 'ryba wędzona', 'ryba smażona', 'karp na wigilię',
            'sushi domowe', 'ryba na parze', 'owoce morza',
        ],

        // --- Warzywa ---
        'skladnik_warzywo' => [
            'ziemniaki', 'marchewka', 'cebula', 'czosnek', 'pomidory', 'ogórki',
            'papryka', 'cukinia', 'bakłażan', 'kapusta', 'kapusta kiszona',
            'brokuły', 'kalafior', 'brukselka', 'szpinak', 'sałata', 'rukola',
            'burak', 'seler', 'pietruszka', 'por', 'rzodkiewka', 'groszek zielony',
            'fasolka szparagowa', 'fasola', 'soczewica', 'ciecierzyca', 'bób',
            'dynia', 'kukurydza', 'szparagi', 'karczochy', 'grzyby', 'grzyby leśne',
            'pieczarki', 'borowiki', 'kurki', 'jarmuż', 'boćwina', 'chrzan',
            'rzepa', 'topinambur', 'kalarepa', 'szczypiorek', 'koper', 'natka pietruszki',
            'awokado', 'imbir świeży', 'chili świeże', 'oliwki', 'kapary',
        ],

        // --- Owoce ---
        'skladnik_owoc' => [
            'jabłka', 'gruszki', 'śliwki', 'wiśnie', 'czereśnie', 'truskawki',
            'maliny', 'jagody', 'borówki', 'porzeczki', 'agrest', 'rabarbar',
            'brzoskwinie', 'morele', 'winogrona', 'arbuz', 'melon', 'cytryna',
            'limonka', 'pomarańcza', 'grejpfrut', 'mandarynki', 'banany', 'ananas',
            'mango', 'kiwi', 'granat', 'żurawina', 'figi', 'daktyle', 'orzechy włoskie',
            'orzechy laskowe', 'migdały', 'orzechy nerkowca', 'pistacje', 'kasztany jadalne',
        ],

        // --- Przetwory i kiszonki ---
        'skladnik_przetwor' => [
            'przetwory', 'dżem', 'powidła', 'konfitura', 'kompot w słoiku',
            'ogórki kiszone', 'ogórki konserwowe', 'kapusta kiszona domowa',
            'kimchi', 'sok jabłkowy', 'przecier pomidorowy', 'passata', 'ketchup domowy',
            'musztarda domowa', 'chutney', 'marynaty', 'grzyby marynowane',
            'papryka konserwowa', 'cukinia w słoiku', 'sałatka ogórkowa na zimę',
            'syrop z czarnego bzu', 'syrop malinowy', 'nalewka wiśniowa',
            'nalewka orzechowa', 'wino domowe', 'octówka', 'suszone owoce',
            'suszone grzyby', 'kwas chlebowy', 'kombucha',
        ],

        // --- Napoje ---
        'napoj' => [
            'herbata', 'kawa', 'kawa mrożona', 'lemoniada', 'kompot owocowy',
            'sok owocowy', 'sok warzywny', 'koktajl mleczny', 'kakao', 'grzane wino',
            'poncz', 'mrożona herbata', 'napój izotoniczny domowy', 'oranżada',
            'napój z imbirem', 'herbata ziołowa', 'nalewki', 'drink bezalkoholowy',
        ],

        // --- Święta i okazje ---
        'okazja' => [
            'wigilia', 'boże narodzenie', 'wielkanoc', 'śniadanie wielkanocne',
            'tłusty czwartek', 'andrzejki', 'sylwester', 'urodziny', 'imieniny',
            'komunia', 'wesele', 'grill', 'ognisko', 'majówka', 'walentynki',
            'dzień matki', 'dzień dziecka', 'pierwszy dzień szkoły', 'halloween',
            'mikołajki', 'dożynki', 'piknik', 'impreza rodzinna', 'przyjęcie',
            'wielkanocne jajka', 'świąteczny stół', 'kolacja wigilijna',
        ],

        // --- Kuchnie świata i regionalne ---
        'kuchnia' => [
            'kuchnia polska', 'kuchnia włoska', 'kuchnia francuska', 'kuchnia hiszpańska',
            'kuchnia grecka', 'kuchnia turecka', 'kuchnia meksykańska', 'kuchnia amerykańska',
            'kuchnia indyjska', 'kuchnia tajska', 'kuchnia wietnamska', 'kuchnia chińska',
            'kuchnia japońska', 'kuchnia koreańska', 'kuchnia bliskowschodnia',
            'kuchnia marokańska', 'kuchnia niemiecka', 'kuchnia węgierska',
            'kuchnia skandynawska', 'kuchnia żydowska', 'kuchnia śląska',
            'kuchnia podlaska', 'kuchnia kresowa', 'kuchnia góralska', 'kuchnia nadmorska',
            'kuchnia wielkopolska', 'kuchnia kaszubska', 'kuchnia karaibska',
            'kuchnia brazylijska', 'kuchnia peruwiańska', 'kuchnia libańska',
            'kuchnia wegetariańska świata', 'kuchnia fusion', 'kuchnia śródziemnomorska',
            'kuchnia bałkańska', 'kuchnia rosyjska', 'kuchnia ukraińska', 'kuchnia austriacka',
            'kuchnia portugalska', 'kuchnia holenderska',
        ],

        // --- Techniki gotowania ---
        'technika' => [
            'pieczenie', 'gotowanie na parze', 'smażenie', 'duszenie', 'grillowanie',
            'wędzenie', 'peklowanie', 'kiszenie', 'fermentacja', 'blanszowanie',
            'confit', 'sous vide', 'karmelizowanie', 'flambirowanie', 'marynowanie',
            'panierowanie', 'gotowanie na wolnym ogniu', 'pieczenie w rękawie',
            'gotowanie w wodzie', 'gotowanie beztłuszczowe', 'pieczenie na grillu węglowym',
            'gotowanie na parze w bambusie', 'pieczenie chleba', 'wyrabianie ciasta',
            'zaprawianie zupy',
        ],

        // --- Urządzenia ---
        'urzadzenie' => [
            'air fryer', 'wolnowar', 'multicooker', 'termomix', 'piekarnik',
            'grill elektryczny', 'kuchenka indukcyjna', 'robot kuchenny', 'blender',
            'sokowirówka', 'gofrownica', 'opiekacz do kanapek', 'maszynka do mielenia mięsa',
            'parowar', 'ekspres do kawy',
        ],

        // --- Diety i ograniczenia żywieniowe ---
        'dieta' => [
            'bez glutenu', 'bez laktozy', 'wegetariańskie', 'wegańskie', 'keto',
            'niskowęglowodanowe', 'wysokobiałkowe', 'dieta cukrzycowa', 'bez cukru',
            'fit', 'lekkostrawne', 'dla alergików', 'bez orzechów', 'dieta odchudzająca',
            'paleo', 'niskotłuszczowe', 'dla dzieci', 'dla niemowląt', 'bez jajek',
            'dieta sportowca',
        ],

        // --- Podstawowe składniki i przyprawy ---
        'skladnik_podstawowy' => [
            'mąka', 'mąka pszenna', 'mąka żytnia', 'mąka orkiszowa', 'mąka migdałowa',
            'cukier', 'cukier trzcinowy', 'miód', 'masło', 'olej', 'oliwa z oliwek',
            'jajka', 'mleko', 'śmietana', 'jogurt naturalny', 'twaróg', 'ser żółty',
            'ser feta', 'ser pleśniowy', 'mozzarella', 'parmezan', 'ser wiejski',
            'drożdże', 'soda oczyszczona', 'proszek do pieczenia', 'wanilia',
            'cynamon', 'imbir', 'kurkuma', 'papryka słodka', 'papryka ostra',
            'pieprz', 'sól', 'liść laurowy', 'ziele angielskie', 'majeranek',
            'oregano', 'bazylia', 'tymianek', 'rozmaryn', 'kolendra', 'kminek',
            'gałka muszkatołowa', 'wanilia w strąku', 'czekolada', 'kakao w proszku',
            'orzechy', 'sezam', 'siemię lniane', 'żelatyna', 'agar', 'ocet balsamiczny',
            'sos sojowy', 'musztarda', 'majonez', 'ketchup', 'chrzan tarty',
        ],
    ];

    /**
     * Aliasy RĘCZNE — liczba mnoga, potoczna nazwa, częsty synonim. Klucz to
     * KANONICZNA nazwa (musi wystąpić w `KATEGORIE` wyżej), wartość to lista
     * wariantów. Nie każdy tag ma tu wpis — to jest dopisek dla tych, gdzie
     * warto, nie wymóg dla wszystkich 600+.
     *
     * @var array<string, list<string>>
     */
    private const ALIASY = [
        'sernik' => ['serniki', 'sernik babci'],
        'pierogi' => ['pierogi domowe'],
        'kotlet schabowy' => ['schabowe', 'kotlety schabowe'],
        'kotlet mielony' => ['kotlety mielone', 'mielone'],
        'gołąbki' => ['gołąbek'],
        'żurek' => ['żur', 'żurek na zakwasie'],
        'barszcz czerwony' => ['barszcz', 'czerwony barszcz'],
        'zupa pomidorowa' => ['pomidorówka'],
        'zupa ogórkowa' => ['ogórkowa'],
        'zupa grzybowa' => ['grzybowa'],
        'kapuśniak' => ['zupa kapuśniak'],
        'chleb na zakwasie' => ['chleb żytni na zakwasie'],
        'placki ziemniaczane' => ['placki kartoflane', 'placuszki ziemniaczane'],
        'naleśniki' => ['naleśnik'],
        'ciasto marchewkowe' => ['ciasto z marchewki'],
        'szarlotka' => ['jabłecznik z kruszonką'],
        'ciasteczka owsiane' => ['ciastka owsiane'],
        'kanapki' => ['kanapka'],
        'jajecznica' => ['jajecznica na maśle domowa'],
        'surówka z kapusty' => ['surówka', 'kapusta surówka'],
        'ziemniaki' => ['kartofle'],
        'pomidory' => ['pomidor'],
        'ogórki' => ['ogórek'],
        'grzyby' => ['grzybki'],
        'jabłka' => ['jabłko'],
        'truskawki' => ['truskawka'],
        'kiełbasa domowa' => ['domowa kiełbasa'],
        'wołowina' => ['mięso wołowe'],
        'wieprzowina' => ['mięso wieprzowe'],
        'ryba po grecku' => ['ryba po grecku wigilijna'],
        'karp na wigilię' => ['karp wigilijny'],
        'bez glutenu' => ['gluten free', 'bezglutenowe'],
        'wegetariańskie' => ['wege', 'wegetariańska'],
        'wegańskie' => ['wegan', 'roślinne'],
        'air fryer' => ['frytkownica beztłuszczowa', 'airfryer'],
        'wolnowar' => ['slow cooker'],
        'kuchnia śląska' => ['śląskie'],
        'kuchnia podlaska' => ['podlaskie'],
        'kuchnia góralska' => ['góralskie'],
        'grill' => ['grillowanie na ruszcie'],
        'wigilia' => ['wieczerza wigilijna'],
        'tłusty czwartek' => ['pączkowy czwartek'],
    ];

    public function run(): void
    {
        $raport = [
            'utworzone_tagi' => 0,
            'zaktualizowane_tagi' => 0,
            'utworzone_aliasy' => 0,
            'odrzucone_tagi' => [],
            'odrzucone_aliasy' => [],
        ];

        $zajeteSlugi = Tag::query()->pluck('slug', 'normalized_name')->all();

        foreach (self::KATEGORIE as $kategoria => $nazwy) {
            // Kategoria techniczna jest tylko etykietą raportową na potrzeby
            // tego seedera — dwie sekcje mogą dzielić ten sam
            // `internal_category` w bazie (np. wszystkie podkategorie
            // "danie_*" zapisują po prostu "danie").
            $techniczna = str_starts_with($kategoria, 'danie_') ? 'danie'
                : (str_starts_with($kategoria, 'skladnik_') ? 'skladnik' : $kategoria);

            foreach ($nazwy as $surowaNazwa) {
                $this->importujTag($surowaNazwa, $techniczna, $zajeteSlugi, $raport);
            }
        }

        foreach (self::ALIASY as $kanoniczna => $warianty) {
            $tag = Tag::query()->where('normalized_name', Tag::znormalizujNazwe($kanoniczna))->first();

            if ($tag === null) {
                // Literówka w `ALIASY` wskazująca na nazwę spoza `KATEGORIE`.
                // Zgłaszamy to jako odrzucenie, zamiast cicho pomijać —
                // seeder ma być narzędziem, które wykrywa własne pomyłki.
                $raport['odrzucone_aliasy'][] = "„{$kanoniczna}” (brak takiego tagu kanonicznego)";

                continue;
            }

            foreach ($warianty as $alias) {
                $this->importujAlias($tag, $alias, TagAlias::SOURCE_SEED, $raport);
            }
        }

        // Aliasy AUTOMATYCZNE — transliterowany wariant bez polskich znaków,
        // dla KAŻDEGO kanonicznego tagu, który je ma (SPEC §1.2, patrz
        // komentarz klasy). Osobny przebieg PO imporcie kanonicznych nazw,
        // żeby sprawdzenie kolizji widziało już całą świeżo wstawioną bazę.
        foreach (Tag::query()->where('is_seeded', true)->get() as $tag) {
            $transliterowany = Str::ascii($tag->name);

            if (mb_strtolower($transliterowany) === mb_strtolower($tag->name)) {
                // Nazwa bez diakrytyków od początku — nie ma czego dodawać
                // ("obiad" nie potrzebuje aliasu "obiad").
                continue;
            }

            $this->importujAlias($tag, $transliterowany, TagAlias::SOURCE_SEED, $raport);
        }

        $this->zgloscRaport($raport);
    }

    /**
     * @param  array<string, string>  $zajeteSlugi  normalized_name => slug, aktualizowane w miejscu
     * @param  array{utworzone_tagi: int, zaktualizowane_tagi: int, utworzone_aliasy: int, odrzucone_tagi: list<string>, odrzucone_aliasy: list<string>}  $raport
     */
    private function importujTag(string $surowaNazwa, string $kategoria, array &$zajeteSlugi, array &$raport): void
    {
        $nazwa = trim($surowaNazwa);
        $znormalizowana = Tag::znormalizujNazwe($nazwa);

        if (FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($znormalizowana)) {
            $raport['odrzucone_tagi'][] = "„{$nazwa}” (lokalna baza wulgaryzmów)";

            return;
        }

        $istniejacySlug = $zajeteSlugi[$znormalizowana] ?? null;
        $slug = $istniejacySlug ?? $this->wolnySlug(Tag::slugDlaNazwy($nazwa), $zajeteSlugi);

        $tag = Tag::query()->where('normalized_name', $znormalizowana)->first();

        if ($tag === null) {
            Tag::create([
                'name' => $nazwa,
                'normalized_name' => $znormalizowana,
                'slug' => $slug,
                'is_seeded' => true,
                'internal_category' => $kategoria,
            ]);
            $zajeteSlugi[$znormalizowana] = $slug;
            $raport['utworzone_tagi']++;

            return;
        }

        // Aktualizujemy TYLKO kategorię — nazwa i slug redakcyjnie ustalone
        // raz, patrz komentarz klasy.
        if ($tag->internal_category !== $kategoria) {
            $tag->forceFill(['internal_category' => $kategoria])->save();
            $raport['zaktualizowane_tagi']++;
        }
    }

    /**
     * @param  array{utworzone_tagi: int, zaktualizowane_tagi: int, utworzone_aliasy: int, odrzucone_tagi: list<string>, odrzucone_aliasy: list<string>}  $raport
     */
    private function importujAlias(Tag $tag, string $alias, string $zrodlo, array &$raport): void
    {
        $alias = trim($alias);
        $znormalizowanyAlias = Tag::znormalizujNazwe($alias);

        if ($znormalizowanyAlias === $tag->normalized_name) {
            // Transliteracja, która nic nie zmieniła (np. tag bez diakrytyków
            // podany też w ALIASY przez pomyłkę) — nie jest to błąd, po
            // prostu nie ma czego dodawać.
            return;
        }

        if (FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($znormalizowanyAlias)) {
            $raport['odrzucone_aliasy'][] = "„{$alias}” (lokalna baza wulgaryzmów)";

            return;
        }

        // Kolizja z NAZWĄ KANONICZNĄ innego tagu — to nie jest bezpieczny
        // alias, tylko dwa różne pojęcia, które akurat transliterują się
        // tak samo (D-021: „ta relacja musi pochodzić z bezpiecznej reguły" —
        // kolizja z cudzym tagiem kanonicznym bezpieczną regułą nie jest).
        $kolidujeZTagiem = Tag::query()
            ->where('normalized_name', $znormalizowanyAlias)
            ->where('id', '!=', $tag->getKey())
            ->exists();

        if ($kolidujeZTagiem) {
            $raport['odrzucone_aliasy'][] = "„{$alias}” (koliduje z istniejącym tagiem kanonicznym)";

            return;
        }

        $istniejacy = TagAlias::query()->where('normalized_alias', $znormalizowanyAlias)->first();

        if ($istniejacy !== null) {
            // Alias już istnieje — dla TEGO SAMEGO tagu to nieszkodliwe
            // powtórzenie (seeder chodzi na istniejącej bazie), dla INNEGO
            // to prawdziwa kolizja do zaraportowania.
            if ($istniejacy->tag_id !== $tag->getKey()) {
                $raport['odrzucone_aliasy'][] = "„{$alias}” (już przypięty do innego tagu)";
            }

            return;
        }

        TagAlias::create([
            'tag_id' => $tag->getKey(),
            'alias' => $alias,
            'normalized_alias' => $znormalizowanyAlias,
            'source' => $zrodlo,
        ]);
        $raport['utworzone_aliasy']++;
    }

    /** @param  array<string, string>  $zajete */
    private function wolnySlug(string $baza, array &$zajete): string
    {
        $zajeteSlugi = array_flip($zajete);
        $slug = $baza;
        $sufiks = 2;

        while (isset($zajeteSlugi[$slug]) || Tag::query()->where('slug', $slug)->exists()) {
            $slug = $baza.'-'.$sufiks;
            $sufiks++;
        }

        return $slug;
    }

    /** @param  array{utworzone_tagi: int, zaktualizowane_tagi: int, utworzone_aliasy: int, odrzucone_tagi: list<string>, odrzucone_aliasy: list<string>}  $raport */
    private function zgloscRaport(array $raport): void
    {
        $wiersz = sprintf(
            'TagSeeder: %d nowych tagów, %d zaktualizowanych, %d nowych aliasów, %d odrzuconych tagów, %d odrzuconych aliasów.',
            $raport['utworzone_tagi'],
            $raport['zaktualizowane_tagi'],
            $raport['utworzone_aliasy'],
            count($raport['odrzucone_tagi']),
            count($raport['odrzucone_aliasy']),
        );

        $this->command?->info($wiersz);

        foreach ([...$raport['odrzucone_tagi'], ...$raport['odrzucone_aliasy']] as $powod) {
            $this->command?->warn('  odrzucono: '.$powod);
        }
    }
}
