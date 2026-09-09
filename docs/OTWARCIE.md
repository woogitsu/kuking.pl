# Otwarcie serwisu — kolejność, nie instrukcja

**Ten dokument NICZEGO nie tłumaczy.** Tłumaczą to cztery dokumenty niżej,
każdy w swoim zakresie i szczegółowo. Tego brakowało: **kolejności** i tego,
która rzecz blokuje którą.

Wszystkie cztery etapy są **po stronie właściciela**. Repozytorium nie ma
dostępu do paneli Railway, Cloudflare, EmailLabs ani R2 — i nie powinno mieć.
Audyt A6 nazywa to bramkami A6-06 i A6-07 i nie da się ich zamknąć kodem.

---

## Kolejność i dlaczego właśnie taka

```
ETAP 2  R2 (bucket) ───────────┐  ← przesunięty do przodu: zrzut
        (kilka godzin)         │    z etapu 0 nie ma gdzie lądować
                               ▼
ETAP 0  kopia bazy (#193) ─────┐
        automat, nie panel     │  blokuje wszystko, co pisze do produkcji
                               ▼
ETAP 1  poczta ───────────► ETAP 4  treść zalążkowa
        API HTTPS, nie SMTP            (wymaga `railway config apply`,
                                        które nie było uruchomione)

ETAP 3  reszta bramki (A6-06) — równolegle, nic nie blokuje
```

**Kolejność zmieniła się 9 września po południu** i to jest zmiana na gorsze,
nie na lepsze: etap 0 okazał się zależny od etapu 2, bo Railway nie robi kopii
na tym planie. Etap 1 też się zmienił — SMTP jest na Free i Hobby wyłączony,
więc poczta idzie przez API HTTPS.

**Etap 0 jest pierwszy dlatego, że dziś nie ma żadnej kopii bazy** — nie
dlatego, że wdrożenie zaraz coś do niej zapisze.

> ⚠️ **SPROSTOWANIE, 9 września wieczorem.** Ten akapit twierdził, że „od
> PR-a #174 wdrożenie na produkcję woła `db:seed --force`". **Nieprawda.**
> PR #174 dopisał `db:seed` do `preDeployCommand` w **`.railway/railway.ts`**,
> a ten plik nie obowiązuje, bo **`railway config apply` nigdy nie zostało
> uruchomione**. Zmierzone connectorem Railway: produkcyjny
> `preDeployCommand` to dokładnie `php artisan migrate --force
> --no-interaction` i nic więcej.
>
> To były dwa różne zdarzenia pomylone z sobą: commit do repozytorium
> (nastąpił) i zastosowanie konfiguracji w Railway (nie nastąpiło). Skutek
> praktyczny jest odwrotny do opisanego: najbliższe wdrożenie **nie** wpisze
> treści zalążkowej — patrz etap 4.

**Etap 0 zamyka przy okazji bramkę A6-07.** Audyt nie zarzucił braku procedury
— procedura jest napisana. Zarzucił, że **tabela wyniku w
`docs/infra/KOPIE_I_ODTWORZENIE.md` §5 jest pusta**, czyli nikt nie
udowodnił, że ta procedura działa. Jedno ćwiczenie załatwia obie sprawy.

---

## ETAP 0 — kopia i ćwiczenie odtworzenia

> ⚠️ **SPROSTOWANIE, 9 września po południu.** Ten etap napisałem przy
> założeniu, że Railway robi kopie sam i wystarczy przeklikać dwa przełączniki.
> **Nieprawda: Volume Backups i PITR są tylko w planie Pro**, a Kuking jest na
> Free i przechodzi na Hobby. Dziś nie ma **żadnej** kopii bazy.
>
> Etap 0 zaczyna się więc nie od panelu, tylko od **#193** — zautomatyzowanego
> zrzutu poza Railwayem, który przestał być „trzecią warstwą" i jest jedyną.
> Potrzebuje miejsca do lądowania, czyli bucketu z etapu 2; R2 ma darmowy
> pułap 10 GB, więc koszt nie jest tu przeszkodą. Do czasu wykonania #193
> **każda utrata bazy jest bezpowrotna** — patrz D-043.

**Gdzie:** `docs/infra/KOPIE_I_ODTWORZENIE.md` §4 (ćwiczenie), §5 (tabela wyniku).

