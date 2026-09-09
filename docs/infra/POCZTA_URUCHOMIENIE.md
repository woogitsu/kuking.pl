# Uruchomienie poczty — krok po kroku

Ten dokument jest dla **właściciela**, nie dla administratora poczty. Zakłada, że
nie wiesz, co to SPF, i tłumaczy to po drodze. Zakłada też, że masz dostęp do
panelu Railway i do panelu Cloudflare z domeną `kuking.pl` — i nic więcej.

**Pełne, źródłowane porównanie sześciu dostawców, cen i rezydencji danych jest
w [`docs/decyzje/POCZTA.md`](../decyzje/POCZTA.md).** Ten dokument mówi, co
zrobić **po** wyborze, dla pięciu wariantów: EmailLabs, Brevo, Postmark,
Amazon SES i Resend.

> ### Rekomendacja w skrócie
>
> **Wybierz EmailLabs (§2A).** W trzech zdaniach: to jedyny z pięciu opisanych
> wariantów, z którym podpiszesz umowę powierzenia (DPA) po polsku, na polskim
> prawie, przy danych, które nie opuszczają UE — a przy grupie odbiorców 50+
> zaufanie jest walutą. Darmowy pakiet STARTUP (300 wiadomości na dobę,
> 9 000 na miesiąc, **bez karty płatniczej**) pokrywa pierwszą falę ~20 osób
> z dużym zapasem. I nie wymaga jednej linijki nowego kodu — korzysta
> z gotowego sterownika `smtp`, więc „włączenie jednym wpisem” to dosłownie
> cztery zmienne w Railway.
>
> **Zapasowo, gdyby EmailLabs odmówił rejestracji** nowej spółce (kontrola
> antyfraudowa, spółka bez historii) — **Brevo (§2B)**: ten sam mechanizm
> (`smtp`, też zero kodu), też serwery w UE, też bez karty. Zamiana jednego
> na drugi to podmiana czterech wartości w Railway, nie nowy PR.
>
> Postmark, Amazon SES i Resend (§2C–§2E) zostają opisane niżej — to
> sprawdzone, działające warianty, przydatne, gdyby priorytety się zmieniły
> (Amazon SES jako plan na wypadek eksplozji wolumenu, patrz §6) — ale żaden
> z nich nie jest dzisiejszą rekomendacją.

---

## 0. Stan na dziś — co dokładnie jest zepsute

`config/mail.php` ma `'default' => env('MAIL_MAILER', 'log')`, a runbook
produkcyjny każe ustawić `MAIL_MAILER=log`.

**Sterownik `log` zapisuje wiadomość do pliku i zgłasza sukces.** Dla Laravela
wysyłka „się udała”: rejestracja kończy się zieloną stroną, zadanie w kolejce
kończy się bez błędu, Sentry milczy. Do nikogo nic nie dociera.

Serwis o tym wie i częściowo się broni: przy `log` ekran „Nie pamiętam hasła”
świadomie **nie przyjmuje adresu** i odsyła do skrzynki kontaktowej
(`App\Support\Poczta`). Ale potwierdzenie adresu przy rejestracji i cztery listy
moderacyjne (DSA) wychodzą mimo to — czyli donikąd.

### Co serwis wysyła mailem

| Wiadomość | Klasa | Kiedy | Kolejkowana |
|---|---|---|---|
| Potwierdź swój adres e-mail | `App\Notifications\PotwierdzenieAdresu` | rejestracja i ponowna wysyłka | tak |
| Ustaw nowe hasło | `App\Notifications\UstawienieNowegoHasla` | „Nie pamiętam hasła” | tak |
| Twoje dane są gotowe | `App\Mail\DataExportReady` | koniec pakowania danych (RODO) | w tle, wewnątrz zadania |
| Przyjęliśmy Twoje zgłoszenie | `App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci` | zgłoszenie nielegalnej treści (DSA art. 16 ust. 4) | tak |
| Decyzja w sprawie zgłoszenia | `App\Notifications\DecyzjaWSprawieZgloszenia` | decyzja moderacyjna (DSA art. 16 ust. 5) | tak |
| Dostaliśmy Twoje odwołanie | `App\Notifications\PotwierdzenieOdwolaniaZglaszajacego` | odwołanie zgłaszającego (DSA art. 20) | tak |
| Sprawdziliśmy Twoje odwołanie | `App\Notifications\OdpowiedzNaOdwolanieZglaszajacego` | rozpatrzenie odwołania (DSA art. 20) | tak |

Siedem wiadomości. **Tygodniowego digestu w kodzie nie ma** — mimo że
`docs/decyzje/POCZTA.md` liczy na nim cały wolumen. Do czasu, aż powstanie,
realna wysyłka to kilkadziesiąt listów miesięcznie i **żaden limit dzienny nie
ma znaczenia**.

### Poczta bez workera nie wychodzi

Wszystkie powiadomienia mają `ShouldQueue`, a `QUEUE_CONNECTION=database`. List
nie jest wysyłany w żądaniu — trafia do tabeli `jobs` i czeka na `queue:work`.
Na produkcji robi to **osobny serwis `worker`** (`.railway/railway.ts`,
`PRODUCTION_SPLIT_SERVICES = true`; na staging i preview jest to jeden kontener
w trybie `APP_ROLE=all`).

Wniosek, o którym łatwo zapomnieć: **poprawny dostawca + niedziałający worker =
dokładnie ten sam skutek co `MAIL_MAILER=log`.** Nikt nic nie dostaje i nic tego
nie pokazuje. Dlatego sprawdzenie z §5 ma dwa przebiegi: synchroniczny
i przez kolejkę.

