# Otwarcie serwisu — stan bramek i kolejność

**STAN ZWERYFIKOWANY: 10.09.2026.**

**Ten dokument NICZEGO nie tłumaczy.** Tłumaczą to dokumenty wskazane przy
każdym etapie, każdy w swoim zakresie i szczegółowo. Tego brakowało:
**kolejności**, tego która rzecz blokuje którą, i **czy jest zrobiona**.

Wszystkie etapy są **po stronie właściciela**. Repozytorium nie ma dostępu do
paneli Railway, Cloudflare, EmailLabs ani R2 — i nie powinno mieć. Kodem tych
bramek zamknąć się nie da.

---

## Jak czytać ten dokument

Każda pozycja ma jeden z czterech znaczników. Trzeci i czwarty **nie są
sukcesem**:

| Znacznik | Co znaczy |
|---|---|
| `WYKONANE I ZWERYFIKOWANE` | ktoś to zrobił **i** ktoś sprawdził, że działa; jest data, metoda i środowisko |
| `ZAIMPLEMENTOWANE, NIEZWERYFIKOWANE NA PRODUKCJI` | kod jest, testy zielone, nikt tego nie potwierdził na żywym serwisie |
| `NIEZROBIONE` | nie ma tego |
| `DECYZJA WŁAŚCICIELA` | czeka na człowieka, nie na kod |

Zasada, której ten dokument ma pilnować: **`NIE WIEMY` liczy się jako
nieprzejście bramki, nie jako sukces.** Puste pole „wynik" w dowolnej tabeli
dowodowej znaczy nieprzejście, nie „chyba dobrze".

Każde `WYKONANE I ZWERYFIKOWANE` musi mieć trzy rzeczy: **datę + metodę
dowodu + środowisko**. Bez nich to jest `NIEZWERYFIKOWANE` z ładniejszą nazwą.

---

## Tabela stanu — 10.09.2026

