# Poprawki zgłoszeń — Alfa 0.27

Stan: praca lokalna, przed CI i wdrożeniem. Baza:
`595f41fa8a4c21642f21a566c27946c5811125a9`. Pełny port marki nadal
**CZĘŚCIOWO**. Ten raport nie zastępuje odbioru wszystkich stron.

## Dostęp do treści przy małej wysokości — #434 / #492

[Raport nawigacji](NAWIGACJA_NISKI_WIDOK_492.md) opisuje odpięcie obu
belek przy małym obszarze strony, zapas obrysu oraz jeden rząd powiadomień
i konta przy 390 px. Zwykłe widoki zachowują przypięcie; przy bardzo
małym obszarze nawigacja wymaga przewinięcia do początku lub końca.

Lokalny moduł: 20 konfiguracji, pełne przejście Tab po treści i pięciu
dolnych linkach, oba motywy i JS/bez JS, siedem fizycznych negatywów.
Raport rozróżnia prawdziwy zoom od fontu 32 px, odczyt kodu od odbioru
w przeglądarce oraz Chrome 151 od środowiska CI. Sam build i 72 pary
kontrastu przeszły lokalnie; pełne CI i produkcja pozostają osobnymi
bramkami.

## Komunikat długości imienia — #538

Rejestracja i ustawienia profilu odrzucały nazwę dłuższą niż 40 znaków,
ale komunikat pozwalał na 100. Oba kontrolery korzystają teraz z `:max`
pochodzącego z rzeczywistej reguły. Limit, istniejące dane i uprawnienia
pozostają bez zmian.

`DlugoscNazwyProfiluTest` sprawdza rzeczywiste POST/PUT i powrót do
formularza: dokładny komunikat przy właściwym polu, zachowanie wpisanego
tekstu i brak zapisu błędnej nazwy. Dwa limity (40 i 46) chronią przed
wpisaniem aktualnej liczby na sztywno. Rodzina: **9 testów / 42 asercje**.

Cztery fizyczne kontrole ujemne: w każdym kontrolerze osobno przywrócono
100, następnie wpisano na sztywno 40. Każda mutacja oblała właściwą
regresję; po odtworzeniu kopii spoza repo test przeszedł. Sprawdzono MD5
i mtime po każdym przywróceniu. MD5 poprawnych źródeł:
`fafb99b8afddf520984719de1ff66ad0` (rejestracja),
`c09c68a348e6aca81401cab40fa784a5` (ustawienia).
Niezależny review bez uwag; nie przypisujemy mu dodatkowego wykonania testów.

## Odstęp przed usuwaniem komentarza — #444

Z obu stref usuwania w `comment-thread.blade.php` usunięto lokalne
`mt-2 pt-3`. Wraca istniejąca norma D-154: margines 32 px i padding
24 px. Z kreską rzeczywista przerwa rośnie z 21 do 57 px, czyli o 36 px
na własnym komentarzu lub odpowiedzi. To zastosowanie istniejącej normy,
nie nowa decyzja właściciela po obejrzeniu telefonu.

W izolowanym Chromium porównano 64 stany: szerokości CSS 320/360/390/414,
zoom 100/200%, tekst aplikacji 100/140%, oba warianty odstępu i zwinięte
lub rozwinięte potwierdzenie. Zoom był rzeczywisty (`chrome.tabs.setZoom`,
kontrola `innerWidth` i DPR); fizyczne okno powiększano tak, aby zachować
wskazane szerokości CSS. Pomiar powtórzono na fizycznym Blade po pierwszym
eksperymencie DOM: zapis źródła, wyczyszczenie cache widoków, nowy GET.

Bez dodatkowych wierszy akcji, poziomego przepełnienia ani przecięć
przycisków. Najmniejsza wysokość celu 50,5 px, tekst przycisków co najmniej
18 px. Obecność CSRF, DELETE i potwierdzenia pozostała sprawdzona;
przeglądarka nie wysyłała formularzy kasowania. Obejrzano reprezentatywne
zrzuty, nie wszystkie 64 stany. Nie używano fizycznego telefonu.

Regresja komentarza i odpowiedzi: **11 testów / 102 asercje**, Pint PASS.
Dwa niezależne przywrócenia starych klas w prawdziwym Blade oblały nową
regresję. Kopia poza repo, przywrócenie MD5
`e77bdce302045496658246d1ebe8ad7f` i mtime, ponowny wynik dodatni.

## Porządkowanie historycznych zgłoszeń

[Próbka karuzeli #431](KARUZELA_MIESZANA_431.md) uzupełnia automat
dostępności o prawdziwe obrazy obu orientacji, osiem wariantów bez JS
i fizyczne kontrole ujemne. Nie zmienia zamierzonej kwadratowej ramki
ani polityki dopasowania zdjęć.

[Ogląd 18 wiadomości](ODBIOR_WIADOMOSCI_027.md) obejmuje wszystkie nazwy
własnych i standardowych szablonów przy 320/640 px, bez wysyłki.
Nie znaleziono błędu układu w tych fixture; nadal nie ma dowodu działania
w rzeczywistych klientach pocztowych.

Na podstawie kodu, historii scalonych PR i regresji zamknięto #440
(limit 40; osobny błąd tekstu przeniesiony do #538), #445 (odstęp pod
podpisem pola) oraz #448 (wyświetlanie gotowego wariantu zdjęcia profilu).
#518 zamknięto jako nieodtwarzalne po potwierdzeniu użytkownika, że na
0.19 objaw zniknął. Przyczyna ramki pozostaje nieustalona; nie usunięto
globalnego fokusu i nie przypisano fikcyjnego commita naprawczego.

Po integracji #444 i #538 lokalny przebieg sześciu wybranych rodzin
regresji: **33 testy / 229 asercji**, Pint czterech plików PASS.
To wynik wybranych testów, nie pełnego zestawu ani CI.

## Uzupełnienie odbioru #539

Potwierdzono i poprawiono kontrast fokusu przy jednoczesnym najechaniu na przycisk podpowiedzi. Regresja: 88 wierszy pomiarów, pięć kontroli ujemnych rzeczywistego CSS i cztery próby prawdziwego zoomu. Niezależny końcowy odbiór po integracji z nawigacją: ciemny motyw, zoom 200%, tekst 140%, Tab 14/14 PASS wraz z hover + focus-visible. Wcześniejszego nieudanego przebiegu nie traktujemy jako pozytywnego. Szczegóły: [FOKUS_PODPOWIEDZI_539.md](FOKUS_PODPOWIEDZI_539.md). Wdrożenie pakietu wymaga osobnego potwierdzenia.
