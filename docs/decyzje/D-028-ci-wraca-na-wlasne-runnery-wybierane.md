## D-028 · CI wraca na własne runnery — wybierane etykietami, nie nazwą

**Data:** 7 września 2026 · **Decyzja właściciela** · Status: **obowiązuje
co do zasady, ZAWIESZONA w praktyce od 8 września — patrz poprawka niżej**

> **Zmiana D-010.** D-010 przeniosło CI na runnery GitHuba, bo nowa
> organizacja `woogitsu` dawała nieużywane 2 000 minut miesięcznie. Ta decyzja
> to odwraca: wszystkie joby chodzą na własnej puli
> `woogitsu-linux-01`–`woogitsu-linux-10`.

> **Poprawka z 8 września, wieczorem — decyzja właściciela.** Wszystkie
> **14 jobów** chodzi tymczasowo na `ubuntu-latest`. Zasada zapisana wyżej
> (własna pula, wybierana etykietami) NIE jest odwołana; zmieniła się
> sytuacja, nie strategia. Ta decyzja przewidywała własny warunek zmiany —
> „dłuższa niedostępność puli, która ten koszt zamieni z hipotetycznego
> na zmierzony" — i dokładnie to zaszło.
>
> **Zmierzony koszt, dla którego to piszemy.** Trzy z sześciu runnerów były
> wyłączone. W kolejce stało dziesięć przebiegów po siedem jobów; job
> „Testy (PostgreSQL 18)" czekał na wolną maszynę **ponad godzinę**. Railway
> ma włączone „Wait for CI", więc przez ten czas **nie wdrożył na produkcję
> ani jednej scalonej zmiany** — pięć kolejnych scaleń stało bez efektu.
> Ten skutek był w D-028 wypisany jako hipotetyczny; 8 września przestał być.
>
> **Zmierzony zysk.** Ten sam pełny zestaw na runnerach GitHuba: siedem jobów
> **równolegle**, całość w **3 min 21 s** (Vite 16 s, audyt 23 s, Larastan
> 28 s, Pint 38 s, build obrazu 58 s, testy 3:18, dostępność 3:21).
>
> **Czego to kosztuje.** Repozytorium jest prywatne, więc minuty są płatne.
> Pełny przebieg to orientacyjnie 25–35 minut maszynowych z puli ~2000
> miesięcznie, którą właściciel kazał wykorzystać.
>
> **Jak wrócić.** W każdym z czterech workflow-ów podmienić
> `runs-on: ubuntu-latest` na
> `runs-on: [self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]`.
> Nagłówek każdego pliku mówi to samo w miejscu, w którym się na to patrzy.
> Nic poza `runs-on` nie wymagało zmiany: usługa `postgres` i odczyt
> zmapowanego portu przez `job.services.postgres.ports[5432]` działają
> jednakowo na obu rodzajach runnerów — sprawdzone przebiegiem, nie założone.
>
> **Czego ta poprawka NIE rozstrzyga.** Kiedy wrócić. To jest decyzja
> właściciela i wymaga jednej informacji, której z repozytorium nie widać:
> czy pula stoi z powodu, który minie sam.

