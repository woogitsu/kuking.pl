# Czego świadomie nie zmieniam i dlaczego

Ta lista jest równie ważna jak reszta paczki. Pokazuje, że dzisiejszy wygląd nie
jest zły w całości, i chroni przed przepisaniem serwisu w imię odświeżenia.
Każda pozycja ma powód, a nie tylko brak powodu, żeby ruszyć.

Poprzednia próba (kit v2) rozbiła się między innymi o to, że proponowała zmiany
tam, gdzie serwis miał już lepsze rozwiązanie — i za każdym razem rozstrzygano
na rzecz serwisu, nie makiety.

---

## 1. Cała paleta kolorów — co do wartości

Terakota `#B3401F`, kość słoniowa `#FAF6F0`, ciepła czekolada `#1E1A16`
w motywie ciemnym. Ani jeden hex nie zmienił wartości.

**Dlaczego:** paleta jest policzona (70 par, wszystkie przechodzą), ciepła,
pasuje do zdjęć jedzenia i nie wygląda jak „beż dla starszych”. Właściciel prosi
o „podobny styl, ale przyjemniejszy” — przyjemniejszy robi się przez hierarchię
i powietrze, nie przez inny kolor. Trzy najciaśniejsze pary są opisane
w `KOLOR.md` §1 i zmiana któregokolwiek z tych odcieni wymaga przeliczenia obu
par naraz.

## 2. Nazwy wszystkich istniejących tokenów

Żaden token nie zmienił nazwy. Nowe są dopisane, stare zostały.

**Dlaczego:** w arkuszach serwisu jest kilkaset użyć `var(--…)`. Przemianowanie
kosztuje dzień pracy i ryzyko, a nie daje człowiekowi przy garnku ani jednej
rzeczy więcej.

## 3. Nazwy klas CSS, które już istnieją

`.btn`, `.card`, `.field`, `.badge`, `.empty-state`, `.bottom-nav`,
`.error-summary`, `.wizard-steps`, `.autosave-badge`, `.avatar`, `.chip` —
zostają. Nowe rzeczy dostają nazwy polskie, tak jak nowsze partie `app.css`
(`.kolumna-czytania`, `.karuzela-*`).

**Dlaczego:** to samo co wyżej. Mieszanka polskiego i angielskiego w nazwach
klas jest brzydka, ale kosztuje zero, a jej naprawa kosztuje dzień.

## 4. Rozmiar podstawowy 18 px

**Dlaczego:** to była decyzja podjęta dla tego odbiorcy. Nie wraca do 16 bez
powodu i takiego powodu nie ma.

## 5. Font: „Inter Variable” z pełnym stosem systemowym w zapasie

**Dlaczego:** działa, ma polskie znaki, jest wgrany lokalnie. `Atkinson
Hyperlegible` może być lepszy przy niskiej ostrości wzroku, ale to jest decyzja
do podjęcia po testach z ludźmi, nie przy okazji odświeżania wyglądu.

## 6. Jedno wejście do trybu ciemnego

Motyw wyłącznie z jawnego wyboru człowieka. Reguła `prefers-color-scheme`
zostaje usunięta i nie wraca; `color-scheme` zostaje jawnie ustawiony.

**Dlaczego:** właściciel zgłosił to jako usterkę, jest na to test
(`tests/Feature/WyborMotywuTest.php`), a powód jest realny — część osób z tej
grupy nie kojarzy, że to własne urządzenie zmieniło wygląd strony, i widzi
awarię serwisu.

## 7. Pięć pozycji dolnego paska: Start · Szukaj · Dodaj · Moje · Profil

Te same nazwy, ta sama kolejność, „Dodaj” dalej wyróżniony kolorem.

**Dlaczego:** to jest wdrożone, działa i jest zgodne z `UX_50_PLUS.md`. Nawigacja
ma być przewidywalna, nie dowcipna. Zmiana nawigacji to zmiana modelu mentalnego
u kogoś, kto już się nauczył — najdroższa rzecz, jaką można zrobić.

## 8. Ekran przepisu jako całość

