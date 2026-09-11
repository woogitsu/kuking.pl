# KuKing / Kuking.pl — profesjonalny audyt copy UX

**Repozytorium:** `woogitsu/kuking.pl`  
**Gałąź:** `main`  
**Snapshot audytu:** `88e718a440e125bcfc02e2b0537f66f97e136eae`  
**Data audytu:** 2026-09-10  
**Zakres:** teksty widoczne dla użytkownika, mikrocopy, CTA, formularze, onboarding, logowanie/rejestracja, publikacja, przepisy, wyszukiwanie, strony informacyjne, ustawienia, błędy, powiadomienia i e-maile.  
**Cel:** usunąć brzmienie sztuczne, nadmiernie „napisane”, protekcjonalne, nieprecyzyjne lub potencjalnie wprowadzające w błąd, bez zabijania charakteru marki.

---

## 1. Wniosek

Copy Kuking nie ma problemu typowego dla tekstów generowanych przez AI: nie jest generyczne, napompowane ani pełne korporacyjnych sloganów. Ma wyraźny charakter i konsekwentną ideę.

Problem jest odwrotny: **interfejs za często stara się udowodnić, że jest „po ludzku”**.

Najczęściej występują:

- dopowiedzenia, że coś jest „naprawdę”, „po ludzku”, „w porządku”;
- uspokajanie użytkownika nawet wtedy, gdy nie ma czego się bać;
- tłumaczenie intencji projektu zamiast funkcji;
- frazy pisane „głosem autora”, które po kilkunastu ekranach zaczynają dominować nad produktem;
- obietnice czasu, zachowania systemu lub emocji użytkownika bez potrzeby;
- mikrocopy zbyt długie jak na formularz;
- techniczne lub wewnętrzne szczegóły, których użytkownik nie powinien potrzebować;
- kilka realnych sprzeczności informacyjnych.

### Najważniejsza rekomendacja

Nie robiłbym z Kuking chłodnego, generycznego produktu. Zostawiłbym ciepły ton i najważniejsze rozpoznawalne elementy marki, ale zastosował regułę:

> **Jedno zdanie charakteru marki na ekran. Reszta ma być przezroczysta i funkcjonalna.**

Na landing page charakter marki może być mocny.  
W feedzie i profilach — lekki.  
W formularzach, ustawieniach, błędach i mailach technicznych — prawie niewidoczny.

---

## 2. Co już działa dobrze

### 2.1. Marka ma własny głos

`docs/brand/COPY_STYLE.md` jest znacznie lepszy niż typowy „tone of voice”. Dobrze rozróżnia landing, feed, formularze, błędy i komunikację prawną.

Kierunek „ciepło, prosto, bez reklamowego zadęcia” warto zachować.

### 2.2. Produkt ma konkretne wyróżniki

Najmocniejsze komunikaty nie wymagają wymyślania marketingu:

- zdjęcia prawdziwego gotowania;
- własne i rodzinne przepisy;
- historia pochodzenia przepisu;
- „Ugotowałem” jako realny sygnał użycia przepisu;
- chronologiczne treści zamiast rankingu popularności;
- eksport własnych danych;
- prywatność wpisów;
- brak potrzeby sztucznego „pompowania” serwisu wygenerowanymi przepisami.

To jest lepszy materiał marketingowy niż slogany typu „Gotujemy po swojemu”.

### 2.3. Dobre intencje dostępności

Widać świadome projektowanie dla osób mniej technicznych i starszych. To jest wartość.

Trzeba jednak pilnować ważnego rozróżnienia:

> **dostępność = prostsza decyzja i jasna etykieta, nie większa liczba tłumaczących zdań.**

---

## 3. Problemy najwyższego priorytetu

### P0 — poprawić przed dalszym polerowaniem tonu

| Miejsce | Obecny problem | Dlaczego to ważne | Rekomendacja |
|---|---|---|---|
| `components/recipe-wizard.blade.php` | „To zostaje w rodzinie.” przy historii/źródle przepisu | Może być nieprawdziwe, jeśli przepis jest publiczny | „Tu możesz zapisać, skąd pochodzi przepis i jak do Ciebie trafił.” |
| `components/recipe-wizard.blade.php` | „Podgląd: tak zobaczą to inni” | Nieprawdziwe dla przepisu prywatnego | „Podgląd przepisu” / „Sprawdź, jak wygląda przepis przed zapisaniem lub publikacją.” |
| `components/recipe-wizard.blade.php` | zachęta „zapisz szkic” obok autosave | Dwa różne modele działania | Jeśli autosave jest pewny: „Szkic zapisuje się automatycznie.” |
| `auth/register.blade.php` | e-mail „Potrzebny tylko wtedy, gdy zapomnisz hasła” | Repo ma logowanie linkiem i ustawienia e-mail; komunikat wygląda na niepełny | „Adres nie jest publiczny. Służy do logowania, odzyskiwania dostępu i wiadomości dotyczących konta.” — zweryfikować dokładny zakres wysyłek |
| `errors/419.blade.php` | najpierw „nic nie przepadło”, a przy dużym formularzu później „nie wszystko udało się przenieść” | Wewnętrzna sprzeczność w krytycznym momencie | Treść nagłówkową uzależnić od `$formularz->obciete`; nigdy nie obiecywać pełnego odzyskania, jeśli mogło dojść do obcięcia |
| `components/recipe-wizard.blade.php` | „To najczęściej czytana część przepisu” | Twierdzenie analityczne bez wskazanego pomiaru | „Tu możesz zapisać pochodzenie i historię przepisu.” |
| landing / logowanie | „Zajmie minutę” / podobne obietnice | Niepotrzebna, mierzalna obietnica, która może być fałszywa | Usunąć. Pokazać liczbę pól albo po prostu działanie |
| `pages/search.blade.php` | akcja „Obserwuj” dla gościa prowadząca do rejestracji | Etykieta sugeruje natychmiastowe wykonanie akcji | „Załóż konto, aby obserwować” albo po kliknięciu jasno zachować zamiar po logowaniu |
| `components/kuking-board.blade.php` | „Jutro będzie tu ktoś inny” | Obietnica konkretnego harmonogramu | Zostawić tylko jeśli rotacja jest gwarantowana; inaczej „Pokazujemy tu różne osoby z Kuking.” |
| `settings/security.blade.php` | przykład hasła `zielonapietruszkarano` | Uczy przewidywalnego wzorca bez separatorów i może wyglądać jak rekomendacja konkretnej konstrukcji | „Użyj długiej, unikalnej frazy — najlepiej co najmniej kilku słów, których nie używasz w innych serwisach.” |