> **Druga poprawka, tego samego wieczoru — powrót przestaje wymagać PR-a.**
> `runs-on` we wszystkich czterech workflow-ach czyta teraz zmienną
> repozytorium `CI_RUNS_ON`; bez niej stoi `ubuntu-latest`. Ustawienie jej
> na listę etykiet własnej puli przełącza CI **bez zmiany w kodzie i bez
> cyklu przeglądu**, a skasowanie wraca na runnery GitHuba.
>
> **To nie jest cofnięcie tego, co D-028 zrobiła z `CI_RUNNER`.** Tamta
> zmienna została usunięta, bo **nic jej nie czytało** — była atrapą
> wyglądającą na przełącznik. Tę czyta `runs-on` w czterech plikach, a powód
> jej istnienia jest zmierzony: przełącznik, który wymaga PR-a i przeglądu,
> nie jest przełącznikiem awaryjnym. 8 września produkcja stała, a zmiana
> puli musiała przejść przez pełną ścieżkę zmiany kodu.
>
> **Przy okazji ucięte marnotrawstwo:** nowy job `zakres` w `ci.yml` pomija
> ciężkie zadania, gdy zmiana dotyka wyłącznie `docs/` albo `README.md`.
> Tego samego wieczoru sześć PR-ów dotykało tylko dokumentacji i każdy
> przepuścił testy na PostgreSQL, axe-core, build obrazu i build assetów.
> **Skutek dla kryterium scalania:** „Testy (PostgreSQL 18)" mogą teraz stać
> jako `skipped` i jest to poprawny stan dla zmiany w dokumentacji — regułą
> jest odtąd „zielone ALBO pominięte".
>
> **Czego NIE dało się zmierzyć i dlaczego to ważne:** ile minut Actions
> realnie zostało. Endpoint `get_workflow_run_usage` zwraca dla każdego
> przebiegu — także sprzed godzin — zerowy czas rozliczeniowy, co przy
> repozytorium PRYWATNYM znaczy najpewniej, że token nie ma dostępu do
> danych rozliczeniowych, a **nie** że przebiegi są darmowe. Jedynym
> wiarygodnym źródłem jest strona rozliczeń organizacji. Nie należy wyciągać
> z tych zer wniosku, że limit nie jest zużywany.

Wszystkie **14 jobów** w czterech workflow-ach (`ci.yml` 7, `deploy.yml` 2,
`preview.yml` 3, `railway-iac.yml` 2) ma dokładnie:

```yaml
runs-on: [self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]
```

**Etykiety, nie nazwa runnera.** Nazwa w `runs-on` przypina job do jednej
maszyny, więc jej awaria zatrzymuje całe CI, a dziesięciu maszyn nie da się
tak obsłużyć bez macierzy.

**Dlaczego akurat te dwie dodatkowe.** Stara pula WSL-owa
(`woogitsu-wsl-DOM-NEW-01`–`04`) ma etykiety `self-hosted`, `Linux`, `X64`,
`wsl2`, `woogitsu` — czyli samo `self-hosted` wpuściłoby joby także na nie.
`i5-10400f` i `nvidia-gtx1070` występują wyłącznie na nowej puli i to one
są tu bramką.

**Co zniknęło.** Poprzednio runnera wybierała zmienna repozytorium
`CI_RUNNER` z fallbackiem `ubuntu-latest`. Zmiennej nie czyta już nic i można
ją usunąć. Zmierzone przed zmianą (przebieg CI nr 141 dla `main`, commit
`e24f30d`): wszystkie siedem jobów wykonało się na runnerach GitHuba
(`runner_group_name: "GitHub Actions"`, etykiety `["ubuntu-latest"]`), czyli
zmienna nie była ustawiona, a stare runnery WSL-owe nigdy w tym repozytorium
nie pracowały — nie było też w nim ani jednego odwołania do ich nazw.

**Koszt, żeby był zapisany.** Joby nie mają już zapasu w runnerach GitHuba.
Gdy cała pula jest offline, przebiegi stoją w kolejce bez końca — a CI jest
bramką deployu (Railway ma „Wait for CI"), więc stoi wtedy także wdrożenie.
Właściciel wybrał tę opcję świadomie, znając ten skutek.

**Zmiana wymaga:** decyzji właściciela — albo dłuższej niedostępności puli,
która ten koszt zamieni z hipotetycznego na zmierzony.

**Co pula musi mieć, żeby joby przeszły** — etykiety, Docker, rozszerzenia
PHP, sieć wychodząca, miejsce na dysku i pułapka z portem 5432 przy dwóch
runnerach na jednej maszynie — jest wypisane w
`docs/infra/WYMAGANIA_RUNNERA.md`.

📄 `.github/workflows/ci.yml` · `.github/workflows/deploy.yml` ·
`.github/workflows/preview.yml` · `.github/workflows/railway-iac.yml` ·
`docs/infra/WYMAGANIA_RUNNERA.md` · `docs/infra/SELF_HOSTED_RUNNER.md` ·
`docs/infra/CI_BEZ_ACTIONS.md` · `docs/infra/PRZENIESIENIE_DO_ORGANIZACJI.md`
