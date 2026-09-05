# COLD_START.md — plan 0 → 200 → 2000 użytkowników

> Najważniejszy dokument projektu. Kuking może mieć bezbłędny kod i umrzeć, bo w piątek wieczorem nikt nikomu nie odpowiedział.
> Zakłada start closed alphy w **pierwszym tygodniu listopada 2026** (T1 = 2–8 XI 2026). Jeśli start się przesunie, przesuwa się cały kalendarz z rozdz. 8, ale logika sezonowa zostaje przypisana do dat, nie do numerów tygodni.

## 1. Dlaczego tu jest trudniej niż w typowym starcie społeczności

| Problem | Dlaczego dotyczy właśnie Kuking |
|---|---|
| **Feed obserwowanych = pustka** | Świadomie wybraliśmy chronologiczny feed obserwowanych bez algorytmu. Nowy użytkownik z 0 obserwowanymi widzi zero. Algorytmiczny feed „ratuje” ten problem sztucznie — my go nie mamy i musimy go rozwiązać produktowo |
| **Podwójna pustka** | Brak przepisów → brak `Ugotowałem`. Brak `Ugotowałem` → przepisy wyglądają na porzucone. Dwie pętle blokują się wzajemnie |
| **Nie możemy oszukać contentem** | Standardowe lekarstwo (zaimportować 20 000 przepisów) niszczy nasz jedyny wyróżnik. Nie mamy prawa użyć najtańszego rozwiązania |
| **Grupa docelowa nie eksperymentuje z aplikacjami** | Osoba 61-letnia nie wchodzi na nowy portal „żeby zobaczyć”. Wchodzi, bo ktoś konkretny ją poprosił i pokazał, jak to działa |
| **Jednorazowe okno zaufania** | 50+ daje produktowi jedną szansę. Puste, milczące pierwsze wejście = koniec relacji, nie „wrócę później” |
| **Brak wiralowości gotowania** | Zdjęcie zupy nie rozchodzi się jak mem. Wzrost będzie liniowy i pracochłonny — trzeba to zaakceptować w planowaniu |
| **PWA bez sklepu** | Nie ma darmowego kanału odkrycia (App Store). Każdy użytkownik przyjdzie od człowieka albo z Google (a Google przyjdzie za późno) |

**Wniosek strategiczny:** przez pierwsze ~6 miesięcy Kuking nie jest produktem, który się skaluje. Jest **klubem prowadzonym ręcznie**. Wzrost jest kosztem operacyjnym redakcji, nie funkcją produktu.

## 2. Zasada nadrzędna: obietnica odzewu

Jedna obietnica, na której stoi cały plan:

> **Każdy wpis pierwszych 200 użytkowników dostaje odpowiedź od prawdziwego człowieka. Zwykle w ciągu 2 godzin, najpóźniej tego samego dnia.**

To nie jest miły dodatek — to jest produkt. Wszystko inne (search, kolekcje, SEO) można dowieźć później. Bez tego nie ma niczego.

Konsekwencja: **do 200 użytkowników nie wolno rosnąć szybciej, niż redakcja jest w stanie komentować.** Wzrost ponad tę granicę jest szkodliwy, nie korzystny.

---

## 3. Etap 0 → 20: „Kuchnia gospodarza” (tygodnie −4 do T3)

Cel: 20 realnych osób, które publikują z własnej woli, oraz **~250 obiektów startowych** (dania, przepisy, wykonania) zanim przyjdzie ktokolwiek z zewnątrz.

### 3.1 Kim mają być pierwsze 20 osób

Kryterium doboru — nie „kto jest wpływowy”, ale **kto już dziś fotografuje jedzenie i ma na to nawyk**.

| Priorytet | Kto | Ile osób | Dlaczego akurat oni |
|---|---|---|---|
| 1 | **Rodzina i rodzina znajomych 50+**, która wrzuca zdjęcia obiadów na Facebooka | 6–8 | Mają nawyk, mają zdjęcia w telefonie, ufają osobie, która prosi. Najwyższa konwersja |
| 2 | **Koła Gospodyń Wiejskich** — jedno, maks. dwa koła, przez sekretarza/przewodniczącą | 3–5 | Gotowa, zorganizowana grupa z realną wiedzą kulinarną i regionalną tożsamością. Jedna przewodnicząca = 5 osób |
| 3 | **Uniwersytet Trzeciego Wieku** — jedna grupa komputerowa | 2–4 | Ludzie 60+, którzy właśnie uczą się internetu i szukają na czym poćwiczyć. Prowadzący zajęcia to idealny sojusznik |
| 4 | **Domowi piekarze zakwasowi / kiszący** z grup FB (Marek, 54) | 2–3 | Najbardziej gadatliwa grupa w polskim internecie kulinarnym. Napędzają komentarze, których potrzebujemy |
| 5 | **Osoby po zeszytach po mamie/babci** — z grup „przepisy babci” | 2–3 | Przynoszą najcenniejszą treść (rodzinne receptury) i najsilniejszą motywację (nie chcą tego stracić) |

