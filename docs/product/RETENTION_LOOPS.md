# RETENTION_LOOPS.md — pętle powrotu, powiadomienia, digest, metryki

> Rozwija `docs/PRODUCT.md` (North Star = Weekly Active Cooks) i `docs/SEO_ANALYTICS_GROWTH.md`. Zakłada mechaniki z `SOUL.md` i operacje z `COLD_START.md`.

## 1. Zasada: powrót ma mieć powód, nie przypomnienie

Trzy rodzaje powodów powrotu, w kolejności siły:

| Siła | Powód powrotu | Przykład |
|---|---|---|
| 🔥🔥🔥 | **Ktoś zwrócił się do mnie** | „Marek ugotował z Twojego przepisu”, „Basia odpowiedziała na Twoje pytanie” |
| 🔥🔥 | **Mam coś do zrobienia** | temat tygodnia, sezon („kisisz w tym tygodniu?”), przepis zapisany na sobotę |
| 🔥 | **Ciekawość** | co nowego u obserwowanych |

Produkt bez pierwszej kategorii nie ma retencji — ma tylko ruch. Dlatego priorytet operacyjny (obietnica odzewu z `COLD_START.md`) jest ważniejszy niż jakakolwiek funkcja z tego dokumentu.

---

## 2. Katalog pętli

Format: **trigger → akcja → nagroda → inwestycja**. „Inwestycja” = to, co użytkownik zostawia w produkcie, dzięki czemu następny obrót pętli jest silniejszy.

### Pętla 1 — Ugotowałem → wzruszenie autora → odpowiedź → kolejne wykonanie ⭐ główna

| | |
|---|---|
| **Trigger** | Powiadomienie: „**Marek ugotował Twoje pierogi z kaszą**” + miniatura jego zdjęcia |
| **Akcja** | Autor otwiera pełnoekranowy ekran „Komuś wyszło”, patrzy na zdjęcie, odpowiada Markowi |
| **Nagroda** | Autor: dowód, że jego przepis żyje w cudzym domu. Marek: odpowiedź od **autorki przepisu**, wyróżniona plakietką |
| **Inwestycja** | Marek zaczyna obserwować autorkę → jej kolejny przepis wpada mu do feedu. Autorka dopisuje uwagę do przepisu („Marek dodawał chrzan — dobra myśl”) → przepis mądrzeje |
| **Koszt / kiedy** | M / **MVP** |
| **Miara** | `cooked → odpowiedź autora w 24 h` (cel ≥70%), `2. wykonanie tego samego autora w 30 dni` (cel ≥30%) |
| **Jak się psuje** | Autor nie odpowiada → Marek nie dostaje nic → nie ugotuje drugi raz. **Naprawa: gospodarz odpowiada za milczącego autora** („Basia jeszcze nie zajrzała, ale ja Ci powiem — te pierogi wyglądają lepiej niż moje”) |

### Pętla 2 — Zdjęcie dnia → odzew → nawyk ⭐ główna

| | |
|---|---|
| **Trigger** | Pytanie dnia na `/home`: „Co dziś ugotowałaś, Basiu?” (wariant zależny od pory dnia/sezonu) |
| **Akcja** | Zdjęcie z galerii + kilka słów + `Opublikuj` (<60 s) |
| **Nagroda** | Komentarz z konkretem w 2 h + „Ładne!” od kilku osób + potwierdzenie z datą („Twoje danie jest w Kuking — 4 listopada 2026”) |
| **Inwestycja** | Wpis wchodzi do archiwum profilu → rośnie własna kolekcja życia, której nie chce się porzucić |
| **Koszt / kiedy** | S produktowo / **L operacyjnie** (obietnica odzewu) / **MVP** |
| **Miara** | `% wpisów z ≥1 odpowiedzią` (cel 100% do 200 użytkowników, ≥70% później), `mediana czasu do 1. odpowiedzi` (≤3 h) |
| **Jak się psuje** | Wpis bez odpowiedzi. Jeden taki przypadek u nowej osoby = duża szansa, że nie wróci. **Panel „wpisy bez odpowiedzi”** to najważniejszy ekran admina |

