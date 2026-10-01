## D-190 · Próbka pomiaru jest powtarzalna, a powtarzalność nie może zabrać zasięgu

**Data:** 12 września 2026 · PR #463 · issue #440 · Status: **obowiązuje**

### Co było nie tak

`scripts/dostepnosc.mjs` wybierał przykładowy przepis, wpis i konta przez
`->first()`/`->value()` **bez `ORDER BY`**. PostgreSQL nie obiecuje przy takim zapytaniu
żadnej kolejności — który obiekt zostanie zmierzony, potrafi się zmienić od samego
dołożenia wierszy. Pomiar, którego nie da się powtórzyć, nie jest dowodem, a na nim
stoją progi bramki dostępności.

Poprawionych **16** zapytań: `->orderBy('id')` (UUID v7, klucz główny), a tam, gdzie
kolejność ma znaczyć „najnowsze", `->orderByDesc('published_at')->orderByDesc('id')`,
czyli tak, jak sortuje feed (AGENTS.md §8). `created_at` świadomie nie rozstrzyga tu
remisu: seeder zapisuje wiersze w jednej sekundzie.

### Rzecz, której nie dało się przewidzieć

**Samo uczynienie wyboru powtarzalnym odebrałoby próbkę.** Karta wpisu autora
o stuznakowej nazwie wchodziła do pomiaru fokusu bocznymi drzwiami: na `EKRANY_FOCUS`
nie ma jej ani razu, a mierzona była dlatego, że „wpis (przykładowy)" rozwiązywał się
przez zapytanie bez `ORDER BY` i to jej wpis wypadał pierwszy. Po `orderBy('id')` wybór
ląduje na innym koncie, a trzy naruszenia WCAG 2.2 AA 2.4.11 przestają być mierzone —
**bez jednego oblanego testu**.

### Decyzja

Każda próbka, która ma być mierzona, ma **własną pozycję na liście ekranów i własne
zapytanie**, pytające o to, co ją czyni ciekawą (tu: o autora), a nie o kolejność.
To, co wpada do pomiaru przypadkiem, wypadnie z niego równie cicho.

Przy okazji dopisany `/ustawienia/zdjecie`, którego na liście nie było, a który pękał:
przy czcionce przeglądarki 200% i oknie 320 px `scrollWidth` **333 px** — przepełnienie
13 px. Winowajcą jest akapit opisu: element flex przy `align-items: flex-start` ma
szerokość `fit-content`, a ta nie schodzi poniżej najdłuższego słowa (252 px przy piśmie
32 px). Zamyka to `overflow-wrap: anywhere`; globalne `break-word` z `tokens.css` nie
wystarcza.

📄 `scripts/dostepnosc.mjs` · `ProfilZNajdluzszaNazwaWchodziDoPomiaruTest` ·
`resources/css/ekran-profilu.css` · D-107 · D-184
