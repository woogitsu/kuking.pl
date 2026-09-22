# Śledzenie listów i informacja o brakach paczki — #204 i #692

Pomiar własny: 20 września 2026. Gałąź `gpt/analityka-piksel`, nietknięta
baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Przed pierwszą zmianą
`git status --short` był pusty.

## Wynik

**#692 jest już naprawione w badanym drzewie.** Nie zmieniam eksportu.
**#204 pozostaje otwarte po stronie konfiguracji dostawcy i pomiaru
doręczonych listów.** Nie ma własnego świeżego dowodu piksela w skrzynce
odbiorcy. Kod resetu hasła i potwierdzenia adresu przekazuje do API HTML
bez obrazków; tego wyniku nie wolno przedstawiać jako braku piksela po
doręczeniu.

Zmiana tej gałęzi dodaje pomiar dwóch listów na granicy API, powtarzalne
kontrole ujemne, ten raport i dokładniejszą instrukcję w
`POCZTA_URUCHOMIENIE.md`, krok 6. Nie zmienia transportu, zakresu paczki,
schematu ani publicznej polityki prywatności.

## Co przejęto, a co zmierzono

Przed zmianami przeczytano raport `output/EKSPORT-819-825-RAPORT.md` z
`gpt/eksport` przy SHA `89bca55e98ae73d9773e605c6bfc96c5fd02a9bf`.
**[pomiar cudzy: ten raport]** opisuje naprawy #819–#825 i ich wyniki;
żadnej z podanych tam liczb testów nie zaliczam do własnego wyniku.
Nie przenoszono commitów tej gałęzi ani nie zmieniano jej plików.