### P1 — duży wpływ na profesjonalny odbiór

| Miejsce | Obecny tekst / wzorzec | Problem | Lepszy kierunek |
|---|---|---|---|
| landing | „Gotujemy po swojemu.” | generyczny slogan | usunąć albo „Domowe gotowanie, bez wyścigu.” |
| landing | „ludzi, którzy gotują naprawdę” | sztuczne dzielenie na „prawdziwych” i innych | „dla osób, które chcą dzielić się codziennym gotowaniem” |
| landing | „Nic więcej nie musisz.” | niepotrzebne uspokajanie | usunąć |
| landing | „Zostań kuKINGiem — to darmowe” | żart marki w funkcjonalnym CTA + dwie informacje naraz | „Załóż darmowe konto” |
| landing | „Przepis jest dobry wtedy, kiedy ktoś go ugotował” | atrakcyjne, ale logicznie zbyt absolutne | „Najlepsze potwierdzenie przepisu? Ktoś naprawdę go ugotował.” albo neutralniej: „Zobacz, kto ugotował z Twojego przepisu.” |
| landing | „firma, która czeka na Twoje dane” | defensywne, dramatyczne | podać konkretną politykę: brak reklam, eksport danych, ustawienia widoczności |
| landing | „Na to powiadomienie się tutaj czeka.” | dopisywanie emocji użytkownikowi | usunąć |
| discover | „Bez żadnego układania przez komputer.” | dziecięce / antytechnologiczne | „Wpisy są ułożone chronologicznie — od najnowszych. Bez rankingu popularności.” |
| about | „Ludzie, nie treści.” | slogan brzmiący efektownie, ale nieprecyzyjnie | „Codzienne gotowanie, nie rankingi.” |
| about | „bez liczników w twarz” | nienaturalny idiom | „bez wyścigu na liczby” |
| contact | „Po drugiej stronie jest człowiek, nie automat.” + kolejne podobne zdania | powtarzana deklaracja zaufania zaczyna brzmieć jak obrona | powiedzieć raz: „Wiadomość trafia bezpośrednio do osoby prowadzącej Kuking.” |
| contact | „Lepiej dwa razy niż wcale” | rubaszne, nie pomaga wykonać zadania | usunąć |
| contact | „nie będziemy go udawać” | defensywne | usunąć |
| contact | „inna droga i prowadzi do innej kolejki” | ujawnia wewnętrzny model systemu | powiedzieć, którego formularza użyć i dlaczego |
| login | „Klikasz — i jesteś w środku.” | styl autora zamiast instrukcji | „Po kliknięciu zalogujesz się bez wpisywania hasła.” |
| onboarding | „Jedno i drugie jest w porządku.” | interfejs ocenia decyzję użytkownika | po prostu pokazać dwie akcje |
| onboarding | „to pomoc…, a nie kolejny obowiązkowy krok” | tłumaczenie intencji UX | „Ten krok jest opcjonalny.” albo samo „Pomiń” |
| komentarze | „Napisz normalnie, po ludzku” | brzmi jak polecenie copywritera, nie pomoc pola | „Napisz komentarz” |
| 404 | „To nie jest Twoja wina i nic się nie zepsuło.” | za mocno „terapeutyczne” przy zwykłym 404 | „Adres może być niepełny albo strona została usunięta.” |
| security | „u wnuka, w bibliotece albo u znajomych” | tekst projektowany *o* starszej osobie zamiast dla niej | „na wspólnym albo cudzym urządzeniu” |
| mail logowania | „kliknij zielony przycisk” | treść zależna od wyglądu; słabsza dostępność | „kliknij przycisk „Zaloguj mnie w Kuking”” |
| mail logowania | „i już będziesz w środku” | zbędna stylizacja | „Na następnej stronie potwierdź logowanie.” |
| mail logowania | „odpisuje człowiek” | kolejna deklaracja „human” | „Jeśli masz problem, napisz na…” |

---

## 4. Główny problem systemowy: „przeprojektowana ludzkość”

Wiele tekstów pojedynczo brzmi sympatycznie. Problem ujawnia się dopiero w skali całej aplikacji.

Powtarzają się konstrukcje:

- „naprawdę”;
- „po ludzku”;
- „nic nie musisz”;
- „jedno i drugie jest w porządku”;
- „zawsze możesz”;
- „nic nie zginie”;
- „to wszystko”;
- „kliknij i jesteś w środku”;
- „nie będziemy udawać”;
- „człowiek, nie automat”;
- wyjaśnianie, że krok „nie jest obowiązkiem”;
- mówienie użytkownikowi, co powinno być „najmilsze”, „najważniejsze” albo „na co się czeka”.

To tworzy charakter w jednym miejscu. W trzydziestu miejscach tworzy manierę.

### Nowa reguła

Dla każdego tekstu zadać trzy pytania:

1. **Czy pomaga podjąć decyzję lub wykonać działanie?**
2. **Czy podaje informację, której użytkownik nie może łatwo wywnioskować z UI?**
3. **Czy jest to jedno z nielicznych miejsc, gdzie świadomie budujemy charakter marki?**

Jeśli odpowiedź na wszystkie trzy brzmi „nie” — tekst usunąć.

---

## 5. Reguła copy dla całej aplikacji

Najlepszy model dla Kuking:

> **stan / informacja → działanie → skutek**

Przykład źle:

> „Nie martw się — nic nie zginie. To tylko pomoc, a nie kolejny obowiązkowy krok. Zawsze możesz zrobić to później.”

Przykład dobrze:

> „Szkic zapisuje się automatycznie. Możesz wrócić do niego później.”

Przykład źle:

> „Po drugiej stronie jest człowiek, nie automat. Nie musisz pisać ładnie.”

Przykład dobrze:

> „Wiadomość trafia bezpośrednio do osoby prowadzącej Kuking.”

---

## 6. Landing page — rekomendowany wariant