### Pętla 3 — Zapisuję → przypomnienie w weekend → wykonanie

| | |
|---|---|
| **Trigger** | Sobota rano, jeden komunikat: „Zapisałaś 3 przepisy. Sernik Marka robi się 1,5 godziny — dziś jest czas?” |
| **Akcja** | Otwarcie zapisanego przepisu, ugotowanie, `Ugotowałem` |
| **Nagroda** | Poczucie zamkniętej pętli („zapisałam i naprawdę zrobiłam”) + odpowiedź autora (wchodzi Pętla 1) |
| **Inwestycja** | Kolekcje rosną i stają się osobistym narzędziem („Na niedzielę”, „Ciasta mamy”) |
| **Koszt / kiedy** | S / **MVP** (jako jedna sobotnia wiadomość, bez plannera) |
| **Miara** | `save → cooked w 30 dni` (cel ≥15%) |
| **Jak się psuje** | Zapisane przepisy zamieniają się w cmentarz zakładek. **Naprawa: maks. 1 przypomnienie tygodniowo, zawsze o jednym konkretnym przepisie, z czasem przygotowania** |

### Pętla 4 — Temat tygodnia → wspólne zajęcie → widoczność w kolekcji

| | |
|---|---|
| **Trigger** | Poniedziałek: „Temat tygodnia: **Twoje pierogi**. Pokaż swoje.” (na `/home` + w digeście) |
| **Akcja** | Wpis oznaczony tematem |
| **Nagroda** | Widzę 20 innych osób robiących to samo w tym samym tygodniu + szansa na piątkową kolekcję („Wasze pierogi — 23 dania od 14 osób”) |
| **Inwestycja** | Uczestnictwo w rytmie tygodnia; tematy budują historię wspólnoty („pamiętasz tydzień pierogów?”) |
| **Koszt / kiedy** | S / **MVP** |
| **Miara** | `wpisy w temacie / WAC` (cel ≥25%), `osoby uczestniczące w ≥3 tematach z rzędu` |
| **Jak się psuje** | Temat z 2 wpisami zawstydza uczestników. **Naprawa: gospodarz publikuje pierwszy; jeśli do środy <5 wpisów, prosi osobiście 5 osób** |

### Pętla 5 — Sezon → potrzeba teraz → przepis → wykonanie

| | |
|---|---|
| **Trigger** | Sezon: „Teraz sezon na śliwki” (Discover) albo z własnego archiwum: „W zeszłym roku kisiłaś w trzecim tygodniu października” |
| **Akcja** | Szukanie przepisu → gotowanie → `Ugotowałem` albo własny wpis |
| **Nagroda** | Rozwiązany realny problem (mam 5 kg śliwek) + wykonanie trafia do sezonowej kolekcji |
| **Inwestycja** | Powstaje treść sezonowa, która za rok będzie triggerem dla kogoś innego → **pętla roczna, najtrwalsza w produkcie** |
| **Koszt / kiedy** | S (kalendarz jako dane) / **MVP**; wariant z archiwum: M / V1 |
| **Miara** | `wykonania przepisów sezonowych / tydzień`, `powroty rok do roku w tym samym tygodniu sezonowym` (mierzalne od 2. roku) |
| **Jak się psuje** | Sezon staje się banerem, który się ignoruje. **Naprawa: sezon zmienia teksty (pytanie dnia, temat, digest), nigdy nie jest pop-upem** |

### Pętla 6 — Rodzinny przepis → wzruszenie rodziny → zaproszenie → nowa osoba

