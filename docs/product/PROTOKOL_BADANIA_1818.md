# Badanie „Ukryj…” z osobami 50+ — protokół R1 (#1818)

Wersja 1.0, 29 września 2026. Powiązanie: [#1818](https://github.com/woogitsu/kuking.pl/issues/1818),
część wdrożenia [#1781](https://github.com/woogitsu/kuking.pl/issues/1781) (D-278, D-305),
zależność [#15](https://github.com/woogitsu/kuking.pl/issues/15).
**Materiał przygotowany; żadna sesja z człowiekiem nie została tu wykonana.**
Żaden pusty wiersz tego dokumentu nie jest dowodem przeprowadzenia badania.

Format, kody wyników (S/H/N/X/W, Q/R) i zasady notatek przejmujemy z
[protokołu #15](TESTY_Z_UZYTKOWNIKAMI.md) i [karty badania #15](KARTA_BADANIA_15.md).
Tu jest tylko to, co dotyczy ukrywania. Gdy ten plik milczy, obowiązuje
protokół #15.

## 1. Cel i zakres

Właściciel zdecydował 25.09.2026 budować od razu. Test **nie blokuje** wdrożenia:
ma sprawdzić, czy ludzie 50+ (a) znajdują „Ukryj…” w menu trzech kropek, (b) trafnie
przewidują skutek (co znika, gdzie, czy autor się dowie), (c) potrafią przywrócić
ukryte, (d) rozumieją powrót po 30 dniach. Na koniec zapada rekomendacja (§8).

Nie badamy: retencji, prawdziwego gotowania, rejestracji, ani skuteczności
doboru wpisów. Test nie zastępuje bramki #15 (13 sesji w przekroju).

## 2. Rekrutacja i skład

Zgodnie z issue:

- **4 osoby pilotażowe + 16 właściwych.** Pilotaż służy dopracowaniu
  organizacji (stany startowe, brzmienie poleceń). Wyniki pilotażu **nie wchodzą**
  do progów §6. Jeśli po pilotażu zmieni się tekst zadania lub dane, opisz to
  w karcie rundy; produktu w trakcie rundy się nie zmienia.
- **Wiek 50–59, 60–69, 70+.** Rozkład 16 osób ustala właściciel przed rekrutacją;
  propozycja: 6 / 6 / 4 (minimum 4 osoby 70+). *To propozycja, nie zapis z issue.*
- **Własne telefony** (Android i iPhone, przynajmniej po 5 osób na system;
  propozycja). **Część osób przy czcionce 200%** — naturalne ustawienie tej
  osoby albo świadomy wybór z rekrutacji; propozycja: co najmniej 5 z 16.
  Nie przestawiaj czcionki osobie, która jej nie potrzebuje, żeby „zaliczyć” 200%.
- Osoby z różnym doświadczeniem: co najmniej 4 z 16 bez doświadczenia
  w publikowaniu w internecie (propozycja).
- **Prowadzi ktoś inny niż właściciel** — a także nikt, kogo uczestnik zna
  z rekrutacji jako „twórcę Kuking”. Prowadzący nie może być autorem
  zmienianych ekranów. Właściciel nie siedzi na sesji ani nie widzi surowych notatek.
- **Bez sztucznych kont na produkcji.** Sesje nie zakładają kont testowych ani
  wpisów ćwiczeniowych w produkcyjnej społeczności (spójnie z #15).

### Decyzja właściciela przed startem: gdzie działa badany ekran

Issue wymaga własnych telefonów i zakazuje sztucznych kont na produkcji. Ukrycie
i jego przywrócenie po 24–72 h wymagają jednak konta, które trwa między dwiema
wizytami. Dwie możliwości; wybór zapisuje właściciel w karcie rundy:

| Wariant | Koszt i ryzyko |
|---|---|
| **A. Odizolowana instancja ćwiczeń** (jak w #15: osobna baza, konta ćwiczeniowe zakładane przez prowadzącego, dane wymyślone, poczta i push wyłączone) — **zalecany** | Realizm niższy; pełna powtarzalność i zero ryzyka dla prawdziwych osób. Konto uczestnika musi przetrwać 24–72 h. Uczestnik loguje się na własnym telefonie, ale na koncie ćwiczeniowym, bez podawania własnych danych |
| **B. Prawdziwe konta uczestników na produkcji** | Wymaga odrębnego planu danych i zgody. Ukrycia dotyczą prawdziwych osób w prawdziwym feedzie; „ukryj” trzeba by cofać po teście. **Nieobjęte tą instrukcją** |

Poniższe zadania zakładają wariant A.

## 3. Zgoda, prywatność, notatki

- **Bez nagrań** obrazu, dźwięku i ekranu. **Bez zdjęć twarzy** i uczestnika.
  Prowadzący nie fotografuje ekranu z danymi uczestnika.
- **Zgoda ustna, przed startem, na początku każdej sesji (obu wizyt)** — tekst w §5.
  Brak zgody = koniec, bez namawiania. Zgodę można cofnąć w każdej chwili,
  także po sesji; wtedy notatki tej osoby usuwamy.
- **Kod sesji** R1818-S01… (pilotaż: R1818-P1…). Nie ma klucza łączącego kod
  z nazwiskiem w repozytorium ani w karcie. Nie zapisujemy: nazwiska, wieku
  (tylko przedział 50–59 / 60–69 / 70+ dla przekroju), płci, głosu, adresów,
  e-maili, loginów, telefonu, prywatnych cytatów.
- Kontakt (umówienie drugiej wizyty po 24–72 h) prowadzi prowadzący **poza
  repozytorium**; dane kontaktowe nie trafiają do notatek ani do GitHuba.
- Notatki: osobna, chroniona kopia u prowadzącego; w repozytorium publikuje się
  **wyłącznie zestawienie** bez identyfikatorów. Sam kod sesji nie usuwa danych
  prywatnych z cytatu — parafrazuj albo wycinaj szczegóły.
- Zapisujemy to, co uczestnik zrobił i powiedział o serwisie. Nie oceniamy
  sprawności osoby. Przerwa lub wycofanie to nie porażka (kod W).
- Ryzyko ujawnienia danych lub dyskomfort: zatrzymaj, nie zachowuj treści.
- Retencja: surowe notatki usuwa prowadzący po zatwierdzeniu zestawienia,
  najpóźniej po 90 dniach (propozycja; rozstrzyga właściciel).

## 4. Przygotowanie (lista prowadzącego, nie uczestnika)

Wersja i dane zamrożone na całą rundę. Wpisz w [kartę rundy](#a-karta-rundy--przed-pierwszą-sesją):
SHA aplikacji, adres instancji, datę próby technicznej, `KUKING_UKRYCIA_DNI`
(domyślnie 30; badana wartość musi być wpisana), wariant A/B.

Dla każdej sesji:

1. Osobne konto ćwiczeniowe uczestnika, zweryfikowane, bez blokad. Uczestnik
   **nie obserwuje** przed sesją nikogo z autorów poniżej.
2. Autor **B** (konto ćwiczeniowe, z profilem) — **nie** obserwowany przez uczestnika,
   z wpisami, które trafiają na „Świeżo z Kuking” (Odkrywaj) uczestnika:
   - **Wpis W1**: zdjęcie i opis, z przynajmniej jednym tagiem, który uczestnik
     jeszcze nie obserwuje (cel zadania Z3). Zapisz jego nazwę i adres w karcie danych.
   - **Wpisy W2–W4** tego samego autora B i po jednym wpisie od co najmniej
     4 innych autorów ćwiczeniowych — żeby lista nie była jednowpisowa i
     ukrycie osoby miało widoczny skutek (tablica, propozycje).
3. Wpis W1 wyświetla się w tym samym stanie przy każdej sesji (ta sama treść,
   pozycja w „Świeżo z Kuking”, brak poprzednich reakcji uczestnika).
4. **Stan kolizji z moderacją (Z2c):** przygotuj osobno jeden wpis autora C
   ukryty przez moderację (napis „Ten wpis jest ukryty przez moderację.”
   widzi tylko autor — konto C dostępne dla prowadzącego, nie dla uczestnika).
   Służy wyłącznie do pytania po zadaniu; uczestnik go nie otwiera.
5. Próba techniczna prowadzącego: przejdź Z1–Z5 przez interfejs na telefonie,
   potwierdź: po ukryciu wpis znika z Odkrywania i z tablicy, autor B **nie**
   dostaje powiadomienia, linia „Ukrywasz N wpisów” pokazuje właściwą liczbę,
   „Przywróć” działa. Brak któregokolwiek = STOP przygotowania, nie wynik uczestnika.
6. Odległość wizyt: druga wizyta 24–72 h po pierwszej (zapisz faktyczne godziny).
   Między wizytami prowadzący **nie** dotyka konta ani danych uczestnika.
   Konto i ukrycie muszą przetrwać (sprawdź ręcznie przed drugą wizytą, bez
   zmian stanu).
7. Poza pierwszą i drugą wizytą nie przypominaj uczestnikowi, gdzie co jest.
   Przypomnienie o terminie wizyty nie może zawierać słów „ukryj”, „przywróć”,
   „ustawienia”.

Czas: pierwsza wizyta do 35 min (5 wstęp, do 25 zadania, 5 pytania), druga
do 20 min; potem 15 min na notatki.

## 5. Skrypt moderatora

Prowadzący czyta polecenia **dosłownie**, nie wskazuje ekranu ani przycisku.
Uczestnikowi wręczasz wydruk z jednym poleceniem naraz, min. 18 px / 14 pt.
Nie nazywaj ikon, menu ani opcji. **Słowa zabronione w ustach prowadzącego
do końca ostatniego zadania:** „ukryj”, „ukryte”, „menu”, „trzy kropki”, „kropki”,
„ustawienia”, „przywróć”, „cofnij”, „Odkrywaj”, „obserwuj”, „tag”. (Uczestnik
może ich używać sam; prowadzący je powtarza tylko cytując dosłownie polecenie
z wydruku — a wydruk ich nie zawiera.)

**Na początku pierwszej wizyty:**

> „Sprawdzamy serwis, nie Twoje umiejętności. Wszystkie konta i wpisy tutaj są
> do ćwiczenia; nie podawaj własnych danych. Nie nagrywamy i nie robimy
> zdjęć Tobie. Zapiszę tylko, co pomaga lub przeszkadza w wykonaniu zadań,
> bez nazwiska. Możesz zrobić przerwę albo skończyć w dowolnym momencie, także
> później poprosić o usunięcie moich notatek. Proszę mówić głośno, czego szukasz
> i co zamierzasz. Nie będę od razu pomagać, bo chcemy zobaczyć, gdzie serwis
> nie daje jasnej drogi. Czy zgadzasz się, żebyśmy zaczęli?”

Na początku drugiej wizyty tę samą zgodę zadaj ponownie (pierwsze zdanie i
ostatnie pytanie).

**Reakcje prowadzącego** (bez podpowiadania):

| Sytuacja | Wolno powiedzieć lub zrobić | Zapis |
|---|---|---|
| Niezrozumiały cel | Powtórz dosłownie polecenie z wydruku | Q, czas |
| Cisza | Czekaj 10 s; najwyżej raz na zadanie: „O czym teraz myślisz?” | Q |
| „Co mam kliknąć?” | „Co chcesz teraz osiągnąć?” i pozwól próbować | Q, miejsce, słowa |
| „Czy dobrze?” | „Po czym poznasz, że cel został osiągnięty?” | Q |
| Rezygnacja lub limit | „Zatrzymajmy to zadanie. Dziękuję, to nam pomaga.” | Stop próby samodzielnej |
| Zły stan po poprzednim zadaniu | Po zatrzymaniu czasu ustaw stan startowy następnego | R |
| Dyskomfort, prywatne dane | Zatrzymaj od razu; nie zapisuj treści | W |

**Pytanie o przewidywanie skutku** (Z2a/Z2b/Z2c) zadaj **przed** pokazaniem
wyniku i **przed** wykonaniem akcji, tam gdzie zadanie tak mówi. Po odpowiedzi
nie potwierdzaj ani nie prostuj (nie mów „tak”, „dokładnie”). Przewidywanie
ocenia prowadzący **po sesji** z kluczem z §6.

Każda wskazówka o drodze — także przypadkowa — to **H** i wyklucza S.
Zapisz jej dosłowną treść i czas. Po zapisaniu niepowodzenia próby samodzielnej,
jeśli osoba chce, można pomóc; wynik po pomocy osobno. Nie wydłużaj czasu po
limicie.

## 6. Zadania i progi

Zadania odpowiadają ekranom na BAZIE (D-278, D-305). Czas liczony od końca
odczytu polecenia do deklaracji końca, rezygnacji lub limitu. Limity organizacyjne.

Kolejność: **Z1 → Z2 → Z3 → Z4** (pierwsza wizyta), po 24–72 h **Z5 → Z6** (druga
wizyta). Z6 jest pytaniem, nie czynnością.

### Ekrany i etykiety w badanej wersji (klucz dla prowadzącego — uczestnik nie widzi)

Sprawdź w próbie technicznej; jeśli badane SHA ma inne napisy, wpisz je w kartę.

| Element | Etykieta / ścieżka | Skąd |
|---|---|---|
| Menu przy wpisie | przycisk z ikoną trzech kropek, opis dla czytnika „Więcej przy tym wpisie” (`<details>`, bez JS) | `resources/views/components/post-card.blade.php` |
| Ukrycie wpisu | „Ukryj ten wpis” (POST od razu, bez ekranu potwierdzenia) | jw., `posts.hide` |
| Komunikat po ukryciu | „Ukryliśmy ten wpis tylko dla Ciebie do {data}. Inni widzą go jak dotąd.” i przycisk „Cofnij” | `UkryciaController::ukryjWpis` |
| Ukrycie osoby | „Ukryj tę osobę” → ekran „Ukryj tę osobę” z „Co się stanie” i przyciskami „Ukryj tę osobę” / „Anuluj” (nie pokazywana, gdy osoba jest obserwowana — wtedy „Przestań obserwować”) | `social.hide.confirm` |
| Obserwowanie tagu z menu | „Obserwuj tag: {nazwa}”; na stronie tagu: „Obserwuj ten tag” | `post-card`, `tags/show` |
| Linia w Odkrywaniu | pod nagłówkiem „Świeżo z Kuking”: „Skąd te wpisy i jak to zmienić” oraz, przy ukryciach, „Ukrywasz N wpisów. Zmień” (albo „Ukrywasz wpisy N osób. Zmień”) | `discover.blade.php`, `linia-ukryc.blade.php` (D-305) |
| Ekran zarządzania | Konto → Ustawienia → „Ukryte” (`/ustawienia/ukryte`); sekcje „Ukryte wpisy”, „Ukryte osoby”; przyciski „Zostaw ukryte”, „Przywróć”; data: „Ukryte do {data}. Potem wróci samo.” | `pages/settings/ukryte.blade.php` |
| Strona o doborze | „Jak dobieramy wpisy” (`/jak-dobieramy-wpisy`) | D-305 |
| Ukrycie przez moderację | „Ten wpis jest ukryty przez moderację.” (widzi tylko autor) | `wpis-ukryty-przez-moderacje.blade.php` |
| Domyślny czas | 30 dni (`kuking.ukrycia.dni`) | `config/kuking.php` |

### Z1 — Pozbądź się jednego wpisu (5 minut)

**Czytaj:** „Na tej liście jest wpis ze zdjęciem zupy, którego nie chcesz oglądać.
Zrób tak, żeby przestał się pokazywać, ale żeby wpisy tej osoby jak dotąd
pozostały.”

Start: Odkrywaj (`/odkryj`), wpis W1 widoczny. Konto bez ukryć.
Sukces (**Z1**): ukryty wyłącznie wpis W1 (wpis, nie osoba); po ponownym
wejściu na listę W1 nie ma, pozostałe wpisy autora B są. Nie ukryto osoby.
Zapisz: czas do otwarcia menu, czy pierwsza próba była w menu kropek, inne drogi
(np. przewijanie, zgłoś, blokada), błędną czynność (ukryto osobę, zablokowano,
zgłoszono). **Pozycję w menu odnalazł bez pomocy** = S dla Z1. To jest pomiar
progu 1 (§6, próg A).

### Z2 — Przewidź skutek (4 minuty, pytania ustne)

Zadaj **po Z1**, na ekranie z komunikatem po ukryciu, bez czytania komunikatu na
głos. Uczestnik może czytać ekran. Pytania:

- **Z2a:** „Co się teraz stało z tym wpisem — dla kogo zniknął i gdzie go już nie
  zobaczysz?”
- **Z2b:** „Czy autor tego wpisu dowie się o tym w jakiś sposób? Jak myślisz,
  skąd?”
- **Z2c:** pokaż wpis autora C schowanego przez moderację (ekran prowadzącego,
  z zakrytą nazwą konta): „Przeczytaj napis na górze tego wpisu. Czym, Twoim
  zdaniem, różni się to od tego, co zrobiłeś przed chwilą?” Pytanie kontrolne, nie zadanie z progiem — wynik zapisz jako T2c.

Klucz oceny **Z2 = poprawne przewidzenie**: uczestnik mówi, że wpis znika
**tylko u niego** (nie „usunięty”, nie „u wszystkich”), że **autor nie dowie się**
(nie „dostanie powiadomienia/informacje”), oraz wskazuje mniej więcej **gdzie
wpisu już nie ma** (lista Świeżo z Kuking/tablica; nie musi znać wszystkich miejsc,
ale nie twierdzi, że zniknie wszędzie, np. z profilu autora czy spod adresu).
Odpowiedź „nie wiem” to N (nie S). Błędne przekonanie z §7 zapisz jako
**błąd krytyczny** niezależnie od częstości:
K1 — „ukrycie usuwa wpis dla wszystkich”;
K2 — „autor zostanie powiadomiony” albo „autor zobaczy, że go ukryłem”.

### Z3 — Zaobserwuj temat (4 minuty)

**Czytaj:** „Wpis ze zdjęciem zupy pokazuje temat, o którym chcesz częściej
czytać. Zrób tak, żeby takie wpisy pojawiały się częściej.”

Start: strona wpisu W1 lub lista, na której W1 (lub podobny wpis z tagiem docelowym)
jest widoczny. Sukces: obserwowany tag docelowy zapisany (sprawdza prowadzący po
próbie: „Obserwuj tag…” w menu, „Obserwuj ten tag” na stronie tagu, albo przez
Ustawienia → Tagi). Każda droga produktu jest poprawna. Zapisz: czy w tym
zadaniu uczestnik dotknął „Ukryj…” przez pomyłkę (pomylenie „więcej” z „mniej”)
— to daje kontrolę, czy nazwy się nie mylą. Zadanie **bez progu**; służy
odróżnieniu obserwowania od ukrywania.

### Z4 — Znajdź, co ukryłeś (koniec pierwszej wizyty; 3 minuty, tylko pytanie)

**Czytaj:** „Chcesz sprawdzić, co dotąd schowałeś przed sobą w tym serwisie. Pokaż,
gdzie to zobaczysz.” — **bez wykonywania przywrócenia**. Zapisz drogę i czas,
kod S/H/N. Zadanie **bez progu**; sprawdza, czy uczestnik kojarzy miejsce.
Zatrzymaj, gdy dotrze do ekranu (albo limit). Nie prowadź do „Przywróć”. Reset
stanu nie jest potrzebny; ukrycie z Z1 zostaje do drugiej wizyty.

### Z5 — Przywróć po 24–72 h (druga wizyta; 6 minut, dwie drogi)

Druga wizyta odbywa się 24–72 h po pierwszej, konto z ukrytym wpisem W1.
Uczestnik zaczyna na stronie startowej (`/home`).

- **Z5a — z Ustawień.** **Czytaj:** „Wczoraj zrobiłeś tak, że jeden wpis przestał Ci się pokazywać.
  Chcesz go znowu widzieć. Zrób to.” Start: `/home`, otwarta strona powitalna, bez ekranu ukryć.
  Sukces: W1 znów widać w Odkrywaniu po przywróceniu na liście „Ukryte”
  (przycisk „Przywróć”). Zapisz droga: Konto → Ustawienia → Ukryte (S), lub inna;
  oraz czy sięgnął po „Cofnij” z komunikatu (tego komunikatu już nie ma po
  odświeżeniu — zapisz, czy szukał).
- **Z5b — z linii w Odkrywaniu.** Przed Z5b prowadzący ponownie ukrywa W1
  (stan startowy R, na koncie uczestnika, poza jego wzrokiem). **Czytaj:** „Jeden wpis
  znów przestał Ci się pokazywać. Zrób tak, żeby wrócił na tę listę.” Bez
  wskazywania linii. Sukces: przejście z linii „Ukrywasz N wpisów. Zmień” do „Ukryte” i
  „Przywróć”, W1 widoczny. Ta próba pokazuje, czy druga droga jest widoczna,
  gdy uczestnik ma już doświadczenie pierwszej — **wynik Z5b opisz osobno**,
  z uwagą, że osoba zna już ekran „Ukryte” po Z5a (nie jest to niezależny test).
  Kolejność Z5a → Z5b jest stała; dla odczytu niezależnego pierwszej drogi
  właściciel może zdecydować o podziale losowym (połowa osób Z5b pierwsze).

Próg C dotyczy **Z5a**. Z5b raportuj obok.

### Z6 — Zrozum datę powrotu (3 minuty, tylko pytanie)

Na ekranie „Ukryte” (uczestnik ma otwarty ten ekran po Z5, lub prowadzący go
pokazuje na koncie z innym, wciąż ukrytym wpisem — np. drugim wpisem W2
ukrytym na życzenie prowadzącego przed sesją):

- „Jak rozumiesz napis o dacie przy tym wpisie? Co się wydarzy w tym dniu?”
- „A gdybyś chciał, żeby wpis nie wracał — co zrobisz?”

Sukces (**Z6**): uczestnik mówi, że wpis **wróci sam** w tym dniu (nie „zniknie
na zawsze”, nie „zostanie usunięty”) i wskazuje „Zostaw ukryte” lub inną drogę
do zatrzymania. Zadanie **bez progu liczbowego** z issue; służy rekomendacji
o 30 dniach (§8).

### Pytania końcowe (druga wizyta)

Po Z6, bez sugerowania: „Kto widzi, że coś ukryłeś?” oraz „Czy ukrycie kogoś
skończy się dla tej osoby czymś? Czym?” — odpowiedź potwierdza lub obala K1/K2.
Zapisz dosłownie (bez danych prywatnych).

### Progi zaliczenia — zapisane przed testem, dokładnie jak w issue #1818

Mianownik = **16 właściwych osób z ważną próbą** (kod X/W nie liczą się do
mianownika; brakującą osobę rekrutuj dodatkowo, dopóki nie będzie 16 ważnych).
Pilotaż nie liczy się.

| Próg | Zadanie | Wymagany wynik (z 16) | Kod |
|---|---|---|---|
| A | Z1 — znajduje pozycję „Ukryj…” w menu bez pomocy | **≥ 13 / 16** | S |
| B | Z2 — poprawnie przewiduje skutek | **≥ 14 / 16** | S |
| C | Z5a — przywraca bez pomocy | **≥ 14 / 16** | S |
| D | Z2 + pytania końcowe — nikt nie myśli, że ukrycie usuwa wpis dla wszystkich albo powiadamia autora | **0 osób z K1 lub K2** | — |

Wynik po pomocy (H) **nie zalicza** progu. Próg D jest bezwzględny: jedna osoba
z K1 albo K2 = próg niezaliczony, niezależnie od reszty. Progów nie zmieniamy po
poznaniu wyników. Nie zaokrąglamy (13/16 = 81,25 % — tak, 12/16 — nie).

Z3, Z4, Z5b, Z6 i Z2c nie mają progu w issue; ich wyniki służą rekomendacji
i **nie zmieniają** powyższych progów.

## 7. Czego to badanie nie dowodzi

Szesnaście osób nie daje statystyki populacji. Instancja ćwiczeń zmniejsza koszt
błędu i nie dowodzi zachowania przy prawdziwym, zatłoczonym feedzie. Powrót
po 24–72 h nie dowodzi powrotu po 30 dniach — Z6 mierzy **rozumienie napisu**,
nie to, czy człowiek pamięta o ukryciu po miesiącu. Uczestnik nie widzi
wpisów prawdziwych osób, więc nie sprawdzamy realnej chęci ukrywania.

## 8. Rekomendacja (wypełnia prowadzący z właścicielem po sesjach)

Rekomendacja rozstrzyga trzy pytania z issue. Poniższe reguły to **propozycja
decyzyjna** (nie zapis z issue); właściciel zatwierdza je przed pierwszą sesją
lub zmienia — po sesjach się ich nie zmienia.

| Pytanie | Zostaje, jeśli | Zmiana do rozważenia, jeśli |
|---|---|---|
| Nazwy „Ukryj ten wpis” / „Ukryj tę osobę” | progi A, B i D zaliczone | próg A nie zaliczony przy dobrym rozumieniu → zmiana nazwy pozycji; próg B/D nie zaliczony (K1/K2) → zmiana nazwy lub tekstu komunikatu; wnioski z cytatów, nie z jednej osoby |
| Trzy kropki + druga droga (linia w Odkrywaniu, Ustawienia → Ukryte) wystarczają | próg A i C zaliczone; Z5b bez uporczywych ślepych uliczek | próg A nie zaliczony → druga droga do „Ukryj…” przy wpisie (np. widoczny przycisk); próg C nie zaliczony → wyraźniejsza droga do „Ukryte” (opisz, gdzie szukano) |
| 30 dni jako domyślny czas | Z6: uczestnicy rozumieją, że wpis wróci sam, i znają „Zostaw ukryte” | rozumieją jako „usunięte” albo „wróci” bez zdziwienia i protestu — zapisz wszystkie zdania o czasie; zmiana dni = `kuking.ukrycia.dni` (konfiguracja) |

Kolumny „zmiana” są opcjami do dyskusji, **nie zleceniem**. Zmiana nazw wymaga
decyzji właściciela i wpisu w DECISIONS (nie w tym protokole).

---

## Formularze wyników

### A. Karta rundy — przed pierwszą sesją

- Runda / wersja protokołu: R1818 / 1.0
- SHA aplikacji / adres instancji / wariant A lub B (§2):
- Data próby technicznej prowadzącego (Z1–Z5, PASS/STOP):
- `KUKING_UKRYCIA_DNI` i inne odchylenia konfiguracji:
- Etykiety badanej wersji różniące się od tabeli w §6 (wpisz):
- Prowadzący (rola, nie nazwisko) / potwierdzenie, że to nie właściciel:
- Rozkład 16 osób: 50–59 / 60–69 / 70+; Android / iPhone; czcionka 200%:
- Pilotaż (4): liczba wykonana, co zmieniono w organizacji:
- Decyzja właściciela z §2 (A/B), data i uzasadnienie:
- Zatwierdzenie reguł §8 przez właściciela, data (przed sesjami):
- Sposób odtworzenia tego samego stanu startowego między sesjami:

Karta danych (nie pokazuj uczestnikowi): adres i tag wpisu W1; autor B; wpisy
W2–W4; wpis moderacji (autor C); wpis W2 na Z6; potwierdzenie „B nie obserwowany”,
„uczestnik bez ukryć”.

### B. Karta sesji — kopia na każdą sesję, bez klucza do tożsamości

- Kod sesji: R1818-S__ (pilotaż: R1818-P__)
- Zgoda ustna wizyta 1 / wizyta 2: tak/nie; bez nagrań i zdjęć twarzy: potwierdzone
- Przedział wieku (50–59 / 60–69 / 70+); system telefonu; przeglądarka; skala
  czcionki (100 / 150 / 200 / inna); szerokość ekranu CSS:
- Faktyczny odstęp między wizytami (godziny):
- Doświadczenie z publikowaniem w sieci (tak/nie, ogólnie):
- Odstępstwa od przygotowania i protokołu:

Kody: S samodzielnie, H po wskazówce, N nieosiągnięte, X nieważna, W niepodjęte
pozaproduktowo; Q neutralna wypowiedź, R reset.

| Zadanie | Start zgodny / R | Wynik | Czas próby s | Przerwy s | H tak/nie | Kryteria spełnione / brakujące | Uwagi (bez danych prywatnych) |
|---|---|---|---|---|---|---|---|
| Z1 ukrycie wpisu | | | | | | | |
| Z2a gdzie znika | | | | | | | |
| Z2b czy autor się dowie | | | | | | | |
| Z2c „ukryty” a moderacja (kontrolne) | | | | | | | |
| Z3 obserwuj temat | | | | | | | |
| Z4 gdzie to zobaczysz | | | | | | | |
| Z5a przywróć z Ustawień | | | | | | | |
| Z5b przywróć z linii w Odkrywaniu | | | | | | | |
| Z6 data powrotu / „Zostaw ukryte” | | | | | | | |

- Z1: pierwsza droga; czy w menu kropek; inne próby; pomyłki (ukryto osobę /
  zablokowano / zgłoszono):
- Z2: dosłowna odpowiedź o zasięgu, autorze, miejscu; K1 tak/nie; K2 tak/nie:
- Z3: droga; pomylenie z „Ukryj…” tak/nie:
- Z5a: droga, czas, „szukał Cofnij po odświeżeniu”:
- Z5b: czy widział linię „Ukrywasz…” bez pomocy; droga:
- Z6: dosłowne rozumienie daty; wskazana droga do zatrzymania:
- Pytania końcowe (dosłowne, bez danych prywatnych):
- Gdzie padło „co mam kliknąć?” (ekran i element):
- Co działało samodzielnie i należy zachować:
- Brak obserwacji (nie wpisuj PASS):
- Bloker / kwestia techniczna / pytanie produktowe / organizacja:

| Zadanie i sekunda | Działanie widoczne na ekranie | Cytat bez danych prywatnych | Q/H/R i dosłowne słowa prowadzącego | Interpretacja (hipoteza, osobno) | Wynik po pomocy |
|---|---|---|---|---|---|
| | | | | | |

### C. Zestawienie rundy — tylko sesje odbyte (16 ważnych właściwych)

| Próg | Zadanie | Wymagane (issue) | Ważne (mianownik) | S | H | N | X | W | Wynik | Zaliczony tak/nie |
|---|---|---|---|---|---|---|---|---|---|---|
| A | Z1 pozycja w menu bez pomocy | ≥ 13 / 16 | | | | | | | | |
| B | Z2 poprawne przewidzenie skutku | ≥ 14 / 16 | | | | | | | | |
| C | Z5a przywrócenie bez pomocy | ≥ 14 / 16 | | | | | | | | |
| D | K1 lub K2 (liczba osób) | 0 | | — | — | — | — | — | | |

| Zadanie bez progu | Ważne | S | H | N | X | W | Uwagi |
|---|---|---|---|---|---|---|---|
| Z2c moderacja a „ukryj” | | | | | | | |
| Z3 obserwuj temat | | | | | | | |
| Z4 gdzie to zobaczysz | | | | | | | |
| Z5b przywrócenie z linii | | | | | | | |
| Z6 data powrotu | | | | | | | |

Każda osoba liczy się raz na problem. Podaj także rozkład progu A i C według
wieku (50–59 / 60–69 / 70+) i czcionki 200 % — nie jako osobne progi, tylko
by zobaczyć, czy porażka nie skupia się w jednej grupie.

| ID problemu | Fakt / dowód sesja+zadanie+sekunda | Dotknięte / narażone | Bloker i dlaczego | Skutek | Hipoteza przyczyny | Co działało | Dalsze sprawdzenie |
|---|---|---|---|---|---|---|---|
| | | | | | | | |

### D. Rekomendacja (po zatwierdzeniu reguł §8)

| Pytanie | Wynik progów i dowody | Rekomendacja | Decyzja właściciela i data |
|---|---|---|---|
| Nazwy „Ukryj…” zostają / zmiana | | | |
| Trzy kropki + druga droga wystarczają | | | |
| 30 dni domyślnie | | | |

- Nierozwiązane blokery; wymagane ponowne sesje:
- Propozycje uzupełnienia UX_50_PLUS.md o fakty, nie interpretacje:
- Zatwierdzone zestawienie bez danych identyfikujących: opublikowane tak/nie, data:

W repozytorium publikuj dopiero zestawienie bez identyfikujących informacji.