Kogo **nie** zapraszamy na tym etapie: blogerów kulinarnych, influencerów, znajomych z branży IT, „foodies”. Ich obecność zmieni normę z „domowe” na „ładne” i zablokuje Basię.

### 3.2 Jak ich znaleźć — konkretnie

| Kanał | Działanie | Realny nakład |
|---|---|---|
| Rodzina / znajomi rodziny | Lista 30 nazwisk na papierze, telefon lub rozmowa przy stole. Nie wiadomość na Messengerze | 1 wieczór |
| Koła Gospodyń Wiejskich | KGW są rejestrowane w ARiMR i mają lokalne dane kontaktowe; wiele ma strony na FB. Napisać do 15 kół w jednym regionie z konkretną, krótką prośbą. Odpowie 2–3 | 3–4 godziny + tygodnie oczekiwania |
| UTW | Lista UTW w kilku miastach, kontakt do osoby prowadzącej zajęcia komputerowe. Propozycja: „Kuking jako materiał na jedne zajęcia” | 2–3 godziny |
| Grupy FB (zakwas, kiszenie, przetwory, przepisy babci) | **Nie spamować.** Najpierw 2–3 tygodnie normalnego udziału w grupie własnym imieniem. Potem post o projekcie za zgodą administratora | 15 min/dzień przez 3 tygodnie |
| Lokalne biblioteki i domy kultury | Miejsca prowadzące warsztaty kulinarne dla dorosłych. Kontakt bezpośredni | 2 godziny |

### 3.3 Skrypt rozmowy (rozmowa telefoniczna lub twarzą w twarz)

Nie mówimy: „portal społecznościowy”, „platforma”, „aplikacja”, „projekt startupowy”.

> „Robię stronę, na której zwykli ludzie pokazują, co dziś ugotowali. Taką jak dawny Garnek, jeśli Pani pamięta. Nie ma tam ćwiczeń fitness ani reklam. Wrzuca się zdjęcie i dwa słowa, i to wszystko.
>
> Zbieram teraz **dwadzieścia osób, które naprawdę gotują**, żeby to nie było puste. Chciałbym, żeby Pani była jedną z nich.
>
> Nic Pani nie musi umieć. Zakładam Pani konto, wrzucam z Panią pierwsze zdjęcie przez telefon i pokazuję, gdzie kliknąć. Piętnaście minut.
>
> Jedno obiecuję: **jak Pani coś wrzuci, ktoś odpowie.** Nie zostawimy tego bez słowa.”

Na zastrzeżenia:

| Zastrzeżenie | Odpowiedź |
|---|---|
| „Nie umiem tego obsługiwać” | „Dlatego dzwonię, a nie piszę. Załóżmy razem konto teraz, przy telefonie.” |
| „Moje zdjęcia są brzydkie” | „To jest cała różnica między nami a Instagramem. Tam ma być ładne, tu ma być prawdziwe.” |
| „Nie mam czasu” | „Jedno zdjęcie tygodniowo wystarczy. Tego, co i tak Pani gotuje.” |
| „A po co Panu moje przepisy?” | „Nie sprzedajemy przepisów. Autorka zostaje podpisana zawsze i może w każdej chwili wszystko zabrać — jest przycisk »Pobierz swoje dane«.” |
| „Czy to jest darmowe?” | „Tak. Kiedyś być może będzie coś dodatkowego płatnego, ale pokazywanie zdjęć i przepisów zostanie darmowe.” |

### 3.4 Concierge onboarding (do ~50 użytkowników)

Nie wysyłamy linku. **Zakładamy konto razem z osobą**, przy telefonie lub przy jej komputerze:

1. konto (my wpisujemy dane, jeśli tak jest łatwiej);
2. avatar z jej telefonu;
3. **pierwsze 5 zdjęć z galerii telefonu** — z ostatnich tygodni, tego, co już ugotowała. To jest kluczowy moment: człowiek ma w telefonie gotowe 40 zdjęć obiadów;
4. jeden przepis, który zna na pamięć — my wpisujemy, ona dyktuje;
5. pole „po kim ten przepis” — wypełnić przy niej, na głos. To moment, w którym ludzie się otwierają;
6. obserwowanie 5 osób (gospodarz + 4 z klubu);
7. ustawienie większego tekstu, jeśli mruży oczy;
8. **pokazujemy, gdzie znajdzie odpowiedzi** (`Powiadomienia`) — bo to tam wróci.

Po rozmowie: w ciągu 2 godzin gospodarz komentuje **każde** z tych 5 zdjęć. Konkretnie, nie „pięknie!”.

### 3.5 Treść startowa: skąd 250 obiektów bez importu