**[pomiar cudzy: treść issue #204 i krok 6 runbooka]** 9 września 2026
potwierdzenie adresu doręczone na o2.pl miało `img` i tło CSS kierujące
na `click.kuking.pl/track/o/…`. Odnośnik potwierdzenia pozostał bez
przekierowania. To dowód dotyczący jednego rodzaju listu i wcześniejszego
ustawienia konta. Nie jest pomiarem resetu hasła ani stanu z 20 września.
Fixture `.eml` w repozytorium zawiera przykładowego odbiorcę; własny test
na tej fixture sprawdza wykrywacz, nie odtwarza rzeczywistego doręczenia.

Oba zgłoszenia odczytano przez `gh issue view --repo woogitsu/kuking.pl`
(wersja JSON). W chwili odczytu oba miały stan `OPEN`, bez komentarzy.
Nie zmieniono ich stanu i nie dodano komentarzy.

## #692 — własny pomiar istniejącej naprawy

Historia wskazuje commit `1afd5f697545000b50d1ef36d0aca13e52dd90e5` przy
istniejącym teście `EksportMowiOZdjeciachKtoreNieWejdaNigdyTest`.
Na nietkniętym drzewie uruchomiono ten test wraz z testami zdjęć w drodze,
wykrywacza piksela i transportu: **51 PASS, 327 asercji**.

Test #692 naprawdę buduje ZIP przez `GenerateUserExport`, otwiera go
i czyta `index.html`, `CZYTAJ-TO-NAJPIERW.txt` i `dane.json`. Sprawdza:

- konto z gotowym i odrzuconymi zdjęciami, mimo obecności plików odrzuconych
  w testowym storage: w paczce jest tylko gotowe zdjęcie;
- osobne zdania o liczbie odrzuconych i skasowanych zdjęć oraz o tym, że
  nie wejdą do następnej paczki, wraz z powodem;
- jednoczesną obecność braków trwałych i zdjęć w przygotowaniu bez
  przypisywania odrzuconym rady „Poproś o nową paczkę”;
- odmianę liczebników, brak fałszywego ostrzeżenia przy komplecie zdjęć
  i brak poszerzenia tablicy zdjęć w JSON o odrzucone i skasowane.

Własne kontrole ujemne w izolowanym runtime: osobno zamieniono wynik
`rejectedCount()` i `deletedCount()` na zero. W obu przypadkach test oblał
na brakującym zdaniu w `index.html`, po przywróceniu przeszedł.
Po zakończeniu każdego procesu dodatkowo porównano MD5 i mtime źródła.
Nie opieram się tu na dawnym pliku `docs/design/evidence/eksport692/`.

Wniosek: #692 można przekazać do zamknięcia na podstawie pomiaru tego
commita, bez kolejnej implementacji. Wynik nie jest oglądem produkcyjnej
paczki i nie mówi, jaki SHA jest obecnie wdrożony.

## #204 — co dokładnie wysyła aplikacja

`ListyKontaPrzedEmailLabsTest` przepuszcza prawdziwe powiadomienia
`UstawienieNowegoHasla` i `PotwierdzenieAdresu` przez `MailChannel` i
`TransportEmailLabs`. Atrapa HTTP zatrzymuje ruch dopiero przed siecią;
nie użyto `Mail::fake()`. Wymagane są dokładnie dwa żądania, niepuste HTML,
odnośniki właściwe obu listom i pole `smtpAccount`.

**Własny pomiar: oba żądania mają zero obrazków, zero śladów wykrywacza
i `headers.X-TRACKING-OFF = "1"`.** Test nie mierzy kolejki, dostawcy ani
zawartości skrzynki. Wywołuje bezpośrednio kanał wysyłkowy, żeby zmierzyć
ostateczną treść żądania, bez prawdziwej korespondencji.

Kontrole ujemne: wstawka `img` do szablonu resetu oblewa pomiar śladów;
zmiana wartości nagłówka na `0` oblewa sprawdzenie wyłączenia kliknięć.
Obie kończą się ponowną zielenią i zgodnością MD5 oraz mtime po przywróceniu.

### W których wiadomościach piksel może powstać dalej

Poniżej **inwentaryzacja kodu**, nie lista potwierdzonych doręczeń.
Klasy używają domyślnego mailera; jeżeli jest nim EmailLabs, treść HTML
podlega ustawieniu Open Tracking właściwego subkonta. Nie ma osobnego
przełącznika otwarć zależnego od klasy wiadomości w aplikacji.

| Rodzaj | Klasy |
|---|---|
| Konto i bezpieczeństwo | `PotwierdzenieAdresu`, `UstawienieNowegoHasla`, `LinkDoLogowania`, `UstawienieHaslaZamiastLinku`, `PotwierdzenieNowegoAdresu`, `ZgloszonaZmianaAdresu`, `ProbaWejsciaKontemFacebooka`, `ZaproszenieDoZalozeniaKonta` |
| Zgłoszenia i odwołania | `PotwierdzenieZgloszeniaNielegalnejTresci`, `DecyzjaWSprawieZgloszenia`, `PotwierdzenieOdwolaniaZglaszajacego`, `OdpowiedzNaOdwolanieZglaszajacego` |
| Listy do obsługi serwisu | `PilnyAlarmModeracyjny`, `TerminOdwolaniaBlisko`, `PodsumowanieKolejkiAutomatu` |
| Paczka i kontakt | `DataExportReady`, `OdpowiedzNaWiadomosc` |
| Osobny zakres produktowy: tygodniowy list | `PodsumowanieTygodnia` — wspólny transport, lecz decyzji o mierzeniu digestu nie rozstrzyga #204 |

Jedyny przejęty pomiar rzeczywiście doręczonego HTML dotyczy
`PotwierdzenieAdresu`. Dla pozostałych nie ma w tym zadaniu własnych
surowych źródeł ze skrzynki. Nie wolno powiedzieć „potwierdzono piksel
w resecie hasła” na podstawie samego wspólnego transportu.

`kuking:sprawdz-poczte` używa `Mail::raw()`, czyli wysyła tekst.
Brak piksela w takim liście nie potwierdza wyłączenia wstawek do HTML.
Runbook wcześniej polecał właśnie tę próbę; teraz wskazuje rzeczywiste
wiadomości HTML. Komenda wykrywacza pozostaje narzędziem pomocniczym:
zielony wynik nie dowodzi ustawienia konta ani nie wykrywa automatycznie
każdej możliwej przyszłej techniki śledzenia.

## Konfiguracja dostawcy — aktualne źródła, bez wejścia na konto

20.09.2026 pobrano publiczne
[OpenAPI EmailLabs](https://apidocs.emaillabs.io/openapi.json).
Opis `POST https://api.emaillabs.io/v2.1/email` przypisuje `X-TRACKING-OFF` do śledzenia kliknięć.
`EmailObject` nie zawiera ustawienia otwarć. Endpoint
`https://api.emaillabs.io/v2.1/email/smtpAccount` opisuje listę kont, nie zmianę Open Tracking.
Nie znaleziono w tej dokumentacji nagłówka ani pola wysyłki wyłączającego
otwarcia. To ograniczony wynik przeglądu publicznego API, a nie dowód
nieistnienia opcji dostępnej wyłącznie przez wsparcie.

**Po stronie aplikacji już istnieje:** `EMAILLABS_TRACKING=false`
→ `services.emaillabs.tracking=false` → `X-TRACKING-OFF: 1` w polu
`headers` żądania. To wyłącza kliknięcia według dokumentacji. Nie należy
nazywać tej zmiennej wyłącznikiem piksela otwarć.

Według aktualnej dokumentacji panelu właściciel może wejść:

1. **Email → Email API → Settings → SMTP Accounts**.
2. Wybrać subkonto odpowiadające aktywnemu `EMAILLABS_SMTP_ACCOUNT`.
   W historycznym pomiarze było to `1.mkapica.smtp`.
3. Otworzyć **Additional Settings** przy adresie IP subkonta.
4. W **Open Tracking** ustawić przełącznik na wyłączony. Jeśli interfejs
   wymaga zapisu, zapisać; ponownie otworzyć ustawienie i sprawdzić stan.
5. Na własnych kontach testowych odebrać nowe HTML: reset hasła,
   potwierdzenie adresu i informację o paczce. Zachować prywatnie surowe
   źródła, sprawdzić `text/html`, uruchomić `kuking:sprawdz-piksel` na każdym.
   Do runbooka wpisać datę, typy listów i wyniki bez adresów i tokenów.

Źródła ścieżki:
[SMTP Accounts](https://docs.emaillabs.io/en/email/email-api/settings/smtp-accounts),
[Open Tracking Configuration](https://docs.emaillabs.io/en/email/email-api/settings/smtp-accounts/open-tracking/open-tracking-configuration).
Ścieżka pochodzi z dokumentacji nowego panelu, nie z oglądu konta właściciela.
Starsza dokumentacja używa nazw „Panel analityczny / Funkcjonalności /
Open Tracking”; nie jest to dowód, którą wersję panelu ma dane konto.

## Co mówi polityka prywatności

Własny odczyt `resources/legal/polityka-prywatnosci.md` na badanym SHA:

- §2, wiersz o e-mailach (linia 35): informacja o momencie otwarcia, IP,
  programie pocztowym i obrazku dodawanym przez dostawcę;
- wiersz o tygodniowym liście (36): rozróżnienie niewykorzystywania tych
  danych przez Kuking i dodawania piksela przez dostawcę;
- §3, tabela dostawców (52) oraz akapity o EmailLabs (77–79): dostawca
  i mechanizm są wymienione, tekst zapowiada wyłączenie po jego stronie.

Zatem zarzut całkowitego milczenia polityki nie opisuje tego drzewa.
Samo poinformowanie nie rozstrzyga podstawy zbierania danych ani nie
zastępuje wyłączenia. Nie wydaję opinii prawnej i nie zmieniam podstaw
przetwarzania. Po potwierdzonym wyłączeniu trzeba zaktualizować te zdania;
dziś ich usunięcie obiecywałoby wynik, którego nie zmierzono.
Sformułowanie „w każdym liście” jest szersze od udokumentowanego mechanizmu
HTML — tekstowa diagnostyka nie może służyć ani za dowód tej obietnicy,
ani za dowód wyłączenia. Aktualizacja polityki po decyzji powinna również
usunąć to uogólnienie.

## Kontrole, ograniczenia i wycofanie

Runtime: `/home/mateusz/flota/gpt-analityka-piksel-run`.
PostgreSQL: `127.0.0.1:55439`, rola `kuking`, własna baza
`kuking_flota_gpt-analityka-piksel`. Testy używają lokalnego storage i
atrapy HTTP. Po zmianach źródeł wykonano ponownie skrypt przygotowania.

Końcowe regresje: **52 PASS, 343 asercje**. Cztery kontrole ujemne:
**PASS → FAIL → PASS**, z właściwym powodem czerwieni. Dowody:
`output/analityka-regresje.txt`, `output/analityka-kontrola-*.txt/.json`
i `output/analityka-przywrocenie.json`. Powtórzenie:
`python3 output/analityka-piksel-pomiary.py` po przygotowaniu runtime.
Pole `przywrocenie` w JSON samego przyrządu powstaje przed jego pułapką
EXIT i może mówić „nie wykonane”. Dlatego dowodem końcowym są również
log po EXIT oraz osobne porównania w `analityka-przywrocenie.json`.

Próby techniczne niezaliczone do dowodów: pierwsze wywołanie filtra z `|`
zostało rozdzielone przez powłokę WSL; zastąpiono je jawną listą plików.
Pierwsza kontrola nagłówka odrzuciła niewłaściwy wzorzec oczekiwanego
komunikatu PHPUnit. Dodano nazwany komunikat asercji i powtórzono pełne
cztery kontrole; dopiero te wyniki stanowią dowód.

Pełny przebieg: **4392 PASS, 2 FAIL, 83 708 asercji, 530,99 s**.
Obie porażki były w `DokumentyMdNieMajaMartwychOdnosnikowTest` i dotyczyły
mojej pracy, nie zastanych usterek: raport dopisano po skopiowaniu runtime,
więc odnośnik nie miał tam jeszcze pliku docelowego; zapis samej ścieżki
API dostawcy wyglądał dla strażnika jak trasa aplikacji. Dodano domenę
dostawcy i odświeżono runtime. Końcowe uruchomienie całej klasy strażnika
dokumentacji i nowego testu poczty: **4 PASS, 65 asercji, 3,56 s**.
Nie przedstawiam tego pełnego przebiegu jako zielonego.

Pint `--test --format=json`: PASS, bez plików do poprawy. PHPStan dla nowego
testu: PASS. Polecenia i wyniki: `output/analityka-kontrole-jakosci.txt`.
Pełny log testów pozostaje lokalnie w `output/analityka-pelne-testy.txt`.
Logi w commicie mają usunięte kody kolorów terminala i końcowe spacje;
wyniki nie zostały zmienione.
Z pełnego przebiegu wyłączono wyłącznie `ProbaOdtworzeniaTest`, zgodnie
z instrukcją właściciela dotyczącą wspólnej bazy tego testu.

Nie wykonano pushowania, PR-a, zmian panelu, wysyłek do ludzi, odczytu
prywatnych skrzynek, zmian produkcji ani wdrożenia. Nie zmieniono schematu,
retencji, autoryzacji ani liczby eksportowanych plików. Nie badano wyglądu
w przeglądarce, bo wygląd nie jest zmieniany.

Wycofanie: odwrócić commit tej gałęzi; nie ma migracji ani operacji na
danych. Wycofanie raportu lub testu nie zmienia konfiguracji EmailLabs.

## Czego potrzeba od właściciela

Rekomendowane działanie z istniejącego #204: wyłączyć Open Tracking
i zebrać dowody z doręczonych HTML. Koszt: ustawienie konta oraz próby
na własnych skrzynkach; nie wymaga zmiany transportu aplikacji.

Jeśli opcja jest niedostępna albo nieskuteczna, pozostają warianty:

- wyjaśnienie konfiguracji ze wsparciem EmailLabs przez właściciela —
  koszt oczekiwania i ponownej weryfikacji, bez migracji aplikacji;
- zmiana dostawcy — koszt integracji, DNS, prób doręczalności, dokumentów
  i ustalenia warunków przetwarzania;
- pozostawienie pomiaru otwarć — wymaga osobnej decyzji o celu i oceny
  prawnej; obecny akapit polityki sam tej decyzji nie załatwia.

Nie utrwalam żadnego z tych wariantów asercją. Nowy test pilnuje wyłącznie
obecnej treści aplikacji i istniejącego wyłączenia kliknięć, nie legalizuje
śledzenia u dostawcy.
