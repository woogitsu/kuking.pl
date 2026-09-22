# Zlecenie: audyt wielodyscyplinarny — kod, produkt, prawo, rynek

**Dla:** modelu spoza tego projektu (GPT-6) z dostępem do odczytu repozytorium.
**Zleca:** właściciel, 8 września 2026.
**Wynik:** `docs/AUDYT_GPT_2026-09.md`.

---

## Jak tego użyć

Treść poniżej, od linii „# AUDYT WIELODYSCYPLINARNY", jest **promptem do
skopiowania w całości**. Wszystko nad nią to instrukcja dla nas, nie dla modelu.

**Podziel na tury.** To jest zadanie na kilka godzin pracy modelu. Zlecone
jednym rzutem zostanie skrócone. Kolejność, która ma sens:

1. §3.1–3.2 — kod i dane;
2. §3.3–3.5 — produkt, UX, mierzalność;
3. §3.6–3.7 — prawo i infrastruktura;
4. §4 — research zewnętrzny;
5. §3.8 — weryfikacja poprzedniego audytu;
6. złożenie raportu wedle §6.

**Najcenniejsze są §3.8 i §9.** Pierwsza każe rozstrzygnąć znaleziska
`docs/AUDYT_2026-09.md`, których nikt nie sprawdził. Druga wymusza przyznanie
się, czego model nie zweryfikował — bez niej dostaniemy pewny siebie tekst
zamiast audytu.

**Ten plik żyje.** Każda kolejna pułapka, na którą stracimy czas, dopisuje się
do §2. Sekcja „stan na dziś" (§8) starzeje się najszybciej — przed wysłaniem
sprawdź, czy jest aktualna, bo nieaktualna lista każe modelowi zgłaszać rzeczy
już zrobione.

---

# AUDYT WIELODYSCYPLINARNY — kuking.pl

Masz dostęp do repozytorium `woogitsu/kuking.pl`. Twoim zadaniem jest audyt
całego przedsięwzięcia: kodu, produktu, treści, prawa, infrastruktury i pozycji
rynkowej. Nie jest to przegląd jednego PR-a — masz spojrzeć na całość i
powiedzieć właścicielowi rzeczy, których sam nie widzi.

Właściciel prowadzi to sam. Pisz po polsku.

---

## 0. Zanim cokolwiek napiszesz — przeczytaj, w tej kolejności

1. `AGENTS.md` — komplet zasad projektu, JEDYNE źródło prawdy. `CLAUDE.md` jest
   tylko wskaźnikiem na niego.
2. `docs/PRODUCT.md` i `docs/UX_50_PLUS.md` — czym ten serwis JEST i dla kogo.
3. `docs/ROADMAP.md` — co należy do MVP, a co do V1/V2. Krótki, 111 linii.
4. `docs/DECISIONS.md` — D-001..D-039, rejestr decyzji z uzasadnieniami.
   Decyzja z tego pliku nie jest „propozycją do przemyślenia" — jest ustaleniem.
   Jeśli uważasz, że któraś jest zła, argumentuj wprost przeciw NIEJ, po numerze.
5. `docs/HANDOVER.md` — stan prac, co poszło źle w poprzednich sesjach.
6. `docs/legal/BRAMKA_BETY.md` — macierz tego, co blokuje otwarcie bety.
7. `docs/AUDYT_2026-09.md` — **audyt zrobiony przez inny model, 237 znalezisk.
   NIKT GO NIE ZWERYFIKOWAŁ.** Traktuj jako materiał wejściowy, nie jako prawdę
   (patrz zadanie w §3.8).

Reszta map: `docs/ARCHITECTURE.md`, `docs/DATABASE.md`, `docs/FEATURES.md`,
`docs/FLOWS_AND_SCREENS.md`, `docs/MEDIA_PIPELINE.md`, `docs/MODERATION.md`,
`docs/SECURITY_PRIVACY_LEGAL.md`, `docs/TESTING.md`, `docs/SEO_ANALYTICS_GROWTH.md`,
`docs/COSTS.md`, `docs/MONETIZATION.md` oraz katalogi `docs/decyzje/`,
`docs/design/`, `docs/infra/`, `docs/legal/`, `docs/product/`, `docs/research/`.

