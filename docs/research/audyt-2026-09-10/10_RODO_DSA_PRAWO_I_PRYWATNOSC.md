# Audyt 10/13 — RODO, DSA, prawo, prywatność i komunikacja prawna

**Repozytorium:** `woogitsu/kuking.pl`  
**Punkt odniesienia:** `main` @ `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data audytu:** 10.09.2026  
**Charakter:** audyt produktu/kodu/dokumentów; **nie zastępuje opinii prawnej**.

## Werdykt

Warstwa prawna jest wyjątkowo dobrze udokumentowana jak na projekt tej skali, ale **nie jest gotowa do otwarcia rejestracji publicznej**. Największym problemem nie jest brak kolejnego paragrafu, tylko rozjazd między tym, co serwis **obiecuje**, a tym, co faktycznie robi.

Najpilniejsze są:

1. **P0 — #8 pozostaje bramką startową:** brak potwierdzonego przeglądu prawnika, brak domkniętej dokumentacji umów powierzenia i ROPA.
2. **P0 — regulamin obiecuje wynik każdego zgłoszenia, lecz zwykły reporter go nie dostaje.**
3. **P0 — polityka prywatności mówi, że e-maile nie śledzą otwarć, a #204 dokumentuje aktywny piksel EmailLabs na prawdziwej wiadomości.**
4. **P1 — zgoda na tygodniowy digest nie ma wystarczającego śladu dowodowego.**
5. **P1 — dokument COMPLIANCE ma nieaktualny stan polskiej legislacji DSA.**
6. **P1 — brak potwierdzonego okresu życia danych w backupach.**

## 1. P0 — bramka prawna #8 nadal jest realna

Issue **#8** ma priorytet P0 i literalnie wymaga przed publicznym startem:

- przeglądu regulaminu i polityki prywatności przez prawnika,
- weryfikacji DSA/UŚUDE,
- umów powierzenia z dostawcami,
- rejestru czynności przetwarzania,
- potwierdzonej procedury dotyczącej CSAM / art. 18 DSA.

Stan operatora został już poprawiony: publiczne dokumenty zawierają SAMSUFI sp. z o.o. i dane rejestrowe. Nie należy więc powtarzać starego punktu issue o placeholderach.

Natomiast oba dokumenty publiczne nadal wprost oznaczają się jako **nieweryfikowane przez prawnika**. Polityka prywatności dodatkowo stwierdza, że umów powierzenia z wymienionymi dostawcami jeszcze nie ma podpisanych.

### Rekomendacja

Nie zamykać #8 zmianą tekstu „dokument zweryfikowany”. Kryterium zamknięcia powinno wymagać artefaktu spoza publicznego repo:

- kto wykonał przegląd i kiedy,
- wersja dokumentów / commit objęty przeglądem,
- lista zmian po opinii,
- rejestr DPA/DPA-in-terms z datą i dostawcą,
- ROPA / rejestr czynności przetwarzania,
- osobna checklista reakcji z art. 18 DSA.

**Uwaga krytyczna:** stwierdzenie „nie mamy podpisanych DPA” w polityce może być technicznie zbyt kategoryczne, jeżeli któryś dostawca włącza umowę powierzenia automatycznie do warunków online. Trzeba sprawdzić faktyczny łańcuch umowny, a nie samo istnienie osobnego PDF-u z podpisem.

---

## 2. P0 — regulamin obiecuje odpowiedź każdemu reporterowi, a produkt tego nie robi

`resources/legal/regulamin.md`, §7:

> „Każde zgłoszenie sprawdzamy. **Poinformujemy Cię o wyniku.**”

`docs/legal/MODERATION_PLAYBOOK.md` mówi natomiast wprost, że osoba korzystająca ze zwykłego przycisku **„Zgłoś”** nie dostaje nic po zamknięciu sprawy; wiadomość e-mail idzie dziś tylko dla określonej ścieżki zgłoszenia nielegalnej treści z adresem e-mail.

To nie jest rozjazd dokumentacji wewnętrznej. To rozjazd **umowa → zachowanie usługi**.

Dodatkowo własny `docs/legal/COMPLIANCE.md` wskazuje jako minimum DSA dla Kuking potwierdzenie przyjęcia zgłoszenia i informację o decyzji dla zgłaszającego.

### Ryzyko

- zobowiązanie wobec użytkownika jest szersze niż implementacja,
- moderator może uważać proces za zamknięty, podczas gdy warstwa publiczna obiecała dalszą komunikację,
- problem ma znaczenie niezależnie od tego, jak ostatecznie prawnik zakwalifikuje konkretną ścieżkę pod art. 16 DSA.

### Rekomendacja

**Preferowane rozwiązanie:** wdrożyć spójny lifecycle reportera zamiast usuwać obietnicę:

1. potwierdzenie odebrania,
2. stabilny identyfikator sprawy,
3. status,
4. decyzja końcowa w bezpiecznie ograniczonym zakresie,
5. właściwa informacja o środkach zaskarżenia.

Nie ujawniać reporterowi danych o sankcji nałożonej na autora ponad to, co jest potrzebne.

---

## 3. P0 — EmailLabs śledzi otwarcia wbrew publicznej polityce prywatności

Issue **#204** dokumentuje pomiar na prawdziwej wiadomości dostarczonej 09.09.2026 na o2.pl. W HTML znalazły się **dwa mechanizmy śledzenia otwarcia** pod `click.kuking.pl/track/o/...`:

- piksel `<img>`,
- zapasowy `background:url(...)`.

Jednocześnie polityka prywatności przy tygodniowym digescie mówi:

> „Nie sprawdzamy, czy otworzyłaś list (...) — w tych wiadomościach nie ma obrazków śledzących (...).”

oraz deklaruje ogólnie brak zewnętrznego narzędzia analitycznego.

### Dlaczego to jest P0

To stan **faktycznie sprzeczny z opublikowaną informacją**, a nie hipotetyczna przyszła funkcja. Według #204 tracking kliknięć jest wyłączony z kodu, ale tracking otwarć jest ustawieniem konta EmailLabs i nie daje się wyłączyć tym samym nagłówkiem API.

### Rekomendacja

Przed publicznym startem:

1. wyłączyć tracking otwarć w panelu EmailLabs,
2. wysłać realny list testowy,
3. sprawdzić **surowy HTML** wiadomości,
4. kryterium akceptacji: zero `click.kuking.pl/track/o/`, także w CSS/background,
5. zapisać datę weryfikacji w dokumentacji poczty.

Jeżeli dostawca nie pozwala wyłączyć trackingu, należy rozstrzygnąć zmianę dostawcy albo pełną zmianę opisu/podstawy prawnej. Dla transakcyjnych maili Kuking najlepszym wariantem jest **brak trackingu**, ponieważ repo nie wskazuje celu, dla którego te dane są potrzebne.

---

## 4. P1 — dowód zgody na tygodniowy digest jest zbyt słaby

Baza zapisuje obecnie:

- bieżący boolean `wants_weekly_digest`,
- datę ostatniej wysyłki.

Brakuje co najmniej informacji, **kiedy i jak** zgoda została udzielona oraz wycofana. RODO art. 7 ust. 1 wymaga, by administrator był w stanie wykazać udzielenie zgody.

Samo „dzisiaj pole ma wartość true” jest słabszym dowodem niż historia operacji zgody.

### Rekomendowany minimalny model

- `weekly_digest_consented_at`,
- `weekly_digest_withdrawn_at`,
- opcjonalnie `weekly_digest_consent_source` (`settings`, `unsubscribe_link`, przyszłe źródła),
- audit event bez treści wiadomości i bez zbędnego PII.

Dodatkowo rollback migracji ustawiającej opt-in domyślnie na `false` nie powinien przywracać domyślnego `true`.

**Źródło prawne:** RODO art. 7 ust. 1 — administrator ma móc wykazać zgodę.

---

## 5. P1 — brak ROPA / rejestru czynności przetwarzania

Najnowsza weryfikacja #8 wskazuje, że w repo nie ma rejestru czynności przetwarzania.

RODO art. 30 przewiduje wyjątek dla organizacji poniżej 250 osób, ale wyjątek nie działa m.in. wtedy, gdy przetwarzanie **nie ma charakteru sporadycznego**. Dla portalu społecznościowego obsługującego konta, UGC, moderację i e-mail przetwarzanie jest działalnością ciągłą, nie okazjonalną.

### Rekomendacja

ROPA nie musi być publiczne i nie musi mieszkać w publicznym repo. Powinno jednak istnieć jako kontrolowany dokument operacyjny i obejmować co najmniej:

- cele i kategorie danych,
- kategorie osób,
- odbiorców/podmioty przetwarzające,
- transfery poza EOG,
- okresy retencji,
- środki bezpieczeństwa,
- procesy: konto, UGC, moderacja, Turnstile, OpenAI moderation, e-mail, backupy, support, telemetryka.

---

## 6. P1 — dokument `COMPLIANCE.md` ma nieaktualny stan polskiej legislacji DSA

Dokument nadal zawiera uwagę, że ustawa krajowa była w toku jeszcze w 2025 r. i wymaga weryfikacji „na wrzesień 2026”.

Stan sprawdzony na **10.09.2026**:

- poprzednia nowelizacja UŚUDE z 18.12.2025 została zawetowana przez Prezydenta RP 09.01.2026,
- nowy proces legislacyjny doszedł dalej: 04.09.2026 Sejm przyjął poprawkę Senatu i zakończył etap parlamentarny,
- oficjalny UKE informuje, że ustawa ma wyznaczyć Prezesa UKE na Koordynatora ds. usług cyfrowych,
- po tym etapie ustawa została skierowana do Prezydenta; UKE podaje, że po akceptacji ma wejść w życie po 30 dniach od ogłoszenia.

Nie znalazłem w użytych oficjalnych źródłach potwierdzenia podpisu/ogłoszenia nowej ustawy do chwili audytu, więc nie należy pisać, że już weszła w życie.

### Rekomendacja

Zamienić placeholder historyczny na konkretną notę z datą:

> Stan na 10.09.2026: prace parlamentarne zakończone 04.09.2026; ustawa skierowana do Prezydenta RP; przed startem ponownie sprawdzić podpis, publikację w Dz.U. i datę wejścia w życie.

Oficjalne źródła:

- UKE, 04.09.2026: https://www.uke.gov.pl/uslugi-cyfrowe/aktualnosci/sejm-uchwalil-ustawe-wdrazajaca-przepisy-aktu-o-uslugach-cyfrowych-prezes-uke-koordynatorem-ds-uslug-cyfrowych%2C26.html
- Prezydent RP, 09.01.2026 (weto poprzedniej ustawy): https://www.prezydent.pl/aktualnosci/wydarzenia/prezydent-podpisal-osiem-ustaw-trzy-zawetowal%2C112911

---

## 7. P1 — nieustalony okres retencji danych w backupach

Polityka prywatności uczciwie mówi, że backupy bazy mogą zawierać dane po usunięciu konta i że liczby dni **jeszcze nie ustalono z dostawcą**.

To jest lepsze niż zmyślona liczba, ale dla dojrzałej operacji nadal brakuje odpowiedzi na cztery pytania:

1. ile kopii i przez ile dni,
2. kto może przywrócić backup,
3. jak po restore ponownie egzekwowane są późniejsze usunięcia/anonimizacje,
4. kiedy backup fizycznie przestaje zawierać usunięte dane.

### Rekomendacja

Potwierdzić retencję u Railway i opisać **proces restore-after-deletion**, nie tylko liczbę dni.

---

## 8. P2 — `zasady.md` i playbook opisują odwołanie inaczej

Publiczne `resources/legal/zasady.md`, pkt 11 mówi jedynie:

> „napisz do nas. Sprawdzimy to jeszcze raz.”

Playbook moderacji sam odnotowuje, że produkt ma już przycisk odwołania w powiadomieniu, termin 6 miesięcy i obietnicę odpowiedzi w 7 dni roboczych.

Nie jest to samo w sobie naruszenie — krótka wersja zasad może być krótsza — ale w dokumencie operacyjnym problem jest już oznaczony jako rozjazd. Warto ujednolicić najważniejsze parametry proceduralne.

---

## 9. P2 — stopka polityki prywatności jest nieaktualna względem własnego dokumentu

Na końcu polityki stoi:

> „Czego w tym dokumencie jeszcze nie ma, a będzie: dostawcy poczty (...)”

Tymczasem tabela w §3 już wymienia **EmailLabs (Vercom S.A.)**.

To mały błąd, ale w dokumencie prawnym taki self-contradiction obniża zaufanie i utrudnia przegląd zmian.

---

## 10. Co jest zrobione dobrze

- operator jest wskazany konkretnie,
- polityka opisuje rzeczywisty przepływ danych do Turnstile i OpenAI zamiast ukrywać pod hasłem „partnerzy”,
- automatyczna moderacja jest opisana jako **sygnał do review, nie autonomiczna decyzja**,
- dokument rozróżnia dane konta, UGC, dane moderacyjne, statystyki i support,
- okresy retencji są w wielu miejscach konkretne i powiązane z jobami,
- polityka uczciwie zaznacza nieustalone elementy,
- regulamin zachowuje prawa użytkownika do UGC i ogranicza licencję do funkcji produktu,
- usuwanie konta opisuje zarówno wariant zachowania zanonimizowanych tekstów, jak i pełnego usunięcia.

## Kolejność napraw

1. **#204 — wyłączyć i zweryfikować tracking otwarć.**
2. **Zamknąć kontraktowy rozjazd „Poinformujemy Cię o wyniku” vs zwykłe zgłoszenia.**
3. **Przegląd prawnika + rzeczywisty rejestr DPA + ROPA (#8).**
4. **Dodać ślad dowodowy zgody na digest.**
5. **Zaktualizować status polskiej ustawy DSA.**
6. **Ustalić backup retention i restore-after-deletion.**
7. Ujednolicić zasady / playbook / stopkę polityki.

## Źródła zewnętrzne

- RODO, art. 7 i art. 30: EUR-Lex, Rozporządzenie (UE) 2016/679.
- UKE, 04.09.2026 — zakończenie etapu parlamentarnego nowej ustawy wdrażającej DSA.
- Prezydent RP, 09.01.2026 — weto poprzedniej nowelizacji UŚUDE.

## Ograniczenia audytu

Nie miałem dostępu do zawartych umów handlowych/DPA poza repozytorium, panelu EmailLabs, panelu Railway ani systemów kancelarii. Tam, gdzie dokument repo mówi „brak”, traktuję to jako stan projektu, ale przed wnioskiem prawnym trzeba sprawdzić rzeczywiste warunki kont dostawców.