| | |
|---|---|
| **Trigger** | Ktoś wypełnia „po kim ten przepis” i wstawia skan zeszytu |
| **Akcja** | Pokazuje to rodzinie (telefon przy stole, link do siostry) |
| **Nagroda** | Reakcja rodziny — najsilniejsza emocja dostępna w tym produkcie. Nie potrzebuje żadnej mechaniki po naszej stronie |
| **Inwestycja** | Siostra zakłada konto i dopisuje swoją wersję → rodzinna książka rośnie → nikt tego nie porzuci, bo to pamięć po zmarłej osobie |
| **Koszt / kiedy** | S (pola) / **MVP**; rodzinna książka i współautorzy: M / V1 |
| **Miara** | `% przepisów z wypełnionym „po kim”` (cel ≥35%), `zaproszenia z linku przepisu rodzinnego`, `konta z ≥2 osobami z jednej rodziny` |
| **Jak się psuje** | Pole wygląda urzędowo i zostaje puste. **Naprawa: gospodarz wypełnia je we wszystkich swoich przepisach; temat tygodnia T4 „Przepis po mamie” i T12 „Jak robiła babcia”** |

### Pętla 7 — Archiwum → nostalgia → powtórne gotowanie

| | |
|---|---|
| **Trigger** | „Rok temu gotowałaś powidła. Znowu sezon.” (maks. 1× w tygodniu, na `/home`, nie mailem) |
| **Akcja** | Otwarcie starego wpisu → ponowne ugotowanie → nowy wpis |
| **Nagroda** | Poczucie ciągłości własnego życia + porównanie („teraz wychodzą lepsze”) |
| **Inwestycja** | Drugi rok tej samej potrawy → „gotujesz to od 2026, 11 razy” → archiwum staje się cenniejsze |
| **Koszt / kiedy** | M / V1 (archiwum po miesiącach: MVP) |
| **Miara** | `CTR kafla wspomnienia`, `powtórne wpisy tej samej potrawy`, **`% ukrytych wspomnień`** (jeśli >10%, mechanika jest zbyt nachalna) |
| **Jak się psuje** | Wspomnienie boli (osoba zmarła, trudny okres). **Obowiązkowo `Ukryj to wspomnienie` + globalny wyłącznik w `/settings/privacy`. Nigdy nie przypominamy cudzych treści.** |

### Pętla 8 — Pytanie do autora → autorytet → więcej publikacji

| | |
|---|---|
| **Trigger** | „**Anna ma pytanie do Twojego przepisu**” (wyróżnione powiadomienie, bo wymaga odpowiedzi) |
| **Akcja** | Autor odpowiada |
| **Nagroda** | Poczucie bycia kimś, kto się zna — u osoby, której nikt o nic nie pytał od czasu emerytury. Bardzo silne u 50+ |
| **Inwestycja** | Odpowiedź zostaje przy przepisie i pomaga następnym → autor publikuje więcej, bo widzi, że jego wiedza jest potrzebna |
| **Koszt / kiedy** | S (MVP: zwykły komentarz) / M (osobny typ „Pytania”: V1) |
| **Miara** | `% pytań z odpowiedzią w 48 h` (cel ≥80%), `publikacje autora po pierwszym pytaniu` |
| **Jak się psuje** | Pytania bez odpowiedzi wiszą publicznie i wstydzą autora. **Naprawa: nie pokazujemy publicznie „bez odpowiedzi od 30 dni”; gospodarz podbija pytanie prywatnie** |

### Pętla 9 — Digest tygodniowy → jedna ciekawa rzecz → wejście → wpis

| | |
|---|---|
| **Trigger** | E-mail w piątek 17:00 (szczegóły w rozdz. 4) |
| **Akcja** | Klik w jedną konkretną rzecz (ktoś ugotował z jej przepisu / temat tygodnia / kolekcja) |
| **Nagroda** | Wejście na coś, co dotyczy jej osobiście, a nie na listę „poleconych treści” |
| **Inwestycja** | Weekend to główny czas gotowania → wpis w sobotę/niedzielę |
| **Koszt / kiedy** | M / **MVP** (digest jest jedynym kanałem, który dociera do osób nieodwiedzających serwisu codziennie) |
| **Miara** | `open rate` (cel ≥35% w małej społeczności), `CTR ≥8%`, **`wypisy <0,5%`** |
| **Jak się psuje** | Digest wygląda jak newsletter marketingowy → wypisy. **Naprawa: nadawcą jest gospodarz z imieniem, treść osobista, maks. 1 mail w tygodniu** |

### Pętla 10 — Ambasador tematu → status → dbanie o odzew (od ~200 użytkowników)