---

## 1. Czym jest ten produkt (żebyś nie audytował czegoś innego)

- Kuking to **społeczność ludzi, którzy gotują**, a NIE baza przepisów.
- Grupa docelowa: **50+**, ale produkt nigdy nie jest oznaczany jako „dla seniorów".
- Główna akcja: „Co dziś ugotowałeś?" → zdjęcie + kilka słów → Opublikuj.
- **„Ugotowałem" jest ważniejsze niż lajk** i zawsze powiadamia autora przepisu.
  To jest pętla, na której stoi cały produkt.
- Feed obserwowanych jest **chronologiczny**, bez algorytmu. To decyzja, nie brak.
- Stack: Laravel 13 · PHP 8.4 · Blade + Livewire 4 · Tailwind 4 · PostgreSQL 18 ·
  Railway. **Modularny monolit. Zero mikroserwisów, SPA, Redisa i osobnego
  silnika wyszukiwania.** Propozycja, która to łamie, jest odrzucona z definicji —
  chyba że pokażesz, że bez tego produkt nie zadziała, i powiesz to wprost.

Nienaruszalne zasady, które musisz znać przed oceną kodu:

- `status` i `role` użytkownika **nigdy** w `$fillable`;
- **UUID w adresie to nie autoryzacja** — każde wejście przez Policy;
- zmiana schematu = migracja + test + `docs/DATABASE.md` + rollback;
- bugfix = test regresyjny;
- testy chodzą na **PostgreSQL**, nigdy na SQLite;
- **ważne funkcje działają bez JavaScriptu**;
- UX 50+: tekst ≥ 18 px, przyciski ≥ 48 px, ikona nigdy sama, bez hover/swipe,
  błędy po polsku mówiące CO ZROBIĆ, **poprawne dane nigdy nie znikają**;
- brak destrukcyjnych operacji na produkcji.

---

## 2. Metoda: MIERZ, NIE ZGADUJ

To jest najważniejsza część tego zlecenia.

**Każde twierdzenie ma mieć dowód.** Przy każdym znalezisku podaj: `plik:linia`,
sposób pomiaru i jak to odtworzyć. „Wygląda na to, że…" bez pomiaru jest
bezwartościowe i gorsze niż milczenie, bo właściciel zacznie naprawiać zmyśloną
diagnozę.

**Dla testu zadaj pytanie: co musiałoby się zepsuć w kodzie, żeby ten test padł?**
Odpowiedź zmierz — zepsuj kod naprawdę, uruchom test, potem cofnij. Test, który
przechodzi także po zepsuciu tego, czego pilnuje, jest wydmuszką i to jest
znalezisko samo w sobie.

**Rozdziel w raporcie: ZMIERZONE / WYWNIOSKOWANE / NIEROZSTRZYGNIĘTE.** Trzeciej
kategorii nie zamiataj — wypisz ją.

### Pułapki tego konkretnego repozytorium (kosztowały już czas — nie wpadaj)

1. **CSS.** `resources/css/app.css` ma wczesny `@layer components {`, który
   zamyka się ~1800 linii dalej. Wszystko za nim jest BEZ warstwy i bije reguły
   warstwowane niezależnie od kolejności. **Każde twierdzenie o CSS sprawdzaj na
   ZBUDOWANYM arkuszu** `public/build/assets/app-*.css`, nie na źródle.
2. **Baza testowa.** `tests/bootstrap.php` różnicuje bazę wyłącznie po nazwie
   `git worktree`. Dwa równoległe przebiegi w JEDNYM checkoucie trafiają w tę
   samą bazę `kuking_test` i test cofający migrację zabiera schemat drugiemu.
   Objaw: `relation "..." does not exist`. To wygląda jak znalezisko, a jest
   artefaktem pomiaru. Jeśli pracujesz równolegle — własna baza na proces.