### Hero

**H1**  
`Pokaż, co dziś ugotowałeś`

To jest rozpoznawalny rdzeń marki i warto go zostawić.

**Eyebrow:** najlepiej usunąć.  
Jeśli koniecznie ma zostać:

`Domowe gotowanie, bez wyścigu.`

**Lead — rekomendacja**

> Dodawaj zdjęcia swoich dań, zapisuj przepisy i zachowuj ich historię. Zobacz, kto ugotował z Twojego przepisu.

**CTA główne**

`Załóż darmowe konto`

**CTA drugie**

`Zobacz, co jest w Kuking`

### Jak to działa

1. **Dodaj zdjęcie lub przepis.**  
   Pokaż to, co naprawdę było dziś na stole.

2. **Napisz tyle, ile chcesz.**  
   Jedno zdanie wystarczy; przepis możesz uzupełnić później.

3. **Zobacz, kto ugotował z Twojego przepisu.**  
   Zdjęcia i komentarze pojawią się przy przepisie.

Uwaga: drugie zdania można jeszcze skrócić, jeśli obrazki/ikony wystarczają.

### „Ugotowałem”

**H2**

`Zobacz, kto ugotował z Twojego przepisu`

**Body**

> Przy przepisie mogą pojawić się zdjęcia i komentarze osób, które go przygotowały. Zamiast samej liczby reakcji widzisz prawdziwy efekt.

**Dodatkowo**

> Kuking nie ma tabel liderów ani rankingu popularności wpisów.

### Feed publiczny

**H2**

`Najnowsze z Kuking`

**Lead**

`Ostatnio dodane dania i przepisy.`

### Dane / prywatność

**H2**

`Twoje przepisy i zdjęcia możesz pobrać`

**Body**

> W każdej chwili możesz przygotować paczkę ze swoimi zdjęciami, wpisami i przepisami.

> Przy każdym wpisie wybierasz, kto go widzi: wszyscy, obserwujący albo tylko Ty.

> Kuking nie wyświetla reklam między wpisami.

Nie używać: „firma, która czeka na Twoje dane”. To tworzy niepotrzebnego przeciwnika i brzmi jak argument z reklamy VPN.

### Rejestracja na końcu strony

**H2**

`Załóż konto`

**Body**

> Do rejestracji potrzebujesz nazwy, nazwy użytkownika, adresu e-mail i hasła. Nie wymagamy numeru telefonu ani daty urodzenia.

**CTA**

`Załóż darmowe konto`

**Link**

`Masz konto? Zaloguj się.`

Nie obiecywać „minuty”, jeśli nie ma pomiaru i gwarancji.

---

## 7. Rejestracja

### Rekomendowany ekran

**H1**

`Załóż konto`

**Lead**

> Podaj podstawowe dane. Po rejestracji możesz od razu korzystać z Kuking; wybór zainteresowań i osób do obserwowania jest opcjonalny.

### Pola

**Jak mamy Cię nazywać?**  
Proponuję bardziej neutralnie:

`Nazwa wyświetlana`

Help:

`Może to być imię, przezwisko lub inna nazwa widoczna dla innych.`

**Nazwa użytkownika**

Help:

`Utworzymy z niej adres Twojego profilu. Polskie litery i spacje zamienimy automatycznie.`

Nie trzeba tłumaczyć sluga bardziej szczegółowo na tym etapie.

**E-mail**

Help — po sprawdzeniu faktycznego wykorzystania adresu:

`Adres nie jest publiczny. Służy do logowania, odzyskiwania dostępu i wiadomości dotyczących konta.`

Obecne „potrzebny tylko wtedy, gdy zapomnisz hasła” należy usunąć, jeśli adres służy również do linków logowania albo innych wiadomości.

**Hasło**

`Co najmniej 10 znaków. Użyj długiego, unikalnego hasła lub frazy, której nie używasz w innych serwisach.`

Nie podawałbym `zielonapietruszkarano` jako wzoru.

**Błędy formularza**

Zamiast:

`Na górze jest napisane...`

użyć:

`Sprawdź oznaczone pola.`

---

## 8. Logowanie i link do logowania

### Zwykłe logowanie

**H1**

`Zaloguj się`

Pole:

`E-mail lub nazwa użytkownika`

Help można usunąć. Etykieta już tłumaczy obie możliwości.

### Sekcja logowania bez hasła

**H2**

`Zaloguj się bez hasła`

**Body**

> Podaj adres e-mail. Wyślemy jednorazowy link, który pozwoli zalogować się bez wpisywania hasła. Twoje hasło się nie zmieni.

**CTA**

`Wyślij link do logowania`

### Strona wysłania linku

**H1**

`Zaloguj się linkiem`

**Body**

> Podaj adres e-mail przypisany do konta. Wyślemy jednorazowy link do logowania.

**Informacja techniczna**

`Link jest ważny 30 minut i działa jednorazowo.`

Nie pisać o kolorze przycisku. Nie pisać „klikniesz i jesteś w środku”.

---

## 9. E-mail z linkiem do logowania

Ten e-mail ma słusznie wyjaśniać pochodzenie wiadomości, ważność linku, drugi krok i sytuację osoby, która nie prosiła o logowanie. To są informacje bezpieczeństwa i warto je zachować.

Skróciłbym natomiast język.

### Rekomendowany tekst

**Nagłówek**

`Zaloguj się w Kuking`

**Treść**

> {{ displayName }},  
> otrzymujesz tę wiadomość, ponieważ ktoś poprosił o link do logowania na konto Kuking przypisane do tego adresu e-mail.

> Jeśli to Ty, użyj przycisku poniżej. Nie musisz wpisywać hasła.

**CTA**

`Zaloguj mnie w Kuking`

> Na następnej stronie potwierdź logowanie przyciskiem „Zaloguj mnie”.

> Link jest ważny {{ waznoscTekst }} i działa jednorazowo. Jeśli wygaśnie, możesz poprosić o nowy na stronie logowania.

> Jeśli nie prosisz o ten link, zignoruj wiadomość. Samo otrzymanie e-maila nie zmienia hasła do konta.

> Jeśli przycisk nie działa, skopiuj poniższy adres i wklej go w pasku adresu przeglądarki:  
> {{ linkUrl }}

