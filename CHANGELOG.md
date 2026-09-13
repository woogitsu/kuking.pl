# Co się zmieniło w Kuking

## Alfa 0.19 — zeszyty zgodne z wizualizacją

- Zeszyty mają ciemne karty, a ostatnie zapisy znajdują się pod nimi w głównej części strony.
- Przepisy w zeszycie mają większe zdjęcia nad pełnymi tytułami i układają się w siatkę dopasowaną do dostępnego miejsca.
- Klawiatura zaznacza krótkie „Zobacz przepis”, dzięki czemu długi tytuł nie wypycha fokusu pod nawigację.
- Instrukcja pustego zeszytu wyjaśnia zapisywanie bez obietnicy stałego dostępu do każdej treści.

## Alfa 0.18 — kolejne ekrany zgodne z wizualizacją

- Logowanie i rejestracja mają osobną kartę formularza obok zaproszenia.
- Na szerokim ekranie tytuł, opis i dane przepisu stoją obok zdjęcia; wszystkie akcje są pod nimi.
- Profil ma większy awatar oraz jeden zestaw czytelnych statystyk pod ciemnym nagłówkiem.
- Strona publiczna wyjaśnia zeszyty, widoczność i pobieranie własnych treści w trzech kartach.
- Przy jednoczesnym dużym powiększeniu pisma i tekstu aplikacji wąski nagłówek mieści znak garnka bez poziomego przewijania.
- Obrys zaznaczonej klawiaturą zakładki mieści się w jej ramie także przy powiększeniu strony.

## Alfa 0.17 — publiczna strona zgodna z wizualizacją

- Trzy otwarte, numerowane kroki z odnośnikami zastępują białe kafle „Jak działa”.
- Duży blok „Twój przepis. Czyjś dobry obiad.” stoi bezpośrednio pod krokami, z publicznym zdjęciem i podpisem autora.
- Tablica osób, dania i najnowsze wpisy pozostają dostępne niżej.

## Alfa 0.16 — spójne karty osób

- Karty proponowanych osób na Odkrywaj i w wyszukiwaniu mają te same proporcje co na Start.

## Alfa 0.15 — kompozycja strony głównej zgodna z wizualizacją

- Krótkie powitanie, odrębny nagłówek aktualności i duży tytuł w ciemnym kaflu dodawania z pierścieniem.
- Na komputerze Start / Odkrywaj / Mój zeszyt oraz osobne Szukaj. Mobilne pięć pozycji bez zmian.
- Ciemny wstęp „Co dobrego u innych?” i osobne karty osób oraz dań; zachowane rzeczywiste propozycje, notatki, podglądy i obserwowanie.
- Nowy wygląd obejmuje także zalogowane wejście przez `/`, wyszukiwanie i stronę publicznych wpisów. Pomoc wyjaśnia kolejność strumienia.

## Alfa 0.14 — czytelne wiadomości i dokładniejsze instrukcje

- Powiększyliśmy drobne teksty w e-mailach oraz linki w tygodniowym podsumowaniu.
- Wiadomość o pobraniu danych podaje także godzinę wygaśnięcia linku.
- Instrukcje logowania opisują dodatkowe potwierdzenie na stronie. Ustawienia adresu e-mail nie sugerują już wysyłania hasła pocztą.
- Instrukcje zabezpieczenia konta i edycji przepisu dokładniej opisują wymagane kroki.
- Przy edycji opublikowanego przepisu komunikaty mówią o zapisanych zmianach, a nie o szkicu.
- Pobrana paczka danych obsługuje ciemny wygląd systemu. Ostrzeżenie o przygotowywanych zdjęciach nie obiecuje już terminu ich gotowości.

## Alfa 0.13 — aktualizacja zainstalowanej aplikacji

Poprawiliśmy pobieranie aktualizacji w aplikacji zapisanej na telefonie, aby nowy ekran braku połączenia docierał także do osób korzystających ze starszej wersji. Aktualizacja nie przeładowuje otwartego formularza.

## Alfa 0.12 — spójny wygląd także poza głównymi ekranami