| Źródło | Ile obiektów | Uczciwość |
|---|---|---|
| Galerie telefonów pierwszych 20 osób (5–15 starych zdjęć każda, z prawdziwymi datami) | 150–250 | Pełna: to ich własne dania, oznaczone datą wykonania |
| Własne przepisy pierwszych 20 (1–5 każda) | 30–80 | Pełna |
| Gospodarz: własne gotowanie codziennie od tygodnia −4 | ~30 | Pełna |
| Wykonania krzyżowe: prosimy 10 osób, żeby ugotowały po 1 przepisie z klubu | 10–15 | Pełna, i to najcenniejsza treść, jaką mamy |

Zero scrapowania, zero AI, zero fałszywych kont. Każdy obiekt ma prawdziwego autora, który wie, że tam jest.

---

## 4. Etap 20 → 200: playbook gospodarza (T1 – T12)

### 4.1 Kim jest gospodarz

1–2 osoby (nie „community manager” — **gospodarz**), z twarzą, imieniem i własnym profilem, na którym naprawdę gotuje. Jeśli to możliwe: jedna osoba 50+ i jedna młodsza. Gospodarz jest widoczną osobą, nie kontem `@kuking_team`.

Nakład: **2–2,5 h dziennie, 7 dni w tygodniu.** To jest cena tego projektu i musi być zaplanowana jak koszt serwera.

### 4.2 Dzień gospodarza

| Blok | Czas | Co dokładnie |
|---|---|---|
| **Rano 8:00–8:30** | 30 min | Przegląd nocnych wpisów. Komentarz do **każdego** nowego. Odpowiedzi na pytania. Sprawdzenie zgłoszeń moderacyjnych |
| **Publikacja własna 13:00** | 15 min | Gospodarz wrzuca swój obiad — zdjęcie + kilka słów. Raz w tygodniu pełny przepis z wypełnionym „skąd ten przepis” (wzorzec dla innych) |
| **Południe 14:00–14:30** | 30 min | Druga tura komentarzy. `Ugotowałem` z przepisu kogoś z klubu (min. 3 w tygodniu — to napędza najważniejszą pętlę) |
| **Wieczór 19:30–20:30** | 60 min | Godzina szczytu (ludzie wrzucają po obiedzie/kolacji). Komentarze, odpowiedzi, prywatne przywitanie nowych osób. Zaproszenie 1–2 nowych osób do klubu |
| **Poniedziałek +30 min** | 30 min | Ogłoszenie tematu tygodnia + własny wpis do tematu jako pierwszy |
| **Piątek +30 min** | 30 min | Kolekcja tygodnia z treści użytkowników (maks. 2 wpisy od jednej osoby) + przygotowanie digestu |

### 4.3 Zasady komentowania (twarde)

1. **Nigdy „pięknie!”, „mniam”, „super”.** Komentarz musi zawierać konkret ze zdjęcia lub pytanie.
2. **Zawsze pytanie na końcu**, jeśli to pierwsze 3 wpisy tej osoby — pytanie zmusza do powrotu.
3. **Nigdy nie poprawiamy przepisu.** „U nas robi się różnie” to zasada, nie uprzejmość.
4. **Nigdy nie komentujemy jakości zdjęcia** (ani pozytywnie: „jakie ładne zdjęcie!” — bo to ustawia zdjęcie jako kryterium).
5. Jeden komentarz na wpis. Nie zalewamy.

### 4.4 Szablony odpowiedzi (do adaptacji, nigdy kopiuj-wklej bez zmiany)

| Sytuacja | Odpowiedź gospodarza |
|---|---|
| Pierwszy wpis nowej osoby | „Dzień dobry w Kuking, Pani Basiu. Te placki wyglądają na smażone na smalcu — zgadłem? Robi je Pani z ziemniaków tartych na drobno czy na grubo?” |
| Zwykłe danie codzienne | „Kotlet i mizeria — u nas w domu to był standard w czwartek. Panierka bułka czy tarta?” |
| Coś regionalnego | „Kartacze! Mało kto je tu jeszcze pokazał. To przepis podlaski czy z innej strony?” |
| Rodzinny przepis | „»Po babci Halinie« — to najlepsza rzecz, jaką można napisać przy przepisie. Wie Pani, z którego to roku?” |
| Nieudane danie / przepraszający wpis | „Dobrze, że Pani to pokazała. U mnie zakwas dwa razy nie wyszedł i nikt tego nie widział. Tu można pokazywać też takie dni.” |
| Ktoś przyszedł i milczy tydzień | (nie ponaglać publicznie) — komentarz do starego wpisu: „Wracam do Pani żurku — robiłem w weekend podobny. Dodaje Pani chrzan?” |
| Pytanie techniczne o serwis | „Zrobię to dla Pani od razu. A jeśli coś jeszcze nie działa, proszę pisać wprost do mnie — jestem tu codziennie.” |
| Pierwszy `Ugotowałem` z czyjegoś przepisu | „Pani Basiu, Marek ugotował z Pani przepisu — zdjęcie jest u niego. To pierwsza taka rzecz w Kuking.” |
| Konflikt / uwaga krytyczna | „Każdy robi po swojemu i to jest w porządku. Pani wersja z majerankiem, Marka z lubczykiem — dopiszmy obie.” |

