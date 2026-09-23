# Co zrobiono — 18–21 września 2026

Stan na 21.09, późne popołudnie. `main` = `65327e69`, wersja **Alfa 0.68** (w kolejce).

**Liczby:** 58 scaleń do `main` w cztery dni · 23 gałęzie gotowe i czekające w kolejce ·
285 otwartych zgłoszeń, wszystkie oetykietowane.

---

## 1. CO ZOBACZY CZŁOWIEK, KTÓRY GOTUJE

To jest wpis wydania **Alfa 0.68** — pierwsze podbicie wersji od 18 września.

**Formularze przestały gubić wpisany tekst.** Jedno źle wypełnione pole potrafiło
zabrać całą resztę formularza razem z poprawnie wypełnionymi polami. Teraz błąd
zostaje przy swoim polu. Podsumowanie błędów w kreatorze przepisu prowadzi do kroku,
na którym to pole naprawdę jest.

**Postęp w trybie gotowania przeżywa poprawkę przepisu.** Do tej pory każdy zapis
przepisu po cichu odznaczał wszystkie odhaczone kroki.

**Minutnik przy kroku przepisu, ekran nie gaśnie podczas gotowania.** Minutnik liczy
rzeczywisty czas — zmiana godziny w telefonie go nie skróci.

**Powiększone zdjęcie pokazuje swój opis**, a gdy się nie wczyta — informację
i przycisk „Spróbuj ponownie".

**Wpis da się wyjąć z zeszytu** przyciskiem na karcie, nie tylko z wnętrza zeszytu.

**Większe pismo i większe pola do kliknięcia** w stopce, okruszkach nad przepisem
i filtrze na stronie pytań — dociągnięte do minimów UX 50+.

**Wyszukiwarka czyta frazę dosłownie.** „100%" szuka „100%", a nie wszystkiego
z „100". Wklejony adres z polskimi znakami staje się działającym odnośnikiem.

**Licznik znaków pod komentarzem.** Powiadomienie prowadzi wprost do właściwego
wątku, a z komentarza usuniętego mimo odpowiedzi nie wystaje już jego dawna treść.

**Ekrany odwołań i potwierdzeń przestały obiecywać nieprawdę.**

**„Zablokuj" trafia w tę osobę, którą widziałeś na ekranie** — jeśli zmieniła nazwę,
serwis odmawia po polsku zamiast po cichu zablokować kogoś innego.

---

## 2. BEZPIECZEŃSTWO I PRYWATNOŚĆ