> Masz problem z logowaniem? Napisz na {{ contact_email }}.

### Co usunąć

- „zielony przycisk”;
- „i już będziesz w środku”;
- „na tym samym telefonie albo na komputerze, to bez znaczenia”;
- „odpisuje człowiek”.

---

## 10. Odzyskiwanie hasła

Obecny ekran jest stosunkowo dobry.

### Zmiany

`Podaj adres e-mail, na który jest założone konto`

→

`Podaj adres e-mail przypisany do konta.`

`Wyślij link`

→

`Wyślij link do zmiany hasła`

Jeśli jest wskazówka o Gmailowych „Ofertach”, uogólnić:

`Jeśli wiadomości nie ma w skrzynce odbiorczej, sprawdź spam i inne foldery.`

---

## 11. Onboarding

Obecny onboarding za często mówi użytkownikowi, że może coś pominąć i że jego wybór jest „w porządku”. Sama obecność przycisku „Pomiń” jest wystarczającą informacją.

### Tekst wspólny

> Konto jest gotowe. Te kroki pomogą ustawić stronę główną; możesz je pominąć.

Wystarczy raz na początku. Nie powtarzać na kolejnych ekranach.

### Zainteresowania

**H1**

`Co chcesz obserwować?`

**Body**

`Wybierz tematy, które chcesz widzieć na stronie głównej.`

Ewentualnie:

`Na początek pokażemy wpisy z wybranych tagów.`

### Ludzie

**H1**

`Kogo chcesz obserwować?`

**Body**

`Wybierz osoby, których wpisy chcesz widzieć na stronie głównej.`

Karta wyszukiwania:

**H2** `Szukasz konkretnej osoby?`

`Wpisz imię lub nazwę użytkownika.`

Błąd:

`Wpisz co najmniej 2 znaki.`

Pusty stan:

`Nie mamy jeszcze osób do polecenia.`

### Koniec

**H1**

`Konto jest gotowe`

**Body**

`Możesz dodać pierwsze zdjęcie albo przejść do strony głównej.`

CTA:

`Dodaj pierwsze zdjęcie`

Drugie:

`Przejdź do strony głównej`

Usunąć „Jedno i drugie jest w porządku”.

---

## 12. Feed i odkrywanie

### Composer

Obecne:

`Dodaj zdjęcie tego, co ugotowałeś`

jest dobre.

`Nie musi być ładne — ma być prawdziwe.`

To jest mocna linia marki, ale **nie powinna występować na wielu ekranach**. Zostawiłbym ją w jednym strategicznym miejscu: landing albo pierwszy composer.

### Zakładki

`Obserwowani`

→

`Od obserwowanych`

To naturalniej opisuje źródło wpisów.

`Świeżo z Kuking`

może zostać jako branded label, ale `Najnowsze` jest bardziej natychmiastowe.

### Discover

Obecne:

`Bez żadnego układania przez komputer.`

→

`Wpisy są ułożone chronologicznie — od najnowszych. Bez rankingu popularności.`

To mówi dokładnie, co się dzieje, zamiast budować „komputer” jako przeciwnika.

---

## 13. Wyszukiwanie

### Pytania retoryczne

Własny style guide praktycznie zabrania pytań retorycznych poza głównym sloganem. Tymczasem pojawiają się konstrukcje typu:

- `Może to Ty go dodasz?`
- `Nie wiesz, od czego zacząć?`

Nie są katastrofalne, ale robią z pustych stanów małe reklamy.

Lepsze:

`Nie znaleźliśmy takiego przepisu.`

`Możesz dodać własny przepis.`

albo:

`Spróbuj wyszukać składnik, nazwę dania lub użytkownika.`

### Akcja dla gościa

Jeśli „Obserwuj” nie może zostać wykonane bez konta, CTA powinno pokazywać warunek:

`Załóż konto, aby obserwować`

albo produkt powinien zapamiętać zamiar i po logowaniu dokończyć akcję.

---

## 14. Strona „O Kuking”

Obecny tekst ma dobry materiał, ale za dużo miejsca poświęca historii innych serwisów i ryzyku utraty danych.

### Rekomendowany lead

> Kuking służy do dzielenia się codziennym gotowaniem, zapisywania przepisów i zachowywania ich historii.

### „Po co”

> Przepisy i zdjęcia potrafią zniknąć razem z zeszytem, telefonem albo serwisem, w którym były zapisane. Dlatego w Kuking możesz pobrać własne dane wraz ze zdjęciami.

Jeśli historia Garnek.pl / Durszlak ma znaczenie dla genezy projektu, przenieść ją niżej do sekcji `Skąd ten pomysł`, zamiast robić z niej główny argument.

### Wartości — rekomendowane etykiety

- `Codzienne gotowanie, nie rankingi`
- `„Ugotowałem” zamiast wyścigu na polubienia`
- `Przepisy wraz z ich historią`
- `Chronologiczne treści`
- `Możliwość pobrania własnych danych`

### AI

Zamiast szerokiej deklaracji anty-AI:

`Nie generujemy automatycznie przepisów, żeby sztucznie wypełniać serwis.`

To jest konkretne i wiarygodne.

---

## 15. Pomoc

Help center powinien być najbardziej użytkowy ze wszystkich stron.

### Do usunięcia lub osłabienia

`To najważniejszy przycisk w Kuking.`

→ bez oceny; wyjaśnić działanie.

`i to jest tu najmilsza rzecz.`

→ usunąć.

`Odpisujemy po ludzku i naprawdę czytamy każdą wiadomość.`

→

`Wiadomość trafia do osoby prowadzącej Kuking.`

### Zgłoszenia

Zamiast tłumaczyć, że „to ta sama / inna droga”, podać regułę:

- problem z treścią → `Zgłoś` przy treści;
- podejrzenie treści niezgodnej z prawem → formularz prawny;
- problem techniczny / konto / pomysł → `Napisz do nas`.

---

## 16. „Napisz do nas”

To jeden z ekranów najbardziej wymagających skrócenia.

### Rekomendowany wariant

**H1**

`Napisz do nas`

**Lead**

> Wiadomość trafia bezpośrednio do osoby prowadzącej Kuking. Opisz problem, pytanie lub pomysł. Konto nie jest wymagane.

