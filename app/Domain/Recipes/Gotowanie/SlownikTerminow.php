<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

/**
 * Słownik terminów kulinarnych do trybu gotowania (#2343, D-333).
 *
 * CO TO JEST. Statyczna, ręcznie napisana lista słów i technik, które
 * początkujące osoby mylą albo pomijają („zahartuj”, „zredukuj”, „zasmaż”).
 * W kroku przepisu szukamy tych słów i pod krokiem, na żądanie, pokazujemy
 * krótkie wyjaśnienie. To pierwszy etap z issue #2343: „lokalny słownik bez
 * modelu”.
 *
 * CZEGO TU NIE MA, I MA NIE BYĆ.
 * - Modelu AI ani żadnego wywołania sieci: D-333 („AI — nic nowego”). Hasło
 *   pisze i przegląda człowiek, w repozytorium, w PR-ze.
 * - Zapamiętywania, liczenia i zapisu czegokolwiek. Funkcja jest czystą
 *   funkcją tekstu kroku: nie czyta konta, nie zapisuje zdarzeń.
 * - Zmiany treści przepisu. Wyjaśnienie jest osobnym blokiem pod krokiem.
 *
 * JAK DOPISAĆ HASŁO. Nowy element w {@see self::HASLA}: `haslo` (nazwa
 * w mianowniku), `rdzenie` (początki słów, liczone od granicy słowa — łapią
 * odmianę: „zasmaż” trafi w „zasmażkę”, „zasmażyć” i „zasmażce”),
 * `wyjasnienie` (do {@see self::MAKS_DLUGOSC_WYJASNIENIA} znaków, bez rodzaju
 * gramatycznego wobec czytającego). Rdzeń ma być na tyle długi, żeby nie łapać
 * innych słów („zważ” to także „zważ mąkę”, więc „zważyć się” jest poza
 * słownikiem). Pilnuje tego `SlownikTerminowTest`.
 */
final class SlownikTerminow
{
    public const MAKS_DLUGOSC_WYJASNIENIA = 330;