- Ekrany awarii i braku internetu oraz pobrane dane mają nową oprawę i czytelny krój pisma.
- Pozostałe wiadomości systemowe mają spójny wygląd. Długie adresy nie rozpychają wiadomości na telefonie.
- Zainstalowana aplikacja odświeża ikony po zmianie marki i zachowuje dostępny ekran bez internetu.
- Komunikaty awarii podają, co zrobić, bez niepotwierdzonych zapewnień o stanie danych lub terminie powrotu.
- Sprawdzenie antyspamowe mieści się na wąskim telefonie i korzysta z wybranego jasnego lub ciemnego wyglądu.
- Podpowiedzi składników i przygotowania mają poprawne nowe linie. Wskaźnik kroków przepisu mieści się także przy dużym tekście na wąskim ekranie.

## Alfa 0.11 — klawiatura i duży tekst

Karty dań można nadal otwierać po kliknięciu zdjęcia lub opisu. Przy poruszaniu się klawiaturą fokus obejmuje czytelną nazwę, dzięki czemu wysoka karta nie chowa go pod nawigacją.

## Alfa 0.10 — spójne podstrony i wiadomości

- Nagłówek każdego profilu ma tę samą grafitową oprawę. Tytuły przepisów korzystają z nowej typografii.
- Opisy w ustawieniach i przy wyborach formularza są czytelniejsze.
- Wiadomości e-mail otrzymały nową paletę, prosty krój pisma i spójne przyciski.
- Instrukcje logowania i pomocy są krótsze i precyzyjniejsze.

## Alfa 0.9 — pełny układ nowej marki

Pływająca nawigacja, ciemny blok publikacji, nowe karty i typografia. Spójny wygląd profilu, zeszytów, wyszukiwarki, przepisów i formularzy. Funkcje korzystają z dotychczasowych danych i ustawień konta.

Ten plik jest dla **ludzi**, nie dla programistów. Piszemy tu, co widać
na ekranie — nie jak się nazywa klasa, którą przy okazji przeniesiono.

Każde podbicie numeru wersji (`config/kuking.php`, klucz `kuking.wersja.etykieta`)
ma tu swój wpis. Jedno pilnuje drugiego: wersja bez wpisu jest numerem bez
treści, a wpis bez wersji nie da się z niczym powiązać.

Numer rośnie przy każdej zmianie, którą **człowiek zobaczy**: nowy ekran,
zmieniony układ, nowa funkcja, inne zachowanie formularza. Poprawki bez śladu
w interfejsie — testy, refaktor, dokumentacja — numeru nie ruszają, więc i tu
ich nie ma.

---

## Alfa 0.8 — 12 września 2026

### Wygląd i strona główna

- Jasne neutralne tło, białe karty i grafitowe pismo. Czerwone akcenty mają
  osobne odcienie do jasnego i ciemnego motywu.
- Powitanie na stronie głównej dostało wyraźniejszą oprawę.
- Podpis przy dodawaniu zdjęcia ma teraz 18 px zamiast 16 px przy domyślnym
  rozmiarze tekstu. Przy dużej czcionce opis nadal przechodzi do własnego wiersza.

### Formularze i zeszyt

- Błędy formularzy mówią, co poprawić, i prowadzą do odpowiedniego pola.
- Nieprawidłowy wybór zeszytu daje komunikat z prośbą o odświeżenie strony
  i ponowny wybór, zamiast błędu serwera.

### Wpisy

- Szkic ma podpis „Szkic — jeszcze nieopublikowany” zamiast pustego odnośnika daty.

---

## Alfa 0.7 — 12 września 2026

### Zdjęcia

- **Po dodaniu zdjęcia widać zdjęcie, a nie napis o nim.** Do dziś po
  opublikowaniu wpisu każdy — zawsze, nie od czasu do czasu — dostawał zdanie
  „Twoje zdjęcie się jeszcze przygotowuje". Przyczyna nie leżała w obciążeniu,
  tylko w kolejności: wgranie zdjęcia i publikacja wpisu to jedno żądanie, więc
  w chwili rysowania strony nie było jeszcze ani jednej gotowej wersji zdjęcia.
  Teraz jedna powstaje od razu. Waży **61,5 kB zamiast 6,14 MB** oryginału
  i zostaje potem w serwisie, więc telefon pobiera 66,8 kB tam, gdzie wcześniej
  178,6 kB.
