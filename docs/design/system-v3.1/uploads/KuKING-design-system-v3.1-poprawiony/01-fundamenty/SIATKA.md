> **Aktualizacja v3.1 · 7 września 2026.** Zmiany z audytu opisuje [AUDYT-V3.1.md](../AUDYT-V3.1.md). W sprawach motywu, stopki, pozycjonowania nagłówka i skali 200% ten dokument oraz `07-wdrozenie/MOTYW-V3.1.md` mają pierwszeństwo przed poniższym opisem v3.0. Paleta i znak pozostają bez zmian.

# Siatka i układ

Jedna zasada nadrzędna, z której wynika reszta:

> **Belka górna, siatka treści i stopka mają tę samą szerokość i te same
> kolumny. Zawsze, na każdej podstronie.**

Wcześniej każda z tych trzech warstw liczyła sobie szerokość osobno. Zmierzone
przy oknie 1512 px: 1424 px z szyną, 1040 px bez szyny, 768 px u gościa. Trzy
różne strony w jednym serwisie — nawigacja boczna przeskakiwała przy każdym
przejściu, a pole wyszukiwania w belce było szersze niż kolumna treści pod nim.

---

## 1. Trzy progi, nie pięć

| Od | Co się dzieje |
|---|---|
| 320 px | jedna kolumna, dolny pasek nawigacji, wszystko na pełną szerokość |
| **64rem (1024 px)** | dochodzi nawigacja boczna po lewej; dolny pasek znika |
| **80rem (1280 px)** | dochodzi trzecia kolumna — szyna |

Progi są dwa, bo dwie rzeczy naprawdę się zmieniają. Każdy kolejny próg to
kolejny układ do sprawdzenia przy każdej zmianie i kolejna okazja, żeby coś się
rozjechało.

Wewnątrz kolumny elementy układają się same, przez `grid-template-columns:
repeat(auto-fit, …)` albo `flex-wrap` — bez dodatkowych zapytań o szerokość.

---

## 2. Kolumny

```
80rem i więcej:   [ nawigacja 240 ]  [ treść 720 ]  [ szyna 352 ]     = 1424 px
64–80rem:         [ nawigacja 240 ]  [ treść ≤720 ]                    = 1040 px
poniżej 64rem:    [ treść 100% ]                     + dolny pasek
gość:             [ treść ≤720 ]                                       =  768 px
```

**Trzecia kolumna istnieje od 80rem zawsze — także wtedy, gdy nie ma czym jej
wypełnić** (decyzja D-102). Powód jest prosty: alternatywą jest strona, która ma
inną szerokość na każdej podstronie. Pusty margines po prawej wygląda spokojnie;
przeskakująca nawigacja wygląda na usterkę.

Czym wypełniamy trzecią kolumnę:

| Ekran | Co w szynie |
|---|---|
| tablica | „kuKINGi na dziś”, przypomnienie o dodaniu wpisu |
| szukaj | podpowiedzi zakresu, ostatnio szukane |
| przepis | **panel przepisu**: składniki i akcje, przyklejony przy przewijaniu |
| dodaj przepis | pomoc do bieżącego kroku |
| profil, zeszyt, powiadomienia | zostaje pusta |

**Szyna nigdy nie powtarza kolumny głównej.** Dziś sekcja „DANIA” pokazuje te
same wpisy, które są obok — ten sam tekst dwa razy na jednym ekranie.

---

## 3. Szerokość czytania

| Kontener | Szerokość | Do czego |
|---|---|---|
| `--container-content` | 45rem / 720 px | strumień kart, przepis, formularz — ~65–75 znaków |
| `--container-czytanie` | 38rem / 608 px | polityka, regulamin, zasady — ~65 znaków |

Dokument prawny czyta się węziej, bo nie ma w nim zdjęć, które łamałyby rytm.
720 px czystego tekstu przez cztery ekrany męczy.

---

## 4. Rytm pionowy

Wszystko jest wielokrotnością 4 px. W praktyce używa się sześciu wartości i to
wystarcza:

| Odstęp | Gdzie |
|---|---|
| `--spacing-2` (8 px) | między ikoną a napisem, między plakietkami |
| `--spacing-3` (12 px) | wewnątrz kontrolki, między przyciskami w rzędzie |
| `--spacing-5` (20 px) | wcięcie treści karty |
| `--spacing-6` (24 px) | między kartami w strumieniu |
| `--spacing-10` (40 px) | między sekcjami strony |
| `--spacing-16` (64 px) | między dużymi blokami strony powitalnej |

Odstępy **nie skalują się** ustawieniem tekstu. Gdyby się skalowały, przy 150%
przyciski przestałyby mieścić się w wierszu na 320 px.

---

## 5. Zdjęcie w układzie

Zdjęcie jest w tym serwisie treścią, nie ilustracją (D-105). Stąd:

- w karcie wpisu: **pełna szerokość karty, proporcja 4:3**, bez wcięcia i bez
  zaokrągleń wewnątrz. Na kolumnie 720 px daje to 720×540 — tyle, żeby było
  widać, co jest na talerzu;
- w karcie zwartej: **16:9**, żeby w wiersz weszły dwie karty. To dalej duże
  zdjęcie, nie miniatura;
- w siatce: **maksimum dwie kolumny**. Przy czterech zdjęcie robi się ikonką —
  a wtedy przestaje być dowodem, że przepis komuś wyszedł.

**Nigdy ściana miniaturek.** To jest reguła produktowa, nie estetyczna: zdjęcie
cudzego wykonania potrawy jest jedynym dowodem, że przepis działa u zwykłego
człowieka.

---

## 6. Co się nie zmieści — zawija się, a nie wystaje

Reguły, które trzymają obietnicę „nigdy przewijania w poziomie od 320 px”:

1. każdy rząd przycisków ma `flex-wrap: wrap`;
2. każda kolumna siatki ma `minmax(0, …)` albo `min-width: 0` — bez tego element
   siatki nie może zrobić się węższy od swojej zawartości i rozpycha stronę;
3. `body` ma `overflow-wrap: break-word` — długie słowo („ugotowała/ugotował”,
   wklejony adres) łamie się zamiast wypychać układ. `break-word`, nie `anywhere`:
   łamiemy dopiero wtedy, gdy inaczej tekst wystaje;
4. obraz ma `max-width: 100%`;
5. jedyne miejsce z przewijaniem w poziomie to karuzela zdjęć we wpisie — i to
   świadomie.

Sprawdza się to automatem od 320 px w górę, przy każdym kroku skali tekstu.

---

## 7. Co się przykleja, a co nie

| Element | Zachowanie |
|---|---|
| belka górna | przyklejona u góry, `z-index: 30` |
| dolny pasek | przyklejony u dołu, `z-index: 40`, tylko poniżej 64rem |
| nawigacja boczna | przyklejona, ale przewija się razem ze stroną, jeśli jest wyższa niż okno |
| szyna | przyklejona, `max-height: 100vh − 96px`, własne przewijanie |
| panel przepisu | przyklejony — to jest jego cała wartość przy garnku |

`<body>` ma zapas na dole równy wysokości dolnego paska plus odstęp, żeby ostatni
element treści nie chował się pod nawigacją. Na desktopie zapas znika razem
z paskiem.

Uwaga przy oglądaniu pełnostronicowych zrzutów: dolny pasek pojawia się na nich
w środku strony. To artefakt sklejania zrzutu, nie usterka — w przeglądarce stoi
na swoim miejscu.
