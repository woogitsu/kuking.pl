# Audyt 13/13 — treści, copy, lokalizacja i spójność informacji

**Repozytorium:** `woogitsu/kuking.pl`  
**Punkt odniesienia:** `main` @ `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 10.09.2026

## Werdykt

Warstwa językowa Kuking.pl jest jedną z mocniejszych części projektu. Teksty są po polsku, zwykle konkretne, unikają żargonu i bardzo często tłumaczą użytkownikowi **co ma zrobić**, a nie tylko co poszło źle. To szczególnie ważne dla grupy 50–75.

Największy problem leży jednak w **spójności stanu informacji**. Repozytorium rozwija się bardzo szybko, a dokumenty i komentarze zaczęły gromadzić sprostowania do wcześniejszych założeń. Efekt: poprawna informacja istnieje, lecz operator musi wiedzieć, **który akapit jest najnowszy**.

To jest ryzyko operacyjne, nie redakcyjne.

---

## 1. P1 — aktualny `deploy.yml` sam zawiera dwa różne opisy tego, gdzie działa CI

Na początku `.github/workflows/deploy.yml` nadal znajduje się starszy opis, według którego joby chodzą na własnej puli `woogitsu-linux-*` i runnera „nie wybiera już żadna zmienna repozytorium”. Kilkadziesiąt linii niżej ten sam aktualny plik wyjaśnia odwrotny stan: tymczasowo używane jest `ubuntu-latest`, a `runs-on` czyta `CI_RUNS_ON`, które pozwala przełączyć się na self-hosted.

Kod wykonawczy używa:

```yaml
runs-on: ${{ fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"') }}
```

Czyli drugi opis odpowiada kodowi, pierwszy jest historycznym komentarzem.

### Ryzyko

Komentarz operacyjny stojący obok workflow jest traktowany jak dokumentacja wysokiego zaufania. Przy incydencie może skierować operatora do złej diagnozy („job czeka na nasz runner”), mimo że job faktycznie miał trafić na GitHub-hosted runner.

### Rekomendacja

W plikach wykonywalnych zostawiać wyłącznie **stan bieżący**. Historię decyzji przenosić do `docs/DECISIONS.md` / runbooka. Każdy komentarz opisujący stan z datą powinien mieć jedną z dwóch form:

- `AKTUALNE — zweryfikowano YYYY-MM-DD`, albo
- `HISTORYCZNE — nie opisuje bieżącej konfiguracji`.

---

## 2. P1 — `docs/OTWARCIE.md` jest dobrym źródłem kolejności, ale sprostowania zaczynają zasłaniać stan bieżący

`docs/OTWARCIE.md` robi bardzo dobrą rzecz: próbuje być dokumentem **kolejności bramek**, a nie kolejnym pełnym runbookiem. Jednocześnie ma już kilka dużych bloków `SPROSTOWANIE`, m.in. dotyczących:

- braku Volume Backups/PITR na bieżącym planie,
- zależności etapu backupu od R2,
- niewykonanego `railway config apply`,
- faktycznego `preDeployCommand`,
- liczby usług Railway.

Sprostowania są merytorycznie wartościowe, lecz dokument startowy nie powinien zmuszać operatora do rekonstruowania stanu poprzez historię pomyłek.

### Rekomendacja

Przepisać `OTWARCIE.md` do modelu snapshot:

```text
STAN ZWERYFIKOWANY: 2026-09-10
ŹRÓDŁO: panel / connector / repo / test na produkcji

[ ] R2 — NIEZWERYFIKOWANE / BLOKUJE
[ ] offsite backup — NIEZROBIONE / BLOKUJE
[ ] restore drill — NIEZROBIONE / BLOKUJE
[x] ...
```

Historia sprostowań powinna zostać w `DECISIONS.md` albo w sekcji na końcu, nie pomiędzy krokami do wykonania.

---

## 3. P1 — daty na publicznych dokumentach prawnych nie odpowiadają już tempu zmian repo

`resources/legal/regulamin.md` mówi, że opisuje stan serwisu na **7 września 2026**, a `resources/legal/polityka-prywatnosci.md` — na **8 września 2026**. Oba dokumenty jednocześnie deklarują, że są „aktualizowane razem” z serwisem.

Tymczasem 9–10 września doszły lub zostały ujawnione materialne fakty dotyczące m.in.:

- sposobu moderowania awatara przez OpenAI,
- tygodniowego digestu,
- faktycznego zachowania EmailLabs,
- śledzenia otwarć wiadomości,
- obsługi kolejki pocztowej,
- zmian w ścieżkach użytkownika.

Nie każda zmiana wymaga zmiany regulaminu, ale **stała data stanu** sugeruje użytkownikowi, że po tej dacie przeprowadzono pełne sprawdzenie zgodności.

### Rekomendacja

Rozdzielić dwie daty:

- `ostatnia zmiana treści prawnej`,
- `ostatnia weryfikacja zgodności treści ze stanem produkcji`.

Druga powinna być aktualizowana wyłącznie po realnym przeglądzie, nie automatycznie przy każdym commicie.

---

## 4. P2 — słownik produktu nie jest jeszcze całkowicie jednolity: „Mój zeszyt” / „Zeszyt” / „Moje”

`public/manifest.webmanifest` używa skrótu PWA:

- `Mój zeszyt`,
- `Zeszyt`,
- URL `/zeszyt`.

W aktualnym UI produkt używa też prostszego określenia `Moje`. Dla użytkowników migrujących ze starego serwisu ważniejsze od kreatywności nazewniczej jest to, aby ta sama rzecz miała **jedną nazwę** na telefonie, w menu, w PWA, w pomocy i w mailach.

### Rekomendacja

Ustalić krótki słownik produktu, np.:

| Pojęcie wewnętrzne | Jedyna nazwa dla użytkownika |
|---|---|
| saved recipes / collections | `Moje` albo `Zeszyt` — wybrać jedno |
| post | `wpis` |
| recipe | `przepis` |
| cooked event | `Ugotowałem` |
| follow | `Obserwuj` |

Nie mieszać synonimów w głównych ścieżkach.

---

## 5. P2 — rejestracja obiecuje koniec wcześniej, niż faktycznie kończy się onboarding

To samo ustalenie pojawia się w audycie UX, ale z perspektywy copy jest osobnym problemem spójności obietnicy.

Formularz rejestracji komunikuje sens „cztery pola i gotowe”, a po utworzeniu konta użytkownik widzi onboarding `Krok 1 z 3`.

Dla nowego użytkownika oznacza to: serwis właśnie powiedział „gotowe”, po czym natychmiast oznajmił, że zostały trzy kroki.

### Rekomendacja

Jedna z dwóch dróg:

1. onboarding jest **opcjonalnym ulepszeniem profilu** — po rejestracji użytkownik trafia do produktu, a kroki można pominąć;
2. onboarding jest częścią rejestracji — wtedy pierwsza strona nie obiecuje „gotowe”.

Dla grupy 60–75 wybrałbym wariant 1.

---

## 6. P2 — `composer.json` nadal przedstawia aplikację jako szkielet Laravel

`composer.json` ma:

```json
"name": "laravel/laravel",
"description": "The skeleton application for the Laravel framework."
```

Nie psuje to działania aplikacji, ale jest nieprawdziwą metadokumentacją projektu. Trafia do narzędzi zależności, SBOM-ów, skanerów, raportów i może wprowadzać w błąd kolejne osoby/agentów.

### Rekomendacja

Zmienić na własną nazwę projektu, np.:

```json
"name": "woogitsu/kuking",
"description": "Kuking.pl — społeczność ludzi, którzy gotują."
```

Jeżeli pakiet nigdy nie jest publikowany, nie ma potrzeby komplikować tego bardziej.

---

## 7. P2 — plan i stan powinny mieć różne, maszynowo rozpoznawalne oznaczenia

W wielu dokumentach istnieją zdania typu:

- „docelowo”,
- „gdy powstanie”,
- „po R2”,
- „Railway ma mieć”,
- „Sentry/PostHog”,
- konfiguracje wpisane do `.railway/railway.ts`, które nie zostały jeszcze `config apply`.

Repo już kilkukrotnie samo odkryło pomylenie **„kod/planuje” z „produkcja ma”**.

### Rekomendacja

W dokumentacji infrastruktury wprowadzić cztery standardowe znaczniki:

- `WYKONANE I ZWERYFIKOWANE`,
- `ZAIMPLEMENTOWANE, NIEZWERYFIKOWANE NA PRODUKCJI`,
- `PLAN`,
- `BLOKADA / DECYZJA WŁAŚCICIELA`.

Dla krytycznych bramek każdy `WYKONANE` powinien mieć: **datę + metodę dowodu + środowisko**.

---

## 8. P2 — publiczna polityka prywatności ma już wewnętrznie nieaktualny footer

W tabeli dostawców polityka prywatności wymienia EmailLabs, ale footer dokumentu nadal mówi:

> „Czego w tym dokumencie jeszcze nie ma, a będzie: dostawcy poczty...”

To jednoznaczny przykład dryfu dokumentu w obrębie **tego samego pliku**.

### Rekomendacja

Usunąć zdanie o brakującym dostawcy poczty. Dodać prosty test tekstowy lub checklistę legal-review wykrywającą takie sprzeczności przed publikacją.

---

## 9. P3 — polski-only na start jest właściwą decyzją, nie brakiem lokalizacji

Projekt jest kierowany do polskojęzycznej społeczności, a kampania migracyjna odnosi się do Garnek.pl. Brak wersji angielskiej/niemieckiej nie jest obecnie długiem produktu.

Nie rekomenduję dodawania i18n przed osiągnięciem aktywnej społeczności. Koszt tłumaczenia:

- UI,
- e-maili,
- regulaminów,
- zgłoszeń moderacyjnych,
- SEO,
- supportu

byłby większy niż wartość na obecnym etapie.

---

## 10. Co jest bardzo dobre

- dominują krótkie zdania i czasowniki czynnościowe,
- polskie komunikaty błędów często mówią, jak wyjść z problemu,
- dokumenty prawne próbują mówić ludzkim językiem zamiast kopiować kancelaryjne formuły,
- produkt nie nazywa odbiorców „seniorami”,
- CTA `Co dziś ugotowałeś?` dobrze tłumaczy sens serwisu bez instrukcji,
- terminologia techniczna jest w dużej mierze schowana przed zwykłym użytkownikiem,
- repo ma kulturę jawnego prostowania wcześniejszych pomyłek zamiast ich ukrywania.

## Kolejność działań

1. Oczyścić `deploy.yml` z nieaktualnego opisu runnerów.
2. Zamienić `OTWARCIE.md` w aktualny snapshot bramek, historię wynieść niżej.
3. Zrobić ponowny legal/content review dokumentów publicznych po zmianach z 9–10 września.
4. Usunąć sprzeczny footer o brakującym dostawcy poczty.
5. Ujednolicić `Moje` / `Zeszyt`.
6. Naprawić obietnicę `gotowe` względem onboardingu.
7. Zmienić domyślne metadata Laravel w `composer.json`.
8. Nie dodawać nowych języków przed osiągnięciem product-market fit społeczności.
