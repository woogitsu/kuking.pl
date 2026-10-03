# Wspólny odbiór paczki Y — Codex, 3.10.2026

## Zakres

Root odebrał źródła i niezależne recenzje #2877, #2810, #2879, #2887,
#2889 oraz dodatkowych rzeczywistych prób HTTP dla #2851, #2854, #2861
i #2862. Kontrole obejmują również odmowę uznania błędu procesu B za
prawidłową porażkę mutanta. Wszystkie szczegółowe receipt pozostają osobno.

Wspólna bramka dotyczy dokładnego commita
`8f02481a478413a12ce32e473642ea99987cd3ab`. Nie jest to odbiór produkcji.

## Izolowana instancja root

- Runtime: `/home/codex-admin/kuking-koordynacja-20261003-codex/repo-y`.
- PostgreSQL 18.6, jawny host `127.0.0.1`, port `55488`.
- Bazy: `kuking_race_repo_y` i `kuking_test_y20261003`.
- Właściciel obu baz: `kuking_pg18_owner`; potwierdzony przed uruchomieniem.
- Potwierdzono brak innych aktywnych sesji tych baz, zgodne locki i czysty WT.
- Fizyczne zależności własnej kopii, jawny APP_BASE_PATH i APP_ENV=testing.
- Wyłącznie procesowe APP_URL=http://localhost:8042; brak zmian produkcji.

## Wyniki wspólnej bramki

`transfer/Y-root-8f.exit` ma terminalne **0**. Surowe XML sprawdzono
ponownie poza przyrządem: właściwe testcase istnieją, zero failure/error/skip.

| Kontrola | Wynik |
| --- | --- |
| Osiem klas Dwa: cookies, 2FA, sprzątanie, zgłoszenia i postęp | 58 PASS / 2032 asercje |
| Sześć klas Feature: NULL porcji, historia, zamiana szkicu, wyjście i strażnik | 83 PASS / 2396 asercji |
| Fizyczna kontrola #2879 | 10 PASS → 5 właściwych FAIL + 5 PASS → dokładny restore → 10 PASS |
| Fizyczna kontrola #2887 | Właściwa porażka wyznaczonego przeplotu, dokładny restore i ponowny PASS |
| Mechanizmy werdyktów 2861/S4/2879/2887 | 13 + 8 + 9 + 10 PASS |
| Mechanizm przyczyn i zawężenia | 37 + 22 PASS |
| Pint, pełny PHPStan, składnia skryptów, indeks decyzji | PASS; PHPStan 0 błędów |
| Build i dołączone testy npm | PASS; 307 testów, 0 failure/skip |
| Czystość źródeł i indeksu po kontrolach | PASS |

Wcześniejszy wspólny odbiór `3c109e12` obejmował 37/1500 Dwa oraz
fizyczne kontrole literalnych cookies i okna 2FA, włącznie z rzeczywistym
błędem B42P01 odrzucanym przez werdykt. Pierwsza próba bez lokalnego
opt-in odmówiła; zachowano ją jako błąd przygotowania, następnie wykonano
wyłącznie pozostałe kroki z jawnym opt-in. Nie jest ona dowodem PASS.

Niezależny przegląd końcowych rejestracji potwierdził pojedyncze wykonywalne
wywołania czterech mechanizmów, komplet warunków powłoki i 580/580
unikalnych kontroli oraz wzorców. #2887 i #2889 nie zmieniły tych dwóch
centralnych rejestrów.

## Dowody poza repo

Katalog `/home/codex-admin/kuking-koordynacja-20261003-codex/transfer`:
`Y-root-8f.log`, `.exit`, `Y-root-8f-dwa.xml`, `Y-root-8f-feature.xml`
i `Y-root-8f-proof.json`. Kopia proof jest również w lokalnym dzienniku
koordynatora poza checkoutem.

| Artefakt | SHA-256 |
| --- | --- |
| Y-root-8f-dwa.xml | f51d65cd991268cf6208edd16ca3aa60698bcb6ea0df8bfab821ecba9d811d6a |
| Y-root-8f-feature.xml | 19fce962f73269b2b043765d9c2df8531abbf56d8b41f2b3fb9cfaf831e1efd7 |

## Bramka wydania i rollback

Y czeka na zależności X/W oraz terminalnie zieloną integrację C. Nie
otwieramy kumulującego PR ani nie nazywamy tej regresji pełnym hookiem.
Przed dostawą wymagane zwykłe pełne pre-push, pełne niedraftowe CI,
świeże head/base, review i bezkonfliktowość. Wydanie wymaga CodeQL,
terminalnego main CI, SUCCESS trzech usług i zgodnego SHA produkcji.

#2851/#2854/#2861/#2862 oraz #2810/#2849 pozostają otwarte do wspólnego
odbioru produkcji. Kryteria ręczne nie są zaliczone przez test lokalny.
Rollback kodu: revert właściwego merge po przeglądzie zależności; brak
automatycznego niszczenia danych lub cofania wartości semantycznych.
