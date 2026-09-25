# Decyzja 1 — poczta transakcyjna

Stan na **wrzesień 2026**. Wszystkie ceny netto, w walucie podanej przez dostawcę.
Kurs orientacyjny: 1 USD ≈ 3,65 zł, 1 EUR ≈ 4,25 zł `[do weryfikacji — kurs bieżący]`.

> **Ponowna weryfikacja: 2026-09-08.** Darmowe pułapy i ceny bazowe EmailLabs,
> Brevo, Resend, Postmark, Mailgun i Amazon SES sprawdzone jeszcze raz pod kątem
> tego dokumentu — bez zmian względem stanu opisanego niżej. Doszedł wiersz
> „Wymaga karty płatniczej” (§1, brakowało go, a właściciel wprost o to pytał)
> i konkretne rekordy DNS oraz host SMTP dla EmailLabs i Brevo, teraz opisane
> krok po kroku w [`docs/infra/POCZTA_URUCHOMIENIE.md`](../infra/POCZTA_URUCHOMIENIE.md) §2A–§2B.

---

## 0. Najpierw policz, ile to naprawdę jest e-maili

Serwis wysyła: weryfikację e-maila, reset hasła, „Twoje dane są gotowe", **tygodniowy digest**.
Digest jest tu jedynym wolumenem, który się liczy.

| Faza | Konta | Transakcyjne / mies. | Digest / mies. (4,3 tyg.) | **Razem / mies.** | **Szczyt dzienny** (digest sobota rano) |
|---|---:|---:|---:|---:|---:|
| alfa | 50 | ~100 | ~215 | **~320** | **50** |
| beta | 1 000 | ~800 | ~4 300 | **~5 100** | **1 000** |
| wzrost | 10 000 | ~5 000 | ~43 000 | **~48 000** | **10 000** |

> **To jest najważniejsza liczba w całym dokumencie: limit *dzienny*, nie miesięczny, jest wiążącym ograniczeniem.**
> Tygodniowy digest to burst — cały wolumen tygodnia idzie w jedno przedpołudnie.
> Plan „3 000 e-maili miesięcznie, ale max 100 dziennie" (Resend Free) przy 1 000 użytkowników **nie zadziała**,
> mimo że miesięczny limit jest z zapasem. Digest da się rozłożyć na kilka godzin kolejką Laravela,
> ale nie da się go rozłożyć na 10 dni — przestanie być tygodniowy.

---

## 1. Tabela porównawcza