### Osobna karta zgłoszeń

**H2**

`Chcesz zgłosić treść?`

**Body**

> Do zgłoszenia wpisu, przepisu lub komentarza użyj przycisku „Zgłoś” przy tej treści.

> Jeśli uważasz, że treść jest niezgodna z prawem, użyj formularza zgłoszenia treści niezgodnej z prawem.

Nie pisać o „kolejkach” ani o wewnętrznym sposobie routowania zgłoszeń.

### Help pod wiadomością

`Jeśli coś nie działa, napisz, co próbujesz zrobić i na jakim urządzeniu.`

### Po formularzu

`Odpowiemy na adres podany w formularzu lub przypisany do konta.`

Jeżeli właściciel chce ustawić oczekiwanie czasowe:

`Kuking prowadzi jedna osoba, dlatego odpowiedź może nie być natychmiastowa.`

To jest lepsze niż obiecywanie, że „każda zostanie przeczytana”.

---

## 17. Dodawanie wpisu

### Intro

`Wybierz zdjęcie, dodaj kilka słów i opublikuj wpis.`

Usunąć `To wszystko.`

### Upload

`Wybierz zdjęcie z urządzenia lub zrób nowe.`

`Maksymalny rozmiar jednego pliku: X MB.`

Nie opierać instrukcji na dokładnych nazwach systemowych typu „Galeria”, jeśli mogą się różnić między Androidem, iOS i przeglądarką.

### EXIF/GPS

Obecne sformułowanie jest poprawne technicznie, ale dla zwykłego użytkownika zbyt techniczne.

Lepsze:

`Przed publikacją usuwamy ze zdjęć dane o lokalizacji i informacje techniczne.`

Można dodać link `Jak to działa?`, jeśli ktoś chce szczegółów o EXIF.

---

## 18. Kreator przepisu

To obszar z największą liczbą mikrocopy i dlatego najbardziej odczuwa nadmiar tłumaczeń.

### Krok 1

**Zamiast**

`Jeśli nie masz teraz czasu — zapisz szkic. Nic nie zginie...`

**użyć**

`Na tym etapie wymagana jest tylko nazwa przepisu. Szkic zapisuje się automatycznie.`

Jeśli autosave ma wyjątki, opisać je dokładnie. Nie tworzyć obietnicy absolutnej.

### Krótki opis

`Napisz 1–2 zdania: kiedy przygotowujesz to danie albo co warto o nim wiedzieć.`

### Pochodzenie

**H2**

`Pochodzenie przepisu`

**Lead**

`Tu możesz zapisać, skąd pochodzi przepis i jak do Ciebie trafił.`

**Historia**

Label:

`Historia przepisu (opcjonalnie)`

Help:

`Możesz dopisać, skąd go znasz, kiedy się go przygotowuje i co jest w nim ważne.`

Usunąć `To zostaje w rodzinie`, ponieważ widoczność wynika z ustawień przepisu.

### Źródło zewnętrzne

`Jeśli przepis pochodzi z innej strony lub publikacji, podaj źródło.`

W kwestii kopiowania treści zalecam osobny przegląd prawny. Zdanie „Nie publikuj cudzych treści bez zgody” jest zbyt szerokim uproszczeniem prawa autorskiego jak na komunikat produktu.

### Składniki

`Dodaj składniki w kolejności, w jakiej będą potrzebne.`

`Bez ilości` może zostać jako kontrolka, ale help skrócić do:

`Włącz, jeśli dokładna ilość nie jest potrzebna.`

Akcja:

`Usuń składnik`

zamiast generycznego `Usuń ten wiersz`.

### Przygotowanie

`Opisz przygotowanie krok po kroku. Jeden krok = jedna czynność.`

Akcja:

`Usuń krok`

### Minutnik

`Opcjonalnie. W trybie gotowania pokażemy minutnik dla tego kroku.`

Nie opisywać przyszłego interfejsu w kilku zdaniach.

### Podgląd

**H2**

`Podgląd przepisu`

**Body**

`Sprawdź przepis przed zapisaniem lub publikacją. Wróć do poprzedniego kroku, jeśli chcesz coś zmienić.`

Nie pisać `tak zobaczą to inni`, bo może to być przepis prywatny.

### Walidacja

Zamiast:

`Ta liczba porcji jest nierealna.`

podać ograniczenie:

`Maksymalna liczba porcji to 999.`

Zasada ogólna: **walidacja ma opisywać regułę systemu, nie oceniać użytkownika.**

---

## 19. „Ugotowałem”

Mechanika jest mocna i nie potrzebuje dopisywania emocji.

Unikać:

- `najmilsza część`;
- `to i tak się liczy`;
- `najczęściej czytana część`;
- oceniania, co użytkownik „powinien” czuć.

### Lepszy model

**H1**

`Ugotowałem ten przepis`

**Body**

`Dodaj zdjęcie lub komentarz. Autor przepisu zobaczy je przy swoim przepisie.`

Jeśli oba są opcjonalne:

`Zdjęcie i komentarz są opcjonalne.`

### Ekran po zapisaniu

`Gotowe — informacja „Ugotowałem” została dodana do przepisu.`

Ewentualnie:

`Autor przepisu dostanie powiadomienie.`

Tylko jeśli jest to zawsze prawdziwe w danej konfiguracji powiadomień.

---

## 20. Komentarze

`Napisz normalnie, po ludzku`

→

`Napisz komentarz`

Placeholder może być:

`Dodaj komentarz…`

Jeśli aplikacja ma określone zasady komentarzy, lepiej linkować `Zasady rozmowy` niż sugerować, co znaczy „normalnie”.

---

## 21. Ustawienia bezpieczeństwa

### Zmiana hasła

Obecne długie wyjaśnienie:

> Zmień hasło, jeśli podejrzewasz, że ktoś inny je zna — na przykład je zgadł albo zobaczył...

można skrócić:

`Zmień hasło, jeśli podejrzewasz, że ktoś inny może je znać.`

### Help hasła

`Co najmniej 10 znaków. Użyj długiego, unikalnego hasła lub frazy, której nie używasz w innych serwisach.`

### Wylogowanie innych urządzeń

Obecne przykłady `u wnuka, w bibliotece albo u znajomych` są zbyt mocno profilowane.