---

## 1. Sześć rzeczy do zrobienia niezależnie od dostawcy

Zrób je raz. Zmiana dostawcy nie unieważnia żadnej z nich.

1. **Adres nadawcy: `kontakt@kuking.pl`.** Ta sama skrzynka, którą pokazujemy
   ludziom (`KUKING_CONTACT_EMAIL`). Żadnego `noreply@` — `docs/brand/BRAND_EXTENDED.md`
   tego zabrania, bo odpowiedź na list to dla osoby 60+ najbardziej naturalna
   reakcja. Pilnuje tego test `NadawcaPocztyNieJestNoreplyTest`.
2. **Ta skrzynka musi realnie odbierać.** Jeśli `kontakt@kuking.pl` nie istnieje,
   załóż ją, zanim wyślesz pierwszy list — inaczej pierwsza odpowiedź od
   użytkownika odbije się z błędem.
3. **Osobna subdomena wysyłkowa** (`poczta.kuking.pl` albo `send.kuking.pl`).
   Nigdy nie wysyłaj transakcyjnych prosto z gołego `kuking.pl`: awaria
   reputacji zabija wtedy także pocztę firmową. Dostawcy z §2 zakładają ją sami
   — trzeba tylko dodać ich rekordy.
4. **DMARC od pierwszego dnia, startowo `p=none`.** WP.pl mówi wprost: bez
   polityki DMARC wasze maile lądują w spamie.
5. **Wszystkie rekordy poczty w Cloudflare muszą być „DNS only” (szara
   chmurka), nigdy „Proxied”.** Cloudflare nie proxuje poczty; proxowanie
   rozbija weryfikację domeny i nie daje przy tym żadnego błędu.
6. **Sprawdź, że worker chodzi**, zanim uznasz pocztę za działającą (§5).

---

## 2. Warianty dostawców

Wspólne dla wszystkich pięciu: po zmianie zmiennych **zrestartuj serwisy**.
Konfiguracja jest zapiekana przy starcie kontenera (`php artisan optimize`
w `docker/entrypoint.sh`), więc zmienna zmieniona bez restartu nie działa,
a wygląda, jakby działała. Przy EmailLabs i Brevo restart dotyczy tylko
wartości Shared Variables (§2A, §2B) — plik `.railway/railway.ts` się nie
zmienia.

### 2A. EmailLabs — rekomendowany

**Czas: ~20 minut pracy + do godziny na rozejście się DNS. Zero zmian w kodzie
i zero zmian w `.railway/railway.ts`** — sterownik `smtp` jest tam już
ustawiony domyślnie (`MAIL_MAILER: "smtp"`), a `MAIL_SCHEME: "smtp"` też jest
wpisany na stałe. Zostają cztery wartości do wpisania w Railway.

#### Krok 1 — konto i domena

