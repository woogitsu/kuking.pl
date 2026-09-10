# Audyt 09 — moderacja, Trust & Safety i odwołania

**Repozytorium:** `woogitsu/kuking.pl`  
**Punkt odniesienia:** `main` @ `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 2026-09-10

## Ocena

Polityka moderacji jest wyjątkowo szczegółowa jak na ten etap produktu i dobrze rozdziela zgłoszenia społecznościowe, zgłoszenia nielegalnych treści oraz odwołania. Największy problem to rozjazd między dojrzałością dokumentu a możliwościami narzędzia moderatora: część kluczowych reguł istnieje tylko „w głowie moderatora”.

Dla serwisu z 1–2 moderatorami jest to ryzyko operacyjne, nie akademickie. Przy małym zespole system powinien bardziej pilnować kolejności, historii i terminów, bo właśnie wtedy człowiek ma najmniej redundancji.

## Znaleziska

### MOD-01 — P0/P1 — krytyczne zgłoszenie może zostać zakopane przez nowszy spam

`docs/legal/MODERATION_PLAYBOOK.md` definiuje P0–P3 i wprost przyznaje, że:
- `reports` nie ma kolumny priorytetu;
- `/admin/zgloszenia` jest sortowane od **najnowszych**;
- P0 sprzed dwóch dni może znaleźć się poniżej świeżego spamu;
- priorytet jest „porządkiem w głowie moderatora”.

To jest niedopuszczalne dla kategorii opisanych jako P0: CSAM, realne groźby, aktywny doxxing / zagrożenie życia.

**Klasyfikacja:**
- P0 przed publicznym startem, jeżeli system ma być oficjalnym kanałem notice-and-action dla treści nielegalnych;
- P1 jeśli przed startem istnieje równoległy, skuteczny alert operacyjny poza repo.

**Rekomendacja:**
1. `priority`/`severity` jako pole wynikające z typu zgłoszenia + możliwość korekty przez moderatora;
2. kolejka `P0 -> P1 -> P2 -> P3`, w obrębie priorytetu najstarsze pierwsze;
3. osobne, nieusuwalne oznaczenie „P0 nieprzejrzane”;
4. alert poza samym panelem dla nowych P0;
5. test: P0 starsze od P2 zawsze pojawia się wyżej.

### MOD-02 — P1 — system eskalacji kar nie pokazuje moderatorowi historii wcześniejszych decyzji

Playbook używa reguł typu „2. wystąpienie”, „powtórka”, „3. → blokada trwała”, ale sam dokument przyznaje:

> Panel nie pokazuje historii wcześniejszych kar autora.

W efekcie ta sama sprawa może zakończyć się inaczej w zależności od pamięci moderatora. Przy zmianie moderatora historia praktycznie znika z procesu decyzyjnego, mimo że istnieje w danych.

**Rekomendacja:** na karcie sprawy pokazać ograniczony do potrzeb moderacyjnych timeline: ostatnie decyzje, kategoria, data, kara, wynik odwołania. Bez treści niepotrzebnej do bieżącej decyzji.

### MOD-03 — P1 — `/admin/sygnaly` może przenieść uzasadnienie między sprawami

Otwarte issue **#243** opisuje dwa konkretne błędy na stronie z maks. 25 formularzami:
- `old('note')` i `old('user_message')` po błędzie jednego formularza wypełniają wszystkie pozostałe;
- pola w pętli dostają powtarzające się `id` (`f-note`, `f-user_message`).

Najgroźniejszy skutek jest merytoryczny: moderator może wysłać przy sprawie B notatkę/uzasadnienie napisane dla sprawy A.

**Rekomendacja:** zamknąć #243 przed pracą operacyjną na kolejce; stare dane kluczować identyfikatorem zgłoszenia, a wszystkie `id`/`for` generować per sprawa.

### MOD-04 — P1 — statusy `triage`/`reviewing` istnieją, lecz workflow ich nie używa

Playbook mówi wprost, że `triage` i `reviewing` istnieją w bazie, ale kod prowadzi zgłoszenie z `open` bezpośrednio do `resolved` albo `rejected`; zakładka „W trakcie” jest przez to stale pusta.

To nie jest tylko martwy status. Przy 1–2 moderatorach potrzebny jest sygnał:
- „ktoś już to czyta”;
- „tej sprawy nie zamykamy dziś, ale jest przejęta”;
- „czekamy na dodatkowe dane”.

**Rekomendacja:** albo realnie wdrożyć `triage/reviewing` z timestampem i właścicielem sprawy, albo usunąć pozorną funkcję z UI/schematu. Obecny stan udaje workflow, którego nie ma.

### MOD-05 — P1 — brak automatycznego pilnowania spraw „ukrytych do wyjaśnienia”

Playbook przyznaje, że ukryta treść pozostaje ukryta bezterminowo, a termin ponownego przeglądu moderator ma zapisać sobie sam. To tworzy stan „zapomnianej sprawy”: treść nie jest formalnie usunięta, ale może być niewidoczna miesiącami bez decyzji końcowej.

**Rekomendacja:** `review_due_at` / „wróć do sprawy”, kolejka przeterminowanych decyzji tymczasowych, bez automatycznego kasowania treści.

### MOD-06 — P2 — zgłaszający zwykłym przyciskiem „Zgłoś” nie dostaje wyniku sprawy

Zgodnie z playbookiem odpowiedź automatyczna do zgłaszającego istnieje tylko dla zgłoszeń nielegalnej treści z adresem e-mail. Zwykłe zgłoszenie społecznościowe nie daje zgłaszającemu żadnego zamknięcia pętli.

Nie musi to być pełne uzasadnienie. Wystarczy stan „sprawdziliśmy — podjęliśmy działanie / nie stwierdziliśmy naruszenia”, jeżeli nie narusza to prywatności drugiej strony.

**Wpływ:** użytkownik nie wie, czy przycisk w ogóle działa, co obniża skłonność do przyszłych zgłoszeń.

### MOD-07 — P1 — procedura P0 nadal zawiera prawny placeholder „do weryfikacji przed startem”

W sekcji CSAM / zagrożenie życia playbook ma jawny zapis:

> `[do weryfikacji z prawnikiem: ... Art. 18 DSA ... potwierdzić dokładną ścieżkę przed startem]`

To jest poprawnie oznaczone jako brak, ale brak dotyczy właśnie scenariusza, którego nie wolno ustalać podczas incydentu.

**Rekomendacja:** przed publicznym startem zamienić placeholder na zatwierdzoną procedurę: kto decyduje, do jakiego organu, jakim kanałem, jakie minimum danych, jak dokumentować numer sprawy, kto ma dostęp do materiału dowodowego i jak wygląda retencja.

## Co jest zrobione dobrze

- obowiązkowe 2FA dla moderatorów;
- rozróżnienie `suspended` vs `banned` i automatyczne wygasanie kar czasowych;
- autor jest informowany o decyzji, istnieje odwołanie;
- decyzja moderacyjna nie jest automatyzowana w najcięższych sprawach;
- playbook uczciwie opisuje rzeczy, których panel jeszcze nie potrafi;
- soft-delete i zachowanie danych przy poważnym incydencie są przemyślane;
- formularz DSA jest oddzielony od zwykłego „Zgłoś”.

## Priorytet wdrożenia

1. **Przed publicznym startem:** prawdziwy priorytet P0 + alert oraz domknięta procedura kryminalna/Art. 18.
2. Naprawić #243.
3. Pokazać historię wcześniejszych kar i wyników odwołań.
4. Uruchomić prawdziwe `reviewing` / terminy ponownego przeglądu.
5. Dodać minimalne zamknięcie pętli dla zwykłego zgłaszającego.
