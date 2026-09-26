## D-194 · „Ugotowałem" jest ważniejsze niż lajk

**Data:** 12 września 2026 · PR #466 · Status: **obowiązuje** (sprostowany przez D-275, 25 września 2026)

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

- „ugotowałem" **zawsze** powiadamia autora przepisu, a lżejsza reakcja nie ma takiej mocy;
- liczba ugotowań stoi wyżej niż jakakolwiek liczba lżejszych reakcji i to ona jest
  widoczna na karcie;
- feed obserwowanych jest **chronologiczny**, bez algorytmu: ranking zamienia dzielenie
  się jedzeniem w konkurs, a w konkursie przegrywa ten, kto gotuje zwyczajnie.

### Co z tej zasady wynika w praktyce

**Liczba „Ugotowałem” ani reakcji nie wpływa na kolejność ani dobór.** Hierarchia
sygnałów rozstrzyga, co człowiek **zobaczy przy wpisie** i o czym dostanie
powiadomienie — nie to, które wpisy albo osoby komu pokażemy. Dobór treści wolno
opierać wyłącznie na regułach z zamkniętej listy w `AGENTS.md` §8 (D-275).

> **Sprostowanie (25 września 2026, #1806, D-275).** Stało tu: „Każda funkcja, która
> podnosi widoczność treści za coś tańszego niż ugotowanie, wymaga osobnego
> uzasadnienia”. Czytane dosłownie dopuszczało podnoszenie widoczności **za**
> ugotowanie — czyli ranking po liczbie „Ugotowałem”. Tak nie jest i nie było
> zamiarem: żadna miara cudzych reakcji nie układa ani nie przycina list.

Domyślną odpowiedzią na „dodajmy licznik reakcji na widoczne miejsce" jest **nie**.

### Czego ten wpis nie rozstrzyga

Jak wygląda lżejsza reakcja. Poprzednia wersja tego akapitu mówiła, że „lajk jest
i zostaje” — **to było nieprawdą: polubienia w kodzie nie ma** (stan na 25 września
2026). Ludzie potrzebują taniego sposobu, żeby powiedzieć „widzę cię”, i tym lżejszym
sygnałem będzie reakcja **„Smakowicie wygląda”** (osobne issue #1813) — nie lajk.
Ten wpis rozstrzyga wyłącznie **hierarchię** sygnałów: „Ugotowałem” stoi wyżej niż
jakakolwiek lżejsza reakcja wszędzie tam, gdzie trzeba wybrać, który zobaczy człowiek.

📄 `AGENTS.md` · `CLAUDE.md` · `docs/brand/GLOS_MARKI.md` ·
`resources/views/pages/landing.blade.php` · D-187
