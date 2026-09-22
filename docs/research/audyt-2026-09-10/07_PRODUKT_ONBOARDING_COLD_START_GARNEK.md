# Audyt 7 — produkt, onboarding, cold start i migracja z Garnek.pl

**Bazowy commit:** `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 2026-09-10

## Wniosek

Rdzeń produktowy jest trafny: **„pokaż, co dziś ugotowałeś”**, chronologiczny feed, zwykłe zdjęcia, komentarze, Zeszyt i eksport danych. To jest lepszy punkt wejścia dla dawnych użytkowników Garnek.pl niż próba odtworzenia całego starego serwisu.

Największym ryzykiem nie jest brak funkcji. Jest nim **cold start**: nostalgia może wygenerować wejście tylko raz; jeśli po rejestracji użytkownik zobaczy pustkę albo obcych ludzi bez interakcji, kampania spali najsilniejszą grupę pierwszego kontaktu.

**Ocena produktu: 8,5/10. Gotowość growth/cold-start: 6/10.**

## Ustalenia

### PROD1 — P0 jako bramka kampanii — najpierw realna społeczność, potem szerokie pozyskanie

Otwarte issue #29 identyfikuje „pierwsze 20 realnych użytkowników” jako najważniejszą pracę niekodową. To poprawna diagnoza, ale trzeba potraktować ją jako twardą bramkę, nie backlog.

**Rekomendacja:** przed szerokim wrzucaniem kreacji „Pamiętasz Garnek.pl?” przygotować minimum:

- 20–30 realnych, aktywnych osób;
- 100–150 autentycznych wpisów/zdjęć;
- codziennie nowe treści przez co najmniej 7–10 dni;
- odpowiedź/komentarz pod większością pierwszych wpisów nowych osób;
- kilka aktywnych tagów/tematów, żeby dało się coś przeglądać bez znajomych.

Nie seedować fałszywych kont. Lepiej mała prawdziwa społeczność niż duża scenografia.

### PROD2 — P1 — pierwszy sukces użytkownika jest za daleko od rejestracji

Po rejestracji użytkownik trafia w trzyetapowy onboarding, choć główna wartość produktu brzmi „wrzuć zdjęcie tego, co ugotowałeś”. Kroki są opcjonalne, lecz proces mentalnie odsuwa pierwszą publikację.

**Rekomendacja:** po utworzeniu konta natychmiast pokazać dwa duże wybory:

1. „Dodaj pierwsze zdjęcie” — domyślny CTA;
2. „Ustaw, co chcesz oglądać” — onboarding opcjonalny.

Onboarding zainteresowań/ludzi można wykonać po pierwszym sukcesie albo jako checklistę, nie obowiązkowo wyglądający kreator.

### PROD3 — P1 — odpowiednik „fotofor” nadal jest słabszy niż model odkrywania Garnka

`PROSTOTA_JAK_GARNEK.md` trafnie wskazuje, że Garnek pozwalał wejść w tematy/fotofora z jawnej listy. Kuking ma tagi i strony pojedynczych tagów, ale dostęp do przeglądania wszystkich sensownych tematów jest mniej oczywisty.

**Rekomendacja:** publiczna, prosta strona „Tematy”/„Co dziś gotują” z promowanymi tagami, nazwą i liczbą nowych wpisów, bez algorytmicznego rankingu. To jest funkcja cold-start, nie rozbudowa IA.

### PROD4 — P1 — kampania Garnek jest akceleratorem, nie tożsamością produktu

`docs/marketing/KAMPANIA_GARNEK.md` ma właściwą granicę: „Pamiętasz Garnek.pl?” jako historyczny haczyk, ale bez „Garnek wrócił”, „oficjalny następca”, starego logo czy imitacji starego UI.

**Rekomendacja:** na każdym landing page kampanii w pierwszym ekranie marka KUKING musi być większa/silniejsza niż wzmianka Garnek. Po pierwszej wizycie retencję budować już wyłącznie marką Kuking.

### PROD5 — P1 — obietnica „Twoje dane nie znikną” wymaga operacyjnego dowodu

Marketing słusznie eksponuje eksport danych jako odpowiedź na doświadczenie utraty dorobku po zamknięciu dawnego serwisu. To obietnica o wyjątkowo wysokiej wadze emocjonalnej.

**Ryzyko:** funkcja eksportu w kodzie nie wystarczy, jeśli backup/restore nie jest przećwiczony (otwarte #9 i #193).

**Rekomendacja:** używać komunikatu „możesz pobrać swoje dane” — bo to funkcja użytkowa. Nie używać szerszego „u nas nic nie zniknie” / „Twoje zdjęcia są bezpieczne na zawsze”, dopóki restore drill i warstwa backupu nie są zamknięte.

### PROD6 — P2 — KPI North Star jest dobre, ale brakuje jawnych progów decyzji

`PRODUCT.md` wybiera **Weekly Active Cooks**. To właściwsze niż odsłony czy liczba kont. Brakuje jednak progów, po których produkt ma reagować.

**Rekomendowane metryki startowe:**

- activation: % nowych kont, które publikują pierwszy wpis w 24 h;
- time-to-first-post;
- % pierwszych wpisów z co najmniej jedną odpowiedzią w 24 h;
- D1 / D7 return rate;
- WAC / zarejestrowani w ostatnich 30 dniach;
- liczba upload failures / 100 prób, z podziałem na powód;
- % użytkowników, którzy obserwują ≥3 osoby lub ≥3 tagi po 7 dniach.

Nie ustalałbym jeszcze arbitralnych benchmarków „branżowych”; pierwsze 50–100 osób ma stworzyć baseline Kukinga.

## Co zachować bez zmian

- chronologiczny feed;
- brak punktów/grywalizacji;
- „Ugotowałem” jako sygnał realnej aktywności;
- prosty wpis zdjęcie + kilka słów;
- brak presji perfekcyjnej fotografii;
- eksport danych;
- brak masowego AI/SEO contentu;
- brak DM/live chatu na starcie.

## Rekomendowana kolejność

1. 20–30 realnych aktywnych osób i zawartość startowa.
2. Uproszczenie przejścia rejestracja → pierwszy wpis.
3. Jawne „Tematy” jako narzędzie odkrywania.
4. Dopiero wtedy szeroka kampania nostalgiczna.
5. Mierzyć activation/WAC/odpowiedzi, nie rejestracje i pageviews.