| | **Resend** | **Brevo** | **Mailgun** | **Postmark** | **Amazon SES** | **EmailLabs** (PL) |
|---|---|---|---|---|---|---|
| **Podmiot / siedziba** | Resend, Inc., USA | Brevo (d. Sendinblue), **Francja** | Mailgun Technologies (grupa **Sinch**, SE), spółka US | ActiveCampaign / Wildbit, **USA** | Amazon Web Services, **USA** | **Vercom S.A., Poznań, PL** (spółka giełdowa) |
| **Darmowy limit** | 3 000/mies., **max 100/dzień** | **300/dzień** = 9 000/mies., bezterminowo | **100/dzień** (plan Free) | **100/mies.** — tylko do testów | brak stałego free tier; nowe konta AWS: do **$200 kredytów** na 12 mies. | **STARTUP: 0 zł**, do 9 000/mies., **max 300/dzień** |
| **Cena po przekroczeniu** | Pro $20 = 50 tys.; Pro $35 = 100 tys.; nadmiar **$0,90/1 tys.** | Starter od **$9/mies.** (5 tys.); Standard od **$18/mies.** `[do weryfikacji — progi wolumenowe]` | Basic $15 = 10 tys. (**$1,80/1 tys.**); Foundation $35 = 50 tys. (**$1,30/1 tys.**); Scale $90 = 100 tys. (**$1,10/1 tys.**) | Basic $15 od 10 tys. (**$1,80/1 tys.**); Pro $16,50 (**$1,30/1 tys.**); Platform $18 (**$1,20/1 tys.**) | **$0,10/1 tys.** (à la carte) + $0,12/GB załączników. Plany: Essentials $0,16/1 tys. | Essential 30: **99–129 zł/mies.** do 100 tys.; PRO 100: **299–499 zł/mies.** + dedykowane IP, nadmiar **4,40 zł/1 tys.** |
| **Dane przetwarzane w UE** | **NIE** — patrz §2 | **TAK** — serwery własne we Francji i Niemczech + GCP **Belgia**; wszystkie w UE | **TAK, jeśli wybierzesz region EU** — „your data can remain in the region of your choice" | **NIE** — Deft (Chicago) + AWS US; Postmark deklaruje brak planów serwerów w UE | **TAK, jeśli wybierzesz region EU** — eu-central-1 (Frankfurt), eu-west-1, eu-north-1, eu-central-2 i in. | **TAK** — własne serwery w EOG / CEE, spółka polska |
| **DPA / umowa powierzenia** | standardowe DPA + certyfikacja **EU-US DPF**; **zmiany w DPA tylko na planie Enterprise** | DPA opublikowane w ToS, spółka UE — SCC nie są potrzebne | DPA dostępne; przy regionie EU brak transferu danych operacyjnych, ale spółka-matka jest w USA (CLOUD Act) | DPA + aktualne **SCC** (od 27.09.2021). Transfer do USA = potrzebna **TIA** | AWS DPA (GDPR addendum) standardowo, SCC w pakiecie; przy eu-central-1 dane nie opuszczają UE | **Umowa powierzenia po polsku, polskie prawo, polski sąd** — jedyny dostawca z tej listy |
| **Poczta polska 50+ (wp/o2/interia/onet)** | brak danych niezależnych; IP współdzielone globalnie | brak danych; duży wolumen marketingowy na współdzielonych IP = ryzyko sąsiedztwa | brak danych; dobre IP przy planach z dedykowanym adresem | historycznie **najlepsza reputacja transakcyjna** (oddzielny stream transakcyjny), ale IP w USA — polskie filtry patrzą na reputację IP, nie na kraj | zależy **wyłącznie od Ciebie** — SES daje surowy transport, reputację budujesz sam | **deklaruje najwyższą dostarczalność w Polsce** — to **twierdzenie dostawcy**, nie niezależny pomiar `[do weryfikacji — poproś o dane per-domena wp.pl/o2.pl/interia.pl]` |
| **Wymagania DNS** | SPF, DKIM (CNAME), rekomendowany DMARC, własny return-path | SPF, DKIM, DMARC, dedykowana subdomena wysyłkowa | SPF, DKIM, DMARC, CNAME trackingowy, własny MAIL FROM | SPF, DKIM (klucz Postmarka), DMARC, custom Return-Path (CNAME) | SPF, DKIM (3× CNAME Easy DKIM), **custom MAIL FROM** (bo domyślnie amazonses.com łamie zgodność SPF z DMARC), DMARC | SPF, DKIM, DMARC + rekomendowany rDNS przy dedykowanym IP |
| **Laravel 13** | **sterownik wbudowany** `resend`; `composer require resend/resend-php` + 1 zmienna | **brak wbudowanego** — `symfony/brevo-mailer` jako dodatkowy transport Symfony **albo** zwykły SMTP | **sterownik wbudowany** `mailgun`; region EU przez `MAILGUN_ENDPOINT=api.eu.mailgun.net` (udokumentowane w docs Laravela) | **sterownik wbudowany** `postmark`; `symfony/postmark-mailer` | **sterownik wbudowany** `ses`; `aws/aws-sdk-php`, region w configu | **zwykły SMTP** — zero pakietów, działa od razu; brak tagów/metadanych na poziomie API |
| **Wysiłek konfiguracyjny** | ~15 min | ~30 min (SMTP) | ~30 min | ~15 min | **~1–3 dni** — wniosek o production access, obsługa bounce/complaint przez SNS jest **obowiązkowa** | ~20 min |
| **Wymaga karty płatniczej?** | **NIE** — konto darmowe bez karty `[sprawdzone 2026-09-08, resend.com/pricing]` | **NIE** — plan Free bez karty `[sprawdzone 2026-09-08]` | **NIE** na planie Free `[sprawdzone 2026-09-08]` | **NIE** do planu Developer (100/mies.); karta dopiero przy upgrade `[sprawdzone 2026-09-08, postmarkapp.com/support]` | **TAK** — AWS wymaga ważnej karty już przy zakładaniu konta root, niezależnie od tego, czy zmieścisz się w darmowym limicie `[sprawdzone 2026-09-08]` | **NIE** — rejestracja bez karty; do konta w ogóle nie da się jej dziś podpiąć, po przekroczeniu limitu przychodzi faktura mailem, płatna przelewem `[sprawdzone 2026-09-08, docs.emaillabs.io/faq/konto]` |

