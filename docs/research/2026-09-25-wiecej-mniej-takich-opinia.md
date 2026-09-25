# Opinia: „więcej / mniej takich” i zakaz algorytmicznego feedu

**Data:** 25 września 2026.
**Status:** opinia do decyzji właściciela. Niczego nie zmienia w zasadach:
`AGENTS.md` §8 i §12 obowiązują bez zmian, dopóki właściciel nie zapisze nowej
decyzji w `docs/DECISIONS.md`.
**Odpowiada na:** prośbę „Przyciski »więcej / mniej takich treści« i zakaz
algorytmicznego feedu w Kukingu” (punkty 1–8 i forma odpowiedzi z §6 prośby).
**Autor:** asystent AI (Claude Code) pracujący na repozytorium Kukinga.
Opinia ma więc jedną przewagę nad niezależnym recenzentem: widzi kod. Tam, gdzie
kod zmienia ocenę, jest to napisane wprost.

## Jak czytać ten dokument

Każde twierdzenie ma jedno z czterech oznaczeń:

| Oznaczenie | Znaczenie |
|---|---|
| **Fakt [S*n*]** | stoi w źródle nr *n* z sekcji „Źródła” |
| **Fakt (repo)** | sprawdzone w kodzie tego repozytorium 25.09.2026, z plikiem |
| **Opinia** | mój wniosek; może być błędny |
| **Szacunek** | liczba wyliczona albo przyjęta przeze mnie, nie zmierzona |

Źródła sprawdzono w sieci 25.09.2026. Przy każdym w sekcji „Źródła” jest
stopień sprawdzenia: *sprawdzone* (przeczytany tekst albo oficjalne
streszczenie), *częściowo* (potwierdzone istnienie i główna teza, szczegóły
z omówień), *z pamięci* (źródła nie otwarto w tej sesji).

---

## Rekomendacja w pięciu zdaniach

1. **Nie budowałbym teraz ani przycisku „więcej takich”, ani „mniej takich”.**
   Przy 20 osobach problemem Kukinga jest niedobór wpisów, nie nadmiar. Filtry
   i ranking rozwiązują nadmiar, a przy niedoborze tylko pogłębiają pustkę.
2. **Zamiast kodu zrobiłbym dwutygodniowy test z tymi 20 osobami:** rozmowy
   o tym, co ludzie rozumieją pod każdą nazwą, oraz dzienniczek „czego nie
   chcę widzieć i dlaczego”. Ten test rozstrzyga, czy ukrywać osobę, temat czy
   pojedynczy wpis.
3. **Zakaz rankingu utrzymałbym, ale przepisał go z nazwy techniki na nazwy
   własności:** nie porządkujemy po popularności ani zaangażowaniu, nie
   wnioskujemy o upodobaniach z zachowania, nie ukrywamy niczego, o czym
   człowiek nie wie. Dzisiejsze słowa „algorytmiczny feed” zakazują czegoś, co
   Kuking już ma, na przykład regułę „jeden wpis na autora”, a nie nazywają
   tego, przed czym naprawdę chronią.
4. **Jeśli test potwierdzi potrzebę, „mniej” ma być jawnym, odwracalnym
   filtrem, a nie wagą.** Filtr działa wszędzie tam, gdzie Kuking sam
   podsuwa wpisy (także w feedzie obserwowanych i w tygodniowym liście), a nie
   działa tam, gdzie człowiek sam szuka. „Więcej” ma być nazwane tym, co
   naprawdę robi: „Obserwuj Halinę” albo „Zobacz więcej o: pierogi”.
5. **Zanim cokolwiek z tego powstanie, trzeba naprawić dwie rzeczy w kodzie.**
   Obserwowanie tematu dziś nie zmienia Startu nikomu, kto obserwuje choćby
   jedną osobę. Obserwowanie osoby wysyła jej powiadomienie. Skrót „więcej
   takich = obserwuj” byłby więc albo martwym przyciskiem, albo gestem
   społecznym, którego napis nie zapowiada.

---

## Założenia, które uważam za błędne

### B1. „Algorytmiczny feed” jest źle nazwanym zakazem

- **Fakt (repo):** Kuking już dziś porządkuje wpisy regułami, a nie samą
  datą. Odkrywanie wybiera jeden wpis na autora przez `DISTINCT ON`
  ([`DiscoverFeed`](../../app/Domain/Feed/DiscoverFeed.php)). Tablica ma część
  automatyczną ([`DailyBoard`](../../app/Domain/Feed/DailyBoard.php)).
  Wyszukiwarka układa wyniki po podobieństwie trigramowym.
- **Fakt [S27]:** definicja „systemu rekomendacji” w DSA (art. 3 lit. s)
  obejmuje każdy system, który „w pełni lub częściowo automatycznie” podsuwa
  informacje albo w inny sposób ustala ich względną kolejność lub
  eksponowanie, także w wynikach wyszukiwania.
- **Opinia:** zakaz „algorytmicznego feedu” czytany dosłownie zakazuje
  więc mechanizmów, które chronią nowych autorów, a nie nazywa tego, co
  szkodzi. Szkodzą trzy konkretne rzeczy: kolejność według popularności lub
  zaangażowania, wnioskowanie o upodobaniach z zachowania oraz ukrywanie
  czegoś bez wiedzy człowieka. Zakaz powinien wymieniać te trzy rzeczy.
- **Powiązany błąd w dokumentacji (repo):**
  [`COMPLIANCE.md`](../legal/COMPLIANCE.md) w pierwszym akapicie zakłada
  „brak systemu rekomendacji w rozumieniu DSA na MVP”. W świetle art. 3 lit. s
  to założenie jest moim zdaniem nieprawdziwe. Skutek prawny jest dziś mały,
  bo art. 27 leży w sekcji objętej zwolnieniem z art. 19. Zdanie trzeba jednak
  poprawić, zanim ktoś zbuduje na nim wniosek.

### B2. „Więcej takich = skrót do obserwowania” nie działa tak, jak zakłada research

- **Fakt (repo):** Start pokazuje wpisy tylko z **jednego** źródła, w kolejności
  obserwowani → tagi → odkrywanie
  ([`FeedController::aktualneZrodloFeedu()`](../../app/Http/Controllers/FeedController.php)).
  Kto obserwuje choć jedną osobę, która coś opublikowała, ten wpisów
  z obserwowanych tematów na Starcie nie widzi wcale.
- **Wniosek:** „Chcę widzieć więcej takich” zamienione na „obserwuj temat”
  byłoby dla większości aktywnych osób akcją bez widocznego skutku. To jest
  martwy przycisk w rozumieniu D-053, tyle że martwy „po cichu”.
- **Fakt (repo):** obserwowanie osoby tworzy powiadomienie „X zaczyna Cię
  obserwować” ([`FollowUser`](../../app/Domain/Social/Actions/FollowUser.php)),
  a nowi obserwujący trafiają też do tygodniowego listu
  ([`ZbierzTresciDigestu`](../../app/Domain/Digest/ZbierzTresciDigestu.php)).
- **Wniosek:** napis „więcej takich” nie mówi, że autor się o tym dowie.
  Ukryty gest społeczny w społeczności 50+, gdzie ludzie się znają, jest
  poważniejszą wadą niż w anonimowym serwisie.

### B3. „Odkrywanie to bezpieczne miejsce na eksperymenty z rankingiem”

- **Opinia:** jest odwrotnie. Odkrywanie widzi każda nowa osoba, bo jej feed
  obserwowanych jest pusty (`AGENTS.md` §8). To tam powstają pierwsze
  obserwacje, a pierwsze obserwacje decydują, czy nowy autor dostanie
  jakikolwiek odzew. Ranking według popularności działa najmocniej właśnie
  w takim punkcie wejścia (mechanizm z [S13]). Dla nowych autorów ranking
  w feedzie obserwowanych byłby mniej szkodliwy niż ranking w Odkrywaniu.
  Nie jest to argument za tym pierwszym.

### B4. „Mniej takich nie działa w feedzie obserwowanych”

- **Opinia:** ludzie nie odróżniają „Odkrywania” od „feedu obserwowanych”.
  Odróżniają „Kuking mi to pokazał” od „sam(a) tego szukam”. Kto rano ukryje
  temat „podroby”, a wieczorem zobaczy podroby na Starcie, uzna, że przycisk
  nie działa.
- **Fakt [S19]:** w badaniu Mozilli 39,3% ankietowanych uznało, że kontrolki
  YouTube'a nie zmieniają niczego, a łącznie 62,3%, że nie działają albo
  działają niekonsekwentnie.
- **Propozycja:** granica powinna przebiegać między tym, co Kuking
  **podsuwa**, a tym, czego człowiek **szuka** (szczegóły w punkcie 4).

### B5. „Nie mamy danych o zachowaniu”

- **Fakt (repo):** najważniejsze dane już są i nie wymagają śledzenia
  wyświetleń. Chodzi o to, czy pierwszy wpis nowej osoby dostał odzew:
  komentarz, ugotowanie, polubienie albo zapis. Wszystko to leży w tabelach,
  a moduł `app/Domain/Analytics` liczy już podobne rzeczy (`ZasiegUgotowalem`,
  `CookRetentionCohorts`).
- **Opinia:** brakuje danych o **nadmiarze**, bo nadmiaru jeszcze nie ma.
  I to jest argument przeciw rankingowi, nie za badaniem zachowań.

### B6. „Jawne »nie pokazuj mi X« to ustawienie konta, a nie profilowanie” jest prawdą niepełną

