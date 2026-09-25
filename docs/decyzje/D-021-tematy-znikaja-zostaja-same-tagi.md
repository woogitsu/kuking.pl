## D-021 · Tematy znikają, zostają same tagi

**Data:** 7 września 2026 · **Decyzja właściciela** · Status: **obowiązuje** ·
zastępuje mechanizm z issue #31 (`topics`, `topic_follows`, `posts.topic_id`)

Właściciel: „Tematy usuwamy, tylko tagi."

Zamknięty słownik redakcyjny (`Topic`) ustępuje otwartym tagom użytkowników.
Jeden mechanizm klasyfikacji treści zamiast dwóch — bo dwa znaczyłyby, że
osoba 50+ musi zrozumieć, czym „temat" różni się od „tagu", a to jest
pytanie, na które sam produkt nie ma dobrej odpowiedzi.

### Czego ta decyzja NIE rozstrzyga, a co trzeba rozstrzygnąć

**Tematy powstały dzień przed tą decyzją i powstały po coś.** Migracja
`2026_09_06_100000_create_topics_tables.php`, commity `eb235fc` (#31 część A)
i `ce6f48d` — „Nowe konto przestaje widzieć pusty ekran" (#31 część B). Nie
jest to stary dług, tylko świeża odpowiedź na udokumentowany problem
cold-startu.

Na tematach stoi w `docs/product/COLD_START.md` cały plan startu, nie tylko
pierwszy feed:

- **temat tygodnia** ogłaszany przez gospodarza,
- **ambasadorzy tematów** — 8–12 osób z osobistym zaproszeniem „prowadź temat
  »chleb i zakwas«", z widoczną rolą i zadaniem trzech komentarzy dziennie,
- przygotowane tematy na **Wigilię, tłusty czwartek i Wielkanoc**, planowane
  trzy tygodnie wcześniej.

Otwarte tagi nie unoszą żadnej z tych trzech rzeczy: nie da się powierzyć
komuś prowadzenia tagu, który każdy może utworzyć, ani zagwarantować, że nowe
konto trafi tydzień przed Wigilią na coś sensownego.

**POTWIERDZONE PRZEZ WŁAŚCICIELA 7 września 2026:** rolę redakcyjną przejmuje
**wąska lista tagów promowanych**, prowadzona przez gospodarza — te same trzy
funkcje (temat tygodnia, ambasador, tag sezonowy) realizowane na tagach, bez
drugiego typu obiektu w interfejsie. To nie jest powrót Tematów: promowany tag
jest zwykłym tagiem, który dodatkowo stoi na liście gospodarza, z kolejnością
i opcjonalnym jednym zdaniem od niego. `docs/product/COLD_START.md` wymaga
aktualizacji pod tym kątem.

**Co odrzucono i dlaczego.** Rozważane były trzy inne warianty.
*Lista po popularności* — najprostsza, ale przy zerowym ruchu popularność nie
istnieje, więc nowe konto zobaczyłoby pustą albo losową listę, czyli dokładnie
problem, który Tematy rozwiązywały. *Wykorzystanie istniejących mechanizmów
redakcyjnych* — projekt ma już tablicę dnia („kuKINGi na dziś": do 6 wpisów
i 6 osób z notatką) oraz publiczne zeszyty, i one pokrywają „co gospodarz dziś
pokazuje" oraz „zestaw, który gospodarz złożył". Nie pokrywają jednego:
NAZWANEJ RZECZY, DO KTÓREJ SPOŁECZNOŚĆ SAMA DOSYPUJE TREŚĆ — zeszyt składa
gospodarz, tag rośnie od użytkowników, a „temat tygodnia" ma z definicji
rosnąć. *Odłożenie decyzji* — odrzucone, bo rdzeń tagów był budowany w tej
chwili, a dodanie promocji później oznaczałoby przebudowę onboardingu, strony
tagu, strony głównej i panelu.

**Opiekun tagu (ambasador) NIE jest jeszcze zbudowany.** Zatwierdzona została
sama możliwość promowania tagu. Przypisanie konkretnej osoby do prowadzenia
tagu to osobny krok.

### Stan danych w chwili decyzji

W lokalnej bazie deweloperskiej: **0 tematów, 0 wpisów z tematem, 0
obserwacji tematów**. Stanu produkcji nie da się sprawdzić z kontenera
roboczego — właściciel musi to zrobić przed migracją, bo od tego zależy, czy
usunięcie tematów jest zmianą schematu, czy rozmową z ludźmi, którym coś
zniknie z profilu.

### Co konkretnie znika

`topics`, `topic_follows`, `posts.topic_id`, `TopicFeed`, `TopicController`,
trasy `topics.*`, strona tematu, wybór tematów w onboardingu i wpisy tematów
w mapie strony. Każde z tych miejsc jest dziś pokryte testami — po usunięciu
testy mają zniknąć razem z kodem, a nie zostać wyciszone.

### Poprawka techniczna, która wychodzi razem z tagami

Normalizacja nazwy tagu do UNIKALNOŚCI nie może używać `unaccent` — inaczej
`zurek` i `żurek` stają się jednym tagiem, a to są dwie różne rzeczy.
Istniejąca funkcja `kuking_normalize()` (`pg_trgm` + `unaccent`, migracja
`2026_09_05_001300_fix_search_indexes.php`) służy do SZUKANIA i PODPOWIADANIA,
nie do rozstrzygania tożsamości tagu.