**Co masz z tego mieć:** świeży zrzut produkcyjnej bazy **i** dowód, że da się
go odtworzyć. Nie sam zrzut — zrzut, którego nikt nigdy nie odtworzył, jest
obietnicą, nie kopią.

**Gdzie zapisujesz dowód:** tabela w §5 tego samego dokumentu. Data, kto,
rozmiar, czas zrzutu, czas odtworzenia (RTO), wiek zrzutu (RPO), co nie
zadziałało. Ostatnia kolumna jest najważniejsza i zwykle nie jest pusta.

> Ćwiczenie **nie obejmuje** odtworzenia zdjęć — to niemożliwe przed etapem 2.
> Kopia bazy nie zastępuje kopii oryginałów zdjęć, których nie da się odtworzyć
> z niczego.

---

## ETAP 1 — poczta (EmailLabs)

**Gdzie:** `docs/infra/POCZTA_URUCHOMIENIE.md` — §1, potem §2A, potem §5.
Decyzja, dlaczego akurat EmailLabs: `docs/decyzje/POCZTA.md`.

**Czas:** ~20 minut klikania plus do godziny na rozejście się DNS.
**Zmian w kodzie: zero.** `.railway/railway.ts` już referencuje właściwe
zmienne — trzeba wpisać ich wartości w panelu.

Kolejność w obrębie etapu:

1. **§1 — sześć rzeczy niezależnych od dostawcy.** Zrób je raz; zmiana
   dostawcy ich nie unieważnia. Najważniejsze dwie: skrzynka
   `kontakt@kuking.pl` musi **realnie odbierać** (inaczej pierwsza odpowiedź
   człowieka odbije się z błędem), a wszystkie rekordy poczty w Cloudflare
   muszą być **„DNS only", nigdy „Proxied"** — proxowanie rozbija weryfikację
   domeny i nie daje przy tym żadnego błędu.
2. **§2A kroki 1–3** — konto EmailLabs, trzy rekordy w Cloudflare, cztery
   zmienne w Railway. Po wpisaniu zmiennych: `railway config apply`
   **i restart serwisów**. Konfiguracja jest zapiekana przy starcie kontenera,
   więc zmienna bez restartu nie działa, a wygląda, jakby działała.
3. **§5 — sprawdzenie.** Pięć kroków, wszystkie potrzebne.

**Pułapka, o której najłatwiej zapomnieć:** wszystkie listy mają `ShouldQueue`,
a wysyła je pętla kolejki — **w tym samym kontenerze co strona**, bo serwis
`kuking.pl` chodzi w trybie `all` (nie ma osobnego serwisu `worker`, cokolwiek
mówi `.railway/railway.ts`). **Poprawny dostawca + niedziałająca kolejka =
dokładnie ten sam skutek co `MAIL_MAILER=log`:** nikt nic nie dostaje i nic
tego nie pokazuje. Dlatego §5 ma dwa przebiegi:

```bash
railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl
railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl --kolejka
```

Drugi sprawdza pętlę kolejki. Jeśli po minucie nic nie przyszło:
`railway ssh -- php artisan queue:failed`.

Potem w doręczonym liście muszą być trzy słowa: `spf=pass`, `dkim=pass`,
`dmarc=pass`. I na koniec cztery polskie skrzynki — wp.pl, o2.pl, interia.pl,
onet.pl — bo żaden benchmark dostarczalności do nich nie istnieje, a Twój
własny test kosztuje popołudnie i zero złotych.

**Co się odblokuje samo:** ekran „Nie pamiętam hasła". Nie ma tam nic do
przełączenia ręcznie — działa, gdy `MAIL_MAILER` przestanie być `log`.

---

## ETAP 2 — R2 (issue #120)

**Gdzie:** issue #120 ma pełną listę kontrolną. Tło: `docs/infra/INFRA_DECISION.md`.

**Najdłuższy etap i jedyny, który wymaga zmiany w kodzie** (zapis bez
`x-amz-acl` — issue opisuje dwie drogi). Resztę robisz w panelu Cloudflare.

**Twarda zasada, dopóki ta bramka nie przejdzie:** nie wystawiaj produkcyjnego
bucketu mediów pod `cdn.kuking.pl`.

Sedno listy z #120 to trzy dowody, których nie da się uzyskać z testów PHP,
bo testy nie widzą panelu Cloudflare:

- wariant zdjęcia pod publicznym adresem → **200**,
- **oryginał** pod każdą publiczną ścieżką → **403 albo 404**,
- ten sam oryginał przez API S3 z serwera → **sukces**.