### Uwaga do Amazon SES: sandbox

Nowe konto SES startuje w sandboxie: **200 e-maili / 24 h, 1 e-mail / sekundę**, i tylko na zweryfikowane adresy.
Wyjście z sandboxa wymaga wniosku do AWS z opisem, jak obsługujesz bounce i complaint.
To nie jest formalność — SES zawiesza konta, na których wskaźnik odbić przekroczy próg.

### Poza listą, ale warto wiedzieć: Cloudflare Email Sending

Laravel 13 ma **wbudowany sterownik `cloudflare`**, a domena `kuking.pl` już jest w Cloudflare.
Email Sending wszedł do **public bety 16.04.2026**: wymaga planu Workers ($5/mies.), zawiera **3 000 e-maili/mies.**,
potem **$0,35/1 tys.** — to najtaniej na całej liście po SES.

**Nie polecam na produkcję teraz:** usługa jest w becie, brak deklaracji rezydencji danych w UE,
Cloudflare to spółka amerykańska, a reputacja tych IP w polskich filtrach jest nieznana.
Wart obserwowania na 2027. `[do weryfikacji — status GA i DPA/rezydencja UE]`

---

## 2. Region UE — czy da się go wymusić

To jest sekcja, przez którą odpada połowa listy.

| Dostawca | Czy jest region UE | Czy da się **wymusić** | Co realnie zostaje w USA |
|---|---|---|---|
| **EmailLabs** | ✅ tak, domyślnie | nie trzeba — spółka polska, serwery w EOG | nic |
| **Brevo** | ✅ tak, domyślnie | nie trzeba — cała infrastruktura w UE (FR, DE, GCP Belgia) | nic *(sprawdź listę podprocesorów przed podpisem — Brevo używa też narzędzi amerykańskich)* |
| **Amazon SES** | ✅ `eu-central-1` (Frankfurt) | **tak, twardo** — endpoint regionu wybierasz w configu; dane nie wychodzą z regionu | nic po stronie danych; **spółka-matka w USA → CLOUD Act** |
| **Mailgun** | ✅ region EU (`api.eu.mailgun.net`) | **tak** — domenę zakładasz w regionie EU i wysyłasz na endpoint EU | nic po stronie danych; **spółka US → CLOUD Act** |
| **Resend** | ⚠️ **tylko wysyłka** | **NIE** | **wszystko poza samym relayem** |
| **Postmark** | ❌ brak | — | **wszystko** |

### Resend — pułapka, którą trzeba nazwać wprost

Resend pozwala wybrać region wysyłki: `us-east-1`, **`eu-west-1` (Irlandia)**, `sa-east-1`, `ap-northeast-1`.
Dokumentacja mówi jednak dosłownie:

> „Region selection controls where your emails are routed and sent from. **It does not control where customer data is stored.**
> All account data, including email metadata, logs, and API records, is stored in the **United States** regardless of the sending region you select."

Czyli: **adresy e-mail Twoich użytkowników, tematy wiadomości i logi doręczeń leżą w USA i nie ma ustawienia, które to zmieni.**
Retencja: 30 dni na planach Free/Pro/Scale.
Resend jest certyfikowany w **EU-US Data Privacy Framework** — transfer jest legalny, ale wymaga:
DPA + wpisu do rejestru czynności + informacji o transferze poza EOG w polityce prywatności + oceny (TIA).
Dodatkowo: **zmiany w DPA są dostępne tylko na planie Enterprise** — bierzesz wzór albo nic.

