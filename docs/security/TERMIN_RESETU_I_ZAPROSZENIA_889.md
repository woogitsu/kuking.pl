# Termin resetu hasła i zaproszenia w kolejce — #889

## Zachowanie

`UstawienieNowegoHasla` i `ZaproszenieDoZalozeniaKonta` sprawdzają ważność
przy wykonywaniu zadania przez `shouldSend`. Wygasły, usunięty albo zastąpiony
token zatrzymuje wiadomość przed transportem. Powiadomienie nie wystawia
nowego tokenu, nie zmienia jego terminu i nie wysyła listu zastępczego.

Treść podaje czas **od chwili zamówienia**, np. „przez godzinę od chwili
zamówienia”. Opóźnienie kolejki nie rozpoczyna kolejnej godziny. Przekazany
termin może skrócić termin odtworzony z bazy, ale nie może go wydłużyć.

Reset wymaga adaptacji wzorca logowania linkiem: używa brokera haseł
(`Password::tokenExists`), a nie `LoginLinkToken`. Data wystawienia pochodzi
z `created_at` w tabeli wskazanej przez konfigurację brokera. `User` przekazuje
termin podczas zlecania powiadomienia; worker nie może go przesunąć przez
zmianę konfiguracji. Bieżące skrócenie ważności brokera nadal ogranicza wysyłkę.

Zaproszenie odczytuje `RegistrationInvite` po skrócie tokenu, sprawdza
`jestWazne()` oraz zgodność adresu odbiorcy. Jego termin w bazie jest zapisany
wprost jako `expires_at`; bieżąca konfiguracja długości nowych zaproszeń
nie zmienia istniejącego zaproszenia.

## Starsze zadania

Reset zapisany przed zmianą nie zawiera właściwości `wygasa`. Zaproszenie
może mieć ją równą `null`. Oba przypadki odczytują aktualny rekord z bazy.
Nie liczą ważności od uruchomienia workera. Brak rekordu zatrzymuje wysyłkę.

Stary reset nie przechowuje historycznej wartości konfiguracji brokera,
więc nie da się jej odzyskać: dla niego obowiązuje `created_at` plus bieżący
limit brokera, zgodnie z walidacją samego resetu. Nowe zadanie zachowuje
dodatkowo przekazany termin. Nie zmieniamy schematu ani zasad przyjmowania
tokenów przez formularze.

## Logowanie linkiem (dołożone 24 września 2026)

`LinkDoLogowania` ma teraz ten sam strażnik `shouldSend`: szuka wiersza
`login_link_tokens` po skrócie tokenu, sprawdza właściciela i termin
(`expires_at` z bazy, skrócony przez przekazane `wygasa`, nigdy wydłużony).
Wygasły albo zastąpiony link nie trafia do transportu; tokenu nie odnawiamy,
a miejsca w dobowym budżecie nie oddajemy (budżet liczy próby). Zadanie bez
daty (`wygasa === null`) czyta termin z bazy. Treść podaje „przez pół godziny
od chwili zamówienia", a przy opóźnieniu — ile minut naprawdę zostało.
Regresja: `tests/Feature/LinkLogowaniaTerminIPonowienieTest.php`.

## Własne pomiary

Baza wyjściowa gałęzi `gpt/tokeny-zaproszen`:
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Przed edycją kodu aplikacji
drzewo było czyste. W tej bazie nie ma `shouldSend` w obu badanych klasach
ani przy logowaniu linkiem. Wzorzec został odczytany z lokalnego commita
`ce394d8c8b2c7f7772fee2fc970aecf04b28617b`; jego wyników testów nie przyjmowano
jako własnego pomiaru i nie przenoszono tego commita na gałąź.

`TerminResetuIZaproszeniaWKolejceTest` wykonuje rzeczywiste powiadomienia
po serializacji i odtworzeniu `SendQueuedNotifications`, z zamrożonym zegarem
oraz transportem `ArrayTransport`. `Queue::fake` przechwytuje wyłącznie
zlecenie; wykonanie zadania i kanału pocztowego nie jest atrapą.

Przed poprawką: **16 porażek, 52 asercje**, osobno dla resetu i zaproszenia.
Wiadomości dokładnie w terminie oraz po nim trafiały do transportu
(1 zamiast 0). Zmiana konfiguracji z 60 na 120 minut dla resetu oraz z godziny
na dobę dla zaproszenia zmieniała opis starego tokenu. Przekazany wcześniejszy
termin 10 minut nie ograniczał opisu. Wynik podano po poprawieniu błędu
w samym przyrządzie testowym (nieistniejąca metoda czyszczenia brokera);
tego błędu nie zaliczono jako dowodu usterki aplikacji.

