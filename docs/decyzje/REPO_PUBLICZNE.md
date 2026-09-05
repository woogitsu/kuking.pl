# Decyzja 2 — repozytorium publiczne czy prywatne

Stan na **wrzesień 2026**.

---

## 1. Twarde fakty o minutach GitHub Actions

| Fakt | Wartość | Źródło |
|---|---|---|
| Darmowe minuty/mies., **repozytoria prywatne**, plan **Free** | **2 000 min** + 500 MB storage | docs.github.com — billing dla Actions |
| Darmowe minuty/mies., **repozytoria prywatne**, plan **Pro** | **3 000 min** + 2 GB storage | ditto |
| (dla kontekstu) Team | 3 000 min | ditto |
| (dla kontekstu) Enterprise Cloud | 50 000 min | ditto |
| **Repozytoria publiczne, standardowe runnery** | *„The use of standard GitHub-hosted runners is free"* — **darmowe, bez miesięcznego limitu minut** | ditto |
| Cena minuty ponad limit, **Linux 2-core x64** | **$0,006 / min** | docs.github.com — Actions runner pricing |
| Cena minuty ponad limit, **Linux 2-core arm64** | **$0,005 / min** | ditto |
| Mnożniki (repo prywatne) | Linux ×1, Windows ×2, macOS ×10 | ditto |
| **Self-hosted runner zużywa minuty?** | **NIE** — *„GitHub Actions usage is **free** for **self-hosted runners**"* | ditto |

### Trzy rzeczy, o których łatwo nie wiedzieć

**a) Cena minuty spadła 1 stycznia 2026.**
GitHub obniżył stawki runnerów hostowanych o **do 39%** (Linux 2-core: z $0,008 na **$0,006/min**),
włączając w to nowo wprowadzoną opłatę platformową $0,002/min.

**b) Opłata za self-hosted runnery została ogłoszona i *wycofana*.**
16.12.2025 GitHub zapowiedział **$0,002/min „GitHub Actions cloud platform charge"**
także dla self-hosted, od **1 marca 2026**, przy czym minuty self-hosted miały konsumować tę samą darmową pulę.
Po fali krytyki społeczności GitHub **wstrzymał tę zmianę** („we've read your posts and heard your feedback",
„taking time to reevaluate our approach"). Obniżka cen runnerów hostowanych weszła zgodnie z planem.
**Na wrzesień 2026 dokumentacja nadal mówi, że self-hosted jest darmowy.**

> **Ryzyko do zapisania w kalendarzu:** to jest zmiana odłożona, nie odwołana.
> Gdyby wróciła w zapowiedzianym kształcie, przy 2 000 darmowych minut i realnym zużyciu
> ~600–1 200 min/mies. **nadal byłbyś w darmowej puli** — koszt = 0. Więc ryzyko jest realne, ale małe.

**c) Copilot code review od 1 czerwca 2026 zużywa minuty Actions.**
Jeśli kiedykolwiek włączysz automatyczne review Copilota, zaczyna ono ciąć tę samą pulę.

### Fakt, który podważa założenie z `docs/infra/CI_BEZ_ACTIONS.md`

`CI_BEZ_ACTIONS.md` mówi: *„Dla repozytoriów prywatnych GitHub nalicza minuty Actions z puli konta;
konto tej puli obecnie nie ma."*

**Plan GitHub Free daje 2 000 minut miesięcznie dla repozytoriów prywatnych i nie wymaga do tego karty
ani żadnego planu płatnego.** Więc albo:

1. minuty zostały w tym miesiącu wyczerpane (resetują się 1. dnia miesiąca), albo
2. w Billing ustawiony jest spending limit i komunikat dotyczy *nadmiaru*, nie puli bazowej, albo
3. repozytorium należy do organizacji z inną konfiguracją, albo
4. założenie jest po prostu nieaktualne.

**Zanim podejmiesz tę decyzję, wejdź w Settings → Billing and licensing → Usage this month i sprawdź licznik.**
To jedno kliknięcie, a może całkowicie unieważnić problem. `[do weryfikacji — stan limitu organizacji woogitsu]`

**Ile CI da się kupić za 2 000 minut?**
Pełna kontrola z `scripts/check.sh` (PostgreSQL jako service, `composer install` z cache, Pint, analiza statyczna,
Pest, `migrate:refresh`, build assetów) to realnie **4–8 min na ubuntu-latest** (mnożnik ×1).

