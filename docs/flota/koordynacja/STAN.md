# Stan koordynacji: jak przejąć pracę w nowej sesji

**Aktualizacja: 2.10.2026, ok. 14:00 UTC.** Plik prowadzi sesja koordynatora.
Nowa sesja zaczyna od przeczytania tego pliku, potem AGENTS.md. Stan GitHuba
(PR-y, CI) zawsze sprawdzaj na żywo, bo plik może być starszy niż repozytorium.

## 1. Zasady pracy z właścicielem (stałe)

- **Język i decyzje.** Pisz po polsku. Decyzje dawaj w formie klikalnej
  (AskUserQuestion), z opcją zalecaną na pierwszym miejscu.
- **Agenci.** Stale pracują 2 agenci Sonnet, bez pytania o zgodę. Dodatkowy
  Opus wolno brać do dużych PR-ów i scalania paczek.
- **Tempo.** Nie szacuj w „czasie ludzkim”: pracujemy non stop i równolegle.
- **Decyzje właściciela** zapisujemy w D-333 (`docs/decyzje/D-333-…md`), bez
  nowych numerów D, a potem uruchamiamy `php scripts/decyzje-indeks.php`.
- **Zakazy:**
  - destrukcyjne operacje na produkcji bez jawnej zgody;
  - wypisywanie sekretów;
  - push na gałąź, na której trwa pełne CI;
  - pomijanie lub wyłączanie testów;
  - rebase lub force-push na cudzych gałęziach;
  - PR-y do `main` inne niż PR-y wydań.
- **Repozytorium jest PUBLICZNE.** Przed dodaniem dokumentu z danymi osób
  zapytaj właściciela (tak było przy PDF-ie umowy z Railway).

## 2. Przepływ pracy (paczki, potem wydanie)

1. **Gałąź integracji C:** `codex/integracja-poprawki-20261002-c`. Agent robi
   issue V2 na gałęzi `claude/<nr>-<slug>` od C i otwiera draft PR do C.
   Instrukcja dla agentów: [`WSPOLNE-issue.md`](WSPOLNE-issue.md) (dla
   dokumentacji: [`WSPOLNE-dok.md`](WSPOLNE-dok.md)).
2. **Paczka:** kilka gotowych gałęzi scalamy na `claude/paczka-<litera>-20261002`
   od C (`narzedzia/scal_f.sh`). Konflikty rozwiązujemy jako sumę obu stron
   (`union_cl.py`, `union_sep.py`, `scal_db.py`, `changelog_lista.py`). Potem
   jeden PR do C.
3. **Przed pushem paczki:**
   - pełny PHPStan: `vendor/bin/phpstan analyse --no-progress --memory-limit=2G`;
   - preflight kotwic kontroli negatywnych: `narzedzia/preflight_kotwic.sh`
     (wynik musi mieć „zlych 0”);
   - `narzedzia/sprawdz_kontrole.py`;
   - testy z `--parallel`;
   - indeks decyzji: `php scripts/decyzje-indeks.php --sprawdz`;
   - w `resources/nowosci/tresc.md` pusta linia przed każdym `###`.
4. **Znane błędy tylko lokalne** (nie naprawiamy ich):
   - PostgreSQL 16 zamiast 18;
   - brak obsługi AVIF;
   - testy `/health` w stanie „degraded” (proxy i środowisko);
   - `SesNieUzywaPoswiadczenR2Test`;
   - `RiskyTestFailsGateTest`;
   - `InstalacjeCiSaPrzypieteTest`.
5. **Po zielonym CI** scalamy PR paczki do C (REST:
   `gh api -X PUT repos/woogitsu/kuking.pl/pulls/N/merge -f merge_method=merge -f sha=…`;
   draft zdejmuje `POST …/pulls/N/ccr/ready_for_review`).
6. **Wydanie:**
   - gałąź `claude/wydanie-20261002-<litera>` wskazuje SHA C, wypychana przez
     `git push origin <SHA>:refs/heads/…`, bo API do tworzenia refów jest zablokowane;
   - PR do `main`; po jego scaleniu zamykamy PR-y źródłowe komentarzem
     „Zawarte w paczce X”.
