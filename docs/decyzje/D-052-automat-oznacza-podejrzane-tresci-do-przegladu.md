## D-052 · Automat oznacza podejrzane treści do przeglądu — trzecie źródło w `reports`, nigdy konsekwencja dla autora

**Data:** 9 września 2026 · **Prośba właściciela** · Status: **obowiązuje**

Właściciel: *„dodaj, żeby algorytm jakoś sam sprawdzał podejrzane wpisy,
zachowania, teksty itp, by szybciej wyłapać to"*.

Serwis dostaje **wykrywacz, który podnosi rękę** — trzy sygnały liczone
w kolejce po opublikowaniu wpisu albo komentarza, kończące się JEDNĄ pozycją
w kolejce moderatora z powodem napisanym po polsku. Treść zostaje widoczna,
autor niczego nie zauważa, nikomu nic się nie dzieje.

### CO TA DECYZJA REALIZUJE, A CZEGO NIE RENEGOCJUJE

To jest wykonanie poz. **3.6** (ADAPT: sygnały pasywne → oznaczenie do
przeglądu, nigdy blokada) i poz. **3.10** (automat nigdy nie decyduje sam)
z `docs/INSPIRATION_DECISIONS.md`. Nie rusza i nie osłabia:
poz. **3.14** (żadnego wyciszania po N zgłoszeniach — REJECT),
poz. **3.16** (żadnego cichego ograniczania zasięgu — REJECT, art. 17 DSA),
`AGENTS.md` §9 („flagowanie, nigdy samodzielny ban").

### TRZY SYGNAŁY, NIE SIEDEM

Wdrożone dziś: **znany wzorzec ogłoszenia** (numer telefonu do kontaktu,
„zarabiaj z domu", `t.me/`), **odnośnik zewnętrzny u świeżego konta** (konto
młodsze niż 7 dni ORAZ pierwsze trzy treści) i **powtórzona treść tego samego
konta** (≥ 92% podobieństwa, w ciągu 60 minut, przy tekście dłuższym niż
40 znaków).

Świadomie ODŁOŻONE, mimo że przy setkach kont wreszcie miałyby dane:
wiele kont z jednego IP, nagła seria wpisów, ta sama treść u RÓŻNYCH kont,
czas wypełnienia formularza. **Powód jest jeden i nazywa się falą migracyjną
z Garnek.pl:** grupa osób 50+ przechodzi do nas razem, rejestruje się w tym
samym tygodniu, część z jednego łącza (koło gospodyń, biblioteka, dom
seniora), i od razu przenosi archiwum — dziesiątki przepisów w godzinę,
wklejanych z notatnika, czasem tych samych u kilku osób, bo krążyły w tej
grupie latami. Każdy z tych czterech sygnałów opisuje dokładnie to zachowanie.
Wykrywacz, który by je złapał, oznaczyłby w pierwszym dniu dokładnie te osoby,
dla których ten serwis powstał.

**Wzrost skali NIE odblokował więc sygnałów „na dużą skalę".** Odblokował
dane, ale ruch, który je wnosi, jest ruchem, na którym te sygnały się mylą.
Odblokował za to pracę nad KOLEJKĄ — i to jest część, w której skala zmieniła
projekt naprawdę.

### DLACZEGO `reports` Z NOWYM `source`, A NIE DRUGA TABELA

Bo koniec drogi jest ten sam: decyzja moderatora, wiersz
w `moderation_actions`, ścieżka odwołania z DSA art. 17, wspólna retencja
(`kuking:sprzataj-sprawy-moderacyjne`). Druga tabela znaczyłaby drugą kolejkę,
drugi ekran i drugą okazję, żeby jedna z nich została z tyłu — ten sam
argument, którym `docs/DATABASE.md` uzasadnia trzymanie drogi społecznościowej
i prawnej razem.

`source = 'automat'` różni się od tamtych dwóch trzema rzeczami:
nie ma zgłaszającego (więc nie uruchamia obowiązków z art. 16 ust. 4 i 5 —
nikt nic nie zgłosił), MUSI mieć cel, i **powstaje najwyżej raz na treść**.

### JEDNO OZNACZENIE NA TREŚĆ, NA ZAWSZE

Indeks częściowy `reports_jeden_automat_na_tresc` obejmuje WSZYSTKIE statusy,
także `rejected`. To jest obietnica złożona moderatorowi: „to nic takiego"
zamyka sprawę i automat już z tym nie wraca. Discourse rozwiązał to tym samym
warunkiem (`docs/research/repos/discourse-discourse.md` §4.5: reguła nie
flaguje ponownie, jeśli wcześniejsze zgłoszenie zostało odrzucone) —
bez tego automat kłóci się z człowiekiem w kółko.

Cena jest nazwana wprost: wpis opublikowany niewinnie i poprawiony edycją nie
jest analizowany drugi raz. Ta luka jest opisana
w `docs/legal/SYGNALY_AUTOMATU.md` §4 i zamykana zgłoszeniem od człowieka.
Dla komentarzy lukę zamyka D-256 (#909) — bez naruszania tej obietnicy.

### OSOBNY EKRAN, BO TO JEST INNA PRACA

`/admin/sygnaly` — grupowane po autorze, uszeregowane od najcięższego sygnału,
z jednym przyciskiem zamykającym całą grupę. Oznaczenia automatu **nie
wchodzą** do `/admin/zgloszenia`: tam czekają ludzie i biegną terminy z DSA
art. 16 ust. 5, a maszynowe podejrzenia zasypałyby tamtą listę przy pierwszej
fali nowych kont. Pełny formularz decyzji jest jeden, na ekranie zgłoszeń
(`?zrodlo=automat`) — druga jego kopia rozjechałaby się z oryginałem przy
pierwszej zmianie w pouczeniu z art. 17.

### CO Z TEGO WYNIKA DLA DOKUMENTÓW UŻYTKOWNIKA

`resources/legal/zasady.md` punkt 12 mówił „reagujemy na zgłoszenia, nie
inwigilujemy — sprawdzamy to, co ktoś zgłosił". Od tej decyzji to nie była
już prawda, więc punkt został przepisany: mówi, że narzędzie istnieje, co
wychwytuje i **że niczego samo nie ukrywa, nie usuwa ani nie ogranicza**
(DSA art. 14 ust. 1 wymaga opisania narzędzi automatycznych).

`UzasadnienieDecyzji::skadSprawa()` dostało trzecią gałąź. Bez niej autor
treści wskazanej przez automat przeczytałby „sprawa zaczęła się od zgłoszenia,
które dostaliśmy od innej osoby" — nieprawdę każącą mu szukać wśród znajomych
kogoś, kto go zgłosił, choć nikt tego nie zrobił (art. 17 ust. 3 lit. b i c).
Zdanie „nie mamy w Kuking automatu, który sam ukrywa, usuwa albo blokuje"
zostaje prawdą i po tej zmianie.

### POMIAR — BO INACZEJ PO MIESIĄCU NIKT NIE BĘDZIE WIEDZIAŁ

`php artisan kuking:raport-sygnalow --dni=30`: ile pozycji dziennie i jaki
odsetek okazał się niczym, w rozbiciu na sygnały. Progi reakcji (70% fałszywych
alarmów, 30 pozycji dziennie, pozycje starsze niż tydzień) —
`docs/legal/SYGNALY_AUTOMATU.md` §6. Tam też stoi §7: przy jakiej skali to
podejście się kończy i na co je wtedy zamienić.

### WYCOFANIE

1. **Wyłączenie bez wdrożenia:** `KUKING_SYGNALY_AUTOMATU=false`. Zadanie
   w kolejce kończy się na pierwszej linijce, nowe oznaczenia nie powstają,
   istniejące zostają do rozpatrzenia.
2. **Wycofanie kodu:** rewert commita.
3. **Wycofanie schematu:** `migrate:rollback` tej jednej migracji. `down()`
   **odmawia**, gdy w bazie są oznaczenia już ROZSTRZYGNIĘTE — niosą powód,
   dla którego moderator coś zrobił, i są dokumentem przy odwołaniu.
   Świadome wymuszenie (najpierw kopia tabeli):
   `KUKING_ROLLBACK_KASUJE_SYGNALY_AUTOMATU=1`.

**Zmiana wymaga:** pomiaru z komendy wyżej, nie wrażenia. Dołożenie sygnału
z listy odłożonych wymaga pokazania, że fala migracyjna już go nie zapala —
odwrotna intuicja nie wystarcza.

📄 `app/Domain/Moderation/Sygnaly/WykrywaczSygnalow.php` ·
`app/Domain/Moderation/Sygnaly/Sygnal.php` ·
`app/Domain/Moderation/Actions/OznaczDoPrzegladu.php` ·
`app/Jobs/PrzeanalizujTresc.php` ·
`app/Http/Controllers/Admin/SygnalyController.php` ·
`app/Console/Commands/RaportSygnalow.php` ·
`resources/views/pages/admin/sygnaly.blade.php` ·
`database/migrations/2026_09_09_400000_sygnaly_automatu_w_zgloszeniach.php` ·
`config/kuking.php` (`moderation.sygnaly`) ·
`resources/legal/zasady.md` (punkt 12) ·
`tests/Feature/SygnalyAutomatuTest.php` ·
`tests/Feature/CofniecieMigracjiSygnalowAutomatuTest.php` ·
`docs/legal/SYGNALY_AUTOMATU.md` · `docs/DATABASE.md` · `docs/MODERATION.md` ·
`docs/INSPIRATION_DECISIONS.md` poz. 3.6 i 3.10
