# Wejście kontem Facebooka — uruchomienie krok po kroku

Ten dokument jest dla **właściciela**, nie dla programisty. Zakłada, że masz
konto na Facebooku i przeglądarkę — i nic więcej. Mówi, co kliknąć w panelu
Meta, co skopiować, czego się spodziewać i **co można zrobić dziś, jeszcze
przed napisaniem choćby linijki kodu**.

Wzorem jest dla niego **D-069** (`docs/DECISIONS.md` na gałęzi
`claude/logowanie-kontem-google`) — wejście kontem Google. Wszędzie, gdzie
Facebook zachowuje się inaczej niż Google, jest to tutaj powiedziane wprost,
razem ze skutkiem dla kodu.

**Kodu logowania Facebookiem jeszcze nie ma.** Ten dokument jest po to, żeby
procedura po stronie Meta biegła równolegle z jego pisaniem, a nie po nim.

---

## ⚠️ NAJWAŻNIEJSZE ZDANIE W CAŁYM PLIKU

> **`public_profile` i `email` NIE wymagają przeglądu aplikacji (App Review).**
> To dwa jedyne uprawnienia, których Meta nie każe uzasadniać, i to dokładnie
> te dwa, których potrzebujemy. Dokumentacja Meta mówi dosłownie:
>
> *„Your app can ask for people's name and photo (the default profile fields)
> and for email without going through app review"*
> ([Authentication Versus Data Access](https://developers.facebook.com/docs/facebook-login/auth-vs-data/),
> sprawdzone 10 września 2026).
>
> **Zlecenie, na podstawie którego powstał ten dokument, zakładało coś
> odwrotnego** — że Meta wymaga przeglądu na adres e-mail w użyciu publicznym
> i że to jest tygodniowa przeszkoda po stronie właściciela. **To nieprawda
> i to jest dobra wiadomość:** nie ma tu procedury, która trwa dniami i którą
> trzeba uruchomić dziś, żeby nie blokowała kodu.

Cena tej dobrej wiadomości jest jednak realna i leży w innym miejscu, niż
się spodziewaliśmy — w §7. **Nie jest to przeszkoda proceduralna, tylko
projektowa: Facebook nie mówi nam, czy adres e-mail jest potwierdzony, więc
warunek, na którym stoi bezpieczeństwo wejścia kontem Google (reguła 1
z D-069), przy Facebooku nie ma z czego powstać.** Zanim właściciel podejmie
decyzję, powinien przeczytać §7 — to jest jedyne miejsce w tym dokumencie,
w którym coś jest naprawdę drogie.

---

## Kiedy i jak to sprawdzano

**Data sprawdzenia: 10 września 2026.** Wszystko poniżej, co jest oznaczone
jako sprawdzone, zostało odczytane tego dnia z dokumentacji Meta pod
adresami wypisanymi w §14.

**Czym sprawdzano i co z tego wynika dla zaufania do tego dokumentu.**
`curl` do `developers.facebook.com` przez proxy sesji oddaje **HTTP 400**
i stronę błędu Meta — surowego HTML-a tych stron nie dało się pobrać.
Odczyt szedł więc przez narzędzie, które renderuje stronę i **streszcza** ją
modelem. Skutek: **cytaty w cudzysłowie są dosłowne** (o to prosiłem wprost
i takie wróciły), ale **niecytowane zdania są streszczeniem, nie źródłem**.
Wszędzie, gdzie streszczenie było jedyną podstawą, jest to napisane.

**Czego NIE udało się sprawdzić** (zebrane też w §13, żeby nie zginęło):

1. **Realnego czasu przeglądu aplikacji.** Meta nie podaje w dokumentacji
   żadnego terminu ani SLA. Jedyna liczba, na jaką trafiłem („średnio około
   24 godziny"), stała na stronie o przeglądzie dla dostawców rozwiązań
   WhatsApp i **do naszego przypadku się nie stosuje**. Nie zgaduję.
   W naszym przypadku to zresztą pytanie bez treści — patrz §6.
2. **Czy weryfikacja biznesowa (Business Verification) dotyczy nas.**
   Dokumentacja Meta mówi w tej sprawie **dwie rzeczy, które się nie
   składają**. Rozpisane w §3.5 razem z tanim sposobem rozstrzygnięcia.
3. **Listy dokumentów do weryfikacji biznesowej i czasu jej trwania.**
   Dokumentacja odsyła do Centrum pomocy Business Managera, którego nie
   czytałem.
4. **Czy `http://localhost` da się dopisać do adresów przekierowań.**
   Dokumentacja Meta tego nie mówi; twierdzenia, że „w trybie deweloperskim
   Facebook jest pobłażliwy dla localhosta", pochodzą z forów, nie od Meta.
   **Traktuj to jako niesprawdzone** (§4.4).
5. **Wyglądu dzisiejszego kreatora aplikacji krok po kroku.** Dokumentacja
   wymienia kroki i część przypadków użycia, ale **pełnej listy przypadków
   użycia na stronie nie ma** — jest nagłówek „Available use cases" bez
   wyliczenia. Nazwy przycisków mogą się różnić od tych w §1.

---

## 0. Co już mamy, a czego nie ma

**Jest** (na `main`, sprawdzone w kodzie 10 września):

- własna droga usunięcia konta: `/ustawienia/twoje-dane` →
  `DataSettingsController::requestDeletion` → `User::markForDeletion()`
  → po 30 dniach `App\Domain\Users\Actions\EraseAccountData`;
- **kasowanie jest odroczone i odwracalne** (30 dni, `cofnij`), a jego zakres
  wybiera człowiek na ekranie (`delete_scope`: `minimum` albo `everything`) —
  **D-018** i **D-022**;
- dziennik audytowy `audit_log` z trzema zdarzeniami, których nigdy nie
  kasujemy: `account.data_erased`, `account.delete_requested`,
  `account.delete_cancelled` (`App\Models\AuditLogEntry::NIGDY_NIE_KASUJ`);
- polityka prywatności z tabelą dostawców i akapitem o przekazywaniu poza EOG
  (`resources/legal/polityka-prywatnosci.md`).

**Nie ma:**

- ani jednej linijki kodu logowania Facebookiem;
- tabeli `tozsamosci_zewnetrzne`, o której D-069 mówi, że będzie właściwym
  kształtem **przy drugim dostawcy** — czyli teraz;
- ekranu „połącz / odłącz konto zewnętrzne" w ustawieniach. D-069 wymienia
  jego brak wśród rzeczy świadomie niezrobionych i ostrzega: odłączenie konta,
  które **nie ma innej drogi wejścia**, zamyka człowiekowi drzwi jednym
  kliknięciem. To ostrzeżenie wraca w §9 i jest tam decydujące.

Istniejące zgłoszenie na tę funkcję to **issue #259**. Warunek, który sobie
tam postawiliśmy („dopiero gdy logowanie Google działa na produkcji"),
właściciel 10 września zmienił — prosi o obie drogi pilnie.

---

## 1. Jaki typ aplikacji zakłada się w panelu Meta

### 1.1. Meta nie ma już „typów aplikacji" — ma „przypadki użycia"

To pierwsza rzecz, która myli przy czytaniu starszych poradników. Dawniej
zakładało się aplikację typu **Consumer**, **Business** albo **Gaming**.
Dziś kreator pyta o **przypadek użycia** (*use case*) i sam włącza produkty,
których ten przypadek wymaga.

Kroki kreatora, wprost z dokumentacji
([Create an App](https://developers.facebook.com/docs/development/create-an-app)):

1. **Start** — wejście na stronę zakładania aplikacji.
2. **App details** — *„Enter your app's name and a contact email address"*.
3. **Use cases** — wybór jednego albo kilku przypadków użycia; niezgodne ze
   sobą są wyszarzone.
4. **Business** — portfolio firmowe albo „nie chcę teraz łączyć".
5. **Requirements** — dopełnienie wymagań, jeśli jakieś są.
6. **Overview** — podsumowanie i „Go to dashboard".

### 1.2. Który przypadek użycia wybrać

**Wybierz: „Authenticate and request data from users with Facebook Login".**

Ta nazwa jest dosłownym cytatem z dokumentacji Meta i jest to dokładnie nasz
przypadek: serwis WWW, logowanie ludzi, potrzebny adres e-mail. Dokumentacja
odnotowuje też, że ten przypadek użycia **jest niezgodny** z niektórymi
innymi (np. „Manage everything on your Page") — więc wybieraj jeden i nie
dokładaj „na przyszłość".

**Czym różnią się przypadki użycia i dlaczego to ma znaczenie.** Przypadek
użycia decyduje o tym, jakie uprawnienia aplikacja może w ogóle prosić i co
Meta będzie od niej wymagać. Wybór „logowanie ludzi" trzyma nas w obszarze,
w którym `public_profile` i `email` są dostępne bez przeglądu (§2). Każdy
przypadek użycia dołożony obok — strony, reklamy, wiadomości — wciąga
uprawnienia, które przeglądu wymagają, i to jest droga, na którą **nie
wchodzimy** (§8).

### 1.3. Portfolio firmowe — brać czy nie brać

Dokumentacja mówi, że nie jest obowiązkowe: da się wybrać „I don't want to
connect a business portfolio yet". Ale mówi też: *„if your app will access
data that you don't own or manage, you must connect your app to a business
portfolio"* — a my właśnie tak robimy (dane cudzych ludzi, nie nasze).

**Zalecenie: podłącz portfolio firmowe od razu, na SAMSUFI sp. z o.o.**
Powód praktyczny, nie formalny: jeśli okaże się, że przy przełączaniu
uprawnień na dostęp zaawansowany panel poprosi o weryfikację biznesową
(§3.5), to bez portfolio nie ma czego weryfikować i procedura zaczyna się od
początku. Portfolio założone dziś nic nie kosztuje i niczego nie blokuje.

---

## 2. Uprawnienia: co jest potrzebne i co wymaga przeglądu

### 2.1. Potrzebujemy dwóch i tylko dwóch

| Uprawnienie | Co daje | Przegląd aplikacji |
|---|---|---|
| `public_profile` | *„read the Default Public Profile Fields on the User node"* — u nas: identyfikator konta i imię | **NIE.** Dokumentacja: *„This permission is automatically granted to all apps."* |
| `email` | *„read a person's primary email address"* — dozwolone użycie obejmuje wprost *„letting them log into your app with the email address associated with their Facebook profile"* | **NIE** — patrz §2.2 |

### 2.2. Dostęp standardowy vs zaawansowany — jedyny przycisk do kliknięcia

To jest miejsce, w którym rodzi się nieporozumienie, że „email wymaga
przeglądu". Meta ma dwa poziomy dostępu do każdego uprawnienia
([Access Levels](https://developers.facebook.com/docs/graph-api/overview/access-levels)):

- **Standard Access** — *„Permissions with Standard Access can only be
  requested from app users who have a role on the requesting app."* Czyli:
  działa wyłącznie dla ludzi, którzy mają rolę w aplikacji (§5).
- **Advanced Access** — *„Permissions with Advanced Access can be requested
  from any app user."* Czyli: działa dla wszystkich, i to jest to, czego
  potrzebujemy publicznie.

I dalej, dosłownie: *„Advanced Access, however, must be approved on an
individual permission and feature basis through the App Review process"* —
**z wyjątkiem naszych dwóch uprawnień**:

> *„All newly created Consumer apps are automatically approved for Advanced
> Access for the email and public_profile permissions"*
>
> *„both permissions are set to Standard Access by default and must be
> manually switched to Advanced Access"*

**Co z tego wynika w praktyce, i to jest sedno całego §2:** dostęp
zaawansowany do `email` i `public_profile` jest **już zatwierdzony** —
trzeba go tylko **przestawić przełącznikiem w panelu**. Nie ma wniosku, nie
ma nagrania z ekranu, nie ma czekania na czyjąś decyzję.

**Uwaga na dwie rzeczy w tym cytacie.** Po pierwsze, mówi on o aplikacjach
typu *Consumer*, a dzisiejszy kreator typami się nie posługuje (§1.1) —
**nie sprawdziłem, jak Meta odwzorowuje to zdanie na aplikację założoną
przez przypadek użycia „Facebook Login"**. Po drugie, dokumentacja dodaje
ostrożne *„In some cases additional App Review on an individual permission
and feature basis might be required"*, nie mówiąc, jakie to przypadki.
**Rozstrzygnięcie jest tanie i zajmuje minutę:** po założeniu aplikacji wejdź
w App Review → Permissions and Features, znajdź `email`, kliknij przełączenie
na dostęp zaawansowany i **przeczytaj, co panel powie**. Jeśli przejdzie —
tematu nie ma. Jeśli poprosi o wniosek albo o weryfikację biznesową — to jest
odpowiedź i wtedy dopiero zaczyna się procedura z terminem. To jest
**czynność nr 6** w tabeli z §12 i można ją zrobić dziś.

### 2.3. Czego przegląd dotyczy naprawdę

Żeby nie zostało wrażenie, że przeglądu nie ma nigdzie. Jest, i dotyczy
wszystkiego poza naszymi dwoma uprawnieniami: *„to ask for any other
permission beyond basic ones, your app will need to be reviewed by Facebook
before these permission become visible in the Login Dialog"*. Lista
znajomych, zdjęcia, strony, reklamy, wiadomości — każde z tych uprawnień to
wniosek, nagranie z ekranu i uzasadnienie. **Dlatego §8 nie jest kokieterią:
proszenie o cokolwiek więcej zmienia tę funkcję z jednodniowej w
tygodniową.**

---

## 3. Co Meta wymaga PRZED publicznym użyciem

Kolejność jest tu istotna: **przeglądu aplikacji nie potrzebujemy (§2), ale
trybu publicznego — tak.** Aplikacja w trybie deweloperskim działa wyłącznie
dla ludzi z rolą (§5). Żeby wpuścić kogokolwiek innego, trzeba przestawić
**App Mode** na **Live**, a do tego Meta stawia warunki.

### 3.1. Cztery pola bez których nie ma trybu publicznego

Wprost z ogłoszenia Meta
([Live Mode for production use](https://developers.facebook.com/blog/post/2019/09/23/live-mode-for-production-use/)),
wszystkie w **Settings → Basic**:

| Pole w panelu | Co wpisać u nas | Można zrobić dziś? |
|---|---|---|
| **Privacy Policy URL** | `https://kuking.pl/prywatnosc` | **tak** |
| **App Icon (1024 × 1024)** | logo Kuking, kwadrat 1024 px, bez przezroczystości | **tak** |
| **Business Use** | wybór z listy — nasz przypadek to „support my own business" (własny serwis, nie usługa dla innych firm) | **tak** |
| **App Category** | najbliższa nam kategoria — „Lifestyle" albo „Food & Drink"; dokumentacja mówi tylko *„select a category that accurately describes your app"* | **tak** |

**Adres warunków (Terms of Service URL)** nie jest wymieniony wśród czterech
warunków trybu publicznego, ale dokumentacja odnotowuje, że pola na politykę
i warunki *„are commonly needed to complete App Review"*. Mamy regulamin, więc
**wpisz oba** — nie ma powodu zostawiać pustego pola, o które ktoś kiedyś
zapyta. U nas: `https://kuking.pl/regulamin`.

> **Oba adresy są sprawdzone w kodzie** (`routes/web.php:122-123`:
> `/regulamin` i `/prywatnosc`) i oba stoją **poza grupą `auth`**, czyli
> otwierają się bez logowania — a Meta wymaga, żeby adres polityki był
> *„active, publicly available, easily accessible… and non-geoblocked"*.
> Uwaga na jedno: adres to `/prywatnosc`, **nie** `/polityka-prywatnosci`.

### 3.2. Usuwanie danych — jedno z dwóch pól, wybór opisany osobno

Meta wymaga, żeby aplikacja miała drogę przyjęcia żądania usunięcia danych,
i daje **dwa warianty do wyboru**: adres zwrotny (*Data Deletion Callback
URL*) albo adres z instrukcją dla człowieka (*Data Deletion Instructions
URL*). Oba ustawia się w **Settings → Basic**.

**To jest jedyna rzecz z tego rozdziału, która wymaga rozstrzygnięcia, a nie
wpisania. Cały §9 jest o niej** — razem z rekomendacją, którą wybrać dziś
(krótko: **adres z instrukcją**, bo nie wymaga ani linijki kodu i pozwala
przestawić tryb publiczny natychmiast).

### 3.3. Ikona, kategoria, kontakt — po co to Meta

Nie z biurokracji. Ekran zgody Facebooka pokazuje człowiekowi **nazwę
aplikacji i jej ikonę**. Osoba 60+ widzi w tej sekundzie okno Facebooka,
w którym coś prosi o jej dane. Jeśli w tym oknie stoi ikona-zaślepka i nazwa
robocza, część ludzi kliknie „Anuluj" — i będzie miała rację. **Ikona
i nazwa w panelu Meta są elementem interfejsu naszego logowania, tylko
narysowanym na cudzym ekranie.** Nazwa aplikacji ma brzmieć `Kuking`
i nic więcej.

### 3.4. Czego Meta wymaga od nas jako od dostawcy, na zawsze

Z Platform Terms (sprawdzone 10 września; streszczenie z cytatami):
musimy *„Update or delete Platform Data promptly after receiving a request
from us or the User"*, dać ludziom *„an easily accessible and clearly marked
way to ask for their Platform Data to be modified or deleted"*, a polityka
prywatności musi *„clearly explain what data you are Processing, how you are
Processing it, the purposes for which you are Processing it, and how Users may
request deletion of that data"*.

**Pierwsze dwa mamy** — `/ustawienia/twoje-dane` i `kontakt@kuking.pl`
(D-018, D-022). **Trzecie domyka §10 tego dokumentu.**

### 3.5. Weryfikacja biznesowa — czy dotyczy nas, i dlaczego nie wiem

Tu dokumentacja Meta mówi dwie rzeczy, które się nie składają:

| Gdzie | Co mówi |
|---|---|
| Permissions Reference i Access Levels | *„Business Verification is required for all apps making requests for Advanced Access"*, *„Business Verification is required to get Advanced Access"* |
| Access Levels, kilka akapitów dalej | *„All newly created Consumer apps are automatically approved for Advanced Access for the email and public_profile permissions"* |
| Release / Business Verification | *„Apps used exclusively by users with roles directly on the app itself do not require verification"* |

**Moje odczytanie** (i piszę to jako odczytanie, nie jako fakt): weryfikacja
biznesowa jest przypięta do **wniosku** o dostęp zaawansowany, a nasze dwa
uprawnienia dostęp zaawansowany mają **z automatu, bez wniosku** — więc nie
powinna nas dotyczyć. **Nie oprę na tym niczego, czego nie da się cofnąć.**

**Jak rozstrzygnąć za darmo:** to jest ten sam ruch co w §2.2 — kliknij
przełączenie `email` na dostęp zaawansowany i przeczytaj panel. Jeśli
poprosi o weryfikację biznesową, to od tego momentu **jest to procedura
z terminem po stronie właściciela** — i wtedy, i tylko wtedy, pilne staje się
to, o co pierwotnie prosiło zlecenie. Mamy do tego dobrą pozycję: SAMSUFI
sp. z o.o. jest w KRS (0000901262), ma NIP i REGON, czyli dokładnie te dane,
o które takie weryfikacje pytają. **Listy dokumentów i czasu trwania nie
sprawdzałem** — dokumentacja odsyła do Centrum pomocy Business Managera.

---

## 4. Adresy przekierowań

### 4.1. Gdzie to się wpisuje

**App Dashboard → Products → Facebook Login → Settings → Client OAuth
Settings → Valid OAuth Redirect URIs.** Dokumentacja podaje tę ścieżkę
dosłownie: *„Under Products in the App Dashboard's left side navigation menu,
click Facebook Login, then click Settings. Verify the Valid OAuth redirect
URIs in the Client OAuth Settings section."*

Osobno, w **Settings → Basic → App Domains**, wpisuje się same domeny.

### 4.2. Konkretne wartości dla nas

Środowiska bierzemy z `docs/infra/INFRA_DECISION.md`: produkcja to
`kuking.pl` i `www`, staging to `staging.kuking.pl`, a środowiska preview
siedzą pod `*.up.railway.app`.

**Valid OAuth Redirect URIs** — wpisz dokładnie te trzy:

```text
https://kuking.pl/wejdz/facebook/wroc
https://www.kuking.pl/wejdz/facebook/wroc
https://staging.kuking.pl/wejdz/facebook/wroc
```

**App Domains** — wpisz dwie:

```text
kuking.pl
staging.kuking.pl
```

> ### ⚠️ Ścieżka `/wejdz/facebook/wroc` jest PROPOZYCJĄ, nie faktem
>
> Wzięła się z symetrii do Google (`/wejdz/google/wroc`, D-069) i z zasady
> „adresy widoczne dla użytkownika po polsku" (`AGENTS.md` §11). **Kodu
> jeszcze nie ma, więc nikt tej ścieżki jeszcze nie zaimplementował.**
> Meta dopasowuje adres przekierowania **dokładnie**, znak w znak — jeden
> ukośnik różnicy i człowiek zobaczy „URL Blocked" zamiast logowania.
>
> **Dla agenta, który będzie pisał kod: to jest wartość, którą musisz
> dopasować do tego dokumentu, a nie odwrotnie.** Jeśli z jakiegoś powodu
> wybierzesz inną ścieżkę, popraw tutaj **i powiadom właściciela**, bo on
> ma ją już wklejoną w panelu Meta.

### 4.3. HTTPS jest wymagany

Facebook wymaga `https://` w adresach przekierowań; wersji `http://`
w polu nie przyjmie (poza możliwym wyjątkiem dla localhosta — §4.4).
W panelu Facebook Login stoi też przełącznik **Enforce HTTPS**, który dla
nowych aplikacji jest włączony.

**Zaufanie do tego akapitu:** samego zdania „HTTPS jest wymagany" **nie
znalazłem w dokumentacji Meta w formie, którą mógłbym zacytować** — dostępne
strony mówią o adresach przekierowań, nie o schemacie. Potwierdzenie mam ze
źródeł zewnętrznych i z powszechnej praktyki. **Dla nas to jednak pytanie bez
konsekwencji: produkcja i staging chodzą po HTTPS przez Cloudflare i nie mamy
żadnego adresu, który byłby `http://`.**

### 4.4. Dwie rzeczy, które zaskoczą — środowiska preview i localhost

**Środowiska preview (jedno na każdy PR) nie będą miały wejścia
Facebookiem.** Adresy przekierowań u Meta trzeba wpisać co do znaku,
a adresy preview są losowe i efemeryczne — nie da się wpisać `*`. To nie
jest usterka i nie ma sensu tego obchodzić. **Rozwiązanie jest to samo, co
D-069 przewidziało dla Google: bez kluczy w środowisku przycisku po prostu
nie ma na ekranie.** Środowiska preview zachowują się wtedy dokładnie tak
jak przed tą zmianą, i to jest poprawne zachowanie, nie brak funkcji.

**Localhost — niesprawdzone.** Poradniki twierdzą, że Facebook w trybie
deweloperskim dopuszcza `localhost`; **dokumentacja Meta tego nie mówi**.
Jeśli okaże się, że nie dopuszcza, praca lokalna nad kodem nie będzie
zablokowana — testy sprawdzają nasze trasy z podstawioną odpowiedzią
dostawcy, tak jak przy Google (tam też nikt nie woła Google z CI).
**Traktuj jako pytanie do rozstrzygnięcia przy kodzie, nie przy panelu.**

---

## 5. Tryb deweloperski vs publiczny — co da się przetestować bez niczego

To jest najważniejszy rozdział dla harmonogramu, bo mówi, że **kod można
napisać i sprawdzić na żywym Facebooku, nie czekając na cokolwiek po stronie
Meta.**

### 5.1. Co daje tryb deweloperski

Aplikacja w trybie deweloperskim działa **w pełni** — ekran zgody, wymiana
kodu na token, odczyt profilu — ale wyłącznie dla ludzi, którzy mają w niej
**rolę**. Dokumentacja mówi wprost: *„if your app will only be used by people
who have a role on the app itself you do not need to complete any of these
processes"* (o przeglądzie i weryfikacji biznesowej) oraz — o rolach —
*„Testers can grant the app any permission while it is in development, and
all features are active for Testers"*.

Czyli: **w trybie deweloperskim, na kontach z rolą, `email` działa bez
żadnego przełącznika i bez żadnego wniosku.**

### 5.2. Role i kogo w nie wpisać

Role wymienione w dokumentacji: **Administrator**, **Developer**, **Tester**,
**Analytics user** i **Consumer tester**.

| Rola | Co może | Kogo tu wpisać |
|---|---|---|
| Administrator | *„complete access to an app"* — ustawienia, sekrety, role | właściciel, i nikt więcej |
| Developer | *„run, edit, and test the app"* | nikt (agenci nie mają kont na Facebooku) |
| Tester | testuje, ale *„cannot edit any app settings, give other people access to the app or access insights"* | **właściciel z drugiego, prywatnego konta, jeśli takie ma; ktoś z rodziny, kto zgodzi się kliknąć** |

Dwie rzeczy, których dokumentacja wymaga wprost i o których łatwo zapomnieć:
zaproszenie do roli **trzeba przyjąć** (u Consumer testera przez ustawienia
Facebooka), a Meta oczekuje, że tester jest *„your employee or you have an
agreement with them"* — czyli nie zaprasza się przypadkowych ludzi z grupy.

### 5.3. Co z tego wynika dla kolejności prac

```text
DZIŚ, bez czekania na nic:
  właściciel:  zakłada aplikację, wpisuje cztery pola, dodaje adresy
               przekierowań, dodaje siebie jako testera, przekazuje
               identyfikator i sekret do Railwaya
  agent:       pisze kod przeciwko aplikacji w trybie DEWELOPERSKIM
               i sprawdza go na koncie właściciela — pełna droga,
               z żywym ekranem zgody Facebooka

POTEM, jednym kliknięciem:
  właściciel:  przestawia `email` na dostęp zaawansowany (§2.2)
               i App Mode na Live — i od tej chwili droga działa
               dla wszystkich

TYLKO JEŚLI panel poprosi (§3.5):
  właściciel:  weryfikacja biznesowa — i to jest jedyne miejsce,
               w którym coś tu trwa dniami
```

**Nie ma tu żadnej czynności, która blokuje pisanie kodu.** To jest cała
odpowiedź na pytanie z tytułu zlecenia — i różni się od tego, czego zlecenie
się spodziewało.

---

## 6. Ile trwa przegląd i co powoduje odrzucenie

### 6.1. Ile trwa — nie wiem i nie zgaduję

> ## ⛔ 12 września 2026: TO PRZEWIDYWANIE BYŁO BŁĘDNE — przegląd był
>
> **Właściciel złożył wniosek o App Review i Meta go zatwierdziła.** Aplikacja
> jest opublikowana (Live), a wejście kontem Facebooka działa — potwierdza to
> niezależnie produkcja (`/health` → `checks.facebook.ok = true`, przycisk na
> `/login`).
>
> Rozdział niżej twierdzi coś przeciwnego: „przeglądu nie składamy, bo nie
> prosimy o żadne uprawnienie, które go wymaga” — to samo stoi w §2 i w punkcie 7
> listy w `DEPLOYMENT_RUNBOOK.md` KROK 8E.1. **Przewidywanie się nie
> sprawdziło.** Nie wiemy, czy Meta zażądała przeglądu dla samego
> `public_profile` i `email`, czy wymusił go nowy kreator aplikacji — wiemy
> tylko, że **odbył się i był potrzebny**.
>
> **Co z tego wynika dla następnego wdrożenia:** przewiduj czas na przegląd
> aplikacji, nawet jeśli prosisz wyłącznie o uprawnienia podstawowe. Ile on
> trwa — dalej nie wiemy; nasz przypadek to **jedna obserwacja, nie SLA**.
>
> Zdania niżej zostają **jako zapis tego, co przewidywano**, żeby widać było,
> gdzie rozumowanie rozjechało się z rzeczywistością. Nie planuj według nich.


**Meta nie podaje w dokumentacji żadnego terminu ani SLA dla przeglądu
aplikacji.** Jedyna liczba, na którą trafiłem, dotyczyła przeglądu dla
dostawców rozwiązań WhatsApp i do naszego przypadku się nie stosuje.

**W naszym przypadku to jednak pytanie bez treści:** przeglądu nie
składamy, bo nie prosimy o żadne uprawnienie, które go wymaga (§2). Jedyna
procedura z niepewnym terminem, która może nas dotknąć, to weryfikacja
biznesowa — i o niej też nie wiem, ile trwa (§3.5, §13).

### 6.2. Co powoduje odrzucenie — jedno zdanie, ale to najważniejsze

Dokumentacja Meta powtarza pod wieloma uprawnieniami jedno ostrzeżenie
i jest ono dosłowne:

> *„Only select permissions that your app needs to function as intended.
> Selecting unneeded permissions is a common reason for rejection during app
> review."*

**Czyli: najczęstszą przyczyną odrzucenia jest proszenie o za dużo.** To jest
dokładnie ten sam wniosek, do którego niezależnie doszliśmy w §8 i w D-069
(„nie prosimy o awatar wcale") — z tą różnicą, że tutaj wynika on wprost
z dokumentacji tego, kto ocenia.

Reszty typowych przyczyn odrzucenia (nagranie z ekranu, instrukcja testowa,
konto testowe dla recenzenta) **nie sprawdzałem w dokumentacji** — strony
`app-review/requirements` i `app-review/faq` oddają dziś 404. Skoro
przeglądu nie składamy, nie zgaduję.

---

## 7. Trzy rzeczy, których Facebook NIE daje, a D-069 na nich stoi

**To jest jedyny rozdział tego dokumentu, w którym coś jest naprawdę
drogie.** Jeśli właściciel przeczyta tylko jeden rozdział, powinien
przeczytać ten.

### 7.1. Facebook nie mówi, czy adres e-mail jest potwierdzony

**REGUŁA 1 z D-069 brzmi: „`email_verified` od Google jest WARUNKIEM. Bez
niego nie robimy nic — ani logowania, ani rejestracji."** D-069 nazywa ten
warunek najkrótszą znaną drogą przejęcia konta przy „zaloguj się przez…"
i zamyka ją tym jednym polem.

**Facebook takiego pola nie ma.** Pełny opis pola `email` w dokumentacji
Graph API brzmi:

> *„The User's primary email address listed on their profile. This field will
> not be returned if no valid email address is available."*

I to wszystko. **Ani słowa o potwierdzeniu.** W tokenie tożsamości Google
`email_verified` jest osobnym polem, które MUSIMY przeczytać; u Facebooka nie
ma czego przeczytać — dostajemy adres albo nie dostajemy nic.

**Co to znaczy dla bezpieczeństwa.** Facebook faktycznie potwierdza adresy
przy zakładaniu konta, więc w praktyce przychodzący adres zwykle jest
potwierdzony. Ale „zwykle" nie jest warunkiem, na którym wolno oprzeć
logowanie: reguła 1 z D-069 istnieje właśnie po to, żeby nie zgadywać.
**Skutek jest taki, że przy Facebooku regułę 1 trzeba zastąpić czymś innym,
bo nie da się jej spełnić.**

Dwie drogi, które widzę, obie do rozstrzygnięcia przez właściciela — **to nie
jest decyzja, którą wolno podjąć przy klawiaturze**:

1. **Adres z Facebooka NIGDY nie łączy z istniejącym kontem Kuking.**
   Wejście kontem Facebooka rozpoznaje wyłącznie po identyfikatorze konta
   (`user_id`), a gdy tego identyfikatora u nas nie ma — zakłada **nowe**
   konto i traktuje adres jako **niepotwierdzony**, czyli wysyła nasz własny
   list z potwierdzeniem, tak jak przy rejestracji hasłem. Gdy adres jest
   już w bazie przy innym koncie, droga **odmawia** i mówi, co zrobić.
   *Zaleta: reguła 2 z D-069 (przejęcie z wyprzedzeniem) zostaje zamknięta
   bez zmian, bo nigdy nie ufamy adresowi. Cena: człowiek, który ma już
   konto Kuking, kontem Facebooka na nie nie wejdzie — dostanie odmowę,
   której nie zrozumie, dopóki nie przeczyta zdania na ekranie.*
2. **Powiązanie powstaje wyłącznie po zalogowaniu się u nas w inny
   sposób** — na ekranie ustawień, przez „połącz konto Facebooka".
   Wtedy nie zgadujemy niczego: powiązanie robi człowiek, który już dowiódł,
   że jest właścicielem konta Kuking.
   *Zaleta: nie ma żadnego nowego ryzyka przejęcia. Cena: przycisk
   „Wejdź kontem Facebooka" nie zakłada konta i przestaje być tym, po co
   właściciel go chciał — jednym kliknięciem dla człowieka z kampanii.*

**Nie rekomenduję tu jednej z nich, bo to jest wybór między
bezpieczeństwem a tym, po co ta funkcja w ogóle powstaje** — i to jest
decyzja właściciela, wymagająca wpisu w `docs/DECISIONS.md`. Numeru decyzji
świadomie nie zajmuję.

### 7.2. Adres e-mail może w ogóle nie przyjść

Dwie niezależne przyczyny, obie potwierdzone w dokumentacji:

1. **Konto może nie mieć adresu.** *„This field will not be returned if no
   valid email address is available."* Konto na numer telefonu nie ma adresu
   e-mail wcale — a to jest u nas przypadek realny, nie teoretyczny.
2. **Człowiek może odznaczyć adres na ekranie zgody.** Dokumentacja mówi
   wprost, że ludzie *„may completely deny those permissions, not fully
   grant those permissions, or change them later"*, i że sprawdza się to
   przez `GET /{user-id}/permissions`, które oddaje `granted` albo
   `declined`. Ekran zgody Facebooka ma odnośnik do edycji zakresu i część
   ludzi z niego korzysta — świadomie.

**Skutek dla kodu:** droga wejścia Facebookiem musi mieć **osobny, po polsku
napisany ekran dla przypadku „nie dostaliśmy adresu"**, który mówi, co
zrobić, i pozwala podać adres u nas. Adres e-mail jest u nas jedyną drogą
odzyskania konta i jedyną drogą powiadomień — konto bez niego nie ma sensu.

Meta dodaje przy tym ostrzeżenie, o którym trzeba pamiętać przy projektowaniu
tego ekranu: ponowne pytanie o odrzucone uprawnienie wymaga
`auth_type=rerequest`, a *„if someone is actively choosing not to grant a
specific permission to an app they are unlikely to change their mind, even in
the face of continued prompting"*. Czyli: **jedna prośba, jasne zdanie i
droga dalej** — nie pętla dopraszania. To zresztą dokładnie zasada z D-053:
nigdzie martwego przycisku.

To był punkt 2 z issue #259, postawiony tam trafnie i wciąż nierozstrzygnięty.

### 7.3. Interfejs Facebooka wygasa, a Google nie

Google od lat ma dwa niezmienne adresy. Graph API ma **wersje z datą
ważności**:

> *„Each version will remain for at least 2 years from release"*, a *„a
> version will no longer be usable two years after the date that the
> subsequent version is released"*. Po wygaśnięciu *„any calls made to it
> will be defaulted to the next oldest, usable version"*.

Dokumentacja pokazywała 10 września 2026 przykłady na wersji **v25.0**
(`https://www.facebook.com/v25.0/dialog/oauth`,
`GET https://graph.facebook.com/v25.0/oauth/access_token`).

**Skutek: numer wersji w naszym kodzie ma termin ważności i ktoś musi go
pilnować.** To nie jest awaria, która zgłosi się sama — po wygaśnięciu
wywołania **milcząco spadną na starszą wersję**, co jest gorsze od błędu, bo
działa do dnia, w którym przestanie. Wniosek praktyczny: **numer wersji
trzyma się w `config/kuking.php` jako jedna stała, nie wkleja się w kod
w trzech miejscach**, a do przeglądu kwartalnego z `DEPLOYMENT_RUNBOOK.md`
dochodzi jedna pozycja: „sprawdź, czy nasza wersja Graph API jeszcze żyje".

### 7.4. Więc czy to jest droższe, niż wygląda? Tak — ale nie tam, gdzie się baliśmy

Uczciwe podsumowanie, bo o to zlecenie prosiło wprost.

**Taniej, niż zakładaliśmy:** żadnego przeglądu aplikacji, żadnego wniosku,
żadnego czekania dniami. Procedura po stronie Meta to jedno popołudnie
klikania i można ją zrobić dziś (§12).

**Drożej, niż wygląda:**

| Co | Dlaczego to koszt |
|---|---|
| **Brak `email_verified`** | Wywraca regułę 1 z D-069, czyli fundament bezpieczeństwa wejścia przez dostawcę. Wymaga **decyzji właściciela**, nie kodu (§7.1) |
| **Adres może nie przyjść** | Osobny ekran, osobna droga, osobne testy — funkcja, której przy Google nie ma wcale (§7.2) |
| **Wersjonowanie Graph API** | Stały, powracający obowiązek utrzymania; nowa pozycja w przeglądzie kwartalnym (§7.3) |
| **Tabela `tozsamosci_zewnetrzne`** | D-069 mówi wprost: przy drugim dostawcy to jest właściwy kształt, a nie trzecia i czwarta kolumna na `users`. Czyli migracja, przeniesienie danych Google, test, wpis w `docs/DATABASE.md` i rollback (`AGENTS.md` §6) — **zanim** powstanie pierwsza linijka logiki Facebooka |
| **Data Deletion** | Meta wymaga drogi usuwania danych; my mamy własną, odroczoną i odwracalną, i te dwie rzeczy się nie nakładają (§9) |

**Moja ocena:** to nadal jest funkcja na dni, nie na tygodnie — ale
**nierówno podzielone**. Klikanie w panelu Meta jest tanie. Kosztowna jest
jedna decyzja z §7.1 i jedna migracja. **Kolejność, która nie marnuje pracy:
najpierw tabela `tozsamosci_zewnetrzne` (osobny PR, bo jest potrzebna
niezależnie od Facebooka i porządkuje to, co już mamy przy Google), potem
decyzja właściciela z §7.1, i tylko potem logika wejścia.**

### 7.5. A czy przy dwóch dostawcach nie opłaca się już `laravel/socialite`?

**D-069, rozstrzygnięcie 1, odrzuciło pakiet i nazwało próg powrotu:
„drugi dostawca tożsamości (Facebook z issue #258 jako »ewentualnie«) ALBO
pierwsza zmiana po stronie Google, której nie da się obsłużyć zmianą jednej
stałej".** Ten próg właśnie został osiągnięty, więc pytanie trzeba zadać, a
nie pominąć. Przechodzę przez argumenty tamtej tabeli po kolei — bo tego
wymaga zlecenie i bo tak jest uczciwie.

| Argument z D-069 | Czy trzyma się przy Facebooku |
|---|---|
| „~30 klas dostawców, z których używamy jednego" | **Słabnie.** Używalibyśmy dwóch. Nadal nie trzydziestu |
| „PKCE trzeba dopisać na wnętrznościach pakietu" | **Nie dotyczy.** Facebook PKCE nie wymaga; przy Facebooku ten argument nie działa w żadną stronę |
| „`email_verified` w cieniu wygodnego `getEmail()`" | **Wzmacnia się, i to bardzo.** Przy Facebooku `getEmail()` **może oddać `null`** i **nigdy** nie mówi, czy adres jest potwierdzony. Wygodne `getEmail()` jest tu wprost mylące: zachęca do zaufania wartości, której zaufać nie wolno (§7.1, §7.2) |
| „pobiera awatar domyślnie, a my nie prosimy o niego wcale" | **Wzmacnia się.** Sterownik Facebooka w Socialite standardowo sięga po adres zdjęcia profilowego. D-061 mówi, że każde zdjęcie u nas przechodzi przez moderację i przekodowanie; zdjęcie z zewnątrz weszłoby **poza** tę drogę. Pakiet trzeba by więc odchudzać, a nie używać |
| „Aktualizacje: nasze" | **ODWRACA SIĘ i to jest jedyny mocny argument ZA pakietem.** Graph API wygasa co dwa lata (§7.3). Utrzymywana biblioteka podnosi numer wersji za nas; nasz kod wymaga, żeby ktoś o tym pamiętał |
| „jedna zależność na jedną funkcję" (`AGENTS.md` §3) | **Trzyma się.** Rozmowa z Facebookiem to jedno przekierowanie, jeden `POST` po token i jeden `GET` po profil — trzy wywołania `Http::`, nie framework |

**Moje rozstrzygnięcie: zostajemy przy własnym kodzie — ale to jest bliższa
sprawa niż przy Google i jeden argument przemawia przeciw.**

Decyduje to, że dwie rzeczy, które przy Facebooku są **najgroźniejsze**
(adres bez potwierdzenia i adres, którego wcale nie ma), pakiet **ukrywa za
wygodnym akcesorem**, zamiast wymuszać ich obsługę. To ten sam wywód co
w D-069: *„kod, który MUSI zapytać o potwierdzenie adresu, jest
bezpieczniejszy niż kod, który MOŻE"* — tylko przy Facebooku mocniejszy,
bo tam potwierdzenia nie ma wcale i milczenie akcesora jest odpowiedzią
fałszywą.

**Cenę zapisuję wprost, bo jest realna:** wersjonowanie Graph API zostaje
naszym obowiązkiem. Odpowiedzią jest jedna stała w konfiguracji i jedna
pozycja w przeglądzie kwartalnym (§7.3) — nie jest to wymówka, tylko
zobowiązanie.

**Nowy próg powrotu do pakietu:** trzeci dostawca tożsamości ALBO pierwsza
sytuacja, w której podniesienie wersji Graph API wymaga u nas więcej niż
zmiany tej jednej stałej.

---

## 8. Czego świadomie NIE prosimy — i dlaczego

**Prosimy o `public_profile` i `email`. O nic więcej. Nigdy.**

| Czego nie bierzemy | Dlaczego |
|---|---|
| **Zdjęcia profilowego** | D-061: każde zdjęcie u nas przechodzi przez moderację modelem i własny pipeline przekodowania (zdejmuje EXIF, robi warianty). Zdjęcie zaciągnięte z Facebooka weszłoby **poza** tę drogę. D-069 mówi o Google to samo zdanie: **„nie prosimy o awatar wcale"** — i to nie jest tam oszczędność, to jest granica bezpieczeństwa |
| **Listy znajomych** (`user_friends`) | Wymaga przeglądu aplikacji, czyli zamienia tę funkcję z jednodniowej w tygodniową (§2.3). Do niczego u nas nie służy: feed jest chronologiczny i budowany na tym, kogo człowiek sam wybrał (`AGENTS.md` §8). „Znajdź znajomych z Facebooka" to funkcja, której świadomie nie budujemy |
| **Publikowania na Facebooku** | Nie prosimy i nie będziemy. Serwis, który po zalogowaniu może coś napisać na cudzej tablicy, jest dla osoby 60+ powodem, żeby nie kliknąć — i słusznie |
| **Daty urodzenia, płci, miejsca** | Polityka prywatności mówi wprost, że o to nie pytamy i że „żadne z tych pól nie istnieje w formularzach". Wzięcie ich z Facebooka byłoby obejściem własnej obietnicy tyłem |
| **Tokenu długoterminowego** | Ten sam wywód co w D-069 przy tokenie odświeżania: trwałe pełnomocnictwo do cudzego konta, leżące w serwisie, który go do niczego nie używa. Token dostępu żyje u nas przez jedno wywołanie akcji i nigdzie nie jest zapisywany. Po zalogowaniu nie wołamy żadnego API Facebooka — nigdy, ani razu |
| **Żadnego skryptu Facebooka na stronie** | Nie używamy JS SDK ani piksela. Cała rozmowa idzie z naszego serwera. Skutek jest taki, że **na stronach Kuking nie ma ani jednego trackera Facebooka** — i to jest ten sam wybór, który podjęliśmy przy udostępnianiu wpisów (issue #180) |

**Trzy powody, każdy wystarczający sam.** Pierwszy: każde dodatkowe
uprawnienie to punkt na ekranie zgody, na którym osoba 60+ ma prawo się
wystraszyć i wyjść (D-069, rozstrzygnięcie 4). Drugi: to akapit
w polityce prywatności i pozycja w rejestrze czynności — czyli praca,
która wraca przy każdej aktualizacji dokumentów. Trzeci, i to jedyny, który
mówi sama Meta: *„Selecting unneeded permissions is a common reason for
rejection during app review."*

---

## 9. Data Deletion Callback — co wybrać i najważniejsze zdanie w tym dokumencie

### 9.1. Dwa warianty, które daje Meta

| Wariant | Co to jest | Ile kodu |
|---|---|---|
| **Data Deletion Callback URL** | Adres na naszym serwerze. Meta wysyła na niego `POST` z polem `signed_request` podpisanym sekretem aplikacji. Musimy odpowiedzieć JSON-em `{ url: '<url>', confirmation_code: '<code>' }`, gdzie adres prowadzi do strony ze stanem sprawy | trasa, kontroler, weryfikacja podpisu, strona stanu, testy |
| **Data Deletion Instructions URL** | Adres strony, która **człowiekowi** wyjaśnia, jak poprosić o usunięcie danych | **zero** |

Dokumentacja: *„Developers need to specify either a data deletion callback
instruction URL or a callback URL found in Basic Settings for your app"* —
czyli **wystarczy jedno z dwóch**, oba są dopuszczalne, oba ustawia się
w Settings → Basic.

O skutkach zaniechania dokumentacja mówi dwie rzeczy, które warto znać razem:
*„Failure to comply with these requirements may result in your callback being
removed or your app being disabled"*, ale też — w części pytań i odpowiedzi —
*„Nonaction will not result in deactivation; however, we do require developers
to promptly delete user data upon the request of a user"*.

### 9.2. Co wybrać u nas — rekomendacja

**Na dziś: Data Deletion Instructions URL, wskazujący na `https://kuking.pl/prywatnosc`
— czyli na naszą politykę prywatności, której sekcja 7 („Usunięcie konta
krok po kroku") opisuje dokładnie to, o co Meta pyta.**

Nie na `/ustawienia/twoje-dane`, choć to tam się klika przycisk: ta trasa
stoi w grupie `auth`, więc niezalogowany człowiek zobaczyłby ekran
logowania. Adres wpisany u Meta musi otwierać się każdemu.

Trzy powody:

1. **Nie wymaga ani linijki kodu**, więc nie stoi na drodze przestawieniu
   trybu publicznego. Dziś jest to jedyna rzecz, która dzieli nas od
   działającej funkcji, gdy kod będzie gotowy.
2. **Mamy co pokazać, i to lepsze niż średnia.** Ta strona nie jest
   zaślepką: opisuje krok po kroku, co znika, co zostaje, w jakim terminie
   i jak wybrać zakres (D-018, D-022). Meta wymaga *„an easily accessible and
   clearly marked way to ask for their Platform Data to be modified or
   deleted"* — a my mamy przycisk w ustawieniach, nie adres e-mail do
   napisania podania.
3. **Nie budujemy przy tym drogi, która nie powinna istnieć** — §9.3.

**Docelowo, jeśli Meta kiedyś zażąda adresu zwrotnego** (albo jeśli
uznamy, że wypada go mieć): szkic jest w §9.4 i jest gotowy do
zaimplementowania bez ponownego czytania dokumentacji Meta.

### 9.3. NAJWAŻNIEJSZE ZDANIE: „usuń dane" od Facebooka ROZŁĄCZA POWIĄZANIE, nie kasuje konta Kuking

> **Rozstrzygnięcie: żądanie usunięcia danych przychodzące od Facebooka
> usuwa POWIĄZANIE z Facebookiem i nic więcej. Konta Kuking nie kasuje,
> nie stawia na `pending_delete` i nie tyka ani jednego przepisu.**

Pięć powodów. Pierwsze dwa są decydujące.

**1. Zakres żądania to dane OD FACEBOOKA, a tych mamy dokładnie trzy.**
Meta wymaga, żebyśmy *„initiate the deletion of any data your app has from
Facebook about the user"*. Co byśmy mieli od Facebooka? Identyfikator konta
(`user_id`, unikalny dla naszej aplikacji), datę powiązania, i — **raz,
w chwili zakładania konta** — adres e-mail oraz imię jako podpowiedź nazwy.
Pierwsze dwa to całość „danych od Facebooka" i ich usunięcie **jest**
rozłączeniem. Adres e-mail i nazwa profilu przestały być danymi z Facebooka
w chwili, gdy człowiek zaczął z nich korzystać u nas: adres potwierdza
i zmienia u nas, nazwa profilu jest jego adresem w Kuking. Przepisy, wpisy
i zdjęcia nie przyszły z Facebooka ani przez sekundę.

**2. To żądanie NIE JEST dowodem, że poprosił człowiek — i to jest cały
problem.** Podpis HMAC dowodzi jednej rzeczy: że wiadomość przyszła od Meta,
bo tylko Meta i my znamy sekret aplikacji. **Nie dowodzi, że po drugiej
stronie stoi właściciel konta.** Wystarczy dostęp do konta na Facebooku —
a konta na Facebooku są przejmowane ludziom 50+ regularnie, i to jest
w naszej grupie zdarzenie codzienne, nie hipotetyczne. **Gdyby to żądanie
kasowało konto Kuking, zbudowalibyśmy dokładnie tę drogę, której zlecenie się
obawia: obcy człowiek, jednym kliknięciem w cudzym Facebooku, kasuje komuś
dziesięć lat rodzinnych przepisów.** Rozłączenie jest w najgorszym razie
niedogodnością do naprawienia w minutę. Skasowanie konta jest nieodwracalne
w tym samym sensie, w jakim nieodwracalne jest wszystko, o czym mówi D-018.

**3. Żądanie nie umie wyrazić tego, co nasze kasowanie konta ma
obowiązkowo.** D-022 rozstrzygnęło, że **zakres usunięcia wybiera człowiek**,
i uzasadniło to zdaniem, którego nie da się tu obejść: *„anonimizacja jest
naszą oceną, że tak jest lepiej dla społeczności — a to nie jest ocena, którą
wolno robić za kogoś przy jego własnych danych"*. W `signed_request` jest
`user_id`, `algorithm`, `issued_at` i `expires` — **pola na zakres nie ma
i nie będzie**. Wykonanie kasowania z tego żądania znaczyłoby wybranie
zakresu za człowieka, czyli cofnięcie D-022 tyłem, bez decyzji.

**4. Nasze kasowanie jest odroczone i odwracalne, a to żądanie nie ma
adresata dla „zmieniłem zdanie".** 30 dni karencji (D-022) działa, bo
człowiek może wejść na swoje konto i cofnąć. Kto uruchomił żądanie ze strony
Facebooka, na konto Kuking wchodzić nie musi i może nie umieć — a jeśli
właśnie rozłączyliśmy mu jedyną drogę wejścia, to nie umie już wcale.

**5. Prawo tego nie wymaga, a interfejs Meta wprost przewiduje odpowiedź
„zrobiliśmy coś innego".** RODO art. 17 nie zna trybu „usuń konto na
sygnał podmiotu trzeciego" — zna żądanie od osoby, której dane dotyczą, i my
je obsługujemy dwiema drogami (`/ustawienia/twoje-dane` i
`kontakt@kuking.pl`), obiema działającymi po tym rozłączeniu. Meta wymaga
przy tym, żeby adres w odpowiedzi dawał *„a human-readable explanation of the
status of their request, including a legitimate justification for any
refusal"* — czyli **pole na wyjaśnienie, dlaczego zrobiliśmy mniej, jest
częścią protokołu, nie obejściem go.**

#### Jedna rzecz, której nie wolno przy tym zapomnieć

**Konto założone kontem Facebooka nie ma hasła** — tak jak konto z Google
(D-069, rozstrzygnięcie 3: w kolumnie `password` leży skrót wartości
losowej, której nie zna nikt). **Samo rozłączenie zamyka takiemu człowiekowi
jedyne drzwi, jakie zna.** D-069 ostrzega przed tym wprost w „czego
świadomie nie zrobiliśmy".

Dlatego rozłączenie **musi** być parą:

```text
usuń powiązanie z Facebookiem
  +
wyślij na adres konta list „Twoje wejście kontem Facebooka zostało
odłączone — oto link, którym wejdziesz i ustawisz hasło"
  +
napisz to samo na stronie stanu sprawy, tej z odpowiedzi dla Meta
```

Mamy do tego gotowe klocki: logowanie linkiem (D-056) i „Nie pamiętam
hasła". **Rozłączenie bez tego listu jest zamknięciem drzwi, nie
rozłączeniem** — i to jest jedyny sposób, w jaki ta decyzja mogłaby zrobić
komuś krzywdę.

### 9.4. Szkic implementacji — dla agenta, który to kiedyś napisze

**Kodu tu nie ma świadomie** (ani trasy, ani kontrolera, ani testu). To jest
opis kształtu, żeby dało się to napisać bez ponownego czytania dokumentacji
Meta.

**Trasa.** `POST /facebook/usun-dane`. Trzy cechy, każda konieczna:

- **poza `auth`** — żądanie przychodzi od Meta, nie od zalogowanego człowieka;
- **poza ochroną CSRF** — to `POST` z zewnątrz, więc bez wyjątku w
  `bootstrap/app.php` zostanie odrzucony przez token, którego Meta nie ma
  skąd wziąć. **Podpis HMAC jest tu zamiennikiem tokenu CSRF, nie jego
  brakiem**, i tak trzeba to skomentować w kodzie, żeby nikt nie „naprawił"
  tego wyjątku przy porządkach;
- **z limitem zapytań** z `config/kuking.php` (nowy koszyk `limits.facebook_*`,
  ta sama konstrukcja co `limits.google_*` w D-069) — publiczny adres
  przyjmujący `POST`-y bez limitu jest zaproszeniem.

**Weryfikacja podpisu** — dokładnie tak, jak opisuje dokumentacja
`signed_request`:

1. weź `signed_request` z ciała żądania;
2. rozdziel po pierwszej kropce: `<podpis>.<ładunek>`;
3. odkoduj obie części base64 **w wariancie URL** (`-` i `_` zamiast `+`
   i `/`, dopełnienie `=` dopisane samodzielnie);
4. policz `hash_hmac('sha256', <ŁADUNEK JAKO SUROWY NAPIS Z KROKU 2>,
   <sekret aplikacji>, true)` — **na napisie przed odkodowaniem, nie po**;
   to jest najczęstsza pomyłka przy tej weryfikacji i daje błąd, który
   wygląda jak zły sekret;
5. porównaj `hash_equals()`, nigdy `==`;
6. sprawdź, że `algorithm` z ładunku to `HMAC-SHA256`, i odrzuć wszystko
   inne — pole mówiące „jakim algorytmem to sprawdzić" jest polem od
   napastnika, dopóki go nie sprawdzimy;
7. sprawdź `issued_at` i odrzuć żądania stare (ładunek niesie też `expires`).

Ładunek po odkodowaniu ma kształt:

```json
{
  "algorithm": "HMAC-SHA256",
  "expires": 1291840400,
  "issued_at": 1291836800,
  "user_id": "218471"
}
```

`user_id` to identyfikator konta **unikalny dla naszej aplikacji**
(*„The app user's App-Scoped User ID. This ID is unique to the app and cannot
be used by other apps."*) — czyli dokładnie ta wartość, którą trzymamy przy
koncie jako `subject`.

**Co zrobić po weryfikacji:**

1. znajdź powiązanie po `user_id` (w tabeli `tozsamosci_zewnetrzne`, gdy już
   będzie: `provider = 'facebook'`, `subject = user_id`);
2. **gdy nie ma takiego powiązania — odpowiedz normalnie, tym samym
   kształtem i kodem potwierdzenia.** To jest ta sama zamknięta wyrocznia co
   w D-085: odpowiedź „nie mam takiego konta" mówiłaby komukolwiek, kto ma
   dowolny `signed_request`, czy dana osoba jest w Kuking. Strona stanu
   powie po polsku, że nie mamy żadnych danych powiązanych z tym kontem
   Facebooka — bo to jest prawda w obu przypadkach;
3. **rozłącz** — usuń powiązanie, i **tylko** je (§9.3);
4. **wyślij list z linkiem do wejścia i ustawienia hasła** (§9.3, akapit
   o zamkniętych drzwiach). To jest część rozłączenia, nie dodatek;
5. **zapisz w dzienniku audytowym.** `AuditLogEntry::record()`, nazwa
   zdarzenia `facebook.deletion_requested` (schemat `obszar.zdarzenie`, jak
   `account.delete_requested`), w `metadata` **kod potwierdzenia i moment**,
   **nigdy** `signed_request` ani sekretu. Wpisu nie ma na liście
   `AuditLogEntry::NIGDY_NIE_KASUJ` i **nie należy go tam dopisywać** — ta
   lista jest zamknięta i trzyma dowody wykonania żądań RODO, a to nim
   nie jest;
6. **odpowiedz** JSON-em `{ "url": "...", "confirmation_code": "..." }`,
   gdzie `url` prowadzi na naszą stronę stanu, a `confirmation_code` to
   wartość, po której da się tę sprawę u nas odnaleźć.

**Kod potwierdzenia i strona stanu.** Kod ma być losowy i nieodgadywalny —
nie identyfikatorem wiersza. Strona stanu (`GET /facebook/usun-dane/{kod}`)
musi być **dostępna bez logowania**, bo człowiek otwiera ją z Facebooka,
i dlatego **nie wolno jej pokazać ani adresu e-mail, ani nazwy konta, ani
niczego, po czym dałoby się kogoś rozpoznać**. Ma powiedzieć trzy rzeczy,
po polsku, mówiąc CO ZROBIĆ (`AGENTS.md` §5):

1. co zrobiliśmy — odłączyliśmy wejście kontem Facebooka i usunęliśmy
   powiązanie;
2. **czego nie zrobiliśmy i dlaczego** — konto Kuking i treści zostają,
   bo o ich usunięciu decyduje się na koncie, ze wyborem zakresu;
3. jak usunąć konto, jeśli o to właśnie chodziło — `/ustawienia/twoje-dane`
   albo `kontakt@kuking.pl`.

**Czego przy tym nie robić:** nie logować treści `signed_request` (niesie
identyfikator konta Facebooka), nie odpowiadać kodem błędu na zły podpis
w sposób odróżniający „zły podpis" od „nie znam tego konta", nie kolejkować
niczego, co kasuje treści.

**Co przetestować** (dla agenta — z kontrolą ujemną dla każdego, wg
`docs/PULAPKI_TESTOW.md`): poprawny podpis rozłącza i **nie** rusza statusu
konta ani liczby wpisów; podpis policzony innym sekretem jest odrzucony;
`algorithm` inny niż `HMAC-SHA256` jest odrzucony; nieznany `user_id` oddaje
tę samą odpowiedź co znany; strona stanu nie zawiera adresu e-mail ani nazwy
konta (**asercja na wycinku strony, nie na całym HTML-u** — ta pułapka
złapała w tym repozytorium już sześć osób); konto bez hasła po rozłączeniu
dostaje list z linkiem do wejścia.

---

## 10. Do wklejenia w politykę prywatności

> **Ta sekcja jest PROPOZYCJĄ, nie zmianą.** `resources/legal/polityka-prywatnosci.md`
> i `docs/legal/COMPLIANCE.md` są w tej chwili w rękach innego agenta i nie
> tknąłem ich. Poniższe teksty są gotowe do wklejenia po podjęciu decyzji
> o wejściu Facebookiem — **nie wcześniej**, bo polityka prywatności opisuje
> stan serwisu, a nie plany.

> **WKLEJONE 11 września 2026 — ta sekcja jest już historią, nie zadaniem.**
> Logowanie Facebookiem działało wtedy na produkcji, a polityka prywatności
> nie wymieniała ani Facebooka, ani Mety ani razu. Teksty niżej trafiły do
> `resources/legal/polityka-prywatnosci.md` §3 w trzech miejscach (wiersz
> tabeli, akapit „Co dostajemy od Facebooka", zdanie w akapicie o EOG) —
> z dwiema zmianami wobec tego, co tu stoi, obiema wymuszonymi przez kod:
> dopisane jest, że **Facebook nie zawsze oddaje adres e-mail** i że adres
> z Facebooka jest u nas **zawsze niepotwierdzony**, więc nigdy sam nie łączy
> się z istniejącym kontem (D-098), a także że powiadomienie o odebraniu
> dostępu z Facebooka **nie kasuje konta w Kuking**
> (`FacebookDeauthorizeController`, kolumna `dostep_odebrany_at` — czego ten
> runbook nie opisuje wcale). Uwaga z §10.3 o tym, że Meta nie jest
> „podmiotem przetwarzającym", była już na `main` rozwiązana dla Google
> i została rozciągnięta na Facebooka tym samym zdaniem.
> `docs/legal/COMPLIANCE.md` pozostał nietknięty.

### 10.1. Rozstrzygnięcie: który podmiot Meta jest administratorem dla EOG

**Administratorem dla osób z Europejskiego Obszaru Gospodarczego jest
Meta Platforms Ireland Limited, Merrion Road, Dublin 4, D04 X2K5, Irlandia
(CRO 462932) — podmiot z Unii Europejskiej, nie amerykański.**

Podstawa: europejska wersja warunków Facebooka wskazuje jako administratora
*„Meta Platforms Ireland Limited, or any other Affiliate of Meta Platforms,
Inc."*, a Platform Terms mają osobną sekcję **10A „EEA Data Transfers"**,
w której stroną jest **Meta Platforms Ireland Limited** (sekcja 10B, dla
Wielkiej Brytanii, wskazuje Meta Platforms, Inc. — czyli podział podmiotów
jest w tych warunkach świadomy i wypisany).

### 10.2. Rozstrzygnięcie: czy potrzebne jest zdanie o przekazywaniu poza EOG

**TAK — ale w innym kształcie niż przy Cloudflare i OpenAI, i to jest cała
różnica.**

Przy Cloudflare Turnstile i OpenAI **my** wysyłamy dane do spółki
amerykańskiej, więc **my** jesteśmy tym, kto przekazuje dane poza EOG, i to
my musimy wskazać podstawę (EU-US Data Privacy Framework, standardowe
klauzule umowne). Przy Facebooku jest inaczej: naszym kontrahentem jest
**podmiot irlandzki**, więc **przekazania poza EOG po naszej stronie nie
ma** — i nie wolno napisać, że jest, bo to byłoby nieprawdą podaną
w dokumencie prawnym.

**Ale milczenie też byłoby nieuczciwe**, bo Meta przenosi dane w obrębie
własnej grupy do Stanów Zjednoczonych i ma na to własne mechanizmy — sekcja
10A jej Platform Terms istnieje właśnie po to. Człowiek, który czyta naszą
politykę, ma prawo to wiedzieć, nawet jeśli nie jest to nasze przekazanie.

**Dlatego: jedno zdanie, mówiące, jak jest** — że nasz kontrahent jest
irlandzki, że my danych poza EOG nie wysyłamy, i że to, co Meta robi dalej
we własnej grupie, podlega jej własnej polityce, do której odsyłamy. Gotowy
tekst w §10.4.

### 10.3. Wiersz do tabeli dostawców (§3 polityki)

Do wklejenia w tabelę „Komu przekazujemy dane", po wierszu OpenAI:

```markdown
| Facebook (Meta Platforms Ireland Limited) | Wejście na konto kontem Facebooka — potwierdzenie, że to Ty, oraz Twój adres e-mail | Irlandia (Unia Europejska). Meta przetwarza dane także poza EOG w obrębie własnej grupy — patrz akapit o przekazywaniu poza EOG |
```

> **Uwaga dla agenta pracującego nad polityką — jedno zdanie, które warto
> sprawdzić z prawnikiem, i nie zmieniam go sam.** Tabela jest
> zapowiedziana zdaniem „Korzystamy z zaufanych dostawców usług technicznych
> (tzw. **podmioty przetwarzające**)". **Meta przy logowaniu Facebookiem
> podmiotem przetwarzającym prawdopodobnie nie jest** — nie przetwarza danych
> na nasze polecenie, tylko obsługuje własnego użytkownika we własnym celu,
> czyli jest **osobnym administratorem**. Ten sam zarzut dotyczy zresztą
> Google (#258) i pewnie Cloudflare. Najtańsza poprawka to dopisanie do
> zdania wprowadzającego pół zdania w rodzaju *„a przy wejściu kontem Google
> albo Facebooka — z dostawcami, którzy są osobnymi administratorami danych
> swoich użytkowników"*. **Nie wpisuję tego sam, bo to jest zmiana
> w klasyfikacji prawnej całej tabeli, nie dopisanie wiersza.**

### 10.4. Zdanie do akapitu o przekazywaniu poza EOG (§3 polityki)

Do wklejenia w akapit „Przekazywanie danych poza Europejski Obszar
Gospodarczy", po zdaniu o OpenAI, przed zdaniem o kolejnych dostawcach:

```markdown
**Wejście kontem Facebooka jest tu przypadkiem innym niż dwa poprzednie i mówimy o tym wprost:** naszym kontrahentem jest **Meta Platforms Ireland Limited** z siedzibą w Irlandii, czyli w Unii Europejskiej, więc **my nie przekazujemy tych danych poza EOG**. Meta przetwarza jednak dane także w Stanach Zjednoczonych, w obrębie własnej grupy spółek, na podstawie własnych mechanizmów — i robi to jako osobny administrator Twoich danych jako użytkownika Facebooka, na zasadach opisanych w [polityce prywatności Meta](https://www.facebook.com/privacy/policy/), a nie na nasze zlecenie. Jeśli nie chcesz, żeby cokolwiek szło tą drogą, po prostu nie korzystaj z wejścia kontem Facebooka — hasło i wiadomość z linkiem do zalogowania działają dokładnie tak samo.
```

### 10.5. Akapit „Co robi wejście kontem Facebooka i czego nie robi" (§3 polityki)

Do wklejenia po akapicie o Turnstile — w tym samym kształcie i tonie:

```markdown
**Co robi wejście kontem Facebooka i czego nie robi.** Jeśli klikniesz „Wejdź kontem Facebooka", przenosimy Cię na stronę Facebooka, gdzie potwierdzasz, że pozwalasz nam się w ten sposób logować. Od Facebooka dostajemy wtedy **trzy rzeczy: identyfikator, który jest inny dla każdego serwisu i poza Kuking do niczego nie służy, Twoje imię — używamy go raz, żeby podpowiedzieć nazwę konta — oraz Twój adres e-mail**. I to wszystko. **Nie dostajemy listy Twoich znajomych, Twoich zdjęć, Twojej tablicy ani niczego o tym, co robisz na Facebooku.** Nie prosimy o zdjęcie profilowe — zdjęcie w Kuking ustawiasz sam albo nie masz go wcale. **Nigdy nic nie publikujemy na Twoim Facebooku** i nie mamy do tego uprawnienia; gdybyśmy je kiedyś chcieli, Facebook musiałby Cię o to osobno zapytać.

**Na stronach Kuking nie ma żadnego skryptu ani piksela Facebooka.** Cała rozmowa o Twoim logowaniu idzie z naszego serwera do serwera Facebooka, a nie z Twojej przeglądarki — Facebook nie widzi więc, po jakich stronach Kuking chodzisz. Widzi natomiast to, że się u nas zalogowałeś, i tego nie da się ukryć: to jest cena tej wygody i dlatego wejście kontem Facebooka jest u nas jedną z kilku dróg, a nie jedyną. Hasło i wiadomość z linkiem do zalogowania zostają na ekranie logowania obok niego.

Powiązanie z Facebookiem możesz w każdej chwili rozłączyć — napisz na **kontakt@kuking.pl**. Rozłączenie nie usuwa Twojego konta w Kuking ani niczego, co w nim napisałeś; jeśli nie masz jeszcze hasła, wyślemy Ci list z linkiem, którym wejdziesz i ustawisz je sobie, żebyś nie stracił drogi do konta.
```

---

## 11. Zmienne środowiskowe

**Nazwy — tak. Wartości — nigdy w repozytorium.** Identyfikator aplikacji
i sekret wpisuje się **wyłącznie w panelu Railway** (Variables), osobno na
produkcji i na stagingu, i nigdzie ich nie kopiuje.

| Zmienna | Co to jest | Gdzie wziąć |
|---|---|---|
| `FACEBOOK_CLIENT_ID` | identyfikator aplikacji (**App ID**) | Meta: Settings → Basic → App ID |
| `FACEBOOK_CLIENT_SECRET` | **sekret aplikacji** (App Secret) | Meta: Settings → Basic → App Secret → „Show" |
| `KUKING_WEJSCIE_FACEBOOK` | świadome wyłączenie funkcji przy kluczach na miejscu | ustawia się u nas |

Nazwy są proponowane przez symetrię do `GOOGLE_CLIENT_ID`,
`GOOGLE_CLIENT_SECRET` i `KUKING_WEJSCIE_GOOGLE` z D-069 — **agent piszący
kod ma je albo wprowadzić dokładnie tak, albo poprawić tutaj.**

**Dwie rzeczy o sekrecie, obie ważne.** Pierwsza: **sekret aplikacji jest
kluczem, którym weryfikuje się podpis żądania usunięcia danych** (§9.4) —
czyli jego wyciek to nie tylko cudze logowanie, ale też fałszywe żądania
usunięcia. Druga: rotacja jest u Meta jednym przyciskiem („Reset"), więc
w razie wątpliwości **rotuj bez wahania**; kosztem jest jedna zmiana
zmiennej w Railwayu i jeden restart.

**Wyłącznik podwójny, jak przy Google (D-069):** brak `FACEBOOK_CLIENT_ID`
albo `FACEBOOK_CLIENT_SECRET` = **przycisku nie ma na ekranie w ogóle**
(tak zachowują się lokalne środowiska, CI, testy i wszystkie środowiska
preview — §4.4). `KUKING_WEJSCIE_FACEBOOK=false` = świadome wyłączenie
z kluczami na miejscu, bez migracji i bez utraty powiązań.

---

## 12. Tabela czynności właściciela

Kolejność ma znaczenie — czynności są ustawione tak, że każda następna
korzysta z poprzedniej. Kolumna „kiedy" mówi, czy da się to zrobić **dziś**,
czy czeka na coś innego.

| ✔ | # | Czynność | Gdzie | Kiedy |
|---|---|---|---|---|
| ☐ | 1 | Przeczytaj **§7** i powiedz, którą drogę z §7.1 wybierasz (adres z Facebooka nigdy nie łączy z istniejącym kontem / powiązanie tylko z ustawień) | — | **DZIŚ** — i to jest jedyna czynność, która **blokuje kod** |
| ☐ | 2 | Załóż aplikację: przypadek użycia **„Authenticate and request data from users with Facebook Login"**, nazwa **`Kuking`**, adres kontaktowy `kontakt@kuking.pl` | [developers.facebook.com](https://developers.facebook.com) → My Apps → Create App | **DZIŚ** |
| ☐ | 3 | Podłącz portfolio firmowe na **SAMSUFI sp. z o.o.** (§1.3) | kreator, krok „Business" | **DZIŚ** |
| ☐ | 4 | Wypełnij **Settings → Basic**: Privacy Policy URL, Terms of Service URL, App Icon 1024×1024, Business Use, App Category (§3.1) | Settings → Basic | **DZIŚ** |
| ☐ | 5 | Wpisz **Data Deletion Instructions URL**: `https://kuking.pl/prywatnosc` (§9.2) | Settings → Basic | **DZIŚ** |
| ☐ | 6 | Dodaj **App Domains**: `kuking.pl`, `staging.kuking.pl` (§4.2) | Settings → Basic | **DZIŚ** |
| ☐ | 7 | Włącz produkt **Facebook Login** i wpisz **trzy adresy przekierowań** z §4.2, znak w znak | Products → Facebook Login → Settings | **DZIŚ** |
| ☐ | 8 | Dodaj siebie jako **testera** (i przyjmij zaproszenie), żeby dało się sprawdzić kod przed trybem publicznym (§5.2) | App Roles → Roles | **DZIŚ** |
| ☐ | 9 | **Sprawdź przełącznik `email` na dostęp zaawansowany i przeczytaj, co panel powie** (§2.2, §3.5). Zapisz odpowiedź i **przekaż ją** — od niej zależy, czy jest tu jakakolwiek procedura z terminem | App Review → Permissions and Features | **DZIŚ** |
| ☐ | 10 | Przekaż `FACEBOOK_CLIENT_ID` i `FACEBOOK_CLIENT_SECRET` do zmiennych Railwaya (produkcja i staging osobno) — **nie wklejaj ich do issue, PR-a ani rozmowy z agentem** (§11) | Railway → Variables | **DZIŚ**, ale bez pośpiechu: bez kodu i tak nic nie robią |
| ☐ | 11 | Jeśli punkt 9 poprosił o **weryfikację biznesową** — złóż ją. Dane spółki: KRS 0000901262, NIP 5423435334 (§3.5) | Business Settings → Security Center | **DZIŚ, tylko jeśli punkt 9 tego zażądał.** Jedyna czynność w tym dokumencie o nieznanym czasie trwania |
| ☐ | 12 | Przestaw **`email` i `public_profile` na Advanced Access** | App Review → Permissions and Features | **PO** punkcie 9 (i 11, jeśli był) |
| ☐ | 13 | Przestaw **App Mode → Live** | górny pasek panelu | **PO** punktach 4, 5 i 12 **oraz po wdrożeniu kodu na produkcję** — nie wcześniej |
| ☐ | 14 | Sprawdź na żywo z telefonu: wejście kontem Facebooka na `staging.kuking.pl`, potem na `kuking.pl` | telefon, nie komputer | **PO** wdrożeniu |
| ☐ | 15 | Zdecyduj o wpisie w `docs/DECISIONS.md` — numeru nikt Ci nie zabrał, jest wolny | repozytorium | **PO** przeczytaniu tego dokumentu |

**Krótko: dziś da się zrobić dziesięć z piętnastu punktów**, a jedyny
z nich, który może odsłonić procedurę z terminem, to punkt 9 — i zajmuje
minutę.

---

## 13. Czego w tym dokumencie NIE zweryfikowano

> **Aktualizacja z 12 września 2026.** Wdrożenie się odbyło, więc część tej
> listy przestała być otwarta — ale **nie przez sprawdzenie dokumentacji,
> tylko przez przejście procedury**. Zapisuję to osobno, żeby było jasne,
> skąd wiadomo:
>
> | pozycja z tabeli niżej | co rozstrzygnęło wdrożenie |
> |---|---|
> | Czas trwania przeglądu aplikacji | **przegląd był i został zatwierdzony** — założenie „przeglądu nie składamy” z §2 i §6.1 było BŁĘDNE. Ile trwał, dalej nie wiemy: jedna obserwacja to nie SLA. Patrz nota w §6.1 |
> | Czy weryfikacja biznesowa dotyczy nas | aplikacja jest opublikowana, więc przeszła wszystko, czego Meta wymagała — **czy weryfikacja biznesowa była jednym z tych kroków, wie tylko właściciel** |
> | Czy Meta przyjmie `/prywatnosc` jako adres instrukcji usuwania danych | **przyjęła** — aplikacja jest Live, a ten adres był wpisany |
> | Dzisiejszy wygląd kreatora i pełna lista przypadków użycia | właściciel przeszedł przez kreator; jeśli nazwy przycisków się różniły, warto to dopisać |
>
> Pozostałe pozycje (adresy `localhost`, dosłowny wymóg HTTPS, aktualność
> `v25.0`) **zostają otwarte** — wdrożenie ich nie dotknęło.


Zebrane w jednym miejscu, żeby nikt nie wziął tego za sprawdzone. Powtórzenie
listy z góry, bo tam łatwo ją przeczytać i zapomnieć.

| Co | Dlaczego nie i co z tym zrobić |
|---|---|
| **Czas trwania przeglądu aplikacji** | Meta nie podaje SLA w dokumentacji. **Nieaktualne od 12.09.2026: przegląd był i został zatwierdzony** (nota w §6.1). Czasu trwania dalej nie znamy |
| **Czy weryfikacja biznesowa dotyczy nas** | Dokumentacja mówi dwie rzeczy, które się nie składają (§3.5). **Rozstrzyga punkt 9 z tabeli, w minutę** |
| **Dokumenty i czas weryfikacji biznesowej** | Dokumentacja odsyła do Centrum pomocy Business Managera, którego nie czytałem |
| **Czy `localhost` wolno dopisać do adresów przekierowań** | Dokumentacja Meta milczy; twierdzenia pochodzą z forów (§4.4) |
| **Dosłowny wymóg HTTPS w adresie przekierowania** | Nie znalazłem zdania Meta, które dałoby się zacytować. Dla nas bez konsekwencji — nie mamy adresu `http://` (§4.3) |
| **Dzisiejszy wygląd kreatora aplikacji i pełna lista przypadków użycia** | Strona wymienia kroki, ale pod nagłówkiem „Available use cases" nie ma wyliczenia. Nazwy przycisków mogą się różnić (§1) |
| **Czy zdanie o automatycznym dostępie zaawansowanym dla aplikacji typu *Consumer* stosuje się do aplikacji z nowego kreatora** | Cytat mówi o typach, których kreator już nie pokazuje (§2.2). **Też rozstrzyga punkt 9** |
| **Aktualność wersji Graph API `v25.0`** | To numer, który dokumentacja pokazywała w przykładach 10 września 2026. Wersje wygasają (§7.3) — przed pisaniem kodu sprawdź, co jest bieżące |
| **Czy Meta przyjmie `/prywatnosc` jako adres instrukcji usuwania danych** | Adresy są sprawdzone w `routes/web.php` i publiczne, ale **nie wiem, czy Meta stawia stronie instrukcji jakieś dodatkowe wymagania co do treści** — dokumentacja ich nie wymienia (§9.2) |

**Metoda odczytu** — powtarzam, bo to jest ważne dla oceny zaufania:
`curl` do `developers.facebook.com` oddaje przez proxy **HTTP 400**, więc
strony czytano narzędziem, które je renderuje i **streszcza modelem**.
Cytaty w cudzysłowie są dosłowne; zdania bez cudzysłowu są streszczeniem.

---

## 14. Źródła

Wszystkie sprawdzone **10 września 2026**.

**Facebook Login i uprawnienia**

- [Facebook Login Overview](https://developers.facebook.com/docs/facebook-login/overview)
- [Authentication Versus Data Access](https://developers.facebook.com/docs/facebook-login/auth-vs-data/) — *tu stoi zdanie, które przewraca założenie zlecenia*
- [Permissions Reference](https://developers.facebook.com/docs/permissions/)
- [Permissions Reference — email](https://developers.facebook.com/docs/permissions/reference/email)
- [Permissions Reference — public_profile](https://developers.facebook.com/docs/permissions/reference/public_profile)
- [Access Levels](https://developers.facebook.com/docs/graph-api/overview/access-levels) — *Standard vs Advanced, automatyczne zatwierdzenie dla naszych dwóch uprawnień*
- [Manually Build a Login Flow](https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow) — *adresy końcówek, `state`, ścieżka do Valid OAuth Redirect URIs*
- [Requesting and revoking permissions](https://developers.facebook.com/docs/facebook-login/guides/permissions/request-revoke) — *odrzucone uprawnienia, `auth_type=rerequest`*

**Zakładanie aplikacji, tryby, role**

- [Create an App](https://developers.facebook.com/docs/development/create-an-app)
- [App Roles](https://developers.facebook.com/docs/development/build-and-test/app-roles)
- [Release](https://developers.facebook.com/docs/development/release)
- [Business Verification](https://developers.facebook.com/docs/development/release/business-verification)
- [All apps must be set to Live Mode for production use](https://developers.facebook.com/blog/post/2019/09/23/live-mode-for-production-use/) — *cztery pola wymagane do trybu publicznego*
- [App Review](https://developers.facebook.com/docs/app-review)

**Graph API, tokeny, usuwanie danych**

- [Graph API — User](https://developers.facebook.com/docs/graph-api/reference/user/) — *opis pola `email` i identyfikatora zakresowego*
- [Graph API — Versioning](https://developers.facebook.com/docs/graph-api/guides/versioning) — *wersje wygasają po ~2 latach*
- [Graph API — Securing Requests](https://developers.facebook.com/docs/graph-api/securing-requests) — *`appsecret_proof`, „Require App Secret"*
- [Graph API — Debug Token](https://developers.facebook.com/docs/graph-api/reference/debug_token/)
- [Data Deletion Callback](https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback/) — *dwa warianty, `signed_request`, kształt odpowiedzi*

**Prawne, po stronie Meta**

- [Meta Platform Terms](https://developers.facebook.com/terms/) — *obowiązki dostawcy, sekcja 10A „EEA Data Transfers"*
- [Warunki Facebooka dla Europy](https://www.facebook.com/legal/terms/Privacy/Europe/) — *administrator dla EOG*
- [Polityka prywatności Meta](https://www.facebook.com/privacy/policy/)

**Nasze**

- `docs/DECISIONS.md` — **D-018**, **D-022** (usuwanie konta), **D-047**,
  **D-050**, **D-053**, **D-056**, **D-061**, **D-069** (wejście kontem
  Google), **D-085**
- `docs/infra/INFRA_DECISION.md` — domeny środowisk
- `docs/infra/DEPLOYMENT_RUNBOOK.md` — przegląd kwartalny
- `docs/infra/POCZTA_URUCHOMIENIE.md` — wzór tego dokumentu
- `resources/legal/polityka-prywatnosci.md` — tabela dostawców, akapit o EOG
- `docs/PULAPKI_TESTOW.md` — przy testach z §9.4
- issue **#258** (Google), issue **#259** (Facebook), issue **#8** (prawnik
  i umowy powierzenia), issue **#180** (bez trackerów Facebooka)
