<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use Illuminate\Support\Str;

/**
 * Lista wulgaryzmów i obelg blokująca TWORZENIE nowego tagu
 * (R1 §8, w odpowiedzi na SPEC §1.10).
 *
 * DLACZEGO TAG JEST TRAKTOWANY OSTRZEJ NIŻ TREŚĆ WPISU
 * Tag jest publiczną etykietą indeksowaną przez wyszukiwarkę i ma własną
 * stronę (`/tag/{slug}`) — wyższa ekspozycja niż wolny tekst wpisu, który
 * wymaga wejścia pod konkretną treść. Ten filtr blokuje wyłącznie
 * TWORZENIE nowego tagu (w `App\Domain\Tags\Actions\ResolveTagsForPost`
 * i w `TagSeeder`) — treść wpisu ma osobną warstwę moderacji (SPEC §1.11).
 *
 * SKĄD SIĘ WZIĘŁA TA LISTA
 * Pierwsza wersja miała 48 haseł pisanych z pamięci i była jawnie za krótka:
 * praktycznie nie zawierała mowy nienawiści, czyli tej jednej kategorii,
 * która w publicznej przestrzeni nazw robi realną krzywdę i jest wprost
 * przedmiotem art. 16 DSA. Obecna lista pochodzi z zamówionej kuracji
 * językowej, która leży w repozytorium jako
 * `database/seeders/dane/lista-wulgaryzmow.json` — razem z 215 wyjątkami,
 * 18 rozstrzygniętymi kolizjami i uzasadnieniem każdego z nich.
 * `Tests\Unit\FiltrWulgaryzmowZgodnyZeZrodlemTest` pilnuje, że stała w tym
 * pliku i plik źródłowy się nie rozjechały — w żadną stronę.
 *
 * Stała, a nie odczyt pliku w `zawieraNiedozwoloneSlowo()`, bo ta metoda
 * jest wołana raz na każdą nazwę tagu: przy wczytywaniu słownika to 1446
 * wywołań w jednym przebiegu. Stała trafia do cache'u opcodeów i nie ma
 * trybu awarii „brak pliku".
 *
 * DOPASOWANIE PO CAŁYCH TOKENACH, NIGDY `str_contains()`
 * SPEC §1.10 nazywa to wprost: dopasowanie substringu blokowałoby niewinne
 * słowa zawierające zakazany ciąg wewnątrz innego słowa (efekt Scunthorpe).
 * W tym projekcie to nie jest hipoteza — `str_contains` zgłaszał już
 * „konfiturę" za „fit" i „kuchnię łęczycką" za „leczy". Tag dzieli się na
 * tokeny po spacji i myślniku, a każdy token jest sprawdzany OSOBNO
 * i W CAŁOŚCI. Znane obejścia (`k.u.r.w.a`, `k u r w a`, `kurwazupa`)
 * NIE są tu łapane i nie wolno ich „naprawiać" dopasowaniem podciągu —
 * wróciłoby blokowanie poprawnych nazw kulinarnych. Właściwym miejscem na
 * te przypadki jest polityka dopuszczalnych znaków w nazwie tagu, nie ten
 * filtr.
 *
 * NORMALIZACJA CELOWO UŻYWA `Str::ascii()` (usuwa diakrytyki) —
 * W ODRÓŻNIENIU od `Tag::znormalizujNazwe()`, które go NIE używa.
 * To dwa różne pytania. `Tag::znormalizujNazwe()` odpowiada na „czy to jest
 * ten sam tag" (gdzie `zurek` i `żurek` MUSZĄ móc pozostać różne — D-021,
 * a dostarczony słownik osobną decyzją redakcyjną ustawia `zurek` jako
 * alias `żurka`). Ten filtr odpowiada na „czy to jest to samo wulgarne
 * słowo" — a tam forma z ogonkami i bez nich to wciąż jedno słowo.
 */