    /**
     * @var list<array{haslo: string, rdzenie: list<string>, wyjasnienie: string}>
     */
    public const HASLA = [
        [
            'haslo' => 'Al dente',
            'rdzenie' => ['al dente'],
            'wyjasnienie' => 'Makaron albo warzywa ugotowane tak, że w środku są jeszcze lekko jędrne i stawiają niewielki opór przy gryzieniu. Zwykle oznacza to minutę lub dwie krócej niż najdłuższy czas z opakowania.',
        ],
        [
            'haslo' => 'Blanszować',
            'rdzenie' => ['blansz', 'zblansz'],
            'wyjasnienie' => 'Bardzo krótko gotować w wodzie, zwykle od jednej do kilku minut, a potem od razu zanurzyć w zimnej wodzie. Warzywo zachowuje kolor i jędrność, a skórka łatwiej schodzi.',
        ],
        [
            'haslo' => 'Bukiet garni',
            'rdzenie' => ['bukiet garni'],
            'wyjasnienie' => 'Pęczek ziół, na przykład tymianku, liścia laurowego i natki pietruszki, związany nitką. Gotuje się go razem z potrawą i wyjmuje na koniec, zanim danie trafi na stół.',
        ],
        [
            'haslo' => 'Deglasować',
            'rdzenie' => ['deglasuj', 'deglasow'],
            'wyjasnienie' => 'Po smażeniu wlać na patelnię odrobinę płynu, na przykład wody, bulionu albo wina, i zeskrobać drewnianą łyżką przypieczone resztki z dna. Dzięki temu sos dostaje głębszy smak.',
        ],
        [
            'haslo' => 'Duszenie',
            'rdzenie' => ['dusz', 'udusz', 'dusi', 'udusi'],
            'wyjasnienie' => 'Gotowanie pod przykryciem, na małym ogniu, w niewielkiej ilości płynu, czasem w samym soku z warzyw lub mięsa. Dzięki temu potrawa mięknie powoli i się nie przypala.',
        ],
        [
            'haslo' => 'Flambirować',
            'rdzenie' => ['flambuj', 'flambir', 'flambow'],
            'wyjasnienie' => 'Polać potrawę mocnym alkoholem i krótko podpalić, żeby alkohol się spalił. To otwarty ogień: odsuń twarz, nie lej alkoholu wprost z butelki nad patelnią i trzymaj pod ręką przykrywkę.',
        ],
        [
            'haslo' => 'Hartowanie',
            'rdzenie' => ['hartuj', 'hartow', 'zahartuj', 'zahartow'],
            'wyjasnienie' => 'Powolne ocieplanie zimnego składnika, na przykład śmietany albo jajek: dodajesz do niego po łyżce gorącego płynu z garnka i mieszasz. Dzięki temu po wlaniu do całości nie zważy się ani nie zetnie.',
        ],
        [
            'haslo' => 'Karmelizować',
            'rdzenie' => ['karmeliz'],
            'wyjasnienie' => 'Podgrzewać cukier albo cebulę, aż zbrązowieje i nabierze słodkiego, lekko palonego smaku. Gorący karmel mocno oparza, więc uważaj na rozpryski.',
        ],
        [
            'haslo' => 'Marynować',
            'rdzenie' => ['marynu', 'marynow', 'zamarynuj'],
            'wyjasnienie' => 'Zostawić mięso, rybę lub warzywa w przyprawionym płynie albo oleju na kilka godzin lub na noc. Składniki robią się bardziej aromatyczne, a mięso kruchsze.',
        ],
        [
            'haslo' => 'Obtoczyć',
            'rdzenie' => ['obtocz', 'obtacz'],
            'wyjasnienie' => 'Przewrócić składnik w mące, bułce tartej albo przyprawach, żeby ze wszystkich stron był równo pokryty cienką warstwą. Nadmiar strzepnij.',
        ],
        [
            'haslo' => 'Panierować',
            'rdzenie' => ['panier', 'spanier'],
            'wyjasnienie' => 'Obtoczyć kolejno w mące, rozbitym jajku i bułce tartej, a dopiero potem smażyć. Panierka tworzy chrupiącą skorupkę, a w środku mięso zostaje soczyste.',
        ],
        [
            'haslo' => 'Pasteryzować',
            'rdzenie' => ['pasteryz', 'spasteryz'],
            'wyjasnienie' => 'Podgrzewać zamknięte słoiki z zawartością w gorącej wodzie lub piekarniku, żeby przetwory dłużej się trzymały. Temperaturę i czas podaje autor przepisu, więc trzymaj się dokładnie jego wskazówek.',
        ],
        [
            'haslo' => 'Podpiec',
            'rdzenie' => ['podpiec', 'podpiek'],
            'wyjasnienie' => 'Wstępnie upiec samo ciasto albo spód, zanim dołożysz nadzienie lub mokre składniki. Dzięki temu spód nie robi się surowy i rozmoczony.',
        ],
        [
            'haslo' => 'Przesiać',
            'rdzenie' => ['przesiej', 'przesia', 'przesiew'],
            'wyjasnienie' => 'Przepuścić mąkę lub inny suchy składnik przez sitko. Rozbija to grudki i wprowadza do mąki powietrze, więc ciasto wychodzi lżejsze.',
        ],
        [
            'haslo' => 'Redukować',
            'rdzenie' => ['redukuj', 'zredukuj', 'redukow', 'zredukow'],
            'wyjasnienie' => 'Gotować sos albo wywar bez przykrycia, aż część wody odparuje. Płyn gęstnieje, jest go mniej, a smak robi się intensywniejszy. Mieszaj od czasu do czasu, żeby nie przywarł do dna.',
        ],
        [
            'haslo' => 'Rumienić',
            'rdzenie' => ['zarumie', 'zrumie', 'rumie'],
            'wyjasnienie' => 'Smażyć albo piec, aż powierzchnia przybierze złotą lub jasnobrązową barwę. Taki kolor oznacza smak; ciemnobrązowy lub czarny to już przypalenie.',
        ],
        [
            'haslo' => 'Sparzyć',
            'rdzenie' => ['sparz'],
            'wyjasnienie' => 'Zalać wrzątkiem na krótką chwilę, a potem odlać. Tak robi się na przykład z pomidorami, żeby łatwo zeszła z nich skórka.',
        ],
        [
            'haslo' => 'Sztywna piana',
            'rdzenie' => ['sztywna pian', 'sztywną pian', 'sztywnej pian', 'sztywnych pian', 'na sztywno'],
            'wyjasnienie' => 'Ubijać, aż piana trzyma kształt i nie spływa, gdy odwrócisz miskę do góry dnem. Naczynie i trzepaczka mają być suche i czyste, a w białku nie może być nawet odrobiny żółtka.',
        ],
        [
            'haslo' => 'Szumowiny',
            'rdzenie' => ['szumowin', 'szumow'],
            'wyjasnienie' => 'Szara piana i zanieczyszczenia, które wypływają na wierzch gotującego się rosołu lub wywaru. Zbiera się je łyżką, a wywar jest dzięki temu czystszy i klarowny.',
        ],
        [
            'haslo' => 'Wyrabianie ciasta',
            'rdzenie' => ['wyrób', 'wyrab'],
            'wyjasnienie' => 'Długo ugniatać ciasto rękami albo mikserem z hakiem, aż będzie gładkie, elastyczne i przestanie kleić się do rąk. Przy drożdżowym trwa to zwykle kilkanaście minut.',
        ],
        [
            'haslo' => 'Wyrastanie',
            'rdzenie' => ['wyrośn', 'wyrast'],
            'wyjasnienie' => 'Czas, kiedy ciasto drożdżowe odpoczywa pod przykryciem w cieple i rośnie. Zwykle trwa około godziny, a gotowe jest wtedy, gdy mniej więcej podwoi objętość.',
        ],
        [
            'haslo' => 'Zaparzyć',
            'rdzenie' => ['zaparz'],
            'wyjasnienie' => 'Zalać wrzątkiem i zostawić na wskazany czas pod przykryciem, żeby składnik oddał smak lub zmiękł.',
        ],
        [
            'haslo' => 'Zaprawić zupę',
            'rdzenie' => ['zaprawi', 'zaprawia'],
            'wyjasnienie' => 'Dodać do gorącej zupy lub sosu śmietanę albo mąkę rozmieszaną z zimnym płynem, żeby go zagęścić i wzbogacić. Śmietanę najpierw zahartuj, wtedy zupa się nie zważy.',
        ],
        [
            'haslo' => 'Zasmażka',
            'rdzenie' => ['zasmaż'],
            'wyjasnienie' => 'Mąka podsmażona na tłuszczu, na przykład na maśle, którą zagęszcza się zupy i sosy. Smaż, mieszając, aż mąka lekko się zrumieni i przestanie pachnieć surowo. Potem wlewaj płyn stopniowo, ciągle mieszając, żeby nie było grudek.',
        ],
        [
            'haslo' => 'Zeszklić',
            'rdzenie' => ['zeszkl'],
            'wyjasnienie' => 'Smażyć cebulę na małym ogniu, aż zrobi się miękka i półprzezroczysta, ale jeszcze się nie zarumieni.',
        ],
    ];

    /**
     * Hasła, których rdzenie występują w tekście kroku, w kolejności pierwszego
     * wystąpienia w tekście, bez powtórzeń.
     *
     * @return list<array{haslo: string, rdzenie: list<string>, wyjasnienie: string}>
     */
    public static function wTekscie(string $tekst): array
    {
        $male = mb_strtolower($tekst);
        $trafione = [];

        foreach (self::HASLA as $indeks => $haslo) {
            $pozycja = null;
            foreach ($haslo['rdzenie'] as $rdzen) {
                $wzor = '/(?<![\p{L}\p{N}])'.preg_quote($rdzen, '/').'/u';
                if (preg_match($wzor, $male, $wynik, PREG_OFFSET_CAPTURE) === 1) {
                    $miejsce = (int) $wynik[0][1];
                    $pozycja = $pozycja === null ? $miejsce : min($pozycja, $miejsce);
                }
            }
            if ($pozycja !== null) {
                $trafione[$indeks] = $pozycja;
            }
        }

        asort($trafione);

        return array_values(array_map(fn (int $indeks): array => self::HASLA[$indeks], array_keys($trafione)));
    }
}