| | |
|---|---|
| **Trigger** | Osobiste zaproszenie od gospodarza: „prowadź temat »chleb i zakwas«” |
| **Akcja** | 3 komentarze dziennie pod nowymi wpisami w temacie |
| **Nagroda** | Widoczna rola („prowadzi temat”), wpływ na kolekcje, bezpośredni kontakt z redakcją — status bez rankingu |
| **Inwestycja** | Ambasador buduje „swoje” miejsce i przejmuje obietnicę odzewu → produkt przestaje zależeć od 2 osób |
| **Koszt / kiedy** | S produktowo (plakietka + panel „wpisy bez odpowiedzi” w temacie) / V1 |
| **Miara** | `% odpowiedzi nie od redakcji` (Bramka B: ≥60%), `ambasadorzy aktywni ≥4 tygodnie` |
| **Jak się psuje** | Wypalenie i poczucie darmowej pracy. **Naprawa: maks. 1 temat na osobę, jawny zakres (3 komentarze/dzień), możliwość odejścia bez tłumaczenia, kontakt z gospodarzem raz w miesiącu** |

---

## 3. Powiadomienia — miłe, nie nachalne

### 3.1 Katalog i priorytety

| Zdarzenie | Kanał | Natychmiast? | Treść (przykład) |
|---|---|---|---|
| Ktoś ugotował z mojego przepisu | in-app + e-mail | **tak** | „Marek ugotował Twoje pierogi z kaszą. Zobacz, jak mu wyszły.” |
| Odpowiedź na mój komentarz / pytanie | in-app + e-mail | tak | „Basia odpowiedziała Ci pod przepisem na żurek.” |
| Komentarz pod moim wpisem | in-app + e-mail (jeśli pierwszy w dobie) | tak | „Marek napisał coś o Twoich plackach.” |
| Pytanie do mojego przepisu | in-app + e-mail | tak | „Anna ma pytanie do Twojego przepisu na sernik.” |
| Ktoś zaczął mnie obserwować | in-app, **zbiorczo** | nie | „3 nowe osoby Cię obserwują.” |
| Ktoś zapisał mój przepis | in-app, **zbiorczo dziennie** | nie | „5 osób zapisało dziś Twoje przepisy.” |
| „Ładne!” | in-app, **zbiorczo dziennie** | nie | „7 osób doceniło Twoje zdjęcie z wczoraj.” |
| Temat tygodnia | tylko `/home` + digest | nie | — (nigdy osobny e-mail) |
| Wspomnienie („rok temu”) | tylko `/home` | nie | — (nigdy e-mail, nigdy push) |
| Przypomnienie o zapisanym przepisie | e-mail, sobota rano, maks. 1/tydz. | nie | „Sernik Marka robi się 1,5 godziny — dziś jest czas?” |
| Cudza aktywność bez związku ze mną | **nigdy** | — | — |

### 3.2 Limity częstotliwości (twarde)

| Reguła | Wartość |
|---|---|
| E-maile transakcyjne (odzew na moje treści) | maks. **1 dziennie**, zbiorczo; przy 3 zdarzeniach jeden mail: „Dziś w Kuking: Marek ugotował Twój przepis i 2 osoby coś napisały” |
| E-maile nietransakcyjne (digest, przypomnienie o zapisanych) | maks. **1 tygodniowo** każdy typ, łącznie maks. 2 |
| Cisza nocna | 21:00 – 8:00 — brak e-maili i pushy, kolejkowane do rana |
| In-app | bez limitu (użytkownik sam wchodzi), zawsze grupowane po typie |
| Web Push (V1) | **tylko** „ktoś ugotował z Twojego przepisu” i „odpowiedź na Twoje pytanie”. Nic więcej. Zgoda pytana nie wcześniej niż po 3. wpisie użytkownika |
| Nowy użytkownik | w pierwszych 7 dniach maks. 3 e-maile łącznie (powitanie, odzew, pierwszy digest) |
| Osoba nieaktywna >60 dni | maks. **1 e-mail miesięcznie**, po 6 miesiącach — zero (bez „wracaj!”) |