### 4.5 Co gospodarz robi z każdym nowym użytkownikiem (checklista, 7 dni)

| Dzień | Działanie |
|---|---|
| 0 | Obserwuje nową osobę. Komentarz do pierwszego wpisu w ciągu 2 h |
| 1 | Prosi 2 osoby z klubu, żeby też skomentowały („trzy odpowiedzi” to progowa liczba, przy której człowiek czuje się przyjęty) |
| 2–3 | Podsuwa jej 1 przepis dopasowany do tego, co gotuje: „Pani Anno, tu jest przepis na sernik, o który Pani pytała” |
| 4–5 | Jeśli nie ma drugiego wpisu — komentarz pod pierwszym, nie mail |
| 7 | Jeśli publikuje: zaproszenie do tematu tygodnia. Jeśli milczy: jedna wiadomość, potem cisza (nie nagabujemy) |

---

## 5. Etap 200 → 2000 (mniej więcej T13 – T52)

Tu **musi** się zmienić model, bo gospodarz przestaje wyrabiać. Zmiana polega na przekazaniu obietnicy odzewu społeczności.

| Dźwignia | Co robimy | Warunek wejścia |
|---|---|---|
| **Ambasadorzy tematów** | 8–12 najaktywniejszych osób dostaje osobiste zaproszenie: „prowadź temat »chleb i zakwas«”. Zadanie: 3 komentarze dziennie pod nowymi wpisami w temacie. Nagroda: widoczna rola „prowadzi temat”, wpływ na kolekcje, kontakt z redakcją | ≥200 użytkowników, ≥5 tematów z ruchem |
| **Otwarcie kół** (V1) | Otwieramy 3–5 kół z najdłuższymi listami zapisów (mechanika z `SOUL.md` 4.7), każde z wyznaczonym gospodarzem | Gotowa funkcja grup + ambasadorzy działają ≥6 tygodni |
| **Zaproszenia rodzinne** | „Zaproś córkę / siostrę / sąsiadkę” — z realnym powodem: „żeby zobaczyła Twoją rodzinną książkę”. Nie punkty za zaproszenia | ≥1000 dań w serwisie (żeby zaproszony coś zobaczył) |
| **Koła Gospodyń — z jednego na wiele** | Pierwsze koło jako referencja („KGW z X już z nami”). Wtedy list do 100 kół konwertuje znacznie lepiej niż zimny | 1 aktywne koło z ≥5 publikującymi osobami |
| **Prasa lokalna i senioralna** | Gazety powiatowe, portale miejskie, prasa dla 50+ („Przyjaciółka”-typ, „Świat Seniora”-typ), audycje lokalnego radia. Temat dla nich: „Polacy ratują przepisy po babciach” — nie „nowy startup” | Historia do opowiedzenia: 3–5 realnych rodzinnych receptur z fotografiami zeszytów, za zgodą właścicielek |
| **Wielkie momenty roku** | Wigilia (24 XII 2026), tłusty czwartek (4 II 2027), Wielkanoc (28 III 2027) jako naturalne szczyty — do każdego przygotowany temat i kolekcja | Zaplanować 3 tygodnie wcześniej |
| **SEO — dopiero teraz** | Publiczne przepisy, `Recipe` schema, sitemapa (już w MVP technicznie), ale **aktywna praca nad ruchem z Google startuje przy ~1500 przepisach z prawdziwą treścią** | Nigdy przed 200 użytkownikami — patrz anty-wzorce |

Skalowanie odzewu przy 2000 użytkowników:

| Warstwa | Kto odpowiada |
|---|---|
| Nowi użytkownicy (pierwsze 3 wpisy) | Gospodarz — nadal ręcznie, bez wyjątku |
| Wpisy w tematach | Ambasadorzy tematów |
| Reszta | Społeczność (mierzymy `% wpisów z ≥1 odpowiedzią` — patrz `RETENTION_LOOPS.md`) |

---

## 6. Feed przy zerowych obserwowanych — rozwiązanie produktowe

Problem: chronologiczny feed obserwowanych to nasza zasada i nie zmieniamy jej. Ale **nowy użytkownik nie może zobaczyć pustki.**

Rozwiązanie: `/home` to nie „feed”, to **strona z blokami**, których kolejność zależy od stanu użytkownika. Feed obserwowanych jest jednym z bloków, nie całą stroną.

### 6.1 Kompozycja `/home` — reguły

