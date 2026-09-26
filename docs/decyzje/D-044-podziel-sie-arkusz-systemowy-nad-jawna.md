## D-044 · „Podziel się": arkusz systemowy nad jawną listą, bez Messengera w wersji podstawowej

**Data:** 9 września 2026 · **Decyzja właściciela (mechanizm) + pomiar (lista dróg)** ·
Status: **obowiązuje**

### Mechanizm — decyzja właściciela

Na telefonie jeden duży przycisk „Podziel się" otwiera **arkusz systemu**
(`navigator.share`) — tam człowiek widzi swojego Messengera, WhatsAppa
i SMS-y. Na komputerze i wszędzie tam, gdzie tego arkusza nie ma, stoi
**jawna lista** dróg plus adres do skopiowania. **Zawsze widać coś, co
działa** — nigdy pusty przycisk, nigdy „twoja przeglądarka nie obsługuje".

Kolejność warstw wynika z `AGENTS.md` §5 i jest odwrotna, niż podpowiada
intuicja: `navigator.share` jest JavaScriptem z definicji, więc **wersją
podstawową, renderowaną przez serwer, jest jawna lista**, a arkusz jest
ulepszeniem nałożonym na ten sam przycisk.

### Czego NIE ma na jawnej liście i dlaczego (zmierzone 9 września 2026)

| Droga | Wynik pomiaru | Decyzja |
|---|---|---|
| `wa.me/?text=…` | 200, przekierowanie na `api.whatsapp.com/send/?text=…&type=custom_url` | **jest** — działa bez żadnej rejestracji |
| `mailto:?subject=…&body=…` | zawsze | **jest** |
| `facebook.com/sharer/sharer.php?u=…` | 200, przekierowanie na `facebook.com/share_channel/?type=reshare&link=…&app_id=966242223397117` — Facebook podstawia WŁASNY `app_id` | **jest**, opisane uczciwie jako „wstawisz na swoją tablicę" |
| `facebook.com/dialog/send` (Messenger, wyślij osobie) | bez `app_id` kończy się na `facebook.com/login` — okno wysyłania w ogóle się nie otwiera | **nie ma** |
| `fb-messenger://share?link=…` | protokół aplikacji: na komputerze bez Messengera przeglądarka pokazuje błąd nieznanego protokołu | **nie ma** |
| `sms:?body=…` | na telefonie działa, na komputerze najczęściej nie robi nic | **nie ma** |

**Messengera nie da się dziś dać jako linku bez zarejestrowania własnej
aplikacji na Facebooku** (`app_id` + weryfikacja domeny + regulamin Meta).
To jest pytanie do właściciela, nie do agenta — więc funkcja jest zbudowana
tak, że Messenger i tak działa tam, gdzie ludzie z niego korzystają
naprawdę: w arkuszu systemowym na telefonie.

**Decyzja do podjęcia przez właściciela:** czy zakładamy aplikację na
Facebooku, żeby dołożyć „Wyślij w Messengerze" także na komputerze.
Koszt: konto dewelopera Meta, weryfikacja domeny i utrzymanie
`app_id` w konfiguracji. Zysk: jedna droga więcej dla osób, które
Messengera używają na laptopie.

### Przy jakiej treści przycisk się pokazuje

Wyłącznie przy treści, którą zobaczy **ktoś bez konta** — pyta o to
`Gate::forUser(null)->allows('view', …)`, czyli te same `PostPolicy`
i `RecipePolicy`, co całe wejście na stronę. Wpis „tylko dla obserwujących"
i „tylko dla mnie" przycisku nie dostaje **nawet u własnego autora**:
wysłany adres pokazałby odbiorcy 403, a autor byłby przekonany, że coś
wysłał. Autor widzi w tym miejscu jedno zdanie mówiące, co zrobić.

Blokada między dwiema osobami **nie** zmienia tego, co wolno wysłać —
przepis dalej jest publiczny dla całej reszty świata, a zablokowany i tak
nie zobaczy strony, więc do przycisku nie dojdzie.

### Nazwa przycisku

`BRAND_EXTENDED.md` §1.2 zakazuje „Podziel się" jako etykiety **publikacji
dania** (tam jest „Opublikuj"). To jest inna czynność — wysłanie linku poza
serwis — i właściciel wybrał dla niej właśnie „Podziel się", bo tak nazywa
się ta rzecz w Facebooku, czyli tam, gdzie nasza grupa nauczyła się jej
używać. Zakaz z tabeli zostaje w mocy dla publikacji.

**Zmiana wymaga:** wyniku testów z osobami 50+ (#15) mówiącego, że „Podziel
się" przy cudzym przepisie jest mylone z publikowaniem u siebie — albo
decyzji właściciela o założeniu aplikacji na Facebooku (wtedy dochodzi
Messenger).

📄 `app/Domain/Sharing/Udostepnianie.php` ·
`resources/views/components/podziel-sie.blade.php` ·
`resources/js/app.js` · `tests/Feature/PodzielSieTest.php` ·
`docs/FEATURES.md`