3. **Znaczniki czasu mają dokładność do SEKUNDY** (`timestampsTz()` →
   `timestamptz(0)`). `ORDER BY` po samym znaczniku jest niedookreślony przy
   remisie, a przy paginacji `LIMIT`/`OFFSET` gubi i dubluje wiersze. Naprawione
   dla list w #141 — **sprawdź, czy nie zostało tego więcej** (np. sortowania
   w widokach, zapytania w `app/Domain/`).
4. **`trustProxies(at: '*')`** to w Laravelu `setTrustedProxies([REMOTE_ADDR])`,
   a Symfony bierze wtedy **OSTATNI** wpis `X-Forwarded-For`, nie pierwszy.
   Dokumentacja projektu twierdziła odwrotnie i było to nieprawdą.
5. **CI kopiuje `.env.example` do `.env`.** Środowisko testowe zależy więc od
   tego pliku; `<env>` w `phpunit.xml` bije `.env`, ale nie bije zmiennej
   z otoczenia. Zanim uznasz test za zielony, sprawdź, czy w ogóle coś sprawdza.
6. **`id` to UUID v7** — rośnie z czasem, więc nadaje się na drugi klucz
   sortowania i daje porządek chronologiczny.
7. **CI chodzi tymczasowo na runnerach GitHuba** (14 jobów, poprawka do D-028),
   bo własna pula była niedostępna. Pełny zestaw trwa ~3,5 minuty. Możesz z tego
   korzystać: wypchnij gałąź i pozwól CI zweryfikować, zamiast zgadywać.

Jeśli `composer install` nie przejdzie w Twoim środowisku — **powiedz to wprost
i nie udawaj, że testy chodziły.** Poprzednia sesja straciła na tym wiarygodność.

---

## 3. Zakres — osiem dyscyplin

Dla każdej podaj znaleziska wedle formatu z §5.

**3.1. Poprawność i bezpieczeństwo kodu.** Autoryzacja (Policy przy KAŻDYM
wejściu, nie tylko w kontrolerze), walidacja, `$fillable`, wycieki danych między
użytkownikami, blokady (`blocks`) egzekwowane wszędzie, obsługa błędów, warunki
wyścigu, N+1, zapytania bez limitu, transakcje. Szukaj **klas błędów**, nie
pojedynczych wystąpień: jeśli znajdziesz jeden, sprawdź, gdzie jeszcze ten sam
kształt występuje.

**3.2. Dane i schemat.** Spójność `docs/DATABASE.md` z rzeczywistością (sprawdź
`psql \d+` na świeżo zmigrowanej bazie, nie z migracji), ograniczenia CHECK,
indeksy pod realne zapytania, kaskady przy usuwaniu konta, co się dzieje
z cudzymi treściami, gdy autor znika.

**3.3. UX dla 50+.** Nie audytuj „dostępności w ogóle" — audytuj TE zasady.
Ścieżki krytyczne przejdź krok po kroku i powiedz, gdzie człowiek utknie:
rejestracja → pierwsze zdjęcie → pierwszy przepis → „Ugotowałem" → komentarz.
Sprawdź, czy komunikaty błędów mówią, co zrobić. Sprawdź, co działa bez JS.
Osobno: czy pierwsze 60 sekund nowej osoby ma cokolwiek do pokazania.

**3.4. Produkt i pętle.** Czy pętla „Ugotowałem → powiadomienie autora → powrót"
jest domknięta w kodzie i widoczna w interfejsie. Gdzie produkt obiecuje coś,
czego nie dowozi. Co jest zbudowane, a nie ma po co istnieć. **Czego brakuje,
żeby ktoś wrócił drugi raz** — to jest ważniejsze niż lista funkcji.