- **Zdjęcie widać już w trakcie wysyłania**, jeszcze zanim dojedzie na serwer —
  prosto z pamięci telefonu. Wcześniej pokazywało się jako znaczek zajmujący
  **niecałą połowę** szerokości; teraz bierze całą.
- **Wpis z kilkoma zdjęciami, z których część się jeszcze przygotowuje, nie
  rozjeżdża się już w bok.** Blok z komunikatem brał pół szerokości karty
  i pięć wierszy — teraz całą i dwa.

### Karta wpisu i strumień

- **Menu przy wpisie to trzy kropki, bez podpisu.** Tak samo jak w miejscach,
  które nasi ludzie znają od lat. Sam przycisk zszedł ze **125 px na 48 px**,
  a główka karty na wąskim telefonie z osiemnastu wierszy na osiem.
- **Data przy wpisie z tego roku nie powtarza roku** — „12 września, 10:04"
  zamiast „12 września 2026, 10:04". Przy starszych wpisach rok zostaje, bo
  bez niego data przestaje być prawdą.
- **Wpis, który jest tylko wskazaniem przepisu, prowadzi wprost do przepisu.**
  Wcześniej otwierał pustą stronę — bez zdjęcia i bez przepisu — z której
  trzeba było kliknąć jeszcze raz. Przy okazji: taka strona pokazywała
  „publicznie" także pod przepisem widocznym wyłącznie dla obserwujących.
- **Zakładka „Świeżo z kuKING" odzyskała zjedzoną spację**, a awatary przy
  wpisach przestały być ściskane w owal.

### Komentarze

- **„Odpowiedz" i „Popraw" stoją obok siebie**, a nie jedno pod drugim — blok
  akcji zszedł ze 196 px na 138 px. Przy najwęższych telefonach nadal się
  zawijają, bo naprawdę się nie mieszczą.
- **Zniknęła pustka nad „Napisz komentarz"**, a między podpisem pola a samym
  polem pojawił się odstęp, którego tam nie było wcale.

### Profil i ustawienia

- **`@nazwa` stoi obok imienia**, a nie w osobnym wierszu. Rząd przycisków
  profilu wjechał dzięki temu **nad zgięcie ekranu** — na telefonie pierwszy
  raz widać go bez przewijania.
- **„Ustawienia" prowadzą na stronę o nazwie „Ustawienia".** Do dziś ten napis
  otwierał ekran zatytułowany „Czytelność". Nowa strona jest spisem wszystkich
  dziewięciu ekranów ustawień.
- **Menu konta przy awatarze** — „Mój profil", „Ustawienia", „Wyloguj się" —
  i „Powiadomienia" w pasku górnym na telefonie. Wcześniej własny profil był
  jedyną drogą do obsługi konta z telefonu.

### Strona główna

- **Powitanie przestało zmyślać porę dnia.** „Dobry wieczór" witało od 15:00,
  a godzinę serwis liczył w strefie serwera — latem o 11:50 uważał, że jest
  9:50, a po 23:00 mówił „Dzień dobry". Teraz wita „Witaj" i pyta, co dziś
  gotujesz; to jest prawdą o każdej porze i w każdym kraju.

### Pod spodem (bez zmian na ekranie, ale warto wiedzieć)

- Komenda pokazująca, do kogo nie doszedł list z serwisu — bez pokazywania
  żetonu z takiego listu.
- Dziennik decyzji urósł o dziesięć wpisów (D-172 … D-181), w tym o trzy
  REGUŁY, a nie pojedyncze poprawki: o tym, że czerwień w testach nie musi
  pochodzić ze zmiany, którą właśnie oglądasz; o tym, że reguła CSS oparta na
  „pierwszym elemencie" nie trafia w żadne widoczne pole formularza; i o tym,
  że skrócenie listy kolumn w zapytaniu potrafi po cichu zgasić zdjęcie.

---

## Alfa 0.6 — 11 września 2026

### Dla wszystkich

- **Nazwa serwisu wygląda wszędzie tak samo: kuKING, dwukolorowo.** Do tej pory
  w zdaniach pisaliśmy „Kuking", a dwukolorowy zapis trzymaliśmy na jedno
  miejsce na ekranie. Teraz nazwa jest pisana tak samo w nagłówkach, w tekście
  i w stopce — bo to nazwa mieszkańca tego serwisu, nie żart, który trzeba
  racjonować. Nazwa zostaje zwykłym „Kuking" tam, gdzie kolory nie działają
  (tytuł okna, temat listu, opis zdjęcia) oraz w komunikatach o błędach,
  w sprawach moderacyjnych i w dokumentach — tam nie ma miejsca na charakter.