**Wniosek:** Resend i Postmark oznaczają dodatkową robotę prawną i dodatkowe zdanie w polityce prywatności,
którego przy dostawcy z UE po prostu nie ma. Przy serwisie dla grupy 50+, gdzie zaufanie jest walutą,
zdanie „Twój adres e-mail przetwarzamy w Polsce" jest warte więcej niż $10 różnicy w cenie.

---

## 3. Dostarczalność do polskich skrzynek — co jest faktem, a co wiarą

Twarde, źródłowe wymagania **WP.pl** (dotyczy też o2.pl — ta sama infrastruktura Wirtualnej Polski):

| Wymóg WP | Konsekwencja niespełnienia |
|---|---|
| każda wiadomość **podpisana DKIM** | filtr traktuje jak niezweryfikowanego nadawcę |
| **SPF + DMARC** na domenie wysyłkowej | *„Jeśli nie ustawicie polisy DMARC w domenie wysyłkowej, wasze maile na serwerach WP będą trafiały do spamu."* — cytat z pomocy WP |
| poprawny **rDNS** dla domeny z `From` i z koperty | obniżona reputacja |
| wysyłka **tylko z IP wpisanych w SPF**, **stałych, nie dynamicznych** | odrzucenia |
| unikalny **Message-ID** | utrudnione śledzenie i obsługa |

**To jest lista, którą trzeba spełnić niezależnie od wybranego dostawcy.** Żaden z nich nie zwalnia z konfiguracji DNS.

Czego **nie** udało się ustalić i czego nie należy udawać, że się wie:

- Nie istnieje publiczny, niezależny benchmark dostarczalności do wp.pl / o2.pl / interia.pl / onet.pl
  w rozbiciu na dostawców. Wszystkie liczby typu „99% dostarczalności" to marketing dostawcy.
- Twierdzenie EmailLabs o „najwyższej dostarczalności transakcyjnej w Polsce" jest **twierdzeniem sprzedażowym**.
  Przemawia za nim struktura (polska spółka, długoletnie relacje z polskimi operatorami, IP w EOG),
  ale nie jest to dowód. `[do weryfikacji — poproś EmailLabs o raport dostarczalności per domena odbiorcy]`
- **Jedyny wiarygodny test to test własny**: 20 skrzynek testowych (5× wp.pl, 5× o2.pl, 5× interia.pl, 5× onet.pl),
  ta sama treść, dwóch dostawców równolegle, sprawdzasz folder docelowy. Koszt: 0 zł i jedno popołudnie.
  **Zrób to przed betą, nie po.**

Czynnik, który waży więcej niż wybór dostawcy: **treść i zachowanie**.
Digest do grupy 50+ z małą liczbą otwarć i wysokim „to nie ja się zapisałam" zniszczy reputację u każdego dostawcy.
Stąd: double opt-in na digest, widoczny link wypisu, adres `kontakt@kuking.pl` zamiast `noreply@`
(zgodne z `docs/brand/BRAND_EXTENDED.md`).

---

## 4. Koszt przy trzech skalach

| Dostawca | alfa (~320/mies.) | beta (~5 100/mies., szczyt 1 000/dzień) | wzrost (~48 000/mies., szczyt 10 000/dzień) |
|---|---|---|---|
| **EmailLabs** | **0 zł** (STARTUP) | **0 zł** — ale szczyt 1 000/dzień > limitu 300/dzień → **99–129 zł** | **99–129 zł** (Essential, do 100 tys.) |
| **Brevo** | 0 zł | 0 zł limitem mies., ale szczyt > 300/dzień → od **$9** | od **$18–49** `[do weryfikacji]` |
| **Resend** | **0 zł nie starczy** (100/dzień) → **$20** | **$20** (Pro 50 tys.) | **$20** (Pro 50 tys.) |
| **Mailgun** | 0 zł (100/dzień wystarczy dla 50 kont) | **$15** (Basic 10 tys.) | **$35** (Foundation 50 tys.) |
| **Postmark** | **$15** (free = 100/mies.) | **$15** | ~**$70–90** `[do weryfikacji — progi wolumenowe Postmarka]` |
| **Amazon SES** (eu-central-1) | **~$0,03** | **~$0,51** | **~$4,80** |
| **Cloudflare Email Sending** | $5 (Workers) | $5 | ~$21 |

