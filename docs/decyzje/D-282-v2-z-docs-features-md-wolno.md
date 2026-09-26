## D-282 — V2 z `docs/FEATURES.md` wolno budować od 26 września 2026 (Nie wcześnie — bez zmian)

**Data:** 26 września 2026 · Decyzja właściciela · Status: **obowiązuje**

**Problem.** `AGENTS.md` §2 i `CLAUDE.md` kazały sprawdzić sekcję „V2”
w `docs/FEATURES.md` wyłącznie po to, **żeby nie budować z niej podczas prac
nad MVP** — to zdanie stało tam od początku projektu i nikt go nie cofnął,
mimo że MVP dawno przestało być jedyną pracą w repozytorium (V1 w większości
zbudowane, dziennik dochodzi do D-28x). Zakaz był więc coraz mniej opisem
stanu, a coraz bardziej starym zdaniem, którego nikt nie zauważał przy
czytaniu ze zrozumieniem.

**Decyzja.** Od 26 września 2026 wolno budować funkcje V2 wymienione
w `docs/FEATURES.md` (sekcja „## V2”): skalowanie porcji, zamienniki, import
przepisu z adresu URL/PDF/zdjęcia, OCR starych zeszytów, spiżarnia
(„pantry”), „co ugotuję z tego, co mam”, wartości odżywcze i koszt
przygotowania. **Native apps zostają bez zmian** — nadal wyłącznie „jeśli PWA
potwierdzi retencję”, to zdanie ta decyzja NIE dotyka.

**Lista „Nie wcześnie” w `docs/FEATURES.md` zostaje BEZ ZMIAN i w całości** —
DM, czat/wideo na żywo, marketplace, wypłaty (payouts), punkty za liczbę
postów i masowy import cudzych treści są nadal zakazane, niezależnie od tej
decyzji. Ta decyzja dotyczy wyłącznie granicy MVP↔V2, nie granicy
V2↔„Nie wcześnie”.

**Kolejność wciąż obowiązuje.** Zniesienie zakazu V2 nie zwalnia z pracy
issues po kolei (P0 → P1 → P2, `AGENTS.md` §2/§10) — funkcja z V2 wchodzi do
kolejki na swoich prawach, nie przed pilniejszym P0/P1 z MVP/V1.

**AI w funkcjach V2 — model i konfiguracja.** Funkcje V2 wymagające modelu
językowego (OCR starych zeszytów, import ze zdjęcia/PDF/adresu URL, a w miarę
potrzeby też zamienniki i wartości odżywcze) korzystają z modelu OpenAI
**„GPT-6 Luna”**. Nazwa modelu i klucz API stoją WYŁĄCZNIE w konfiguracji
(`config/kuking.php` + zmienna w `.env`/`.env.example`), tym samym wzorcem co
istniejąca integracja moderacji AI (`config('kuking.moderation.model')`,
`config/kuking.php` ok. linii 3006–3040: `klucz` z `env()`, brak klucza =
funkcja wyłączona i nic nie pada, bez wyjątku dla żadnej z tych funkcji).
**Klucz nigdy nie stoi w kodzie** — ani wprost, ani jako domyślna wartość
`env()` inna niż pusta.

**Co z tym zrobiono w dokumentacji.** `AGENTS.md` §2 i `CLAUDE.md` — zdanie
nakazujące sprawdzać V2 „żeby nie budować" zastąpione odesłaniem do tej
decyzji. `docs/FEATURES.md` — adnotacja przy nagłówku „## V2” z datą i
numerem decyzji. Sama treść list V1/V2/„Nie wcześnie” w `docs/FEATURES.md`
się nie zmienia — zmienia się wyłącznie to, czy V2 wolno realizować.
`docs/ROADMAP.md` nie wspominał zakazu V2 wprost, więc nie wymagał zmiany.

### Wycofanie
Cofnięcie tej decyzji przywraca zakaz budowania V2 podczas prac nad MVP —
wymaga nowej decyzji właściciela, przywrócenia poprzedniego brzmienia
`AGENTS.md` §2 / `CLAUDE.md` i usunięcia adnotacji przy „## V2” w
`docs/FEATURES.md`. Nie cofa kodu już zbudowanego pod funkcje V2 — to
wymagałoby osobnej, jawnej decyzji o wycofaniu konkretnej funkcji.

📄 `AGENTS.md` §2, `AGENTS.md` §10, `CLAUDE.md`, `docs/FEATURES.md`,
`docs/ROADMAP.md`, `config/kuking.php`