### 3.3 Zasady języka powiadomień

1. **Zawsze imię konkretnej osoby**, nigdy „ktoś” ani „użytkownik”.
2. **Zawsze nazwa potrawy**, nigdy „Twój post” („Twoje pierogi z kaszą”, nie „Twoja publikacja”).
3. **Zero pilności**: nie „Nie przegap!”, nie „Ostatnia szansa”, nie liczników.
4. **Zero fałszywej personalizacji**: nie „Wybraliśmy dla Ciebie”.
5. Jedno zdanie + jeden link. Bez akapitów.
6. Ustawienia powiadomień w jednym miejscu, po polsku, z wyłącznikami per typ, dostępne z każdego maila (jedno kliknięcie, bez logowania).

---

## 4. Tygodniowy digest — szkic treści

**Nadawca:** imię gospodarza + „z Kuking” (np. „Marta z Kuking”), adres odpowiadalny — odpowiedzi czyta człowiek.
**Wysyłka:** piątek 17:00 (przed weekendem, gdy ludzie gotują i planują).
**Format:** prosty HTML, jedna kolumna, tekst **18 px**, duże zdjęcia, przyciski z tekstem, wersja tekstowa zawsze. Bez ikon social media, bez stopki korporacyjnej.

### 4.1 Temat maila (rotacja, zawsze konkret — nigdy „Newsletter Kuking #14”)

- „Ktoś ugotował z Twojego przepisu, Basiu”  *(jeśli prawda — zawsze wygrywa)*
- „W tym tygodniu robiliśmy pierogi”
- „Wasze przetwory ze śliwek — 23 słoiki”
- „Basiu, co gotujesz w ten weekend?”
- „Tydzień pierogów w Kuking”

### 4.2 Szkic maila

```text
Temat: Ktoś ugotował z Twojego przepisu, Basiu


Dzień dobry, Pani Basiu,

zaczynam od najlepszej rzeczy w tym tygodniu:

  ┌─────────────────────────────────────────┐
  │  [ZDJĘCIE — pierogi Marka, duże]        │
  │                                         │
  │  Marek ugotował Pani pierogi z kaszą.  │
  │  „Farsz zrobiłem dokładnie jak w        │
  │  przepisie, tylko dodałem chrzan.       │
  │  Wyszło świetnie.”                      │
  │                                         │
  │  [ Odpowiedz Markowi ]                  │
  └─────────────────────────────────────────┘


CO SIĘ DZIAŁO W PANI KUCHNI

  W tym tygodniu dodała Pani 3 dania.
  Odpowiedziało na nie 11 osób.
  Pani przepisy zapisało 5 osób.

  [ Zobacz swoje wpisy z tego tygodnia ]


TEMAT TYGODNIA: TWOJE PIEROGI

  Do Wigilii dwa tygodnie, więc lepimy.
  Do tej pory pokazało swoje pierogi 14 osób —
  są ruskie, są z kapustą, są dwa razy z soczewicą,
  a Pani Jadwiga z Podlasia pokazała pierogi z kartaczy.

  [ Zobacz wszystkie ]     [ Dodaj swoje ]


TRZY RZECZY, KTÓRE WARTO ZOBACZYĆ

  1. [zdjęcie] Kapusta z grzybami Pani Jadwigi —
     przepis po jej mamie, z 1968 roku.
     „Mama nie kroiła grzybów, tylko rwała.”

  2. [zdjęcie] Sernik Marka, którego zrobiły
     już cztery osoby. Wszystkie zrobią ponownie.

  3. [zdjęcie] Zakwas Pana Andrzeja — trzeci tydzień
     i wreszcie wyszedł. Warto przeczytać, co poprawił.


NA WEEKEND

  Zapisała Pani 3 przepisy i jeszcze żadnego nie zrobiła.
  Najkrótszy z nich to placki z cukinii Pani Ewy —
  20 minut.

  [ Zobacz przepis ]


Sezon na kapustę i grzyby. Jeśli Pani coś kisi
albo suszy w tym tygodniu — proszę pokazać,
bo mało kto to jeszcze u nas pokazał.

Dobrego weekendu,
Marta

—
Piszę do Pani raz w tygodniu. Jeśli to za często albo
za rzadko, proszę po prostu odpowiedzieć na tego maila
— czytam wszystkie odpowiedzi.

Nie chcę tych listów  ·  Ustawienia powiadomień
```

