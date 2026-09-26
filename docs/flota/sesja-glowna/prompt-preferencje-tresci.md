# Prośba o opinię: przyciski „więcej / mniej takich treści” i zakaz algorytmicznego feedu w Kukingu

Jesteś doświadczonym projektantem produktów społecznościowych. Znasz systemy rekomendacji, UX dla osób starszych i prawo UE (RODO, DSA). Proszę o krytyczną, niezależną opinię. Nie potwierdzaj naszych założeń tylko dlatego, że je mamy. Jeśli uważasz, że się mylimy, napisz to wprost i uzasadnij.

---

## 1. Czym jest Kuking

- **Kuking.pl** to polska społeczność ludzi, którzy gotują. To **nie jest baza przepisów**. Sercem jest pytanie „Co dziś ugotowałeś?”: robisz zdjęcie, dopisujesz kilka słów i publikujesz.
- **Grupa docelowa to osoby 50+**, ale produkt celowo **nie jest oznaczany jako „dla seniorów”**. Interfejs ma za to twarde zasady dostępności:
  - tekst co najmniej 18 px, przyciski co najmniej 48 px;
  - brak interakcji na hover i swipe;
  - komunikaty po polsku, które mówią, co zrobić;
  - poprawnie wpisane dane nigdy nie znikają.
- **Najważniejsza interakcja** to „Ugotowałem” (ktoś ugotował czyjś przepis), ważniejsza niż lajk. Liczników lajków nie eksponujemy.
- **Etap:** zamknięta alfa. Na produkcji jest kilka kont; celem jest pierwszych 20 realnych użytkowników i testy z osobami 50+. Nie mamy więc danych o zachowaniu.
- **Technika:** Laravel, PostgreSQL, modularny monolit. Świadomie bez mikroserwisów, bez Redisa i bez osobnej wyszukiwarki. Jeden właściciel i mały budżet.

## 2. Obecne zasady dotyczące feedu (cytaty i streszczenie z zasad projektu)

- **Feed obserwowanych jest chronologiczny:** `WHERE author_id IN (...) ORDER BY published_at DESC`. Bez rankingu.
- Wytyczna: „**Nie projektuj skomplikowanego rankingu bez danych** — algorytmiczny feed natychmiast dzieli użytkowników na »widzianych« i »niewidzianych« i wyłącza publikowanie u większości.”
- Na liście anty-wzorców, których „**nie wprowadzamy nigdy**”, obok streaków, publicznych rankingów użytkowników i sztucznych kont, jest **„algorytmiczny feed”**.
- **Gdy feed obserwowanych jest pusty**, pokazujemy „Świeżo z Kuking” (Odkrywanie) i propozycje osób. Odkrywanie działa dziś tak:
  - chronologia, **jeden wpis na autora**, żeby nikt nie zalał strony;
  - odsiewa blokady i treści niewidoczne dla widza.
- **„Kuking na dziś”** to tablica: kilka pozycji wybranych przez gospodarza (moderatora społeczności) plus część automatyczna.
- **Istniejące kontrole użytkownika:** obserwowanie osób i tagów (tematów), blokada osoby.

## 3. Pomysł właściciela

Przy wpisie, najpewniej w menu trzech kropek, dodać opcje w rodzaju „**Chcę widzieć więcej takich treści**” i „**Chcę widzieć mniej takich treści**”, żeby „**uczyć, co lubi dana osoba**”.

Właściciel świadomie chce **przemyśleć zakaz algorytmicznego feedu**. Nie chce go ani automatycznie bronić, ani automatycznie znosić.

## 4. Co ustalił nasz pierwszy research (streszczenie, do zakwestionowania)

1. **„Więcej takich”** da się zrobić bez żadnej zmiany zasad, jako skrót do „obserwuj tę osobę albo ten temat”.
2. **„Mniej takich”** jako jawne, odwracalne ukrycie osoby albo tematu:
   - działa **tylko w Odkrywaniu**, w automatycznej części tablicy i w propozycjach osób;
   - **nie działa w feedzie obserwowanych** ani w wyszukiwarce;
   - jest filtrem, a nie wagą; kolejność zostaje chronologiczna;
   - lista ukrytych jest w Ustawieniach, z przyciskiem „Przywróć”.
3. **Twarda reguła:** sygnał należy wyłącznie do widza.
   - Nie sumujemy go między ludźmi.
   - Nie wpływa na zasięg autora u innych, na moderację ani na żadne liczby.
   - Autor się o nim nie dowiaduje.
   - Cel: uniknąć masowego „mniej” wobec kogoś i efektu „niewidzianych” nowych autorów.
4. **Wagi tagów i uczenie z zachowania** (czas oglądania, kliknięcia) research odradza:
   - to już ranking;
   - na starcie nie ma danych;
   - to profilowanie w rozumieniu RODO;
   - powstaje dokładnie ten mechanizm, przed którym ostrzegają nasze zasady.
5. **Inne serwisy:**
   - Facebook i Instagram tylko obniżają ranking, co jest częstą skargą;
   - YouTube („Nie polecaj kanału”), Pinterest (przełączniki tematów) i Mastodon (chronologia plus wyciszenia) pokazują, że dla starszych osób zrozumiały jest filtr z listą w ustawieniach.