| # | Bramka | Stan | Dowód / co brakuje |
|---|---|---|---|
| 1 | **Poczta wychodzi z produkcji** | `WYKONANE I ZWERYFIKOWANE` | 09–10.09: raport EmailLabs pokazuje realne doręczenia; potwierdzenie adresu przy rejestracji doszło do prawdziwej skrzynki. Metoda: panel dostawcy + relacja odbiorcy. |
| 2 | **Brak nieujawnionego śledzenia otwarć** | `NIEZROBIONE — BLOKUJE` | Issue #204: w doręczonej wiadomości z 09.09 są dwa mechanizmy `click.kuking.pl/track/o/…` (piksel i zapasowy `background:url`), a polityka prywatności mówi, że ich nie ma. Wyłączyć w panelu EmailLabs i sprawdzić **surowy HTML** realnego listu. |
| 3 | **`kontakt@kuking.pl` realnie odbiera** | `NIE WIEMY` | Dwie wiadomości z 09.09 miały w raporcie EmailLabs stan `deferred`. Sprawdzić, czy ostatecznie doszły. |
| 4 | **SPF / DKIM / DMARC na polskich skrzynkach** | `NIEZWERYFIKOWANE` | Trzy słowa `spf=pass`, `dkim=pass`, `dmarc=pass` w nagłówkach listu doręczonego na wp.pl, o2.pl, interia.pl, onet.pl. Benchmarku dla nich nie ma; własny test kosztuje popołudnie i zero złotych. |
| 5 | **R2: nowe zdjęcia idą z R2, nie z kontenera** | `WYKONANE, ZWERYFIKOWANE CZĘŚCIOWO` | 10.09, z zewnątrz, na produkcji: żądanie wariantu → 302 na podpisany adres `…r2.cloudflarestorage.com…` → 200 `image/webp`. Żądanie bez podpisu i żądanie oryginału odrzucone. `cdn.kuking.pl` nie istnieje w DNS. **To nie zamyka bramki #120** — patrz wiersz 6. |
| 6 | **Bramka R2 (#120) wypełniona** | `NIEZROBIONE — BLOKUJE` | `railway ssh -- php artisan kuking:bramka-r2 --zapis` odhacza siedem punktów z dwunastu prawdziwymi żądaniami. **Od 11.09 punkt 2 (oryginał niedostępny przez KAŻDĄ publiczną ścieżkę) jest odhaczany tylko dla adresów wypisanych w `KUKING_R2_PUBLICZNE_ADRESY`** — pusta zmienna daje `NIE WIEMY` i oblewa bramkę, bo adres niezapytany nie jest dowodem na nic. Reszta to panel Cloudflare. Kroki krok po kroku: `docs/infra/BRAMKA_R2.md` §2a; wynik z datą do §3 — dziś kolumny „wynik" i „data" są puste. |
| 7 | **Stare zdjęcia przeniesione z wolumenu** | `NIEZROBIONE` | 10.09 zmierzone z zewnątrz: zdjęcia sprzed R2 nadal serwuje PHP z wolumenu kontenera. `php artisan kuking:przenies-zdjecia --dry-run`, potem bez `--dry-run`. Dopóki tam leżą, utrata kontenera to utrata oryginałów. |
| 8 | **Automatyczna kopia bazy poza Railway** | `NIEZROBIONE — BLOKUJE` | Issue #193. **Dziś nie ma żadnej kopii bazy.** Volume Backups i PITR są tylko w planie Pro, Kuking jest na Free/Hobby. Do wykonania #193 każda utrata bazy jest bezpowrotna (D-043). Potrzebuje bucketu z wiersza 6 jako miejsca lądowania. |
| 9 | **Ćwiczenie odtworzenia (restore drill)** | `NIEZROBIONE — BLOKUJE` | Issue #9. Zrzut, którego nikt nigdy nie odtworzył, jest obietnicą, nie kopią. Tabela wyniku w `docs/infra/KOPIE_I_ODTWORZENIE.md` §5 jest pusta. Potrzebne: RPO, RTO, kontrola integralności po odtworzeniu, lista tego co nie zadziałało. |
| 10 | **Retencja kopii ustalona** | `NIEZROBIONE` | Ile trzymamy zrzuty i co to znaczy dla usunięcia konta na żądanie. To jest też pozycja RODO, nie tylko infrastruktury. |
| 11 | **Zewnętrzny monitor `/health`** | `NIEZWERYFIKOWANE` | Issue #33. `/health` istnieje w kodzie; nie ma dowodu, że ktokolwiek go z zewnątrz odpytuje i że alarm dociera. Potrzebny kontrolowany test awarii i **zmierzony** czas do alarmu. |
| 12 | **Test dymny po wdrożeniu naprawdę się uruchamia** | `NIEZWERYFIKOWANE` | `.github/workflows/deploy.yml` sam podaje: 249 przebiegów, wszystkie `skipped`. Warunek został zmieniony, skuteczność poprawki **nie została potwierdzona realnym wdrożeniem**. Dowód: run `Deploy` z jobem `Test dymny po deployu` = SUCCESS, nie skipped. |
| 13 | **`railway config apply` uruchomione** | `NIEZROBIONE` | Stan zmierzony connectorem 09.09: produkcyjny `preDeployCommand` to wyłącznie `php artisan migrate --force --no-interaction`. Nie sprawdzone ponownie 10.09. Bez tego `db:seed --force` z `.railway/railway.ts` nie obowiązuje. **Nie uruchamiaj bez kopii z wiersza 8.** |
| 14 | **Treść zalążkowa na produkcji** | `NIEZROBIONE` | Zależy od wierszy 8 i 13. Po wdrożeniu sprawdzić **na żywej stronie**, że przykładowe konta i przepisy są. Zielony deploy nie jest dowodem, że seeder cokolwiek zapisał. |
| 15 | **Bezpośredni origin Railway nie obsługuje ruchu** | `NIEZWERYFIKOWANE` | `NormalizeForwardedFor` sam dokumentuje, że wejście na `*.up.railway.app` może dać łańcuch `X-Forwarded-For` złożony wyłącznie z wartości podanej przez klienta. Dowód: test z zewnętrznego internetu, że origin nie obsługuje zwykłego żądania albo wymaga niepodrabialnego sygnału z brzegu. Sama konfiguracja middleware to nie dowód. |
| 16 | **Administrator z 2FA zdolny rozpatrzyć odwołanie** | `NIEZWERYFIKOWANE` | `php artisan kuking:nadaj-role`, potem realne wejście do panelu. |
| 17 | **Reset hasła przećwiczony na własnym koncie** | `NIEZWERYFIKOWANE` | Nie „kod istnieje", tylko „dostałem wiadomość i ustawiłem hasło". |
| 18 | **Alarm z webhooka naprawdę dochodzi** | `NIEZWERYFIKOWANE` | I to bez danych osobowych w treści (po naprawie A6-01). |
| 19 | **Regiony bazy i magazynu zgodne z polityką** | `NIEZWERYFIKOWANE` | Porównać panel z tym, co mówi `resources/legal/polityka-prywatnosci.md`. |
| 20 | **Umowy powierzenia (DPA)** | `DECYZJA WŁAŚCICIELA` | Cloudflare, EmailLabs, OpenAI, Railway, a po wdrożeniu logowania kontem Google także Google. **Brak osobno podpisanego PDF-u nie dowodzi braku umowy** — RODO art. 28 dopuszcza formę elektroniczną i włączenie do warunków usługi. Sprawdzać warunki wiążące konto, nie to, czy dostawca ma stronę o DPA. |
| 21 | **Przegląd dokumentów przez prawnika** | `DECYZJA WŁAŚCICIELA` | Issue #8. Regulamin, polityka prywatności, ROPA/rejestr czynności, procedura DSA, ścieżka dla najwyższego ryzyka moderacyjnego. |
| 22 | **Aktualny stan polskiego wdrożenia DSA** | `DECYZJA WŁAŚCICIELA` | Sprawdzić **w dniu decyzji o starcie**, nie przepisywać z `docs/legal/COMPLIANCE.md`. Stan na 10.09.2026: komunikat UKE z 04.09 mówi, że Sejm uchwalił ustawę i przekazano ją Prezydentowi; brak potwierdzenia podpisania i ogłoszenia. |
| 23 | **Procedura P0 bez prawnego placeholdera** | `DECYZJA WŁAŚCICIELA` | `docs/legal/MODERATION_PLAYBOOK.md` ma przy CSAM / zagrożeniu życia jawny zapis „do weryfikacji z prawnikiem … Art. 18 DSA". Brak jest poprawnie oznaczony, ale dotyczy scenariusza, którego nie wolno ustalać w trakcie incydentu. |
| 24 | **Testy z osobami 50–75** | `W TOKU` | Issue #15 zakłada 13 sesji (50–59, 60–69, 70+). Wykonana **jedna**, z 63-latką, i wykryła rzeczy, których nie złapał żaden automat (rejestracja, ekran logowania linkiem, kolory stanów, zdjęcie zamiast napisu o przetwarzaniu). To argument za kontynuowaniem sesji, nie za uznaniem UX za zamknięty. |
| 25 | **Realna społeczność przed kampanią** | `NIEZROBIONE — BLOKUJE KAMPANIĘ` | Issue #29. Minimum: 20–30 aktywnych realnych osób, 100–150 autentycznych wpisów, 7–10 dni historii, odpowiedź pod większością pierwszych wpisów, kilka żywych tematów. **Bez fałszywych kont.** Kampania nostalgiczna skierowana w pusty feed spala najsilniejszą grupę pierwszego kontaktu — wejdą raz. |

---

## Kolejność i dlaczego właśnie taka

```
[6] R2 — bramka #120 ──────────┐  R2 jest pierwsze, bo zrzut z [8]
    (kilka godzin)             │  nie ma gdzie lądować
                               ▼
[8] kopia bazy (#193) ─────────┐
    automat, nie panel         │  blokuje wszystko, co pisze do produkcji
                               ▼
[9] ćwiczenie odtworzenia ─────┼─────► [13][14] config apply + treść zalążkowa
                               │
[2] wyłączyć śledzenie otwarć ─┘  niezależne, ale blokuje szerszy mailing

[11][12] monitoring i test dymny — równolegle, nic nie blokują
[16]–[23] bramka bety i prawo — równolegle, nic nie blokują
[24] testy 50–75 → [25] społeczność → dopiero potem kampania
```

**Etap R2 jest pierwszy nie dlatego, że jest najważniejszy**, a dlatego, że
kopia bazy potrzebuje miejsca do lądowania i R2 ma darmowy pułap 10 GB.

**Najważniejszy jest wiersz 8 i 9.** Do czasu wykonania #193 każda utrata bazy
jest bezpowrotna. Marketing ma prawo mówić „możesz pobrać swoje dane", bo to
istniejąca funkcja. **Nie ma prawa mówić „u nas nic nie zniknie" ani „Twoje
zdjęcia są bezpieczne na zawsze", dopóki wiersze 7, 8 i 9 nie są zamknięte** —
to obietnica o bardzo wysokiej wadze emocjonalnej dla ludzi, którzy raz już
stracili swój dorobek razem z zamkniętym serwisem.

---

## Co robić w każdym etapie

Sam dokument tego nie tłumaczy. Wskazuje miejsce:

| Bramka | Gdzie napisane |
|---|---|
| poczta [1]–[4] | `docs/infra/POCZTA_URUCHOMIENIE.md` §1 → §2A → §5; decyzja o dostawcy: `docs/decyzje/POCZTA.md` |
| R2 [5]–[7] | `docs/infra/BRAMKA_R2.md` (lista z miejscem na wynik i datę); tło: `docs/infra/INFRA_DECISION.md` |
| kopia i odtworzenie [8]–[10] | `docs/infra/KOPIE_I_ODTWORZENIE.md` **§7.3** (czynności w panelach: bucket, dwa tokeny, klucz szyfrujący, serwis cron) → **§4A** (ćwiczenie na prawdziwej kopii) → **§5** (tabela wyniku) |
| bramka bety [16]–[19] | `docs/legal/BRAMKA_BETY.md` (macierz warunków) |
| prawo [20]–[23] | `docs/legal/COMPLIANCE.md`, issue #8 |
| testy z ludźmi [24] | `docs/product/TESTY_Z_UZYTKOWNIKAMI.md`, issue #15 |
| kampania [25] | `docs/marketing/KAMPANIA_GARNEK.md`, issue #29 |

### Etap 0 (wiersze 8–10) — co jest w kodzie, a czego nie ma w panelach

**Kod tej warstwy jest już w repozytorium** (`docker/kopia/`, issue #193):
zrzut `pg_dump` 18, szyfrowanie kluczem publicznym, wysyłka do R2, alarm przy
porażce, retencja i czujka `kuking:sprawdz-kopie` po stronie aplikacji. Nie
ma za to **ani jednej** z rzeczy, których nie da się zrobić kodem — bucketu,
tokenów, klucza i serwisu w Railway. Do ich założenia liczba kopii bazy
wynosi zero, mimo gotowego kodu. **To jest cały etap 0.**

> ⚠️ **Nie zakładaj serwisu przez `railway config apply`.** Produkcja ma dziś
> jeden serwis `kuking.pl`, a `.railway/railway.ts` opisuje trzy inne —
> `apply` skasowałby ten działający (patrz sprostowanie w tamtym pliku
> i §7.3 krok 4). Serwis kopii zakłada się dziś ręcznie w panelu.

**Co masz z tego mieć:** świeży zrzut produkcyjnej bazy **i** dowód, że da się
go odtworzyć — **odszyfrować kluczem prywatnym i wczytać przez `pg_restore`**.
Nie sam zrzut: zrzut, którego nikt nigdy nie odtworzył, jest obietnicą, nie
kopią. A zrzut, którego nie da się odszyfrować, jest tylko plikiem.

**Gdzie zapisujesz dowód:** tabela w §5 tego samego dokumentu. Data, kto,
które ćwiczenie, rozmiar, czas odszyfrowania, czas odtworzenia (RTO), wiek
zrzutu (RPO), zgodność skrótu z `.meta`, zgodność liczników, co nie zadziałało.
Ostatnia kolumna jest najważniejsza i zwykle nie jest pusta.

**Gdzie mieszka klucz, którym to odszyfrujesz:** §7.1. W Railwayu leży tylko
część **publiczna** — klucz prywatny ma dwie kopie, w menedżerze haseł i na
nośniku offline, i **nigdzie więcej**. Jego utrata unieważnia wszystkie kopie
naraz, dlatego stoi w tabeli ryzyk §1.1 obok `APP_KEY`.

### Trzy pułapki, na których najłatwiej stracić popołudnie

1. **Zmienna w panelu bez restartu nie działa, a wygląda, jakby działała.**
   Konfiguracja jest zapiekana przy starcie kontenera. Po wpisaniu zmiennych:
   `railway config apply` **i restart serwisów**.
2. **Poprawny dostawca poczty + niedziałająca kolejka = dokładnie ten sam
   skutek co `MAIL_MAILER=log`:** nikt nic nie dostaje i nic tego nie pokazuje.
   Wszystkie listy mają `ShouldQueue`, a wysyła je pętla kolejki w **tym samym
   kontenerze co strona** (serwis chodzi w trybie `all`, cokolwiek mówi
   `.railway/railway.ts`). Dlatego sprawdzenie poczty ma dwa przebiegi:
   ```bash
   railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl
   railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl --kolejka
   ```
   Jeśli po minucie nic nie przyszło: `railway ssh -- php artisan queue:failed`.
3. **Rekordy poczty w Cloudflare muszą być „DNS only", nigdy „Proxied".**
   Proxowanie rozbija weryfikację domeny i **nie daje przy tym żadnego błędu**.

### Czego nie wpisywać do dokumentów dowodowych

Sekretów ani zrzutów całych paneli. Dowód to zdanie „sprawdzone, data, metoda,
wynik", nie wklejony klucz.

### Twarda zasada, dopóki bramka [6] nie przejdzie

Nie wystawiaj produkcyjnego bucketu mediów pod `cdn.kuking.pl`.

---

## Kiedy można otworzyć publiczną betę

Nie „wygląda gotowe". Dopiero gdy pierwsze dwadzieścia trzy wiersze tabeli
stanu są zamknięte, a wiersze 24 i 25 mają wynik. Zewnętrzny audyt z 10.09.2026
(`docs/research/audyt-2026-09-10/`) formułuje to tak samo i dodaje jedno
zdanie, które warto tu powtórzić:

> `NO-GO` nie oznacza, że produkcja jest zepsuta. Oznacza, że nie ma jeszcze
> **dowodu**, żeby bezpiecznie skierować na serwis szeroki ruch osób, których
> zdjęcia, konta i relacje mają być trwałe.

---

## Czego ten dokument świadomie nie robi

Nie powtarza treści dokumentów, na które wskazuje. Gdyby powtarzał, za tydzień
rozjechałby się z nimi i byłby gorszy niż jego brak — to ta sama zasada, którą
`CLAUDE.md` stosuje do siebie.

Nie jest też archiwum. Historia pomyłek jest niżej, w jednej sekcji, a nie
pomiędzy krokami do wykonania — bo operator czytający ten plik w trakcie
awarii ma poznać **stan**, a nie rekonstruować go z ciągu sprostowań. To
zalecenie z audytu 13 i było trafne: poprzednia wersja tego pliku miała pięć
bloków `SPROSTOWANIE` wplecionych między etapy.

---

## Historia sprostowań

Zostaje, bo pokazuje, jakie założenia okazały się fałszywe — i dlatego, że
każde z nich mogło kosztować dane. **Żadne zdanie z tej sekcji nie opisuje
stanu bieżącego.** Stan bieżący jest wyłącznie w tabeli wyżej.

**9.09.2026 po południu — „Railway robi kopie sam".** Etap kopii napisano przy
założeniu, że wystarczy przeklikać dwa przełączniki w panelu. Nieprawda:
Volume Backups i PITR są tylko w planie Pro. Skutek: kopia przestała być
„trzecią warstwą" i została jedyną, a #193 przestało być usprawnieniem
i zostało bramką.

**9.09.2026 wieczorem — „wdrożenie woła `db:seed --force`".** Nieprawda. PR
#174 dopisał `db:seed` do `preDeployCommand` w `.railway/railway.ts`, ale ten
plik nie obowiązuje, bo `railway config apply` nigdy nie zostało uruchomione.
Dwa różne zdarzenia pomylono z sobą: **commit do repozytorium** (nastąpił)
i **zastosowanie konfiguracji w Railway** (nie nastąpiło). Usterka „ostatniego
metra", którą PR #174 miał zamknąć, zamknęła się o metr za wcześnie: decyzja
zapadła, kod powstał, testy są zielone — i nikt nie zastosował konfiguracji.

**9.09.2026 — SMTP.** Na planach Free i Hobby SMTP jest wyłączony, więc poczta
idzie przez API HTTPS. To nie była pomyłka w dokumencie, ale zmieniło etap
poczty na tyle, że warto pamiętać dlaczego.

**10.09.2026 — pamięć workera.** Zewnętrzny audyt oparł alarm o limitach
zdjęć na komentarzu `--memory=384` w `ProcessUploadedImage`. Obowiązuje
**D-064**: limit kontenera to 1024 MB, `PHP_WORKER_MEMORY_LIMIT` = 512M,
zmierzony szczyt RSS dla 50 Mpx ≈ 452 MB. Audytor skorygował to sam przed
oddaniem raportu. Wniosek: limitu 50 Mpx **nie obniżamy arbitralnie**, najpierw
benchmark współbieżności.

**10.09.2026 — „zgłaszający nie dostaje odpowiedzi".** Ten sam audyt postawił
jako P0 tezę, że regulamin §7 obiecuje odpowiedź każdemu zgłaszającemu, a
produkt tego nie robi. Nieprawda od issue #10 — pętla jest zamknięta, także po
zwykłym przycisku „Zgłoś". Audytor przeczytał `MODERATION_PLAYBOOK.md`, który
opisywał stan sprzed #10. Szczegóły i dowody w kodzie:
`docs/research/audyt-2026-09-10/SPRAWDZENIE.md`.

📄 `docs/infra/KOPIE_I_ODTWORZENIE.md` · `docs/infra/POCZTA_URUCHOMIENIE.md` ·
`docs/decyzje/POCZTA.md` · `docs/legal/BRAMKA_BETY.md` ·
`docs/infra/INFRA_DECISION.md` · `docs/infra/BRAMKA_R2.md` ·
`docs/research/audyt-2026-09-10/` · issue #120 ·
`docs/zlecenia/2026-09-09-audyt-a6.md` (A6-06, A6-07)

---

## Uzupełnienie 17.09.2026 — monitoring (wiersze 11, 12 i 18)

Tabela wyżej jest snapshotem z 10.09.2026 i celowo nie jest przepisywana.
Ta sekcja dokłada do niej trzy pomiary z 17.09.2026 i nic poza nimi.

**Wiersz 18 — „Alarm z webhooka naprawdę dochodzi": `NIEZWERYFIKOWANE` →
`NIEZROBIONE — BLOKUJE`.** To nie jest zaostrzenie oceny, tylko zamiana
„nie wiemy" na „wiemy, że nie". Kanał `blad_webhook` włącza jedna zmienna,
a odczyt listy zmiennych usługi `kuking.pl` (production) przez API Railway
pokazuje, że **`LOG_BLAD_WEBHOOK_URL` w niej nie występuje**;
`sealedVariableNames` jest puste, więc brak nazwy znaczy brak zmiennej,
a nie ukrytą wartość. Skutek: dziś nie dzwoni **nic** — ani błąd 500, ani
czujka kopii, ani dwie czujki dołożone w #598 i #599. Mechanizm jest
sprawdzony na prawdziwym lokalnym odbiorniku HTTP; brakuje wyłącznie
czynności właściciela w panelu. Kolejność zamykania:
`docs/infra/MONITORING_BLEDOW.md` §7.3.

**Wiersz 11 — zewnętrzny monitor `/health`: bez zmian, `NIEZROBIONE`.**
Warto natomiast zapisać, dlaczego jego założenie nie wystarczy dziś do
niczego: `/health` odpowiada wprawdzie **HTTP 200** (odczyt z zewnątrz,
17.09 21:10 UTC), ale ze `status: degraded` — i stoi tak **nieprzerwanie od
9 września**, przez cztery nierozliczone zadania w `failed_jobs`. Monitor
pilnujący treści odpowiedzi alarmowałby więc od pierwszej minuty i nauczyłby
się być ignorowany, zanim powstanie. Kolejność jest odwrotna niż wyglądała:
najpierw rozliczenie tych czterech zadań (`php artisan kuking:martwe-zadania`,
**bez zbiorowego `queue:retry`** — żeton resetu hasła wygasa), potem monitor.

**Wiersz 12 — test dymny po wdrożeniu: nadal `NIEZWERYFIKOWANE`.** Nie
sprawdzałem tego wiersza; wdrożenie Alfy 0.57 odbiera drugi model.

Co jest zielone i zmierzone z zewnątrz tego samego dnia: baza, migracje,
media, poczta, Turnstile, Google, Facebook i analityka — wszystkie pola
`checks` w `/health` poza `kolejka`.
