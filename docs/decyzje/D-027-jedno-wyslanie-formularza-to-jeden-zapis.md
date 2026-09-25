## D-027 · Jedno wysłanie formularza to jeden zapis — klucz wysłania, nie okno czasowe

**Data:** 7 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

Podwójne kliknięcie nie jest w grupie 50+ pomyłką, tylko sposobem obsługi
komputera: strona myśli chwilę, więc klika się drugi raz. Zmierzone (audyt
wyścigów, 7 września 2026): dwa kliknięcia „Opublikuj" dawały dwa wpisy,
dwa kliknięcia „Ugotowałem" — dwa wykonania i **dwa powiadomienia** u autora
przepisu, a `reports` przyjmowało drugie identyczne otwarte zgłoszenie bez
oporu bazy.

Rozstrzygnięte DWA mechanizmy, nie jeden, bo to są dwa różne problemy:

1. **Wpis i „Ugotowałem": klucz wysłania.** Formularz dostaje przy
   renderowaniu jednorazowy klucz w ukrytym polu; tabela dostaje kolumnę
   `klucz_wyslania` i częściowy indeks UNIQUE. Zapis idzie
   „wstaw i złap wyjątek", a przy kolizji człowiek trafia na swój
   pierwszy wpis — drugie kliknięcie jest nieodróżnialne od pierwszego.
   **To NIE jest `UNIQUE (user_id, recipe_id)` i D-005 zostaje
   nienaruszone**: nowe gotowanie z nowego formularza przechodzi
   (zmierzone).
2. **Zgłoszenie: częściowy indeks UNIQUE w bazie** na otwartych
   zgłoszeniach pary (osoba, treść). Dedup w PHP już działał w zwykłym
   ruchu, ale nie chronił przed seederem, komendą ani wyścigiem — ten sam
   argument, który stoi za `moderation_actions_one_per_report`.

Odrzucone i dlaczego:

- **Blokada przycisku w JavaScripcie** jako mechanizm — publikacja musi
  działać bez JS (D-007), a brak skryptu to ten sam ruch, w którym strona
  ładuje się wolno, czyli ten, w którym klika się drugi raz. Zostaje
  wyłącznie jako niewiążący dodatek.
- **`lockForUpdate()` dla zgłoszeń** — zmierzone, że nie działa:
  `SELECT ... FOR UPDATE`, który nie zwrócił wiersza, nie blokuje niczego
  i oba połączenia wstawiają bez czekania.
- **Okno czasowe „ta sama treść w ciągu N sekund"** — przy pustym
  wykonaniu „Ugotowałem" odcisk treści degeneruje się do
  `(user_id, recipe_id)`, czyli do tego, czego D-005 zakazuje, na N sekund.

Mechanizm **zawodzi otwarcie**: nieznany albo brakujący klucz oznacza
„wyślij normalnie", nigdy „odmawiam". Zduplikowany wpis jest dla odbiorcy
50+ mniej szkodliwy niż utracony wpis, a ekran mówiący „ta strona wygasła"
jest gorszy od jednego i drugiego (issue #81, `errors/419.blade.php`).

**Wyłącznik awaryjny wchodzi razem z mechanizmem, nie później:**
`kuking.formularze.klucz_wyslania_wlaczony` (zmienna `KUKING_KLUCZ_WYSLANIA`).
Po ustawieniu na `false` formularze renderują się bez ukrytego pola, kolumna
dostaje `NULL`, częściowy indeks takiego wiersza nie obejmuje i serwis wraca
do zachowania sprzed tej decyzji. To jedyna droga wycofania, która nie wymaga
wdrożenia migracji — dlatego jest w konfiguracji, a nie w kodzie. Nie cofa
natomiast `reports_one_open_per_pair`: tamten indeks nie zależy od niczego,
co wysyła formularz, więc jego wycofanie to osobna migracja.

**Zmiana wymaga:** zmierzonego przypadku, w którym klucz wysłania blokuje
prawdziwe wysyłki, i to takiego, którego nie da się naprawić bez zmiany
samego mechanizmu.

📄 `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` · `docs/DATABASE.md` ·
`config/kuking.php` ·
`app/Domain/Posts/Actions/PublishPost.php` ·
`app/Domain/Recipes/Actions/RecordCookedEvent.php` ·
`app/Domain/Moderation/Actions/ReportContent.php`
