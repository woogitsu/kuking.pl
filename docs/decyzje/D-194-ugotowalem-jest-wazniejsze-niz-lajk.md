## D-194 · „Ugotowałem" jest ważniejsze niż lajk

**Data:** 12 września 2026 · PR #466 · Status: **obowiązuje**

### Dlaczego ten wpis powstaje dopiero teraz

Ta zasada działa w Kuking od początku: niesie ją `AGENTS.md` §1 i `CLAUDE.md`, stoi
w tekście strony powitalnej i w `GLOS_MARKI.md`, i wynikła z niej niejedna decyzja
w tym dzienniku. **Wpisu o niej nie było.** Kod odsyłał w dwóch miejscach do „D-004",
a D-004 dotyczy wyszukiwarki na PostgreSQL (D-187).

Zdjęcie złego numeru zostawiło zdanie bez odnośnika. Ten wpis zamyka lukę, zamiast
kazać następnej osobie wyprowadzać tę zasadę z czterech dokumentów naraz.

### Zasada

Kuking to **społeczność ludzi, którzy gotują**, a nie baza przepisów. Najmocniejszym
sygnałem w serwisie jest **„ugotowałem"** — bo kosztuje wieczór przy garnku, a nie
jedno dotknięcie ekranu. Dlatego:

- „ugotowałem" **zawsze** powiadamia autora przepisu, a lajk nie ma takiej mocy;
- liczba ugotowań stoi wyżej niż jakakolwiek liczba polubień i to ona jest widoczna
  na karcie;
- feed obserwowanych jest **chronologiczny**, bez algorytmu: ranking zamienia dzielenie
  się jedzeniem w konkurs, a w konkursie przegrywa ten, kto gotuje zwyczajnie.

### Co z tej zasady wynika w praktyce

Każda funkcja, która podnosi widoczność treści za coś tańszego niż ugotowanie, wymaga
osobnego uzasadnienia — nie odwrotnie. Domyślną odpowiedzią na „dodajmy licznik
polubień na widoczne miejsce" jest **nie**.

### Czego ten wpis nie rozstrzyga

Czy lajk w serwisie **jest**. Jest i zostaje — ludzie potrzebują taniego sposobu, żeby
powiedzieć „widzę cię". Rozstrzygnięta jest wyłącznie **hierarchia** tych dwóch
sygnałów wszędzie tam, gdzie trzeba wybrać, który zobaczy człowiek.

📄 `AGENTS.md` · `CLAUDE.md` · `docs/brand/GLOS_MARKI.md` ·
`resources/views/pages/landing.blade.php` · D-187