**3.5. Mierzalność.** `product_signals` zbiera dziś dwa zdarzenia i **nikt tej
tabeli nie czyta**; `users` nie ma znacznika ostatniej wizyty. Bramka V1
w `ROADMAP.md` brzmi „dopiero gdy WAC i D30 pokazują powroty" — czyli jest
dziś niemierzalna. Zaproponuj **najmniejszy** zestaw sygnałów i jeden raport,
który tę bramkę zamienia w liczbę. Bez systemu analitycznego z zewnątrz.

**3.6. Prawo i zgodność (Polska/UE).** RODO i DSA dla platformy z treściami
użytkowników: obowiązki informacyjne, moderacja i odwołania, retencja, eksport
i usunięcie konta, treści nielegalne, małoletni. Sprawdź, czy dokumenty prawne
w `resources/legal/` mówią prawdę o tym, co robi kod — to była już raz realna
usterka. Wskaż konkretne braki, nie ogólniki o „zgodności".

**3.7. Infrastruktura i odporność.** Wdrożenie, kopie zapasowe i **próbne
odtworzenie** (bramka alfy wymaga „restore przetestowany", a w `docs/infra/`
nie ma o tym ani słowa), monitoring błędów (`composer.json` nie ma dziś ŻADNEGO
pakietu), koszty, limity, nagłówki bezpieczeństwa, co się stanie przy awarii.

**3.8. Weryfikacja poprzedniego audytu.** `docs/AUDYT_2026-09.md` ma 237
znalezisk, których nikt nie sprawdził. Przejdź przez jego dziesięć
najpoważniejszych i **rozstrzygnij każde: POTWIERDZONE / OBALONE / NIEAKTUALNE**,
z dowodem. Obalone znalezisko jest równie cenne jak nowe — fałszywa diagnoza
w dokumencie jest gorsza niż jej brak.

---

## 4. Research zewnętrzny

Osobna sekcja raportu. Interesuje mnie, **co robią inni i co z tego wynika DLA
TEGO produktu**, a nie encyklopedia rynku.

- **Najbliższy precedens to Cookpad** — jego „Cooksnap" jest dokładnie tym, czym
  ma być „Ugotowałem". Sprawdź, jak to rozwiązali, co im działa, na czym się
  wywrócili i czego świadomie nie zrobili.
- Polski rynek treści kulinarnych (Kwestia Smaku, AniaGotuje, Przepisy.pl i
  podobne) — to są media z przepisami, nie społeczności. Gdzie jest luka.
- **Prawdziwa konkurencja dla grupy 50+ w Polsce to grupy na Facebooku.** Zbadaj,
  co tam działa, dlaczego ci ludzie tam zostają i czego im tam brakuje.
- Czego grupa 50+ oczekuje od serwisu, na czym się zniechęca, co ją wypycha.
  Oprzyj to na badaniach, nie na stereotypie o „seniorach".

Wymagania: **podawaj źródła i daty**. Oddzielaj fakt od opinii. Jeśli czegoś nie
udało się ustalić, napisz „nie ustalono" — zmyślona liczba jest tu najgorsza
z możliwych odpowiedzi. Każdą obserwację kończ zdaniem „co to znaczy dla Kukinga".

---

## 5. Format każdego znaleziska

    ID · [P0/P1/P2] · [dyscyplina]
    Co jest nie tak — jedno zdanie.
    Dowód: plik:linia + jak zmierzone (polecenie, zapytanie, przebieg testu).
    Jak odtworzyć: kroki.
    Skutek dla człowieka: co traci użytkownik albo właściciel. Nie „to zła praktyka".
    Propozycja: najmniejsza zmiana, która to zamyka.
    Koszt: godziny. Ryzyko niezrobienia: jedno zdanie.
    Kto może to zrobić: repozytorium / panel Railway/Cloudflare / decyzja właściciela.

Ta ostatnia linia jest obowiązkowa. Właściciel jest sam i musi od razu wiedzieć,
co jest do zlecenia, a co do kliknięcia w panelu.

**Priorytety:** P0 = blokuje otwarcie dla ludzi albo naraża cudze dane.
P1 = psuje doświadczenie albo zaufanie, ale da się otworzyć. P2 = reszta.
**Nie inflacjuj P0.** Jeśli wszystko jest P0, nic nie jest.

