## D-063 · PostHog: nie teraz — statystyki zostają własne, w naszej bazie

**Data:** 10 września 2026 · Issue #33 (audyt monitoringu) · Status: **obowiązuje do spełnienia warunków powrotu niżej**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Dwa zdania z zakończenia
> tego wpisu przestały obowiązywać przy **D-092** i nie zostały wtedy
> odnotowane. „PostHog **zostaje w tabeli stacku jako wybór docelowy**
> (`AGENTS.md` §3)" — nie zostaje: `AGENTS.md:116` ma dziś wiersz „Analityka |
> własna, serwerowa (`App\Domain\Analytics\*`) + Cloudflare Web Analytics (bez
> ciasteczek — D-092)". „`.railway/railway.ts` i `.env.example` mają już
> przygotowane `POSTHOG_KEY`/`POSTHOG_HOST` (EU) — ten kawałek nie wymaga
> zmian" — `.env.example` nie zawiera ani jednego `POSTHOG`; zostało wyłącznie
> `.railway/railway.ts:439`. Zmieniła się też przesłanka tytułu („statystyki
> zostają własne, w naszej bazie"): zewnętrzne narzędzie analityki odwiedzin
> JEST wpięte (`app/Support/AnalitykaCloudflare.php`,
> `resources/legal/polityka-prywatnosci.md:54`). Instrukcja wdrożeniowa niżej
> odsyła do zmiennych, których nie ma.

`AGENTS.md` §3 nazywa PostHog (EU) docelową analityką w tabeli stacku, a
issue #33 prosił o „projekt na EU Cloud" i taksonomię zdarzeń. Audyt
zewnętrzny z 9 września (`docs/research/ANALITYKA_STAN_WDROZENIA.md` §0)
potwierdza stan faktyczny: **PostHog nie jest wpięty nigdzie w kodzie.**
Cała taksonomia z `docs/seo/ANALYTICS.md` jest planem, nigdy nie
uruchomionym. Ta decyzja nie zmienia tego stanu — mówi, dlaczego, i pod
jakim warunkiem miałby się zmienić.

### 1. Co PostHog realnie by dał

- **Lejki i porzucenia bez pisania SQL-a** — `docs/seo/ANALYTICS.md` opisuje
  dziesiątki zdarzeń (`onboarding_step_failed`, `recipe_create_abandoned`,
  `photo_upload_failed` z powodem) i budowanie z nich lejków w interfejsie,
  zamiast ręcznie liczonych zapytań w `app/Domain/Analytics`.
- **Session replay i heatmapy** — dziś nie mamy NIC, co pokazuje, GDZIE
  na ekranie ludzie się gubią, tylko ŻE się gubią (z liczby zdarzeń).
- **Segmentację i kohorty** bez pisania nowej migracji za każdym razem, gdy
  ktoś chce inaczej podzielić użytkowników.
- **Feature flagi / A-B testy** — infrastruktura, której dziś nie ma wcale.

### 2. Czego PostHog NIE daje przy tej skali

- **Nie zastępuje statystyk, które produkt pokazuje UŻYTKOWNIKOM** — liczba
  „Ugotowań", licznik obserwujących, statystyki panelu admina. Te muszą
  zostać w PostgreSQL niezależnie od PostHoga, bo muszą być widoczne w
  interfejsie, nie tylko w dashboardzie analitycznym (polityka prywatności,
  sekcja 3: „Statystyki liczymy sami, w naszej własnej bazie" — zdanie,
  nie tylko intencja tego dokumentu).
- **Nie rozwiązuje niczego, czego nie da się dziś policzyć zapytaniem SQL.**
  Przy kilkuset użytkownikach i JEDNYM właścicielu bez zespołu produktowego
  ani analityka, pytania typu „ile osób porzuciło kreator przepisu na kroku
  2" da się odpowiedzieć zapytaniem do `recipe_versions`/`product_signals`
  w kilka minut — dokładnie tak, jak dziś liczy to
  `app/Domain/Analytics/*` (audyt z 9 września to potwierdza).
- **Nie jest za darmo w danych osobowych.** Nawet w konfiguracji „bez
  identyfikatorów" (`docs/legal/COMPLIANCE.md` §5.3) PostHog widzi adres IP
  i cechy przeglądarki każdego zdarzenia — to jest NOWY podprocesor,
  którego dziś nie ma, i NOWE zdanie w tabeli sekcji 3 polityki prywatności
  („Statystyki liczymy sami, w naszej własnej bazie — nie korzystamy z
  żadnego zewnętrznego narzędzia analitycznego" przestałoby być prawdą —
  `PolitykaPrywatnosciWymieniaKazdaUslugeTest` i `DokumentyPrawneNieKlamiaTest`
  oblałyby się tego samego dnia, i słusznie).
- **Nie jest za darmo bez banera zgody.** `docs/legal/COMPLIANCE.md` §5.3-5.4
  ustala wprost: Polska (UODO) nie ma wyjątku dla analityki, jaki mają
  Francja, Włochy czy Hiszpania (ten warunek issue #33 oznaczał `[do
  weryfikacji]` — sprawdzone: UODO nie wydała takich wytycznych, więc
  ostrożne założenie „potrzebny baner" zostaje). Realistyczny wariant
  „bez identyfikatorów, bez cookies" wymaga rygorystycznej konfiguracji
  i konsultacji prawnej, których dziś nikt nie zamówił — dokładnie tak,
  jak COMPLIANCE.md to rekomenduje dla zespołu tej wielkości.
- **Zakaz publicznych rankingów w AGENTS.md/FEATURES.md nie zmienia się
  przez PostHoga** — PostHog to narzędzie WEWNĘTRZNE dla właściciela, nie
  publiczny ranking; to nie jest argument za ani przeciw, tylko potwierdzenie,
  że ta decyzja nie dotyka tamtej.

### 3. Rachunek przy jednym właścicielu i kilkuset użytkownikach

Koszt wdrożenia PostHoga to nie composer/npm (SDK JS jest darmowy do
wpięcia), tylko:

1. decyzja produktowa o banerze zgody ALBO konfiguracji bez-zgodowej
   (`docs/legal/COMPLIANCE.md` §5.4) — pracy prawnej i UX, nie kodu;
2. zmiana `resources/legal/polityka-prywatnosci.md` (nowy wiersz w tabeli
   dostawców, akapit o EOG — PostHog EU Cloud siedzi we Frankfurcie, więc
   przekazywanie poza EOG raczej nie dotyczy, ale to trzeba SPRAWDZIĆ przy
   wdrożeniu, nie założyć);
3. utrzymywanie DRUGIEGO źródła prawdy o zachowaniu użytkowników obok
   `app/Domain/Analytics` — ryzyko dokładnie takiego rozjazdu, jakiego
   ten projekt unika wszędzie indziej (`kuking.media_disk`, dwa liczniki
   budżetu poczty, D-060).

Przy jednym właścicielu, kilkuset użytkownikach i istniejącej, działającej
analityce w PostgreSQL ten koszt dziś przewyższa korzyść. Lejki i porzucenia
da się policzyć zapytaniem; session replay i feature flagi są przydatne przy
zespole i skali, których jeszcze nie ma.

### 4. Decyzja: nie teraz. Warunki powrotu

PostHog **zostaje w tabeli stacku jako wybór docelowy** (`AGENTS.md` §3) —
ta decyzja go stamtąd nie usuwa, tylko mówi, że jeszcze nie teraz. Wracamy
do tematu, gdy zajdzie **którykolwiek** z tych warunków:

1. serwis przekroczy rząd wielkości, przy którym zapytanie SQL przestaje
   wystarczać na odpowiedź w rozsądnym czasie (w praktyce: tysiące aktywnych
   użytkowników dziennie, nie setki);
2. do zespołu dojdzie osoba odpowiedzialna za produkt/wzrost, dla której
   lejki bez pisania SQL-a są codzienną pracą, nie ciekawostką raz na
   miesiąc;
3. pojawi się konkretne pytanie produktowe, którego `app/Domain/Analytics`
   NIE umie dziś odpowiedzieć (nie „może się przyda", tylko realne pytanie
   bez odpowiedzi);
4. właściciel PODEJMIE decyzję o banerze zgody (albo o konfiguracji
   bez-zgodowej po konsultacji prawnej) — to jest warunek WSTĘPNY, nie
   następstwo wdrożenia.

Gdy któryś z warunków zajdzie: wybrać PostHog Cloud **EU** (nigdy US —
`docs/legal/COMPLIANCE.md` §4), wpiąć zgodnie z taksonomią
`docs/seo/ANALYTICS.md`, i **W TYM SAMYM PR-ze** dopisać wiersz w tabeli
dostawców `resources/legal/polityka-prywatnosci.md` oraz usunąć/przeformułować
zdanie „nie korzystamy z żadnego zewnętrznego narzędzia analitycznego" —
dokładnie ten sam mechanizm, który D-024/D-038 pilnują w drugą stronę.
`.railway/railway.ts` i `.env.example` mają już przygotowane
`POSTHOG_KEY`/`POSTHOG_HOST` (EU) — ten kawałek nie wymaga zmian.

**Pliki:** `docs/seo/ANALYTICS.md` · `docs/legal/COMPLIANCE.md` §4-5 ·
`docs/research/ANALITYKA_STAN_WDROZENIA.md` §0 ·
`app/Domain/Analytics/*` · `resources/legal/polityka-prywatnosci.md` §3 ·
`tests/Feature/PolitykaPrywatnosciWymieniaKazdaUslugeTest.php` ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` · `AGENTS.md` §3