1. → [panel.emaillabs.net.pl/pl/register](https://panel.emaillabs.net.pl/pl/register)
   → nowe konto. **Rejestracja jest darmowa i nie wymaga karty płatniczej** —
   każde nowe konto startuje na darmowym pakiecie STARTUP (300 wiadomości/dobę,
   9 000/miesiąc) `[sprawdzone 2026-09-08 — emaillabs.io/cennik-v2,
   docs.emaillabs.io/faq/konto]`.
2. W panelu: **Domeny** → dodaj `kuking.pl` (albo dedykowaną subdomenę
   wysyłkową, np. `poczta.kuking.pl` — zalecane, patrz §1 pkt 3) →
   **Autoryzacja domeny From**.
3. W sekcji **SMTP** panelu utwórz login i hasło do wysyłki — to są wartości
   do `MAIL_USERNAME` i `MAIL_PASSWORD` w kroku 3.

#### Krok 2 — rekordy w Cloudflare (DNS → Records, wszystkie „DNS only”)

| Typ | Nazwa | Wartość | Po co |
|---|---|---|---|
| TXT | `@` (albo nazwa subdomeny wysyłkowej) | `v=spf1 include:_spf.emaillabs.net.pl ~all` | SPF — zgoda na wysyłkę w Twoim imieniu |
| CNAME | `emaillabs._domainkey` | `emaillabs._domainkey.emaillabs.net.pl` | DKIM — podpis wiadomości (selektor `emaillabs` jest stały u tego dostawcy, nie losowy per konto) |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:kontakt@kuking.pl` | DMARC — polityka i raporty |

`[sprawdzone 2026-09-08 — docs.emaillabs.io: wartości SPF i selektora DKIM są
udokumentowane i stałe; panel może dodatkowo pokazać wpis weryfikacyjny przy
konkretnej domenie — dodaj go, jeśli się pojawi]`

> Jeśli `kuking.pl` ma już rekord SPF, **nie dodawaj drugiego** — dopisz
> `include:_spf.emaillabs.net.pl` do istniejącego (wyjaśnienie w §3).

#### Krok 3 — zmienne w Railway (Shared Variables, środowisko `production`)

**`.railway/railway.ts` się nie zmienia** — plik już referencuje te cztery
zmienne jako `ctx.shared.MAIL_HOST` i analogicznie. Trzeba tylko wpisać ich
WARTOŚCI raz, w panelu Railway (Environment → Variables → Shared Variables):

| Zmienna | Wartość | Sekret? |
|---|---|---|
| `MAIL_HOST` | `smtp.emaillabs.net.pl` | nie |
| `MAIL_PORT` | `587` | nie |
| `MAIL_USERNAME` | login SMTP z panelu (krok 1.3) | nie |
| `MAIL_PASSWORD` | hasło SMTP z panelu (krok 1.3) | **TAK** |

`MAIL_SCHEME` zostaje `smtp` — to już jest wpisane na stałe w
`.railway/railway.ts` dla portu 587 (STARTTLS), nie trzeba go dodawać.
Po wpisaniu wartości: `railway config apply`, potem restart serwisów.

#### Krok 4 — sprawdzenie i uwaga o rozliczeniach

Status domeny w panelu musi być zweryfikowany. Potem §5 tego dokumentu.

> **Do konta EmailLabs nie da się dziś podpiąć karty płatniczej.** Po
> przekroczeniu darmowego pakietu dostawca wysyła fakturę mailem, płatną
> przelewem `[sprawdzone 2026-09-08, docs.emaillabs.io/faq/konto]`. Przy
> pierwszej fali ~20 osób (`docs/decyzje/POCZTA.md` §0) limit 300/dobę nie
> zostanie nawet zbliżony — ale warto wiedzieć, że przekroczenie kończy się
> fakturą do opłacenia przelewem, nie automatycznym obciążeniem karty.

---

### 2B. Brevo — zapasowy, gdyby EmailLabs odmówił rejestracji

**Czas: ~20 minut. Też sterownik `smtp`, też zero zmian w kodzie** — zamiana
z EmailLabs na Brevo to podmiana czterech wartości w Railway, nic więcej.

#### Krok 1 — konto i domena

1. → [app.brevo.com/account/register](https://app.brevo.com/account/register)
   → nowe konto. **Darmowy plan (300 wiadomości/dobę, bezterminowo) nie
   wymaga karty płatniczej** `[sprawdzone 2026-09-08, brevo.com/pricing]`.
2. **Senders & IP** → **Domains** → dodaj `kuking.pl` (albo subdomenę
   wysyłkową) → panel pokaże komplet rekordów do wklejenia.
3. **SMTP & API** → **SMTP** → tam są login i klucz SMTP (login to zwykle
   adres e-mail rejestracyjny, hasło to osobny „SMTP key”, **nie** hasło do
   panelu).

#### Krok 2 — rekordy w Cloudflare (wszystkie „DNS only”)

| Typ | Nazwa | Wartość | Po co |
|---|---|---|---|
| TXT | `@` (albo subdomena wysyłkowa) | `v=spf1 include:spf.brevo.com ~all` | SPF |
| CNAME | `brevo1._domainkey` | z panelu | DKIM (1 z 2) |
| CNAME | `brevo2._domainkey` | z panelu | DKIM (2 z 2) |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:kontakt@kuking.pl` | DMARC |

`[do weryfikacji w panelu — Brevo generuje dwa CNAME DKIM plus TXT
weryfikacyjny przy dodaniu domeny; dokładne wartości pokazuje panel po
kroku 1.2, sprawdzone 2026-09-08 co do KSZTAŁTU rekordów, nie ich treści]`

#### Krok 3 — zmienne w Railway

| Zmienna | Wartość | Sekret? |
|---|---|---|
| `MAIL_HOST` | `smtp-relay.brevo.com` | nie |
| `MAIL_PORT` | `587` | nie |
| `MAIL_USERNAME` | login SMTP z panelu | nie |
| `MAIL_PASSWORD` | SMTP key z panelu | **TAK** |

Jak wyżej: `.railway/railway.ts` się nie zmienia, `MAIL_SCHEME=smtp` jest już
ustawiony. `railway config apply`, potem restart.

#### Krok 4 — sprawdzenie

§5 tego dokumentu. Brevo (spółka francuska, infrastruktura we Francji,
Niemczech i GCP Belgia) ma DPA opublikowane w regulaminie — przy spółce z UE
nie są potrzebne dodatkowe SCC.

---

### 2C. Postmark

**Czas: ~15 minut pracy + do godziny na rozejście się DNS.**

#### Krok 1 — konto i domena

1. → postmarkapp.com → nowe konto → **Sender Signatures** → **Add Domain**
   → `kuking.pl`.
2. Postmark pokaże komplet rekordów DNS. **Nie przepisuj wartości z tego
   dokumentu — przepisz je z panelu.** Poniżej jest kształt, nie treść.

#### Krok 2 — rekordy w Cloudflare (DNS → Records, wszystkie „DNS only”)

| Typ | Nazwa | Wartość | Po co |
|---|---|---|---|
| TXT | `<selektor>._domainkey` | klucz publiczny z panelu Postmarka | DKIM — podpis wiadomości |
| CNAME | `pm-bounces` | `pm.mtasv.net` | własny Return-Path (adres odbić) |
| TXT | `@` | `v=spf1 include:spf.mtasv.net ~all` | SPF — zgoda na wysyłkę w Twoim imieniu |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:kontakt@kuking.pl` | DMARC — polityka i raporty |

`[do weryfikacji w panelu — Postmark nadaje własny selektor DKIM i może
poprosić o inny host dla Return-Path]`

> Jeśli `kuking.pl` ma już rekord SPF, **nie dodawaj drugiego**. Domena może
> mieć tylko jeden rekord SPF; dwa oznaczają `permerror` i pogorszenie, a nie
> poprawę. Dopisz `include:spf.mtasv.net` do istniejącego.

#### Krok 3 — kod

Postmark potrzebuje paczki, której w repozytorium nie ma:

```bash
composer require symfony/postmark-mailer
```

To zmienia `composer.json` i `composer.lock`, więc **wymaga wdrożenia (nowy
obraz), nie samego restartu**.

#### Krok 4 — zmienne w Railway

W `.railway/railway.ts`, blok „Poczta transakcyjna”, zamień `MAIL_MAILER: "smtp"`
na `MAIL_MAILER: "postmark"` i usuń cztery zmienne SMTP (`MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`). Potem `railway config apply`.

| Zmienna | Wartość | Sekret? |
|---|---|---|
| `MAIL_MAILER` | `postmark` | nie |
| `POSTMARK_API_KEY` | **Server API Token** z panelu (nie Account Token) | **TAK** |
| `MAIL_FROM_ADDRESS` | `kontakt@kuking.pl` | nie |
| `MAIL_FROM_NAME` | **nie ustawiaj** — patrz uwaga pod §5 | nie |

#### Krok 5 — sprawdzenie

W panelu Postmarka domena musi mieć status **Verified**. Potem §5 tego dokumentu.

> **Strumienie wiadomości.** Postmark ma natywne „message streams” (osobny
> transakcyjny i osobny masowy) — to jest jego największa przewaga, gdy powstanie
> digest. `config/mail.php` ma na to gotowe, ale **zakomentowane** miejsce
> (`message_stream_id`). Odkomentowanie tej linii i dodanie
> `POSTMARK_MESSAGE_STREAM_ID` to osobna, jednolinijkowa zmiana — nie jest
> potrzebna, dopóki wysyłamy tylko transakcyjne.

---

### 2D. Amazon SES (region `eu-central-1`, Frankfurt)

**Czas: ~1 godzina pracy + 1–3 dni na wyjście z sandboksa + około pół dnia na
obsługę odbić.** To jest jedyny wariant, którego nie da się skończyć jednego
popołudnia. To też jedyny z pięciu opisanych tu wariantów, który **wymaga
karty płatniczej już przy zakładaniu konta** — AWS żąda ważnej karty przy
tworzeniu konta root, niezależnie od tego, czy wysyłka zmieści się w darmowym
limicie `[sprawdzone 2026-09-08, aws.amazon.com/free/registration-faqs]`.

#### Krok 1 — konto, region, domena

1. Konsola AWS → **przełącz region na `eu-central-1` (Frankfurt)**. To jest
   pierwsza rzecz i najłatwiejsza do przeoczenia: SES jest usługą regionalną,
   a domena zweryfikowana w innym regionie nie liczy się tutaj.
2. **SES → Verified identities → Create identity → Domain** → `kuking.pl`,
   z włączonym **Easy DKIM** (2048 bit).
3. Włącz **Custom MAIL FROM domain** i podaj `poczta.kuking.pl`.
   To nie jest ozdoba: domyślnie SES używa koperty `amazonses.com`, przez co
   SPF przechodzi dla domeny Amazona, a nie dla Twojej — i **DMARC nie jest
   spełniony**, mimo że każdy pojedynczy rekord wygląda dobrze.

#### Krok 2 — rekordy w Cloudflare (wszystkie „DNS only”)

| Typ | Nazwa | Wartość | Po co |
|---|---|---|---|
| CNAME | `<token1>._domainkey` | `<token1>.dkim.amazonses.com` | DKIM (1 z 3) |
| CNAME | `<token2>._domainkey` | `<token2>.dkim.amazonses.com` | DKIM (2 z 3) |
| CNAME | `<token3>._domainkey` | `<token3>.dkim.amazonses.com` | DKIM (3 z 3) |
| MX | `poczta` | `10 feedback-smtp.eu-central-1.amazonses.com` | odbiór odbić dla własnej koperty |
| TXT | `poczta` | `v=spf1 include:amazonses.com ~all` | SPF dla własnej koperty |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:kontakt@kuking.pl` | DMARC |

`[do weryfikacji w panelu — trzy tokeny DKIM generuje AWS przy tworzeniu
tożsamości]`

#### Krok 3 — wyjście z sandboksa

Nowe konto SES jest w **sandboksie**: 200 wiadomości na dobę, 1 na sekundę
i **tylko na adresy, które sam wcześniej zweryfikujesz**. Wysyłka do
przypadkowego użytkownika po prostu nie przejdzie.

Wyjście: **SES → Account dashboard → Request production access**. Wniosek trzeba
opisać: co wysyłasz (poczta transakcyjna serwisu społecznościowego), jak
obsługujesz odbicia i skargi, jak ludzie się wypisują. Odpowiedź zwykle w 24 h,
bywa dłużej.

#### Krok 4 — obsługa odbić i skarg **jest obowiązkowa**

To nie jest formalność ani „dobra praktyka”: SES **zawiesza konta**, na których
wskaźnik odbić przekroczy próg. U pozostałych dostawców robi to za Ciebie
webhook i panel; tutaj piszesz to sam.

Minimum: **SNS → temat → subskrypcja HTTPS na trasę w aplikacji**, która twardo
odbijające adresy oznacza jako niewysyłalne. Tej trasy w Kuking **jeszcze nie
ma** — to osobna robota do policzenia przed wyborem SES.

#### Krok 5 — kod

`aws/aws-sdk-php` jest już w projekcie (przyszedł z obsługą R2), więc **żadna
paczka nie jest potrzebna**. To realna przewaga SES w tym repozytorium.

#### Krok 6 — zmienne w Railway

> **Pułapka, która kosztuje wieczór.** W Kuking `AWS_ACCESS_KEY_ID`,
> `AWS_SECRET_ACCESS_KEY` i `AWS_DEFAULT_REGION` należą do **Cloudflare R2**
> (zdjęcia), a region jest ustawiony literalnie na `auto`. Gdyby SES czytał te
> same zmienne, próbowałby zalogować się do Amazona kluczem Cloudflare
> w regionie, którego Amazon nie ma. Dlatego `config/services.php` daje poczcie
> **własne** nazwy i dopiero potem sięga po `AWS_*`.

| Zmienna | Wartość | Sekret? |
|---|---|---|
| `MAIL_MAILER` | `ses` | nie |
| `MAIL_SES_KEY` | Access Key ID użytkownika IAM z prawem `ses:SendRawEmail` | **TAK** |
| `MAIL_SES_SECRET` | Secret Access Key tego użytkownika | **TAK** |
| `MAIL_SES_REGION` | `eu-central-1` | nie |
| `MAIL_FROM_ADDRESS` | `kontakt@kuking.pl` | nie |
| `MAIL_FROM_NAME` | **nie ustawiaj** — patrz uwaga pod §5 | nie |

Osobny użytkownik IAM **wyłącznie do wysyłki**, nie klucz konta głównego
i nie ten sam, którym chodzą zdjęcia.

---

### 2E. Resend

**Czas: ~15 minut pracy + do godziny na DNS.**

#### Krok 1 — konto i domena

1. → resend.com → **Domains** → **Add Domain** → `kuking.pl`,
   **region: `eu-west-1` (Irlandia)**.
2. Resend pokaże komplet rekordów.

> **Region w Resend dotyczy tylko wysyłki.** Dokumentacja Resend mówi wprost, że
> dane konta — adresy odbiorców, tematy wiadomości, logi doręczeń — leżą
> **w USA niezależnie od wybranego regionu** i nie ma ustawienia, które to
> zmieni. To nie jest przeszkoda nie do przejścia (Resend ma DPA i certyfikację
> EU-US DPF), ale jest to zobowiązanie prawne: ocena transferu (TIA), wpis
> w rejestrze czynności i akapit w polityce prywatności. Szczegóły:
> `docs/decyzje/POCZTA.md` §2.

#### Krok 2 — rekordy w Cloudflare (wszystkie „DNS only”)

| Typ | Nazwa | Wartość | Po co |
|---|---|---|---|
| TXT | `resend._domainkey` | klucz publiczny z panelu | DKIM |
| MX | `send` | `10 feedback-smtp.eu-west-1.amazonses.com` | odbiór odbić (Resend stoi na SES) |
| TXT | `send` | `v=spf1 include:amazonses.com ~all` | SPF dla subdomeny wysyłkowej |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:kontakt@kuking.pl` | DMARC |

`[do weryfikacji w panelu — Resend podaje własny selektor i host MX zależny od
wybranego regionu]`

#### Krok 3 — kod

```bash
composer require resend/resend-php
```

Wymaga wdrożenia (nowy obraz), nie samego restartu.

#### Krok 4 — zmienne w Railway

| Zmienna | Wartość | Sekret? |
|---|---|---|
| `MAIL_MAILER` | `resend` | nie |
| `RESEND_API_KEY` | klucz z panelu, uprawnienie **Sending access** | **TAK** |
| `MAIL_FROM_ADDRESS` | `kontakt@kuking.pl` | nie |
| `MAIL_FROM_NAME` | **nie ustawiaj** — patrz uwaga pod §5 | nie |

#### Krok 5 — uwaga o limicie

Darmowy plan Resend to 3 000 wiadomości miesięcznie, ale **maksymalnie 100 na
dobę**. Dla dzisiejszej wysyłki (kilkadziesiąt listów miesięcznie) to z ogromnym
zapasem. Dla przyszłego digestu — nie, bo digest to burst: cały tydzień idzie
w jedno przedpołudnie.

---

## 3. SPF, DKIM i DMARC po ludzku

Trzy rekordy, trzy różne pytania. Filtry pocztowe zadają je po kolei.

**SPF — „kto ma prawo wysyłać w moim imieniu”.**
Wpis w DNS z listą serwerów, którym na to pozwalasz. Serwer odbiorcy patrzy,
z jakiego adresu IP przyszedł list, i sprawdza, czy jest na tej liście.
Domena może mieć **dokładnie jeden** rekord SPF — dwa dają błąd i skutek
odwrotny do zamierzonego. `~all` na końcu znaczy „reszta jest podejrzana”;
`-all` znaczy „reszta na pewno nie jest ode mnie” i zostawia się to na koniec,
po kilku tygodniach czystych raportów.

**DKIM — „ten list naprawdę wyszedł ode mnie i nikt go po drodze nie zmienił”.**
Dostawca podpisuje każdą wiadomość kluczem prywatnym, a klucz publiczny leży
w DNS pod selektorem (`cośtam._domainkey.kuking.pl`). WP.pl wymaga podpisu DKIM
na każdej wiadomości. Selektor nadaje dostawca — dlatego w tabelach wyżej jest
`<selektor>`, a nie konkretna wartość.

**DMARC — „co zrobić, gdy SPF albo DKIM nie wyjdzie”.**
Jeden rekord TXT pod `_dmarc.kuking.pl` z polityką i adresem na raporty.
WP.pl pisze wprost: *„Jeśli nie ustawicie polisy DMARC w domenie wysyłkowej,
wasze maile na serwerach WP będą trafiały do spamu.”*

Kolejność, w jakiej się to zaostrza — i nie należy jej przyspieszać:

| Etap | Polityka | Kiedy |
|---|---|---|
| start | `p=none` | od pierwszego dnia, razem z `rua=` |
| po 2–4 tygodniach czystych raportów | `p=quarantine` | gdy w raportach nie ma obcej wysyłki |
| po kolejnych kilku tygodniach | `p=reject` | dopiero gdy masz pewność, że nic legalnego nie odpada |

`p=reject` ustawiony za wcześnie **kasuje** listy, które gdzieś po drodze
przeszły przez zapomniany system (formularz na stronie, newsletter, hosting).
Raporty `rua` są po to, żeby te systemy najpierw znaleźć.

**Czego żaden z tych rekordów nie załatwia:** treści i zachowania. Digest do
grupy 50+ z małą liczbą otwarć i częstym „to nie ja się zapisałam” zniszczy
reputację u każdego dostawcy, przy komplecie zielonych rekordów.

---

## 4. Co zmienić w repozytorium (ściągawka)

| Plik | Zmiana | Warianty |
|---|---|---|
| — | **żadna** — `MAIL_MAILER: "smtp"` i `MAIL_SCHEME: "smtp"` są już w `.railway/railway.ts`; wystarczą cztery Shared Variables w panelu Railway | **EmailLabs, Brevo** (rekomendowane) |
| `.railway/railway.ts` | `MAIL_MAILER` na `postmark` / `ses` / `resend`; usunąć cztery zmienne SMTP | Postmark, SES, Resend |
| `composer.json` | `symfony/postmark-mailer` | Postmark |
| `composer.json` | `resend/resend-php` | Resend |
| `composer.json` | — (`aws/aws-sdk-php` już jest) | SES |
| `config/mail.php` | odkomentować `message_stream_id`, jeśli chcesz rozdzielić strumienie | Postmark, opcjonalnie |
| `docs/DECISIONS.md` | wpis o wybranym dostawcy | wszystkie pięć |
| `resources/legal/polityka-prywatnosci.md` | akapit o transferze danych poza EOG | **Postmark i Resend** (nie EmailLabs, Brevo ani SES `eu-central-1`) |

Ostatni wiersz nie jest formalnością: przy dostawcy z USA to jest wymóg,
a nie ozdoba. Przy dostawcy z UE tego akapitu po prostu nie ma.

---

## 5. Sprawdzenie, że poczta naprawdę wychodzi

### Krok 1 — jedna prawdziwa wiadomość

```bash
railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl
```

Komenda wypisuje sterownik, nadawcę, stan kolejki i to, czy wysyłka poszła
synchronicznie czy przez kolejkę. **Przy sterowniku `log` albo `array` kończy
się porażką i nic nie wysyła** — bo zielony wynik przy sterowniku, który nic nie
dostarcza, jest gorszy niż brak sprawdzenia. Przy błędzie mówi, co zrobić.

Brak błędu znaczy tylko tyle, że **dostawca przyjął wiadomość**. O doręczeniu
mówi panel dostawcy i sama skrzynka.

### Krok 2 — ta sama droga przez kolejkę

```bash
railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl --kolejka
```

To sprawdza drugą połowę układu: czy serwis `worker` w Railway w ogóle chodzi.
Jeśli po minucie nic nie przyszło:

```bash
railway ssh -- php artisan queue:failed
```

### Krok 3 — nagłówki doręczonego listu

W doręczonej wiadomości (Gmail: „Pokaż oryginał”; WP i o2: „Więcej” → „Pokaż
szczegóły”) muszą być trzy słowa:

```text
spf=pass   dkim=pass   dmarc=pass
```

Jedno `fail` albo `none` znaczy, że któryś rekord z §2 jest zły albo jeszcze się
nie rozszedł.

### Krok 4 — cztery polskie skrzynki

Powtórz krok 1 na **wp.pl, o2.pl, interia.pl i onet.pl**. Nie istnieje żaden
niezależny benchmark dostarczalności do polskich skrzynek — wszystkie liczby
„99% dostarczalności” to marketing dostawcy. **Jedyne wiarygodne dane to Twój
własny test**, a kosztuje jedno popołudnie i zero złotych. Sprawdzaj, do którego
folderu wpadł list, nie tylko czy wpadł.

### Krok 5 — prawdziwa droga

Na koniec przejdź ścieżkę użytkownika, nie komendy:

1. załóż konto testowe → ma przyjść „Potwierdź swój adres e-mail”;
2. wyloguj się i użyj „Nie pamiętam hasła” → ma przyjść „Ustaw nowe hasło”;
3. kliknij link i ustaw hasło → ma się dać zalogować.

Dopiero to jest dowód. Ekran „Nie pamiętam hasła” **sam się odblokuje**, gdy
`MAIL_MAILER` przestanie być `log` — nie ma tam nic do przełączenia ręcznie.

### `MAIL_FROM_NAME` zostaw NIEUSTAWIONE

Tabele wariantów wyżej mówią „nie ustawiaj" i to nie jest przeoczenie.

`config/mail.php` składa nazwę nadawcy z konfiguracji:

```php
'name' => env('MAIL_FROM_NAME', config('kuking.community.host_name').' z Kuking'),
```

Listy z Kuking podpisuje **gospodarz imieniem**, nie sama marka — to decyzja
produktowa (`docs/brand/COPY_STYLE.md` §6, `docs/product/RETENTION_LOOPS.md`
§4), pilnowana testem
`ListyZSystemuPoPolskuTest::test_nadawca_podpisuje_sie_imieniem_gospodarza`.
Ustawienie tej zmiennej na `Kuking` w Railway **cicho ją odwraca**: kod
i testy zostają zielone, bo żaden test nie widzi zmiennych z Railway, a do
ludzi zaczynają chodzić listy od „Kuking" zamiast od „Ula z Kuking".

Zmiana gospodarza to zmiana `kuking.community.host_name` w konfiguracji,
a nie zmiennej środowiskowej.

**Jeśli `MAIL_FROM_NAME` już stoi w Railway — usuń ją**, zamiast poprawiać
jej wartość. Wartość wpisana ręcznie znowu się rozjedzie przy następnej
zmianie gospodarza; brak zmiennej nie rozjedzie się nigdy.

---

## 6. Rekomendacja

Kontekst: dziś zero użytkowników, docelowo pierwsza fala ~20 osób,
kilkadziesiąt listów transakcyjnych miesięcznie, jedna osoba utrzymująca
całość, grupa odbiorców 50+ w polskich skrzynkach.

**Wybierz EmailLabs.** W trzech zdaniach: to jedyny z pięciu opisanych
wariantów, przy którym umowa powierzenia jest po polsku, na polskim prawie,
a dane nie opuszczają UE — przy grupie 50+, gdzie zaufanie jest walutą, to
waży więcej niż różnica w cenie. Darmowy pakiet STARTUP (300 wiadomości/dobę,
9 000/miesiąc, **bez karty płatniczej**) pokrywa pierwszą falę ~20 osób
z dużym zapasem, a przy wzroście Essential (99–129 zł/mies. do 100 tys.) nadal
jest tańszy albo porównywalny z resztą listy. I korzysta z gotowego sterownika
`smtp` — zero nowego kodu, zero nowej paczki Composera, tylko cztery Shared
Variables w Railway (§2A, §4).

**Zapasowo, gdyby EmailLabs odmówił rejestracji** nowej spółce (kontrola
antyfraudowa, brak historii NIP-u) — **Brevo**: ten sam mechanizm (`smtp`,
zero kodu), też serwery w UE (Francja, Niemcy, GCP Belgia), też bez karty.
Zamiana jednego na drugi to podmiana czterech wartości w Railway (§2B), nie
nowy Pull Request.

Pełne, źródłowane porównanie sześciu dostawców — w tym dlaczego Postmark
i Resend odpadają nie z powodu ceny, tylko rezydencji danych w USA — jest
w [`docs/decyzje/POCZTA.md`](../decyzje/POCZTA.md).

### Gdyby jednak priorytety były inne

Poniższe trzy warianty (Postmark, Amazon SES, Resend) zostają opisane
w §2C–§2E, bo o nie proszono i bo mogą się przydać w innym kontekście —
nie dlatego, że unieważniają rekomendację wyżej.

**Gdyby rezydencja danych w UE przestała być wymogiem** (np. po ocenie
transferu — TIA — uznanej za akceptowalną), z tej trójki wybrałbym Postmark:

1. **Kosztuje 15 minut, a nie trzy dni.** Przy jednej osobie czas jest droższy
   niż pieniądze. SES żąda wniosku o wyjście z sandboksa, własnego kodu do
   obsługi odbić przez SNS i budowania reputacji od zera — to kilka dni pracy
   i stały dług utrzymaniowy, za oszczędność rzędu **15 dolarów miesięcznie**.
   Przy naszym wolumenie ta oszczędność jest nieistotna.
2. **Historycznie najlepsza reputacja transakcyjna** i osobny strumień
   transakcyjny — dokładnie to, czego potrzebuje serwis, w którym list z linkiem
   do hasła jest jedyną drogą powrotu na konto.
3. **Wbudowany sterownik Laravela** i jedna paczka Composera.
4. Darmowe 100 wiadomości miesięcznie pokrywa alfę bez płacenia czegokolwiek;
   Basic to 15 USD, gdy przestanie starczać.

Resend odpada w tym scenariuszu nie z powodu jakości, tylko limitu 100/dobę
na darmowym planie (który przy pierwszym digescie odpadnie) i dlatego, że przy
tej samej robocie prawnej co Postmark daje słabszy zestaw narzędzi
transakcyjnych.

**Amazon SES (`eu-central-1`) trzymaj jako plan awaryjny na wypadek eksplozji
wolumenu** — nie jako zamiennik EmailLabs na dziś, tylko jako trzecią linię,
gdyby wolumen (np. po uruchomieniu tygodniowego digestu) przekroczył to, co
udźwignie Essential EmailLabs. SES jest jedynym z całej piątki, który potrafi
trzymać dane w UE i jednocześnie skaluje się bez górnego limitu ceny — ale
kupuje się go kilkoma dniami pracy, kartą płatniczą wymaganą już przy
zakładaniu konta AWS i obowiązkiem, którego nie ma nigdzie indziej: własną
obsługą odbić i skarg, pod groźbą zawieszenia konta.

---

## 7. Na co uważać

**Limit dzienny, nie tylko miesięczny.** EmailLabs STARTUP to 300
wiadomości na dobę **i** 9 000 na miesiąc — oba limity obowiązują naraz.
Przy dzisiejszej wysyłce (siedem typów listów z §0) i pierwszej fali ~20 osób
limit dobowy nie zostanie nawet zbliżony. To się zmieni, gdy powstanie
tygodniowy digest (`users.wants_weekly_digest` — funkcja jeszcze nie
istnieje w kodzie, patrz `docs/decyzje/POCZTA.md` §0): digest to zawsze
burst, cały tydzień wychodzi w jedno przedpołudnie, i wtedy liczy się limit
DOBOWY, nie miesięczny.

**Co się dzieje po przekroczeniu darmowego pułapu.** EmailLabs: do konta nie
da się podpiąć karty, więc po przekroczeniu limitu dostawca wysyła fakturę
mailem, płatną przelewem `[do weryfikacji — dokładny mechanizm w trakcie
okresu rozliczeniowego: czy wysyłka jest wstrzymywana do zapłaty, czy tylko
naliczana na kolejną fakturę]`. Brevo: plan darmowy jest twardo ograniczony do
300 wiadomości na dobę — po przekroczeniu kolejne czekają do północy albo
trzeba przejść na płatny plan (od ok. 9 USD/mies. za 5 000 wiadomości).
Amazon SES nie ma darmowego pułapu do „przekroczenia” — płaci się od
pierwszej wiadomości (~0,10 USD za 1000), za to nowe konto w sandboksie ma
twardy limit 200/dobę i wysyła wyłącznie na zweryfikowane adresy, dopóki nie
zatwierdzą wniosku o production access.

**Ryzyko utraty reputacji domeny.** Reputacja adresu IP i domeny wysyłkowej
buduje się tygodniami i można ją stracić w jeden dzień: nagły skok wolumenu
(pierwszy digest wysłany od razu do wszystkich), wysoki wskaźnik odbić
(nieaktualne albo błędnie wpisane adresy) albo duża liczba zgłoszeń „to spam”
psują dostarczalność u WSZYSTKICH odbiorców na danej domenie, nie tylko
u tych, którzy kliknęli. Dlatego: DMARC startuje od `p=none` (§3), raporty
`rua` obserwuje się 2–4 tygodnie przed zaostrzeniem polityki, a digest —
kiedy powstanie — powinien iść w kolejce rozłożonej na godziny, nie w jednej
minucie do wszystkich naraz.

**Brak niezależnego benchmarku polskiej dostarczalności.** Żadna liczba typu
„99% dostarczalności” żadnego dostawcy — łącznie z twierdzeniami EmailLabs
o „najwyższej dostarczalności w Polsce” — nie jest zweryfikowanym pomiarem
niezależnej strony trzeciej, to twierdzenie sprzedażowe dostawcy. Jedyny
wiarygodny test to własny, opisany w §5 krok 4 (wp.pl, o2.pl, interia.pl,
onet.pl).

---

## 8. Czego ten dokument nie załatwi

Rzeczy, które i tak trzeba zrobić ręcznie i których nie da się przygotować
z wyprzedzeniem:

- **założenie konta u dostawcy** — przy EmailLabs i Brevo bez karty, tylko
  adres e-mail; przy Amazon SES z kartą płatniczą (wymaga jej AWS);
- **założenie skrzynki `kontakt@kuking.pl`**, jeśli jeszcze nie istnieje —
  i sprawdzenie, że ktoś ją czyta;
- **wpisanie rekordów DNS w Cloudflare** — z panelu dostawcy, nie z tego pliku;
- **wniosek o production access w AWS**, jeśli padnie na SES;
- **podpisanie umowy powierzenia (DPA)** z dostawcą — przy EmailLabs i Brevo
  to gotowy wzór w regulaminie, przy Postmark i Resend wymaga też oceny
  transferu (TIA), bo dane trafiają do USA;
- **dopisanie akapitu o transferze poza EOG** do polityki prywatności, jeśli
  padnie na Postmark albo Resend;
- **własny test na czterech polskich skrzynkach** — jedyne dane o polskiej
  dostarczalności, którym można wierzyć;
- **wpis w `docs/DECISIONS.md`**, żeby następna osoba (albo następny model) nie
  otwierał tej dyskusji od nowa.

---

## Referencje

- [`docs/decyzje/POCZTA.md`](../decyzje/POCZTA.md) — porównanie sześciu dostawców, ceny, rezydencja danych, decyzja
- [`docs/infra/DEPLOYMENT_RUNBOOK.md`](DEPLOYMENT_RUNBOOK.md) — krok 3 (poczta) i krok 8 (zmienne w Railway)
- [`docs/brand/BRAND_EXTENDED.md`](../brand/BRAND_EXTENDED.md) §5 — ton e-maili, zakaz `noreply@`
- `App\Support\Poczta` — czym serwis mierzy „poczta działa”
- `App\Console\Commands\SprawdzPoczte` — komenda z §5
- [EmailLabs — rejestracja](https://panel.emaillabs.net.pl/pl/register) · [cennik](https://emaillabs.io/cennik-v2/) · [konto — brak karty, rozliczenie fakturą](https://docs.emaillabs.io/faq/konto) · [SPF/DKIM](https://emaillabs.io/en/secure-email-delivery/) — wszystkie sprawdzone 2026-09-08
- [Brevo — rejestracja](https://app.brevo.com/account/register) · [cennik](https://www.brevo.com/pricing/) · [SPF/DKIM setup](https://easydmarc.com/blog/brevo-ex-sendinblue-spf-dkim-setup/) — sprawdzone 2026-09-08
- [Postmark — Pricing & Billing FAQ](https://postmarkapp.com/support/article/1285-pricing-billing-faq) — brak karty na planie Developer, sprawdzone 2026-09-08
- [AWS — Free Tier FAQ](https://aws.amazon.com/free/registration-faqs/) — karta wymagana przy zakładaniu konta, sprawdzone 2026-09-08