| Zużycie | Liczba przebiegów CI / mies. |
|---|---|
| 4 min/przebieg | **500** |
| 6 min/przebieg | **333** |
| 8 min/przebieg | **250** |

Przy solowym projekcie 250–500 przebiegów miesięcznie to **więcej, niż fizycznie zrobisz pushy**.
A gdyby zabrakło: 100 dodatkowych przebiegów po 6 min = 600 min × $0,006 = **$3,60 ≈ 13 zł**.

---

## 2. Trzy scenariusze

| | **A. Prywatne + tylko hook lokalny** *(stan obecny)* | **B. Prywatne + własny runner** | **C. Publiczne** |
|---|---|---|---|
| **Koszt / mies.** | **0 zł** | **0 zł** za minuty + maszyna: mini-PC 10 W ≈ **8 zł** prądu, VPS w PL ≈ **15–40 zł**, Railway ≈ **$5+** | **0 zł** |
| **Zyskujesz** | zero zależności; `check.sh` jest **szybszy niż CI** (bez kolejki runnerów i `composer install` od zera); pełna kontrola nad kodem i `docs/` | zielony/czerwony status widoczny w PR; własny cache (jeszcze szybciej niż runner GitHuba); brak limitu minut; kod nadal zamknięty | nielimitowane standardowe runnery; **darmowe CodeQL / code scanning** (na Free/Pro tylko dla repo publicznych); darmowe secret scanning + push protection; matryca wersji PHP „za darmo"; zewnętrzna wiarygodność projektu |
| **Tracisz** | brak sprawdzenia w PR; hook działa **tylko u osoby, która go zainstalowała**; `--no-verify` obchodzi wszystko; brak dowodu „zielone przed merge" | trzeba utrzymywać maszynę (aktualizacje, dysk, runner service); przy awarii runnera **CI stoi i nie ma fallbacku**; jedna maszyna = brak równoległości | **poufność `docs/`** (patrz §3); kontrola nad kopiami (patrz niżej) |
| **Ryzyka** | regresja wchodzi na `main`, bo ktoś pominął hook; `railway.ts` z `KUKING_WAIT_FOR_CI` musi zostać wyłączony, inaczej deploy czeka na check suite, który nigdy nie powstanie | **jeśli kiedyś zrobisz repo publicznym, self-hosted runner staje się luką bezpieczeństwa** — GitHub wprost: *„We recommend that you only use self-hosted runners with private repositories. This is because forks of your public repository can potentially run dangerous code on your self-hosted runner machine by creating a pull request that executes the code in a workflow."*; ryzyko powrotu opłaty $0,002/min | plan produktowy w rękach każdego; **nieodwracalność forków** — patrz niżej; LICENSE to dziś placeholder „proprietary / all rights reserved", co przy repo publicznym jest sprzeczne komunikacyjnie |

### Nieodwracalność, o której trzeba wiedzieć przed kliknięciem „Make public"

Regulamin GitHuba (ToS, D.5 *License Grant to Other Users*):

> *„By setting your repositories to be viewed publicly, you agree to allow others to view and 'fork' your repositories…
> By making a repository public, you grant other Users a nonexclusive, worldwide license to use, display, perform
> and reproduce (by forking) Your Content through the Service."*

Czyli: **upublicznienie nadaje licencję na forkowanie**, a późniejsze przełączenie na prywatne
**nie usuwa cudzych forków ani kopii w cache Google / archiwach / zbiorach treningowych**.
To decyzja jednokierunkowa. Nie jest „na próbę".

### Stan higieny repo — sprawdzone, wygląda dobrze

| Sprawdzenie | Wynik |
|---|---|
| Liczba commitów w historii | **5** — historia krótka, łatwa do przejrzenia ręcznie |
| Pliki wrażliwe w całej historii (`git log --all --name-only`) | tylko **`.env.example`** — żadnego `.env`, kluczy R2, DSN Sentry, `.pem`, `.key` |
| LICENSE | `KUKING PROJECT — PROPRIETARY PLACEHOLDER`, `All rights reserved` — **wymaga decyzji przed upublicznieniem** |

Historia jest czysta. To zdejmuje z upublicznienia największe pojedyncze ryzyko techniczne.
Nadal warto przed przełączeniem uruchomić `gh secret-scanning` / `trufflehog` — 10 minut.

---

## 3. Co realnie oznacza upublicznienie **tego** repozytorium

W `docs/` leży ok. **11 200 linii** dokumentacji, w tym:

| Plik | Linie | Co zawiera |
|---|---:|---|
| `docs/research/PUBLIC_REPOS.md` | 608 | analiza cudzych repozytoriów |
| `docs/product/RETENTION_LOOPS.md` | 404 | mechaniki retencji |
| `docs/research/AUDIENCE_50_PLUS.md` | 402 | badanie grupy docelowej |
| `docs/product/COLD_START.md` | 397 | **strategia cold startu** |
| `docs/research/COMPETITIVE_LANDSCAPE.md` | 384 | **analiza konkurencji** |
| `docs/MONETIZATION.md` | — | **plan monetyzacji** |
| `docs/infra/DEPLOYMENT_RUNBOOK.md` | 1 126 | runbook produkcyjny |
| `docs/legal/*` | ~780 | compliance, moderacja, security baseline |

### Argumenty ZA upublicznieniem

1. **Strategia nie jest sekretem, wykonanie jest.** Cookpad, Kwestia Smaku i Mojegotowanie mogą przeczytać
   `COLD_START.md` w całości i nic z tego nie wyniknie: żaden z nich nie przestawi się na produkt dla 50+
   bez rankingów, bo to sprzeczne z ich modelem reklamowym. Plan monetyzacji „subskrypcja kiedyś"
   to nie jest informacja, którą ktoś zmonetyzuje.
2. **Realny konkurent nie czyta cudzych repozytoriów.** Konkurencja, która ma zespół i budżet,
   robi własne badania. Konkurencja, która nie ma zespołu, nie zagraża.
3. **Twarda korzyść finansowo-techniczna, nie tylko minuty:** na planach Free/Pro **CodeQL / code scanning
   działa tylko na repozytoriach publicznych**. Dla repo prywatnego to Team/Enterprise + Code Security.
   Secret scanning i push protection też są darmowe dla publicznych. To realny, wyceniony gain, nie ideologia.
4. **Wiarygodność w grupie 50+ jest budowana zaufaniem, nie tajemnicą.** Serwis, którego regulamin,
   playbook moderacji i baseline bezpieczeństwa są jawne, jest łatwiejszy do polecenia
   bibliotece, UTW i kołu gospodyń niż serwis-czarna-skrzynka.
5. **Rekrutacja i pomoc.** Jednoosobowy projekt z jawnym repo dostaje czasem PR-a albo poprawkę dostępności.
   Prywatny nie dostaje nigdy.

### Argumenty PRZECIW

1. **`COLD_START.md` to jedyna rzecz, która jest naprawdę trudna do odtworzenia.** Kto pozyskuje pierwszych
   500 użytkowników 50+, przez które biblioteki, które UTW, jakim tekstem ulotki, w jakiej kolejności —
   to nie jest wiedza z internetu, to jest wynik pracy. Upublicznienie oddaje ją **razem z listą kanałów**.
   Jeśli w tym dokumencie są nazwy konkretnych instytucji i osób kontaktowych, to jest to jednocześnie
   **ryzyko RODO** (dane osobowe w publicznym repo), nie tylko konkurencyjne. **Sprawdź to zanim cokolwiek klikniesz.**
2. **`AUDIENCE_50_PLUS.md` to gotowy brief dla kogoś, kto chce zrobić to samo szybciej.** Ktoś z kapitałem
   może wziąć ten dokument i wystartować z gotowym rozumieniem grupy. Nie musi to być konkurent z branży —
   równie dobrze agencja szukająca niszy.
3. **`DEPLOYMENT_RUNBOOK.md` + `SECURITY_BASELINE.md` publicznie to mapa dla atakującego.** Nie sekrety,
   ale topologia: co gdzie stoi, jakie są limity, jak wygląda rate limiting, gdzie są kolejki.
   To realnie skraca rozpoznanie.
4. **Serwis o treściach użytkowników ma podwyższone ryzyko trollingu.** Jawny `MODERATION_PLAYBOOK.md`
   mówi trollowi dokładnie, gdzie są progi i ile trwa reakcja. To jedyna kategoria dokumentu,
   o której naprawdę bym powiedział: nie publikuj.
5. **Odwrotności nie ma.** Patrz ToS D.5 wyżej.

### Odpowiedź, która nie udaje, że jest prosta

To **nie jest wybór binarny „całe repo publiczne albo całe prywatne"**, i to jest najważniejszy wniosek
tej sekcji. Realnie masz czwartą opcję, której nie ma w pytaniu:

