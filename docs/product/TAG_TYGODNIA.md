# Tag tygodnia i kalendarz kuchni — dane oraz decyzje do #18

Stan odczytany i zmierzony 20 września 2026 na `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
Materiał do [zgłoszenia #18](https://github.com/woogitsu/kuking.pl/issues/18).
Nie jest nową decyzją w `DECISIONS.md` ani deklaracją ukończenia całego zgłoszenia.

## 1. Czy to V2?

**Nie.** `ROADMAP.md` nie wymienia tagu tygodnia ani kalendarza jako V2
(kończy się bramką V1). `FEATURES.md` również nie przypisuje ich do V2.
Pozytywną podstawą jest `product/SOUL.md` §4.6 oraz `RETENTION_LOOPS.md`
pętle 4–5: kalendarz i zaproszenie sezonowe należą do MVP.

Obowiązują przy tym nowsze, węższe rozstrzygnięcia:

- D-021: zwykły tag na ręcznej liście gospodarza, bez powrotu `Topic`;
- D-026: sezon pozostaje informacją w pliku; sam opis sezonu nie uzasadnia
  kolumny w bazie;
- aktualna treść #18 (odczytana przez `gh issue view 18`): daty albo świadomy
  rytm ręczny, kalendarz może pozostać dokumentem redakcyjnym;
- D-059: nie budujemy redakcyjnego newslettera. Ten pakiet nie dodaje
  wysyłek ani nie zmienia tygodniowego podsumowania.

Tabela `seasonal_moments` wspomniana w `SOUL.md` jest dawnym pomysłem,
nie stanem wdrożenia ani powodem do utworzenia jej w tym zadaniu.
Nazwy „Temat tygodnia” w starszych przykładach czytamy jako **tag tygodnia**.
Brak czasowej warstwy w aplikacji jest pytaniem produktowym z #18,
nie poprawką błędu do zakodowania bez wyboru wariantu.

## 2. Co dostaje redakcja

[Kalendarz polskiej kuchni](dane/kalendarz-polskiej-kuchni.json) zawiera:

- wszystkie 12 miesięcy, zbiory oddzielone od zapasów i zwykłe domowe dania;
- 36 propozycji tagu z krótką notatką do istniejącego panelu;
- 12 okazji, łącznie 14 propozycji: święta stałe, ruchome i zwyczaje lokalne;
- źródła do sezonowości i wybranych zwyczajów oraz zastrzeżenie o regionalności.

To **bank propozycji**, nie 50 zaplanowanych publikacji. Kolejność w pliku
nie ustala kolejności promocji. Trzy propozycje w miesiącu nie oznaczają
trzech tygodni miesiąca: gospodarz może powtórzyć tag, wybrać okazję lub
zrobić przerwę. Dwunastu okazji nie należy ogłaszać wszystkich; `SOUL.md`
zaleca najwyżej 8–10 dużych akcentów rocznie.

Czerwiec ma m.in. botwinkę, chłodnik i młode ziemniaki; listopad kapuśniak,
kaszę z sosem i kopytka. Jest to **propozycja redakcyjna**, a nie pomiar
tego, ile osób naprawdę gotuje daną potrawę. Gęsina na św. Marcina nie
zastępuje listopadowego codziennego obiadu. Nie ma podstaw, by napisać
„wszyscy teraz gotują” albo „najpopularniejsze”.

Zbiory są orientacyjne dla Polski; zapasy oznaczają przechowywane produkty,
susze, kiszonki i mrożonki, nie świeży zbiór zimą. Redakcja dopasowuje wybór
do pogody, regionu i tego, co sama może przygotować. Kalendarz nie zawiera
porad dietetycznych, identyfikacji grzybów ani instrukcji konserwowania.

### Jak poprawić plik bez programowania

1. Otwórz kopię pliku w edytorze tekstu obsługującym UTF-8, np. Notatniku.
2. Znajdź miesiąc po polu `nazwa`. Zmień tekst w cudzysłowie, np. `notatka`.
   Zachowaj przecinki, nawiasy i nazwy pól. W treści używaj polskich
   cudzysłowów „…”, żeby nie uszkodzić zapisu JSON.
3. W polu `tag` wpisz dokładną istniejącą nazwę z serwisu, nie nowe hasło
   reklamowe. Propozycje z tego pakietu sprawdza test względem `TagSeeder`.
4. Zapisz kopię jako `.json` w UTF-8. Sprawdź tekst na głos. Notatka ma
   najwyżej 200 znaków — tyle przyjmuje dzisiejszy panel.
5. Skopiuj wybraną notatkę do panelu zgodnie z §5. **Zapis pliku nie publikuje
   niczego w serwisie.** Aplikacja nie wczytuje tego kalendarza.

Plik w repozytorium jest wersjonowaną kopią materiału. Redaktor może używać
i poprawiać swoją kopię bez narzędzi programistycznych; cotygodniowa zmiana
w portalu odbywa się w panelu i nie czeka na commit, seeder ani wdrożenie.
Uzgodnione poprawki banku propozycji można później przekazać do repozytorium.
Nie uruchamiamy `db:seed` jako sposobu zmiany tygodnia.

## 3. Proponowana reguła wyboru — do zatwierdzenia

Gospodarz wybiera **jeden** tag na tydzień, z możliwością przerwy. W piątek
ogląda kolejny tydzień w kalendarzu, wybiera prostą potrawę lub składnik
i zapisuje jednozdaniowy powód: sezon, nadchodząca okazja albo codzienny
obiad. Najpierw sprawdza dostępność produktów i istniejącą stronę tagu.
W razie zbiegu święta i sezonu wybiera człowiek; nie rozstrzyga licznik.

Wybór nie korzysta z liczby polubień, obserwacji, zapisów, kliknięć ani
wykonań. Nie sortuje tagów według ruchu i nie podbija postów w strumieniu.
Ręczne ustawienie pozycji w istniejącym panelu pozostaje wyborem redakcyjnym.
Chronologia wpisów oraz zasady widoczności zostają takie, jakie są.

Warunek „jest co pokazać” z §4 jest sprawdzeniem dostępności, nie oceną
popularności: jeden prawdziwy przepis może wystarczyć do zaproszenia,
sto polubień nie daje pierwszeństwa. Nie wyliczamy punktacji kandydatów.
Ewentualne przyszłe daty tylko uruchamiałyby **wybór człowieka**, a nie
wybierały treść — harmonogram nie jest rankingiem.

Notatka zaprasza do własnego gotowania, bez konkursu, obietnicy nagrody,
presji czasu i oceniania osoby. Nie obiecujemy kolejnego tagu za tydzień,
jeśli nie ma dyżuru gospodarza lub zastępstwa. Powtórzenie potrawy jest
normalne. Nigdy nie tworzymy sztucznych kont ani zdjęć udających wykonanie.

## 4. Pusty tag: uczciwe zachowanie

**Dziś:** panel pozwala promować tag bez wpisów. `Tag::promowane()` filtruje
aktywność tagu i obecność promocji, nie zawartość. Strona pustego tagu ma
zaproszenie do dodania zdjęcia z wybranym tagiem i nie dorabia fotografii.
Nie istnieje niezależny blok tygodniowy na Start, który sam znika po dacie.

**Rekomendacja redakcyjna do zatwierdzenia:** nie promować pustego tagu jako
tagu tygodnia. Przed promocją gospodarz otwiera stronę bez uprawnień
moderatora, sprawdza publiczną treść i sam publikuje rzeczywiście
przygotowane danie, jeśli nie ma od czego zacząć. Sam szkic, prywatny wpis
lub zdjęcie w przetwarzaniu nie spełnia tego warunku.

| Stan | Proponowane działanie |
|---|---|
| Brak widocznych wpisów | Nie zaczynaj promocji. Wybierz ręcznie inny przygotowany tag albo pomiń tydzień. |
| Jest zdjęcie dania, nie ma przepisu | Wolno zaprosić do pokazania dania; nie pisać „zobacz przepisy”. Jeśli zaproszenie obiecuje gotowanie z przepisu, najpierw musi być dostępny przepis. |
| Jest publiczny przepis | Można zaprosić do gotowania, po sprawdzeniu treści i dostępu. |
| Jedyna treść znika lub staje się prywatna | Gospodarz zdejmuje promocję; nie usuwa tagu ani cudzych treści. W wariancie z blokiem system pomija go dla osoby, która nic w nim nie zobaczy. |
| Nie ma gospodarza ani zastępstwa | Brak tygodniowego zaproszenia. Pozostaje zwykły portal; nie publikujemy automatycznego zamiennika „po popularności”. |

Próg „co najmniej jeden widoczny wpis” versus „co najmniej jeden przepis”
wymaga decyzji właściciela. Rekomendacja: wpis wystarcza do zaproszenia
„Dodaj zdjęcie”; przepis jest konieczny do obietnicy przepisów. W kalendarzu
notatki zapraszają do dań. **Żaden nowy test nie utrwala tego progu.**

Przy przyszłym bloku widoczność trzeba sprawdzać dla konkretnego odbiorcy
(w tym blokady w obie strony, stan autora, wpisu, przepisu i zdjęcia).
Globalny licznik tagu ani uprawnienia moderatora nie zastąpią Policy.
Brak widocznej treści ma ukryć zaproszenie, bez ujawniania przyczyny.

## 5. Kto i jak zmienia po starcie

Gospodarz prowadzi kalendarz; właściciel wyznacza konkretną osobę zastępującą.
Technicznie dzisiejszy panel jest dostępny moderatorowi i administratorowi
przez istniejącą bramkę `moderate`. Nie tworzymy roli „redaktor”. Nie należy
nadawać komuś szerszych uprawnień wyłącznie po to, by mógł poprawić plik.

Obsługa obecnego mechanizmu, bez wdrożenia:

1. W piątek wybierz propozycję oraz zapisz planowaną datę rozpoczęcia
   i zakończenia w dzienniku pracy redakcji (własny dokument: daty, tag,
   notatka, powód wyboru, osoba prowadząca, zastępstwo, odnośnik do treści).
   Dla wspólnego rytmu proponujemy poniedziałek–niedzielę w Europe/Warsaw;
   aplikacja dziś tego nie egzekwuje.
2. Sprawdź stronę tagu także jako gość, przygotuj pierwsze prawdziwe danie
   i skontroluj brak obietnicy przepisów przy samych zdjęciach.
3. Wejdź przez Panel moderacji → **Tagi promowane** (`/admin/tagi-promowane`).
   Wpisz dokładną nazwę w **Dodaj tag do listy**, wybierz **Dodaj do promowanych**.
   Jeśli tag już jest na liście, edytuj istniejącą pozycję.
4. Wklej tekst do **Notatka**, wybierz **Zapisz notatkę**. Kolejność ustawiaj
   **W górę / W dół**. Otwórz **Zobacz stronę tagu** i sprawdź efekt.
5. Po zakończeniu wybierz **Zdejmij z promowanych** przy sezonowej pozycji.
   Jeśli tag był wcześniej stałym poleceniem, przywróć zapisaną wcześniejszą
   notatkę i pozycję zamiast usuwać go z listy. Promocje obsługują również
   zainteresowania nowych kont; nie zastępuj całej listy jednym tagiem.
6. Zapisz rzeczywiste daty zmiany w dzienniku. `audit_log` rejestruje operacje
   panelu, ale nie jest publicznym archiwum tygodniowych wyróżnień.

**Ograniczenie:** nie da się tu zaplanować startu ani końca. Bez pracy
człowieka notatka zostaje na stronie po terminie. Sam dopisek daty w notatce
nie sprawi, że promocja wygaśnie. Przy dłuższej nieobecności należy wcześniej
usunąć tygodniowe zaproszenie lub przywrócić notatkę bez terminu.

## 6. Decyzje właściciela — warianty i koszt

Poniższe koszty są szacunkami planistycznymi, nie pomiarem pracy redakcji.

| Wybór | Koszt i konsekwencje |
|---|---|
| **A. Rytm ręczny na istniejących promocjach — rekomendacja na start** | Brak nowego kodu i migracji. Orientacyjnie 15–30 min tygodniowo na wybór, kontrolę i zmianę, poza gotowaniem. Konieczny gospodarz i zastępstwo. Nie spełnia automatycznego końca ani niezależnego bloku na Start z #18. |
| **B. Datowane wyróżnienie na istniejącym mechanizmie tagów** | Osobne wdrożenie formularza dat, zakresu aktywności, bloku na Start, autoryzacji, migracji z ochroną wyborów przy rollbacku oraz testów. Redakcja planuje z wyprzedzeniem; nie wdraża kodu co tydzień. Większy koszt początkowy, mniej ręcznej obsługi końca. |

Do wybrania: A albo B; warunek zawartości z §4; osoba prowadząca i zastępstwo.
Nie przypisujemy tych obowiązków konkretnej osobie bez decyzji właściciela.
Archiwum publiczne jest osobną decyzją w wariancie B, nie bezpłatnym skutkiem
dopisania dat. Historia `audit_log` i dziennik redakcji wystarczają do pracy
wewnętrznej wariantu A, ale nie spełniają obietnicy archiwum dla czytelnika.

### Gotowy zakres przyszłego wdrożenia B

Po wyborze B uzupełnić #18 o te granice, zanim powstanie migracja:

- zwykłe promocje nadal działają bez dat; wyróżnienie tygodniowe nie może
  zmieniać znaczenia wszystkich poleceń ani wymuszać powrotu `Topic`;
- jedno aktywne wyróżnienie; zakres początku włącznie, końca wyłącznie,
  jawna strefa Europe/Warsaw; konflikt okresów odrzucany z zachowaniem danych;
- wygaśnięcie sprawdzane podczas odczytu, także przy awarii harmonogramu;
  brak automatycznego wyboru następcy; test granic dat i zmiany czasu;
- blok na Start niezależny od źródła strumienia, bez sortowania wpisów;
  zniknięcie po terminie i po utracie widocznej zawartości dla odbiorcy;
- jedna droga dodania przez istniejące `posts.create` z parametrem `tag`;
  wybór można usunąć, a błąd walidacji nie odtwarza go wbrew osobie;
- kontrola dostępu przy zapisie, audyt zmian i ekran w istniejącym panelu;
- migracja, test na PostgreSQL, `DATABASE.md`, bezpieczny rollback,
  czerwone kontrole regresji i pomiar interfejsu 320 px / 200%.

Nie trzeba do tego odczytu kalendarza przy każdym żądaniu, importera,
nowego pakietu, algorytmu sezonowości ani systemu zarządzania treścią.

## 7. Źródła i pomiary

Źródła zewnętrzne oraz zakres ich użycia są zapisane w JSON. Przykłady
letnich produktów potwierdza [NCEŻ](https://ncez.pzh.gov.pl/abc-zywienia/zasady-zdrowego-zywienia/sezonowe-warzywa-i-owoce-lato/),
a ruchomy termin tłustego czwartku [ARiMR](https://www.gov.pl/web/arimr/powiedzial-bartek-ze-dzis-tlusty-czwartek2).
Są pomocą do redakcji, nie badaniem zachowania społeczności Kuking.

Własny pomiar, nietknięte drzewo: filtr `Tag` — **258 testów, 49 992 asercje,
17,55 s**, PostgreSQL `127.0.0.1:55439`, własna baza
`kuking_flota_gpt-tag-tygodnia`, użytkownik `kuking`. Wykonano przez skrypty
floty po przygotowaniu runtime, przed dodaniem plików tego zadania.
Pierwsze wywołanie z kilkoma filtrami nie powiodło się wskutek cytowania
argumentu przez powłoki; wynik powyżej pochodzi z poprawnego filtra `Tag`.

Wśród wykonanych testów: `TagiPromowaneAdminTest` (operacje i uprawnienia),
`TagiPromowaneZListyGospodarzaTest` (seeder respektuje wybór gospodarza),
`TagPlacePagesTest` (pusty tag oraz droga dodania), `TagPreselectionTest`
(przejście przez logowanie, odznaczenie i walidacja). To testy HTTP aplikacji,
nie pomiar w przeglądarce ani na produkcji.

Odczyt kodu: `TagPromotion`, `TagPromotionController`, `Tag::scopePromowane`,
`FeedController` i widoki panelu oraz strony tagu. Brak dat i niezależnego
bloku tygodniowego to ustalenie z tego odczytu, nie wynik nowego testu.
Test `KalendarzKuchniDaneTest` sprawdza tylko integralność danych, istniejące
nazwy i możliwość zapisania notatek. Nie zatwierdza wyborów produktowych.

Nie przejmowano cudzych wyników jako własnych. Komentarz z 9 września pod
#18 zawiera m.in. deklarację czasu obsługi; nie użyto jej jako pomiaru.
Końcowe kontrole pakietu: [raport weryfikacji](TAG_TYGODNIA_WERYFIKACJA.md).

Nie zmieniono aplikacji, schematu, danych produkcyjnych ani listy promocji.
Nie zbudowano dat, nowego bloku, archiwum ani automatycznego ukrywania
pustego tagu: wymagają wskazanych decyzji. #18 pozostaje częściowo otwarte.
Wycofanie tego pakietu polega na cofnięciu commita z dokumentami, danymi
i ich testem; nie wymaga operacji na bazie ani odwracania promocji.
