# Czy `railway config apply` usuwa zmienne ustawione tylko w panelu?

**Status: NIESPRAWDZONE.** Runbook dla właściciela. Audyt po fali scaleń
z 25.09.2026 (`docs/audyt/2026-09-25-PO-FALI.md`, znalezisko 12, propozycja C).
Sesja, która to przygotowała, nie ma dostępu do Railway i niczego tu nie
uruchamiała. Wszystkie kroki niżej **tylko czytają**. Żaden nie uruchamia
`apply`.

## O co chodzi

Trzy zmienne serwisu `kuking.pl` działają dziś wyłącznie jako wartości
wpisane ręcznie w panelu Railway. `.railway/railway.ts` ich nie wymienia:

| Zmienna | Co robi | Co się stanie, gdy zniknie |
|---|---|---|
| `KUKING_EDGE_TRYB` | tryb bramki brzegu #1306: `obserwacja` albo `egzekwowanie` | wraca `obserwacja` (`config/proxy.php`) — **bramka przestaje blokować**, bez żadnego komunikatu |
| `KUKING_TAG_TYGODNIA` | włącza tag tygodnia | wraca `false` (`config/kuking.php`) — tag tygodnia znika ze strony |
| `KUKING_HTML_EDGE_CACHE_SECONDS` | cache HTML dla gości na brzegu (#610) | wraca `0` — cache HTML wyłączony (bezpieczny kierunek, ale po cichu) |

Nikt dotąd nie sprawdził, czy `railway config apply` usuwa ze serwisu
zmienne, których nie ma w pliku. Jeśli usuwa, pierwsze apply po cichu cofnie
bramkę do `obserwacja` i wyłączy tag tygodnia. Tabela „Stop” w
`PRZELACZENIE_NA_3_SERWISY_595.md` (krok 2) już każe zatrzymać apply, gdy plan
proponuje usunięcie zmiennej — ten dokument mówi, **jak to sprawdzić przed
tym dniem** i co zrobić z wynikiem.

Pilnuje tego `scripts/railway/iac.test.mjs` (blok „Zmienne tylko w panelu”):
skrypt z kroku 2 zgłasza każdą zmienną z panelu, której nie deklaruje graf.

**Stan z 26.09.2026, po PR #1775/#1883:** wszystkie trzy zmienne z audytu są
już zadeklarowane w `.railway/railway.ts` (przez `ctx.shared`), więc skrypt
z kroku 2 nie powinien ich dziś zgłaszać. Procedura zostaje dla zmiennych,
które ktoś ustawi w panelu później.

## Dlaczego bez stagingu

Staging jest odłożony (decyzja z 25.09.2026, `DEPLOYMENT_RUNBOOK.md` §8).
Nie jest potrzebny: `railway config plan` **niczego nie zmienia** — liczy
różnicę między plikiem a żywym środowiskiem i ją wypisuje. Plan na produkcji
wystarcza, żeby odpowiedzieć na pytanie.

## Krok 0 — co przygotować (raz)

- Railway CLI, zalogowane: `railway login`.
- Repozytorium na aktualnym `main`, zależności: `npm ci`.
- Node ≥ 22.6 (`node --version`).
- Wartość zmiennej repozytorium `KUKING_WAIT_FOR_CI` (GitHub → Settings →
  Secrets and variables → Actions → Variables). Pusta = `false`. Bez niej
  `railway.ts` odmawia kompilacji (#1390).

## Krok 1 — połącz się z produkcją (tylko odczyt)

```bash
cd ~/kuking.pl
railway link          # workspace → projekt kuking → środowisko production → serwis kuking.pl
```

## Krok 2 — które zmienne są tylko w panelu (same NAZWY)

```bash
railway variables --service kuking.pl --json \
  | KUKING_WAIT_FOR_CI=false node --experimental-strip-types --no-warnings \
      scripts/railway/zmienne-spoza-iac.mjs production kuking.pl
echo "kod wyjścia: $?"
```

(`KUKING_WAIT_FOR_CI=` ustaw na wartość z kroku 0.)

- Skrypt czyta z wejścia **tylko klucze** i wypisuje **tylko nazwy**.
  Wartości nie pojawiają się na ekranie (pilnuje tego test).
- Kod `0` — w panelu nie ma nic poza plikiem. Kod `1` — lista wyżej to
  zmienne, których apply mógłby dotknąć. Kod `2` — złe wywołanie albo
  wejście.
- Spodziewane dziś: pusta lista — trzy zmienne z audytu (`KUKING_EDGE_TRYB`,
  `KUKING_HTML_EDGE_CACHE_SECONDS`, `KUKING_TAG_TYGODNIA`) są od #1883
  w grafie. Każda nazwa, która się pojawi, to zmienna, o której nikt nie
  wie — **te są najważniejsze**.

Nie wklejaj nigdzie surowego wyniku `railway variables --json` — ma wartości,
w tym sekrety. Wynik skryptu (same nazwy) można wkleić do issue.

## Krok 3 — co plan proponuje z tymi zmiennymi

Jedna z dwóch dróg, wynik ten sam:

**A. Lokalnie:**

```bash
KUKING_WAIT_FOR_CI=false railway config plan > ~/plan-zmienne-12C.txt   # wartość z kroku 0
grep -n -E "KUKING_EDGE_TRYB|KUKING_TAG_TYGODNIA|KUKING_HTML_EDGE_CACHE_SECONDS" ~/plan-zmienne-12C.txt
```

Do `grep` dopisz każdą dodatkową nazwę z kroku 2. Plik planu trzymaj poza
repozytorium.

**B. Z komentarza na PR-ze.** PR zmieniający `.railway/**` albo
`.github/workflows/railway-iac.yml` dostaje od joba `plan` komentarz z tym
samym planem (liczonym tokenem produkcyjnym, tylko do odczytu). Szukaj w nim
tych samych nazw.

## Krok 4 — co znaczy wynik

| Co widać w planie przy tych nazwach | Znaczenie | Co zrobić |
|---|---|---|
| usunięcie zmiennej (`delete`, `remove`, `-` przy nazwie — zależnie od wersji CLI) | **apply usuwa zmienne spoza pliku** | **Nie uruchamiaj apply.** Najpierw PR: w panelu utwórz Shared Variable o tej samej nazwie i wartości, w `railway.ts` dopisz `NAZWA: ctx.shared.NAZWA` w zmiennych serwisu, dopisz nazwę do testu jako oczekiwaną w pliku. Potem powtórz kroki 2–3: skrypt ma dać kod 0, plan nie może proponować usunięcia. |
| nazwy w ogóle nie występują w planie | apply zostawia zmienne spoza pliku | Zapisz wynik (krok 5). Nic więcej nie trzeba; trzy zmienne mogą zostać w panelu. |
| zmiana wartości | ktoś dopisał je do `railway.ts` z inną wartością | Sprawdź `git log -p .railway/railway.ts`; wartość w panelu jest dziś prawdą produkcji. |
| plan się nie liczy (błąd) | — | Wklej sam komunikat błędu (bez wartości) do issue; nie obchodź go. |

Wynik „nie widać ich w planie” jest wiarygodny tylko wtedy, gdy krok 2
potwierdził, że zmienne **są** ustawione w panelu. Jeśli skrypt ich nie
zgłosił, nie ma czego sprawdzać — i to też jest wynik do zapisania.

## Krok 5 — zapisz wynik

Dopisz pod tym nagłówkiem datę, wersję CLI (`railway --version`), listę nazw
z kroku 2 i jedno zdanie z kolumny „Znaczenie” z kroku 4. Status na górze
zmień z „NIESPRAWDZONE” na wynik.

### Wynik

_Jeszcze nie sprawdzone._

## Czego ten dokument NIE rozstrzyga

- Czy apply usuwa **Shared Variables** spoza pliku — `railway.ts` tylko
  odwołuje się do nich przez `ctx.shared` i ich nie tworzy. Plan z kroku 3
  pokaże to przy okazji, jeśli dotyczy.
- Czy przenosić te trzy zmienne do `railway.ts` na zapas — to decyzja
  właściciela po wyniku z kroku 4, nie przed nim.