- **Opinia:** co do profilowania research ma rację. Brakuje jednak art. 9 RODO.
  Lista tematów obserwowanych i ukrytych może pośrednio ujawniać zdrowie
  (np. „dieta cukrzycowa”, „bezglutenowe”) albo przekonania religijne
  (np. „post”, „koszerne”).
- **Fakt [S30]:** według TSUE dane, które **pośrednio** ujawniają kategorię
  szczególną, podlegają art. 9.
- **Fakt (repo):** ten problem istnieje już dziś, niezależnie od nowego
  pomysłu. Obserwowane tagi są w eksporcie danych jako `obserwowane_tagi`
  ([`CollectUserExportData`](../../app/Domain/Users/Exports/CollectUserExportData.php)).
  Szczegóły w punkcie 7.

---

## 1. Zakaz algorytmicznego feedu

### Teza

Zakaz **porządkowania według popularności i zaangażowania** oraz zakaz
**wnioskowania z zachowania** są słuszne teraz i pozostaną słuszne przy kilku
tysiącach użytkowników. Samo sformułowanie „algorytmiczny feed” trzeba
zastąpić listą własności (B1). Zdanie z `AGENTS.md` §8, że ranking „wyłącza
publikowanie u większości”, jest mocniejsze, niż pozwalają dowody. Należy je
opisać jako prawdopodobną hipotezę, a nie jako fakt.

### Argumenty za utrzymaniem zakazu

1. **Ranking rozwiązuje problem nadmiaru, którego Kuking nie ma.**
   - **Fakt [S1]:** Instagram przeszedł na ranking, bo w 2016 roku ludzie
     nie widzieli 70% wpisów w swoim feedzie, w tym prawie połowy wpisów od
     bliskich osób.
   - **Szacunek:** Kuking pokazuje 15 wpisów na stronę
     (`config/kuking.php`, `feed.page_size`). Przy 20 osobach Odkrywanie
     mieści na pierwszej stronie prawie każdego, kto w ogóle coś opublikował.
2. **Nowi autorzy wracają, jeśli dostali odzew.**
   - **Fakt [S7]:** w newsgroupach nowicjusz, który dostał odpowiedź na
     pierwszy post, pisał ponownie z prawdopodobieństwem 56% zamiast 44%.
   - **Fakt [S8]:** w danych o ok. 140 tys. nowych użytkowników Facebooka
     odzew i szeroka publiczność przewidywały dalsze publikowanie.
   - **Opinia:** ranking oparty na zaangażowaniu z definicji daje najmniej
     zasięgu temu, kto jeszcze nie ma odzewu.
3. **Sygnały popularności zwiększają nierówność i przypadkowość sukcesu.**
   - **Fakt [S13]:** w eksperymencie z ok. 14 tys. uczestników pokazanie
     wcześniejszych wyborów innych zwiększyło zarówno nierówność, jak
     i nieprzewidywalność sukcesu.
   - **Fakt [S14]:** symulacje pokazują, że systemy uczone na danych
     z własnych rekomendacji ujednolicają zachowanie użytkowników bez wzrostu
     użyteczności.
4. **Osoby 50+ gorzej rozumieją ranking i rzadziej próbują nim sterować.**
   - **Fakt [S3]:**
     - tylko 38% użytkowników Facebooka w wieku 50+ uważa, że rozumie, dlaczego
       dany wpis jest w ich feedzie (wśród osób 18–29 lat: 59%);
     - 37% osób 50+ uważa, że nie ma żadnej kontroli nad feedem (wśród osób
       18–49 lat: 20%);
     - próbę wpłynięcia na feed zgłosiło 28% osób w wieku 50–64 i 19% osób
       65+ (wśród osób poniżej 50 lat: 46%).
   - **Fakt [S5]:** świadomość algorytmów zależy od wieku i wykształcenia.
5. **Ranking w serwisie o relacjach psuje relacje.**
   - **Fakt [S4]:** 62,5% z 40 badanych nie wiedziało, że feed Facebooka jest
     filtrowany. Brak wpisów przypisywali znajomym, a nie algorytmowi; tytuł
     badania cytuje jedną z tych osób: „I always assumed that I wasn't really
     that close to [her]”.
   - **Opinia:** w Kukingu, gdzie sercem jest „ktoś ugotował mój przepis”,
     to najgorszy możliwy błąd.
6. **Zaangażowanie nie jest tym samym co zadowolenie.**
   - **Fakt [S15]:** ranking Twittera oparty na zaangażowaniu wzmacniał
     treści, których użytkownicy według własnych deklaracji nie woleli.
   - **Fakt [S16]:** formalny model pokazuje, że optymalizacja zaangażowania
     przy niespójnych preferencjach może działać przeciw użytkownikowi.
