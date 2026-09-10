# Audyt 17 — poczta, digest, limity i dostarczalność

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot:** `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`  
**Data:** 10.09.2026

## Wniosek

Warstwa pocztowa ma bardzo dobre zabezpieczenia produktowe: komenda diagnostyczna odróżnia „Laravel przyjął wiadomość” od faktycznego dostawcy, `MAIL_MAILER=log/array` nie jest uznawany za działającą pocztę, a budżety funkcji są jawne. Największy błąd jest jednak w samym liczniku: **sprawdzenie miejsca i jego zajęcie nie są jedną atomową operacją**.

## Ustalenia

### MAIL-01 — P1 — dobowy budżet nie jest twardym sufitem przy równoległości

**Plik:** `app/Domain/Security/DziennyBudzetListow.php`

API klasy rozdziela:
- `jestMiejsce()/zostalo()` — odczyt,
- `zajmij()` — późniejszy `Cache::increment()`.

Przy dwóch lub większej liczbie równoległych requestów/jobów wszyscy mogą zobaczyć ostatnie wolne miejsce przed tym, zanim którykolwiek je zajmie.

Przykład: budżet 120, użyte 119.
- A: `jestMiejsce() == true`
- B: `jestMiejsce() == true`
- A wysyła i inkrementuje → 120
- B wysyła i inkrementuje → 121

`increment()` jest atomowy jako pojedyncza operacja, ale **check + increment nie jest atomowe jako para**.

**Naprawa:**
- metoda `sprobujZarezerwowac(): bool`, która wykonuje warunkową atomową zmianę;
- przy cache database najprościej trzymać budżet w tabeli z `UPDATE ... SET used = used + 1 WHERE used < limit RETURNING used`;
- przy Redis: Lua script / atomic counter z odrzuceniem po limicie;
- alternatywnie `Cache::lock()` wokół check+increment, jeśli backend locków jest trwały i współdzielony.

Nie wystarczy „po increment sprawdzić, czy przekroczyliśmy” — wiadomość może już być zakolejkowana.

---

### MAIL-02 — P1 — digest nie ma idempotencji przy crashu

Patrz audyt 16, QUEUE-01. Dla poczty skutek jest szczególnie nieprzyjemny: użytkownik może dostać ten sam tygodniowy list dwa razy, choć UI obiecuje „jeden e-mail tygodniowo”.

**Naprawa:** unikalny klucz wysyłki `user + okres`, rezerwowany trwale przed dispatch.

---

### MAIL-03 — P2 — telemetria `WEEKLY_DIGEST_SENT` oznacza w praktyce „zakolejkowano”

W `WyslijPodsumowaniaTygodnia` sygnał `WEEKLY_DIGEST_SENT` jest zapisywany zaraz po `Mail::queue`, przed faktycznym kontaktem workera z dostawcą.

To miesza trzy różne zdarzenia:
- queued,
- provider accepted,
- delivered.

Dla produktu może to zawyżać skuteczność wysyłki, szczególnie gdy `failed_jobs` nie ma automatycznego alarmu.

**Rekomendacja:**
- zmienić nazwę obecnego sygnału na `weekly_digest_queued`, albo
- emitować `sent/accepted` dopiero w transporcie po odpowiedzi 2xx dostawcy;
- `delivered/opened` nie udawać bez prawdziwego sygnału dostawcy. W szczególności nie wracać do pixel tracking tylko po to, aby mieć ładną metrykę.

---

### MAIL-04 — P0 operacyjny przed szerokim startem — realny dostawca i deliverability muszą być potwierdzone

`app/Console/Commands/SprawdzPoczte.php` jest bardzo dobrym narzędziem i wprost opisuje ryzyko konfiguracji `MAIL_MAILER=log`. Repo nie daje jednak dostępu do panelu EmailLabs ani do rzeczywistego wyniku dostarczania.

Przed kampanią:
- uruchomić `kuking:sprawdz-poczte` synchronicznie i przez kolejkę;
- sprawdzić co najmniej WP/O2/Interia/Onet + Gmail/Outlook;
- sprawdzić SPF, DKIM, DMARC w **nagłówkach dostarczonej wiadomości**;
- potwierdzić Reply-To i możliwość odpowiedzi;
- potwierdzić brak open-trackingu, jeżeli polityka prywatności obiecuje jego brak;
- potwierdzić limit konta u dostawcy i sposób liczenia odrzuconych prób.

To jest bramka operacyjna, nie bug do „naprawienia” w Laravelu.

## Co jest dobre

- reset hasła nie kłamie o wysłaniu, gdy `Poczta::dziala()` jest fałszywe;
- magic-link ma wspólny budżet projektowy i limity per IP/per adres;
- diagnostyka nie traktuje sterowników `log`/`array` jako dostawy;
- komentarze kodu właściwie rozróżniają queue acceptance od doręczenia;
- digest nie wysyła pustej wiadomości.

