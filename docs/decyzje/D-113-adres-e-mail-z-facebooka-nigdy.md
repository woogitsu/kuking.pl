## D-113 · Adres e-mail z Facebooka nigdy nie wchodzi na istniejące konto — a właściciel tego konta dostaje POWIADOMIENIE, nie klucz

**Data:** 11 września 2026 · Rozstrzygnął właściciel · Ciąg dalszy **D-069**
i **D-098** (issue #259) · Status: **obowiązuje**

### Pytanie, które trzeba było rozstrzygnąć

Reguła 1 z **D-069** brzmi: `email_verified` od Google jest WARUNKIEM wejścia,
bez niego nie robimy nic. D-069 nazywa ten warunek najkrótszą znaną drogą
przejęcia konta przy „zaloguj się przez…".

**Facebook takiego pola nie ma.** Pełny opis pola `email` w Graph API to
*„The User's primary email address listed on their profile. This field will not
be returned if no valid email address is available."* — ani słowa
o potwierdzeniu. Reguły 1 nie da się dla Facebooka spełnić, więc trzeba ją było
czymś zastąpić, a to nie jest decyzja do podjęcia przy klawiaturze.

### Co zostało rozstrzygnięte

Słowami właściciela: *„trzeba i tak mu założyć to konto i ewentualnie wysłać
maila żeby potwierdził email i tyle"*.

1. **Nowa osoba** — konto powstaje, adres jest oznaczony jako
   **niepotwierdzony**, wychodzi nasz własny list z potwierdzeniem. Dokładnie
   tak jak przy rejestracji hasłem.
2. **Adres pasuje do istniejącego konta Kuking** — wejście **odmawia**
   i niczego nie łączy. Facebook nie dowiódł, że ta skrzynka należy do osoby
   siedzącej przed ekranem; wystarczyłoby wpisać cudzy adres w swoim koncie
   na Facebooku.
3. Dochodzi **list do właściciela konta**, bo odmowę widział wyłącznie ten, kto
   ją wywołał — właściciel nie dowiadywał się o próbie w ogóle.

### Dlaczego ten list nie ma odnośnika „to ja, połącz konta"

To jest najważniejsze zdanie tego wpisu. Taki odnośnik byłby wygodny i byłby
dziurą, przez którą przechodzi dokładnie ten atak, przed którym stoi odmowa:
obcy zakłada konto na Facebooku, wpisuje w nim cudzy adres, klika „Wejdź
kontem Facebooka" — i wtedy **my** wysyłamy właścicielowi wiarygodny list,
którym ten jednym kliknięciem oddaje obcemu wejście na swoje konto. Napastnik
nie musiałby nawet mieć dostępu do skrzynki; wystarczyłoby, żeby właściciel
kliknął.

**Żaden list nie niesie u nas uprawnienia do zmiany stanu konta.** Ta sama
granica co w `ZgloszonaZmianaAdresu` (issue #195), z tego samego powodu.
List mówi więc: zaloguj się jak zwykle i połącz konta w `Ustawienia →
Bezpieczeństwo`.

### Cena, którą płacimy — wypisana, żeby nie wyglądała na przeoczenie

Człowiek, który ma już konto w Kuking, **nie wejdzie na nie kontem Facebooka**,
dopóki sam nie połączy kont z ustawień. Dostanie odmowę. Zdanie na ekranie musi
więc mówić, CO ZROBIĆ — odmowa bez drogi wyjścia kończy się odejściem człowieka.

Runbook (§7.1) stawiał to jako wybór „albo–albo": albo adres nigdy nie łączy,
albo powiązanie powstaje wyłącznie z ustawień. **Kod robi jedno i drugie** —
wejście zakłada nowe konto, a ścieżka z ustawień istnieje dla tych, którzy
konto już mają. Dylemat był pozorny.

### Ograniczenie jednego listu na godzinę na konto

Bez niego ta funkcja jest zdalnym zalewaniem cudzej skrzynki: wystarczy
w kółko wracać na adres powrotu. Ogranicznik trasy liczy żądania **napastnika**
i jego nie boli — zalewana jest skrzynka **ofiary**, więc licznik stoi przy
koncie odbiorcy. Pominięcie listu jest niewidoczne z zewnątrz; gdyby było
widoczne, dałoby się nim sprawdzać, czy konto istnieje (**D-056**).

### Co musiałoby się stać, żeby to zmienić

Facebook musiałby zacząć oddawać potwierdzenie adresu — wtedy droga wraca do
reguły 3 z D-069 i wygląda jak przy Google. Albo: pomiar pokazałby, że odmowa
odbija ludzi masowo, a nie pojedynczo — wtedy szukamy trzeciej drogi, ale
**nie** przez list z odnośnikiem łączącym.
