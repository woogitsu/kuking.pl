## D-168 · Wejście do Ustawień z telefonu stoi na ekranie profilu, przy „Wyloguj się"

**Data:** 12 września 2026 · PR #419 · issue #344 (część) · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Oba zdania rozstrzygane w
> tym wpisie przestały obowiązywać 12 września 2026 — patrz **D-174**. „Jeden
> odnośnik »Ustawienia«… prowadzący na `settings.accessibility`" prowadzi dziś
> na `settings.index` (`resources/views/pages/profile/show.blade.php:197`), a
> „**ekran-rozdroże `/ustawienia` nie istnieje**" jest nieprawdą — trasa stoi
> w `routes/web.php:827` (`SettingsIndexController`). Komentarz w tym samym
> widoku (linie 180-190) nazywa zmianę po imieniu: „Do 12 września 2026 oba te
> miejsca celowały w `settings.accessibility`… Rozdroże powstało (issue
> #344)". D-174 zapisała odwrócenie u siebie, ale nie postawiła adnotacji
> tutaj, więc do dziś dziennik niósł parę sprzecznych wpisów, oba ze statusem
> „obowiązuje". Nieaktualna jest też sekcja o „koszcie przyjętym świadomie".

### Co było

Na telefonie `.side-nav` jest schowana (`app.css:1173`), awatar w pasku górnym
jest `topbar-desktop-only` i **nie ma pod nim żadnego menu**, a dolny pasek ma
pięć pozycji i szóstej mieć nie może (`AGENTS.md` §5).

**Zmierzone** (Chromium, 390 px, konto zalogowane, pięć ekranów telefonu): słowo
„Ustawienia" jest w DOM każdego z nich, ale `widoczny: false` na **wszystkich
pięciu**.

**Sprostowanie do zgłoszenia:** ekrany ustawień **były** osiągalne — przez „Zmień
swój profil", bo `/ustawienia/profil` niesie `<x-ustawienia-nawigacja>` ze spisem
wszystkich dziewięciu ekranów. Problemem nie była liczba dotknięć (2 → 2), tylko
**brak napisu, którego człowiek szuka**.

### Decyzja

Jeden odnośnik „Ustawienia" w rzędzie akcji własnego profilu, prowadzący na
`settings.accessibility` — tam, gdzie ten sam napis w nawigacji bocznej na
komputerze. Cel dotykowy **141,8 × 50,5 px**, tekst 18 px, bez przewijania w bok
przy 320 px i przy czcionce 200%, działa bez JavaScriptu.

### Koszt przyjęty świadomie

Napis „Ustawienia" prowadzi na ekran o nagłówku **„Czytelność"**. Ekran-rozdroże
`/ustawienia` nie istnieje, a ten sam napis o dwóch celach byłby gorszy niż jeden
cel dziwny. Ratuje to spis „Wszystkie ustawienia" na tym ekranie. Na komputerze
tak jest od dawna; ujednolicenie wymaga rozdroża, czyli osobnej decyzji.

### Czego ta decyzja NIE rozstrzyga

Czy awatar przestaje być `topbar-desktop-only` · czy powstaje ekran-rozdroże
`/ustawienia` · czy rząd akcji na profilu ma wjechać wyżej (zmierzone: stoi na
`y ≈ 913` przy oknie 844 px, więc wymaga przewinięcia — ale to stan **zastany**,
sąsiedni „Zmień swój profil" stał tam już wcześniej) · przełącznik motywu
i licznik powiadomień. Każda to decyzja produktowa, a żadna nie jest potrzebna,
żeby usunąć ślepy zaułek. Dlatego **#344 zostaje otwarte**.

### Co wyszło w kontroli ujemnej i jest warte zapamiętania

Sabotaż „napis schowany pod `<span class="visually-hidden">`" **początkowo nie
oblał testu**. Przyczyna to druga z czterech: *test nic nie mierzył w tym
aspekcie* — szukał odnośnika przez `normalize-space(.)`, a `textContent` zlicza
także tekst schowany dla oka. Czyli test przepuszczał dokładnie to, czego zakazuje
„ikona nigdy sama". Po poprawce odnośnik jest szukany po **widocznym** napisie.

Druga pułapka: `.side-nav` renderuje się w HTML-u **zawsze**, także na telefonie —
chowa ją wyłącznie CSS. Razem z nią w dokumencie jest pozycja „Ustawienia" i
formularz wylogowania, więc asercja po całym dokumencie przechodziłaby nawet nad
cudzym profilem (D-164).

📄 `resources/views/pages/profile/show.blade.php` ·
`tests/Feature/UstawieniaZTelefonuBezZgadywaniaTest.php` ·
D-053 · D-082 · D-107 · D-164 · issue #344