Po poprawce: **66 testów, 419 asercji** w zestawie pocztowym. Obejmuje
nową regresję oraz `ListyZSystemuPoPolskuTest`, `ZaproszenieDoRejestracjiTest`,
`MartweZadaniaTest`, `KtoNieDostalListuTest`, `LinkResetuNieNiesieAdresuWUrlTest`
i `NadawcaPocztyNieJestNoreplyTest`.
Nowa regresja sprawdza wysyłkę od razu, sekundę przed terminem, dokładnie
w terminie i sekundę po nim, wcześniejszy argument, brak daty w starym
zadaniu, usunięcie i zastąpienie tokenu oraz niezmienność rekordów w bazie.

Istniejące testy awarii transportu i polskich listów wymagały uzupełnienia
danych: ich jawny token nie miał dotąd odpowiadającego skrótu w bazie.
Po dodaniu strażnika takie powiadomienie słusznie nie dochodzi do transportu.
Testy zachowują dotychczasowe asercje i teraz używają istniejących tokenów.

Pełny przebieg: **4408 zaliczonych, 1 porażka, 83747 asercji, 462,05 s**.
Jedyna porażka to `NadawcaPocztyNieJestNoreplyTest::
test_wyslany_list_ma_nadawce_przyjmujacego_odpowiedzi`, również używający
tokenu bez rekordu. Po poprawieniu tej jednej fixture cały wyżej wymieniony
zestaw 66 testów przeszedł ponownie, łącznie ze wszystkimi pięcioma testami
nadawcy. Nie przedstawiamy tego jako drugiego, w całości zielonego przebiegu
pełnej suity. `ProbaOdtworzeniaTest` pominięto zgodnie ze zleceniem, ponieważ
używa wspólnej bazy próby odtworzenia. Pint wykonano na wszystkich ośmiu
zmienionych plikach PHP; po formatowaniu powtórzono testy pocztowe.

Środowisko: własna baza `kuking_flota_gpt-tokeny-zaproszen`, użytkownik
`kuking`, PostgreSQL `127.0.0.1:55439`, runtime
`/home/mateusz/flota/gpt-tokeny-zaproszen-run`. Nie używano portu 5432.

## Granice i wycofanie

Nie wykonano rzeczywistej wysyłki ani pomiaru doręczenia przez EmailLabs.
Nie sprawdzano otwarć wiadomości ani skrzynek odbiorców. Pomiar obejmuje
wyłącznie aplikację i lokalny transport testowy.

Nie ma migracji ani nowych zależności. Wycofanie polega na odwróceniu
commita i ponownym uruchomieniu workerów; przywróci jednak wysyłkę martwych
linków i mylącą informację o czasie. Nie należy ponawiać starych zadań
w celu „odświeżenia” poświadczeń. Po przekazaniu do transportu późniejsze
opóźnienie po stronie dostawcy poczty pozostaje poza kontrolą `shouldSend`.

Sposób obsługi wygasłego listu (pominięcie) wynika wprost z tego zlecenia.
W tym zakresie nie ma nowej decyzji produktowej dla właściciela.

## Przekazanie stanowiska — 20 września 2026

W trakcie pracy zniknął `C:\Users\matma\Documents\Codex\kuking.pl\.git`,
na który wskazuje plik `.git` stanowiska. Późniejsze polecenie Git z katalogu
kanonicznego rozpoznaje obce repozytorium nadrzędne `Codex`; nie wolno w nim
zapisać tej pracy. Właściciel poinformował, że naprawą zajmuje się inny model.
Nie modyfikowano rejestracji worktree ani repozytorium nadrzędnego.

**Commit nie został zapisany — brak SHA.** Pliki pozostają w stanowisku
`C:\Users\matma\Documents\kuking-flota\gpt-tokeny-zaproszen`.
Po przywróceniu Git trzeba sprawdzić gałąź i bazę, przejrzeć różnicę i zapisać
lokalny commit, np. „Nie wysyłaj wygasłych resetów hasła i zaproszeń”.
Nie wykonano push ani nie otwarto PR, zgodnie z zakazem zlecenia.