6. **Prawo:**
   - DSA art. 27 (przejrzystość systemów rekomendacji) formalnie prawdopodobnie nie obowiązuje mikro- i małych przedsiębiorców (art. 19; do potwierdzenia przez prawnika);
   - definicja „systemu rekomendacji” z art. 3 lit. s jest jednak szeroka i dzisiejsze Odkrywanie prawdopodobnie już ją spełnia;
   - jawne „nie pokazuj mi X” to raczej ustawienie konta (art. 6 ust. 1 lit. b RODO) niż profilowanie;
   - dane muszą trafiać do eksportu i być usuwane razem z kontem.
7. **Propozycja decyzji:**
   - feed obserwowanych i feed tagów zawsze chronologiczne;
   - w Odkrywaniu wolno **wykluczać**, ale nie **układać**;
   - żadnego wnioskowania z zachowania bez nowej decyzji właściciela.

## 5. O co proszę

Odpowiedz na każdy punkt. Tam, gdzie się da, oprzyj się na konkretnych przykładach produktów, badaniach albo danych, a jeśli czegoś nie jesteś pewien, zaznacz to.

1. **Zakaz algorytmicznego feedu.** Czy jest słuszny dla społeczności kulinarnej 50+ na etapie alfy i później przy kilku tysiącach użytkowników? Jakie są najmocniejsze argumenty za jego złagodzeniem i przeciw niemu? Czy efekt „widzianych i niewidzianych” jest dobrze udokumentowany i czy dotyczy małych społeczności?
2. **Modele.** Oceń te warianty i zaproponuj własne, jeśli widzisz lepsze:
   - (a) czysta chronologia plus wykluczenia;
   - (b) chronologia plus jawne preferencje tematów wybrane przez użytkownika (wagi ustawiane ręcznie);
   - (c) ranking tylko w Odkrywaniu, feed obserwowanych chronologiczny;
   - (d) kilka feedów do wyboru przez użytkownika (jak Bluesky), domyślnie chronologia;
   - (e) ranking z uczeniem z zachowania.

   Dla każdego podaj: wpływ na nowych autorów i częstotliwość publikowania, zrozumiałość dla osób 50+, ryzyka (bańka, nadużycia, manipulacja), koszt budowy i utrzymania przy małym zespole oraz ryzyko prawne.
3. **„Więcej takich”.** Czy skrót do obserwowania wystarczy, czy ludzie oczekują czegoś innego? Jak to nazwać, żeby było jasne, co się stanie?
4. **„Mniej takich”.**
   - Co powinno dać się ukryć: osobę, temat, pojedynczy wpis czy rodzaj treści (np. pytania albo przepisy mięsne)?
   - Na zawsze, czasowo (np. 30 lub 60 dni), czy do wyboru?
   - Gdzie ma działać?
   - Jak uniknąć tego, że ktoś przypadkiem ukryje sobie połowę serwisu?
5. **Nazwy i umiejscowienie w UI dla 50+.** Zaproponuj 5–8 polskich wariantów nazw i oceń je. Czy menu trzech kropek jest dobrym miejscem dla tej grupy, czy lepiej inaczej (np. pod wpisem, po kliknięciu, w ustawieniach)?
6. **Kolejność i pomiar.** Co zbudować teraz, a co dopiero po danych? Jakie wskaźniki i progi powinny uruchomić ewentualne przejście na ranking? Jak w 1–2 tygodnie przetestować pomysł z 20 osobami bez budowania kodu?
7. **Prawo i zaufanie.** Czy coś w naszej ocenie RODO i DSA jest błędne albo niepełne? Czy warto dobrowolnie opisać „jak dobieramy wpisy”, nawet jeśli prawo tego nie wymaga?
8. **Czego nie widzimy?** Ryzyka, alternatywy albo pytania, których nie zadaliśmy.

## 6. Forma odpowiedzi

- **Całą odpowiedź zapisz jako jeden dokument Markdown (.md)**, gotowy do wklejenia do repozytorium, z nagłówkami, listami i tabelami. Jeśli możesz, podaj go jako plik do pobrania; jeśli nie, jako jeden blok ```markdown.
- **Uzasadniaj rozbudowanie.** Każda rekomendacja ma mieć: tezę, argumenty, kontrargumenty i to, co by zmieniło Twoje zdanie.
- **Podpieraj się źródłami:** badania naukowe, raporty (np. Pew Research, Reuters Institute, Nielsen Norman Group), oficjalne komunikaty i dokumentacja platform, teksty prawne (RODO, DSA, wytyczne EROD/UODO), wypowiedzi praktyków i projektantów produktów, studia przypadków (np. zmiany feedu Instagrama 2016, Facebooka 2018, feedy do wyboru w Bluesky, Mastodon).
  - Przy każdym źródle podaj autora albo instytucję, rok i link, jeśli go znasz.
  - Wyraźnie oddzielaj **fakty ze źródeł** od **własnej opinii i szacunków**.
  - Jeśli nie masz pewności, że źródło istnieje albo mówi to, co piszesz, zaznacz to wprost („niezweryfikowane”). Nie wymyślaj źródeł ani liczb.
- Na końcu dokumentu dodaj sekcję **„Źródła”** z pełną listą i sekcję **„Poziom pewności”** z oceną, które wnioski są dobrze udokumentowane, a które są hipotezami.
- Po polsku, konkretnie, bez lania wody.
- Zacznij od **3–5 zdań rekomendacji** (co zrobiłbyś na naszym miejscu i dlaczego), potem odpowiedzi na punkty 1–8.
- Na końcu **tabela decyzji**: pytanie, Twoja rekomendacja, siła przekonania (niska, średnia, wysoka), główne ryzyko.
- Jeśli uważasz, że któreś z naszych założeń jest błędne, wypisz je osobno na samym początku.