Lepsze:

`Użyj tej opcji, jeśli konto zostało zalogowane na wspólnym lub cudzym urządzeniu i nie masz już do niego dostępu.`

`Wylogujemy wszystkie pozostałe urządzenia. To urządzenie pozostanie zalogowane.`

Help przy haśle:

`Wpisz hasło, aby potwierdzić tę operację.`

---

## 22. Powiadomienia

Część konstrukcji bezrodzajowych jest pomysłowa, ale kilka brzmi nienaturalnie.

### „Ugotowane z Twojego przepisu”

Obecne:

`{nazwa} — ugotowane z Twojego przepisu „X”.`

Brzmi jak urwany nagłówek.

Lepsze bez wskazywania rodzaju:

`Nowe „Ugotowałem” od {nazwa} przy przepisie „X”.`

albo:

`{nazwa}: nowe „Ugotowałem” przy Twoim przepisie „X”.`

### Zapisanie przepisu

`{nazwa} zapisuje Twój przepis „X” w swoim zeszycie.`

Czas teraźniejszy rozwiązuje problem rodzaju i brzmi naturalnie.

### Welcome

Nie powtarzać wszędzie:

`Nie musi być ładne — ma być prawdziwe.`

Wystarczy:

`Dodaj pierwsze zdjęcie albo przepis.`

### Powiadomienia administracyjne

`pierwszy wpis bez reakcji zwykle bywa ostatnim`

to twierdzenie analityczne. Jeśli nie ma danych potwierdzających tę regułę, zmienić na:

`To pierwszy wpis tej osoby. Warto odpowiedzieć szybko.`

---

## 23. Błędy

### 404

Obecny ekran wykonuje za dużo pracy psychologicznej i za szczegółowo opisuje przypadki przepisywania linku.

### Rekomendacja

**H1**

`Nie znaleźliśmy tej strony`

**Body**

`Adres może być niepełny albo strona została usunięta.`

**Opcjonalne drugie zdanie**

`Sprawdź adres albo znajdź treść przez wyszukiwarkę.`

CTA:

- `Strona główna`
- `Szukaj`
- `Pomoc`

To wystarcza.

### 419 — wygasła sesja

Najważniejsza jest tu **precyzja**, nie ton.

Jeśli wszystko odzyskano:

> Formularz był otwarty zbyt długo i sesja wygasła. Odzyskaliśmy wpisany tekst. Sprawdź go i wyślij formularz ponownie.

Jeśli dane zostały obcięte:

> Formularz był otwarty zbyt długo i sesja wygasła. Odzyskaliśmy część wpisanej treści. Sprawdź formularz i uzupełnij brakujące informacje przed wysłaniem.

Pliki:

> Ze względów bezpieczeństwa przeglądarka nie pozwala przywrócić wybranego pliku. Wybierz zdjęcie ponownie.

Jeśli logowanie wygasło:

> Zaloguj się ponownie w nowej karcie, a następnie wróć tutaj i wyślij formularz.

Nie używać jednocześnie absolutnego `nic nie przepadło` i warunkowego `nie wszystko udało się przenieść`.

---

## 24. Słownik zamian globalnych

| Unikać | Preferować |
|---|---|
| `naprawdę` jako wzmacniacz | usunąć albo podać konkretny fakt |
| `po ludzku` | opisać konkretne zachowanie |
| `Nic nie musisz` | usunąć / oznaczyć pole jako `(opcjonalnie)` |
| `Zawsze możesz…` | pokazać odpowiednią akcję |
| `Jedno i drugie jest w porządku` | usunąć |
| `Nic nie zginie` | `Szkic zapisuje się automatycznie` — tylko jeśli prawdziwe |
| `To wszystko` | usunąć |
| `Zajmie minutę` | usunąć albo zastąpić liczbą kroków/pól |
| `Klikasz — i jesteś w środku` | `Po kliknięciu zalogujesz się` |
| `zielony przycisk` | nazwa przycisku |
| `kolejka` | nazwa właściwego procesu/formularza |
| `firma, która czeka na Twoje dane` | konkretna polityka danych |
| `Bez żadnego układania przez komputer` | `chronologicznie, bez rankingu` |
| `Napisz normalnie, po ludzku` | `Napisz komentarz` |
| `To najważniejszy…` | wyjaśnić działanie bez rankingu ważności |
| `To najmilsza część…` | opisać skutek działania |
| `To zostaje w rodzinie` | opisać pochodzenie i ustawienie widoczności |
| `bez liczników w twarz` | `bez wyścigu na liczby` |
| `Twoje dane są Twoje` | `Możesz pobrać… / usunąć… / zmienić widoczność…` |

---

## 25. Co zostawić jako charakter marki

Nie należy wycinać wszystkiego.

### Zostawiłbym

`Pokaż, co dziś ugotowałeś`

jako główny slogan / H1.

`Nie musi być ładne — ma być prawdziwe.`

maksymalnie w **jednym** strategicznym miejscu.

`Ugotowałem`

jako nazwę mechaniki, bo jest rozpoznawalna i produktowa.

`kuKING`

jako okazjonalny żart społecznościowy, ale nie jako podstawowy język CTA i formularzy.

### Ograniczyłbym

- `Zostań kuKINGiem` w rejestracji;
- powtarzanie `kuKING` w komunikatach technicznych;
- każdą próbę „dowcipnego” tekstu w błędach, bezpieczeństwie, moderacji i prawie.

---

## 26. SEO / meta — proponowany kierunek

Nie próbowałbym wciskać do meta description wszystkich wartości produktu.

### Landing

**Title**

`Kuking — domowe przepisy i zdjęcia z gotowania`

**Description**

`Dodawaj zdjęcia domowych dań, zapisuj własne i rodzinne przepisy oraz zobacz, kto ugotował z Twojego przepisu.`

Alternatywa bardziej wyróżniająca:

`Kuking to miejsce na domowe gotowanie: zdjęcia dań, własne przepisy, ich historia i prawdziwe „Ugotowałem” od innych osób.`

### O Kuking

**Title**

`O Kuking — miejsce na codzienne gotowanie i przepisy`

**Description**

`Poznaj Kuking: serwis do dzielenia się codziennym gotowaniem, zapisywania przepisów i zachowywania ich historii.`

