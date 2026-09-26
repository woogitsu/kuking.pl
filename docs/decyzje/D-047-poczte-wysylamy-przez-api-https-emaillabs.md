## D-047 · Pocztę wysyłamy przez API HTTPS EmailLabs, własnym transportem Symfony

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

`MAIL_MAILER=emaillabs`. Wysyłka idzie zwykłym `POST`-em HTTPS na
`https://api.emaillabs.io/v2.1/email`, przez transport napisany w tym
repozytorium (`App\Poczta\TransportEmailLabs`), zarejestrowany jako sterownik
Laravela przez `Mail::extend()` w `App\Providers\PocztaServiceProvider`.
**Żadnej nowej paczki Composera.**

### DLACZEGO NIE SMTP — to nie jest kwestia gustu, tylko planu hostingu

Dokumentacja Railwaya mówi wprost: *„SMTP is only available on the Pro plan
and above. Free, Trial, and Hobby plans must use transactional email services
with HTTPS APIs. SMTP is disabled on these plans to prevent spam and abuse."*
([docs.railway.com/networking/outbound-networking#email-delivery](https://docs.railway.com/networking/outbound-networking#email-delivery),
sprawdzone 9 września 2026.) Właściciel jest na planie Free i przechodzi na
Hobby — **obie blokady obowiązują**. Ta sama strona dodaje, że usługi po HTTPS
są rekomendowane **na wszystkich planach**, także tam, gdzie SMTP działa.

Objaw zmierzony na produkcji tego samego dnia jest gorszy niż zwykły błąd:
pakiety idą w próżnię, więc połączenie nie tyle pada, co **wisi**. Zadanie
`App\Notifications\UstawienieNowegoHasla` wchodziło w `RUNNING`
i **nigdy się nie kończyło** — ani `DONE`, ani `FAIL`. W panelu Railwaya
wyglądało to jak zawieszony worker, nie jak awaria poczty, więc nic tego nie
nazwało po imieniu.

To była **trzecia warstwa cichej awarii poczty tego samego dnia**, po
`MAIL_MAILER=log` (przyjmuje list i zgłasza sukces) i `MAIL_SCHEME=tls`
(schemat, którego Symfony nie zna). Stąd nacisk na to, żeby nowa droga
wywracała się głośno.

### DLACZEGO NADAL EMAILLABS, SKORO TRZEBA PISAĆ WŁASNY TRANSPORT

Powód jest prawny i produktowy, nie techniczny. **EmailLabs to Vercom S.A.
z Poznania, serwery w EOG** — dzięki temu w polityce prywatności zostaje
zdanie „Twój adres e-mail przetwarzamy w Polsce", a umowa powierzenia jest po
polsku, na polskim prawie.

Każdy dostawca z **gotowym** sterownikiem Laravela (Mailgun, SES, Postmark,
Resend) to spółka amerykańska: CLOUD Act, nowe DPA, ocena transferu (TIA)
i dodatkowy akapit o wywozie danych poza EOG w polityce prywatności. Przy
serwisie dla grupy 50+, gdzie zaufanie jest walutą, pół dnia pracy nad
transportem jest tańsze niż ten akapit. Pełna analiza sześciu dostawców:
[`docs/decyzje/POCZTA.md`](../decyzje/POCZTA.md) §2.

Rozważona i odrzucona alternatywa: **przejście na plan Railway Pro tylko po
to, żeby odblokować SMTP.** To jest stały koszt miesięczny za możliwość
używania protokołu, który i tak jest wolniejszy i gorzej diagnozowalny niż
HTTPS — a transport po API jest jednorazowy i działa na każdym planie.

### CO Z TEGO WYNIKA DLA KODU

- **Reszta serwisu nie wie o zmianie.** `Mail::`, wszystkie `Notification`,
  kolejka i `kuking:sprawdz-poczte` chodzą przez `MailManager`.
- **Klucze są sekretami i nie wychodzą nigdzie.** Komunikat odmowy budujemy
  z listy dozwolonych pól odpowiedzi (kod błędu, tytuł z wyciętym adresem,
  nazwa parametru, `uniqId`) — nigdy z `errors[].message` ani
  `errors[].meta.value`, bo dokumentacja mówi wprost, że to drugie jest
  „the value of this parameter passed", czyli przy błędnym adresie odbiorcy
  byłby to jego adres e-mail. To jest ta sama lekcja, co audyt A6-01
  w `App\Logging\WebhookBleduHandler`.
- **Cisza jest zakazana.** Sukcesem jest wyłącznie HTTP 2xx *i* zero błędów
  *i* co najmniej jedna przyjęta wiadomość. HTTP 207 („część adresatów
  przyjęta") jest tu porażką, bo nasze listy mają po jednym adresacie.
- **`App\Support\Poczta::dziala()` sprawdza teraz dwie rzeczy**: czy sterownik
  dostarcza ORAZ czy Laravel potrafi zbudować dla niego transport. Sama nazwa
  sterownika okazała się za słabym pomiarem dwa razy tego samego dnia.
  Skutek uboczny, świadomy: `postmark` i `resend` przestały uchodzić za
  działające, bo ich paczek nie ma w `composer.json` i pierwszy list padłby na
  „Class not found".
- **Śledzenie odnośników domyślnie wyłączone** (`X-TRACKING-OFF`). Włączone
  podmienia link do zmiany hasła na adres przekierowujący dostawcy, a link
  prowadzący pod obcą domenę to dla osoby 60+ kształt phishingu, przed którym
  ostrzegają banki.

### CO ZOSTAJE NIETKNIĘTE

Konfiguracja SMTP w `config/mail.php`, w `.railway/railway.ts` i w
`.env.example` **zostaje, uśpiona**: `MAIL_MAILER` jej nie wybiera, ale
wszystkie zmienne są na miejscu. Powód: po przejściu na plan Pro Railway
odblokowuje SMTP i wtedy jest to gotowa droga powrotna oraz gotowe drugie
ramię `failover` u innego dostawcy. Razem z nią zostaje
`SchematPocztyJestObslugiwanyTest` — bo dopóki `MAIL_SCHEME` jest w pliku,
dopóty ktoś może wpisać tam z powrotem `tls`.

**Zmiana wymaga:** przejścia na plan Railway Pro (wtedy SMTP staje się
możliwy, ale nadal nie obowiązkowy) — albo decyzji właściciela o zmianie
dostawcy, co jest decyzją prawną, nie techniczną, i wymaga ponownego
przeczytania `docs/decyzje/POCZTA.md` §2.

📄 `app/Poczta/TransportEmailLabs.php` · `app/Poczta/OdmowaEmailLabs.php` ·
`app/Poczta/BrakKonfiguracjiEmailLabs.php` ·
`app/Providers/PocztaServiceProvider.php` · `app/Support/Poczta.php` ·
`config/mail.php` · `config/services.php` · `.railway/railway.ts` ·
`tests/Feature/PocztaPrzezApiEmailLabsTest.php` ·
`docs/infra/POCZTA_URUCHOMIENIE.md` §2A