| Stan użytkownika | Kolejność bloków na `/home` |
|---|---|
| **0 obserwowanych, 0 wpisów** (dzień 1) | 1. Powitanie z imieniem + `[ Dodaj pierwsze zdjęcie ]` · 2. **Temat tygodnia** · 3. „Świeżo z Kuking” (ostatnie 20 publicznych wpisów, chronologicznie) · 4. „Ludzie, którzy gotują jak Ty” (5 osób wg zainteresowań z onboardingu, z podglądem 3 zdjęć każdej) · 5. „Teraz sezon na…” |
| **0–4 obserwowanych, ≥1 wpis** | 1. Pytanie dnia · 2. Feed obserwowanych (jeśli niepusty) · 3. „Świeżo z Kuking” · 4. Temat tygodnia · 5. Propozycje osób |
| **5+ obserwowanych, aktywny** | 1. Pytanie dnia · 2. **Feed obserwowanych** (dominuje) · 3. Na końcu feedu: „To wszystko z dzisiaj” + „Świeżo z Kuking” jako dokładka · 4. Temat tygodnia raz w tygodniu na górze (poniedziałek–wtorek) |
| **Wracający po >14 dniach** | 1. „Dobrze, że wracasz” + 3 rzeczy, które go dotyczą (kto ugotował z jego przepisu, kto skomentował) · 2. Feed obserwowanych · 3. Reszta |

### 6.2 „Świeżo z Kuking” — zasady, żeby nie było algorytmem

- kolejność **chronologiczna**, nie „popularne”;
- **maks. 1 wpis od jednej osoby** w jednym ładowaniu (inaczej 3 aktywne osoby zasłonią wszystko);
- wykluczamy zablokowanych i zgłoszone treści;
- preferencja tematyczna **tylko** jako filtr na starcie (zainteresowania z onboardingu), nigdy jako ranking;
- widoczna etykieta: „Świeżo z Kuking — najnowsze dania osób, których jeszcze nie obserwujesz”. Użytkownik musi rozumieć, dlaczego to widzi. Zero tajemnicy.

### 6.3 Powitanie zamiast pustki (dzień 1)

```text
Dzień dobry, Basiu.

Kuking jest jeszcze mały — jest nas tu 87 osób i wszystkie
naprawdę gotują. Dlatego zaczynamy od Twojego zdjęcia.

[ Dodaj pierwsze zdjęcie ]

Na razie tylko pooglądam
```

Uczciwość co do skali („jest nas 87”) jest przewagą, nie wstydem: człowiek 50+ chętniej wejdzie do małej, znanej grupy niż do anonimowego tłumu. **Nie udajemy dużego serwisu.**

### 6.4 Co jeszcze ratuje feed w MVP

| Mechanizm | Efekt |
|---|---|
| Automatyczne obserwowanie gospodarza po rejestracji (z możliwością cofnięcia) | Feed nigdy nie jest pusty — gospodarz publikuje codziennie |
| Obserwowanie **tematu**, nie tylko osoby (`SOUL.md` 4.7) | Osoba z 0 obserwowanymi, ale obserwująca „przetwory”, ma pełny feed |
| Propozycje osób z podglądem 3 zdjęć | Ludzie 50+ nie klikają w listę nazwisk; klikają w zdjęcie zupy |
| Jawny koniec feedu | Zamiast pustego przewijania w nieskończoność: „To wszystko z dzisiaj” |

---

## 7. Onboarding: pierwsza treść i pierwsza reakcja w < 5 minut

Cel liczbowy: **od kliknięcia „Załóż konto” do pierwszego komentarza pod własnym zdjęciem — poniżej 5 minut** (mediana pierwszych 200 użytkowników).

### 7.1 Ścieżka sekunda po sekundzie

| Czas | Ekran | Uwaga |
|---|---|---|
| 0:00 | `Załóż konto` — email, hasło, imię. **Trzy pola** | Bez telefonu, bez captchy jeśli da się inaczej, bez zgód marketingowych na tym ekranie |
| 0:40 | „Jak mamy się do Ciebie zwracać?” (imię) + „Co najczęściej gotujesz?” — 8 dużych kafli z obrazkami (zupy, ciasta, chleb, przetwory, mięsa, wegetariańskie, kuchnia regionalna, obiady codzienne) | Maks. 1 ekran. Kafle z obrazkami, nie checkboxy |
| 1:10 | „Ci ludzie gotują podobnie” — 5 osób, każda z 3 zdjęciami, przycisk `Obserwuj` przy każdej. Gospodarz zawsze pierwszy i wstępnie zaznaczony | Można pominąć. Nie blokujemy |
| 1:40 | **„Pokaż, co ostatnio ugotowałaś”** — od razu wybór zdjęcia z galerii telefonu. Nie „możesz dodać post”, ale wprost otwarcie galerii | To jest cały onboarding. Reszta jest dekoracją |
| 2:30 | Napisz kilka słów (z przykładem w placeholderze) → `Opublikuj` | Weryfikacja e-maila **nie blokuje** publikacji — blokuje tylko komentowanie cudzych treści (ochrona przed spamem) |
| 2:50 | „Gotowe. To Twój pierwszy wpis w Kuking — 3 listopada 2026.” + jego widok | Data od pierwszej chwili |
| 3:00 | Ekran zamykający: „**Ktoś na to odpowie.** Zajrzyj wieczorem do Powiadomień.” + `[ Dodaj jeszcze jedno zdjęcie ]` (bo w galerii jest ich więcej) | Zapowiedź odzewu to obietnica powrotu |
| **≤ 5:00** | **Pierwszy komentarz gospodarza** (alert operacyjny — patrz niżej) | To jedyny krok, którego nie robi produkt |