SES jest tanie tak bardzo, że różnica przestaje być argumentem: **~5 USD vs ~130 zł miesięcznie to ok. 100 zł różnicy**.
Za te 100 zł kupujesz: brak wniosku o production access, brak własnej obsługi SNS bounce/complaint,
brak budowania reputacji IP od zera, panel po polsku i wsparcie w polskiej strefie czasowej.
Przy jednoosobowym zespole to jest **tanio**.

---

## 5. Rzeczy, które trzeba zrobić niezależnie od wyboru

1. **Osobna subdomena wysyłkowa**, np. `mail.kuking.pl` albo `poczta.kuking.pl`.
   Nigdy nie wysyłaj transakcyjnych z gołego `kuking.pl` — awaria reputacji zabija wtedy też pocztę firmową.
2. **DMARC startowo `p=none` z raportami**, po 2–4 tygodniach czystych raportów → `p=quarantine`, potem `p=reject`.
   WP wprost mówi, że brak polityki DMARC = spam.
3. **Rozdziel streamy**: transakcyjny (weryfikacja, reset, „dane gotowe") i digest.
   Postmark robi to natywnie (message streams), u innych — osobna subdomena albo osobny `mailer` w `config/mail.php`.
4. **`failover` mailer w Laravelu** — dwa transporty, drugi jako zapas.
   Reset hasła, który nie doszedł, to dla osoby 60+ koniec przygody z serwisem.
5. **Kolejkuj digest** (`Mail::queue`) i rozłóż na godziny — 10 000 wiadomości w 60 sekund to sygnał spamowy.
6. **Obsłuż bounce i complaint** — twardo odbijające adresy muszą wypadać z listy digestu automatycznie.
   Przy SES to Twój kod; u pozostałych — webhook.

---

## 6. Co się dzieje, gdy pula 300 listów na dobę się kończy (D-239)

Punkt 3 wyżej mówi „rozdziel streamy". Do D-239 rozdzielenia nie było
nawet po naszej stronie: każda funkcja wysyłająca wiele listów miała własny sufit
dobowy i widziała **tylko swój**, a listy bez sufitu (potwierdzenie rejestracji,
przypomnienie hasła) nie liczyły się nigdzie. Pulę zjadał ten, kto był pierwszy.

Zmierzone: `/nie-pamietam-hasla` przyjmuje 5 próśb na 10 minut z adresu IP, czyli
720 na dobę, każdą na **inny** adres — jeden sprawca opróżniał całą pulę w około
70 minut. Ponawianie potwierdzenia adresu (6 na minutę z konta, bez sufitu
dobowego) robiło to samo w około 50 minut z jednego niepotwierdzonego konta.

Od D-239 wszystkie drogi liczą się w **jednym** liczniku
(`App\Domain\Security\DziennyBudzetListow::wspolny()`), a o tym, co gaśnie
pierwsze, decyduje `kuking.poczta.progi_wygaszania`. Próg mówi, ile listów z puli
dana klasa ma zostawić nietkniętych:

| Klasa | Próg | Co obejmuje | Kiedy gaśnie |
|---|---|---|---|
| `podsumowanie` | 240 | tygodniowy digest | pierwsza — po 60 listach doby |
| `zwykla` | 100 | przypomnienie hasła, odpowiedzi z „Napisz do nas" | gdy w puli zostaje 100 listów |
| `wejscie` | 0 | potwierdzenie rejestracji (także ponowienie), logowanie linkiem | ostatnia — sięga po ostatni list doby |

Kierunek jest zawsze ten sam i wynika z §5 pkt 4: podsumowanie, które nie doszło,
jest niczym; list, bez którego nie da się wejść na konto, kończy komuś przygodę
z serwisem, zanim się zaczęła.

**Co widzi człowiek.** Przy pustej puli ekran mówi wprost, żeby nie czekać na
list, i podaje adres kontaktowy, pod którym odpisuje człowiek — nigdy „coś poszło
nie tak". Przy ścisku na blokadzie licznika (dwa żądania w tej samej
milisekundzie) prosi o powtórzenie kliknięcia, bo tam drugie kliknięcie zwykle
wystarcza.