- **Przycisk na stronie powitalnej mówi teraz, co obiecujemy:** „Zostań
  kuKINGiem — bez opłat i bez reklam". Zamiast „to darmowe", które brzmiało
  jak sprzedaż.
- **Podpowiedź pod komentarzem mówi, ILE wystarczy, a nie JAK pisać:**
  „Choćby jedno zdanie. Pytanie do autora też jest w porządku."
- **Mniej tłumaczenia się w tekstach.** Na stronie „Napisz do nas" cztery różne
  miejsca zapewniały, że odpisuje człowiek, a nie automat — zostało jedno, to,
  za którym stoi konkret. Powtarzane cztery razy budziło dokładnie to
  podejrzenie, które miało uspokoić.
- **Ekran „Dopisz szczegóły" przestał obiecywać, że nie trzeba przewijać.**
  Stało tam „nie musisz nic przewijać ani szukać", a zmierzona wysokość tej
  strony to od **10 249 px** (sam tytuł i puste wiersze) do **16 586 px**
  (osiem składników i sześć kroków) — czyli od 16 do 26 ekranów telefonu. Cała
  informacja została: wszystko jest na jednej stronie, nic nie jest
  obowiązkowe, wypełnij tyle, ile chcesz, a poprawnie wpisane dane nie zginą.
- **„Świeżo z Kuking" nie ma już pustej prawej kolumny.** U zalogowanych trzecia
  kolumna była zarezerwowana i puściusieńka — **656 px pustki** przy szerokim
  oknie, **452 px** przy węższym — a gość dostawał całą stronę zwiniętą do
  wąskiej szpalty. Teraz stoi tam tablica „kuKINGi na dziś", ta sama, która na
  tym ekranie już była, tylko niżej. Strona skróciła się z 10 557 do 8 898 px,
  a kolumna z tekstem ma tyle samo miejsca co przedtem.
- **Odstępy na stronie przepisu.** Pięć par bloków tekstu stało dosłownie na
  zero pikseli — tytuł kleił się do wiersza z autorem, nagłówek „Składniki" do
  listy, „Skąd ten przepis" do pierwszego akapitu. Teraz każda para ma odstęp,
  ten sam na telefonie i na komputerze, i rośnie razem z tekstem, gdy ktoś
  powiększy czcionkę w przeglądarce.
- **Źródło przepisu pokazuje się dokładnie tak, jak je wpisałeś.** Widok
  doklejał z przodu „Po", więc wpisane „Nasze smaki" wychodziło jako „Po Nasze
  smaki.", a „po mamie" jako „Po po mamie.". Samo pytanie w formularzu też się
  zmieniło — pyta teraz „od kogo albo skąd", bo o to właśnie chodzi.
- **Regulamin i polityka prywatności mówią o usłudze, a nie o sobie.** Zniknęły
  zdania o tym, jak dokument był pisany i co sobie o nim myślimy. **Wszystkie
  niewygodne fakty zostały** — również te o braku podpisanych umów powierzenia,
  braku inspektora ochrony danych i nieustalonym okresie życia danych
  w kopiach zapasowych. Poprawił się przy tym błąd merytoryczny: §8 mówił
  „przez pierwsze 24 godziny", a termin liczy się od pierwotnej decyzji.
- **W logotypie kolor marki został tylko na „King".** „.pl" jest ciemne, tak jak
  „ku" — jeden akcent w znaku zamiast dwóch.
- **Awatar bez zdjęcia przestał się zwijać do rozmiaru litery.** Konto, które nie
  dodało zdjęcia profilowego, pokazuje inicjał w kółku o właściwej wielkości.
- **Wyłączony przycisk w karuzeli nie drga przy naciśnięciu**, a główne pole
  wyszukiwania ma tę samą wysokość co pozostałe pola w serwisie.

### Pod spodem (bez zmian na ekranie, ale warto wiedzieć)

