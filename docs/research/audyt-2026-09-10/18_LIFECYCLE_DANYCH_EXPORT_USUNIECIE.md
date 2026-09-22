# Audyt 18 — lifecycle danych, eksport, usunięcie konta i RODO

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot:** `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`  
**Data:** 10.09.2026  
**Uwaga:** to audyt techniczno-zgodnościowy, nie formalna opinia prawna.

## Wniosek

Kuking ma wyjątkowo rozbudowany jak na MVP lifecycle: 30-dniową karencję, osobny stan `erased`, wybór zakresu usunięcia, kasowanie zdjęć, wygaszanie eksportów i retry po nieudanym delete storage. Dwie kwestie wymagają jednak korekty koncepcyjnej:

1. pozostawienie UGC po usunięciu nazwy konta **nie jest automatycznie anonimizacją**;
2. obecny eksport jest bardzo dobrym eksportem danych/portability, ale nie powinien sam z siebie być przedstawiany jako pełna realizacja art. 15 RODO bez sprawdzenia wszystkich kategorii danych i informacji wymaganych przez prawo dostępu.

## Ustalenia

### DATA-01 — P1 prawny — tekst UGC po podmianie autora może nadal być daną osobową

**Pliki:**
- `app/Domain/Users/Actions/EraseAccountData.php`
- `app/Http/Controllers/Settings/DataSettingsController.php`
- `docs/legal/COMPLIANCE.md`

Kod zakłada, że po usunięciu:
- e-maila,
- username/display name,
- profilu,
- zdjęć,

pozostawione wpisy/przepisy/komentarze można traktować jako „zanonimizowany tekst”.

To za szerokie założenie. Sam tekst może identyfikować człowieka, np. przez:
- imię/nazwisko w treści,
- dokładne miejsce,
- link do własnej strony/social,
- opis choroby lub zdarzenia rodzinnego,
- unikalną historię,
- dane osób trzecich,
- podpis/telefon/e-mail wpisany ręcznie.

EDPB rozróżnia pseudonimizację od anonimizacji: dane prawdziwie zanonimizowane muszą przestać dawać się powiązać z osobą. Motyw 26 RODO wymaga uwzględnienia środków, których można rozsądnie użyć do identyfikacji.

To **nie prowadzi automatycznie** do wniosku „zawsze usuwaj cały UGC”. Art. 17 ma wyjątki i konkretne podstawy dalszego przetwarzania mogą istnieć. Problemem jest blanket statement, że każdy pozostawiony tekst po zdjęciu podpisu nie jest już danymi osobowymi.

**Rekomendacja:**
- rozdzielić produktowo:
  - „zamknij konto i pozostaw wkład społecznościowy bez profilu”,
  - „żądam usunięcia moich danych osobowych”;
- dla retained UGC mieć procedurę redakcji/usunięcia fragmentów identyfikujących;
- opisać podstawę dalszego przetwarzania i wyjątki art. 17 zamiast opierać się na samej etykiecie „anonimowe”;
- przy DSAR/erasure umożliwić zgłoszenie konkretnych pozostawionych treści.

**Źródła zewnętrzne:**
- EDPB, Anonymisation / pseudonymisation: https://www.edpb.europa.eu/topics/ai-and-technology/anonymisation-pseudonymisation_en
- EDPB, Guidelines 02/2026 on Anonymisation (wersja do konsultacji na dzień audytu): https://www.edpb.europa.eu/public-consultations/guidelines-022026-on-anonymisation_en
- RODO, EUR-Lex: https://eur-lex.europa.eu/eli/reg/2016/679/oj

---

### DATA-02 — P2/P1 prawny — eksport miesza art. 15 i art. 20

**Pliki:**
- `app/Domain/Users/Exports/CollectUserExportData.php`
- `app/Http/Controllers/Settings/DataSettingsController.php`

Generator obejmuje dużo:
- konto i profil,
- przepisy i wpisy,
- wykonania,
- komentarze użytkownika,
- kolekcje,
- relacje,
- powiadomienia,
- zdjęcia.

To jest mocny zakres dla eksportu użytkowego/przenoszenia danych.

Nie widać jednak w tym samym eksporcie np. pełnego zestawu:
- zgłoszeń i formalnych notices związanych z osobą,
- odwołań i działań moderacyjnych,
- wiadomości do operatora,
- wpisów `audit_log` dotyczących osoby,
- danych bezpieczeństwa/logowania,
- sygnałów produktowych, jeżeli są danymi osobowymi,
- informacji art. 15(1): cele, kategorie, odbiorcy, okresy retencji, źródło, prawa, automatyczne decyzje.

Część z nich może być przekazywana inną procedurą i część może podlegać ograniczeniom ze względu na prawa innych osób. Audyt nie stwierdza więc automatycznego naruszenia. Stwierdza, że **etykieta „art. 15 i 20” jest szersza niż udowodniony zakres tego generatora**.

**Rekomendacja:**
- traktować obecny self-service ZIP jako „Pobierz swoje dane / przenoszenie danych”;
- osobno opisać procedurę formalnego żądania dostępu art. 15;
- albo rozbudować paczkę o komplet danych i informacji wymaganych przez art. 15 z filtrami ochrony osób trzecich.

**Źródło:** EDPB Guidelines 01/2022 on Right of Access:
https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/guidelines-012022-data-subject-rights-right-access_en

---

### DATA-03 — P2 — dwa równoległe żądania mogą uruchomić dwa eksporty

**Pliki:**
- `app/Http/Controllers/Settings/DataSettingsController.php`
- `database/migrations/2026_09_05_001100_create_data_exports_table.php`

Kontroler wykonuje:
1. `exists()` dla `queued/processing`;
2. później osobny `DataExport::create()`.

Baza nie ma częściowego unikalnego indeksu ograniczającego aktywny eksport do jednego na `user_id`.

**Skutek:** double-submit/równoległe requesty mogą wygenerować dwie duże paczki i dwa joby.

**Preferowana naprawa PostgreSQL:**
```sql
CREATE UNIQUE INDEX data_exports_one_active_per_user
ON data_exports (user_id)
WHERE status IN ('queued', 'processing');
```
Następnie kontroler powinien przechwycić konflikt i zwrócić istniejący komunikat „przygotowanie już trwa”.

Alternatywa: blokada wiersza `users` w transakcji.

---

### DATA-04 — pozytywne — kolejność DB/storage przy anonimizacji jest poprawiona

`EraseAccountData` najpierw zatwierdza transakcję DB, dopiero potem kasuje pliki. To właściwy kierunek: rollback DB nie potrafi odtworzyć usuniętego obiektu R2.

Dodatkowo:
- przy niepełnym delete wiersz `media` zostaje jako retry handle;
- kolejne uruchomienie dla `data_erased_at != null` dokańcza usuwanie zdjęć;
- gotowe eksporty są natychmiast wygaszane logicznie, ale fizyczny delete korzysta z istniejącego retry-safe cleanup.

To jest dobry przykład obsługi efektu zewnętrznego.

## Kryterium zamknięcia

- dokumentacja nie nazywa retained UGC automatycznie anonimowym bez warunków;
- istnieje procedura obsługi personal data w samej treści;
- zakres Art.15 jest jawnie oddzielony od portability albo kompletnie obsłużony;
- aktywny eksport ma twardy invariant po stronie DB.