> **Kod publiczny + strategia prywatna.**
> Przenieś `docs/product/COLD_START.md`, `docs/research/*`, `docs/MONETIZATION.md`
> i `docs/legal/MODERATION_PLAYBOOK.md` do **drugiego, prywatnego repozytorium** (`kuking-strategia`).
> W repo publicznym zostaje kod, `docs/ARCHITECTURE.md`, `docs/DATABASE.md`, `docs/UX_50_PLUS.md`,
> `docs/design/*`, `docs/TESTING.md` — czyli wszystko, co jest potrzebne, żeby zrozumieć i uruchomić projekt,
> i nic, co jest przewagą.

Koszt tej operacji: jedno popołudnie (`git filter-repo` na historii 5 commitów jest trywialne).
Zysk: darmowe nielimitowane CI + darmowy CodeQL, bez oddawania tego jednego dokumentu, który naprawdę boli.

---

## Rekomendacja

**Najpierw sprawdź licznik w Settings → Billing — plan Free daje 2 000 minut miesięcznie dla repozytoriów prywatnych, co przy 4–8-minutowym `check.sh` wystarcza na 250–500 przebiegów CI, więc problem może nie istnieć.** Jeśli minuty faktycznie są, zostań na **scenariuszu A** (hook lokalny, 0 zł) i po prostu odkomentuj `on:` w `ci.yml` — najniższy koszt, zero nowych zależności, `docs/` zostaje zamknięte. Jeśli minut nie ma i chcesz zielonego statusu w PR, wybierz **kod publiczny + strategia w drugim, prywatnym repo** — to daje nielimitowane runnery i darmowe CodeQL bez oddawania `COLD_START.md`, `AUDIENCE_50_PLUS.md` i `MODERATION_PLAYBOOK.md`. **Własnego runnera (scenariusz B) nie polecam jako pierwszego wyboru**: kosztuje utrzymanie maszyny, nie ma fallbacku przy awarii, a przy repo publicznym GitHub wprost odradza to jako lukę bezpieczeństwa. **Zanim klikniesz „Make public", przeczytaj `COLD_START.md` pod kątem nazwisk i danych kontaktowych instytucji** — historia gita jest czysta (5 commitów, tylko `.env.example`), ale RODO w publicznym repo to inny problem niż sekrety, a forka nie da się odwołać.

---

## Źródła

- [GitHub Docs — Billing for GitHub Actions (2 000 / 3 000 / 50 000 min, publiczne repo darmowe, self-hosted darmowy)](https://docs.github.com/en/billing/concepts/product-billing/github-actions)
- [GitHub Docs — Actions runner pricing ($0,006/min Linux 2-core x64)](https://docs.github.com/en/billing/reference/actions-runner-pricing)
- [GitHub Changelog — Reduced pricing for GitHub-hosted runners usage (1.01.2026)](https://github.blog/changelog/2026-01-01-reduced-pricing-for-github-hosted-runners-usage/)
- [GitHub Changelog — Update to GitHub Actions pricing (zapowiedź $0,002/min dla self-hosted + informacja o wstrzymaniu)](https://github.blog/changelog/2025-12-16-coming-soon-simpler-pricing-and-a-better-experience-for-github-actions/)
- [GitHub Roadmap #1197 — Self-hosted runner price increase](https://github.com/github/roadmap/issues/1197)
- [GitHub Community — Updates to GitHub Actions pricing (dyskusja)](https://github.com/orgs/community/discussions/182186)
- [GitHub Changelog — Copilot code review zużywa minuty Actions od 1.06.2026](https://github.blog/changelog/2026-04-27-github-copilot-code-review-will-start-consuming-github-actions-minutes-on-june-1-2026/)
- [GitHub Docs — Adding self-hosted runners (ostrzeżenie o repozytoriach publicznych)](https://docs.github.com/en/actions/how-tos/manage-runners/self-hosted-runners/add-runners)
- [GitHub Docs — Terms of Service, D.5 License Grant to Other Users](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service)
- [GitHub Docs — Code scanning with CodeQL (Free/Pro: tylko repozytoria publiczne)](https://docs.github.com/code-security/code-scanning/introduction-to-code-scanning/about-code-scanning-with-codeql)
- [GitHub Docs — About secret scanning (darmowe dla repozytoriów publicznych)](https://docs.github.com/code-security/secret-scanning/about-secret-scanning)
- [GitHub Changelog — Push protection enabled for free users](https://github.blog/changelog/2024-02-29-push-protection-is-enabled-for-free-users-on-github/)