### Discover

**Title**

`Najnowsze wpisy i przepisy — Kuking`

**Description**

`Zobacz najnowsze dania i przepisy dodane w Kuking, ułożone chronologicznie.`

### Rejestracja / logowanie / ustawienia

Nie optymalizować SEO. Jeśli są indeksowalne, rozważyć `noindex` zgodnie z architekturą serwisu.

---

## 27. Style guide v2 — najważniejsze dopiski do obecnego dokumentu

Obecny `COPY_STYLE.md` warto **rozszerzyć**, nie zastępować.

Dodać następujące reguły:

### 27.1. Funkcja przed charakterem

> Jeśli zdanie jest w formularzu, błędzie, ustawieniu lub komunikacie systemowym, najpierw ma wyjaśniać stan i działanie. Charakter marki jest drugorzędny.

### 27.2. Jedna „ciepła” linia na ekran

> Na ekranach funkcjonalnych maksymalnie jedno zdanie może mieć wyraźny charakter autorski. Pozostałe mają być neutralne.

### 27.3. Nie tłumaczymy intencji UX

Zakazane wzorce:

- `to tylko pomoc`;
- `to nie jest obowiązkowy krok`;
- `jedno i drugie jest w porządku`;
- `nie musisz się martwić`;
- `zawsze możesz`.

Zamiast tego interfejs ma **pokazać opcjonalność** i dać odpowiednią akcję.

### 27.4. Nie przypisujemy emocji

Nie piszemy:

- `najmilsza część`;
- `na to się czeka`;
- `to najważniejsze`;
- `będzie Ci łatwiej`;
- `spokojnie`.

Jeżeli coś jest ważne, piszemy **dlaczego**.

### 27.5. Nie obiecujemy czasu bez pomiaru

`Zajmie minutę`, `chwila`, `od razu`, `zaraz` — tylko gdy system i proces to gwarantują albo pomiar jest świadomie utrzymywany.

### 27.6. Nie obiecujemy zachowania, którego UI nie gwarantuje

Przykłady:

- `Jutro będzie ktoś inny`;
- `nic nie zginie`;
- `tak zobaczą to inni`;
- `autor dostanie powiadomienie`.

Każde takie zdanie traktować jak wymaganie techniczne.

### 27.7. Nie odwołujemy się do wyglądu kontrolki

Nie:

- zielony przycisk;
- przycisk po prawej;
- ikona u góry.

Tak:

- przycisk `Zaloguj`;
- sekcja `Prywatność`;
- link `Pomoc`.

### 27.8. Projektowanie dla 50+/60+

Nie oznacza większej liczby zdań.

Preferować:

- jednoznaczne etykiety;
- duże cele kliknięcia;
- jasny skutek akcji;
- brak żargonu;
- brak „sprytnych” skrótów;
- krótkie akapity;
- możliwość cofnięcia lub poprawy.

Nie preferować:

- przykładów `u wnuka`;
- przesadnego uspokajania;
- tłumaczenia oczywistości;
- tonu „ktoś cierpliwie objaśnia technologię”.

---

## 28. Kolejność wdrażania

### Etap 1 — poprawność

1. 419: rozdzielić pełne i częściowe odzyskanie formularza.
2. Recipe wizard: prywatność podglądu i „To zostaje w rodzinie”.
3. Recipe wizard: ujednolicić autosave / manualne zapisywanie.
4. Rejestracja: zweryfikować wszystkie zastosowania e-maila i poprawić help.
5. Usunąć nieudokumentowane twierdzenia analityczne.
6. Zweryfikować obietnice `Jutro...`, `autor dostanie...`, `zajmie minutę`.
7. Zmienić przykład hasła.

### Etap 2 — funkcjonalne mikrocopy

1. rejestracja;
2. logowanie;
3. onboarding;
4. dodawanie wpisu;
5. recipe wizard;
6. search;
7. security;
8. 404/419;
9. e-maile.

### Etap 3 — marketing i marka

1. landing;
2. about;
3. help;
4. discover;
5. contact.

### Etap 4 — deduplikacja

Przejść cały projekt i usunąć powtarzające się:

- `naprawdę`;
- `po ludzku`;
- `nic nie musisz`;
- `zawsze możesz`;
- `to wszystko`;
- `w porządku`;
- `nic nie zginie`;
- `zajmie minutę`;
- powtórzenia sloganu;
- zbyt częste `kuKING`.

---

## 29. Lista QA dla każdego nowego tekstu

Przed merge:

- [ ] Czy zdanie mówi coś potrzebnego?
- [ ] Czy można usunąć połowę słów bez utraty informacji?
- [ ] Czy opisuje funkcję zamiast intencji projektanta?
- [ ] Czy nie mówi użytkownikowi, co ma czuć?
- [ ] Czy nie uspokaja go bez potrzeby?
- [ ] Czy nie zawiera twierdzenia, które powinno być testem technicznym?
- [ ] Czy nie obiecuje czasu?
- [ ] Czy nie zależy od koloru lub położenia elementu?
- [ ] Czy termin jest taki sam jak w pozostałych ekranach?
- [ ] Czy pole opcjonalne jest po prostu oznaczone `(opcjonalnie)`?
- [ ] Czy komunikat błędu mówi, jak naprawić problem?
- [ ] Czy osoba 65+ zrozumie go bez protekcjonalnego tonu?
- [ ] Czy osoba 25+ nie poczuje, że UI mówi do niej jak do dziecka?
- [ ] Czy da się przeczytać zdanie na głos naturalnym polskim?
- [ ] Czy żart marki jest naprawdę potrzebny na tym ekranie?

---

## 30. Kryterium „brzmi jak AI”

W Kuking nie usuwałbym tekstu tylko dlatego, że jest elegancki. Za „AI-owe” lub sztuczne uznałbym przede wszystkim cztery wzorce:

### A. Nadużywanie kontrastów

`nie X, tylko Y`, `człowiek, nie automat`, `ludzie, nie treści`.

Raz jest mocne. Powtarzane staje się generatorem sloganów.

### B. Nadmiar zapewnień

`naprawdę`, `spokojnie`, `nic nie zginie`, `to w porządku`.