7. **Bramka po merge na main** (skrypt w tle):
   - CI na SHA merge jest zielone;
   - deploy w Railway ma status SUCCESS (`list-deployments`);
   - `curl https://kuking.pl/wydanie` zawiera SHA;
   - `/health` zwraca 200.

   Dopiero wtedy zamykamy issues komentarzem z dowodem (SHA, id deployu).
8. **GitHub:** GraphQL jest zablokowany, działa tylko REST (`gh api repos/woogitsu/kuking.pl/...`).
   `gh issue close` nie działa; issue zamykamy przez
   `PATCH issues/N state=closed state_reason=completed`.

## 3. Pułapki CI (powtarzały się)

- `UzyteZmienneKolorowIstniejaTest`: kolory tylko z istniejących tokenów
  (np. `--color-ink-muted`, nie `--color-text-muted`).
- **Larastan:**
  - klucze dostawców danych muszą być tekstowe;
  - powtórzona asercja na wywołaniu metody bywa uznana za „zawsze prawdziwą”;
    wtedy przypisz wynik do osobnej zmiennej (`@phpstan-impure` w CI nie wystarczył);
  - `match` na `string` wymaga zawężenia typu w `@param`.
- **Migracje (§6):** CHECK na istniejącej tabeli wymaga `NOT VALID`, potem
  `VALIDATE` i `public $withinTransaction = false;`. Znaczniki czasu muszą być
  unikalne. Rollback może odmówić przy danych (D-088).