- **Komendy konsolowe odmieniają rzeczownik przez liczbę.** „Dopisano 1 wpisów"
  zniknęło z czterech komend. Reguła odmiany była w projekcie od dawna — po
  prostu nie była tam użyta.

---

## Alfa 0.5 — 11 września 2026

### Dla wszystkich

- **Prośba o link do zalogowania nie wpuszcza już na cudze konto.** Jeśli ktoś
  założył konto na Twój adres, a ten adres nigdy nie został u nas
  potwierdzony, formularz „Wyślij mi link do zalogowania" przysyła
  teraz wiadomość z **ustawieniem nowego hasła**, a nie przycisk wchodzący
  prosto na to konto. Po ustawieniu hasła stare hasło przestaje działać, a
  wszystkie otwarte sesje na tym koncie zostają zamknięte — nawet jeśli ktoś
  obcy z nich korzystał.
- **Kliknięcie odnośnika z tej wiadomości potwierdza adres.** Od tej chwili
  konto wraca do zwykłego logowania jednym przyciskiem.
- Ekran po wysłaniu formularza wygląda **dokładnie tak samo** jak dotąd i
  dokładnie tak samo dla adresu, który konta u nas nie ma — żeby nie dało się
  z niego wyczytać, kto ma tu konto, a kto nie.

---

## Alfa 0.4 — 11 września 2026

### Dla wszystkich

- **Dodany przepis jest wreszcie widoczny tam, gdzie ludzie patrzą.** Do tej
  pory opublikowany przepis stał wyłącznie na profilu autora i w wyszukiwarce
  — czyli tam, gdzie trzeba go było już szukać. Teraz pokazuje się w „Świeżo
  z Kuking", w feedzie osób, które autora obserwują, i na tablicy „kuKINGi na
  dziś", z tytułem, zdjęciem i przyciskiem „Ugotowałem" od razu pod ręką.
- **To nie jest kopia przepisu, tylko droga do niego.** Poprawiony tytuł albo
  wymienione zdjęcie widać w strumieniu natychmiast, bez czekania i bez
  drugiego kliknięcia.
- **Przepis schowany, usunięty albo zawężony do obserwujących znika ze
  strumieni razem z przepisem** — nie zostaje po nim żadna karta.
- **Dwa kliknięcia „Opublikuj" dają jedną pozycję w strumieniu, nie dwie.**

---

## Alfa 0.3 — 11 września 2026

### Dodawanie przepisu przestało odstraszać

- **Ekran dodawania przepisu pyta o sześć rzeczy zamiast prawie stu.** Zdjęcie,
  tytuł, składniki, przygotowanie, kto to zobaczy, „Opublikuj". Porcje, czasy,
  trudność, pochodzenie przepisu i skan starej kartki przeniosły się na osobny
  ekran „Dopisz szczegóły" — wypełniasz je **po** opublikowaniu albo wcale.
- **Składniki i przygotowanie wpisuje się zwykłym tekstem.** Można wkleić listę
  z kartki albo z maila — każdy wiersz stanie się składnikiem, a pusta linia
  rozdzieli kroki. Nie trzeba już dodawać pól po jednym.
- **Przepis bez listy składników też da się opublikować.** Jeśli znasz danie
  z głowy i wolisz opisać je zdaniem, nic Cię nie zatrzyma. Składniki możesz
  dopisać później.
- **Zaproszenie „dopisz szczegóły" pojawia się tylko wtedy, gdy naprawdę jest
  co dopisać.** Przepis wypełniony do końca go nie dostaje.
- **Nad każdym formularzem dodawania widać obie drogi** — „Zdjęcie i kilka
  słów" oraz „Cały przepis". Wcześniej w większości miejsc w ogóle nie było
  widać, że istnieje ta druga.

### Więcej treści na ekranie, mniej przewijania

- **Strona przepisu ma drugą kolumnę.** „Ugotowałem", „Zapisuję", „Gotuję"
  i „Podziel się" stoją obok treści, a nie nad nią — strona zrobiła się
  o kilkaset pikseli krótsza, a „Ugotowałem" widać wyżej.
- **„Świeżo z Kuking" i „Co się dziś gotuje" układają się w dwie kolumny**
  tam, gdzie jest na nie miejsce. Lista skróciła się prawie o połowę.