Im częściej produkt zapewnia o zaufaniu, tym bardziej użytkownik zaczyna zauważać, że produkt próbuje zaufanie zbudować słowami.

### C. Udawana spontaniczność

`Klikasz — i jesteś w środku.`  
`Lepiej dwa razy niż wcale.`  
`Coś tu nie gra?`

To brzmi naturalnie w rozmowie, ale powtarzane w systemie wygląda jak ręcznie wklejona osobowość.

### D. Marketing własnej prostoty

`To wszystko.`  
`Cztery pola i gotowe.`  
`Zajmie minutę.`

Jeśli flow jest naprawdę proste, interfejs nie musi tego komentować.

---

## 31. Finalny model głosu marki

### Landing

**Charakter:** wyraźny.  
**Cel:** powiedzieć, czym Kuking różni się od kolejnej bazy przepisów.

### Feed / profil / discover

**Charakter:** lekki.  
**Cel:** treść użytkowników ma być ważniejsza niż głos produktu.

### Formularze / onboarding

**Charakter:** neutralny i pomocny.  
**Cel:** jak najmniej tarcia.

### Ustawienia / bezpieczeństwo

**Charakter:** rzeczowy.  
**Cel:** użytkownik ma rozumieć skutek działania.

### Błędy

**Charakter:** spokojny, ale nie terapeutyczny.  
**Cel:** co się stało + co zrobić.

### Moderacja / prawo

**Charakter:** neutralny i precyzyjny.  
**Cel:** fakt, powód, skutek, środek odwoławczy.

### E-maile bezpieczeństwa

**Charakter:** prosty i przewidywalny.  
**Cel:** dlaczego mail przyszedł + co zrobić + co zrobić, jeśli to nie ja.

---

## 32. Moja rekomendowana decyzja produktowa

**Nie robić pełnego „rewrite'u marki od zera”.** To byłby błąd.

Najlepszy efekt da:

1. zachowanie głównego sloganu i mechaniki „Ugotowałem”;
2. ograniczenie żartu `kuKING` do wybranych miejsc;
3. wycięcie 30–50% zdań pomocniczych w formularzach;
4. zastąpienie zapewnień konkretnymi faktami;
5. ujednolicenie mikrocopy wokół autosave, prywatności, e-maila i logowania;
6. ograniczenie „ludzkiego” tonu w błędach i bezpieczeństwie;
7. oddzielenie marketingu od copy operacyjnego.

Po tej zmianie Kuking zachowa własny charakter, ale będzie brzmiał bardziej jak **dojrzały produkt z osobowością**, a mniej jak produkt, który na każdym ekranie przypomina, że ma osobowość.

---

## 33. Zakres źródłowy audytu

Szczegółowo przejrzane zostały kluczowe ścieżki i komponenty, m.in.:

- `docs/brand/COPY_STYLE.md`
- `resources/views/pages/landing.blade.php`
- `resources/views/pages/home.blade.php`
- `resources/views/pages/discover.blade.php`
- `resources/views/pages/static/about.blade.php`
- `resources/views/pages/static/help.blade.php`
- `resources/views/pages/napisz-do-nas.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/auth/login.blade.php`
- `resources/views/auth/forgot-password.blade.php`
- `resources/views/auth/login-link.blade.php`
- `resources/views/pages/onboarding/interests.blade.php`
- `resources/views/pages/onboarding/people.blade.php`
- `resources/views/pages/onboarding/done.blade.php`
- `resources/views/pages/posts/create.blade.php`
- `resources/views/components/recipe-wizard.blade.php`
- `resources/views/components/layout.blade.php`
- `resources/views/pages/search.blade.php`
- `resources/views/components/kuking-board.blade.php`
- `resources/views/components/comment-thread.blade.php`
- `resources/views/pages/settings/privacy.blade.php`
- `resources/views/pages/settings/data.blade.php`
- `resources/views/pages/cooked/create.blade.php`
- `resources/views/pages/cooked/celebrate.blade.php`
- `resources/views/pages/profile/show.blade.php`
- `resources/views/pages/settings/email.blade.php`
- `resources/views/pages/settings/security.blade.php`
- `resources/views/pages/notifications.blade.php`
- `resources/views/errors/404.blade.php`
- `resources/views/errors/419.blade.php`
- `resources/views/mail/link-do-logowania.blade.php`

Dodatkowo została przejrzana struktura `resources/views` pod kątem ekranów publicznych, auth, ustawień, moderacji, maili i stanów błędów.

**Uwaga:** tekstów prawnych nie należy „upiększać” wyłącznie stylistycznie. Każdą zmianę treści regulaminowej, DSA, środków odwoławczych i pouczeń traktować osobno jako zmianę wymagającą weryfikacji prawnej.

---

## 34. Ostateczny skrót: co bym zmienił bez dyskusji

1. `Zostań kuKINGiem — to darmowe` → `Załóż darmowe konto`.
2. `Obserwowani` → `Od obserwowanych`.
3. `Bez żadnego układania przez komputer` → `Chronologicznie, bez rankingu popularności`.
4. `Napisz normalnie, po ludzku` → `Napisz komentarz`.
5. `Klikasz — i jesteś w środku` → `Po kliknięciu zalogujesz się`.
6. usunąć `Jedno i drugie jest w porządku`.
7. usunąć `Lepiej dwa razy niż wcale`.
8. usunąć `nie będziemy go udawać`.
9. usunąć `Na to powiadomienie się tutaj czeka`.
10. usunąć `To najczęściej czytana część`, jeśli brak danych.
11. usunąć `Zajmie minutę`.
12. usunąć absolutne `nic nie zginie` tam, gdzie są wyjątki.
13. `To zostaje w rodzinie` → neutralny opis historii przepisu.
14. `tak zobaczą to inni` → `Podgląd przepisu`.
15. `zielony przycisk` → nazwa przycisku.
16. `zielonapietruszkarano` → ogólna rekomendacja unikalnej frazy.
17. 404 skrócić o co najmniej połowę.
18. contact skrócić o ok. 30–40%.
19. onboarding skrócić i przestać tłumaczyć, że pomijanie jest dozwolone.
20. zostawić charakter marki głównie tam, gdzie pomaga **sprzedać ideę**, a nie tam, gdzie użytkownik chce po prostu wykonać zadanie.