final class FiltrWulgaryzmow
{
    /**
     * Hasła, które przywróciliśmy do blokady WBREW wyłączeniu dostawcy listy.
     *
     * Dostawca wyłączył pięć rodzin ostrożnościowo, każdą z prawdziwym
     * argumentem: `czarnuchy` to także nadrodzina owadów, `kutas` to
     * dawny frędzel, `pedał` to mechanizm w koszu na śmieci, `ciota` bywa
     * gwarowym określeniem ciotki, a użyć `żydostwa` w tekstach o kulturze
     * nie umiał bezpiecznie rozstrzygnąć.
     *
     * Nie przyjmujemy tych wyłączeń i to jest decyzja, nie przeoczenie.
     * Powód jest jeden i wynika z tego, CZYM jest ta lista: bramką na nazwę
     * publicznego tagu w serwisie o gotowaniu. Nikt w dobrej wierze nie
     * nazwie przepisu `czarnuchy`, `kutas` ani `zydostwo`. Koszt fałszywego
     * trafienia to jedna osoba wybierająca inną nazwę; koszt przepuszczenia
     * to obelga na indeksowanej stronie `/tag/{slug}`, którą widzą wszyscy
     * i za którą odpowiada serwis, a nie jej autor. Cztery z tych pięciu
     * rodzin uderzają w cechę chronioną, a `pedał` i `ciota` były na liście
     * od pierwszego dnia — ich zdjęcie byłoby zmianą polityki, nie
     * porządkowaniem danych.
     *
     * Wyłączenia dostawcy, które PRZYJĘLIŚMY, są niżej.
     *
     * @var list<string>
     */
    public const PRZYWROCONE_WBREW_ZRODLU = [
        'ciot', 'ciota', 'ciotach', 'ciotami', 'ciote', 'cioto', 'ciotom', 'cioty',
        'czarnuch', 'czarnucha', 'czarnuchach', 'czarnuchami', 'czarnuchem', 'czarnuchom',
        'czarnuchow', 'czarnuchowi', 'czarnuchu', 'czarnuchy',
        'kutas', 'kutasa', 'kutasach', 'kutasami', 'kutasem', 'kutasie', 'kutasom',
        'kutasow', 'kutasowi', 'kutasy',
        'pedal', 'pedalach', 'pedalami', 'pedalem', 'pedalom', 'pedalow', 'pedalu', 'pedaly',
        'szmato',
        'zydostwa', 'zydostwem', 'zydostwie', 'zydostwo', 'zydostwu',
    ];

    /**
     * Hasła, które BYŁY na starej, 48-elementowej liście i świadomie z niej
     * zniknęły — bo mają prawdziwe, dosłowne znaczenie, które w kuchni albo
     * przy ogródku naprawdę wystąpi.
     *
     * `gnida` i `menda` to nazwy pasożytów, `gnój` to nawóz, a `szmata`
     * to ścierka. Wszystkie cztery są obelgami dopiero wobec człowieka,
     * a token sam z siebie tego nie wie. W odróżnieniu od rodzin wyżej żadne
     * z nich nie uderza w cechę chronioną, więc rachunek kosztów wychodzi
     * odwrotnie: fałszywe trafienie jest tu realne, a szkoda z przepuszczenia
     * mała. Wołacz `szmato` blokujemy dalej, bo ten NIE ma znaczenia
     * dosłownego — ścierki nikt nie zawoła.
     *
     * Lista istnieje po to, żeby cofnięcie tej decyzji było widoczne:
     * test pilnuje, że te słowa nie są blokowane.
     *
     * @var list<string>
     */
    public const PRZYJETE_WYLACZENIA = [
        'gnida', 'gnoj', 'gnoju', 'menda', 'szmata',
    ];