- **Pola do wpisywania są większe** — jednowierszowe 64 px zamiast 56,
  wielowierszowe 176 px zamiast 128.

Przy powiększonej czcionce wszędzie wraca jedna kolumna. Nic się nie chowa.

### Dla moderatorów i administratorów

- **Panel bierze całą szerokość okna.** Tabela kont na szerokim monitorze
  (od około 1600 px) mieści się bez przewijania w bok. Na węższym ekranie
  tabela dalej się przewija — ale w swoim polu, nie całą stroną.
- **Puste kolejki mówią pełnym zdaniem**, zamiast jednej linijki tekstu.

### Dokumenty

- **Regulamin i polityka prywatności nie mówią już o sobie, że nie były
  sprawdzone przez prawnika.** Wszystkie zdania o tym, jak działa serwis,
  zostały bez zmian — zniknęła tylko uwaga o tym, kto tych dokumentów nie
  czytał.
---

## Alfa 0.2 — 11 września 2026

### Dla wszystkich

- **Długie wpisy nie zajmują już całego ekranu.** Wpis dłuższy niż osiem
  wierszy albo czterysta znaków pokazuje początek i odnośnik „Czytaj dalej",
  który prowadzi na stronę wpisu. Lista składników liczy się po wierszach,
  a nie po znakach — bo to wiersze zjadają ekran.
- **Przycisk „Zostań kuKINGiem" nie rozpada się już na telefonie.** Wcześniej
  napis łamał się w środku wyrazów („Zost / ań kuKINGi / em"); teraz mieści
  się w dwóch wierszach łamanych na spacjach.
- **Gość widzi stronę w pełnej szerokości**, z prawą szyną, tak samo jak
  osoba zalogowana. Wcześniej strona zwężała się bez powodu.
- **Logotyp:** człon „King" wrócił do koloru marki.
- **Wejście kontem Google i Facebooka stoi nad formularzem**, a nie pod nim.
  Wcześniej widziała je tylko osoba, która i tak wpisała już hasło.
- **„Co się dziś gotuje" pokazuje więcej.** Wybór gospodarza jest teraz
  uzupełniany automatycznie do pełnej tablicy — wcześniej zaznaczenie choćby
  jednej pozycji w panelu wyłączało dobieranie i strona zostawała w połowie
  pusta.
- **Karty wpisów i sekcje stron przestały wyglądać identycznie.** Sześć
  różnych rzeczy — karta wpisu, formularz, sekcja strony, blok szyny, ramka
  z wyjaśnieniem, kafel do kliknięcia — miało do tej pory ten sam wygląd.
  Teraz widać, co jest treścią, co trzeba wypełnić, a co tylko wyjaśnia.

### W formularzach

- **Pola w jednym rzędzie stoją równo.** „Na ile porcji" wisiało wyżej niż
  „Przygotowanie" i „Gotowanie", bo ma krótszy podpis.
- **Rozmiar tekstu schodzi niżej niż dotąd** — doszły trzy mniejsze rozmiary
  dla osób, którym domyślny jest za duży.

### Dla moderatorów

- **Menu poza panelem pokazuje jedno wejście, nie dziewięć pozycji.**
  Przy wejściu stoi liczba rzeczy czekających we wszystkich kolejkach razem;
  rozbicie na kolejki jest w panelu.
- **Zdjęcia w kolażu na stronie powitalnej wybiera się w panelu**, spośród
  zdjęć z wpisów publicznych. Wcześniej były wpisane na sztywno.
- **Tablica dnia i karty wpisów mają równy rytm**, a rzadsze akcje schowały
  się do menu „Więcej".

### Pod spodem (bez zmian na ekranie, ale warto wiedzieć)

- Kopię bazy i próbę jej odtworzenia robi się dwoma komendami, a próba
  sprawdza **wynik**, nie kod wyjścia.
- Cofnięcie migracji, które skasowałoby dowód zgody z RODO art. 7, **odmawia**
  i mówi, co zrobić zamiast tego.

---

## Alfa 0.1 — pierwsze wydanie

Wersja, od której zaczęliśmy. Historia sprzed 11 września 2026 jest
w historii repozytorium — ten plik zakładamy dziś i nie odtwarzamy go wstecz,
bo wpisy pisane z pamięci po fakcie są gorsze niż ich brak.
