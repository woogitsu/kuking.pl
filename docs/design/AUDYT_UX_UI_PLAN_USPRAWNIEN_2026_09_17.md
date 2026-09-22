# Kuking.pl — audyt UX/UI i plan usprawnień

**Zakres:** wygląd, architektura informacji, funkcjonalność interfejsu, dostępność, spójność marki i utrzymanie warstwy prezentacji.  
**Wersja raportu:** 1.0, rozwinięcie przeglądu z 17 września 2026 r.  
**Repozytorium:** [woogitsu/kuking.pl](https://github.com/woogitsu/kuking.pl).  
**Punkt odniesienia:** commit [`81096be714b99eaa02b9e1f13fe5cae81c0c4b90`](https://github.com/woogitsu/kuking.pl/commit/81096be714b99eaa02b9e1f13fe5cae81c0c4b90), „Popraw stan obserwowania na listach osób (#650)”.  
**Status dokumentu:** analiza i propozycja prac; bez zmian w kodzie, nowych issues, pull requestów ani wdrożeń.

> **Rekomendacja główna:** zachować tożsamość Kuking i poprawić sposób korzystania z istniejących funkcji. Największy potencjał mają: lżejszy Start na telefonie, bardziej uporządkowane karty, prostsze dodawanie zdjęcia, czytelniejsze wyszukiwanie i rozdzielenie czynności przed gotowaniem od pokazania gotowego dania. Najpierw naprawić potwierdzone nieścisłości, następnie weryfikować propozycje wizualne na prawdziwych ekranach i z użytkownikami.

## Spis treści

1. [Jak czytać raport](#1-jak-czytać-raport)
2. [Wnioski i kolejność działań](#2-wnioski-i-kolejność-działań)
3. [Tożsamość produktu i elementy do zachowania](#3-tożsamość-produktu-i-elementy-do-zachowania)
4. [Inwentaryzacja obecnego systemu wizualnego](#4-inwentaryzacja-obecnego-systemu-wizualnego)
5. [Potwierdzone problemy i rozbieżności](#5-potwierdzone-problemy-i-rozbieżności)
6. [Strona powitalna](#6-strona-powitalna)
7. [Start po zalogowaniu](#7-start-po-zalogowaniu)
8. [Nawigacja](#8-nawigacja)
9. [Karta wpisu i fotografia](#9-karta-wpisu-i-fotografia)
10. [Dodawanie zdjęcia](#10-dodawanie-zdjęcia)
11. [Wyszukiwanie](#11-wyszukiwanie)
12. [Strona przepisu](#12-strona-przepisu)
13. [Tryb gotowania](#13-tryb-gotowania)
14. [Zeszyty i zapisywanie](#14-zeszyty-i-zapisywanie)
15. [Profile i relacje](#15-profile-i-relacje)
16. [Rejestracja i pierwsza wizyta](#16-rejestracja-i-pierwsza-wizyta)
17. [Powiadomienia i komunikaty](#17-powiadomienia-i-komunikaty)
18. [Specyfikacja kierunku wizualnego](#18-specyfikacja-kierunku-wizualnego)
19. [Dostępność i responsywność](#19-dostępność-i-responsywność)
20. [Wydajność i utrzymanie kodu](#20-wydajność-i-utrzymanie-kodu)
21. [Pomiar efektów i badania](#21-pomiar-efektów-i-badania)
22. [Backlog i plan wdrożenia](#22-backlog-i-plan-wdrożenia)
23. [Gotowe opisy pierwszych zadań](#23-gotowe-opisy-pierwszych-zadań)
24. [Decyzje do aktualizacji i zakres wyłączony](#24-decyzje-do-aktualizacji-i-zakres-wyłączony)
25. [Referencje](#25-referencje)

## 1. Jak czytać raport

### 1.1. Poziomy pewności

| Oznaczenie | Znaczenie | Czego nie oznacza |
|---|---|---|
| **K — kod** | Stan potwierdzony w odczytanym kodzie lub konfiguracji | Nie dowodzi wykonania konkretnej ścieżki w przeglądarce |
| **D — dokumentacja** | Zasada lub decyzja zapisana w repozytorium | Nie dowodzi kompletnego wdrożenia ani poprawności runtime |
| **H — HTML produkcji** | Element wystąpił w pobranym HTML strony publicznej | Nie dowodzi jego widoczności, wymiarów ani końcowego stylu |
| **P — propozycja** | Rekomendacja projektowa oparta na celu produktu | Nie jest wynikiem eksperymentu ani potwierdzonym błędem |
| **W — wymaga weryfikacji** | Ryzyko, pytanie lub hipoteza do zbadania | Nie należy prezentować go jako wykrytej awarii |

Priorytet i pewność to różne rzeczy. Propozycja o dużym potencjale może nadal wymagać badań. Potwierdzona rozbieżność w podpisie może być łatwa do naprawienia, ale nie blokować publikowania.

### 1.2. Co rzeczywiście sprawdzono

- Reguły projektu w `AGENTS.md`, konstytucję marki, wybrane decyzje i dokumenty UX.
- Tokeny, bazowy CSS i wybrane arkusze warstwy marki.
- Widoki strony publicznej i Startu, wspólny layout oraz kartę wpisu.
- Dodawanie zdjęcia, wyszukiwarkę, stronę przepisu i tryb gotowania.
- Dodatkowo: zeszyty, kartę przepisu, wybór zeszytu, profil, rejestrację i powiadomienia.
- Kontroler oraz zapytania wyszukiwania, wybrane fragmenty kontrolerów przepisów i zeszytów, mechanizm zapisu sygnałów analitycznych.
- Odpowiedź HTTP i HTML publicznej strony głównej. W pobranym HTML były rzeczywiste wpisy, autorzy i zdjęcia, a nie wyłącznie pusty landing.
- Oficjalne objaśnienia wybranych kryteriów WCAG oraz dokumentację Core Web Vitals.

Pliki kluczowe dla wniosków zostały ponownie odczytane z przypiętego commita. Linki repozytorium w tym dokumencie wskazują tę wersję; późniejsze zmiany mogą usuwać opisane problemy. Dokumenty opisujące markę służą także odtworzeniu intencji. Nie zastępują oglądu aplikacji.

### 1.3. Czego nie sprawdzono

- Nie wykonano kompletnego audytu wszystkich plików repozytorium.
- Nie uruchomiono testów aplikacji, builda ani migracji.
- Nie wykonano pomiarów Lighthouse, rzeczywistego LCP/INP/CLS ani transferu assetów.
- Nie zalogowano się do produkcji i nie publikowano treści.
- Nie przeprowadzono badań z użytkownikami.
- Nie uzyskano zrzutów aktualnej strony w przeglądarce: lokalny silnik przeglądarki nie był dostępny, a próba jego pobrania nie doprowadziła do uruchomienia oglądu.
- Nie sprawdzono obecnego backlogu issue po issue. Przed utworzeniem nowych zadań trzeba sprawdzić duplikaty i status wcześniejszych prac.

**Wniosek:** raport jest szczegółowym przeglądem kodu interfejsu i specyfikacją kierunku zmian. Nie jest certyfikatem dostępności, pełnym audytem bezpieczeństwa ani potwierdzeniem wizualnej poprawności produkcji.

## 2. Wnioski i kolejność działań

### 2.1. Najważniejsze ustalenia

1. **Marka jest już określona.** Paleta, znak, font, nawigacja i kompozycje mają opisane zasady. Kolejny całkowity redesign byłby kosztowny i zwiększał ryzyko niespójności.
2. **Najwięcej można zyskać na hierarchii.** Główna czynność powinna być łatwa do zauważenia, a treść społeczności dostępna szybko. Nie wymaga to zmniejszenia liter ani ukrywania ważnych funkcji.
3. **Występuje błąd semantyczny liczników.** Liczba wykonań jest w jednym miejscu opisywana jako liczba osób, choć jedna osoba może gotować ten sam przepis wiele razy.
4. **Nazwy części odnośników nie odpowiadają dokładnie ich celom.** „Przepisy” i „Poszukaj przepisów” kierują do ogólnego strumienia.
5. **Przepis eksponuje dokonanie przed rozpoczęciem.** „Ugotowałem” ma styl główny, a „Gotuję” drugorzędny. To propozycja zmiany hierarchii, nie techniczna awaria.
6. **Wyszukiwarka miesza dwa wymiary wyboru.** Typ wyniku i czas przygotowania są przedstawiane w jednym rzędzie.
7. **Wiele pozornie nowych pomysłów już istnieje.** Tryb gotowania, minutnik, zeszyty, własne ustawienia czytelności i szkice trzeba dopracować oraz dobrze pokazać, a nie implementować po raz drugi.
8. **Utrzymanie stylów wymaga porządku.** Rozbudowane pliki bazowe i nadpisania zwiększają koszt przewidywania efektu zmiany. Sama liczba bajtów kodu nie dowodzi problemu wydajności.

### 2.2. Rekomendowana kolejność

| Kolejność | Zakres | Dlaczego teraz |
|---|---|---|
| 1 | Liczniki wykonań i etykiety odnośników | Konkretne nieścisłości, stosunkowo wąski zakres |
| 2 | Mobilny Start, karta wpisu, publikacja zdjęcia | Główna codzienna ścieżka produktu |
| 3 | Wyszukiwanie i strona przepisu | Łatwiejsze przejście od inspiracji do gotowania |
| 4 | Zapis i zeszyty | Powrót do wartościowych treści |
| 5 | Profil, rejestracja, komunikaty | Spójność całego doświadczenia |
| 6 | Porządkowanie CSS w zmienianych obszarach | Ograniczenie przyszłych regresji bez wielkiej przebudowy |

Nie podaję procentowej prognozy wzrostu konwersji ani retencji. Bez pomiaru bazowego byłaby pozorna. Efekt powinien być oceniany przez skuteczność wykonania zadań i zachowanie ludzi, a nie liczbę zmienionych ekranów.

## 3. Tożsamość produktu i elementy do zachowania

### 3.1. Rdzeń

Kuking jest społecznością wokół codziennego gotowania. Pełny przepis nie jest warunkiem uczestnictwa. Zdjęcie i kilka słów są samodzielną, wartościową formą publikacji. To rozstrzygnięcie wynika z [reguł projektu][R01] i [konstytucji marki][R02].

Trzy podstawowe potrzeby:

| Potrzeba | Przykład sytuacji | Najważniejsza droga |
|---|---|---|
| Pokazać własne gotowanie | „Zrobiłem dziś zupę i chcę się nią podzielić” | Dodaj zdjęcie → opis → Opublikuj |
| Znaleźć i zachować pomysł | „Potrzebuję czegoś na obiad” | Szukaj / strumień → przepis → Zapisuję |
| Wrócić z efektem | „Przygotowałem danie według tego przepisu” | Gotuję → Ugotowałem → zdjęcie / uwaga |

### 3.2. Zachować

- Garnek z pokrywką w formie korony i uśmiechem.
- Dwukolorowy logotyp i aktualne reguły używania nazwy.
- Lokalny Inter i czytelne pismo.
- Neutralne jasne powierzchnie, grafit i czerwony akcent.
- Widoczne podpisy ważnych czynności.
- Chronologię strumienia obserwowanych.
- „Pokaż więcej” zamiast przymusowego nieskończonego przewijania.
- Rolę „Ugotowałem” jako realnego wykonania, a nie odpowiednika polubienia.
- Prywatność wpisów i zeszytów, reguły blokad oraz widoczności treści.
- Autentyczne autorstwo i oznaczanie treści przykładowych.
- Brak presji na codzienną publikację i brak rankingów użytkowników.

### 3.3. Nie utożsamiać grupy 50+ z jednym zachowaniem

Duża czcionka i duże cele dotykowe są standardem produktu. Nie wynika z tego, że każdy użytkownik potrzebuje długich instrukcji przy każdym przycisku. O potrzebie podpowiedzi powinny decydować trudność zadania i obserwacja użytkowników.

Przykład: przy zwykłym polu wyszukiwania krótka etykieta i dobry przykład mogą wystarczyć. Przy wyborze publicznej widoczności krótkie wyjaśnienie skutku jest naprawdę potrzebne.

## 4. Inwentaryzacja obecnego systemu wizualnego

### 4.1. Paleta potwierdzona w tokenach

Wartości z [resources/css/tokens.css][R05]. To odczyt deklaracji, nie pomiar wszystkich końcowych kombinacji kolorów w przeglądarce.

| Rola | Jasny motyw | Ciemny motyw / ciemny blok |
|---|---|---|
| Tło | `#F3F4F1` | `#151714` |
| Powierzchnia podniesiona | `#FFFFFF` | `#222620` |
| Powierzchnia zagłębiona | `#ECEEE9` | `#10120F` |
| Tekst główny | `#151714` | `#F4F5F1` |
| Tekst drugorzędny | `#555E53` | `#CBD0C6` |
| Akcent marki / link | `#BE3025` | `#FF9586` |
| Wypełnienie głównej akcji | `#BE3025` | `#C83B2E` |
| Mocne obramowanie | `#737A70` | `#929A8C` |
| Fokus | `#155EEF` | `#6EA8FF` |

**Rekomendacja:** nie zmieniać całej palety. Doprecyzować role. Czerwony akcent ma wskazywać czynność lub wybór; duże powierzchnie powinny pozostać spokojne. Koloru błędu nie utożsamiać automatycznie z każdą czerwoną akcją.

### 4.2. Typografia

| Token | Wartość przy domyślnym 16 px dla `rem` i skali aplikacji 100% |
|---|---:|
| `--text-meta` | 15 px |
| `--text-help` | 16 px |
| `--text-body` | 18 px |
| `--text-body-lg` | 20 px |
| `--text-lead` | 22 px |
| `--text-title-sm` | 24 px |
| `--text-title` | 28 px |
| `--text-title-lg` | 36 px |
| `--text-title-xl` | 48 px |

Poszczególne komponenty mają nadpisania, a użytkownik może świadomie zmieniać skalę. Tabela nie jest listą faktycznych rozmiarów każdego tekstu. Domyślne 18 px i minimum 48 px dla głównych kontrolek to zasady Kuking, a nie uniwersalne minima WCAG. [Standard projektu][R04].

### 4.3. Kompozycja

W warstwie marki występują m.in.:

- zaokrąglona, odsunięta od krawędzi belka;
- szerokość głównej ramy do 1120 px;
- szeroka kompozycja z prawą kolumną 330 px i odstępem 40 px;
- duże promienie powierzchni, często 24–26 px;
- ciemny kafel publikacji z dekoracyjnym pierścieniem;
- pływająca dolna nawigacja mobilna;
- osobne reguły dla dużego tekstu i niskiego okna.

Źródło: [marka-rama.css][R07]. Szerokości są punktami odniesienia. Nie należy wymuszać dwóch kolumn, gdy powiększenie tekstu lub dostępna przestrzeń tego nie pozwalają.

## 5. Potwierdzone problemy i rozbieżności

### F-01. Wykonania są opisywane jako osoby

**Status:** K. **Priorytet:** P1. **Pewność:** wysoka dla niezgodności semantycznej; bez odtworzenia runtime.

W [RecipeController][R17] `cookedCount` pochodzi z `$cookedEvents->total()`. Liczniki `zrobiaPonownie` i `oceniloWykonanie` również liczą rekordy wykonań, bez deduplikacji osób. Tymczasem [widok przepisu][R16] używa m.in. zdania „N osób ugotowało to danie” oraz „X z Y osób zrobi to ponownie”.

Projekt jawnie dopuszcza wiele wykonań tego samego przepisu przez tę samą osobę. Nie wolno naprawiać tego przez zakaz kolejnych wykonań. [AGENTS.md][R01].

**Przypadek odtwarzający:**

| Osoba | Liczba wykonań | Odpowiedzi „zrobię ponownie” |
|---|---:|---|
| A | 3 | tak, tak, tak |
| B | 1 | nie |
| Razem | 4 wykonania, 2 osoby | 3 odpowiedzi „tak” na 4 odpowiedzi |

Obecna konstrukcja może opisać ten stan jako 4 osoby i 3 z 4 osób. W rzeczywistości są 2 osoby. To także oznacza, że próg trzech ocenionych wykonań nie musi oznaczać opinii trzech różnych ludzi.

**Wariant A — szybka, wąska poprawka:**

- Używać „Ugotowane 4 razy” lub „4 wykonania”.
- Dla ponownego gotowania mówić o odpowiedziach: „W 3 z 4 odpowiedzi zaznaczono «zrobię ponownie»”.
- Zachować liczenie zdarzeń i aktualne filtrowanie widoczności.
- Nie przedstawiać tej statystyki jako liczby niezależnych osób.

**Wariant B — statystyka osób:**

- Osobno liczyć wykonania i unikalnych autorów wykonań.
- Określić regułę dla osoby, która najpierw odpowiedziała „nie”, a potem „tak”: np. ostatnia niepusta odpowiedź wśród widocznych wykonań.
- Próg ujawnienia statystyki oprzeć na unikalnych osobach.
- Dopiero wtedy zachować sformułowanie „X z Y osób”.

**Rekomendacja:** A jako szybka korekta prawdziwości; B jako odrębna decyzja produktowa. Wersja B zmienia znaczenie miary i wymaga spójności wszędzie, gdzie jest używana.

**Kryteria odbioru:**

- Jedna osoba z trzema wykonaniami nie staje się trzema osobami w tekście.
- Galeria nadal zawiera wszystkie uprawnione wykonania.
- Filtry blokad i widoczności działają przed agregacją.
- Paginacja nie zmienia globalnego licznika.
- Tekst poprawnie odmienia wartości 0, 1, 2, 5, 12, 22.
- Dane strukturalne opisujące liczbę zdarzeń nie są automatycznie zmieniane w liczbę osób.

### F-02. „Przepisy” prowadzi do ogólnej aktywności

**Status:** K. **Priorytet:** P1/P2, zależnie od wyników użyteczności.

W [szczególe przepisu][R16] okruszek „Przepisy” prowadzi do `route('discover')`. W [zeszytach][R19] i [pustym folderze][R20] „Poszukaj przepisów” prowadzi do tej samej trasy. [Ekran odkrywania][R10] opisuje ogólny chronologiczny strumień, nie wyłącznie przepisy.

**Nie jest to martwy odnośnik.** Problemem jest rozbieżność obietnicy etykiety z zakresem celu.

Możliwe rozwiązania:

1. Dla okruszka użyć nazwy odpowiadającej publicznemu strumieniowi.
2. Dla pustego zeszytu użyć `search` z zakresem `przepisy` i etykiety „Szukaj przepisu”.
3. Jeżeli celem ma być katalog przepisów bez wpisywania frazy, zaprojektować go jawnie jako nowe zachowanie.

**Istotny szczegół:** sama zmiana adresu na `search?sekcja=przepisy` nie tworzy katalogu. Obecny widok przy pustej frazie pokazuje podpowiedzi i tagi, a zapytanie wyszukiwania nie zwraca przepisów dla fraz krótszych niż dwa znaki. [Kontroler][R14], [zapytania][R15].

**Kryteria odbioru:** podpis, cel i nagłówek strony pasują do siebie; gość ma działającą drogę; zachowanie przy pustej frazie jest opisane i przetestowane.

### F-03. Komentarz o kolażu nie odpowiada aktualnej warstwie marki

**Status:** K. **Priorytet:** P2.

Komentarz w [landing.blade.php][R08] uzasadnia `loading="lazy"` m.in. tym, że kolaż jest ukryty poniżej 64rem. Bazowe [strony-publiczne.css][R29] rzeczywiście zawierają regułę ukrywania. Jednak [marka-ekrany.css][R30] ustawia `.hero-kolaz-blok { display: block; }` w warstwie marki.

Wniosek nie brzmi „kolaż jest popsuty na telefonie”. Wniosek brzmi: **nie można polegać na historycznym komentarzu przy podejmowaniu decyzji o aktualnym układzie i ładowaniu zdjęć**.

Poprawa:

- ustalić finalny computed style w obu motywach;
- opisać bieżące zachowanie w jednym miejscu;
- usunąć nieaktualne uzasadnienie;
- ładowanie obrazów ocenić na podstawie faktycznej pozycji w ekranie i pomiaru LCP.

### F-04. Jedna funkcja ma różne nazwy

**Status:** K + D. **Priorytet:** P2. **To nie jest automatycznie błąd implementacji.**

| Funkcja | Warianty w interfejsie |
|---|---|
| Zapisane treści | „Moje”, „Mój zeszyt”, „Twój zeszyt” |
| Publiczny strumień | „Odkrywaj”, „Świeżo z Kuking” |
| Dodawanie | Ogólne „Dodaj”, bezpośrednie „Dodaj zdjęcie”, „Dodaj przepis” |

Różne poziomy ogólności mogą być uzasadnione. „Dodaj” jako bramka do dwóch typów treści ma sens. „Moje” obok „Profil” jest mniej jednoznaczne. „Odkrywaj” jest świadomą decyzją D-207; nie należy usuwać go pod pretekstem starego komentarza, który mówił o zakazie tej nazwy. [Layout][R11], [decyzje][R03].

## 6. Strona powitalna

### 6.1. Stan obecny

**K + H:** hero i kolaż → trzy kroki → „Ugotowałem” → publiczna tablica → świeże wpisy → własne dane i zeszyty → rejestracja. [Widok][R08]. Kolejność wynika z wcześniejszych decyzji marki, szczególnie D-208 i D-215.

**P:** treści rzeczywistych ludzi warto pokazać wcześniej. Serwis społecznościowy można zrozumieć przez przykłady, zanim przeczyta się kilka objaśnień jego działania.

### 6.2. Proponowana kompozycja

| Pozycja | Blok | Zadanie |
|---|---|---|
| 1 | Krótkie hero z prawdziwymi zdjęciami | Wyjaśnić, co można tu zrobić |
| 2 | „Co się dziś gotuje” | Pokazać realne dania i autorów |
| 3 | Trzy kroki | Wyjaśnić prostotę udziału |
| 4 | „Ugotowałem” | Pokazać najważniejszą relację wokół przepisu |
| 5 | Wybrane świeże wpisy | Umożliwić dalsze oglądanie |
| 6 | Zeszyty, widoczność i dane | Odpowiedzieć na pytania o korzystanie |
| 7 | Zaproszenie do konta | Dać drogę do udziału |

To wariant do oceny, nie nakaz przeniesienia wszystkich wpisów pod pierwszy nagłówek. Publiczna strona może zachować narracyjny charakter.

### 6.3. Przykłady tekstów do rozważenia

| Miejsce | Propozycja | Uwaga |
|---|---|---|
| Krótszy opis hero | „Pokaż zdjęcie swojego dania, zapisz pomysły i porozmawiaj z innymi.” | Nie obiecuje odpowiedzi konkretnej osoby |
| Wejście do treści | „Zobacz, co gotują inni” | Bardziej opisowe niż ogólne „Rozejrzę się” |
| Informacja o publikacji | „Zdjęcie i kilka słów wystarczą.” | Spójne z rdzeniem produktu |
| Rejestracja | Zachować zaakceptowaną grę nazwą w odpowiednim miejscu | Nie przepisywać marki przy okazji skracania układu |

Wszystkie nowe teksty są przykładami, nie zaakceptowanym copy do automatycznego wdrożenia.

### 6.4. Mobilny wariant

- Jedna kolumna, bez mikrokafelków z nieczytelnymi opisami.
- Krótsze odstępy między wprowadzeniem a treścią.
- Zdjęcie ma wspierać zrozumienie strony, a nie odsuwać całą aktywność o kilka ekranów.
- Prawdziwe autorstwo zostaje widoczne.
- Brak zdjęć nie uruchamia fikcyjnych kont ani stockowego „dowodu aktywności”.
- Osoba powracająca ma szybko widoczne logowanie.

**Pomiar:** odległość do pierwszego rzeczywistego dania, widoczność głównej akcji, liczba ekranów do pierwszej użytecznej treści. Nie ustalać sztywnego obowiązku zmieszczenia wszystkiego w pierwszym ekranie przy tekście 140%.

## 7. Start po zalogowaniu

### 7.1. Stan obecny

[home.blade.php][R09] zawiera powitanie, link do informacji o serwisie, ciemny kafel publikacji, wejścia do zdjęcia i przepisu, opcjonalne PWA i wspomnienie, nagłówek oraz zakładki strumienia, komunikaty źródła treści, wpisy i sekcję pomocniczą na dole.

**P:** każda z tych rzeczy może być potrzebna, ale nie każda powinna mieć ten sam ciężar wizualny i występować równie mocno przy każdej wizycie.

### 7.2. Wariant docelowy

1. Krótkie powitanie.
2. Zwarty kafel „Co dziś gotujesz?”.
3. Wyraźnie główne „Dodaj zdjęcie”; przepis jako czynność pomocnicza.
4. Przełącznik źródła wpisów.
5. Rzeczywista aktywność.

Powitanie nie musi mieć tej samej skali co tytuł marketingowy. Link „Poznaj Kuking” może być spokojniejszy; na powracającego użytkownika czeka już menu i pomoc.

### 7.3. Rozróżnienie stanów

| Stan | Zalecane zachowanie |
|---|---|
| Nowe konto bez obserwowanych | Pokaż dostępne wpisy innych i krótkie wyjaśnienie |
| Są obserwowane tagi, brak osób | Pokaż treści z tagów i jasno nazwij źródło |
| Aktywni obserwowani | Szybki dostęp do ich wpisów |
| Brak jakichkolwiek wpisów | Uczciwy pusty stan i jedna droga publikacji |
| Wspomnienie dostępne | Zachowaj możliwość ukrycia i wyłączenia |
| PWA może być zaproponowane | Nie nakładaj zaproszenia na główną czynność |

Obecne wyjaśnienie „Twoja strona główna jest jeszcze pusta” przy równoczesnym pokazaniu innych treści można zastąpić: „Na razie pokazujemy wpisy innych osób. Gdy zaczniesz kogoś obserwować, zobaczysz tutaj jego gotowanie”. Unika to wrażenia sprzeczności: strona przecież nie jest pusta.

### 7.4. Kryteria odbioru

- Zapisana preferencja czytelności działa jak wcześniej.
- Nie znika żadna droga dodawania treści.
- Użytkownik rozpoznaje, skąd pochodzą wpisy.
- Główny kafel zajmuje mniej miejsca przy skali 100%, ale może rosnąć przy powiększeniu.
- Wspomnienie nie staje się przymusowym onboardingiem.
- Chronologia i przycisk dalszych wpisów pozostają.

## 8. Nawigacja

### 8.1. Rekomendowane nazewnictwo

| Cel | Obecnie | Propozycja |
|---|---|---|
| Własny strumień | Start | Start |
| Wyszukiwanie | Szukaj | Szukaj |
| Publikowanie | Dodaj | Dodaj |
| Zapisane treści | Moje / Mój zeszyt | Zeszyt / Mój zeszyt |
| Własne publikacje i tożsamość | Profil | Profil |
| Publiczny strumień | Odkrywaj / Świeżo z Kuking | Pozostawić na pierwszy etap; ocenić rozpoznawalność |

**Wybór rekomendowany:** najpierw sprawdzić „Zeszyt” zamiast „Moje”. Nie zmieniać równocześnie wszystkich etykiet, bo trudno będzie rozpoznać, która zmiana pomaga.

Zmiana pięciu etykiet mobilnych wymaga aktualizacji zasad projektu. Jest to decyzja produktowa, nie zwykłe porządkowanie kodu.

### 8.2. Nagłówek na telefonie

Ryzykiem do pomiaru jest łączny koszt wysokości logo, konta, powiadomień i dolnej nawigacji. Nie ma w tym raporcie dowodu, że aktualnie zasłaniają treść.

Zalecenia:

- zachować widoczny podpis powiadomień;
- zmniejszać dekoracyjny padding przed zmniejszaniem tekstu;
- pozwalać na zmianę układu przy dużym tekście;
- nie wymuszać jednej linii na całym nagłówku;
- sprawdzić otwarte menu, fokus i ekran z klawiaturą;
- zachować istniejące odpinanie belek w małym obszarze roboczym.

## 9. Karta wpisu i fotografia

### 9.1. Obecna struktura

[post-card.blade.php][R12] łączy autora, datę i widoczność, treść, zdjęcia, opcjonalny przepis, tagi, informację o zapisach oraz czynności. Dla zalogowanego przy wpisie z przepisem mogą wystąpić: „Ugotowałem”, komentarz, zapis i wybór zeszytu.

Wiele poprawnych lokalnych decyzji może dawać łącznie zbyt gęsty pasek akcji. Bez zrzutu i pomiaru to hipoteza, nie ustalona awaria.

### 9.2. Hierarchia

| Warstwa | Ma odpowiadać na pytanie | Propozycja oprawy |
|---|---|---|
| Tożsamość | Kto to pokazał? | Awatar, czytelna nazwa, spokojna metryka |
| Treść | Co przygotowano? | Wygodny tekst i dominująca fotografia |
| Powiązanie | Czy jest pełny przepis? | Osobny, jednoznaczny link do przepisu |
| Reakcja | Co mogę zrobić? | Krótki zestaw opisanych działań |
| Narzędzia | Edycja, zgłoszenie, usunięcie | Istniejące menu z zachowanymi uprawnieniami |

### 9.3. Warianty kart

- **Zwykły wpis:** komentarz i zapis; narzędzia w istniejącym menu.
- **Wpis z przepisem:** jednoznaczne wejście do przepisu, komentarz, zapis; „Ugotowałem” pozostaje dostępne.
- **Wpis bez zdjęcia:** pełnoprawna karta tekstowa, bez pustego prostokąta.
- **Długi wpis:** zachować aktualne skracanie i drogę do całości; nie zamieniać treści w sztywne dwie linijki bez możliwości odczytu.
- **Wiele zdjęć:** zachować wybrany tryb normalny, karuzelę lub kolaż. Nie wymuszać jednego kadru wszystkim użytkownikom.

### 9.4. Zdjęcia

- Zdjęcie potrawy powinno mieć więcej znaczenia niż dekoracyjny cień.
- Zachować pełny dostęp do powiększenia i pozostałych fotografii.
- Podgląd może być kadrowany tylko zgodnie z ustalonym trybem; nie gubić treści istotnej dla przepisu.
- Nie zamieniać fotografii użytkowników na generowane „ładniejsze” odpowiedniki.
- Sprawdzić zdjęcie pionowe, poziome, bardzo szerokie, ciemne oraz jasne.
- Przy braku gotowego wariantu obrazu zachować uczciwy stan; nie obiecywać, że publikacja zdjęcia zakończyła się, jeśli trwa przetwarzanie.

### 9.5. Pasek działań

Proponuję lżejszą oprawę akcji pomocniczych, ale bez zmniejszania obszaru dotyku. „Mniejszy wizualnie” oznacza mniej wypełnienia, cienia i obramowania, nie 28-pikselowy przycisk.

Nie chować komentarza i zapisu pod ikoną trzech kropek. Wyjątek ikony bez widocznego napisu w projekcie dotyczy konkretnego menu, a nie wszystkich czynności.

## 10. Dodawanie zdjęcia

### 10.1. Stan obecny

[Formularz][R13] zawiera zdjęcia, opis, podpowiedź tagowania, trzy warianty widoczności, osobny komponent tagów i publikację. Kod ma mechanizm zachowania już przesłanych zdjęć po błędzie walidacji oraz klucz wysłania. To ważne elementy do zachowania.

### 10.2. Docelowa hierarchia

| Pozycja | Element | Zachowanie |
|---|---|---|
| 1 | Dodaj zdjęcie | Duży, czytelny wybór i podgląd |
| 2 | Napisz kilka słów | Widoczna etykieta, krótki przykład |
| 3 | Kto zobaczy wpis | Bieżący wybór stale widoczny; szczegóły rozwijane |
| 4 | Tagi opcjonalne | Rozwijane lub wizualnie pomocnicze |
| 5 | Opublikuj | Jedna główna czynność |

Proponowany skrócony wybór widoczności:

> **Wpis zobaczą: wszyscy**  
> Także osoby bez konta. Może pojawić się w wyszukiwarce.  
> Zmień

Po rozwinięciu zostają obecne opcje i wyjaśnienia. Nie wolno ukryć samego faktu publiczności ani zmieniać wyboru po błędzie.

### 10.3. Stany do zaprojektowania

| Stan | Co powinno być jasne |
|---|---|
| Jeszcze bez pliku | Gdzie dodać zdjęcie; możliwość wpisu tekstowego jeśli dopuszcza ją backend |
| Wybrano plik | Który plik wybrano i jak go usunąć |
| Wybrano kilka | Kolejność i liczba zdjęć |
| Wysyłanie | Czynność trwa; nie ma potrzeby wielokrotnego klikania |
| Błąd pojedynczego pliku | Które zdjęcie wymaga zmiany, a które zachowano |
| Błąd tekstu lub tagów | Zdjęć i poprawnego tekstu nie trzeba wpisywać ponownie |
| Błąd sesji | Konkretna informacja o odzyskanych danych i ponowieniu |
| Sukces | Wpis rzeczywiście opublikowany lub jednoznacznie opisany stan przetwarzania |

Nie zakładać, że wszystkie stany dziś nie istnieją. Tabela jest kontraktem odbioru zmienionego formularza.

### 10.4. Przykłady copy

| Obecne / techniczne | Proponowane |
|---|---|
| „Zdjęcia publikujemy bez danych EXIF i GPS” | „Usuwamy ze zdjęć zapisane dane lokalizacji.” |
| Rozbudowana instrukcja tagów jako główny element | „Dodaj tagi — opcjonalnie” |
| Niejasne „Błąd wysyłania” | „Nie udało się wysłać zdjęcia. Spróbuj ponownie.” |
| „Zapisano” bez określenia czego | „Wpis został opublikowany” albo „Szkic zapisany”, zgodnie z wynikiem |

Nie używać „Twoje dane są bezpieczne” jako zastępstwa dla konkretnej informacji. Nie obiecywać odzyskania pliku, jeżeli zachowano tylko tekst.

### 10.5. Czego nie dodawać na pierwszy etap

- Edytora zdjęć z filtrami i efektami.
- Automatycznego przepisywania stylu wypowiedzi użytkownika.
- Obowiązkowego tytułu, jeśli obecny model wpisu go nie wymaga.
- Obowiązkowych tagów.
- Osobnego wieloetapowego kreatora prostego wpisu.

## 11. Wyszukiwanie

### 11.1. Fakty

[Widok][R06] prezentuje zakresy „Wszystko”, „Przepisy”, „Ludzie” i „Do 30 minut”. [Kontroler][R14] traktuje `szybkie` jako przepisy z limitem 30 minut. [SearchQuery][R15] szuka m.in. w tytule, opisie i składnikach; dla filtra czasu wymaga obu podanych wartości czasu.

Przy pustej frazie nie działa ogólna przeglądarka wszystkich przepisów. To ważne przy zmianie linków i projektowaniu nowych filtrów.

### 11.2. Proponowany układ

1. Etykieta i pole wyszukiwania.
2. Typ: Wszystko / Przepisy / Ludzie.
3. Dla przepisów: opcjonalny filtr „Do 30 minut”.
4. Wyniki i czytelny aktywny zakres.
5. Polecane tagi przede wszystkim przed rozpoczęciem szukania.

**Etap minimalny:** zmiana prezentacji istniejącego `sekcja=szybkie`, bez przebudowy zapytań. Przełączenie typu na „Ludzie” wyłącza filtr czasu, a nie tworzy bezsensownego zestawu parametrów.

**Etap dalszy:** niezależny parametr czasu, tylko jeśli rzeczywiście potrzebne są kolejne filtry. Zachować zgodność starych adresów i reset paginacji po zmianie zakresu.

### 11.3. Pusty wynik

Obecny ekran proponuje dodanie przepisu. To może być pomocnicze, ale nie powinno być pierwszą odpowiedzią na nieudaną próbę znalezienia obiadu.

Przykład:

> Nie znaleźliśmy przepisu pasującego do „sernik pistacjowy”.  
> Spróbuj krótszej nazwy albo innego składnika.  
> **Zmień wyszukiwanie** · **Zobacz tagi**

Przy aktywnym filtrze czasu:

> Nie ma pasujących przepisów do 30 minut.  
> **Szukaj bez limitu czasu**

Nie pisać „Znaleźliśmy podobne”, jeśli backend nie zwrócił podobnych wyników. Nie prezentować losowych przepisów jako dopasowania.

### 11.4. Karta wyniku

Aktualna [karta przepisu][R21] pokazuje zdjęcie, tytuł, pochodzenie i ewentualną liczbę wykonań. Warto rozważyć czas i porcje, jeśli dane są dostępne, bez nowych zapytań na każdą kartę.

Proponowany zestaw:

- zdjęcie;
- pełny tytuł;
- autor / pochodzenie;
- czas, jeżeli znany;
- liczba wykonań, poprawnie nazwana.

Brak czasu oznacza brak informacji, nie „0 minut”. Nie dodawać gwiazdek ani sztucznych ocen.

## 12. Strona przepisu

### 12.1. Obecne mocne strony

[Widok][R16] zawiera autorstwo, źródło, czas i porcje, zdjęcie, zapis, tryb gotowania, składniki w grupach, przygotowanie, wykonania i komentarze. Nie trzeba tworzyć drugiej strony przepisu w innej technologii.

### 12.2. Hierarchia czynności

| Moment | Potrzeba | Akcja |
|---|---|---|
| Pierwsze oglądanie | Ocenić, czy mam czas i składniki | Czytelne informacje, przejście do składników |
| Planowanie | Wrócić później | Zapisuję |
| Rozpoczęcie | Korzystać z instrukcji | Gotuję — krok po kroku |
| Po przygotowaniu | Pokazać efekt i dać znać autorowi | Ugotowałem |

**P:** na górze strony mocniej wyróżnić „Gotuję”. „Ugotowałem” pozostaje dostępne bez konieczności przechodzenia wszystkich kroków. Na końcu trybu gotowania staje się główną czynnością.

To nie deprecjonuje najważniejszego sygnału społeczności. Dopasowuje kolejność do czasu wykonania zadania. Weryfikacja powinna sprawdzić, czy użytkownicy rozumieją różnicę między tymi dwoma czasownikami.

### 12.3. Skróty do treści

Rozważyć krótkie linki „Składniki” i „Przygotowanie” przy długim wprowadzeniu. To nawigacja wewnątrz dokumentu, nie kolejny przyklejony pasek.

Warunki:

- kotwica prowadzi do widocznego nagłówka;
- belka nie zasłania celu;
- brak dodatkowego poziomego przewijania;
- krótki przepis nie wymaga rozbudowanej nawigacji;
- historia i pochodzenie pozostają dostępne.

### 12.4. Autorstwo i brakujące dane

- Nie mieszać autora publikacji z osobą, od której pochodzi receptura.
- Zachować źródło i odnośnik zewnętrzny zgodnie z aktualną logiką.
- Nie uzupełniać automatycznie brakujących porcji lub czasu zgadywanymi wartościami.
- Nazwa „Komu wyszło” może pozostać, ale licznik pod nią musi odpowiadać agregowanym danym.
- Poprawa wyglądu nie może ujawnić ukrytych wykonań lub szkiców.

## 13. Tryb gotowania

### 13.1. Co już jest

[cooking.blade.php][R18] zawiera licznik kroków, składniki rozwijane, kontrolkę nieusypiania ekranu, bieżącą instrukcję i zdjęcie, minutnik, oznaczanie wykonania kroku, przejście dalej / wstecz oraz „Ugotowałem” na końcu.

Odczyt widoku potwierdza obecność interfejsu. Nie potwierdza niezawodności minutnika w tle na każdym telefonie ani obsługi Wake Lock w każdej przeglądarce.

### 13.2. Najważniejsze usprawnienie do sprawdzenia

Obecne „Oznacz krok jako zrobiony” i „Następny krok” są osobnymi czynnościami. To bywa poprawne, bo można czytać instrukcję bez oznaczania wykonania. Warto zbadać, czy użytkownicy rozumieją ten podział.

Wariant do testu:

- główne „Zrobione, następny krok” — tylko jako jawna operacja zapisu;
- pomocnicze „Przejdź dalej bez oznaczania”;
- możliwość cofnięcia oznaczenia;
- przejście wstecz bez kasowania postępu.

Nie zmieniać stanu przez zwykły GET tylko po to, żeby połączyć przyciski. Zapisy pozostają operacjami chronionymi CSRF.

### 13.3. Lista przypadków brzegowych

- Ostatni i pierwszy krok.
- Przepis z jednym krokiem.
- Bardzo długi opis.
- Brak zdjęcia.
- Minutnik, który kończy się po zablokowaniu telefonu.
- Utrata sieci podczas oznaczania.
- Powrót do karty po przełączeniu aplikacji.
- Zmiana orientacji.
- Utrata blokady wygaszania.
- Gość kończący gotowanie bez konta.

Zalecenie wizualne: instrukcja powinna mieć pierwszeństwo przed dekoracyjnym nagłówkiem i globalnymi panelami. Nie dokładać w tym trybie banerów instalacji PWA ani zachęt do obserwowania innych.

## 14. Zeszyty i zapisywanie

### 14.1. Stan obecny

Przy treści występują zapis domyślny i rozwijany wybór zeszytu. Komponent [wybor-zeszytu.blade.php][R22] obsługuje opcje, błędy i zakładanie nowego zeszytu. [Indeks][R19] pokazuje foldery i ostatnie zapisy; [szczegół][R20] odróżnia przepisy, wpisy i zapisy niedostępne.

### 14.2. Uporządkowanie akcji

Proponowana hierarchia:

1. „Zapisuję” — najszybszy zapis zgodny z istniejącą semantyką.
2. Jednoznaczny komunikat po sukcesie, np. „Zapisano w zeszycie «Na później»”, tylko jeśli znamy nazwę miejsca.
3. Pomocniczy wybór innego zeszytu.

**Uwaga domenowa:** trzeba odróżnić „zapisz również w innym zeszycie” od „przenieś”. Nie proponować etykiety „Zmień zeszyt”, jeśli operacja tylko dodaje kolejne powiązanie. Najpierw sprawdzić akcje zapisu i reguły wielu kolekcji.

### 14.3. Wygląd indeksu

Ciemne karty zeszytów są częścią zaakceptowanej kompozycji D-211. Nie należy automatycznie usuwać ich w imię „lżejszego UI”. Można najpierw:

- uspokoić odstępy i wtórne metadane;
- wyraźniej pokazać ostatnio zapisane treści;
- ujednolicić tytuły i akcję otwarcia;
- zachować widoczną informację publiczny / prywatny;
- ograniczyć zbędne powtarzanie opisów.

Nie dodawać na tym etapie wyszukiwarki wewnątrz zeszytu bez sprawdzenia, czy liczba zapisów i badania uzasadniają nową funkcję.

### 14.4. Stany odbioru

Pusty zeszyt, tylko wpisy, tylko przepisy, mieszany zestaw, bardzo długa nazwa, publiczny zeszyt innej osoby, zapis niedostępny, usunięcie folderu, błąd wyboru, domyślny folder i wiele folderów.

Informacja o niedostępnych zapisach nie może zdradzać tytułu lub autora treści, do której widz stracił dostęp. Nie usuwać tej informacji tylko po to, żeby ekran był krótszy.

## 15. Profile i relacje

### 15.1. Stan obecny

[Profil][R23] ma duży awatar, nazwę, dane dodatkowe, opis, działania zależne od uprawnień, statystyki i archiwum z zakładkami. Własny profil i cudzy profil realizują różne zadania.

### 15.2. Rekomendacje

| Własny profil | Cudzy profil |
|---|---|
| Łatwo odnaleźć własne publikacje | Łatwo zobaczyć gotowanie tej osoby |
| Publikacja i edycja profilu czytelne | Obserwowanie jako główna czynność relacyjna |
| Ustawienia dostępne, ale nie dominujące | Opis i specjalność pomocnicze |
| Wylogowanie oddzielone wizualnie | Blokowanie i zgłaszanie odsunięte od zwykłych działań |

Duży awatar 170 px jest obecny w widoku. Jego końcowy rozmiar na telefonie zależy od CSS; nie należy na tej podstawie twierdzić, że zajmuje zbyt dużo ekranu. Zmierzyć wysokość nagłówka przed pierwszą publikacją.

**P:** dla kont z długim opisem rozważyć „Czytaj więcej”, ale tylko po ocenie potrzeby, z pełnym dostępem do tekstu. Nie obcinać nazwy użytkownika i nie zastępować treści wielokropkiem bez możliwości odczytu.

**Nie wprowadzać:** rankingów kucharzy, poziomów za liczbę publikacji, fałszywych odznak ani sztucznie wypełnianych profili.

## 16. Rejestracja i pierwsza wizyta

### 16.1. Stan obecny

[Rejestracja][R24] przewiduje nazwę wyświetlaną, nazwę profilu, e-mail, hasło, potwierdzenia i Turnstile. Istnieje wariant zaproszenia z potwierdzonym adresem oraz komponent wejść zewnętrznych. Obecność komponentu nie dowodzi, że każdy dostawca jest aktualnie skonfigurowany na produkcji.

### 16.2. Usprawnienia

- Skrócić tekst przed pierwszą dostępną metodą założenia konta.
- Zachować jasne rozróżnienie nazwy widocznej i adresu profilu.
- Wyjaśnić podpowiedź nazwy dopiero tam, gdzie jest potrzebna.
- Nie usuwać świadomego potwierdzenia regulaminu i wieku.
- Po błędzie nie usuwać poprawnych wartości niesekretnych.
- Nie przywracać hasła do HTML w ramach odzyskiwania formularza.

Przykładowe krótsze wprowadzenie:

> Załóż konto, żeby pokazywać swoje gotowanie i zapisywać pomysły. Nie pytamy o numer telefonu.

Unikać stałego „cztery pola”, jeśli wariant zaproszenia lub zewnętrznego logowania prezentuje inną liczbę pól. To nie awaria rejestracji, ale przykład tekstu zbyt mocno związanego z jedną wersją formularza.

### 16.3. Pierwsza wizyta

Pierwszy sukces nie musi oznaczać publikacji. Dla części osób będzie nim znalezienie przepisu, zapis lub obserwowanie autora.

Kryteria:

- wybory zainteresowań można pominąć;
- po pominięciu nie ma pustej ślepej uliczki;
- brak wymuszenia zdjęcia profilowego;
- jasne przejście do oglądania i publikacji;
- podpowiedzi znikają po spełnieniu zadania, jeżeli wdrożono mechanizm ich stanu;
- nie tworzymy fikcyjnej aktywności, żeby ekran wyglądał na pełny.

## 17. Powiadomienia i komunikaty

### 17.1. Powiadomienia

[Widok][R25] odróżnia zwykłe zdarzenia od decyzji moderacyjnych. Ma etykietę „Nowe”, autora, treść i datę. To dobry fundament.

Propozycje:

- ważna treść w pierwszym zdaniu;
- tytuł przepisu lub fragment komentarza zamiast samego ogólnego typu zdarzenia;
- jedna czytelna droga do odpowiedniej treści;
- lżejsza akcja „Oznacz wszystkie jako przeczytane”, żeby nie wypierała samych wiadomości;
- brak redukcji uzasadnień i terminów w sprawach moderacyjnych.

Nie dodawać kolejnych filtrów powiadomień bez dowodu, że ich ilość utrudnia odnajdywanie informacji.

### 17.2. Słownik stanów

| Intencja | Przykład | Warunek prawdziwości |
|---|---|---|
| Sukces publikacji | „Wpis został opublikowany.” | Zakończona publikacja |
| Zapis szkicu | „Szkic zapisany.” | Potwierdzony zapis |
| Trwająca operacja | „Wysyłamy zdjęcie…” | Operacja faktycznie trwa |
| Błąd | „Nie udało się zapisać. Spróbuj ponownie.” | Brak potwierdzenia sukcesu |
| Zapis do folderu | „Zapisano w zeszycie «Na święta».” | Znamy faktyczny folder |
| Zmiana źródła feedu | „To wpisy z obserwowanych tagów.” | To rzeczywiste źródło |

Nie stosować jednego komunikatu „Gotowe” dla publikacji, szkicu, zapisu do kolekcji i wysłania zgłoszenia. Użytkownik powinien wiedzieć, co zaszło.

Komunikaty sukcesu dodawane bez przeładowania powinny być dostępne również dla technologii asystujących. Nie każdy status wymaga przeniesienia fokusu; właściwe ogłaszanie zmian opisuje [WCAG 4.1.3][E08].

## 18. Specyfikacja kierunku wizualnego

### 18.1. Teza projektowa

**Prawdziwe domowe gotowanie na pierwszym planie, spokojna i czytelna oprawa wokół niego.**

Ta teza przekłada się na konkretne wybory:

- zdjęcia i wypowiedzi ważniejsze od ozdobników;
- czerwień używana selektywnie;
- grafit zachowany w charakterystycznych miejscach;
- jedna rodzina pisma;
- wyraźne rozróżnienie głównej czynności i narzędzi pomocniczych;
- duże cele dotykowe bez nadmiernie ciężkich ramek.

### 18.2. Hierarchia powierzchni

| Rola | Zalecenie |
|---|---|
| Rama strony | Neutralne tło, nie kolejna duża karta |
| Główna treść | Jasna karta / powierzchnia zgodna z motywem |
| Główna zachęta publikacji | Charakterystyczny grafit, ale zwarta wysokość na telefonie |
| Pomoc i metadane | Mniej cienia i dekoracji |
| Formularz | Wyraźne pola i etykiety, dobra granica kontrolki |
| Błąd | Tekst i struktura wskazują problem; sam kolor nie wystarcza |

### 18.3. Odstępy

Proponowane wartości robocze do prototypu, nie nowy standard zatwierdzony dla całej aplikacji:

| Relacja | Zakres przy skali 100% |
|---|---:|
| Ikona–podpis | 8–12 px |
| Etykieta–pole | 8 px |
| Akcje w jednej grupie | 8–12 px |
| Powiązane bloki w karcie | 12–20 px |
| Karty w strumieniu | 16–24 px |
| Większe sekcje roboczego ekranu | 24–40 px |

Najpierw używać istniejących tokenów. Nie tworzyć odrębnego zestawu odstępów dla każdego ekranu. Ustawienia skali użytkownika i minimum dotykowe mają pierwszeństwo przed próbą idealnego dopasowania do makiety.

### 18.4. Cienie, promienie i obramowania

- Zachować miękkie zaokrąglenia rozpoznawalne dla marki.
- Unikać mnożenia podobnych promieni bez roli semantycznej.
- Cień służy oddzieleniu powierzchni; nie powinien pojawiać się na każdym podbloku.
- Obramowania pól i ważnych kontrolek muszą pozostać czytelne.
- Sprawdzić obcinanie fokusu i menu przez `overflow: hidden`.
- Nie „naprawiać” poziomego overflow globalnym ukrywaniem zawartości.

### 18.5. Co przygotować jako makiety następnego etapu

| Ekran | Niezbędne warianty |
|---|---|
| Start | Nowe konto i aktywne konto; telefon i desktop |
| Karta wpisu | Tekst, jedno zdjęcie, kilka zdjęć, przepis, długi opis |
| Dodaj zdjęcie | Pusty, wybrane pliki, błąd, wysyłanie |
| Szukaj | Przed frazą, wyniki, brak wyników, limit czasu |
| Przepis | Pełne dane, brak części danych, zapisany, gość |
| Zeszyt | Pusty, mieszany, niedostępne zapisy |

Makiety muszą pokazać stany, a nie tylko idealny ekran z krótkimi nazwami. Prototyp w Sites może służyć porównaniu kompozycji, ale nie zastępuje działającego Laravela, autoryzacji i formularzy.

## 19. Dostępność i responsywność

### 19.1. Standard produktu a WCAG

| Obszar | Zasada odbioru |
|---|---|
| Tekst | Domyślnie zgodnie z Kuking: 18 px dla treści i pól; świadome preferencje użytkownika pozostają |
| Dotyk | Główne kontrolki minimum 48 px, zgodnie z projektem |
| Zawijanie | Treść pozostaje dostępna bez utraty funkcji |
| Powiększenie | Oddzielnie skala aplikacji, większy tekst przeglądarki i rzeczywisty zoom |
| Klawiatura | Wszystkie czynności dostępne, logiczna kolejność |
| Błąd formularza | Powiązanie z polem i droga do poprawy |

WCAG 2.2 AA dla rozmiaru celu wskazuje 24 × 24 CSS px z opisanymi wyjątkami. Kuking świadomie stosuje wygodniejsze 48 px dla głównych kontrolek. Nie obniżać standardu produktu do minimum normy. [WCAG 2.5.8][E01].

### 19.2. Macierz urządzeń

| Wymiar testu | Przypadki |
|---|---|
| Telefon | 320, 360, 390, 414 CSS px |
| Tablet | 768 i 1024 CSS px |
| Desktop | 1280 i 1440 CSS px |
| Tekst aplikacji | 100%, 140%; dodatkowo mniejsze skale wspierane przez aplikację |
| Tekst przeglądarki | 200% |
| Zoom | Rzeczywiste 200%, także ze skalą aplikacji 140% |
| Motyw | Jasny i ciemny |
| Wysokość | Niskie okno i otwarta klawiatura |
| Sterowanie | Dotyk, klawiatura, podstawowy przebieg czytnikiem |

Nie jest konieczne wykonywanie pełnego iloczynu wszystkich przypadków dla każdej kosmetycznej zmiany. Najpierw przypadki najbardziej narażone; wspólna zmiana ramy wymaga szerszego pokrycia niż zmiana pojedynczego zdania.

### 19.3. Zawijanie i zoom

Sprawdzenie szerokości 320 CSS px nie jest tym samym co sprawdzenie rzeczywistego zoomu. Kryterium reflow odnosi się do możliwości korzystania z treści przy ograniczonym obszarze, z wyjątkami dla układów wymagających dwóch wymiarów. Oddzielne kryterium dotyczy powiększenia tekstu do 200%. [Reflow][E02], [Resize Text][E03].

Przypadki szczególnie ważne dla Kuking:

- pięć pozycji dolnej nawigacji;
- długie imię lub nazwa profilu;
- tytuł przepisu o długości kilku wierszy;
- długi adres w treści;
- rozwinięty wybór zeszytu;
- formularz z podsumowaniem błędów;
- podpowiedzi tagów nad klawiaturą;
- minutnik i przyciski kroku.

### 19.4. Fokus i belki

WCAG 2.4.11 wymaga, aby element z fokusem nie był całkowicie zasłonięty przez treść utworzoną przez autora strony. Dla Kuking praktyczny cel powinien być wyższy: osoba ma widzieć kontrolkę i jej podpis, a nie jedynie skrawek obrysu. [Focus Not Obscured][E04].

Nie zakładać, że `scroll-margin` rozwiązuje wszystkie kolizje. Zmierzyć stan po otwarciu klawiatury, rozwinięciu menu i zmianie skali.

### 19.5. Kontrast i błędy

Dla zwykłego tekstu WCAG AA wymaga zasadniczo kontrastu 4,5:1, a dla dużego tekstu 3:1, z opisanymi wyjątkami. Pomiar palety nie zastępuje pomiaru końcowego tła konkretnego komponentu. [Contrast Minimum][E05].

Błąd powinien być identyfikowany tekstowo. Kuking dodatkowo wymaga podsumowania i wskazania pola. To dobra reguła do zachowania przy upraszczaniu formularza. [Error Identification][E07], [reguły Kuking][R04].

## 20. Wydajność i utrzymanie kodu

### 20.1. Rozmiary źródeł

W odczytanym drzewie repozytorium:

| Plik | Rozmiar źródłowy |
|---|---:|
| `resources/css/app.css` | 274 770 bajtów |
| `resources/views/components/layout.blade.php` | 81 832 bajty |

**Nie są to rozmiary transferu.** Komentarze Blade nie trafiają w tej postaci do HTML, a build może usuwać komentarze i minifikować CSS. Nie można z tych liczb wywnioskować wolnego ładowania.

Problemem do rozważenia jest koszt utrzymania: wiele reguł, odpowiedzialności i historycznych komentarzy w jednym miejscu.

### 20.2. Porządkowanie bez wielkiego przepisywania

1. Przy każdej zmianie znaleźć regułę bazową, nadpisania i warstwę kaskady.
2. Określić jeden aktywny kontrakt komponentu.
3. Usuwać martwe reguły tylko po potwierdzeniu użycia w widokach, skryptach i stanach warunkowych.
4. Długie uzasadnienia historyczne przenieść do właściwych notatek; w kodzie zostawić krótki powód i odnośnik.
5. Rozdzielić layout na komponenty dopiero tam, gdzie granica odpowiedzialności jest stabilna.
6. Zachować istniejące zależności i stack.

Nie uruchamiać mechanicznego „cleanup” po nazwach klas. Klasa może występować w szablonie dynamicznym, w treści generowanej przez JS albo tylko w błędzie formularza.

### 20.3. Zdjęcia i pierwszy ekran

Na stronie powitalnej obrazy kolażu mają `loading="lazy"`. Czy to jest problem, zależy od tego, który element jest LCP i gdzie obraz znajduje się w danym widoku. Warto zmierzyć:

- faktyczny LCP na telefonie i desktopie;
- wielkość i wariant pierwszego zdjęcia;
- proporcje i rezerwację miejsca;
- czy kilka zdjęć nie konkuruje niepotrzebnie o priorytet;
- czy miniatury są dostatecznie ostre na ekranach o wysokiej gęstości;
- czy obraz poniżej pierwszego ekranu pozostaje ładowany leniwie.

Nie ustawiać wysokiego priorytetu wszystkim obrazom. To usuwa sens priorytetyzacji.

### 20.4. Cele pomiarowe

Oficjalne progi „good” Core Web Vitals to LCP do 2,5 s, INP do 200 ms i CLS do 0,1; ocena opiera się na 75. percentylu. Są to cele odniesienia, a nie wyniki Kuking. [Web Vitals][E06].

Pomiar laboratoryjny powinien służyć diagnozie, a dane rzeczywistych użytkowników ocenie doświadczenia. Dla małego ruchu nie wyciągać kategorycznych wniosków z kilku sesji.

## 21. Pomiar efektów i badania

### 21.1. Mierzyć zadania

| Obszar | Pytanie | Przykładowa miara |
|---|---|---|
| Publikacja | Czy osoba publikuje zdjęcie bez pomocy? | Sukces, przerwania, liczba błędów |
| Start | Czy rozumie źródło wpisów? | Poprawna odpowiedź po krótkim użyciu |
| Szukanie | Czy znajduje odpowiedni przepis? | Sukces zadania i liczba zmian zapytania |
| Zapis | Czy potrafi wrócić do zapisanej treści? | Odnalezienie po opuszczeniu strony |
| Gotowanie | Czy odróżnia „Gotuję” od „Ugotowałem”? | Wybór poprawnej czynności |
| Relacja | Czy potrafi obserwować autora i znaleźć jego wpisy? | Sukces pełnego przebiegu |

### 21.2. Co już jest w analityce

[ZapiszSygnal][R26] zawiera m.in. zdarzenia wyszukiwania, nieudanego uploadu i obsługi PWA. Wyszukiwanie zapisuje długość frazy i fakt istnienia wyników, nie treść frazy. To trzeba zachować.

Proponowane nowe zdarzenia nie są obecnie potwierdzonymi funkcjami:

| Propozycja | Minimalna informacja |
|---|---|
| `post_composer_opened` | Punkt wejścia |
| `post_publish_succeeded` | Rodzaj publikacji, bez treści |
| `collection_save_succeeded` | Typ obiektu, bez nazwy prywatnego zeszytu |
| `cooking_started` | Rozpoczęcie trybu |
| `cooking_last_step_viewed` | Dotarcie do końca instrukcji, nie dowód ugotowania |

Przed dodaniem trzeba sprawdzić istniejące zdarzenia domenowe, schemat i ograniczenia analityki, retencję oraz dokumentację. Nie instalować dodatkowego SDK tylko dlatego, że wygodnie rysuje lejek.

### 21.3. Badanie jakościowe

Projektowy standard [UX_50_PLUS][R04] przewiduje badanie 13 osób: 5 w wieku 50–59, 5 w wieku 60–69 i 3 w wieku 70+. Dla szybkiej iteracji można wcześniej przeprowadzić mniejszą rundę eksploracyjną, ale nie nazywać jej spełnieniem całej bramki projektu.

Scenariusze:

1. „Pokaż zdjęcie dzisiejszego obiadu tylko osobom, które Cię obserwują”.
2. „Znajdź pomysł z cukinią, którego przygotowanie zajmuje do pół godziny”.
3. „Zapisz go, a potem wróć do niego ze strony głównej”.
4. „Chcesz teraz zacząć gotować. Co wybierzesz?”.
5. „Danie jest gotowe. Pokaż autorowi, jak wyszło”.
6. „Zwiększ tekst i znajdź swoje wcześniejsze publikacje”.
7. „W formularzu pojawił się błąd. Popraw go bez ponownego wybierania zachowanych zdjęć”.

Prowadzący nie podpowiada nazwy przycisku. Obserwuje zawahanie, błędne kliknięcia i interpretację tekstu. Nie pytać wyłącznie „czy podoba się wygląd?”.

### 21.4. Interpretacja wyników

- „Dotarł do ostatniego kroku” nie znaczy „ugotował”.
- „Kliknął zapis” nie znaczy „zapis zakończył się sukcesem”.
- „Otworzył formularz” nie znaczy „chciał opublikować”.
- Liczba wykonań nie znaczy liczba unikalnych kucharzy.
- Większa liczba odsłon nie musi oznaczać łatwiejszego korzystania.
- Dłuższy czas zadania może wynikać z problemu, a nie większego zaangażowania.

## 22. Backlog i plan wdrożenia

### 22.1. Skala

- **P1:** prawdziwość informacji lub podstawowa ścieżka użytkownika.
- **P2:** ważne usprawnienie komfortu i spójności.
- **P3:** praca warunkowa, po dowodzie potrzeby.
- **S:** wąska zmiana; **M:** kilka komponentów i stanów; **L:** wspólny układ lub zmiana semantyki domeny.

To względna skala planistyczna, nie estymacja dni. Ostateczny koszt zależy od testów, obecnych issues i faktycznego stanu gałęzi.

### 22.2. Lista prac

| ID | Zadanie | Typ | Priorytet | Zakres | Główny dowód odbioru |
|---|---|---|---|---|---|
| U01 | Poprawić rozróżnienie osób i wykonań | Błąd semantyczny | P1 | S/M | Powtarzane wykonania jednej osoby |
| U02 | Dopasować „Przepisy” do celu odnośnika | Spójność | P1 | S | Docelowy ekran odpowiada etykiecie |
| U03 | Skrócić pionową kompozycję mobilnego Startu | Propozycja | P1 | M | Ogląd i zadanie użytkownika |
| U04 | Uporządkować akcje karty wpisu | Propozycja | P1 | M | Wszystkie typy kart i klawiatura |
| U05 | Uprościć hierarchię dodawania zdjęcia | Propozycja | P1 | M | Publikacja i błędy bez utraty danych |
| U06 | Oddzielić typ wyników i filtr czasu | Propozycja UX | P1 | M | Parametry, paginacja i zero wyników |
| U07 | Poprawić drogę z pustego wyniku | Propozycja | P1 | S/M | Użytkownik potrafi zmienić wyszukiwanie |
| U08 | Rozdzielić hierarchię „Gotuję” / „Ugotowałem” | Propozycja | P1 | M | Test rozumienia czynności |
| U09 | Zweryfikować „Zeszyt” zamiast „Moje” | Decyzja marki | P2 | S/M | Rozpoznawalność i duży tekst |
| U10 | Pokazać prawdziwą tablicę wcześniej na landing | Decyzja kompozycji | P2 | M | Porównanie z aktualnym układem |
| U11 | Uspokoić wtórne elementy kart | Propozycja wizualna | P2 | M | Ogląd obu motywów |
| U12 | Ujednolicić potwierdzenia zapisu | Funkcjonalność UI | P2 | M | Rzeczywisty sukces, błąd, kilka folderów |
| U13 | Rozważyć czas w karcie wyniku | Propozycja | P2 | S/M | Dane kompletne i brak danych |
| U14 | Skrócić wprowadzenie rejestracji | Copy | P2 | S | Wszystkie warianty wejścia |
| U15 | Ocenić wysokość nagłówka profilu | Pomiar | P2 | S | Długie bio, nazwa i mobile |
| U16 | Ocenić połączenie wykonania kroku i przejścia dalej | Badanie | P2 | M | Zapis postępu i cofnięcie |
| U17 | Poprawić nieaktualny komentarz kolażu | Utrzymanie | P2 | S | Zgodność z computed style |
| U18 | Uporządkować CSS zmienianych komponentów | Utrzymanie | P2 | M | Brak regresji wspólnej ramy |
| U19 | Zmierzyć LCP i politykę ładowania kolażu | Wydajność | P2 | S/M | Wynik pomiaru, nie przypuszczenie |
| U20 | Przejść macierz dostępności głównych przepływów | Odbiór | P1 | M/L | Zrzuty, pomiary, klawiatura |
| U21 | Zbadać zadania z użytkownikami | Badanie | P1 | M | Notatki z zachowań i wnioski |
| U22 | Uzupełnić pomiar lejków bez treści prywatnych | Analityka | P2 | M | Zdarzenia odpowiadają skutkom |
| U23 | Oczyścić aktywną dokumentację ze sprzecznych wzorców | Utrzymanie | P2 | M | Jeden aktualny kontrakt |
| U24 | Rozważyć katalog przepisów bez frazy | Nowa funkcja | P3 | M/L | Potrzeba potwierdzona badaniem |

### 22.3. Etapy

**Etap A — prawdziwość i przygotowanie:** U01, U02, sprawdzenie aktualnych issues, ustalenie punktu bazowego i scenariuszy.

**Etap B — główna pętla społeczności:** U03–U05, odpowiednia część U20 i pierwsza runda U21.

**Etap C — od szukania do efektu:** U06–U08, U12–U13 oraz odbiór przepisu i gotowania.

**Etap D — spójność otoczenia:** U09–U11, U14–U16, po decyzjach o zmianie dotychczasowych kompozycji.

**Prace towarzyszące:** U17–U19 i U23 tylko w powiązanych obszarach; nie blokować wąskiej poprawki wielkim refaktorem.

### 22.4. Zasada wdrożenia

Każdy etap powinien mieć widoczny, kompletny rezultat. Nie scalać wielkiego PR-a łączącego nową paletę, zmiany nazw, statystyki, refaktor CSS i nowe filtry. W razie regresji trzeba móc cofnąć konkretną zmianę bez usunięcia niezależnych poprawek.

## 23. Gotowe opisy pierwszych zadań

### Zadanie 1. Licznik „Komu wyszło” nie może nazywać wykonań osobami

**Problem:** `cookedCount` i liczniki odpowiedzi agregują rekordy wykonań. Widok opisuje je jako osoby, co jest nieprawdziwe przy wielokrotnym gotowaniu przez tę samą osobę.

**Zakres pierwszej poprawki:** nazwać dane zgodnie z tym, co liczą; zachować wszystkie wykonania i reguły widoczności. Osobne liczenie unikalnych osób wymaga odrębnej decyzji.

**Przypadki testowe:** jedna osoba i trzy wykonania; dwie osoby i cztery wykonania; brak odpowiedzi; ukryte wykonanie; blokada; kolejne strony galerii; poprawna odmiana.

**Poza zakresem:** ograniczenie liczby wykonań, publiczny ranking, zmiana polityk prywatności.

**Akceptacja:** żaden podpis „osoby” nie opisuje liczby zdarzeń; brak regresji galerii i statystyk dostępnych widzowi.

### Zadanie 2. Ujednolicić znaczenie odnośników „Przepisy” i „Poszukaj przepisów”

**Problem:** linki prowadzą do ogólnego strumienia. Użytkownik nie dostaje zakresu zapowiedzianego przez nazwę.

**Zakres:** zinwentaryzować te odnośniki, dopasować podpisy lub cele, uwzględnić pustą frazę w wyszukiwarce.

**Przypadki:** gość, zalogowany, pusty zeszyt, pusty folder, szczegół przepisu, przejście wstecz.

**Akceptacja:** nie pojawia się pozorny katalog bez danych; nazwa i docelowy nagłówek są zgodne; brak przekierowania do niedostępnej funkcji.

### Zadanie 3. Uprościć formularz zdjęcia bez utraty kontroli nad widocznością

**Problem:** główna akcja jest prosta, ale formularz nadaje dużą wagę także opcjonalnym ustawieniom.

**Zakres:** podkreślić zdjęcie i opis; skrócić prezentację widoczności, zachowując bieżący wybór; uspokoić tagi; zachować wysyłanie, odzyskiwanie i walidację.

**Akceptacja:** publikacja zdjęcia bez pomocy; wszystkie opcje nadal dostępne; błąd rozwija właściwą sekcję; poprawne dane i wybór widoczności pozostają; klawiatura nie zasłania aktywnego pola.

### Zadanie 4. Rozdzielić filtr czasu od typu wyników

**Problem:** „Do 30 minut” jest przedstawiane obok „Ludzie”, choć opisuje cechę przepisu.

**Zakres minimalny:** zmiana hierarchii kontrolek przy zachowaniu istniejących zapytań i zgodności adresów.

**Akceptacja:** brak filtra czasu dla ludzi; reset właściwej paginacji po zmianie zakresu; zachowanie frazy; zero wyników daje drogę usunięcia filtra; pusta fraza nie udaje wykonanej kwerendy katalogu.

### Zadanie 5. Uporządkować górę strony przepisu

**Problem:** „Ugotowałem” jest wyróżnione przed rozpoczęciem gotowania, a „Gotuję” ma mniejszą wagę.

**Zakres:** czytelna hierarchia „Gotuję”, „Zapisuję”, „Ugotowałem”, z utrzymaniem wszystkich dróg i stanów użytkownika.

**Akceptacja:** badany odróżnia rozpoczęcie od zgłoszenia wykonania; osoba, która już ugotowała, nie musi przechodzić instrukcji; gość może korzystać z dostępnych mu kroków; zapis nie traci potwierdzenia.

### Wspólne warunki techniczne tych zadań

- Pracować w istniejącym stacku Laravel / Blade / Alpine / Livewire.
- Zachować CSRF, uprawnienia, filtry widoczności i granice danych.
- Sprawdzić istniejące issues przed zakładaniem nowych.
- Dla poprawki błędu dołączyć test regresyjny zgodnie z regułami projektu.
- W tym repo dowód testu obejmuje wymaganą kontrolę ujemną; nie zastępuje jej samo zielone uruchomienie.
- Uruchomić właściwe kontrole i wymagany gate przed PR; nie deklarować wyników niewykonanych.
- Oddzielnie raportować odczyt kodu, test lokalny i sprawdzenie wdrożenia.
- Dodać plan cofnięcia: powrót konkretnej zmiany UI bez manipulacji produkcyjną bazą.

## 24. Decyzje do aktualizacji i zakres wyłączony

### 24.1. Macierz decyzji

| Propozycja | Obecne źródło decyzji | Jak postąpić |
|---|---|---|
| Tablica wcześniej na landing | D-208, D-215, konstytucja | Nowa decyzja o kolejności; zachować intencję i autorstwo |
| „Zeszyt” zamiast „Moje” | AGENTS, UX_50_PLUS, konstytucja | Zaktualizować etykiety i dokumenty razem |
| Zmiana „Odkrywaj” | D-207 | Nie usuwać jako rzekomej pomyłki; ocenić osobno |
| Lżejsze karty folderów | D-211 | Najpierw poprawić hierarchię bez porzucania zaakceptowanej kompozycji |
| Inna hierarchia akcji przepisu | D-210 i rdzeń „Ugotowałem” | Doprecyzować, gdzie akcja jest główna |
| Zmiana tekstów marketingowych | COPY_STYLE, GLOS_MARKI | Sprawdzić intencję i prawdziwość wszystkich wariantów |
| Nowe liczenie osób | Reguły wielokrotnych wykonań | Jawnie zdefiniować jednostkę i odpowiedzi wielokrotne |

### 24.2. Czego ten raport nie rekomenduje teraz

- Migracji aplikacji do Reacta, Next.js lub osobnego SPA.
- Przeniesienia działającego backendu do statycznego prototypu Sites.
- Nowego logo i całkowitej zmiany kolorystyki.
- Rankingu użytkowników i punktów za aktywność.
- Algorytmicznego strumienia zastępującego obserwowanych.
- Obowiązkowego pełnego przepisu przy zwykłym zdjęciu.
- Wiadomości prywatnych, live, marketplace ani rozbudowanych grup.
- Planera, spiżarni, OCR i generatora przepisów jako części tego etapu.
- Masowego generowania fikcyjnych kont, komentarzy, wykonań i fotografii jako dowodów aktywności.
- Dodania kolejnego narzędzia analitycznego bez sprawdzenia obecnych możliwości.

### 24.3. Dlaczego nie przygotowano oceny „8/10”

Bez oglądu przeglądarkowego i badań liczbowy wynik estetyki lub UX byłby arbitralny. Użyteczniejsze są: konkretny problem, dowód, proponowana zmiana i sposób sprawdzenia. Ten raport ma służyć wykonaniu prac oraz ocenie efektu, nie produkowaniu pozornie precyzyjnego rankingu.

## 25. Referencje

### 25.1. Repozytorium — zasady i kontekst

| ID | Źródło | Rola w raporcie |
|---|---|---|
| R01 | [AGENTS.md][R01] | Rdzeń produktu, UX, stack, wielokrotne wykonania, bramki prac |
| R02 | [Konstytucja marki][R02] | Aktualny kierunek wizualny i kompozycje |
| R03 | [Decyzje][R03] | D-207–D-220 i granice wcześniejszych wyborów |
| R04 | [UX 50+][R04] | Czytelność, kontrolki, formularze, badania |
| R27 | [FEATURES][R27] | Zakres MVP i późniejsze funkcje |
| R28 | [ROADMAP][R28] | Kolejność rozwoju i bramki |
| R31 | [COPY_STYLE][R31] | Nazwy działań i ton komunikatów |
| R32 | [DESIGN_SYSTEM][R32] | Dokument z oznaczonymi historycznymi częściami |

### 25.2. Repozytorium — kod

| ID | Źródło | Rola w raporcie |
|---|---|---|
| R05 | [tokens.css][R05] | Kolory, typografia i tokeny |
| R06 | [search.blade.php][R06] | Zakresy, puste wyniki i podpowiedzi |
| R07 | [marka-rama.css][R07] | Rama, karty i nawigacja |
| R08 | [landing.blade.php][R08] | Kolejność publicznej strony |
| R09 | [home.blade.php][R09] | Start i źródła strumienia |
| R10 | [discover.blade.php][R10] | Zakres publicznego strumienia |
| R11 | [layout.blade.php][R11] | Wspólna nawigacja i rama |
| R12 | [post-card.blade.php][R12] | Treść, fotografia i akcje wpisu |
| R13 | [posts/create.blade.php][R13] | Publikacja zdjęcia i widoczność |
| R14 | [SearchController.php][R14] | Parametry, zakresy i paginacja |
| R15 | [SearchQuery.php][R15] | Warunki szukania i filtr czasu |
| R16 | [recipes/show.blade.php][R16] | Akcje przepisu i etykiety statystyk |
| R17 | [RecipeController.php][R17] | Agregacja wykonań i odpowiedzi |
| R18 | [recipes/cooking.blade.php][R18] | Kroki, minutnik i zakończenie |
| R19 | [collections/index.blade.php][R19] | Foldery i puste stany |
| R20 | [collections/show.blade.php][R20] | Zawartość zeszytu i brak dostępu |
| R21 | [recipe-card.blade.php][R21] | Karta wyniku i kafel przepisu |
| R22 | [wybor-zeszytu.blade.php][R22] | Wybór miejsca zapisu |
| R23 | [profile/show.blade.php][R23] | Profil i działania relacyjne |
| R24 | [auth/register.blade.php][R24] | Warianty rejestracji |
| R25 | [notifications.blade.php][R25] | Rodzaje wiadomości i stany |
| R26 | [ZapiszSygnal.php][R26] | Istniejące zdarzenia analityczne |
| R29 | [strony-publiczne.css][R29] | Bazowe reguły publicznych ekranów |
| R30 | [marka-ekrany.css][R30] | Nadpisania kompozycji marki |
| R33 | [app.css][R33] | Warstwy i style bazowe |
| R34 | [CollectionController.php][R34] | Indeks i ostatnie zapisy |

### 25.3. Zewnętrzne źródła techniczne

| ID | Źródło pierwotne | Zastosowanie |
|---|---|---|
| E01 | [W3C — Target Size Minimum][E01] | Minimum normy a produktowe 48 px |
| E02 | [W3C — Reflow][E02] | Używalność przy ograniczonej szerokości |
| E03 | [W3C — Resize Text][E03] | Powiększenie tekstu |
| E04 | [W3C — Focus Not Obscured][E04] | Przyklejone belki i fokus |
| E05 | [W3C — Contrast Minimum][E05] | Kontrast tekstu |
| E06 | [web.dev — Web Vitals][E06] | LCP, INP i CLS |
| E07 | [W3C — Error Identification][E07] | Rozpoznawalne błędy formularza |
| E08 | [W3C — Status Messages][E08] | Komunikaty bez przeładowania |

Źródła zewnętrzne uzasadniają kryteria odbioru, nie dowodzą, że Kuking je obecnie narusza. Dokumenty W3C „Understanding” wyjaśniają kryteria; nie są samodzielnym wynikiem audytu aplikacji.

### 25.4. Zasada ponownego użycia raportu

Przed implementacją porównać wskazany commit z bieżącą gałęzią. Każde zadanie oznaczyć jako: nadal aktualne, już naprawione, zastąpione decyzją lub wymagające nowych danych. Nie odtwarzać historycznego problemu przez cofnięcie późniejszej poprawki.

[R01]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/AGENTS.md
[R02]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/docs/brand/KONSTYTUCJA_MARKI.md
[R03]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/docs/DECISIONS.md
[R04]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/docs/UX_50_PLUS.md
[R05]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/css/tokens.css
[R06]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/search.blade.php
[R07]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/css/marka-rama.css
[R08]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/landing.blade.php
[R09]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/home.blade.php
[R10]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/discover.blade.php
[R11]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/components/layout.blade.php
[R12]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/components/post-card.blade.php
[R13]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/posts/create.blade.php
[R14]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/app/Http/Controllers/SearchController.php
[R15]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/app/Domain/Search/SearchQuery.php
[R16]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/recipes/show.blade.php
[R17]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/app/Http/Controllers/RecipeController.php
[R18]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/recipes/cooking.blade.php
[R19]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/collections/index.blade.php
[R20]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/collections/show.blade.php
[R21]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/components/recipe-card.blade.php
[R22]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/components/wybor-zeszytu.blade.php
[R23]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/profile/show.blade.php
[R24]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/auth/register.blade.php
[R25]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/views/pages/notifications.blade.php
[R26]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/app/Domain/Analytics/ZapiszSygnal.php
[R27]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/docs/FEATURES.md
[R28]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/docs/ROADMAP.md
[R29]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/css/strony-publiczne.css
[R30]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/css/marka-ekrany.css
[R31]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/docs/brand/COPY_STYLE.md
[R32]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/docs/design/DESIGN_SYSTEM.md
[R33]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/resources/css/app.css
[R34]: https://github.com/woogitsu/kuking.pl/blob/81096be714b99eaa02b9e1f13fe5cae81c0c4b90/app/Http/Controllers/CollectionController.php
[E01]: https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html
[E02]: https://www.w3.org/WAI/WCAG22/Understanding/reflow.html
[E03]: https://www.w3.org/WAI/WCAG22/Understanding/resize-text.html
[E04]: https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html
[E05]: https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html
[E06]: https://web.dev/articles/vitals
[E07]: https://www.w3.org/WAI/WCAG22/Understanding/error-identification.html
[E08]: https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html