**Kiedy przejść na plan płatny.** Ostrzeżenie o zużyciu 80% sufitu idzie do
dziennika i na kanał `blad_webhook` raz na dobę na funkcję
(`poczta.prog_ostrzezenia_procent`, issue #234). Powtarzające się ostrzeżenie
klasy `wejscie` znaczy, że plan STARTUP przestał wystarczać — patrz §4.

**Czego ten licznik nie robi.** Nie gwarantuje, że link do logowania wyjdzie
zawsze: klasa `wejscie` dzieli ostatnie listy doby między potwierdzenie
rejestracji, jego ponowienie i logowanie linkiem. Masowe zakładanie kont albo
klikanie „Wyślij wiadomość jeszcze raz" nadal może zjeść pulę do zera — wtedy
ekran logowania linkiem mówi, że listu nie będzie, i podaje logowanie hasłem
oraz adres kontaktowy (D-239). Nie dzieli też puli między konkretnych ludzi: jeden
sprawca nadal wypali klasę `zwykla` na cały dzień.

**Listy bez rezerwacji też są w rachunku (audyt B8-02, 25.09.2026).** Do tej
daty licznik nie widział listów niskonakładowych z rodziny moderacyjnej
(decyzje w sprawie zgłoszeń, potwierdzenia odwołań i zgłoszeń DSA, dobowe
podsumowanie automatu, eksport danych, ostrzeżenia o zmianie adresu, próba
wejścia kontem Facebooka) ani alarmu automatu o pilnym oznaczeniu. Teraz:

- słuchacz `MessageSending` (`App\Poczta\PoliczListBezRezerwacji`) dolicza do
  klasy `zwykla` każdy list, który wychodzi do transportu **bez** nagłówka
  `X-Kuking-Budzet: zarezerwowany`. Tylko liczy (`zajmij()`), niczego nie
  odrzuca — o tym, co gaśnie, dalej decydują drogi z rezerwacją, ale widzą już
  prawdziwe zużycie. Nagłówek jest zdejmowany przed wysyłką do dostawcy;
- nagłówek (`App\Poczta\ListZarezerwowany`) niosą wyłącznie listy, których
  droga zarezerwowała miejsce przed wysyłką. Rejestr tych klas jest zamknięty
  w obie strony w `tests/Feature/KazdyListLiczySieWPuliTest.php`;
- alarm automatu (`AlarmujModeratora`) idzie spod
  `DziennyBudzetListow::dlaAlarmuAutomatu()`: klasa `wejscie` i własny sufit
  `moderation.model.alarm_dzienny_sufit` (domyślnie 10, `KUKING_MODEL_ALARM_SUFIT`),
  osobny od sufitu alarmu o zgłoszeniu człowieka. Po wyczerpaniu oznaczenie
  czeka w `/admin/sygnaly`, a dziennik mówi, dlaczego bez listu.

Ponowienie zadania po błędzie transportu liczy się przy liście bez rezerwacji
tyle razy, ile razy list poszedł do transportu — tak samo liczy dostawca.

---

## Rekomendacja

**Na alfę: EmailLabs STARTUP (0 zł)** — jedyny dostawca z listy, z którym podpiszesz umowę powierzenia po polsku, na polskim prawie, przy danych nieopuszczających EOG, a limit 300/dzień z zapasem pokrywa 50 kont. **Na skalę: EmailLabs Essential (99–129 zł/mies. do 100 tys.)**, bo przy 10 000 użytkowników szczyt digestu to 10 000 wiadomości w jedno przedpołudnie i dopiero plan bez limitu dziennego to udźwignie. **Resend i Postmark odrzucam nie z powodu ceny, tylko dlatego, że przechowują metadane i logi w USA** — Resend potwierdza to wprost w dokumentacji i nie daje ustawienia, które to zmieni, co przy serwisie dla grupy 50+ oznacza dodatkową ocenę transferu, dodatkowy akapit w polityce prywatności i gorszą rozmowę o zaufaniu. **Amazon SES w `eu-central-1` trzymaj jako plan awaryjny na wypadek eksplozji wolumenu** — jest 20× tańszy (~5 USD za 48 tys.), ale kosztuje kilka dni pracy na production access i własną obsługę bounce/complaint. **Zanim cokolwiek podpiszesz, zrób własny test 20 skrzynek** (wp.pl, o2.pl, interia.pl, onet.pl) — to jedyne dane o polskiej dostarczalności, którym można wierzyć.

---

## Źródła

- [Resend — Pricing](https://resend.com/pricing)
- [Resend — Choosing a Region (dokumentacja: dane konta w USA niezależnie od regionu wysyłki)](https://resend.com/docs/dashboard/domains/regions)
- [Resend — GDPR](https://resend.com/security/gdpr) · [Resend — DPA](https://resend.com/legal/dpa)
- [Brevo — Pricing](https://www.brevo.com/pricing/) · [Brevo — Transactional Email](https://www.brevo.com/products/transactional-email/)
- [Brevo — Data storage location (pomoc)](https://help.brevo.com/hc/en-us/articles/360001005510-Data-storage-location)
- [Brevo — Where can I find the DPA?](https://help.brevo.com/hc/en-us/articles/15403782599570-Where-can-I-find-the-Data-Processing-Agreement-DPA)
- [Mailgun — Pricing (plany, ceny nadmiaru, region EU)](https://www.mailgun.com/pricing/)
- [Mailgun — API regions](https://documentation.mailgun.com/docs/mailgun/api-reference/api-overview#mailgun-regions)
- [Postmark — Pricing](https://postmarkapp.com/pricing) · [Postmark — EU Data Protection](https://postmarkapp.com/eu-privacy) · [Postmark — GDPR FAQ](https://postmarkapp.com/support/article/1218-gdpr-faq)
- [Amazon SES — Pricing](https://aws.amazon.com/ses/pricing/)
- [Amazon SES — endpoints i quoty (regiony UE, sandbox 200/24 h, 1/s)](https://docs.aws.amazon.com/general/latest/gr/ses.html)
- [EmailLabs — cennik](https://emaillabs.io/cennik-v2/)
- [EmailLabs — e-maile transakcyjne i dostarczalność](https://emaillabs.io/e-maile-transakcyjne-dlaczego-ich-dostarczalnosc-jest-tak-kluczowa-dla-branzy-e-commerce/)
- [EmailLabs DOCS — Konto (rejestracja bez karty, rozliczenie fakturą)](https://docs.emaillabs.io/faq/konto)
- [EmailLabs DOCS — konfiguracja SPF i DKIM](https://emaillabs.io/en/secure-email-delivery/)
- [Brevo — SPF/DKIM setup](https://easydmarc.com/blog/brevo-ex-sendinblue-spf-dkim-setup/)
- [Postmark — Pricing & Billing FAQ (brak karty na planie Developer)](https://postmarkapp.com/support/article/1285-pricing-billing-faq)
- [AWS — Free Tier FAQ (karta wymagana przy zakładaniu konta)](https://aws.amazon.com/free/registration-faqs/)
- [WP Pomoc — Zasady wysyłki wiadomości masowych](https://pomoc.wp.pl/zalecenia-dla-nadawcow-masowych)
- [WP Pomoc — Polityka antyspamowa](https://pomoc.wp.pl/polityka-antyspamowa)
- [CERT Polska — Mechanizmy weryfikacji nadawcy wiadomości (SPF/DKIM/DMARC)](https://cert.pl/posts/2021/10/mechanizmy-weryfikacji-nadawcy-wiadomosci/)
- [Laravel 13 — Mail (sterowniki wbudowane: SMTP, Cloudflare, Mailgun, Postmark, Resend, SES, sendmail)](https://laravel.com/docs/13.x/mail)
- [Cloudflare — Email Sending public beta (16.04.2026)](https://developers.cloudflare.com/changelog/post/2026-04-16-email-sending-public-beta/)
- [Cloudflare Email Service — Pricing](https://developers.cloudflare.com/email-service/platform/pricing/)
