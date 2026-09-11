# Co się zmieniło w Kuking

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