Plus: `r2.dev` wyłączone na buckecie oryginałów i zero kluczy `incoming/`
w publicznym buckecie.

**Gdzie zapisujesz dowód:** `docs/infra/`, **z datą** — konfiguracja bucketu
może się zmienić bez jednej linijki w tym repozytorium, więc dowód bez daty
nic nie znaczy.

---

## ETAP 3 — reszta bramki A6-06

**Gdzie:** `docs/legal/BRAMKA_BETY.md` — tam jest macierz warunków.

Idzie równolegle, nic nie blokuje. Do zebrania:

- **działający administrator z 2FA**, zdolny rozpatrzyć odwołanie
  (`php artisan kuking:nadaj-role`),
- **reset hasła przećwiczony na własnym koncie** — nie „kod istnieje", tylko
  „dostałem list i ustawiłem hasło" (to wychodzi z etapu 1 §5 krok 5),
- **alarm z webhooka naprawdę dochodzi** do operatora, i to po naprawie A6-01,
  czyli bez danych osobowych w treści,
- **regiony bazy i magazynu** zgodne z tym, co mówi polityka prywatności,
- **umowy powierzenia (DPA)** — uwaga: **brak osobno podpisanego PDF-u nie
  dowodzi braku umowy.** RODO art. 28 dopuszcza formę elektroniczną i włączenie
  do warunków usługi. Sprawdź warunki wiążące konto spółki, nie to, czy
  dostawca ma stronę o DPA.

**Czego nie wpisywać:** sekretów ani zrzutów całych paneli. Dowód to zdanie
„sprawdzone, data, wynik", nie wklejony klucz.

---

## ETAP 4 — treść zalążkowa na produkcji

**Warunek: etap 0 zrobiony.** Nie „kopia gdzieś jest", tylko „mam świeży zrzut
i wiem, że się odtwarza".

> ⚠️ **SPROSTOWANIE, 9 września wieczorem.** Ten etap twierdził, że
> „wdrożenie samo uruchomi `db:seed --force`". **Dziś nie uruchomi.**
> PR #174 dopisał tę komendę do `preDeployCommand` w `.railway/railway.ts`,
> ale zmierzony connectorem produkcyjny `preDeployCommand` to nadal
> **wyłącznie** `php artisan migrate --force --no-interaction`. Brakuje
> ostatniego kroku: **`railway config apply`**.
>
> Usterka „ostatniego metra", którą PR #174 miał zamknąć, zamknęła się więc
> o jeden metr za wcześnie: decyzja zapadła, kod powstał, testy są zielone —
> i nikt nie zastosował konfiguracji.

Kolejność w tym etapie jest zatem taka:

1. **Etap 0 zrobiony** — masz świeży zrzut i wiesz, że się odtwarza.
   `railway config apply` zmienia to, co wdrożenie robi z produkcyjną bazą,
   więc nie uruchamiaj go bez kopii.
2. **`railway config apply`** — dopiero to wpisuje `db:seed --force`
   do `preDeployCommand`. Przejrzyj plan, który CLI pokaże przed
   potwierdzeniem: ten sam plik chce też rozbić jeden serwis `kuking.pl`
   na trzy (`web`, `worker`, `scheduler`), a to jest osobna, większa zmiana,
   której przy okazji seedowania raczej nie chcesz.
3. **Wdrożenie** — następne wdrożenie po `apply` uruchomi migracje i seeder.

**Po wdrożeniu sprawdź na żywej stronie**, że przykładowe konta i przepisy
naprawdę są. Zielony deploy nie jest dowodem, że seeder coś zapisał.

---

## Czego ten dokument świadomie nie robi

Nie powtarza treści czterech dokumentów, na które wskazuje. Gdyby powtarzał,
za tydzień rozjechałby się z nimi i byłby gorszy niż jego brak — to ta sama
zasada, którą `CLAUDE.md` stosuje do siebie.

📄 `docs/infra/KOPIE_I_ODTWORZENIE.md` · `docs/infra/POCZTA_URUCHOMIENIE.md` ·
`docs/decyzje/POCZTA.md` · `docs/legal/BRAMKA_BETY.md` ·
`docs/infra/INFRA_DECISION.md` · issue #120 · `docs/zlecenia/2026-09-09-audyt-a6.md`
(A6-06, A6-07)
