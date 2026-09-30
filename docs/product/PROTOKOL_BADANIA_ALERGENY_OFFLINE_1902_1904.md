# Badanie z osobami 50+: alergeny (#1902) i przepisy bez internetu (#1904) — protokół R2

Wersja 1.0, 30 września 2026. Powiązania: [#1902](https://github.com/woogitsu/kuking.pl/issues/1902)
(alergeny, flaga `KUKING_ALERGENY_WLACZONE`), [#1904](https://github.com/woogitsu/kuking.pl/issues/1904)
(przepisy do czytania bez internetu). Wzorzec i kody wyników przejęte z
[protokołu #1818](PROTOKOL_BADANIA_1818.md) i [protokołu #15](TESTY_Z_UZYTKOWNIKAMI.md);
ten plik opisuje tylko to, co jest inne. Gdy milczy, obowiązuje protokół #1818.

**Materiał przygotowany. Żadna sesja z człowiekiem nie została tu wykonana.**
Żaden pusty wiersz tego dokumentu nie jest dowodem przeprowadzenia badania.

## 0. Status i co ten dokument rozstrzyga

- **Decyzja właściciela z 30 września 2026:** przed włączeniem alergenów i przed
  budową przepisów bez internetu robimy test z 5–8 osobami 50+. Decyzja nie ma
  jeszcze wpisu w [DECISIONS.md](../DECISIONS.md) (D-333 o niej milczy);
  zapis `D-xxx` z odwołaniem do tego protokołu należy do właściciela (§10).
- **#1902 jest zbudowane, ale wyłączone.** Kod leży na gałęzi
  `origin/claude/1902-alergeny` (stan z 30.09, ostatni commit `0c91f1acb`),
  a flaga `KUKING_ALERGENY_WLACZONE` jest domyślnie `false`. Wyłączona flaga
  oznacza: brak sekcji w kreatorze, brak bloku na stronie przepisu, brak filtra
  w wyszukiwarce. Test rozstrzyga, czy flagę wolno włączyć (§8, §10).
- **#1904 nie jest zbudowane** i nie ma zgody na budowę (lista „V2, ale nie teraz”
  w [FEATURES.md](../FEATURES.md)). Badanie nie pokazuje uczestnikom żadnej atrapy
  funkcji. Sprawdza, czy potrzeba istnieje i czy nie zaspokajają jej już
  „Drukuj przepis” oraz paczka z danymi konta.
- **Nie zastępuje opinii prawnika** o oznaczeniach alergenów; ta zostaje
  w #8 (propozycja wdrożenia #1902, punkt 9 decyzji).
- Zakaz claimu „Twoje przepisy nie zginą” (D-333) obowiązuje także tu:
  prowadzący nie mówi, że zapisane na urządzeniu przepisy są kopią zapasową.

## 1. Cel i hipotezy

### Alergeny (#1902)

Nie badamy, czy funkcja jest ładna. Badamy, czy człowiek, który z niej korzysta
przy prawdziwej decyzji („czy mogę to ugotować dla gościa”), **nie wyciągnie
niebezpiecznego wniosku**. Dlatego trzy z czterech progów są zerowe.

| Nr | Hipoteza | Co sprawdzamy |
|---|---|---|
| **H-A1** | Osoby 50+ odróżniają „autor nie zaznaczył” od „nie zawiera” | Czy po przeczytaniu trzech wariantów bloku (lista, „nie zaznaczył żadnego z 14”, „nie sprawdzono”) nikt nie uznaje żadnego z nich za zapewnienie, że przepis jest bez alergenu |
| **H-A2** | Rozumieją „według autora” | Czy mówią, że oznaczenie wpisał autor przepisu, a nie że sprawdził je Kuking albo laboratorium |
| **H-A3** | Filtr, który pomija przepisy niesprawdzone, jest zrozumiały | Czy znajdują filtr bez pomocy; czy rozumieją, że lista **nie jest kompletna** (przepisy bez oznaczenia są pominięte); czy rozumieją pusty wynik |
| **H-A4** (pomocnicza) | Autor potrafi poprawnie oznaczyć własny przepis, w tym pole „Składniki sprawdzone” | Czy zaznaczy właściwe alergeny, zrozumie komunikat o potwierdzeniu i powie, co zobaczy czytelnik |

### Przepisy bez internetu (#1904)

| Nr | Hipoteza | Co sprawdzamy |
|---|---|---|
| **H-O1** | Słaby zasięg w kuchni jest realnym, powtarzalnym problemem | Opis **prawdziwych** sytuacji z ostatnich trzech miesięcy, nie deklaracja „przydałoby się” |
| **H-O2** | Potrzeba jest częstsza i głębsza niż to, co dają „Drukuj przepis” i paczka z danymi | Czy osoba sama zapewnia sobie przepis bez internetu istniejącymi drogami; czy którakolwiek wystarcza; czy zapisane na telefonie przepisy byłyby wybrane zamiast wydruku i z jakiego powodu |
| **H-O3** (pomocnicza) | Ograniczenia zapisanej kopii da się zrozumieć | Czy osoba wie, że po wylogowaniu kopia zniknie i że może być starsza od przepisu na stronie |

Deklaracja „tak, przydałoby się” **nie liczy się** jako dowód potrzeby
(ludzie zgadzają się na funkcje, z których nie korzystają). Liczy się opis
zdarzenia i to, co osoba zrobiła.

### Czego badanie nie dowodzi

Pięć do ośmiu osób nie daje statystyki populacji. Dowodzi natomiast istnienia
problemu: jedna osoba z błędnym wnioskiem o alergenie wystarcza, żeby flagi nie
włączać bez zmian (próg zerowy, §8). Instancja ćwiczeń nie pokazuje, ile
przepisów w prawdziwej społeczności zostanie oznaczonych; filtr na prawdziwych
danych będzie zwracał mniej wyników niż tu. Nie badamy skuteczności słownika
podpowiedzi (etap 2 #1902) ani prawdziwej treści oznaczeń.

## 2. Rekrutacja

- **5–8 osób w wieku 50+**, przekrój 50–59 / 60–69 / 70+ (propozycja: co najmniej
  po jednej osobie w każdym przedziale, w tym co najmniej 2 osoby 70+, jeśli
  liczba osób to 7–8). Rozkład ustala właściciel przed rekrutacją.
- **2–3 osoby z dietą eliminacyjną w domu** (patrz pytanie przesiewowe niżej).
  Pozostałe osoby bez diety. Nie rekrutujemy „alergików”: nie pytamy o chorobę.
- **Własny telefon** (co najmniej po 2 osoby na Androidzie i iPhonie, jeśli liczba
  osób na to pozwala) **albo komputer** (co najmniej 2 osoby z sesją na komputerze).
  Każda osoba przechodzi całość na jednym urządzeniu. Część osób z powiększeniem
  150–200% (naturalne ustawienie osoby; nie przestawiamy czcionki osobie,
  która jej nie potrzebuje, żeby „zaliczyć” powiększenie).
- Co najmniej 2 osoby bez doświadczenia w publikowaniu w internecie (dla H-A4).
- Osoby, które nie brały udziału w rundzie #1818 ani #15 (nie znają ekranów).
- **Prowadzi ktoś inny niż właściciel i inny niż autor zmienianych ekranów**
  (spójnie z #1818). Właściciel nie siedzi na sesji i nie widzi surowych notatek.
- **Nie rekrutujemy rodziny ani współpracowników właściciela.** Zaproszenia
  idą poza repozytorium i poza kontami produkcyjnymi. Lista kontaktów nie trafia
  do repozytorium, do GitHuba ani do notatek z sesji.
- Upominek rzeczowy lub poczęstunek, nie gotówka; wartość ustala właściciel.
  Informujemy o nim w zaproszeniu i nie uzależniamy go od „dobrej opinii”.

### Pytanie przesiewowe o dietę (jedyne, dosłownie)

Zadaje je prowadzący przy umawianiu spotkania, ustnie albo pisemnie:

> „Czy w Twojej kuchni, dla Ciebie lub dla kogoś z domu, na co dzień unika się
> jakiegoś składnika? Odpowiedz tylko: tak albo nie. Nie pytam, z jakiego powodu
> ani o jaki składnik.”

Zapisujemy wyłącznie **tak / nie** (jako cechę rekrutacyjną sesji, nie osoby).
Jeśli osoba sama zacznie mówić o chorobie, rozpoznaniu, lekach lub alergii
dziecka: prowadzący grzecznie przerywa („Dziękuję, nie potrzebuję szczegółów,
to, czy ktoś unika składnika, wystarczy”) i **niczego z tego nie zapisuje**.
Tego samego obowiązuje prowadzący w czasie sesji (§6).

## 3. Zgoda, dane osobowe i prywatność

### Zasady (RODO, minimalizacja)

- **Podstawa:** dobrowolna zgoda uczestnika (art. 6 ust. 1 lit. a RODO),
  udzielona na piśmie przed pierwszą czynnością. Dodatkowo, z ostrożności,
  zgoda obejmuje zdanie o tym, że uczestnik **nie musi** podawać żadnych danych
  o zdrowiu. Badanie **nie zbiera danych o zdrowiu**: pytanie przesiewowe jest
  zamknięte (tak/nie) i nie dotyczy choroby ani diagnozy. Gdyby dane o zdrowiu
  jednak padły w rozmowie, prowadzący ich nie zapisuje (§2).
- **Administrator:** podmiot wskazany w aktualnej polityce prywatności Kuking
  (strona „Prywatność”). Prowadzący wpisuje w formularz zgody nazwę i dane
  kontaktowe administratora z aktualnej wersji polityki, nie z pamięci.
- **Bez nagrań** obrazu, dźwięku i ekranu. **Bez zdjęć** uczestnika i jego
  ekranu. **Bez zapisu** nazwiska, dokładnego wieku (tylko przedział), płci,
  adresu, e-maila, telefonu, loginu, nazw składników unikanych w domu ani
  powodów diety. Kontakt do umówienia wizyty zostaje poza repozytorium i poza
  notatkami.
- **Konta ćwiczeniowe:** uczestnik nie zakłada prawdziwego konta i nie podaje
  własnych danych; loguje się na konto przygotowane przez prowadzącego, w
  instancji ćwiczeń (§4). Poczta i push są tam wyłączone.

### Formularz zgody (do wydruku, min. 14 pt; uczestnik zatrzymuje drugi egzemplarz)

> **Zgoda na udział w badaniu serwisu Kuking**
>
> Badanie polega na wykonaniu kilku zadań w serwisie na koncie ćwiczeniowym i
> krótkiej rozmowie o gotowaniu, trwa do 75 minut. Sprawdzamy serwis, nie Twoje
> umiejętności.
>
> Co zapiszemy: to, co robisz w serwisie i co mówisz o serwisie oraz o tym, jak
> korzystasz z przepisów. Zapisujemy bez nazwiska; dostajesz kod sesji. Nie
> nagrywamy obrazu ani dźwięku i nie robimy zdjęć. Nie pytamy o zdrowie i
> **nie musisz podawać żadnych danych o zdrowiu ani o tym, dlaczego ktoś w domu
> unika jakiegoś składnika**. Jeśli powiesz więcej, niż potrzeba, nie zapiszemy tego.
>
> Kto ma dostęp: prowadzący badanie. Zestawienie wyników bez żadnych danych,
> po których można Cię rozpoznać, może trafić do dokumentacji serwisu.
>
> Jak długo: notatki z sesji usuwamy po zatwierdzeniu zestawienia, najpóźniej po
> 90 dniach. Ten formularz (z Twoim imieniem i nazwiskiem) usuwamy razem z
> notatkami.
>
> Twoje prawa: możesz przerwać w dowolnej chwili, także w trakcie zadania. Możesz
> wycofać zgodę w każdej chwili, także po badaniu: podaj kod sesji
> prowadzącemu, a notatki z tej sesji usuniemy. Wycofanie zgody nie wpływa na
> zgodność z prawem tego, co zrobiono przed wycofaniem. Masz prawo do dostępu do
> danych, sprostowania, usunięcia, ograniczenia przetwarzania i skargi do Prezesa
> Urzędu Ochrony Danych Osobowych. Udział jest dobrowolny; brak zgody nie ma dla
> Ciebie żadnych skutków.
>
> Administrator danych: [nazwa i dane kontaktowe z aktualnej polityki prywatności].
>
> Wyrażam zgodę na udział w badaniu na powyższych zasadach.
> Data: ________  Imię i nazwisko: ________________  Podpis: ________________

**Uwagi dla właściciela:** treść formularza to propozycja organizacyjna, nie
opinia prawna; weryfikacja zostaje w #8. Przed pierwszą sesją właściciel
potwierdza, że formularz może być użyty.

### Kod sesji i wycofanie zgody

- Kod sesji: **R2-S01…** (pilotaż, jeśli jest: R2-P1). Prowadzący wręcza
  uczestnikowi **kartkę z samym kodem**. Nie prowadzi listy „kod → nazwisko”
  ani nie wpisuje kodu do formularza zgody. Dzięki temu wycofanie zgody
  działa po podaniu kodu, a rozpoznanie osoby z notatek nie jest możliwe.
- Formularze zgody są przechowywane **osobno** od notatek (osobna koperta,
  zamykane miejsce prowadzącego) i nie są skanowane do chmury.
- Zgodę zadaje się ustnie jeszcze raz na początku sesji (zdanie z §6).
  Brak zgody na początku = koniec, bez namawiania.

### Gdzie trzymać dane i kiedy je usunąć

| Dane | Gdzie | Kto | Kiedy usunąć |
|---|---|---|---|
| Formularze zgody (imię, nazwisko, podpis) | Papier, zamykane miejsce prowadzącego, osobno od notatek | Prowadzący | Razem z notatkami: po zatwierdzeniu zestawienia, najpóźniej 90 dni po ostatniej sesji |
| Surowe notatki z sesji (karty sesji, §9) | Jedna chroniona kopia u prowadzącego (szyfrowany dysk albo papier); bez chmury i bez repozytorium | Prowadzący | Po zatwierdzeniu zestawienia, najpóźniej 90 dni po ostatniej sesji (spójnie z zatwierdzonym wariantem #1818, D18) |
| Dane kontaktowe (umawianie) | Poza repozytorium, u prowadzącego | Prowadzący | Po ostatniej sesji uczestnika; nie później niż 14 dni po niej |
| Konta ćwiczeniowe i dane demo | Instancja ćwiczeń | Prowadzący | Po zatwierdzeniu zestawienia: usunięcie kont ćwiczeniowych; dane demo nie są danymi osobowymi uczestników |
| Zestawienie bez identyfikatorów (§9 C, D) | Repozytorium (`docs/product/`) | Prowadzący z właścicielem | Nie usuwamy; zawiera tylko liczby i parafrazy bez danych prywatnych |

Naruszenie (zgubiona kartka, dane w złym miejscu, dane o zdrowiu w notatkach):
prowadzący zatrzymuje pracę, niszczy naruszone zapisy i od razu informuje
właściciela; dalsze kroki wg procedury incydentów z dokumentacji serwisu.

## 4. Przygotowanie techniczne (lista prowadzącego i osoby technicznej)

Wersja i dane zamrożone na całą rundę. Wpisz w [kartę rundy](#a-karta-rundy--przed-pierwszą-sesją):
SHA aplikacji, adres instancji, datę próby technicznej.

### 4.1 Staging z włączoną flagą

Badanie działa **wyłącznie na stagingu** (osobna baza, storage i sekrety, wg
[DEPLOYMENT.md](../DEPLOYMENT.md)). Na produkcji flaga zostaje wyłączona.

1. **Kod #1902 na stagingu.** Wariant zalecany: scalenie #1902 do `main` przy
   wyłączonej fladze (tak jest zaprojektowane) i wdrożenie stagingu z tego
   SHA. Wariant awaryjny: wdrożenie samej gałęzi na staging. Wybór i SHA wpisuje
   właściciel w kartę rundy. Nie wdrażaj na produkcję „tymczasowo”.
2. **Migracja** `add_allergens_to_recipes` (kolumny `allergen_status`,
   `allergens`, `allergens_declared_at`) wykonana na stagingu:
   `php artisan migrate:status` w powłoce usługi stagingowej pokazuje ją jako
   wykonaną. Rollback tej migracji odmawia przy danych (D-088); po badaniu
   **nie cofamy** migracji, tylko wyłączamy flagę.
3. **Flaga:** na stagingu ustaw `KUKING_ALERGENY_WLACZONE=true` dla usług `web`
   **i** `worker` (komentarz w `config/kuking.php`: flaga ma być spójna na
   obu rolach). Sprawdź w `.railway/railway.ts` wdrażanego SHA, czy zmienna
   jest przekazywana rolom; jeśli nie, ustaw ją w usługach bezpośrednio. Po
   zmianie zmiennych poczekaj na redeploy.
4. **Poczta, push i integracje zewnętrzne** w instancji ćwiczeń wyłączone
   (spójnie z #15); powiadomienia wewnętrzne działają.
5. **Kontrola flagi na ekranie** (nie z pamięci): na `/szukaj` pod polem frazy
   jest zwijany przycisk „Bez wskazanych alergenów (według autorów)”; na
   stronie przepisu pod składnikami jest blok „Alergeny”; w edycji przepisu
   sekcja „Alergeny (nieobowiązkowe)”. Brak któregokolwiek = STOP przygotowania.
6. **Kontrola odwrotna po badaniu:** `KUKING_ALERGENY_WLACZONE` usunięta lub
   `false`, po redeployu wymienione trzy miejsca znikają (stan domyślny).

### 4.2 Dane demo (jedno źródło prawdy dla całej rundy)

Trzy konta autorskie ćwiczeń (nazwy wymyślone, bez prawdziwych osób). Przepisy
**publiczne**, każdy z krótką, prawdziwą listą składników zgodną z oznaczeniem
(nie wolno zostawić składnika, który jest alergenem, w przepisie oznaczonym jako
bez niego). Oznaczenia ustawia się w edycji przepisu, tak jak ustawi je uczestnik
(to także próba drogi autora). Zdjęcie ćwiczeniowe przy każdym.

**Zupy** (fraza „zupa”; wszystkie muszą zawierać w tytule słowo „zupa” lub
podtytule/opisie frazę „zupa”, żeby wyszukiwarka je znalazła):

| Kod | Przepis | Stan alergenów | Zaznaczone | Uwagi do składników |
|---|---|---|---|---|
| Z-1 | Zupa krem z dyni | zaznaczone (potwierdzone) | mleko, seler | śmietanka, seler naciowy |
| Z-2 | Zupa jarzynowa na wodzie | zaznaczone (potwierdzone), **lista pusta** | brak | marchew, pietruszka, ziemniaki, por, sól, pieprz, woda; **bez kostki i gotowych dodatków** |
| Z-3 | Zupa pomidorowa z makaronem | **nie sprawdzono** | — | bulion z kostki, koncentrat pomidorowy, makaron, oliwa; nazwy składników **nie** wymieniają mleka ani glutenu wprost |
| Z-4 | Zupa barszcz czerwony | zaznaczone (potwierdzone) | seler | buraki, marchew, seler, ocet; bez mleka i glutenu |
| Z-5 | Zupa krupnik z kaszą jęczmienną | zaznaczone (potwierdzone) | gluten, seler | kasza jęczmienna, włoszczyzna |
| Z-6 | Zupa rosół z makaronem | zaznaczone, **potem zmieniono składnik** (stan „do przeglądu”, czytelnik widzi jak „nie sprawdzono”) | — | makaron, włoszczyzna |

Oczekiwany wynik wyszukiwania „zupa” **z filtrem gluten i mleko**: dokładnie
2 przepisy (Z-2, Z-4). Bez filtra: 6.

**Sałatki** (fraza „sałatka”):

| Kod | Przepis | Stan | Zaznaczone |
|---|---|---|---|
| S-1 | Sałatka grecka | zaznaczone | mleko |
| S-2 | Sałatka z tuńczykiem | zaznaczone | ryby, jaja |
| S-3 | Sałatka jarzynowa | nie sprawdzono | — |
| S-4 | Sałatka z kurczakiem | zaznaczone | jaja |

Oczekiwany wynik „sałatka” **z filtrem jaja, ryby i mleko**: **0 przepisów**
(pusty wynik). Bez filtra: 4.

**Przepis do zadania „bez internetu” (O2):** „Kasza jaglana z jabłkami”,
publiczny, 4 składniki, 3 kroki, nie sprawdzono; zapisać adres w karcie danych.

**Konto uczestnika** (jedno na sesję, założone przez prowadzącego, zweryfikowane,
bez blokad i bez obserwowanych): zawiera jeden **prywatny szkic własny**
„Sałatka ćwiczebna” (składniki: 3 jajka na twardo, 2 łodygi selera naciowego,
łyżka majonezu, sól; stan: nie sprawdzono) do zadania Z3 (H-A4). Adres edycji
zapisz w karcie danych.

### 4.3 Urządzenia i powiększenie

- Próba techniczna prowadzącego **na telefonie i na komputerze**, **przy
  powiększeniu 150% i 200%** (tekst systemowy lub powiększenie strony) oraz
  na wąskim ekranie (ok. 320 px). Sprawdź: filtr alergenów rozwija się i da się
  zaznaczyć wszystkie 14 pól i kliknąć „Szukaj”, blok „Alergeny” jest w całości
  czytelny, sekcja w edycji przepisu nie ucina komunikatu o potwierdzeniu.
- Prowadzący ma własny telefon z trybem samolotowym do O3 i wydruk kart do
  czytania (min. 18 px / 14 pt, jedna karta = jedno polecenie).
- Naturalne ustawienia uczestnika: nie zmieniaj jego czcionki, jasności ani
  przeglądarki. Zanotuj faktyczne ustawienia w karcie sesji.

### 4.4 Próba techniczna (warunek rozpoczęcia)

Prowadzący przechodzi **sam, na konto ćwiczeniowe**, wszystkie zadania z §7
na telefonie i komputerze. Potwierdza:

1. trzy warianty bloku „Alergeny” na Z-1, Z-2, Z-3 pokazują **dokładnie** teksty
   z §7.1 (jeśli badane SHA ma inne napisy, wpisz je w kartę rundy: zmieniają
   klucze oceny);
2. filtr daje wyniki z tabeli w §4.2 (2 zupy; 0 sałatek), a zdania nad i pod
   wynikami oraz komunikat pustego wyniku mają brzmienie z §7.2;
3. zapis oznaczenia w edycji: zaznaczone alergeny **bez** pola „Składniki
   sprawdzone” dają komunikat (§7.3), a z nim zapisują się;
4. „Drukuj przepis” otwiera wydruk, a „Przygotuj paczkę z moimi danymi” na
   stronie danych konta (`/ustawienia/twoje-dane`) działa na stagingu
   (jeśli eksport tam nie działa, O2 zapisuje się jako „droga niedostępna w
   instancji”, z uwagą w karcie rundy);
5. po wyłączeniu internetu w telefonie otwarcie przepisu pokazuje stronę
   „bez połączenia” (O3); zapisz jej dokładne brzmienie w karcie rundy.

Brak któregokolwiek punktu = **STOP przygotowania**, nie wynik uczestnika.

## 5. Przebieg sesji i czas

Jedna wizyta, **do 75 minut** z przerwą, potem 15 minut na notatki. Uczestnik
może przerwać w dowolnej chwili. Jeśli osoba jest zmęczona, pomiń zadanie Z3
(H-A4) i zapisz je jako W (niepodjęte, bez oceny osoby).

| Blok | Czas | Zawartość |
|---|---|---|
| Wstęp i zgoda | 5 min | §6, zgoda ustna, wręczenie kodu |
| **Część 1: gotowanie bez internetu** (rozmowa + O2, O3, O4) | do 25 min | §7.4; kolejność celowo **przed** alergenami, żeby rozmowa o zasięgu nie była podszyta tematem alergii |
| Przerwa | 5 min | Bez rozmowy o badaniu |
| **Część 2: alergeny** (Z1, Z2a, Z2b, Z3) | do 35 min | §7.1–§7.3; kolejność wariantów Z1 w rotacji (§7.1) |
| Pytania końcowe | 5 min | §7.5 |

## 6. Skrypt prowadzącego

Prowadzący czyta polecenia **dosłownie** z kart i niczego nie wskazuje. Karta =
jedno polecenie, druk min. 18 px.

**Słowa, których prowadzący nie używa do końca ostatniego zadania:** „filtr”,
„alergeny” (poza cytowaniem dosłownym z ekranu), „według autora”, „nie sprawdzono”,
„bezpieczne”, „gwarancja”, „wydruk”, „paczka”, „offline”, „zapisz na telefonie”,
„tryb samolotowy” (poza O3), „menu”, „ustawienia”. Uczestnik może ich używać
sam; prowadzący powtarza je tylko cytując ekran dosłownie.

**Na początku sesji** (po wręczeniu formularza do przeczytania):

> „Sprawdzamy serwis, nie Twoje umiejętności. Wszystkie konta, przepisy i autorzy
> są wymyśleni do ćwiczenia; nie podawaj własnych danych. Nie nagrywamy i nie
> robimy zdjęć. Zapiszę tylko to, co pomaga lub przeszkadza w wykonaniu zadań,
> bez nazwiska; dostaniesz kartkę z kodem. Nie musisz mówić nic o zdrowiu ani o
> tym, dlaczego ktoś w domu unika jakiegoś składnika; jeśli powiesz więcej, nie
> zapiszę tego. Możesz zrobić przerwę albo skończyć w dowolnym momencie, także
> później poprosić o usunięcie notatek, podając kod. Proszę mówić głośno, czego
> szukasz i co zamierzasz. Nie będę od razu pomagać, bo chcemy zobaczyć, gdzie
> serwis nie daje jasnej drogi. Czy zgadzasz się, żebyśmy zaczęli?”

| Sytuacja | Wolno powiedzieć lub zrobić | Zapis |
|---|---|---|
| Niezrozumiały cel | Powtórz dosłownie polecenie z karty | Q, czas |
| Cisza | Czekaj 10 s; najwyżej raz na zadanie: „O czym teraz myślisz?” | Q |
| „Co mam kliknąć?” | „Co chcesz teraz osiągnąć?” i pozwól próbować | Q, miejsce, słowa |
| „Czy dobrze?” | „Po czym poznasz, że cel został osiągnięty?” | Q |
| Pytanie „czy ten przepis jest bezpieczny?” lub prośba o ocenę treści | „Powiedz, co Ty o tym myślisz i skąd to wiesz.” Nie potwierdzaj ani nie prostuj | Q, dosłowne słowa |
| Rezygnacja lub limit | „Zatrzymajmy to zadanie. Dziękuję, to nam pomaga.” | Stop próby samodzielnej |
| Osoba opowiada o chorobie, diagnozie, lekach, alergii bliskiej osoby | „Dziękuję, nie potrzebuję szczegółów.” Nie zapisuj treści | W |
| Dyskomfort | Zatrzymaj od razu | W |

**Po odpowiedzi nie potwierdzaj i nie prostuj** („tak”, „dokładnie”, „nie do końca”).
Jeśli po zakończeniu badania osoba wciąż pyta, czy dobrze odczytała oznaczenie,
prowadzący mówi po zakończeniu wszystkich zadań i bez zapisu w notatkach:
„Zaznaczenia na stronach robią autorzy; przy gotowych produktach zawsze czytaj
etykietę.” (uczciwa informacja zwrotna po badaniu, bo przykład dotyczy
bezpieczeństwa żywności).

Każda wskazówka o drodze (także przypadkowa) to **H** i wyklucza S; zapisz jej
dosłowną treść i czas. Kody wyników: S samodzielnie, H po wskazówce, N
nieosiągnięte, X nieważna, W niepodjęte pozaproduktowo; Q neutralna wypowiedź,
R reset (jak w #1818).

## 7. Zadania i pytania

### 7.1 Zadanie Z1 — przeczytaj trzy przepisy (H-A1, H-A2), 12 minut

**Stan startowy:** zalogowane konto ćwiczeniowe, strona startowa. Prowadzący
otwiera kolejno (albo uczestnik klika na liście, jeśli tak wygodniej) strony
Z-1, Z-2, Z-3. **Kolejność w rotacji:** sesje nieparzyste Z-1, Z-2, Z-3;
parzyste Z-3, Z-1, Z-2 (zapisz w karcie).

**Czytaj (raz, przed trzema przepisami):**
„Gotujesz w sobotę obiad dla gościa, który unika mleka. Przeczytaj teraz ten
przepis tak, jak czytasz przepis przed gotowaniem.”

Po 60 sekundach (albo gdy osoba powie, że skończyła) pytania, **dla każdej
strony osobno**, bez wskazywania bloku:

1. „Czy ten przepis nadaje się dla tego gościa? Skąd to wiadomo?”
2. Jeżeli osoba **nie wspomniała o bloku „Alergeny”**: „Czy na tej stronie jest
   coś o alergenach? Gdzie?” Zapisz, czy znalazła samodzielnie (S) albo po
   pytaniu (H).
3. „Co znaczy dla Ciebie to, co tam napisano?” (prowadzący czyta dosłownie
   pierwsze zdanie bloku widocznego na ekranie).
4. „Kto to ustalił? Czy ktoś to sprawdził? Kto?”

**Dokładne teksty bloku w badanej wersji** (klucz dla prowadzącego; źródło:
`resources/views/components/alergeny/blok.blade.php` na gałęzi #1902, SHA
`0c91f1acb`; sprawdź w próbie technicznej, §4.4):

| Przepis | Tekst w bloku „Alergeny” |
|---|---|
| Z-1 (lista) | „Alergeny według autora: mleko, seler. To zaznaczenie autora, nie badanie. Gotowe produkty (sosy, kiełbasy, przyprawy, proszek do pieczenia) mogą zawierać alergeny, których tu nie widać — przeczytaj etykiety.” |
| Z-2 (lista pusta) | „Autor nie zaznaczył żadnego z 14 alergenów. To tylko zaznaczenie autora, nie zapewnienie, że ich tam nie ma. Przeczytaj etykiety gotowych produktów.” |
| Z-3 (nie sprawdzono) | „Alergeny: nie sprawdzono. Autor nie zaznaczył, co zawiera ten przepis, więc nie wiemy, czy nadaje się dla osoby z alergią.” |
| wszystkie | zwijany panel „O alergenach na Kuking”: „Oznaczenia wpisują autorzy przepisów. Kuking ich nie sprawdza i nie zastępują one etykiety ani porady lekarza. Nie obiecujemy też, że w przepisie nie ma śladów alergenów ani zanieczyszczeń z innych produktów. Przy gotowych produktach zawsze czytaj etykietę.” oraz „Jeśli widzisz błąd w oznaczeniu, zgłoś przepis i wybierz powód „Błędne oznaczenie alergenów”.” |

**Zapisz też:** czy otwarto panel „O alergenach na Kuking” (bez pomocy), czy
przeczytano zdanie o etykietach, czy sięgnięto po składniki zamiast po blok.

**Klucz oceny.** Kody dla każdej strony osobno, potem zbiorczo:

- **Z-1 poprawne:** mówi, że lista pochodzi od autora (H-A2), że nie wie, czy
  pełna, że na pewno zawiera mleko (nie nadaje się dla gościa), nie wyciąga z tej
  listy, że nie ma innych alergenów.
- **Z-2 poprawne (H-A1, kluczowe):** mówi, że autor **nic nie zaznaczył** albo
  że **nie wiadomo na pewno**, że trzeba sprawdzić składniki lub etykiety;
  NIE mówi, że przepis jest bez alergenów lub bezpieczny dla gościa.
- **Z-3 poprawne (H-A1, kluczowe):** mówi, że **nikt nie sprawdził** albo że
  **nie wiadomo**, nie mówi, że brak oznaczenia to brak alergenów.
- **H-A2 poprawne:** „według autora” rozumie jako: ustalił autor przepisu, bez
  weryfikacji serwisu; nie mówi, że sprawdził Kuking, redakcja, lekarz lub
  laboratorium.

**Błędy krytyczne** (zapisuj niezależnie od częstości, każdy liczy osobę raz):

| Kod | Błędne przekonanie |
|---|---|
| **K-A1** | „Autor nie zaznaczył żadnego z 14 alergenów” rozumiane jako „przepis nie zawiera alergenów / jest bezpieczny / nadaje się dla gościa” |
| **K-A2** | „Nie sprawdzono” rozumiane jako „nie ma alergenów”, „wszystko w porządku” albo brak oznaczenia traktowany jak oznaczenie bez alergenu; albo blok w ogóle zignorowany i wniosek „nie ma ostrzeżenia, więc jest dobrze” |
| **K-A3** | Oznaczenie uznane za sprawdzone przez Kuking, serwis, redakcję, lekarza albo laboratorium |

### 7.2 Zadanie Z2 — szukanie zup i sałatek dla gościa (H-A3), 12 minut

**Z2a. Czytaj:** „Teraz szukasz zup dla gościa, który unika mleka i glutenu.
Znajdź na Kuking zupy, które mogą się nadawać.”

**Start:** strona startowa konta uczestnika (nie ekran szukania).

**Sukces samodzielny (S):** otwiera wyszukiwanie, korzysta z filtra „Bez wskazanych
alergenów (według autorów)” (zaznacza gluten i mleko, klika „Szukaj”). Droga
alternatywna (wpisanie „zupa bez mleka”): zapisz, co zobaczyła i jak ją
zinterpretowała; wyszukiwarka **nie** wyklucza mleka z frazy. Zapisz czas do
znalezienia filtra, błędne drogi, czy wybrała oba alergeny, czy kliknęła „Szukaj”.

**Teksty na ekranie przy aktywnym filtrze** (klucz; źródło: `search.blade.php`
na gałęzi #1902):

- przycisk filtra: „Bez wskazanych alergenów (według autorów)”;
- opis w filtrze: „Zaznacz alergeny, których autor ma nie wskazywać w przepisie, i kliknij „Szukaj”. Pokażemy tylko przepisy, w których autor zaznaczył brak tych alergenów. Przepisy, w których autor nie sprawdził alergenów, są pominięte.”;
- nad wynikami: „Pokazujemy tylko przepisy, w których autor zaznaczył brak: gluten, mleko. Przepisy, w których autor nie sprawdził alergenów, są pominięte.”;
- na karcie: „Autor zaznaczył brak: gluten, mleko”;
- pod wynikami: „To zaznaczenia autorów, nie badania. Przy gotowych produktach zawsze czytaj etykietę.”.

**Pytania po wynikach** (przed jakimkolwiek wyjaśnieniem; zadawane po kolei):

1. „Skąd wzięła się ta lista?”
2. „Czy na tej liście są wszystkie zupy, które się nadają dla tego gościa? Skąd to wiadomo?”
3. „Przepisu z zupą pomidorową, który czytano wcześniej, na tej liście nie ma. Jak to rozumiesz?” (pytanie zadaj tylko, jeśli Z-3 był czytany w Z1)
4. „Czy każdy przepis z tej listy na pewno nie zawiera mleka ani glutenu? Skąd to wiadomo?”
5. „Co zrobisz przed ugotowaniem takiej zupy dla gościa?”

**Klucz oceny Z2a (H-A3):**

- **Znalezienie filtra:** S samodzielnie; H po wskazówce; N nieznalezione. Liczy się
  do progu P5 (§8).
- **Rozumienie listy, poprawne:** mówi, że lista to tylko przepisy, w których
  autor zaznaczył brak tych alergenów, że pominięte są przepisy bez oznaczenia
  (także takie, które mogą się nadawać), oraz że przed gotowaniem sam sprawdzi
  składniki i etykiety.
- **K-A4 (błąd krytyczny):** lista rozumiana jako **pełna** („to wszystkie zupy
  bez mleka i glutenu”) albo **potwierdzona** („na pewno bez mleka i glutenu”,
  „serwis to sprawdził”, „można gotować bez sprawdzania”).

**Z2b. Czytaj:** „Teraz szukasz sałatki dla gościa, który unika mleka, ryb i jaj.
Znajdź takie sałatki.”

Start: dowolny ekran konta; wynik dla osoby używającej filtra jest pusty.
Komunikat pustego wyniku (klucz): „Nie ma przepisów do „sałatka”, w których autor
zaznaczył brak: jaja, ryby, mleko. Spróbuj odznaczyć jeden alergen albo poszukaj
bez filtra i przeczytaj składniki samodzielnie.”

Pytania: „Co to oznacza?” i „Co zrobisz dalej?”

**Klucz oceny Z2b:** poprawne = rozumie, że pusty wynik **nie znaczy**, że takich
sałatek nie ma ani że wszystkie mają te składniki, tylko że żaden autor nie
zaznaczył braku; wybiera rozsądny następny krok (odznaczyć jeden alergen, szukać
bez filtra i czytać składniki). **K-A4** także tutaj, jeśli: „w serwisie nie ma
takich sałatek” albo „wszystkie zawierają mleko, ryby lub jaja”.

Uwaga: osoba, która z Z2a nie używała filtra, dostaje przy Z2b jedno neutralne
pytanie „Czy w tym serwisie jest coś, co pomaga wybierać przepisy ze względu na
składniki?” i dopiero po odpowiedzi ewentualnie wraca do filtra. Wynik Z2b
opisz osobno od Z2a.

### 7.3 Zadanie Z3 — oznacz własny przepis (H-A4), 8 minut

**Czytaj:** „To jest Twoja sałatka do ćwiczenia. W składnikach są jajka, seler
i majonez. Ustaw tak, żeby osoba, która będzie czytać ten przepis, wiedziała,
jakie alergeny są w składnikach. Potem zapisz przepis.”

**Start:** edycja prywatnego szkicu „Sałatka ćwiczebna” (adres w karcie danych).

**Teksty na ekranie** (klucz; źródło: `components/alergeny/pola.blade.php`):
nagłówek „Alergeny (nieobowiązkowe)”; pomoc „Zaznacz alergeny, które są w
składnikach tego przepisu. Jeśli nie wiesz lub nie chcesz, zostaw puste — przy
przepisie pojawi się „nie sprawdzono”.”; pole „Składniki sprawdzone — zaznaczone
alergeny to wszystkie, o których wiem”; komunikat przy zaznaczeniu bez
potwierdzenia: „Zaznaczone alergeny trzeba potwierdzić. Zaznacz pole „Składniki
sprawdzone”, albo odznacz wszystkie alergeny, jeśli nie chcesz ich oznaczać.”;
pod polami: „To Twoje zaznaczenie, nie badanie. Gotowe produkty (sosy, kiełbasy,
przyprawy, proszek do pieczenia) mogą zawierać alergeny, których nie widać w
nazwie — zajrzyj na etykiety.”

Zapisz: które alergeny zaznaczono (jaja, seler; czy także gorczyca z majonezu:
nie oceniamy wiedzy kulinarnej, zapisujemy fakt), czy komunikat o potwierdzeniu
się pojawił i czy osoba go zrozumiała i poprawiła bez pomocy, czy zapisano.

**Pytania po zapisie:**

1. „Co zobaczy teraz osoba, która otworzy ten przepis?” (bez pokazywania strony)
2. „A gdyby w składnikach nie było żadnego z tych alergenów, co trzeba by zrobić,
   żeby to było widać przy przepisie?”

**Klucz oceny (H-A4):** S = zaznaczone właściwe alergeny (jaja, seler) i przepis
zapisany z potwierdzeniem **bez pomocy**; odpowiedź 1 mówi, że czytelnik zobaczy
listę od autora; odpowiedź 2 mówi o zaznaczeniu tylko pola „Składniki sprawdzone”
(albo wprost o tym, że tego nie wie). Zapisz osobno: czy ktoś sądzi, że **pominięcie
alergenów** znaczy „brak alergenów” (K-A2 po stronie autora; liczy do progu P1).

### 7.4 Część 1 — gotowanie bez internetu (H-O1, H-O2, H-O3)

**O1. Rozmowa o prawdziwych sytuacjach** (prowadzący czyta pytania dosłownie,
notuje fakty; pytania pomocnicze tylko gdy wypowiedź jest niejasna):

1. „Przypomnij sobie ostatni raz, kiedy gotowanie odbywało się według przepisu
   z internetu. Na czym był przepis i gdzie to urządzenie wtedy stało?”
2. „W ciągu ostatnich trzech miesięcy: czy zdarzyło się, że przepis był potrzebny
   w kuchni, a internet działał słabo albo wcale? Gdzie to było?”
   - Jeśli tak: „Co wtedy zrobiono? Ile razy to się zdarzyło, mniej więcej: raz,
     dwa albo trzy razy, częściej?”
   - Jeśli nie: „Czy kiedykolwiek przepis trzeba było mieć przy sobie tam, gdzie
     nie ma internetu, na przykład na działce, w domu za miastem, na wyjeździe?”
3. „Jak na co dzień trzymasz przepisy, z których korzystasz w kuchni? Gdzie
   one są?” (zapisz: zeszyt, kartki, wydruki, zrzuty ekranu, zakładki, aplikacja,
   pamięć; dosłownie, bez oceniania)
4. „Czy przepis z internetu był kiedyś drukowany albo przepisywany na kartkę,
   żeby był pod ręką? Jak to się skończyło?”

**Kod potrzeby (wpisuje prowadzący po sesji, osobno od interpretacji):**
**P = tak**, jeśli osoba opisała co najmniej **dwie** konkretne sytuacje z
ostatnich trzech miesięcy, w których brak lub słabość internetu przeszkodziły
w gotowaniu według przepisu. **P = częściowo**: jedna sytuacja albo sytuacje
starsze niż trzy miesiące. **P = nie**: brak sytuacji. „Przydałoby się” bez
opisu sytuacji to **P = nie**.

**O2. Zadanie: przygotuj przepis na wyjazd** (H-O2). **Czytaj:** „Jutro jedziesz
na działkę, gdzie internet prawie nie działa. Chcesz tam ugotować ten przepis.
Zrób tu i teraz wszystko, co uznasz za potrzebne, żeby przepis był do dyspozycji
na miejscu.”

Start: otwarta strona przepisu „Kasza jaglana z jabłkami” na koncie uczestnika.
Limit 6 minut. Uczestnik może użyć **dowolnej drogi**, także spoza serwisu
(zrzut ekranu, zdjęcie ekranu innym urządzeniem, przepisanie na kartkę).

**Weryfikacja wyniku** (prowadzący po deklaracji końca, nie w trakcie): telefon
uczestnika lub komputer przełącza na tryb samolotowy (albo wyłącza sieć) i prosi:
„Pokaż, jak przeczytasz przepis na działce.” Kody:

- **S trwałe:** składniki i kroki dostępne po zamknięciu strony i bez internetu
  (wydruk lub zapis do pliku z okna drukowania, pobrana paczka z danymi, zrzut
  ekranu w galerii, przepisanie na kartkę). Zapisz **którą** drogą: droga z
  serwisu (wydruk, paczka) albo własna.
- **F krucha:** przepis zostawiony otwarty w karcie przeglądarki („nie zamykam”);
  ta droga przepada po zamknięciu karty.
- **N:** brak dostępu do treści bez internetu.
- Do kodu dopisz, czy osoba **sama znalazła** „Drukuj przepis” albo paczkę z
  danymi bez wskazówki (S/H/N).

**O3. Strona bez połączenia** (stan dzisiejszy). Tylko po O2. Prowadzący włącza
tryb samolotowy i prosi: „Otwórz ten przepis jeszcze raz, tak jak zwykle.”
Uczestnik widzi stronę bez połączenia. Pytania: „Co widzisz?” „Co zrobisz?”
Zapisz dosłownie reakcję i brzmienie strony (z karty rundy).

**O4. Trzy możliwości (karta do czytania; uczestnik czyta samodzielnie).**
Prowadzący mówi wcześniej: „Kuking nie ma jeszcze trzeciej możliwości. Sprawdzamy,
czy byłaby w ogóle potrzebna, więc odpowiedź «żadna» jest tak samo dobra jak
każda inna.” Karta:

> **Możliwość 1. Drukuj przepis.** Kartka z przepisem, którą można mieć pod ręką
> bez internetu i bez prądu. Trzeba ją wydrukować z wyprzedzeniem.
>
> **Możliwość 2. Paczka z Twoimi danymi.** Plik do pobrania na komputer lub
> telefon, w którym są Twoje przepisy; czytasz go bez internetu. Przygotowanie
> paczki wymaga internetu i chwili czekania. W paczce są też inne dane Twojego
> konta.
>
> **Możliwość 3 (jeszcze jej nie ma). Zapisane przepisy na telefonie.** Wybrane
> przepisy zapisują się w przeglądarce telefonu i działają bez internetu.
> Zapisuje się je z wyprzedzeniem. Po wylogowaniu znikają z telefonu, a
> zapisana kopia może być starsza od przepisu na stronie.

Pytania (po kolei):

1. „Która z tych możliwości pasuje do sytuacji, o których mówiliśmy? Dlaczego?
   Może żadna?”
2. „Kiedy ostatni raz któraś z nich byłaby potrzebna? Co wtedy stało na przeszkodzie?”
3. „Czego brakuje każdej z nich?”
4. „Co stanie się z przepisem zapisanym na telefonie po wylogowaniu?” (H-O3)
5. „Czy taki zapisany przepis może różnić się od tego, który jest teraz na stronie?
   Dlaczego?” (H-O3)

**Kod wyboru:** zapisz pierwszą wybraną możliwość i uzasadnienie dosłownie.
**Preferencja dla możliwości 3 liczy się**, tylko gdy uzasadnienie odwołuje
się do opisanej wcześniej sytuacji (P = tak albo częściowo), nie do ogólnej
wygody („fajnie mieć”).

**Kod niezaspokojonej potrzeby (U)**, wpisywany po sesji: **U = tak**, gdy
**P = tak** oraz co najmniej jedno z: (a) O2 to N lub F, (b) osoba mówi, że
obecne drogi (wydruk, paczka) nie zadziałałyby w jej sytuacji i opisuje dlaczego,
(c) wybiera możliwość 3 z uzasadnieniem z sytuacji. W przeciwnym razie U = nie.

### 7.5 Pytania końcowe (5 minut)

1. „Co w tym serwisie było dziś niejasne?” (otwarte; zapisz dosłownie, bez danych prywatnych)
2. „Czy w tej części o alergenach czegoś zabrakło?” (także ocena przydatności, bez
   pytania o powody diety)
3. „Gdyby w oznaczeniu alergenów był błąd, co można z tym zrobić?” (zapisz, czy
   osoba wskazuje zgłoszenie przepisu z powodem „Błędne oznaczenie alergenów”)
4. Dla osób z odpowiedzią „tak” na pytanie przesiewowe: „Czy taki sposób pokazania
   alergenów przydałby się w Twojej kuchni? Czego by brakowało?” (**bez pytania o
   składniki i powody**; zapisz tylko opinię o serwisie)

## 8. Kryteria sukcesu i porażki (zapisane przed badaniem)

**N** = liczba **ważnych** sesji (kod X i W nie liczą się do N; brakującą osobę
rekrutuje się dodatkowo, dopóki nie będzie co najmniej 5 ważnych). Progów nie
zmieniamy po poznaniu wyników. Wynik po pomocy (H) nie zalicza progu.

### Alergeny (#1902)

| Próg | Miara | Wymagane |
|---|---|---|
| **P1** | Osoby z K-A1 lub K-A2 (także po stronie autora, §7.3) | **0 osób** (bezwzględnie) |
| **P2** | K-A3 (oznaczenie uznane za sprawdzone przez Kuking) oraz K-A4 (lista filtra uznana za pełną lub potwierdzoną) | **0 osób** (bezwzględnie) |
| **P3** | H-A2: „według autora” rozumiane poprawnie (Z1, wszystkie trzy przepisy) | co najmniej **80% N**, czyli przy N = 5, 6, 7, 8: **4, 5, 6, 7** osób |
| **P4** | H-A3: poprawne rozumienie listy filtra i pustego wyniku (Z2a pytania 2–4 i Z2b) | co najmniej **80% N**: **4, 5, 6, 7** osób |
| **P5** | Znalezienie filtra bez pomocy (Z2a, kod S) | co najmniej **60% N**: **3, 4, 5, 5** osób (nie blokuje włączenia, wymusza poprawkę układu) |
| **P6** | H-A4: poprawne oznaczenie własnego przepisu bez pomocy (Z3, kod S) | co najmniej **60% N**: **3, 4, 5, 5** osób (nie blokuje włączenia, wymusza poprawkę tekstów przy polach) |

Próg P1 i P2 sprawdzamy także **osobno dla osób z dietą eliminacyjną** (2–3 osoby):
jedna osoba z dietą z błędem krytycznym blokuje włączenie niezależnie od reszty.

### Przepisy bez internetu (#1904)

| Próg | Miara | Wymagane |
|---|---|---|
| **O-A** | Liczba osób z **U = tak** (niezaspokojona potrzeba) | patrz tabela decyzji niżej |
| **O-B** | H-O3: poprawne rozumienie, że po wylogowaniu kopia znika **i** może być nieaktualna | co najmniej **80% N**: **4, 5, 6, 7** osób; poniżej: opis kopii w #1904 wymaga przeróbki, zanim cokolwiek powstanie |
| **O-C** | Dla osób z P = tak: czy O2 zakończyło się S trwałym samodzielnie istniejącą drogą z serwisu (wydruk lub paczka) | tylko opis; służy rozstrzygnięciu „wystarcza wydruk” (§10) |

## 9. Arkusze notatek

Kopie formularzy poniżej, na każdą sesję osobno. Nie zapisujemy nazwiska,
dokładnego wieku, płci, głosu, adresów, loginów, prywatnych cytatów, nazw
składników unikanych w domu ani powodów diety.

### A. Karta rundy — przed pierwszą sesją

- Runda / wersja protokołu: R2 / 1.0
- SHA aplikacji na stagingu / adres instancji / wariant wdrożenia kodu #1902 (§4.1):
- Flaga `KUKING_ALERGENY_WLACZONE` na stagingu (web i worker): ustawiona dnia / zmiana w `.railway/railway.ts` potrzebna tak/nie:
- Data próby technicznej (§4.4, punkty 1–5), wynik PASS/STOP:
- Dokładne teksty bloku, filtra, pustego wyniku i strony bez połączenia różniące się od §7:
- Prowadzący (rola, nie nazwisko) / potwierdzenie, że to nie właściciel i nie autor ekranów:
- Rozkład N osób: przedział wieku; telefon (Android/iPhone) albo komputer; powiększenie; dieta (tak/nie):
- Pilotaż (jeśli był): liczba wykonana, co zmieniono w organizacji (nie w produkcie):
- Zatwierdzenie progów §8 i reguł §10 przez właściciela, data (przed sesjami):
- Potwierdzenie formularza zgody przez właściciela, data:

Karta danych (nie pokazuj uczestnikowi): adresy przepisów Z-1…Z-6, S-1…S-4, „Kasza jaglana z jabłkami”, konta autorów, wynik wyszukiwania kontrolnego (2 zupy, 0 sałatek), adres edycji „Sałatka ćwiczebna” dla każdego konta uczestnika.

### B. Karta sesji — kopia na każdą sesję, bez klucza do tożsamości

- Kod sesji: R2-S__ (pilotaż: R2-P__). Zgoda pisemna i ustna: tak/nie; bez nagrań i zdjęć: potwierdzone
- Przedział wieku (50–59 / 60–69 / 70+); urządzenie i system; przeglądarka; powiększenie (100 / 150 / 200 / inne); szerokość ekranu:
- Pytanie przesiewowe o dietę: tak / nie (tylko to)
- Doświadczenie z publikowaniem w sieci (tak/nie):
- Kolejność Z1 (A: Z-1, Z-2, Z-3 / B: Z-3, Z-1, Z-2):
- Odstępstwa od przygotowania i protokołu:

| Zadanie | Start zgodny / R | Wynik (S/H/N/X/W) | Czas s | H tak/nie, treść | Kryteria spełnione / brakujące | Uwagi (bez danych prywatnych) |
|---|---|---|---|---|---|---|
| O1 rozmowa (kod P: tak / częściowo / nie) | | | | | | |
| O2 przepis na wyjazd (S trwałe / F krucha / N; droga) | | | | | | |
| O3 strona bez połączenia | | | | | | |
| O4 wybór możliwości i uzasadnienie | | | | | | |
| O4 pytania 4–5 (znika po wylogowaniu, może być nieaktualny) | | | | | | |
| Z1 Z-1 (lista): dla kogo / kto ustalił | | | | | | |
| Z1 Z-2 (lista pusta): K-A1 tak/nie | | | | | | |
| Z1 Z-3 (nie sprawdzono): K-A2 tak/nie | | | | | | |
| Z1 „według autora”: K-A3 tak/nie | | | | | | |
| Z2a filtr: znalezienie (S/H/N), czas | | | | | | |
| Z2a rozumienie listy: K-A4 tak/nie | | | | | | |
| Z2b pusty wynik: K-A4 tak/nie | | | | | | |
| Z3 oznaczenie własnego przepisu | | | | | | |
| Pytania końcowe | | | | | | |

- Dosłowne odpowiedzi na pytania Z1 (1, 3, 4) dla Z-1, Z-2, Z-3 (bez danych prywatnych):
- Dosłowne odpowiedzi Z2a (1, 2, 3, 4, 5) i Z2b:
- Dosłowne odpowiedzi O1 (1–4) i O4 (1–5):
- Czy otwarto panel „O alergenach na Kuking”; czy przeczytano zdanie o etykietach:
- Kod U (niezaspokojona potrzeba), uzasadnienie (fakt, nie interpretacja):
- Gdzie padło „co mam kliknąć?” (ekran i element):
- Co działało samodzielnie i należy zachować:
- Bloker / kwestia techniczna / pytanie produktowe:

| Zadanie i sekunda | Działanie widoczne na ekranie | Cytat bez danych prywatnych | Q/H/R i dosłowne słowa prowadzącego | Interpretacja (hipoteza, osobno) |
|---|---|---|---|---|
| | | | | |

### C. Zestawienie rundy — tylko sesje odbyte

N ważnych: ____ (w tym z dietą eliminacyjną: ____)

| Próg | Wymagane przy tym N | Wynik | Zaliczony tak/nie | Osoby z dietą osobno |
|---|---|---|---|---|
| P1 K-A1 lub K-A2 | 0 | | | |
| P2 K-A3 lub K-A4 | 0 | | | |
| P3 „według autora” | | | | |
| P4 filtr i pusty wynik | | | | |
| P5 znalezienie filtra | | | | |
| P6 oznaczenie własnego przepisu | | | | |
| O-A niezaspokojona potrzeba (liczba osób U = tak) | wg §10 | | | |
| O-B znika i bywa nieaktualne | | | | |
| O-C wystarcza wydruk lub paczka | opis | | | |

| ID problemu | Fakt / dowód (sesja, zadanie, sekunda) | Dotknięte osoby | Bloker i dlaczego | Skutek | Hipoteza przyczyny | Co działało | Dalsze sprawdzenie |
|---|---|---|---|---|---|---|---|
| | | | | | | | |

### D. Rekomendacja (po zatwierdzeniu reguł §10)

| Pytanie | Wynik progów i dowody | Rekomendacja | Decyzja właściciela i data |
|---|---|---|---|
| Włączyć `KUKING_ALERGENY_WLACZONE` | | | |
| Budować #1904 (MVP) | | | |

- Nierozwiązane blokery; wymagane ponowne sesje:
- Zmiany tekstów do rozważenia (fakty, nie interpretacje):
- Zestawienie bez danych identyfikujących opublikowane tak/nie, data:
- Notatki surowe i formularze zgody usunięte (data):

W repozytorium publikuj dopiero zestawienie bez informacji identyfikujących.

## 10. Co zrobić z wynikami

Reguły poniżej to **propozycja decyzyjna**; właściciel zatwierdza je przed
pierwszą sesją albo zmienia. **Po sesjach ich nie zmieniamy.** Wpis o wynikach
trafia do [DECISIONS.md](../DECISIONS.md) jako osobny `D-xxx` (pierwszy wolny
numer, po sprawdzeniu wszystkich gałęzi), a wybór flagi wykonuje właściciel.

### Alergeny: włączenie flagi

| Wynik | Decyzja |
|---|---|
| P1 i P2 = 0 osób **i** P3, P4 zaliczone (P5 i P6 bez wpływu na włączenie) | **Proponujemy włączyć** `KUKING_ALERGENY_WLACZONE=true` na produkcji (web i worker). Nadal obowiązuje opinia prawnika przed pierwszymi prawdziwymi użytkownikami (#8) i zdanie w regulaminie (kod #1902). Niezaliczone P5 lub P6 zapisz jako zadanie poprawki (układ filtra, teksty przy polach), nie jako blokadę |
| P1 lub P2 u **1–2 osób** albo P3/P4 niezaliczone | **Flaga zostaje wyłączona.** Zmiana brzmienia tego bloku, który zawiódł (na przykład tekstu „Autor nie zaznaczył żadnego z 14 alergenów”, który sam może być czytany jako zapewnienie), i **druga runda** na co najmniej 3 nowych osobach (w tym 1 z dietą) tylko dla zmienionego elementu. Zmiany tekstów wymagają decyzji właściciela i wpisu w DECISIONS |
| P1 lub P2 u **3 i więcej osób** albo u połowy N lub więcej | **Flaga zostaje wyłączona**; koncepcja wymaga rozmowy z właścicielem (opcje: usunięcie filtra i zostawienie samej etykiety na przepisie, zmiana sposobu pokazywania, rezygnacja z funkcji). Bez nowej rundy, dopóki właściciel nie wybierze kierunku |

Niezależnie od wyniku: **żadne „bezpieczny”, „dla alergików”, „bez alergenów”
nie trafia do interfejsu** (lista zakazanych słów w #1902).

### Przepisy bez internetu: czy budować MVP #1904

Mianownik: N ważnych. Liczba osób z U = tak: **k**.

| Liczba ważnych sesji N | Budować MVP (k co najmniej) | Powtórzyć z 3–4 nowymi osobami (k) | Nie budować (k co najwyżej) |
|---|---|---|---|
| 5 | 3 | 2 | 1 |
| 6 | 4 | 2–3 | 1 |
| 7 | 4 | 2–3 | 1 |
| 8 | 5 | 3–4 | 2 |

- **„Budować MVP”** znaczy: proponujemy właścicielowi wpis `D-xxx`
  przenoszący #1904 z „V2, ale nie teraz” do „wolno budować” (wraz ze zmianą w
  [FEATURES.md](../FEATURES.md)), **pod warunkiem** progu O-B (opis ograniczeń
  zrozumiały). Przy niezaliczonym O-B najpierw poprawa opisu kopii.
- **„Nie budować”** znaczy: #1904 zostaje na liście „V2, ale nie teraz”, a
  potrzebę (jeśli jest) zaspokaja tańsze rozwiązanie. Jeśli O-C pokazuje, że
  osoby z P = tak **nie znajdują** „Drukuj przepis” albo paczki, pierwszą
  zmianą jest widoczność istniejących dróg (instrukcja i miejsce przycisków), nie
  nowy mechanizm.
- **„Powtórzyć”** znaczy: za mało dowodu; dokładamy 3–4 osoby, dla których
  kryterium rekrutacji to **realna sytuacja z ostatnich trzech miesięcy**
  (działka, dom za miastem), bo to tam potrzeba powinna wyjść najmocniej.
- Niezależnie od progu: MVP #1904 wymaga jeszcze pozostałych decyzji z
  propozycji (D2–D15: zakres, limity, prywatność na urządzeniach
  współdzielonych), a test na prawdziwym iPhonie jest obowiązkowy przed
  pierwszym wydaniem. Wynik tego badania **nie zastępuje** tych decyzji.

## 11. Czego trzeba od właściciela przed pierwszą sesją

1. Zatwierdzenie progów §8 i reguł §10 (przed sesjami).
2. Zatwierdzenie formularza zgody i wyboru prowadzącego innego niż właściciel.
3. Wybór wariantu wdrożenia kodu #1902 na staging (§4.1) i ustawienie flagi na
   stagingu (zmienne w Railway robi właściciel).
4. Rekrutacja uczestników poza repozytorium, rozkład osób (§2), upominek.
5. Wpis o decyzji z 30.09 i, po badaniu, o jego wyniku w DECISIONS (`D-xxx`).