### 7.2 Techniczne wsparcie obietnicy odzewu

| Element | Koszt | Opis |
|---|---|---|
| **Alert „nowy wpis nowego użytkownika”** dla gospodarza | S | E-mail/webhook natychmiast, gdy publikuje ktoś z <3 wpisami. Bez tego obietnica 2 godzin nie działa w praktyce |
| **Panel „wpisy bez odpowiedzi”** w `/admin` | S | Lista wpisów bez komentarza, najstarsze na górze. To jest najważniejszy ekran administracyjny w całym MVP |
| **„Na razie tylko pooglądam”** | S | Musi istnieć. Wymuszona publikacja daje jeden słaby wpis i utratę zaufania (zgodne z `UX_50_PLUS.md`) |
| **Kolejne zdjęcia po pierwszym** | S | Po publikacji od razu proponujemy dodanie następnego — człowiek ma w telefonie 40 zdjęć obiadów i jest w trybie „już wiem, jak to działa” |

---

## 8. Kalendarz pierwszych 12 tygodni (T1 = 2–8 XI 2026)

Zasady: jeden temat na tydzień, ogłaszany w poniedziałek, gospodarz publikuje pierwszy, w piątek kolekcja z wpisów uczestników. Temat ma być **łatwy** (każdy to gotuje), a nie ambitny.

| # | Tydzień | Temat tygodnia | Zaczepienie sezonowe / kalendarzowe | Cel dodatkowy |
|---|---|---|---|---|
| T1 | 2–8 XI | **„Co dziś ugotowałaś?”** — bez tematu, tylko rytuał | Start klubu, po Zaduszkach | Nauczyć podstawowej czynności: zdjęcie + kilka słów |
| T2 | 9–15 XI | **„Coś z pieca”** — pieczone mięso, warzywa, zapiekanki | **Św. Marcin 11 XI** — gęsina; początek pieczenia na zimno | Pierwsze przepisy pełne, nie tylko wpisy |
| T3 | 16–22 XI | **„Zupa na listopad”** | Najgorsza pogoda w roku = zupy | Najłatwiejszy możliwy temat, maksymalna liczba uczestników |
| T4 | 23–29 XI | **„Przepis po mamie”** ⭐ | Przed adwentem, nastrój wspomnieniowy; **Andrzejki 30 XI** | **Kluczowy tydzień**: uruchomienie pól „po kim ten przepis” i „w rodzinie od” |
| T5 | 30 XI – 6 XII | **„Ciasto na weekend”** | **Barbórka 4 XII, Mikołajki 6 XII** | Wciągnąć osoby pieczące (najbardziej wytrwała grupa) |
| T6 | 7–13 XII | **„Kapusta i grzyby”** | Przygotowania do Wigilii się rozpoczynają | Przepisy przydatne natychmiast → pierwsze `Ugotowałem` z realnej potrzeby |
| T7 | 14–20 XII | **„Twoje pierogi”** ⭐ | Tydzień lepienia przed Wigilią | Temat maksymalnie polski i maksymalnie sporny (farsz!) → komentarze |
| T8 | 21–27 XII | **„Wigilia u nas”** ⭐⭐ | **Wigilia 24 XII** | Szczyt roku. Nie prowadzić żadnej innej akcji. Gospodarz dyżuruje 24 XII wieczorem |
| T9 | 28 XII – 3 I | **„Co z resztek”** | Między świętami, Sylwester | Lekki, żartobliwy temat po intensywnym T8; odciążenie |
| T10 | 4–10 I | **„Zaczynamy lżej”** — warzywa, kasze, zupy krem | Postanowienia noworoczne bez języka diety | Uwaga: **zero słowa „dieta”, zero kalorii** — nie jesteśmy o odchudzaniu |
| T11 | 11–17 I | **„Chleb i zakwas”** | Środek zimy, czas na dłuższe procesy | Wciągnąć grupę Marka (54) — najbardziej dyskutującą; test przyszłego koła |
| T12 | 18–24 I | **„Jak robiła babcia”** ⭐⭐ | **Dzień Babci 21 I, Dzień Dziadka 22 I** | Domknięcie łuku: od „co dziś ugotowałaś” (T1) do dziedzictwa. Materiał na pierwszą rozmowę z prasą |

Dalej (poza pierwszymi 12): tłusty czwartek **4 II 2027** (pączki i faworki — łatwy wiralny moment), post i śledzie, Wielkanoc **28 III 2027** (mazurki, żurek, jajka), szparagi i rabarbar (V), truskawki (VI), ogórki i kiszenie (VII–VIII), przetwory i powidła ze śliwek (IX), grzyby (IX–X), dynia i wykopki (X), kapusta kiszona (X–XI).