### 4.3 Reguły redakcyjne digestu

| Reguła | Dlaczego |
|---|---|
| **Blok osobisty na górze** („ktoś ugotował z Twojego przepisu”); jeśli nie ma — na górze idzie „co się działo w Pani kuchni” | Otwieralność bierze się z „to o mnie”, nie z „to ciekawe” |
| Maks. **3 cudze treści**, wybrane ręcznie przez gospodarza | Więcej = katalog. Ręczny wybór = ludzki głos, i to jest cała różnica |
| Każda pozycja ma **imię autora i jedno zdanie z historii przepisu** | Autorstwo widoczne; cytat z historii sprzedaje przepis lepiej niż tytuł |
| **Zero liczb rankingowych** („top 10”, „najpopularniejsze”) | Sprzeczne z zasadą bezpiecznej przestrzeni |
| **Zero słów** „content”, „polecane dla Ciebie”, „nie przegap” | Głos marki (`docs/BRAND.md`) |
| Formy grzecznościowe dopasowane do wieku odbiorcy: „Pani Basiu” dla 50+, „Basiu” dla młodszych — pole wyboru w ustawieniach | „Ty” do 68-latki potrafi zrazić; „Pani” do 30-latki brzmi sztywno |
| Jedno zdanie o sezonie na końcu | Sezon jako szept, nie baner |
| Możliwość odpowiedzi na maila, którą czyta człowiek | To najtańszy kanał badań użytkowników, jaki mamy |
| Digest **nie wysyłany**, jeśli w tygodniu było mniej niż 5 nowych wpisów w całym serwisie | Lepiej nie wysłać niż pokazać pustkę |

---

## 5. North Star i drzewo metryk

### 5.1 Definicja

> **WAC (Weekly Active Cooks)** — liczba unikalnych użytkowników, którzy w danym tygodniu kalendarzowym opublikowali **danie**, **przepis** albo **`Ugotowałem`**.

Nie liczą się: wejścia, przewinięcia, lajki, zapisy, komentarze. Kuking mierzy **gotowanie i pokazywanie**, nie konsumpcję.

### 5.2 Drzewo

```text
                      WAC (Weekly Active Cooks)
                                │
      ┌─────────────────────────┼─────────────────────────┐
      │                         │                         │
   NOWI COOKS              POWRACAJĄCY              ODZYSKANI
   (1. publikacja       (publikował w tyg.        (nieaktywny 4+ tyg.,
    w tym tygodniu)       poprzednim)               wrócił)
      │                         │                         │
      │                         │                         │
 ┌────┴─────┐          ┌────────┴────────┐        ┌───────┴───────┐
 │ Rejestr. │          │ Powód powrotu:  │        │ Digest        │
 │ × akty-  │          │ • odzew (P1,P2) │        │ Sezon (P5)    │
 │  wacja   │          │ • zajęcie (P4,  │        │ Wspomn. (P7)  │
 └────┬─────┘          │   P3, P5)       │        └───────────────┘
      │                │ • ciekawość     │
      │                └────────┬────────┘
      │                         │
 ┌────┴───────────────┐   ┌─────┴──────────────────────┐
 │ AKWIZYCJA          │   │ DŹWIGNIE JAKOŚCI           │
 │ • concierge 1:1    │   │ • % wpisów z odpowiedzią   │
 │ • KGW / UTW        │   │ • czas do 1. odpowiedzi    │
 │ • zaproszenia      │   │ • cooked→odpowiedź autora  │
 │   rodzinne         │   │ • save→cooked              │
 │ • prasa lokalna    │   │ • % przepisów z historią   │
 │ • SEO (po Bramce C)│   │ • uczestnictwo w tematach  │
 └────────────────────┘   └────────────────────────────┘

AKTYWACJA (7 dni, ≥3 z 4 — wg docs/SEO_ANALYTICS_GROWTH.md):
  obserwuje 5 osób · zapisał 3 przepisy · opublikował · zrobił Ugotowałem
```