7. **Obietnica chronologii jest już częścią marki.**
   - **Fakt (repo):** stopka tablicy mówi „Tu nie ma rankingu. Pokazujemy różne
     osoby, nie najlepsze.” (`components/kuking-board.blade.php`).
   - **Fakt [S17]:** przejście Twittera na timeline algorytmiczny wywołało
     zorganizowany opór użytkowników (#RIPTwitter).

### Argumenty za złagodzeniem

1. **Chronologia obniża czas spędzany w serwisie i aktywność.**
   - **Fakt [S6]:** w eksperymencie na Facebooku i Instagramie (USA 2020)
     przełączenie na feed chronologiczny „znacząco zmniejszyło” czas spędzany
     na platformach i aktywność.
   - Kuking stawia retencję wyżej niż odsłony (`AGENTS.md` §1), więc ten
     argument trzeba traktować poważnie.
   - **Opinia:** badanie dotyczyło dojrzałych platform z ogromną podażą
     treści i ludźmi obserwującymi setki kont. Przy małej podaży ranking nie ma
     czego przestawiać.
2. **Chronologia też faworyzuje: kogoś, kto publikuje często i o dobrej porze.**
   - **Fakt (repo):** feed obserwowanych nie ma reguły „jeden wpis na
     autora”. Ma ją tylko Odkrywanie.
   - **Szacunek:** przy kilku tysiącach osób jedna bardzo aktywna osoba może
     zająć połowę czyjegoś Startu.
   - To prawdziwy problem, ale rozwiązuje go zwijanie serii (model g w punkcie
     2), a nie ranking.
3. **Algorytm nie musi krzywdzić nowych.**
   - **Fakt [S12]:** TikTok deklaruje, że ani liczba obserwujących, ani
     wcześniejsze popularne filmy nie są bezpośrednimi czynnikami rekomendacji.
     Nowe wideo trafia najpierw do małej grupy widzów.
   - **Opinia:** to najmocniejszy argument przeciw **dosłownemu** brzmieniu
     zakazu. Mechanizm, który celowo daje zasięg nowym, jest algorytmem,
     a pomaga. Stąd propozycja modelu (f).
4. **Grupa 50+ zna ranking z Facebooka.** Chronologia może jej się wydać
   „pusta” albo „przypadkowa”.
   - **Opinia:** to argument słaby, bo [S3] pokazuje, że ta sama grupa
     rankingu nie rozumie. Warto go jednak sprawdzić w teście z punktu 6.

### Czy efekt „widzianych i niewidzianych” jest dobrze udokumentowany?

| Część twierdzenia | Stan dowodów |
|---|---|
| Autorzy **odczuwają** zagrożenie niewidzialnością i dostosowują zachowanie do algorytmu | dobrze opisane jakościowo ([S9], [S4]) |
| Sygnały popularności **zwiększają nierówność** uwagi | dobrze: eksperyment [S13], symulacje [S14] |
| Odzew na pierwsze wpisy **zwiększa dalsze publikowanie** | dobrze w dużych serwisach ([S7], [S8]) |
| Ranking **powoduje**, że większość **przestaje publikować** | **słabo**: nie znam badania randomizowanego, które mierzyłoby to wprost; [S6] mierzyło aktywność czytelników, nie autorów |
| Wszystko powyższe **w małych społecznościach** (setki–tysiące osób) | **brak** dobrych badań; wnioskowanie przez analogię |

### Co zmieniłoby moje zdanie

- Pomiar pokazuje, że przy chronologii ludzie nie widzą dużej części wpisów
  obserwowanych (próg w punkcie 6).
- Pomiar pokazuje, że nowi autorzy **mimo chronologii** nie dostają odzewu.
  Wtedy mechanizm, który celowo promuje nowych (model f), jest lepszy od
  chronologii.
- Test A/B rankingu w Odkrywaniu nie pogarsza wskaźników nowych autorów
  (W1–W3 z punktu 6).

### Proponowane nowe brzmienie zakazu (do decyzji właściciela)

> Kuking nie porządkuje wpisów według popularności ani zaangażowania (lajków,
> komentarzy, zapisów, liczby obserwujących, czasu oglądania). Nie wnioskuje
> o upodobaniach z zachowania. Nie ukrywa niczego, o czym człowiek nie wie.
> Wolno stosować jawne reguły, które wyrównują szanse: jeden wpis na autora,
> miejsce dla pierwszych wpisów nowych osób, wybór gospodarza, wykluczenia
> ustawione przez samego widza.

---

## 2. Modele

### Tabela porównawcza

Koszty to **szacunki** dla jednej osoby znającej ten kod, wraz z testami
i dokumentacją wymaganymi przez `AGENTS.md`.

| Model | Nowi autorzy i częstość publikowania | Zrozumiałość 50+ | Główne ryzyka | Koszt budowy i utrzymania | Ryzyko prawne | Ocena |
|---|---|---|---|---|---|---|
| **(a)** chronologia + wykluczenia | neutralny: wykluczenie jest prywatne i nie sumuje się | wysoka: „nie pokazuj mi X” | przeciek filtra przez tagi, przypadkowe ukrycia, pustka przy małej podaży | niski: tabela, warunek w 4–5 zapytaniach, ekran ustawień, eksport i usuwanie (ok. 1–2 tygodnie) | niskie; art. 9 do sprawdzenia | **tak, po teście** |
| **(b)** ręczne wagi tematów | ujemny dla nowych i niszowych tematów | niska: waga to pojęcie abstrakcyjne, skutek niewidoczny | to już ranking; nieprzewidywalność | średni + strojenie | niskie–średnie | **nie**; zamiast wag przełączniki tak/nie |
| **(c)** ranking tylko w Odkrywaniu | **najgorszy**: to punkt wejścia nowych (B3) | średnia: „dlaczego to widzę?” | bogaci się bogacą, zmowa lajków, gra o widoczność | średni–wysoki (sygnały, ochrona przed nadużyciami) | art. 27 po utracie zwolnienia; profilowanie przy sygnałach z zachowania | **nie** w tej postaci |
| **(d)** kilka feedów do wyboru | zależy od domyślnego | niska–średnia: więcej przełączania | rozproszenie; większość i tak zostanie przy domyślnym [S22] | niski, jeśli to proste zapytania | niskie | **już macie wersję minimalną** (Start, Odkryj, tematy); nie dokładać |
| **(e)** uczenie z zachowania | ujemny: na starcie szum zamiast danych | niska [S3] | bańka, „niewidzialni”, manipulacja, nieprzewidywalność | wysoki: logi wyświetleń, model, monitoring; kłóci się z §3 AGENTS.md | **wysokie**: profilowanie, [S29], możliwa ocena skutków (art. 35) | **nie** |
| **(f)** *propozycja:* sprawiedliwa chronologia z jawnymi sekcjami | **najlepszy**: gwarantowana ekspozycja pierwszych wpisów | wysoka: nazwa sekcji mówi, dlaczego coś tu jest | spam nowych kont (łagodzą go Turnstile i moderacja) | niski–średni | niskie | **tak**, gdy Odkrywanie zacznie się przepełniać |
| **(g)** *propozycja:* zwijanie serii w feedzie obserwowanych | neutralny–dodatni | wysoka: „Halina dodała dziś 4 wpisy — pokaż wszystkie” | niskie | niski–średni | brak | **tak**, jeśli test pokaże „za dużo od jednej osoby” |

### (a) Chronologia plus wykluczenia

- **Teza:** to właściwy kierunek, ale dopiero po teście z punktu 6 i po
  naprawieniu B2.
- **Argumenty:**
  - filtr z listą w ustawieniach jest zrozumiały;
  - Mastodon robi to od lat w serwisie opartym na chronologii [S24];
  - Pinterest pokazuje sygnały jako przełączniki [S25];
  - YouTube ma „Nie polecaj kanału”, a według [S19] to najskuteczniejsza z jego
    kontrolek, bo najbliższa twardemu filtrowi. Mimo to zablokowała tylko 43%
    niechcianych rekomendacji.
- **Kontrargumenty:**
  - przy 20 osobach każde ukrycie zabiera zauważalną część serwisu;
  - filtr tematu jest tak dobry jak tagowanie: 1419 tagów, aliasy
    (`docs/DECISIONS.md`, ranking podpowiedzi);
  - trzeba sprawdzić, czy słownik ma hierarchię. Bez niej ukrycie „podrobów”
    nie ukryje „wątróbki”.
- **Co zmieniłoby zdanie:** dzienniczek z punktu 6 nie pokazuje potrzeby, czyli
  mniej niż jeden sygnał „nie chcę tego widzieć” na osobę tygodniowo.

### (b) Chronologia plus ręczne wagi

- **Teza:** nie.
- **Argumenty:**
  - waga to ranking pod inną nazwą;
  - skutek wagi jest dla człowieka niewidoczny, a niewidoczny skutek to
    kontrolka, która „nie działa” [S19];
  - osoby 50+ rzadziej sterują feedem nawet wtedy, gdy mogą [S3].
- **Kontrargument:** w badaniu MovieLens ludzie chętnie używali suwaka
  i różnili się ustawieniami [S21]. Tamta grupa to jednak entuzjaści systemu
  rekomendacji filmów, nie przekrój 50+.
- **Co zmieniłoby zdanie:** test oczekiwań, w którym ≥80% osób poprawnie
  przewiduje skutek suwaka.

### (c) Ranking tylko w Odkrywaniu

- **Teza:** nie w postaci „ranking popularności”. Jeśli Odkrywanie ma się
  zmienić, to w stronę modelu (f).
- **Argumenty:** B3 oraz [S7], [S8] i [S13].
- **Kontrargument:** przy tysiącach wpisów dziennie czysta chronologia
  w Odkrywaniu pokaże każdemu to, co akurat wpadło w ostatniej godzinie. To też
  jest loteria, tylko inna.
- **Co zmieniłoby zdanie:** A/B test, który pokazuje, że ranking nie obniża
  W1–W3.

### (d) Kilka feedów do wyboru

- **Teza:** Kuking ma już najprostszą wersję tego modelu i nie powinien
  dokładać kolejnych feedów.
- **Argumenty:**
  - według [S22] ponad 95% osób nie zmienia ustawień domyślnych;
  - Instagram przywrócił feedy chronologiczne w 2022 roku, ale nie pozwolił
    ustawić ich jako domyślnych [S2];
  - wybór, którego nikt nie dokonuje, jest kosztem bez zysku.
  - Model Bluesky, czyli feedy pisane przez osoby trzecie [S10], wymaga
    infrastruktury sprzecznej z `AGENTS.md` §3.
- **Kontrargument:** mała grupa zaangażowanych osób może bardzo cenić wybór.
- **Co zmieniłoby zdanie:** powtarzające się w teście prośby o „inny widok”.

### (e) Ranking z uczeniem z zachowania

- **Teza:** nie, bez względu na skalę, dopóki Kuking nie ma zespołu, który
  potrafi to monitorować.
- **Argumenty:** koszt, profilowanie (punkt 7), [S15], [S16], [S4].
- **Kontrargument:** [S6], czyli większy czas w serwisie.
- **Co zmieniłoby zdanie:** nic w horyzoncie roadmapy.

### (f) Moja propozycja: sprawiedliwa chronologia z jawnymi sekcjami

Odkrywanie dzieli się na nazwane sekcje. Każda jest chronologiczna i ma
regułę jeden wpis na autora:

1. **„Nowi w Kukingu”**: pierwsze 1–3 wpisy osób, które dołączyły w ostatnich
   30 dniach, każdy widoczny przez 72 godziny (liczby są szacunkiem do
   dostrojenia);
2. **„Z tematów, które obserwujesz”**;
3. **„Świeżo od wszystkich”**.

- **Teza:** tak. Najpierw przez gospodarza, ręcznie na tablicy, bez kodu.
  W kodzie dopiero wtedy, gdy Odkrywanie zacznie się przepełniać.
- **Argumenty:**
  - bezpośrednio realizuje mechanizm z [S7] i [S8];
  - Mastodon stosuje podobne reguły w „trendach”: jeden wpis na konto
    i przegląd przez moderatora [S24];
  - każda reguła da się opisać jednym zdaniem.
- **Kontrargumenty:**
  - to jest „algorytm” w dosłownym brzmieniu obecnego zakazu, stąd B1;
  - sekcja nowych przyciągnie spamerów, dlatego tylko konta po weryfikacji
    i z czystą historią moderacji.

### (g) Moja propozycja: zwijanie serii w feedzie obserwowanych

Pięć wpisów jednej osoby z jednego dnia pokazuje się jako jeden wpis
z odnośnikiem „pokaż wszystkie”.

- **Teza:** tak, jeśli dzienniczek pokaże motyw „za dużo od jednej osoby”.
- **Uzasadnienie:** usuwa najczęstszy według mnie powód „mniej takich”
  (**Opinia**, do sprawdzenia) bez ukrywania kogokolwiek i bez rankingu.

---

## 3. „Więcej takich”

### Teza

Skrót do obserwowania wystarczy pod dwoma warunkami. Napis musi mówić, że to
obserwowanie. Obserwowanie tematu musi mieć widoczny skutek, a dziś go nie ma
(B2).

### Czego ludzie oczekują od „więcej takich”

- **Fakt [S11]:** na Facebooku „Pokaż więcej” (od 2025 „Interesuje mnie”)
  **tymczasowo podnosi ocenę rankingową** danego wpisu i podobnych.
- **Fakt [S18]:** na Bluesky „Show more like this” jest sygnałem dla
  algorytmicznego feedu Discover.
- **Opinia:** ludzie znający te serwisy oczekują, że system „się nauczy”.
  Przycisk, który obiecuje uczenie, a robi coś innego, zawiedzie oczekiwania
  w obie strony: nie nauczy się, a na dodatek poinformuje Halinę.
- **Uwaga:** Meta zmieniła nazwy z „Show more / Show less” na „Interested /
  Not interested”. Powodu nie podała, więc nie wyciągam z tego wniosku.

### Rekomendacja

W menu są dwie uczciwie nazwane akcje zamiast jednej ogólnej:

- **„Obserwuj Halinę”**, a pod nią na ekranie potwierdzenia: „Halina zobaczy,
  że ją obserwujesz”.
- **„Zobacz więcej o: pierogi”** prowadzi na stronę tematu, czyli do treści,
  którą człowiek sam przegląda. Tam stoi „Obserwuj temat”.

Przed tym właściciel musi zdecydować, czy obserwowane tematy mają trafiać na
Start **razem z** wpisami obserwowanych osób, chronologicznie. Bez tego
„obserwuj temat” pozostanie akcją widoczną tylko dla osób, które nie
obserwują nikogo. To jest zmiana zachowania Startu (D-021, #859), więc wymaga
osobnej decyzji. Nie jest detalem tej funkcji.

### Kontrargument

Jedno ogólne wejście „Więcej takich wpisów…” może być łatwiejsze do
znalezienia niż dwie konkretne pozycje. Kompromis: jedno wejście z wielokropkiem
prowadzi na mały ekran z dwiema uczciwie opisanymi opcjami. Ten wariant
sprawdza test z punktu 6.

### Co zmieniłoby moje zdanie

W teście oczekiwań ≥80% osób poprawnie mówi, co zrobi ogólny przycisk
„więcej takich”, w tym to, że autor się dowie.

---

## 4. „Mniej takich”

### Co ukrywać

| Obiekt | Rekomendacja | Uzasadnienie |
|---|---|---|
| **Pojedynczy wpis** | **tak**, najpierw | zerowe ryzyko przypadkowego ukrycia połowy serwisu; wzorzec już istnieje przy wspomnieniach („Nie pokazuj mi tego więcej”, [`WspomnienieController`](../../app/Http/Controllers/WspomnienieController.php)) |
| **Osoba** (wyciszenie bez blokady) | **tak** | blokada to zbyt mocny gest wobec sąsiadki z tej samej wsi; ta potrzeba jest już opisana w [analizie Pixelfeda](repos/pixelfed-pixelfed.md); Facebook nie powiadamia osoby wyciszonej na 30 dni [S20] |
| **Temat (tag)** | **tak, warunkowo** | tylko jeśli słownik tagów ma hierarchię albo gospodarz przypisuje tematy nadrzędne; inaczej filtr przecieka |
| **Rodzaj treści** („pytania”, „przepisy mięsne”) | **nie teraz** | wymaga niezawodnej klasyfikacji, której nie ma; „mięsne” czy „post” to kategorie z pogranicza art. 9 RODO (punkt 7) |

### Na zawsze czy czasowo

- **Teza:** domyślnie „do odwołania”, z listą w ustawieniach. Bez terminów
  w pierwszej wersji.
- **Argumenty:**
  - cicho wygasający filtr to coś, co „samo wróciło”, a przewidywalność stoi
    w UX Kukinga wyżej niż bogactwo opcji (`docs/UX_50_PLUS.md`);
  - każda dodatkowa opcja to dodatkowy krok.
- **Kontrargumenty:**
  - Facebook ma wyciszenie na 30 dni [S20], a Mastodon pozwala ustawić czas
    filtra i wyciszenia [S24];
  - w polskich realiach czasowe ukrycie ma sens przy poście albo diecie.
- **Co zmieniłoby zdanie:** w dzienniczku powtarza się motyw „na razie nie
  chcę”. Wtedy dodać **jedną** opcję czasową, tylko dla osoby: „na 30 dni”.

### Gdzie ma działać

| Miejsce | Wpis | Osoba | Temat | Dlaczego |
|---|---|---|---|---|
| Start: feed obserwowanych | tak | tak | tak, z linią „1 wpis ukryty według Twoich ustawień — pokaż” | Kuking podsuwa; linia chroni przed błędem z [S4] |
| Start: feed tagów i Odkrywanie | tak | tak | tak | Kuking podsuwa |
| Tablica: część automatyczna **i** redakcyjna | tak | tak | tak | wybór gospodarza nie jest ważniejszy od jawnej woli widza; lukę uzupełnia część automatyczna |
| Propozycje osób | — | tak | — | Kuking podsuwa |
| **Tygodniowy list** | tak | tak | tak | zawiera wpisy obserwowanych ([`ZbierzTresciDigestu`](../../app/Domain/Digest/ZbierzTresciDigestu.php)); research go pominął |
| Wyszukiwarka, profil osoby, strona tematu, bezpośredni link | **nie** | **nie** | **nie** | człowiek sam szuka; filtr w tym miejscu wyglądałby jak awaria |
| Powiadomienia o interakcjach z **moją** treścią | **nie** | **nie** | **nie** | patrz niżej |

**Powiadomienia to osobna decyzja, której research nie zauważył.**
`AGENTS.md` §1 wymienia **trzy** przypadki, w których „Ugotowałem” nie
powiadamia autora. Wyciszenie osoby nie może po cichu stać się czwartym.
Rekomendacja: wyciszenie dotyczy wpisów w feedach, a nie wiadomości o tym, co
ktoś zrobił z moim przepisem. Pilnujący tego test już istnieje
(`tests/Feature/UgotowalemZawszePowiadamiaAutoraTest.php`); wystarczy dopisać
przypadek wyciszenia.

### Jak uniknąć ukrycia połowy serwisu

1. **Każde ukrycie nazywa obiekt i daje „Cofnij” na miejscu.** Zamiast
   znikającego komunikatu karta zamienia się w linię: „Nie pokazujemy Ci
   wpisów o: podroby. Cofnij”. Znikające komunikaty są dla 50+ złym wzorcem,
   bo nie ma czasu ich przeczytać.
2. **Zawsze widoczny licznik.** Gdy cokolwiek jest ukryte, pod nagłówkiem
   Odkrywania i Startu stoi: „Ukrywasz 2 tematy i 1 osobę. Zmień”.
3. **Miękki próg.** Od piątego ukrytego tematu potwierdzenie mówi, ile wpisów
   z ostatnich 30 dni to ukryje. Próg jest **szacunkiem**.
4. **Bezpiecznik pustki.** Jeśli filtry odcinają więcej niż połowę
   kandydatów na ekran, ekran mówi to wprost i prowadzi do listy.
5. **Bez akcji zbiorczych** w rodzaju „ukryj wszystko podobne”.
6. **Ustawienia:** jedna lista „Czego nie pokazujemy”. Przy każdej pozycji
   „Przywróć”, na końcu „Przywróć wszystko” z potwierdzeniem.
7. **Oddzielenie od „Zgłoś”.** Po ukryciu pojawia się linia „Jeśli ten wpis
   łamie zasady, zgłoś go”.
   - **Opinia:** bez tego „mniej” stanie się cichym zastępstwem zgłoszenia.
     Szkodliwy wpis zniknie jednej osobie, a moderacja się o nim nie dowie.
   - Działa to też w drugą stronę: część zgłoszeń „bo mi się nie podoba”
     przejdzie do „mniej”, co odciąży kolejkę moderacji.

---

## 5. Nazwy i umiejscowienie

### Warianty nazw

Kryteria: czy wiadomo, **czego** dotyczy akcja, **co** się stanie, **kto**
się dowie i **jak** to cofnąć. Do tego długość przy 320 px oraz zgodność
z `docs/brand/COPY_STYLE.md`: forma neutralna płciowo, „wpisy” zamiast
„treści”.

| # | Wariant | Co jasne | Co niejasne | Ocena (1–5) |
|---|---|---|---|---|
| 1 | „Chcę widzieć więcej takich treści” | intencja | co się stanie; obiecuje uczenie; „treści” to słowo z żargonu | 2 |
| 2 | „Chcę widzieć mniej takich treści” | intencja | czy to ukrycie, czy zgłoszenie; czego dotyczy | 2 |
| 3 | „Obserwuj Halinę” | obiekt i skutek; zgodne z resztą serwisu | że Halina dostanie powiadomienie (dopisek na potwierdzeniu) | 5 |
| 4 | „Zobacz więcej o: pierogi” | obiekt; prowadzi do przeglądania | nic istotnego | 5 |
| 5 | „Nie pokazuj mi tego wpisu” | obiekt, skutek; spójne z „Nie pokazuj mi tego więcej” | gdzie (wystarczy linia po kliknięciu) | 5 |
| 6 | „Nie pokazuj mi wpisów o: podroby” | obiekt, skutek | długie; przy trzech tagach trzy pozycje w menu | 4 |
| 7 | „Nie pokazuj mi wpisów Haliny” | obiekt, skutek | brzmi jak blokada; potrzebny dopisek „Halina się o tym nie dowie” | 4 |
| 8 | „To mnie nie interesuje” | znane z Facebooka i YouTube'a | czego dotyczy i co się stanie | 2 |
| 9 | „Ukryj temat…” | krótkie | **„ukryj” to słowo moderacji** (ukrywanie i scalanie tagów, #853), więc łatwo je pomylić z ukryciem dla wszystkich | 2 |

### Rekomendowany układ menu trzech kropek

```text
Otwórz wpis
─────────────
Obserwuj Halinę                       (albo „Przestań obserwować Halinę”)
Zobacz więcej o: pierogi
Nie chcę widzieć takich wpisów…       → ekran wyboru
─────────────
Zgłoś ten wpis
```

Ekran wyboru działa bez JavaScriptu, zgodnie ze wzorcem `<details>` i zwykłych
formularzy. Wygląda tak:

```text
Czego nie chcesz widzieć?

( ) Tego jednego wpisu
( ) Wpisów Haliny — Halina się o tym nie dowie
( ) Wpisów o: pierogi
( ) Wpisów o: kuchnia babci

Zawsze możesz to cofnąć w Ustawieniach → Czego nie pokazujemy.
[ Zapisz ]
```

Dlaczego ekran, a nie kilka pozycji w menu:

- wpis z trzema tagami dałby w menu pięć pozycji „nie pokazuj”;
- ekran wymusza jawny wybór **obiektu**;
- na ekranie jest miejsce na zdanie o tym, kto się dowie i jak to cofnąć.

### Czy menu trzech kropek to dobre miejsce

- **Teza:** tak, jako **główne** miejsce, ale nie jedyne.
- **Argumenty:**
  - to akcje drugorzędne i rzadkie, a pod wpisem konkurowałyby
    z „Ugotowałem”;
  - Facebook [S11] i Bluesky [S18] trzymają je właśnie tam, a nasza grupa zna
    ten wzorzec (wyjątek dla menu trzech kropek w `AGENTS.md` §5).
- **Kontrargument:**
  - **Fakt [S23]:** ukrycie nawigacji w menu obniżyło jej odkrywalność prawie
    o połowę;
  - **Fakt [S3]:** osoby 50+ i tak rzadziej sterują feedem.
- **Wniosek:** potrzebna jest druga, widoczna droga. W Odkrywaniu stała linia
  „Co tu widzisz i jak to zmienić” prowadzi na stronę w ustawieniach
  z obserwowanymi osobami, obserwowanymi tematami i tym, czego nie pokazujemy.
  Tak wygląda też dobra praktyka z art. 27 ust. 3 DSA: przełącznik dostępny
  z miejsca, w którym treść jest układana (punkt 7).
- **Czego nie robić:**
  - dwóch przycisków „więcej” i „mniej” pod każdym wpisem;
  - gestów, bo `AGENTS.md` §5 i tak ich zabrania.

---

## 6. Kolejność i pomiar

### Kolejność

| Kiedy | Co | Dlaczego |
|---|---|---|
| **Teraz, bez kodu** | test z 20 osobami (niżej); gospodarz ręcznie daje miejsce nowym na tablicy (model f bez kodu) | tanie; rozstrzyga obiekt i nazwę |
| **Teraz, mały kod** | wskaźnik W1 z istniejących tabel; poprawka B1 w `COMPLIANCE.md`; strona „Jak Kuking układa wpisy” (punkt 7) | nie zmienia feedu, a daje dane i zaufanie |
| **Teraz, decyzja** | czy obserwowane tematy trafiają na Start razem z osobami (B2) | bez tego „więcej o temacie” jest martwe |
| **Po teście, jeśli potwierdzi** | „Nie chcę widzieć takich wpisów…”: wpis, osoba, temat; lista w ustawieniach; eksport i usuwanie; test „wyciszenie nie wyłącza Ugotowałem” | model (a) |
| **Gdy pojawi się nadmiar** (progi niżej) | model (f) w kodzie; model (g), jeśli potrzebny | wyrównuje szanse bez rankingu |
| **Nigdy bez nowej decyzji** | wagi, sygnały popularności w kolejności, uczenie z zachowania | punkt 1 |

### Wskaźniki

Wszystkie da się policzyć z danych, które Kuking już ma albo musiałby mieć
i tak. Żaden nie wymaga śledzenia wyświetleń pojedynczych wpisów.

| # | Wskaźnik | Po co | Próg (**szacunek**) |
|---|---|---|---|
| W1 | Odsetek pierwszych wpisów nowych autorów, które w 48 h dostały ≥1 odzew (komentarz, ugotowanie, polubienie, zapis) | główny wskaźnik zdrowia społeczności ([S7], [S8]) | cel ≥80%; alarm <60% |
| W2 | Odsetek nowych autorów z drugim wpisem w ciągu 14 dni | czy odzew przekłada się na powrót | bazę ustalić w alfie; alarm przy spadku o ≥10 pkt proc. |
| W3 | Udział 10% najaktywniejszych autorów w całym odzewie | strażnik nierówności przy każdej zmianie Odkrywania | alarm przy wzroście o ≥10 pkt proc. po zmianie |
| W4 | Liczba **różnych autorów** publikujących dziennie wobec rozmiaru strony (15) | czy Odkrywanie się przepełnia | patrz niżej |
| W5 | Wpisy obserwowanych na aktywną osobę dziennie | czy Start się przepełnia | alarm przy medianie >30 (dwie strony) |
| W6 | Ukrycia na 100 aktywnych tygodniowo; odsetek cofniętych w 7 dni; odsetek osób z >10 ukrytymi tematami | czy „mniej” jest używane i rozumiane | cofnięcia >25% albo >5% osób z >10 tematami oznaczają problem projektu |

### Progi, przy których warto **rozważyć** zmianę Odkrywania

Na model (f), nie na ranking popularności. Wszystkie trzy warunki muszą być
spełnione naraz:

1. **W4:** dziennie publikuje ponad ok. 75 różnych autorów, czyli pięć razy
   więcej, niż mieści strona. Przy równym rozkładzie w ciągu 15 godzin
   czuwania pierwszy wpis nowej osoby jest wtedy na pierwszej stronie
   Odkrywania krócej niż ok. 3 godziny (**szacunek**).
2. **W1** spada poniżej 70% przez cztery kolejne tygodnie.
3. Ręczne działania gospodarza (tablica) przestają wystarczać, bo jest za dużo
   nowych osób, żeby je zauważyć ręcznie.

Ranking popularności zostaje poza tą listą. Żeby go rozważyć, trzeba nowej
decyzji i testu A/B z W1–W3 jako warunkami zatrzymania.

### Test w 1–2 tygodnie, bez kodu

**Uwaga metodologiczna:** 20 osób to badanie jakościowe. Szuka się wzorców,
nie procentów. Do wykrycia większości problemów z użytecznością wystarcza
kilka osób na rundę [S26]. Progi niżej to reguły decyzyjne, a nie statystyka.

1. **Test oczekiwań**, dni 1–4, 8–10 osób, 20 minut, telefonicznie albo na
   miejscu.
   - Materiał: wydruki lub zrzuty karty wpisu z menu w trzech wariantach
     (1–2, 3–7 i wariant z ekranem wyboru).
   - Pytania do każdej pozycji:
     - „Co się stanie, gdy to naciśniesz?”
     - „Gdzie przestaniesz to widzieć?”
     - „Czy Halina się dowie?”
     - „Jak to cofniesz?”
   - Reguła: nazwa przechodzi, gdy ≥8 na 10 osób poprawnie odpowie na
     pierwsze i trzecie pytanie.
2. **Sortowanie kart**, te same osoby, 10 minut.
   - 15 wydrukowanych, prawdziwych wpisów, układanych w trzy kupki: „chcę
     więcej”, „obojętne”, „wolę nie widzieć”. Przy każdej kupce pytanie
     „dlaczego?”.
   - Odpowiedzi koduje się jako: osoba / temat / jakość zdjęcia / za dużo od
     jednej osoby / coś niestosownego (to już zgłoszenie) / inne.
   - To rozstrzyga pytanie o obiekt z punktu 4.
3. **Dzienniczek**, dni 1–14, wszystkie 20 osób.
   - Prośba: „Gdy w Kukingu pomyślisz »wolę tego nie widzieć« albo »chcę
     więcej takich«, wyślij krótką wiadomość: co to było i dlaczego”.
   - Kanał wybiera sama osoba: SMS, e-mail albo telefon do gospodarza.
   - Reguły decyzyjne:
     - mniej niż 1 sygnał na osobę tygodniowo: funkcja czeka;
     - przewaga „temat”: filtr tematów;
     - przewaga „osoba”: wyciszenie;
     - przewaga „za dużo od jednej osoby”: model (g) zamiast filtra.
4. **Czego nie robić:** „fałszywych drzwi”, czyli przycisku, który po
   naciśnięciu mówi „funkcja dopiero powstaje”. Taki przycisk łamie zakaz
   martwych przycisków (D-053), a w grupie 50+ podkopuje zaufanie do
   wszystkich innych przycisków.

---

## 7. Prawo i zaufanie

**Zastrzeżenie:** nie jestem prawnikiem. Punkty oznaczone „do sprawdzenia”
warto dopisać do `docs/prawo/DO_WERYFIKACJI_PRAWNEJ.md`.

### DSA

| Kwestia | Ocena researchu | Moja ocena |
|---|---|---|
| Art. 27 (przejrzystość systemów rekomendacji) nie obowiązuje mikro- i małych przedsiębiorstw (art. 19) | trafna, do potwierdzenia | **trafna, ale niepełna.** Zwolnienie dotyczy **przedsiębiorstwa**, czyli SAMSUFI sp. z o.o. (D-040), łącznie z przedsiębiorstwami partnerskimi i powiązanymi (Zalecenie 2003/361/WE art. 6). Po utracie statusu trwa jeszcze 12 miesięcy i nie obejmuje VLOP. Te warunki już opisuje [`COMPLIANCE.md`](../legal/COMPLIANCE.md) §1.2. |
| Definicja z art. 3 lit. s jest szeroka i Odkrywanie ją spełnia | trafna | **trafna i idzie dalej**: obejmuje też kolejność wyników wyszukiwania [S27]. Moim zdaniem obejmuje nawet chronologię, bo to też wybrany przez platformę sposób „ustalania względnej kolejności”. Spotyka się pogląd przeciwny. Wniosek dla repozytorium: poprawić [`COMPLIANCE.md`](../legal/COMPLIANCE.md) (B1). |
| Art. 38 (opcja bez profilowania) | nie omówiono | dotyczy tylko bardzo dużych platform (VLOP i VLOSE). Kuking go nie musi stosować, ale pokazuje kierunek prawa: prawo do feedu bez profilowania. Kuking spełnia to z nadwyżką. |
| Art. 25 (zakaz zwodniczych interfejsów) | nie omówiono | też jest w sekcji objętej zwolnieniem. Interfejsów dotyczących danych osobowych dotyczą jednak wytyczne EROD 03/2022 o zwodniczych wzorcach [S33], które wynikają z RODO i zwolnienia nie mają. Lista „Czego nie pokazujemy” nie może być schowana ani utrudniona. |
| Polskie wdrożenie DSA | nie omówiono | **Fakt [S34]:** 25.09.2026 prezydent podpisał ustawę z 4.09.2026 o zmianie ustawy o świadczeniu usług drogą elektroniczną. Prezes UKE zostaje koordynatorem usług cyfrowych. Nadzór w Polsce przestaje więc być teoretyczny. Datę wejścia w życie trzeba **sprawdzić**; doniesienia mówią o 30 dniach od publikacji. |
| Digital Fairness Act | nie omówiono | **Fakt [S35]:** Komisja zapowiada projekt na IV kwartał 2026, obejmujący m.in. uzależniający projekt i nieuczciwą personalizację. Kierunek sprzyja stanowisku Kukinga; treści projektu jeszcze nie ma. |

### RODO

1. **Jawny filtr ustawiony przez człowieka.** Research słusznie wskazuje
   art. 6 ust. 1 lit. b. Według EROD personalizacja **może** być nieodłącznym
   elementem usługi, zależnie od jej charakteru i oczekiwań użytkownika
   [S28]. Filtr, o który człowiek sam prosi, jest najmocniejszym przypadkiem
   takiej sytuacji. Nie jest też profilowaniem w rozumieniu art. 4 pkt 4,
   bo system niczego nie ocenia ani nie przewiduje. Wykonuje polecenie.
2. **Ranking z uczeniem z zachowania.**
   - To profilowanie w rozumieniu art. 4 pkt 4.
   - **Fakt [S29]:** TSUE w sprawie Meta przeciwko Bundeskartellamt uznał, że
     personalizacja treści „nie wydaje się niezbędna” do świadczenia usługi
     sieci społecznościowej (pkt 102).
   - Umowa (art. 6 ust. 1 lit. b) jest więc słabą podstawą. Zostaje zgoda albo
     prawnie uzasadniony interes z prawem sprzeciwu (art. 21), być może
     z oceną skutków (art. 35).
   - Art. 22 raczej nie wchodzi w grę, bo kolejność wpisów zwykle nie wywołuje
     skutków prawnych ani podobnie istotnych [S32].
   - To dodatkowy, **prawny** argument przeciw modelowi (e).
3. **Art. 9: czego research nie zauważył** (B6).
   - Tematy typu „dieta cukrzycowa”, „bezglutenowe”, „post”, „koszerne”,
     „halal” w liście obserwowanych **albo ukrytych** mogą pośrednio ujawniać
     zdrowie lub wyznanie. **Fakt [S30]:** TSUE uznaje dane pośrednio
     ujawniające za dane szczególne.
   - Zalecenia:
     - obie listy są prywatne i nigdy publiczne;
     - nie trafiają do analityki ani do zewnętrznych modeli (spójnie z D-240);
     - są w eksporcie i znikają z kontem (obserwowane tagi już są w eksporcie
       i usuwaniu: `CollectUserExportData`, `EraseAccountData`);
     - są w rejestrze czynności przetwarzania.
   - **Do sprawdzenia:** czy potrzebna jest wyraźna zgoda (art. 9 ust. 2
     lit. a), czy wystarczy argument, że celem nie jest wnioskowanie
     o zdrowiu ani wyznaniu. Orzecznictwo raczej zawęża ten drugi argument.
4. **Prawo dostępu autora (art. 15).** Wiersz „A wyciszyła B” jest też danymi
   B. Rekomendacja: traktować wyciszenia tak samo jak dziś blokady w eksporcie,
   czyli eksportować to, co osoba **sama** ustawiła, a nie to, kto ją
   wyciszył. Art. 15 ust. 4 chroni prawa innych. **Do sprawdzenia** przez
   prawnika.
5. **Retencja:** dopóki człowiek nie przywróci albo nie usunie konta. Bez
   automatycznego kasowania, bo to ustawienie, a nie ślad zachowania.

### Czy warto dobrowolnie opisać „jak dobieramy wpisy”

- **Teza:** tak, i to teraz, bo teraz jest to najtańsze.
- **Treść:** jedna strona „Jak Kuking układa wpisy”, po jednym zdaniu na
  każde miejsce: Start, Odkrywanie, tablica, wyszukiwarka, tygodniowy list.
  Dodatkowo lista tego, czego Kuking **nie** robi. Odnośnik stoi obok
  istniejącej stopki „Tu nie ma rankingu…” i w Odkrywaniu.
- **Argumenty:**
  - [S4]: ludzie, którzy nie wiedzą o regułach, przypisują skutki relacjom;
  - [S3]: osoby 50+ najsłabiej rozumieją, dlaczego coś widzą;
  - taka strona przygotowuje też serwis na art. 27, gdyby zwolnienie wygasło.
- **Kontrargument:** każde zdanie na takiej stronie jest obietnicą. Strona
  rozjedzie się z kodem, tak jak rozjechała się tabela stacku (`AGENTS.md` §3,
  D-104).
- **Rozwiązanie w stylu tego repozytorium:** test, który czyta stronę i sprawdza,
  że każda wymieniona reguła ma odpowiednik w kodzie. Tym samym wzorcem działa
  `TabelaStackuMowiPrawdeTest`.

---

## 8. Czego nie widzicie

1. **Start nie łączy źródeł** (B2). Obserwowane tematy są dziś niewidoczne
   dla każdego, kto obserwuje choć jedną osobę z wpisami. To dotyczy całej
   funkcji tematów, nie tylko „więcej takich”.
2. **Obserwowanie jest gestem publicznym** (B2). „Więcej takich” zamienione
   w obserwowanie wysyła powiadomienie i trafia do tygodniowego listu autora.
3. **Tygodniowy list to czwarty feed.** Każdy filtr musi go obejmować, inaczej
   ukryta osoba wróci w poniedziałkowym mailu.
4. **Wyciszenie a „Ugotowałem zawsze powiadamia”.** Trzeba jawnie zdecydować,
   że wyciszenie nie jest czwartym wyjątkiem, i dopisać test (punkt 4).
5. **„Mniej” jako ciche zastępstwo „Zgłoś”.** Szkodliwe treści mogą przestać
   docierać do moderacji. Ukrycie powinno podpowiadać zgłoszenie.
6. **Jakość słownika tagów.** Filtr tematów jest tak dobry jak tagowanie.
   Bez hierarchii tematów filtr przecieka i wygląda na zepsuty (B4).
7. **Prywatne sygnały kuszą.** Za rok ktoś zechce użyć liczby „ile osób ukryło
   tego autora” w moderacji albo w Odkrywaniu. Regułę „sygnał należy tylko do
   widza” trzeba zapisać jako decyzję **i** jako test. Na przykład żadne
   zapytanie poza eksportem i usuwaniem konta nie grupuje tabeli wyciszeń po
   osobie wyciszonej. To ten sam wzorzec, którym repozytorium pilnuje progu
   D-081.
8. **Zimny start przeważa nad wszystkim.** Przy 20 osobach każdy filtr
   zmniejsza i tak małą podaż. Bezpiecznik pustki z punktu 4 jest obowiązkowy,
   a nie opcjonalny.
9. **Chronologia też ma zwycięzców:** kogoś, kto publikuje często i wieczorem.
   Najczęstszy powód „mniej takich” może być w rzeczywistości „za dużo od
   jednej osoby” (**hipoteza**). Wtedy lekarstwem jest zwijanie serii
   (model g), a nie filtr.
10. **Nieodwracalność.** Filtr łatwo potem usunąć. Ranking trudno cofnąć, bo
    autorzy dostosowują do niego zachowanie ([S9], [S17]). Przy
    niepewności należy wybierać to, co łatwiej odwrócić.
11. **Pytanie, którego nie zadaliście: jaki problem ma rozwiązać ten
    pomysł?**
    - Czy ktoś z użytkowników skarżył się, że widzi coś niechcianego? Czy
      właściciel chce „uczyć, co lubi dana osoba”, bo Start wydaje mu się nudny?
    - Jeśli to drugie, lepszymi narzędziami są:
      - lepszy wybór tematów przy rejestracji (lista promowanych jest pusta,
        [`TAGI_PROMOWANE.md`](../decyzje/TAGI_PROMOWANE.md));
      - połączenie źródeł Startu (B2);
      - praca gospodarza na tablicy.
12. **Słowo „nigdy” w `AGENTS.md` §12** dotyczy techniki, a nie skutku. Przy
    dosłownym czytaniu blokuje mechanizmy, które pomagają nowym, a jednocześnie
    przepuszcza szkodliwy ranking, byle nazwać go inaczej. Nowe brzmienie
    z punktu 1 zamyka obie furtki.

---

## Tabela decyzji

| Pytanie | Rekomendacja | Siła przekonania | Główne ryzyko |
|---|---|---|---|
| Utrzymać zakaz rankingu? | Tak, ale przepisać go na własności: popularność, zachowanie, ukrywanie bez wiedzy | wysoka | nowe brzmienie otworzy furtkę, jeśli nie dostanie testu pilnującego |
| Ranking w Odkrywaniu (c)? | Nie. Przy nadmiarze sprawiedliwe sekcje (f) | wysoka | przy dużej skali chronologia w Odkrywaniu też jest loterią |
| Uczenie z zachowania (e)? | Nie | wysoka | niższy czas w serwisie niż u konkurencji [S6] |
| Ręczne wagi (b)? | Nie; ewentualnie przełączniki tak/nie | średnia | ktoś będzie chciał „trochę mniej”, a nie „wcale” |
| Kilka feedów (d)? | Nie dokładać; obecne trzy wystarczą | średnia | zaangażowana mniejszość chce więcej wyboru |
| Budować „więcej / mniej” teraz? | Nie. Najpierw 2 tygodnie testu bez kodu | wysoka | test pokaże potrzebę, a straci się dwa tygodnie |
| „Więcej takich”? | Nazwać uczciwie: „Obserwuj Halinę”, „Zobacz więcej o: …” | wysoka | mniejsza odkrywalność niż jeden ogólny przycisk |
| Obserwowane tematy na Starcie? | Decyzja właściciela; skłaniam się ku łączeniu z osobami, chronologicznie | średnia | tematy zaleją wpisy znajomych; wtedy reguła „jeden na autora” też na Starcie |
| Co ukrywać? | Wpis, osoba (wyciszenie), temat. Nie „rodzaj treści” | średnia; test może zmienić | filtr tematów przecieka przez słownik bez hierarchii |
| Na zawsze czy czasowo? | Do odwołania; ewentualnie „30 dni” tylko dla osoby | średnia | „na zawsze” zapomniane po latach; licznik i lista to łagodzą |
| Gdzie działa? | Wszędzie, gdzie Kuking podsuwa (także Start i tygodniowy list); nie tam, gdzie człowiek szuka | wysoka | niewidzialność na Starcie; łagodzi ją linia „1 wpis ukryty — pokaż” |
| Wyciszenie a powiadomienia „Ugotowałem”? | Wyciszenie nie wyłącza powiadomień; dopisać test | wysoka | osoba wyciszająca z powodu nękania; do tego jest blokada |
| Nazwy? | Warianty 3–7; bez „treści” i bez „ukryj” | wysoka dla 3–5, średnia dla 6–7 | test oczekiwań może wykazać inaczej |
| Miejsce w UI? | Menu trzech kropek + ekran wyboru + stała droga z Odkrywania do ustawień | średnia | niska odkrywalność menu [S23] |
| RODO: filtr jawny? | art. 6 ust. 1 lit. b, ale sprawdzić art. 9 dla obu list tematów | średnia | tematy dietetyczne i religijne jako dane szczególne |
| DSA? | Poprawić `COMPLIANCE.md` (Kuking **ma** system rekomendacji w rozumieniu art. 3 lit. s); śledzić wejście w życie polskiej ustawy | średnia | utrata zwolnienia z art. 19 przy wzroście albo przez powiązania kapitałowe |
| Opisać „jak układamy wpisy”? | Tak, teraz, z testem zgodności z kodem | wysoka | rozjazd strony z kodem bez testu |

---

## Źródła

Linki sprawdzone 25.09.2026, chyba że zaznaczono inaczej.

**Platformy i praktyka**

- **[S1]** Adam Mosseri / Instagram, „Shedding More Light on How Instagram
  Works”, 8.06.2021. <https://about.instagram.com/blog/announcements/shedding-more-light-on-how-instagram-works>
  *Sprawdzone* (70% niewidzianych wpisów w 2016).
- **[S2]** Meta / Instagram, „Two New Ways to Control Your Instagram Feed”
  (Following i Favorites), 23.03.2022.
  <https://about.fb.com/news/2022/03/two-new-ways-to-control-your-instagram-feed/>
  Brak możliwości ustawienia jako domyślnych według TechCrunch, 23.03.2022:
  <https://techcrunch.com/2022/03/23/instagram-launches-chronological-and-favorites-feeds-for-all-users-but-they-cant-be-the-default>
  *Częściowo.*
- **[S10]** Bluesky, „Algorithmic Choice with Custom Feeds”, 27.07.2023.
  <https://bsky.social/about/blog/7-27-2023-custom-feeds> *Częściowo.*
- **[S11]** Meta, „New Ways to Customize Your Facebook Feed”, 5.10.2022,
  aktualizacja 11.03.2025 (zmiana nazw na Interested / Not interested;
  „tymczasowo podnosi ocenę rankingową”).
  <https://about.fb.com/news/2022/10/new-ways-to-customize-your-facebook-feed/>
  *Sprawdzone.*
- **[S12]** TikTok, „How TikTok recommends videos #ForYou”, 06.2020.
  <https://newsroom.tiktok.com/en-us/how-tiktok-recommends-videos-for-you>
  *Częściowo* (brak bezpośredniego wpływu liczby obserwujących).
- **[S18]** Sarah Perez / TechCrunch, „Bluesky now lets you personalize its main
  Discover feed using new controls”, 10.05.2024.
  <https://techcrunch.com/2024/05/10/bluesky-now-lets-you-personalize-its-main-discover-feed-using-new-controls>
  *Sprawdzone.* Uzupełniająco zgłoszenie użytkownika, że „Show less” nie
  działa: <https://github.com/bluesky-social/social-app/issues/9001>
  (anegdota, nie dowód).
- **[S19]** Mozilla Foundation, „Does This Button Work? Investigating YouTube's
  ineffective user controls”, 20.09.2022 (ponad 20 tys. wolontariuszy,
  ankieta 2758 osób; „Nie polecaj kanału” blokuje 43%, „Nie interesuje mnie”
  11% niechcianych rekomendacji).
  <https://www.mozillafoundation.org/en/blog/mozilla-investigation-youtubes-dislike-button-other-user-controls-largely-fail-to-stop-unwanted-recommendations/>
  Raport: <https://www.mozillafoundation.org/en/research/library/user-controls/report/>
  Wersja recenzowana (ACM Web Conference 2026): <https://doi.org/10.1145/3774904.3792183>
  *Sprawdzone* (wpis na blogu); wersji konferencyjnej nie czytano.
- **[S20]** Facebook Newsroom, „Introducing Snooze to Give You More Control of
  Your News Feed”, 15.12.2017 (30 dni; wyciszana osoba nie dostaje
  powiadomienia). <https://about.fb.com/news/2017/12/news-feed-fyi-snooze/>
  *Częściowo.*
- **[S24]** Mastodon, dokumentacja „Dealing with unwanted content” (filtry
  „ostrzeż” / „ukryj”, czas wygaśnięcia, wyciszenie z czasem trwania).
  <https://docs.joinmastodon.org/user/moderating/> i
  <https://docs.joinmastodon.org/entities/Filter/> *Częściowo.* Trendy
  (przegląd moderatora, jeden wpis na konto) według nieoficjalnego
  przewodnika Fedi.Tips:
  <https://fedi.tips/how-do-admins-moderate-trends-on-their-mastodon-server/>
  *Częściowo.*
- **[S25]** Pinterest Help, „Refine your recommendations” (tuner Home feed,
  przełączniki tematów i tablic).
  <https://help.pinterest.com/en/article/tune-your-home-feed> *Częściowo.*

**Badania**

- **[S3]** Aaron Smith / Pew Research Center, „Many Facebook users don't
  understand how the site's news feed works”, 5.09.2018.
  <https://www.pewresearch.org/short-reads/2018/09/05/many-facebook-users-dont-understand-how-the-sites-news-feed-works/>
  *Sprawdzone* (38% vs 59%; 37% vs 20%; 28% i 19% vs 46%).
- **[S4]** Motahhare Eslami i in., „»I always assumed that I wasn't really that
  close to [her]«: Reasoning about Invisible Algorithms in News Feeds”, CHI
  2015. <https://dl.acm.org/doi/10.1145/2702123.2702556> *Sprawdzone*
  (n = 40; 62,5% nieświadomych).
- **[S5]** Anne-Britt Gran, Peter Booth, Taina Bucher, „To be or not to be
  algorithm aware: a question of a new digital divide?”, Information,
  Communication & Society 24(12), 2021.
  <https://www.tandfonline.com/doi/full/10.1080/1369118X.2020.1736124>
  *Częściowo* (różnice według wieku, wykształcenia i płci; Norwegia).
- **[S6]** Andrew M. Guess i in., „How do social media feed algorithms affect
  attitudes and behavior in an election campaign?”, Science 381(6656), 2023.
  <https://www.science.org/doi/10.1126/science.abp9364> *Częściowo*
  (abstrakt: feed chronologiczny „znacząco zmniejszył” czas i aktywność).
- **[S7]** Elisabeth Joyce, Robert E. Kraut, „Predicting Continued
  Participation in Newsgroups”, Journal of Computer-Mediated Communication
  11(3), 2006. <https://academic.oup.com/jcmc/article-abstract/11/3/723/4617705>
  *Częściowo* (2777 nowicjuszy; 44% → 56%).
- **[S8]** Moira Burke, Cameron Marlow, Thomas Lento, „Feed me: motivating
  newcomer contribution in social network sites”, CHI 2009.
  <https://research.fb.com/publications/feed-me-motivating-newcomer-contribution-in-social-network-sites/>
  *Częściowo* (ok. 140 tys. nowych użytkowników).
- **[S9]** Taina Bucher, „Want to be on the top? Algorithmic power and the
  threat of invisibility on Facebook”, New Media & Society 14(7), 2012.
  <https://journals.sagepub.com/doi/abs/10.1177/1461444812440159> *Częściowo.*
- **[S13]** Matthew J. Salganik, Peter S. Dodds, Duncan J. Watts,
  „Experimental Study of Inequality and Unpredictability in an Artificial
  Cultural Market”, Science 311(5762), 2006.
  <https://www.science.org/doi/10.1126/science.1121066> *Częściowo.*
- **[S14]** Allison J. B. Chaney, Brandon M. Stewart, Barbara E. Engelhardt,
  „How algorithmic confounding in recommendation systems increases
  homogeneity and decreases utility”, RecSys 2018.
  <https://arxiv.org/abs/1710.11214> *Częściowo* (symulacje).
- **[S15]** Smitha Milli i in., „Engagement, user satisfaction, and the
  amplification of divisive content on social media”, PNAS Nexus 4(3), 2025.
  <https://academic.oup.com/pnasnexus/article/4/3/pgaf062/8052060> *Częściowo.*
- **[S16]** Jon Kleinberg, Sendhil Mullainathan, Manish Raghavan, „The
  Challenge of Understanding What Users Want: Inconsistent Preferences and
  Engagement Optimization”, EC 2022; Management Science 70(9), 2024.
  <https://arxiv.org/abs/2202.11776> *Częściowo.*
- **[S17]** Michael A. DeVito, Darren Gergle, Jeremy Birnholtz, „»Algorithms
  ruin everything«: #RIPTwitter, Folk Theories, and Resistance to Algorithmic
  Change in Social Media”, CHI 2017.
  <https://dl.acm.org/doi/10.1145/3025453.3025659> *Częściowo.*
- **[S21]** F. Maxwell Harper i in., „Putting Users in Control of their
  Recommendations”, RecSys 2015.
  <https://files.grouplens.org/papers/harper-recsys2015.pdf> *Częściowo.*
- **[S22]** Jared Spool / UIE, „Do users change their settings?”, 14.09.2011
  (mniej niż 5% zmieniło jakiekolwiek ustawienie w Wordzie).
  <https://archive.uie.com/brainsparks/2011/09/14/do-users-change-their-settings/>
  *Częściowo*; wypowiedź praktyka, nie badanie recenzowane.
- **[S23]** Kara Pernice, Raluca Budiu / Nielsen Norman Group, „Hamburger
  Menus and Hidden Navigation Hurt UX Metrics”, 26.06.2016 (179 uczestników;
  odkrywalność niemal o połowę niższa).
  <https://www.nngroup.com/articles/hamburger-menus/> *Częściowo.* Dotyczy
  nawigacji, nie menu kontekstowego wpisu; przeniesienie wniosku to
  **opinia**.
- **[S26]** Jakob Nielsen / NN/g, „Why You Only Need to Test with 5 Users”,
  2000. <https://www.nngroup.com/articles/why-you-only-need-to-test-with-5-users/>
  *Z pamięci.*
- Uzupełniająco, bez numeru:
  - Jakob Nielsen / NN/g, „Participation Inequality: The 90-9-1 Rule”, 2006.
    <https://www.nngroup.com/articles/participation-inequality/> *Częściowo.*
  - NN/g, „UX Design for Seniors (Ages 65 and older)”, wyd. 3, 2019 (123
    uczestników). <https://www.nngroup.com/reports/senior-citizens-on-the-web/>
    *Częściowo*; raport płatny, nieczytany.
  - Neil Thurman i in., „My Friends, Editors, Algorithms, and I”, Digital
    Journalism 7(4), 2019 (26 krajów, 53 314 osób).
    <https://www.tandfonline.com/doi/full/10.1080/21670811.2018.1493936>
    Istnienie *sprawdzone*. Szczegółów o różnicach wieku **nie
    zweryfikowano**, dlatego nie powołuję się na nie w tekście.

**Prawo**

- **[S27]** Rozporządzenie (UE) 2022/2065 (DSA), art. 3 lit. s, art. 19, 25,
  27, 38. <https://eur-lex.europa.eu/eli/reg/2022/2065/oj> Treść art. 3 lit. s
  *sprawdzona*; pozostałe artykuły przytoczone według ich znanej treści
  i [`DSA-LUKI.md`](DSA-LUKI.md).
- **[S28]** EROD, Wytyczne 2/2019 w sprawie przetwarzania danych osobowych na
  podstawie art. 6 ust. 1 lit. b RODO w kontekście usług online, wersja 2.0,
  2019.
  <https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/guidelines-22019-processing-personal-data-under-article-61b_en>
  *Częściowo.*
- **[S29]** TSUE, wyrok z 4.07.2023, C-252/21, Meta Platforms i in. przeciwko
  Bundeskartellamt, pkt 102.
  <https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=celex%3A62021CJ0252>
  *Częściowo* (pkt 102 potwierdzony w omówieniach).
- **[S30]** TSUE, wyrok z 1.08.2022, C-184/20, OT przeciwko Vyriausioji
  tarnybinės etikos komisija (dane pośrednio ujawniające kategorie
  szczególne). <https://curia.europa.eu/juris/liste.jsf?num=C-184%2F20>
  *Częściowo.*
- **[S32]** Grupa Robocza Art. 29, Wytyczne w sprawie zautomatyzowanego
  podejmowania decyzji i profilowania, WP251rev.01, 2018.
  <https://ec.europa.eu/newsroom/article29/items/612053> *Z pamięci*; link
  niesprawdzony.
- **[S33]** EROD, Wytyczne 03/2022 w sprawie zwodniczych wzorców projektowych
  w interfejsach platform mediów społecznościowych, wersja 2.0, 2023.
  <https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/guidelines-032022-deceptive-design-patterns-social-media_en>
  *Z pamięci*; link niesprawdzony.
- **[S34]** Prawo.pl, „Prezydent podpisał ustawę częściowo wdrażającą DSA. UKE
  będzie koordynować nadzór nad platformami internetowymi”, 25.09.2026.
  <https://www.prawo.pl/biznes/dsa-prezydent-podpisal-jedna-z-ustaw-wdrazajacych,1553457.html>
  *Sprawdzone*. Data wejścia w życie **do sprawdzenia** w Dzienniku Ustaw.
- **[S35]** Parlament Europejski, Legislative Train Schedule, „Digital
  Fairness Act”.
  <https://www.europarl.europa.eu/legislative-train/theme-protecting-our-democracy-upholding-our-values/file-digital-fairness-act>
  *Częściowo* (projekt zapowiadany na IV kw. 2026).

**Repozytorium Kukinga** (stan z 25.09.2026, gałąź `main` @ `a7483d52`)

- [`AGENTS.md`](../../AGENTS.md) §1, §3, §5, §8, §12.
- [`app/Domain/Feed/DiscoverFeed.php`](../../app/Domain/Feed/DiscoverFeed.php),
  [`FollowingFeed.php`](../../app/Domain/Feed/FollowingFeed.php),
  [`DailyBoard.php`](../../app/Domain/Feed/DailyBoard.php).
- [`app/Http/Controllers/FeedController.php`](../../app/Http/Controllers/FeedController.php):
  wybór jednego źródła Startu.
- [`app/Domain/Social/Actions/FollowUser.php`](../../app/Domain/Social/Actions/FollowUser.php):
  powiadomienie o obserwowaniu.
- [`app/Domain/Digest/ZbierzTresciDigestu.php`](../../app/Domain/Digest/ZbierzTresciDigestu.php):
  zawartość tygodniowego listu.
- [`resources/views/components/post-card.blade.php`](../../resources/views/components/post-card.blade.php):
  dzisiejsze menu trzech kropek („Otwórz wpis”, „Zgłoś ten wpis”).
- [`docs/legal/COMPLIANCE.md`](../legal/COMPLIANCE.md),
  [`docs/research/DSA-LUKI.md`](DSA-LUKI.md),
  [`docs/research/repos/pixelfed-pixelfed.md`](repos/pixelfed-pixelfed.md),
  [`docs/decyzje/TAGI_PROMOWANE.md`](../decyzje/TAGI_PROMOWANE.md).

---

## Poziom pewności

### Dobrze udokumentowane

- Osoby 50+ gorzej rozumieją ranking feedu i rzadziej próbują nim sterować [S3].
- Ludzie często nie wiedzą o filtrowaniu feedu i przypisują jego skutki
  relacjom [S4].
- Odzew na pierwsze wpisy wiąże się z dalszym publikowaniem [S7], [S8].
- Sygnały popularności zwiększają nierówność i przypadkowość sukcesu [S13].
- Kontrolki „mniej” działające jako waga, a nie filtr, zawodzą oczekiwania
  użytkowników [S19].
- Feed chronologiczny na dużych platformach zmniejsza czas spędzany
  w serwisie [S6].
- Fakty z repozytorium z sekcji B2 (jedno źródło Startu, powiadomienie
  o obserwowaniu, zawartość tygodniowego listu).

### Średnio pewne

- Definicja z art. 3 lit. s DSA obejmuje Odkrywanie i wyszukiwarkę (wysoka
  pewność). Obejmuje też czystą chronologię (średnia pewność).
- Personalizacja z uczeniem z zachowania nie opiera się na art. 6 ust. 1
  lit. b po wyroku C-252/21 (wysoka pewność co do kierunku, średnia co do
  Kukinga).
- Listy tematów mogą podlegać art. 9 (średnia; wymaga prawnika).
- Menu trzech kropek ma niską odkrywalność dla tej akcji (przeniesienie
  wniosku z nawigacji [S23]).

### Hipotezy

- Ranking **powoduje**, że większość autorów przestaje publikować. Zdanie
  z `AGENTS.md` §8 jest mocniejsze niż dowody.
- Najczęstszym powodem „mniej takich” w Kukingu będzie „za dużo od jednej
  osoby”.
- Wszystkie progi liczbowe w punktach 4 i 6 (72 h, 30 dni, 75 autorów,
  80% i 60% dla W1, 5 tematów) to szacunki do dostrojenia w alfie.
- Model (f) poprawi W1 bardziej niż ranking. Nie jest to zmierzone; wymaga
  A/B testu przy większej skali.
