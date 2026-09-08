# UI kit — dokumenty prawne

Trzeci kształt treści z `03-szablony/`: długi dokument, który czyta się od góry
do dołu. Trzy strony: **Prywatność**, **Regulamin**, **Zasady**.

## Czym się różni od dwóch pozostałych kształtów

| | strumień kart | dokument z panelem | **długi dokument** |
|---|---|---|---|
| kolumna | 45rem | 45rem + panel | **38rem (~65 znaków)** |
| zdjęcia | tak, są treścią | tak | **nie ma żadnych** |
| nawigacja wewnętrzna | brak | panel przyklejony | **spis treści na kotwicach** |

Kolumna jest węższa, bo w dokumencie prawnym nie ma zdjęć, które łamałyby rytm
— i dlatego wiersz musi być krótszy, żeby oko trafiało w następny.

## Reguły

- **Spis treści to zwykłe kotwice `#id`.** Działa bez skryptu, a przycisk
  „wstecz” przeglądarki wraca tam, skąd się skoczyło.
- **Tabela przewija się we własnym pudełku**, nigdy razem ze stroną — to drugi
  (po karuzeli zdjęć) świadomy wyjątek od zakazu przewijania w poziomie.
  Nad tabelą stoi zdanie mówiące, co w niej jest: przy 320 px widać naraz jedną
  kolumnę i to zdanie bywa jedyną orientacją.
- **Punktor wraca.** Cały system zdejmuje punktory, ale w spisie treści i w
  wyliczeniu wewnątrz tekstu ciągłego lista bez punktora przestaje wyglądać jak
  lista — stąd reguła `.tekst-czytany ul { list-style: disc }` w warstwie base.
- **Gra słowem „kuKING” nie pada tutaj ani razu.** Regulamin i polityka
  prywatności są na liście miejsc, w których żart jest zakazany.
- **Data obowiązywania jest widoczna** i stoi nad treścią, nie pod nią.

## Zastrzeżenie

Treść tych trzech dokumentów jest **napisana na potrzeby makiety w tonie
Kuking** i nie jest opinią prawną. Prawdziwe teksty trzeba napisać przed
startem; ten kit pokazuje wyłącznie, jak taki dokument ma wyglądać i jak się go
czyta.