Reguły prowadzenia tematów:
1. Temat to **zaproszenie, nie konkurs** — nie ma zwycięzcy, nie ma jury, nie ma nagrody.
2. Uczestnictwo w temacie = zwykły wpis z wybranym tematem. Nie osobny formularz.
3. Jeśli w temacie jest <5 wpisów do środy, gospodarz osobiście prosi 5 osób z klubu. Temat bez uczestników jest gorszy niż brak tematu.
4. W piątek kolekcja: „Wasze pierogi — 23 dania od 14 osób”, maks. 2 wpisy od jednej osoby.

---

## 9. Metryki bramkowe

Definicje spójne z `docs/PRODUCT.md` i `docs/SEO_ANALYTICS_GROWTH.md`. **WAC** (Weekly Active Cooks) = liczba osób, które w danym tygodniu opublikowały danie, przepis lub `Ugotowałem`.

### Bramka A: 20 → wolno zaprosić kolejnych (cel: 200)

| Warunek | Próg |
|---|---|
| Realni użytkownicy z ≥1 wpisem | ≥20 |
| WAC / zarejestrowani | ≥50% |
| Osoby z ≥3 wpisami | ≥10 |
| `Ugotowałem` łącznie | ≥15, z tego ≥8 nie od gospodarza |
| % wpisów z ≥1 odpowiedzią | **100%** (obietnica odzewu) |
| Mediana czasu do pierwszej odpowiedzi | ≤3 h |
| Awarie uploadu zdjęć | <2% próbek |
| Blokery UX z testów 50+ | 0 |

**STOP, jeśli:** WAC/zarejestrowani <35% albo ludzie publikują tylko po telefonie od gospodarza. To znaczy, że nie ma produktu, tylko grzeczność. Wtedy → rozdz. 10.

### Bramka B: 200 → wolno rosnąć do 2000

| Warunek | Próg |
|---|---|
| Użytkownicy z ≥1 wpisem | ≥200 |
| WAC | ≥80 |
| D30 dla osób publikujących | ≥25% |
| `Ugotowałem` / tydzień | ≥40 |
| Przepisy z ≥3 wykonaniami | ≥15 |
| **% odpowiedzi udzielonych nie przez redakcję** | ≥60% |
| Ambasadorzy tematów działający ≥4 tygodnie | ≥6 |
| Zgłoszenia / 1000 wpisów | <5, kolejka moderacji obsługiwana <24 h |
| Restore drill wykonany | tak |

**STOP, jeśli:** odpowiedzi nie-redakcyjnych <35% (społeczność nie przejmuje odzewu — wzrost tylko zwiększy dług operacyjny) albo D30 <15%.

### Bramka C: 2000 → wolno włączyć SEO i płatną akwizycję

| Warunek | Próg |
|---|---|
| Publiczne przepisy z prawdziwą treścią | ≥1500 |
| Przepisy z wypełnionym „skąd ten przepis” | ≥35% |
| WAC | ≥600 |
| WAU/MAU | ≥40% |
| Wpisy z ≥1 odpowiedzią (bez redakcji) | ≥70% |
| Koła / tematy z własnym życiem (≥10 wpisów/tydz.) | ≥5 |

### Tablica cotygodniowa gospodarza (7 liczb, nic więcej)

`WAC` · `nowi z ≥1 wpisem` · `% wpisów bez odpowiedzi` · `Ugotowałem w tygodniu` · `wpisy w temacie tygodnia` · `powroty D7` · `zgłoszenia`

---

## 10. Plan B — co robić, gdy nie działa

Diagnoza zawsze przed leczeniem. Cztery typowe awarie i odpowiedź na każdą:

| Objaw | Prawdziwa przyczyna (najczęściej) | Co robić |
|---|---|---|
| **Ludzie zakładają konta i nie publikują** | Za wysoki próg albo wstyd przed zdjęciem | Wrócić do concierge onboardingu 1:1 dla 100% nowych. Skrócić onboarding do dwóch ekranów. Sprawdzić na 5 osobach 60+, gdzie pada pytanie „co mam kliknąć” |
| **Publikują raz i nie wracają** | Brak odzewu albo brak powodu powrotu | Sprawdzić `% wpisów bez odpowiedzi`. Jeśli >0 — to jest cała odpowiedź. Jeśli 0 — problem w powiadomieniach (czy człowiek w ogóle widzi, że mu odpowiedziano?) |
| **Publikują, ale nikt nie gotuje z przepisów** | Za mało przepisów albo przepisy nieprzydatne teraz | Tematy sezonowe pod natychmiastową potrzebę (T6 „kapusta i grzyby”). Gospodarz gotuje 3× w tygodniu z cudzych przepisów. Wprowadzić „Będziesz pierwsza?” pod przepisami bez wykonań |
| **Nic nie rośnie mimo dobrej retencji** | Problem akwizycji, nie produktu — to najlepszy z problemów | Zwiększyć liczbę kanałów rekrutacji (KGW, UTW, prasa lokalna), nie zmieniać produktu |

### Drabinka odwrotu (jeśli po 6 miesiącach WAC < 50)