- **Wyciek żetonu w adresie** zamknięty (#781).
- **`kind` poza `$fillable`** — pole, które nie powinno dać się ustawić z formularza (#722).
- **Strażnik R47**: poświadczenia, zaszyte hasła, ochrona CSRF (#917).
- **Dwie wyrocznie hasła bez licznika** — audyt 38 limiterów (#790). Odwołanie
  i cofnięcie usunięcia konta są chronione jak logowanie.
- **Paczka danych milczała o zdjęciach** odrzuconych i skasowanych (#692, #712).
- **Turnstile i `connect-src`** (#706), **strefa połączenia** (#695).
- **Rejestr potwierdzeń żądań RODO** — zaprojektowany, z migracją, ścieżką zapisu
  w jednej transakcji z wymazaniem konta i automatem retencji 36 miesięcy. Czeka w kolejce.
- **Materiał dla prawnika** — 17 rozjazdów „co obiecuje polityka" wobec „co robi kod",
  każdy z dowodem w postaci ścieżki i wiersza. Czeka w kolejce.

---

## 3. CO ZMIERZONO, A NIE BYŁO WIADOMO

**Kiedy naprawdę znika zdjęcie po decyzji moderacyjnej.** Sześć wersji opisu, żadna
nie oparta na uruchomieniu aplikacji. Siódma jest z pomiarem: podpisany adres wydany
przed decyzją **działa przez cały pozostały czas ważności**, a odcina go wyłącznie
zegar. Moderator nie jest odcięty w żadnym stanie — dostaje bajty, nie przekierowanie.
Gościa odcina **ban**, nie usunięcie treści.

**Czyszczenie cache CDN nie działa na produkcji** — brak dwóch zmiennych. Ale zmierzono,
że **bajty zdjęć nie przechodzą przez strefę Cloudflare**, więc purge tej strefy i tak
nie wyczyściłby obiektu. Okno narażenia to ~5 minut, nie „do końca TTL".

**Martwe reguły CSS**: ze 173 deklaracji **152 to prawdziwy martwy kod**, 18 to
fałszywe trafienia miernika, który zwiększał mianownik dopiero po znalezieniu
przykrywacza. Jedno z fałszywych kazałoby usunąć regułę trzymającą **48 px wysokości
pól na ekranie logowania**.

**Klaster eksportu danych**: siedem zgłoszeń to **jeden moduł, nie siedem usterek**.
Wspólna przyczyna: status paczki jest deklaracją, nie skutkiem. Każde z siedmiu ma
test odtwarzający objaw.

---

## 4. NARZĘDZIA I INFRASTRUKTURA

- **CI przeszło na własne runnery** — od 06:49 zero minut z pakietu GitHuba,
  potwierdzone pustym rozliczeniem przy każdym przebiegu.
- **Czerwień na `main` zniknęła po przełączeniu** — krok „Klient PostgreSQL 18"
  padał na runnerze GitHuba, na własnym przechodzi. Usterki w repozytorium nie było.
- **Nazwa bazy testowej liczona z katalogu, nie z `.git`** (#920). To naprawa
  przyczyny, dla której wszystkie stanowiska floty widziały tę samą bazę i niszczyły
  sobie nawzajem dane w trakcie testów.
- **Strażnik kolizji w kolejce** — sprawdza gałęzie **przeciw sobie**, nie przeciw
  `main`: zdublowane numery decyzji, migracje o tej samej nazwie, zdublowane definicje.
- **Uproszczenie CI** (#783), **klient PostgreSQL 18 w CI** (#929),
  **przyrząd kontroli ujemnej** (#727) i naprawa jego sprzecznego raportowania.

---

## 5. PORZĄDKI

- **Odzysk po awarii z 20 września**, gdy repozytorium kanoniczne straciło `.git`:
  wszystkie gałęzie odnalezione, praca odtworzona.
- **107 osieroconych worktree** zweryfikowanych jako bezpieczne.
- **Audyt pracy lokalnej**: znaleziono i uratowano pracę, która istniała wyłącznie
  na dysku — dwie gotowe gałęzie poza kolejką i trzy stanowiska z niezacommitowanymi
  zmianami.
- **Triaż zgłoszeń**: ze 185 bez etykiety do zera; zamknięto kilkanaście z dowodem
  (SHA plus cytat z kodu, nie „wygląda na naprawione").
- **Docker**: przygotowany spis 2534 osieroconych woluminów i skrypt sprzątający
  z potrójnym zabezpieczeniem. **271 GiB do odzyskania.**

---

## 6. CZEKA W KOLEJCE — 23 gałęzie

Najważniejsze: kontrakt nazw baz testowych (PR #966) · strażnik martwych reguł CSS
wraz z wpięciem w CI (PR #960) · złożenie zamykające `P0 #775` (usuwanie z zeszytu
z potwierdzeniem i uczciwym komunikatem o notatkach) · rejestr RODO · materiał
prawny · wersja Alfa 0.68 · sonda czyszczenia CDN w `/health` ·
pomiar odcięcia dostępu do pliku · przeniesienie najdłuższego joba CI na `ubuntu-latest`.

---

## 7. CO ZOSTAJE OTWARTE

- **`#941`** — publiczna strona tagu pokazuje tytuł i zdjęcie przepisu o zawężonej
  widoczności. Jedyne `P0`, które szkodzi teraz. W naprawie.
- **Kompletność paczki danych wobec art. 15** — wymaga decyzji prawnej, nie technicznej.
- **Czy rejestr RODO ma trwale wiązać potwierdzenie z kontem** — decyzja podjęta,
  cena zapisana, do oceny prawnej.
- **Obowiązek zgłoszenia CSAM organom** — procedura istnieje i nazywa numery;
  otwarte jest, czy jako dostawca hostingu mamy obowiązek dodatkowy z art. 18 DSA.
- **Reguła „cyfra rośnie przy zmianie widocznej"** nie ma strażnika. Ten, który
  powstaje, pilnuje spójności dwóch miejsc z wersją, a nie obowiązku podbicia.