- **Kontrole negatywne** (`scripts/kontrole-negatywne-alfa08.py` i
  `kontrole_oczekiwana_przyczyna.py`): przy zmianie wcięć albo układu widoku
  kotwice przestają pasować. Zmiana na przepisie (etapy #2652) przesunęła wcięcie kroku.
- **Teksty i formularze:**
  - teksty bez rodzaju gramatycznego (`TekstyNiePrzypisujaPlciTest`);
  - `novalidate` na formularzach z walidacją natywną;
  - daty przez `App\Support\Czas`;
  - kontrast w trybie ciemnym (axe).
- **Martwe odnośniki** w `.md`: nagłówek z „—” daje w kotwicy `--`.
- **Dokumenty prawne:**
  - polityka prywatności i archiwum `archiwum/polityka-prywatnosci-2026-09-30.md`
    mają być **identyczne** (decyzja właściciela; drobne poprawki bez nowej daty);
  - regulamin i `archiwum/regulamin-2026-09-30.md` też są identyczne (`ArchiwumRegulaminuTest`);
  - dane spółki trzymamy w `config/kuking.php` → `podmiot`.

## 4. Produkcja: Railway

- **Identyfikatory:**
  - projekt `77044ca0-2cf4-4be1-bcd8-fdb6c4d83047`;
  - środowisko `ea146c13-dc55-4a4f-a386-0835f650f9ce`;
  - Postgres `5714f434-d42f-4ab0-addf-f8213409fa7f`.
- **Usługi (rozbicie z 2.10):**
  - `kuking.pl` (web) `200d68a6-24b0-46f3-bd7a-924cd00e532e`;
  - `worker` `bfdd37c9-5f25-46e1-a8d5-87409858c3c3`;
  - `scheduler` `c88ebe51-2fa9-404c-95a6-e0f6daef6f84`. **Zawsze dokładnie 1 replika.**
- **Odwołania** do zmiennych usługi web: `${{"kuking.pl".NAZWA}}`, z cudzysłowem;
  bez niego nie działają.
- **Nie uruchamiaj `railway config apply`**, dopóki wartości nie są w Shared
  Variables, bo usunie zmienne. Rozjazd IaC jest opisany w #595.
- **accept-deploy** przez MCP się zawiesza; staged zmiany zatwierdza
  narzędzie `railway-agent`.
- **Kopie i PITR** są włączone (D-333).
- Na `worker` jest zbędna zmienna `TEST_REF`; właściciel może ją usunąć.

## 5. Stan na 2.10.2026, ok. 14:00 UTC

### Na produkcji
- Wydania F, G i H. H to merge `6f0bf661d`, deploy `4d3468cd`. Issues
  #2587, #2583, #2602 i #2645 są zamknięte z dowodem.

### W toku
- **Wydanie V**, [#2711](https://github.com/woogitsu/kuking.pl/pull/2711), z C
  `aa31a8c77`: wskazówki, wspólne gotowanie, słownik #2343, Mój rok, dyktowanie,
  ścieżka CSAM.
  - Właściciel zgodził się na całość pod warunkiem zielonego CI.
  - Po bramce:
    - zamknąć jako zastąpione #2414, #2415, #2416, #2417, #2427, #2436, #2446,
      #2642 i #2422 (sprawdzić każdy, czy faktycznie jest w C);
    - zamknąć #2343;
    - test słownika z osobą 50+ po wdrożeniu.
- **Paczka I**, [#2709](https://github.com/woogitsu/kuking.pl/pull/2709), gałąź
  `claude/paczka-i-20261002`: 8 funkcji (#2549, #2544, #2550, #2540, #2556, #2652,
  #2620, #2567, PR-y źródłowe #2695–#2706).
  - Agent Opus scala nową C (z V) i rozwiązuje 7 konfliktów. Poprawki Larastana
    i kotwicy #2484 są już w commicie lokalnym.
  - Po zielonym CI: merge do C, wydanie I, zamknięcie PR-ów źródłowych
    #2695, #2696, #2698, #2699, #2702, #2704, #2705, #2706.
- **Prawo**, [#2707](https://github.com/woogitsu/kuking.pl/pull/2707), gałąź
  `claude/opinia-prawna-20261002`:
  - analiza AI z 2.10 i odpowiedzi na pytania 1–18 (`docs/prawo/`);
  - karta CSAM zgodna z art. 18 DSA;
  - dane z art. 206 KSH w regulaminie;
  - DPA z Railway (PDF w `docs/legal/umowy/`);
  - ten plik.

  Lista prac: [#2708](https://github.com/woogitsu/kuking.pl/issues/2708).
- **Agenci w toku:**
  - Opus: #2650, udostępnianie przepisu jednej osobie (draft #2701), z
    wymaganiami 1–7 z pytania 18 analizy;
  - Sonnet: następne issue z listy V2 (patrz niżej).
- **Gotowe, do następnej paczki:** #2710 (#2553, ostatnio oglądane) i #2712 (#2568, dwa opakowania w spiżarni; agent sam przyjął limity, które są zapisane w wierszu D-333 do potwierdzenia przez właściciela).
  Właściciel ma potwierdzić limity 10 przepisów i 7 dni.
- **Kolejne kandydatury V2:** #2627, #2654, #2591, #2535, #2531, #2530, #2529.
  **#2558** (lista „Gdzie jesteś zalogowany”) czeka na decyzję właściciela i
  badanie z osobami 50+.

### Decyzje właściciela z 2.10 (pełne w D-333)
- Śledzenie otwarć w EmailLabs jest wyłączone, a stare dane usunięte. PITR włączony.
- Po analizie prawnej:
  - Cloudflare Web Analytics zostaje (świadome ryzyko);
  - moderacja OpenAI zostaje, a właściciel sprawdza jej DPA;
  - wersjonowanie polityki bez zmian;
  - beta tylko dla osób pełnoletnich;
  - SAMSUFI to mikroprzedsiębiorstwo;
  - sąd rejestrowy w Białymstoku, kapitał 5 000,00 zł;
  - DPA z Railway podpisane.
- Dopisek z gotowania usuwamy przy „Ugotowałem” (#2587).
- #595: rozbicie na 3 usługi jest zrobione.

### Otwarte sprawy właściciela
- DPA: Cloudflare, EmailLabs i OpenAI.
- Źródła `.eml` trzech listów, do sprawdzenia, że nie mają piksela.
- Odczyt retencji kopii, PITR i dzienników w panelu Railway.
- Data wejścia regulaminu dla bety.
- Zewnętrzny monitor `/health`.
- R2: wyłączyć `r2.dev` i przejrzeć tokeny.
- Wymagani recenzenci w GitHubie.
- Osoby do bety i do sesji z osobami 50+.
- Pisemna notatka o statusie mikroprzedsiębiorstwa.
- Exhibit A w DPA Railway mówi „brak danych szczególnych kategorii”; trzeba to
  porównać z notatkami o zdrowiu.