Panel akcji przy zdjęciu, składniki obok kroków, sekcja „Komu wyszło”,
komentarze pod spodem, tryb gotowania na cały ekran.

**Dlaczego:** to jest najlepiej zaprojektowany ekran serwisu i on już działa.
Zmieniam w nim jedną rzecz: panel przenosi się do trzeciej kolumny i przykleja
przy przewijaniu, bo składniki potrzebne są **obok** kroków, nie nad nimi.

## 9. Trzy duże karty „Kto to widzi” zamiast listy rozwijanej

**Dlaczego:** kit v2 chciał tu listy rozwijanej i to był jeden z powodów, dla
których go odrzucono. Odbiorca 50+ nie znosi uproszczeń tam, gdzie chodzi o to,
kto zobaczy jego zdjęcie. Naprawiam tylko sklejanie etykiety z podpowiedzią.

## 10. Etykieta zawsze nad polem, nigdy podpowiedź zamiast etykiety

**Dlaczego:** to jest twarda reguła dostępności i twarda reguła tego produktu.
Nie ma wersji „ładniejszej”, w której etykieta znika.

## 11. Pusty stan jako nazwany komponent, z gotowymi tekstami

**Dlaczego:** `COPY_STYLE.md` §6 ma napisane wszystkie puste stany — „Zeszyt jest
jeszcze pusty”, „Jeszcze nikt tu nic nie napisał. Możesz być pierwsza albo
pierwszy.” Te teksty są dobre i nie ma powodu ich przepisywać.

## 12. Karuzela zdjęć jako jedyne miejsce z przewijaniem w poziomie

**Dlaczego:** świadoma decyzja, opisana, z licznikiem i sterowaniem tekstowym.
Zostaje.

## 13. Zakaz JavaScriptu i atrybutów `style=`

**Dlaczego:** to nie jest ograniczenie do obejścia, tylko rama, która wymusza
lepsze wzorce. Trzy strony kreatora są *bardziej* bezskryptowe niż jedna, a nie
mniej. Osobna strona potwierdzenia jest dla tego odbiorcy lepsza niż modal.

## 14. Zakaz liczb, których nie ma z czego policzyć

Żadnych „X obserwujących”, „popularne teraz”, „przepisów w tagu sezonowym”.

**Dlaczego:** liczba obserwujących jest w tym serwisie wzorcem zakazanym wprost,
a kit v2 narysował ją trzy razy. Publiczne rankingi dzielą ludzi na dwie klasy
i wyłączają publikowanie u większości.

## 15. Cały słownik marki i wszystkie gotowe teksty

Wszystkie napisy w makietach pochodzą z `COPY_STYLE.md` i `BRAND_EXTENDED.md`.

**Dlaczego:** to był powód nr 1 porażki poprzedniej próby. Makieta pisała
„Jak wyszło innym?”, gdy dokument mówił „Komu wyszło”; pisała „Szukaj
i odkrywaj”, gdy „odkrywaj” było na liście słów zakazanych. Za każdym razem
rozstrzygano na rzecz dokumentu — więc tym razem tekst wchodzi do makiety wprost
z dokumentu.

---

## Rzeczy, które chciałem zmienić i się wycofałem

**Nazwa „Świeżo z Kuking” na ekranie 02.** Chciałem czegoś krótszego. Ale to jest
dobra nazwa, jest wdrożona, i jest jednym z niewielu miejsc, w których marka
mówi własnym głosem. Zostaje.

**Awatar z inicjałem.** Chciałem go zastąpić kolorowym znakiem generowanym
z nazwy. Odpuściłem: inicjał jest czytelny, nie wymaga niczego nowego, a kolorowe
znaki po piętnastu w kolumnie robią dokładnie to, co dziś robi pomarańczowa
plakietka.

**Zaokrąglenie kart z 16 na 24 px wszędzie.** Zrobiłem to tylko dla karty wpisu
(największy prostokąt na ekranie, przy 16 px wygląda na kanciasty). Reszta
zostaje na 16 px, bo różnica na małych kartach jest niewidoczna, a niespójność —
widoczna.