| Wariant | Na czym polega | Kiedy wybrać |
|---|---|---|
| **B1: Jedna nisza zamiast całej kuchni** | Skupić się na jednym silnym temacie („przetwory i kiszenie” albo „chleb na zakwasie”) i stać się w nim najlepszym miejscem w Polsce. Rosnąć potem | Jeden temat wyraźnie żyje, resztę trzeba popychać |
| **B2: Jedno koło zamiast portalu** | Obsłużyć jedną realną, offline'ową grupę (KGW, parafia, UTW) jako narzędzie dla nich. Zdobyć retencję z relacji offline | Mamy jedną grupę, w której działa, i zero organicznego wzrostu |
| **B3: Rodzinne archiwum jako produkt główny** | Przestawić pozycjonowanie: nie społeczność, a **ratowanie rodzinnych przepisów** (prywatne książki, skany zeszytów, wydruk dla rodziny). Społeczność jako dodatek. To ścieżka z realną monetyzacją (druk, PDF) | Ludzie wypełniają „po kim ten przepis” i „skąd ten przepis”, ale nie komentują sobie wzajemnie |
| **B4: Pauza, nie śmierć** | Utrzymać serwis w trybie tanim (koszty hostingu minimalne), zatrzymać rozwój, zostawić eksport danych. Wrócić z inną hipotezą | Wszystkie powyższe sprawdzone i nie działają |

**Czego nie robić jako planu B:** nie dokładać funkcji (planner, AI, przepisy z importu). Puste społeczności nie umierają z braku funkcji — umierają z braku ludzi i odzewu.

---

## 11. Anty-wzorce cold startu

| Anty-wzorzec | Dlaczego zabija Kuking |
|---|---|
| **Masowy import cudzych przepisów** (scraping, bazy, „dla wypełnienia”) | Niszczy jedyny wyróżnik (autor + historia + wykonanie), tworzy ryzyko prawne, a nowy użytkownik traci powód, by pisać własne. Zabija też SEO-moat: przepis bez autora jest zastępowalny przez AI |
| **Boty i fałszywe konta** | Jeden odkryty fałszywy komentarz kosztuje zaufanie całej grupy 50+, a wieść rozniesie się po kole gospodyń w jeden dzień. Nieodwracalne |
| **Wygenerowanie treści AI „na start”** | To samo co wyżej, tylko trudniejsze do wykrycia i gorsze, gdy wyjdzie |
| **Kupowanie ruchu / SEO przed społecznością** | Człowiek z Google trafia na pusty przepis bez wykonań i nie wraca. Palimy jednorazową szansę i pieniądze. SEO ma sens **po** Bramce C |
| **Zaproszenie influencerki na start** | Zmienia normę z „prawdziwe” na „ładne”. Basia porównuje swoje zdjęcie z jej zdjęciem i przestaje publikować. Efekt natychmiastowy i trwały |
| **Wzrost szybszy niż zdolność odpowiadania** | Obietnica odzewu jest naszym produktem. 500 osób bez odpowiedzi jest gorsze niż 50 z odpowiedzią |
| **Product Hunt / Hacker News / Wykop na start** | Zły ruch: technologiczny, jednodniowy, nie gotujący. Skrzywia metryki i psuje pierwsze wrażenie kultury |
| **Konkursy z nagrodami** | Sprowadzają ludzi polujących na nagrody, nie gotujących. Po konkursie retencja spada do zera, a norma „to konkurs” zostaje |
| **Punkty za zaproszenia** | Produkuje zaproszenia do osób, które nie chcą; psuje relacje realnych ludzi |
| **Zbieranie e-maili na landingu „coming soon” przez miesiące** | Lista, która ostygła, nie konwertuje. Lepiej 20 osób założonych ręcznie niż 2000 adresów |
| **Ogłaszanie funkcji, których nie ma** | Grupa 50+ pamięta obietnice i traktuje je serio. Wyjątek: zapisy na koła, gdzie wyraźnie mówimy „przygotowujemy”, bez daty |

---

## 12. Koszt operacyjny — uczciwie

| Pozycja | Etap 0→20 | Etap 20→200 | Etap 200→2000 |
|---|---|---|---|
| Gospodarz (godziny/tydzień) | 10–14 h (rekrutacja + concierge) | **15–18 h** (2–2,5 h/dzień, 7 dni) | 20–25 h, ale rozdzielone: redakcja + 8–12 ambasadorów |
| Osoby | 1 | 1–2 | 2 + ambasadorzy |
| Rekrutacja | telefon, spotkania, listy do KGW/UTW | 1–2 nowe osoby dziennie | kanały grupowe, prasa lokalna |
| Moderacja | znikoma | <30 min/dzień | osobna rola lub rotacja ambasadorów |
| Czas trwania | 4–8 tygodni | 3–6 miesięcy | 6–12 miesięcy |

Jeśli nie ma kogoś, kto realnie da 2 godziny dziennie przez pół roku, **nie należy uruchamiać publicznej bety.** Lepiej zostać w trybie 20 osób do czasu, gdy taka osoba się znajdzie.
