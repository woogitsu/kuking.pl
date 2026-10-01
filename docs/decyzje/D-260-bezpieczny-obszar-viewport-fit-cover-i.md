## D-260 — Bezpieczny obszar: `viewport-fit=cover` i cztery tokeny `--safe-*` (24 września 2026)

**Data:** 24 września 2026 · Issue #987 · Status: **do odbioru na urządzeniu**

**Co.** Wspólny meta viewport (`resources/views/components/layout.blade.php`)
wybiera `viewport-fit=cover`. Insety czyta wyłącznie
`resources/css/bezpieczny-obszar.css` — cztery tokeny `--safe-top`,
`--safe-right`, `--safe-bottom`, `--safe-left`. Właściciele brzegów:
pasek górny (`.topbar` przez `padding-top`, karta `.marka-topbar` przez
`top` i margines), `<body>` (boki treści w przepływie, w tym stopka — bez
zmian w jej CSS), dolna belka i szybki wygląd (`fixed`, więc dół i boki
biorą same). Eksporty i poczta mają własne viewporty i zostają w `auto`.

**Dlaczego `cover`, a nie `auto`.** Kuking instaluje się jako PWA
`standalone`: przy `auto` iOS zostawia pasy wokół strony w kolorze tła
dokumentu, a dotychczasowe `env(safe-area-inset-bottom)` sugerowało obsługę
pełnego ekranu, której nie było (góra i boki nieobsłużone). `cover` daje ten
sam wynik w Safari i w PWA pod warunkiem, że każdy brzeg ma właściciela —
dlatego kontrakt obejmuje wszystkie cztery insety, nie tylko dół.

**Czego to nie zmienia.** Bez wycięcia (komputer, większość Androidów)
tokeny są równe 0 — układ co do piksela jak przed zmianą. Klawiatura
ekranowa i visual viewport zostają w #947.

**Znana granica.** Zmiana jest sprawdzona testem kontraktu i w Chromium;
odbioru na fizycznym iPhonie (Safari i ekran główny, pion i poziom, tekst
100/140/200%) wymaga #987 i nie da się go zastąpić emulacją.

Pomiar w Chromium: `scripts/bezpieczny-obszar-pomiar.mjs` ustawia cztery
insety przez CDP (`Emulation.setSafeAreaInsetsOverride`) — bez wycięcia,
iPhone pion (59/0/34/0) i poziom (0/59/21/59) — i sprawdza, że górna belka,
dolna nawigacja i przycisk szybkiego wyglądu leżą w bezpiecznym obszarze,
cele mają ≥ 48 px, strona nie przewija się w poziomie, a zmiana insetów
przelicza odstępy bez przeładowania (39 asercji; kontrola ujemna: usunięcie
`--safe-bottom` z dolnej belki albo `--safe-top` z karty górnej je czerwieni).
Granica emulacji: Chromium podaje niezerowe `env()` także przy
`viewport-fit=auto`, więc różnicy `auto`/`cover` z WebKit nie odtwarza —
to nadal zostaje do odbioru na telefonie.

Dowody: `tests/Feature/BezpiecznyObszarMaJedenKontraktTest.php` (tokeny
meta viewport — nie cały napis, żeby dopisany `interactive-widget` z #947 go
nie czerwienił — jedyne źródło insetów, właściciel każdego brzegu, w tym dół
podpowiedzi szybkiego wyglądu) z trzema kontrolami ujemnymi w
`scripts/kontrole-negatywne-alfa08.py`.