### 5.3 Metryki drugiego poziomu — progi

| Metryka | Cel przy 200 | Cel przy 2000 | Dlaczego to mierzymy |
|---|---|---|---|
| `% wpisów z ≥1 odpowiedzią` | 100% | ≥70% | Najmocniej skorelowane z drugim wpisem. **Metryka nr 1 po WAC** |
| `mediana czasu do 1. odpowiedzi` | ≤3 h | ≤6 h | Odzew po 3 dniach nie jest odzewem |
| `% odpowiedzi nie od redakcji` | ≥40% | ≥70% | Czy społeczność się utrzymuje sama |
| `cooked → odpowiedź autora w 24 h` | ≥70% | ≥60% | Domknięcie głównej pętli |
| `Ugotowałem / tydzień` | ≥40 | ≥400 | Nasz najważniejszy sygnał jakości |
| `save → cooked w 30 dni` | ≥15% | ≥15% | Czy kolekcje są narzędziem, czy cmentarzem |
| `% przepisów z „skąd ten przepis”` | ≥30% | ≥35% | Czy jesteśmy duszą, czy bazą danych |
| `% przepisów z „po kim ten przepis”` | ≥25% | ≥35% | Nasza jedyna nieskopiowalna przewaga |
| `2. wpis w 7 dni od 1.` | ≥55% | ≥45% | Test nawyku |
| `D30 publikujących` | ≥25% | ≥30% | Retencja tam, gdzie ma znaczenie |
| `WAU/MAU` | ≥35% | ≥40% | Częstotliwość |
| `uczestnicy tematu / WAC` | ≥25% | ≥20% | Czy rytm tygodniowy żyje |
| `porzucone szkice przepisów` | <40% | <30% | Czy kreator 3 kroków nie jest za trudny |

---

## 6. Sygnały wczesnego ostrzegania

Kolejność ma znaczenie — pierwsze cztery są śmiertelne, reszta jest bolesna.

| # | Sygnał | Próg alarmowy | Co to znaczy | Co robić natychmiast |
|---|---|---|---|---|
| 1 | **Wpisy bez odpowiedzi** | `>0%` do 200 użytkowników; `>15%` przy 2000 | Fundament produktu pęka. Wszystko inne jest wtórne | Gospodarz odrabia kolejkę tego samego dnia; **wstrzymać rekrutację nowych użytkowników** |
| 2 | **Czas do pierwszej odpowiedzi rośnie** | mediana >6 h dwa tygodnie z rzędu | Redakcja nie wyrabia — wzrost wyprzedził operacje | Uruchomić ambasadorów tematów albo zatrzymać wzrost |
| 3 | **Spada `Ugotowałem`, choć wpisy rosną** | −30% tydzień do tygodnia przy stałym WAC | Stajemy się fotogalerią zamiast społeczności gotujących. Najgroźniejszy cichy dryf | Tematy pod natychmiastową potrzebę sezonową; gospodarz gotuje z cudzych przepisów 3×/tydz.; wyeksponować „Będziesz pierwsza?” |
| 4 | **Nowi publikują pierwszy wpis i znikają** | `2. wpis w 7 dni` <35% | Onboarding albo odzew nie działa (sprawdzić w tej kolejności: czy dostał odpowiedź → czy ją zobaczył → czy rozumie, jak dodać kolejny) | Powrót do concierge 1:1; test z 5 osobami 60+ |
| 5 | **Wypisy z digestu** | >1% na wysyłkę | Digest brzmi jak marketing albo jest za częsty | Skrócić, uczłowieczyć, przenieść blok osobisty na samą górę |
| 6 | **Wyłączanie powiadomień** | >8% użytkowników | Za dużo maili albo powiadomienia o rzeczach nieistotnych | Zaostrzyć limity, wyciąć powiadomienia niedotyczące użytkownika |
| 7 | **Rośnie stosunek komentarzy typu „pięknie!”** | >50% komentarzy bez treści | Kultura płynie w stronę Instagrama, wartość rozmowy spada | Gospodarz i ambasadorzy wracają do wzorca „konkret + pytanie”; zasady kultury przypomniane w digeście |
| 8 | **Porzucone szkice przepisów** | >45% | Kreator 3 kroków za trudny albo autosave nie działa | Test 50+ na kreatorze; sprawdzić autosave i komunikat „Szkic zapisany” |
| 9 | **Puste `skąd ten przepis` / `po kim`** | <20% przepisów | Tracimy różnicę wobec bazy danych — dryf w stronę CRUD-u | Temat tygodnia wspomnieniowy; gospodarz jako wzór; przeredagować podpowiedzi w kreatorze |
| 10 | **Jedna osoba dominuje feed** | >20% wpisów w „Świeżo z Kuking” od jednej osoby | Reguła „maks. 1 wpis od osoby na ładowanie” nie działa albo społeczność jest za mała | Sprawdzić regułę; jeśli działa — to problem skali, nie feedu: rekrutować |
| 11 | **Zgłoszenia moderacyjne** | >5 / 1000 wpisów albo kolejka >24 h | Zaczyna się psuć bezpieczeństwo miejsca. Dla 50+ jeden nieprzyjemny incydent jest zapamiętywany na długo | Moderacja tego samego dnia; przypomnienie zasad kultury; przy powtarzalności — blokada konta |
| 12 | **Cisza gospodarza** | 2 dni bez publikacji redakcji | Feed przestaje być codzienny, rytm się rozpada. Wypalenie gospodarza to realne ryzyko projektu | Zmiennik gospodarza; zaplanowane wpisy z zapasu; przegląd obciążenia |