---

## 6. Struktura raportu

Poprzedni audyt miał 1814 linii i nikt go nie przeczytał w całości. Zrób inaczej:

1. **Jedna strona na początek**: dziesięć najważniejszych rzeczy, po jednym
   zdaniu każda, w kolejności, w jakiej bym je robił.
2. **Tabela wszystkich znalezisk**: ID, waga, dyscyplina, jedno zdanie, koszt.
3. **Pełny opis tylko dla P0 i P1.**
4. **P2 jako lista** — bez rozwijania.
5. **Research** — osobno, wedle §4.
6. **Czego nie udało się sprawdzić i dlaczego** — obowiązkowa sekcja.
7. **Weryfikacja poprzedniego audytu** (§3.8).

Zapisz raport jako `docs/AUDYT_GPT_2026-09.md`.

---

## 7. Czego NIE robić

- **Nie zmieniaj kodu produkcyjnego przy okazji audytu.** Znalezisko z propozycją
  łatki — tak. Zmiana w `app/`, `config/`, `routes/`, `database/`, `resources/`
  wchodzi osobnym, małym PR-em, jeden PR na jedno znalezisko, z testem
  regresyjnym. Sondy diagnostyczne cofaj w tej samej turze, w której je stawiasz.
- **Nie pushuj do `main`** i nie zamykaj issues.
- **Nie proponuj funkcji z V1/V2** (planner, grupy, forki), dopóki bramki MVP są
  otwarte — `ROADMAP.md` wiąże je z metrykami powrotów.
- **Nie proponuj zmiany stacku.** Mikroserwisy, SPA, Redis, Elasticsearch to
  decyzje zamknięte.
- **Nie wyłączaj, nie pomijaj i nie „naprawiaj" testów przez rozluźnienie
  asercji.** Padający test jest informacją.
- **Nie ufaj dokumentacji.** Bywa i przesadzona, i zaniżona względem kodu.
  Sprawdzaj kod, potem poprawiaj dokument.

---

## 8. Stan na dziś — nie zgłaszaj tego jako nowe

Wieczorem 8 września weszło na produkcję: limity zapytań na 66 z 68 tras
zapisujących (#133), normalizacja `X-Forwarded-For` z testami (#139), drugi klucz
sortowania w paginacji (#141), komenda nadania roli administratora (#131), poczta
gotowa do włączenia (#136), prawdziwe okresy retencji w polityce prywatności
(#128), data wydania w stopce (#130), grupy składników (#135), całe CI na
runnerach GitHuba (#138, #140).

**Otwarte i znane — potwierdź albo obal, ale nie odkrywaj na nowo:**

- brak monitoringu błędów (żadnego pakietu w `composer.json`);
- brak dokumentu o kopiach zapasowych i braku próbnego odtworzenia;
- token krawędziowy (granica zaufania proxy) — nie do zamknięcia z repozytorium;
- `product_signals` tylko zapisywane, nigdy czytane;
- po stronie właściciela: tożsamość administratora do dokumentów prawnych, wybór
  dostawcy poczty, potwierdzenie `FILESYSTEM_DISK` i niepubliczności bucketu.

---

## 9. Jak sam sprawdzisz, że ten audyt jest dobry

Zanim oddasz raport, odpowiedz sobie na trzy pytania i dopisz odpowiedzi na końcu:

1. Które moje znalezisko było **niewygodne** — sprzeczne z tym, co mówi
   dokumentacja albo z tym, co właściciel chciałby usłyszeć?
2. Co z tego, co zgłaszam, sprawdziłem **uruchomieniem**, a co tylko czytaniem?
3. Gdyby właściciel zrobił wyłącznie pierwsze trzy rzeczy z mojej listy — czy
   serwis byłby gotowy przyjąć dwadzieścia realnych osób? Jeśli nie, to znaczy,
   że kolejność jest zła. Popraw ją.
