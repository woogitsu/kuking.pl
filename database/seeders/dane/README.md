# Dane seedowane z plików

W tym katalogu leżą dane, których seedery NIE trzymają w kodzie PHP:
słownik tagów (`slownik-tagow.json` + `slownik-tagow-uzupelnienia.json`,
czyta je `TagSeeder`) i treść zalążkowa (`tresc-zalazkowa.json`).

---

# Słownik tagów — `slownik-tagow.json`

**1250 nazw kanonicznych i 2366 aliasów. To jest baza produkcyjna, nie
przykład.** Ułożona pod polską kuchnię domową i pod grupę 50+ — dwie
kategorie istnieją tylko z tego powodu: `pamiec` („przepis po babci",
„z rodzinnego zeszytu", „ze starej książki") i `okolicznosci` („dla wnuków",
„z czerstwego chleba", „mało zmywania", „dla niejadka").

## Kształt pliku

```json
{ "wersja": "1.0",
  "tagi": [ { "nazwa": "zupa", "kategoria": "potrawy",
              "aliasy": ["zupy", "zupka"], "sezonowy": "lato" } ],
  "uwagi": ["…"] }
```

Trzynaście dozwolonych kategorii: `potrawy`, `wypieki`, `skladniki`,
`przygotowanie`, `przetwory`, `okazje`, `sezon`, `regiony`,
`kuchnie-swiata`, `diety`, `okolicznosci`, `sprzet`, `pamiec`. Trafiają do
`tags.internal_category` — kolumny TECHNICZNEJ, nigdy nie pokazywanej
użytkownikowi (SPEC §1.3).

## Pole `uwagi` — nie kasować przy aktualizacji

44 świadome rozstrzygnięcia autora słownika, część z odsyłaczami do WSJP PAN
i do Listy Produktów Tradycyjnych MRiRW. To jest JEDYNE miejsce, w którym
zapisano, dlaczego „żur" i „żurek" NIE są aliasami, dlaczego „pyzy" nie
prowadzą do „klusek na parze", dlaczego „botwinka" nie ma jednego celu
(może znaczyć surowiec albo zupę) i dlaczego tagi typu „bez glutenu" opisują
DEKLARACJĘ autora wpisu, a nie wynik badania. Bez tego pola kolejna osoba
zada te same pytania i najprawdopodobniej odpowie inaczej.

## Pole `sezonowy` — świadomie nieużywane

226 tagów ma podpowiedź sezonu („lato", „czerwiec-lipiec", „Wielkanoc").
Nic w kodzie tego dziś nie czyta i **nie dorabialiśmy do tego kolumny** —
funkcja sezonowości nie istnieje, a kolumna bez drogi zapisu i odczytu to
dokładnie ten błąd, który opisuje zadanie o minutniku kroku. Informacja
zostaje w pliku, żeby była, gdy taka funkcja powstanie.

## Sprawdzane maszynowo, przy każdym przebiegu testów

`tests/Feature/SlownikTagowTest.php` czyta DOKŁADNIE te pliki i pilnuje:
brak powtórzonych nazw kanonicznych, żaden alias nie jest nazwą kanoniczną
innego tagu, żaden alias nie należy do dwóch tagów, nazwy i aliasy 2–30
znaków (tyle mają kolumny), slug mieści się w `tags.slug` i pasuje do
CHECK-a, każda kategoria z listy trzynastu, brak nazw marek i języka
dietetycznego z obietnicą skutku, transliteracja nazwy nie wchodzi na cudzą
nazwę kanoniczną.

**Kolizja aliasu z nazwą kanoniczną to najczęstszy błąd w takich listach
i nie widać go okiem** — w pierwszej wersji pliku uzupełnień było ich pięć
i wszystkie znalazł dopiero ten pomiar.

---

# Uzupełnienia — `slownik-tagow-uzupelnienia.json`

159 nazw z poprzedniej bazy redakcyjnej (651 nazw wpisanych na sztywno
w `TagSeeder`), zawężonych do pojęć, których duży słownik nie ma ANI jako
nazwy kanonicznej, ANI jako aliasu: podstawowe składniki („kapusta",
„seler", „fasola", „olej", „orzechy"), części mięsa („polędwica",
„karkówka wieprzowa", „ozorki"), klasyki bez odpowiednika („zrazy",
„tatar", „sękacz", „krówki", „ptasie mleczko").

**Czego z tamtej listy świadomie NIE przeniesiono** — i to jest ważniejsze
niż to, co przeniesiono:

- nazw angielskich i modnych: „cookies", „cupcakes", „smoothie bowl",
  „chia pudding", „pavlova", „tiramisu";
- fraz zamiast pojęć: „obiad w piętnaście minut", „deser dla dzieci",
  „śniadanie na słodko";
- nazw urządzeń w formie rzeczownika, gdy słownik ma tę samą rzecz w swojej
  formie: „piekarnik" → `z piekarnika`, „blender" → `miksowane`,
  „wolnowar" → `w wolnowarze`;
- **nazwy marki**: „termomix" — słownik ma potoczne `w termomiksie` małą
  literą i to jest cała różnica;
- **tagów „fit", „dieta odchudzająca" i „dieta sportowca"** — łamią tę samą
  regułę o języku dietetycznym, jaką postawiono słownikowi. Poprzednia baza
  je miała; ta ich nie ma i test tego pilnuje.

Rozdzielenie na dwa pliki jest celowe: kolejna wersja słownika podmienia
JEDEN plik, bez scalania cudzych zmian w środku listy.

## Co się dzieje ze starym tagiem, którego słownik nie zna

43 nazwy z poprzedniej bazy nowy słownik traktuje jako alias czegoś innego
(„marchewka" → „marchew", „schabowy" → „kotlet schabowy", „pieczenie" →
„pieczone"). Seeder je **scala** (`MergeTags`, SPEC §1.8) — ale tylko gdy
stary tag jest pusty i redakcyjny: `is_seeded`, `active`, bez wpisów, bez
obserwujących, bez promocji. Tag, którego ktoś już użył albo który powstał
z ręki człowieka, zostaje nietknięty; alias jest wtedy odrzucany i zgłaszany
w raporcie, a decyzja zostaje przy człowieku. Pełne uzasadnienie: D-026
w `docs/DECISIONS.md`, pomiar: `tests/Feature/TagSeederZeSlownikaTest.php`.

---

# Treść zalążkowa — `tresc-zalazkowa.json`

**To jest treść PRZYKŁADOWA, wygenerowana. Dwanaście kont w tym pliku to
persony, nie ludzie.**

Powstała 7 września 2026 na potrzeby problemu opisanego przez właściciela:
serwis działa, ale nie jest promowany i nikt z niego nie korzysta, więc osoba
zaproszona jako jedna z pierwszych dwudziestu widzi pusty ekran.

## Co zawiera

12 kont, 40 przepisów, 80 wpisów, 60 komentarzy, 10 tagów promowanych.
40 unikalnych tagów, z których każdy promowany ma pokrycie w treści.
Komentarze wskazują pozycje przez `ref` (`p1`–`p40`, `w1`–`w80`).

## Zweryfikowane maszynowo

Zero adresów e-mail, numerów telefonu, adresów pocztowych, emotikon,
wykrzykników i obietnic zdrowotnych. Nazwy kont zgodne z regułą
`[a-z0-9_]{2,20}`. Tagi: małe litery, 2–30 znaków, polskie znaki zachowane,
spacje dozwolone (patrz reguły tagów — `zupa pomidorowa` jest poprawnym tagiem).
Integralność odwołań pełna: żaden komentarz nie wskazuje na nieistniejącą
pozycję, żaden autor nie jest spoza listy kont.

## DWIE RZECZY, KTÓRYCH TEN PLIK NIE ROZWIĄZUJE

**1. Nie ma zdjęć, a „zdjęcie + kilka słów" to główna akcja produktu.**
Istniejący `DemoSeeder` tworzy wiersze `media` bez prawdziwych plików
i bez wariantów, więc `Media::url()` podstawia znak Kuking. Do pomiaru
układu to wystarcza i tak jest to opisane w komentarzu tamtej klasy. Do
pokazania serwisu człowiekowi — nie: osiemdziesiąt wpisów z logotypem
zamiast jedzenia wygląda gorzej niż osiemdziesiąt wpisów bez zdjęć.

**2. Użycie tego na produkcji jest decyzją o uczciwości, nie techniczną.**
Jako dane lokalne do pracy nad wyglądem: bez zastrzeżeń. Jako zawartość
produkcyjnego serwisu pokazywana zaproszonym ludziom: dwanaście
zmyślonych osób podpisanych imieniem i regionem, pisanych w pierwszej
osobie, jest nieodróżnialne od prawdziwych użytkowników. Zanim to trafi
na produkcję, musi być albo widocznie oznaczone jako treść przykładowa,
albo opublikowane pod kontem gospodarza jako zebrane przepisy, albo nie
trafić tam wcale. Decyzja właściciela — nie do podjęcia przez agenta.

## Co jeszcze nie istnieje

Seeder czytający ten plik. Powstanie razem z tagami (decyzja D-021),
bo bez tabel tagów nie da się zapisać ani tagów wpisów, ani listy tagów
promowanych.