### 6.1 Metryki, których świadomie nie optymalizujemy

| Metryka | Dlaczego nie |
|---|---|
| Odsłony / sesje / czas na stronie | Optymalizacja pod nie prowadzi prostą drogą do algorytmicznego feedu i nieskończonego przewijania |
| Liczba zarejestrowanych kont | Konto bez wpisu jest zerem. Liczymy tylko cooks |
| Liczba przepisów w bazie | Zaprasza do importu cudzych treści — nasz główny anty-wzorzec |
| Liczba „Ładne!” | Celowo nie jest publiczna, więc nie jest też celem |
| DAU | Nikt nie gotuje nowego dania codziennie i nie powinien czuć, że musi. Tygodniowy rytm jest właściwą jednostką dla tego produktu |

---

## 7. Co z tego wchodzi do MVP

| Element | Koszt | MVP? |
|---|---|---|
| Pętla 1 (`Ugotowałem` → ekran „Komuś wyszło” → odpowiedź autora) | M | **tak** |
| Pętla 2 (pytanie dnia + obietnica odzewu + panel „wpisy bez odpowiedzi”) | S + operacje | **tak** |
| Pętla 3 (sobotnie przypomnienie o 1 zapisanym przepisie) | S | **tak** |
| Pętla 4 (temat tygodnia + piątkowa kolekcja) | S | **tak** |
| Pętla 5 (sezon jako dane: pytanie dnia, temat, pasek w Discover) | S | **tak** |
| Pętla 6 (pola „po kim” / „skąd ten przepis” / „w rodzinie od” / skan) | S | **tak** |
| Pętla 9 (digest tygodniowy) | M | **tak** |
| Powiadomienia in-app + e-mail z limitami i ciszą nocną | M | **tak** |
| Alert dla gospodarza o wpisie nowego użytkownika | S | **tak** |
| Pętla 7 (wspomnienia „rok temu”) | M | V1 (archiwum po miesiącach już w MVP) |
| Pętla 8 (osobny typ „Pytanie do autora”) | M | V1 (w MVP zwykły komentarz) |
| Pętla 10 (ambasadorzy tematów) | S | V1 |
| Web Push | M | V1, tylko 2 typy zdarzeń |
| Planner, lista zakupów, spiżarnia, OCR, AI, native apps | L | **nie** — świadomie odsunięte |