    /**
     * Hasła, PO transliteracji i małych literach — 463 pozycje.
     *
     * „PO TRANSLITERACJI" TO NIE OPIS, A WARUNEK POPRAWNOŚCI.
     * Hasło zapisane tu z polskim znakiem nie zablokuje NIGDY niczego: do
     * porównania dochodzi już `pedal`, nie `pedał`. Dwa hasła stały tu
     * właśnie w ten sposób (`pedał`, `jebnięty`), więc lista wyglądała na
     * dłuższą, niż była — 48 pozycji, z których działało 46.
     * `Tests\Unit\FiltrWulgaryzmowBezMartwychHaselTest` pilnuje tego
     * niezmiennika dla każdej pozycji, także dopisanych w przyszłości.
     *
     * NIE DOPISUJ TUTAJ HASŁA WPROST. Źródłem jest
     * `database/seeders/dane/lista-wulgaryzmow.json` (albo, dla świadomego
     * sporu z nim, `PRZYWROCONE_WBREW_ZRODLU` wyżej) — inaczej
     * `FiltrWulgaryzmowZgodnyZeZrodlemTest` zapali się i będzie miał rację.
     *
     * @var list<string>
     */
    private const SLOWA = [
        'arabuch', 'arabucha', 'arabuchach', 'arabuchami', 'arabuchem', 'arabuchom',
        'arabuchow', 'arabuchowi', 'arabuchu', 'arabuchy', 'chuj', 'chuja', 'chujach',
        'chujami', 'chuje', 'chujem', 'chujnia', 'chujom', 'chujow', 'chujowa', 'chujowe',
        'chujowego', 'chujowej', 'chujowi', 'chujowo', 'chujowy', 'chujowych', 'chujowym',
        'chuju', 'ciot', 'ciota', 'ciotach', 'ciotami', 'ciote', 'cioto', 'ciotom', 'ciotowata',
        'ciotowate', 'ciotowatego', 'ciotowatej', 'ciotowaty', 'ciotowatych', 'ciotowatym',
        'cioty', 'cip', 'cipa', 'cipach', 'cipami', 'cipe', 'cipie', 'cipo', 'cipom', 'cipy',
        'cwel', 'cwela', 'cwelach', 'cwelami', 'cwele', 'cwelem', 'cwelom', 'cwelow', 'cwelowi',
        'cwelu', 'czarnuch', 'czarnucha', 'czarnuchach', 'czarnuchami', 'czarnuchem',
        'czarnuchom', 'czarnuchow', 'czarnuchowi', 'czarnuchu', 'czarnuchy', 'debil', 'debila',
        'debilach', 'debilami', 'debile', 'debilek', 'debilem', 'debili', 'debilka',
        'debilkach', 'debilkami', 'debilke', 'debilki', 'debilko', 'debilkom', 'debilom',
        'debilowi', 'debilu', 'dopierdol', 'dopierdolic', 'dopierdolil', 'dopierdolila',
        'dopierdolona', 'dopierdolone', 'dopierdolony', 'dziwek', 'dziwka', 'dziwkach',
        'dziwkami', 'dziwke', 'dziwki', 'dziwko', 'dziwkom', 'huj', 'huja', 'huje', 'hujem',
        'hujow', 'hujowa', 'hujowe', 'hujowy', 'huju', 'idiota', 'idiotach', 'idiotami',
        'idiote', 'idiotek', 'idiotka', 'idiotkach', 'idiotkami', 'idiotke', 'idiotki',
        'idiotko', 'idiotkom', 'idioto', 'idiotom', 'idiotow', 'idioty', 'imbecyl', 'imbecyla',
        'imbecylach', 'imbecylami', 'imbecyle', 'imbecylem', 'imbecylom', 'imbecylow',
        'imbecylowi', 'imbecylu', 'jebac', 'jebal', 'jebala', 'jebalam', 'jebalas', 'jebalem',
        'jebales', 'jebali', 'jebaly', 'jebana', 'jebane', 'jebanego', 'jebanej', 'jebani',
        'jebany', 'jebanych', 'jebanym', 'jebia', 'jebie', 'jebiesz', 'jebnac', 'jebnal',
        'jebnela', 'jebneli', 'jebnieci', 'jebnieta', 'jebniete', 'jebnietego', 'jebnietej',
        'jebniety', 'jebnietych', 'jebnietym', 'jebnij', 'jebnijcie', 'kretyn', 'kretyna',
        'kretynach', 'kretynami', 'kretynek', 'kretynem', 'kretyni', 'kretynie', 'kretynka',
        'kretynkach', 'kretynkami', 'kretynke', 'kretynki', 'kretynko', 'kretynkom', 'kretynom',
        'kretynow', 'kretynowi', 'kurew', 'kurewska', 'kurewski', 'kurewskie', 'kurewskiego',
        'kurewskiej', 'kurewsko', 'kurwa', 'kurwach', 'kurwami', 'kurwe', 'kurwica', 'kurwie',
        'kurwo', 'kurwom', 'kurwy', 'kutas', 'kutasa', 'kutasach', 'kutasami', 'kutasem',
        'kutasie', 'kutasom', 'kutasow', 'kutasowi', 'kutasy', 'najebac', 'najebal', 'najebala',
        'najebana', 'najebane', 'najebanego', 'najebanej', 'najebany', 'najebanych',
        'najebanym', 'odjebac', 'odjebal', 'odjebala', 'odjebali', 'odjebana', 'odjebane',
        'odjebany', 'odpierdol', 'odpierdolic', 'odpierdolil', 'odpierdolila', 'odpierdolona',
        'odpierdolone', 'odpierdolony', 'pedal', 'pedalach', 'pedalami', 'pedalem', 'pedalom',
        'pedalow', 'pedalska', 'pedalski', 'pedalskich', 'pedalskie', 'pedalskiego',
        'pedalskiej', 'pedalskim', 'pedalstwo', 'pedalu', 'pedaly', 'pierdol', 'pierdola',
        'pierdole', 'pierdoleni', 'pierdoli', 'pierdolic', 'pierdolil', 'pierdolila',
        'pierdolili', 'pierdolily', 'pierdolisz', 'pierdolona', 'pierdolone', 'pierdolonego',
        'pierdolonej', 'pierdolony', 'pierdolonych', 'pierdolonym', 'pizd', 'pizda', 'pizdach',
        'pizdami', 'pizde', 'pizdo', 'pizdom', 'pizdy', 'pizdzie', 'pojeb', 'pojeba',
        'pojebach', 'pojebami', 'pojebana', 'pojebane', 'pojebanego', 'pojebanej', 'pojebani',
        'pojebany', 'pojebanych', 'pojebanym', 'pojebem', 'pojebie', 'pojebom', 'pojebow',
        'pojebowi', 'pojeby', 'przyglup', 'przyglupa', 'przyglupach', 'przyglupami',
        'przyglupem', 'przyglupie', 'przyglupom', 'przyglupow', 'przyglupowi', 'przyglupy',
        'rozjebac', 'rozjebal', 'rozjebala', 'rozjebana', 'rozjebane', 'rozjebanego',
        'rozjebanej', 'rozjebany', 'rozjebanych', 'rozjebanym', 'rozpierdala', 'rozpierdalac',
        'rozpierdalaja', 'rozpierdol', 'rozpierdolic', 'rozpierdolil', 'rozpierdolila',
        'rozpierdolona', 'rozpierdolone', 'rozpierdolony', 'skurwiel', 'skurwiela',
        'skurwielach', 'skurwielami', 'skurwiele', 'skurwielem', 'skurwieli', 'skurwielom',
        'skurwielowi', 'skurwielu', 'skurwysyn', 'skurwysyna', 'skurwysynach', 'skurwysynami',
        'skurwysynem', 'skurwysynie', 'skurwysynom', 'skurwysynow', 'skurwysynowi',
        'skurwysynska', 'skurwysynski', 'skurwysynskie', 'skurwysynstwo', 'skurwysynu',
        'skurwysyny', 'spierdala', 'spierdalac', 'spierdalaj', 'spierdalaja', 'spierdalajaca',
        'spierdalajacy', 'spierdalajcie', 'spierdalal', 'spierdalala', 'spierdalam',
        'spierdalasz', 'sukinsyn', 'sukinsyna', 'sukinsynach', 'sukinsynami', 'sukinsynem',
        'sukinsynie', 'sukinsynom', 'sukinsynow', 'sukinsynowi', 'sukinsynu', 'sukinsyny',
        'szmato', 'wkurw', 'wkurwia', 'wkurwiaja', 'wkurwiam', 'wkurwiasz', 'wkurwic',
        'wkurwieni', 'wkurwiona', 'wkurwione', 'wkurwionego', 'wkurwionej', 'wkurwiony',
        'wkurwionych', 'wkurwionym', 'wyjebac', 'wyjebal', 'wyjebala', 'wyjebali', 'wyjebana',
        'wyjebane', 'wyjebanego', 'wyjebanej', 'wyjebany', 'wyjebanych', 'wyjebanym',
        'wypierdala', 'wypierdalac', 'wypierdalaj', 'wypierdalaja', 'wypierdalajcie',
        'wypierdalal', 'wypierdalala', 'wypierdalam', 'wypierdalasz', 'zajebac', 'zajebal',
        'zajebala', 'zajebali', 'zajebana', 'zajebane', 'zajebany', 'zajebiscie', 'zajebista',
        'zajebiste', 'zajebistego', 'zajebistej', 'zajebisty', 'zajebistych', 'zajebistym',
        'zapierdala', 'zapierdalac', 'zapierdalaj', 'zapierdalaja', 'zapierdalajcie',
        'zapierdalal', 'zapierdalala', 'zapierdalam', 'zapierdalasz', 'zydostwa', 'zydostwem',
        'zydostwie', 'zydostwo', 'zydostwu', 'zydzior', 'zydziora', 'zydziorach', 'zydziorami',
        'zydziorem', 'zydziorom', 'zydziorow', 'zydziorowi', 'zydziory', 'zydziorze', 'zydzisk',
        'zydziska', 'zydziskach', 'zydziskami', 'zydziskiem', 'zydzisko', 'zydziskom',
        'zydzisku',    ];

    public static function zawieraNiedozwoloneSlowo(string $fraza): bool
    {
        $znormalizowana = mb_strtolower(Str::ascii($fraza));
        $tokeny = preg_split('/[\s-]+/u', $znormalizowana, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokeny === false) {
            return false;
        }

        foreach ($tokeny as $token) {
            if (in_array($token, self::SLOWA, true)) {
                return true;
            }
        }

        return false;
    }
}
