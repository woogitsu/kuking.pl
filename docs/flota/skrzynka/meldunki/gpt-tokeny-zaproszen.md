
## 2026-09-20 21:48:42 +02:00 — Poprawka #889 gotowa lokalnie, stanowisko odtworzone i gotowe na zlecenie

Stanowisko: C:\Users\matma\Documents\kuking-flota\gpt-tokeny-zaproszen
Gałąź: gpt/tokeny-zaproszen.

SHA commitów:
- 9b9de13faa11dc66ace453926e47bde106c682b1 — Nie wysyłaj wygasłych resetów hasła i zaproszeń.
- d01036c0ff4e303f67e769c3faa87d2384f8d86b — Zapisz powtórzony pomiar po odtworzeniu stanowiska (HEAD).

Własny pomiar: przy odtworzeniu gałęzi nie było na origin; utworzyłem ją od origin/main = 4c811cc7bff365fb8f86d87eabac93b7738a45cd. Nie przywracałem żadnych plików jako „nie moje”, bo różnica obejmowała wyłącznie własną pracę. Kopia gpt-tokeny-zaproszen-PLIKI pozostaje zachowana. Nic z własnego zadania nie zostało utracone — przed awarią nie powstały własne commity.

Własny pomiar PO odtworzeniu: na kodzie bazowym 16 porażek / 52 asercje; po przywróceniu poprawki 66 zaliczonych testów / 419 asercji. Wykonano serializację i odtworzenie SendQueuedNotifications oraz rzeczywisty kanał pocztowy z ArrayTransport; wygasłe, usunięte i zastąpione tokeny nie dochodzą do transportu. Obie klasy respektują termin konkretnego żądania. Pint --test: 8 plików bez uwag. Baza wyłącznie kuking_flota_gpt-tokeny-zaproszen, 127.0.0.1:55439. Przy kontroli ujemnej przywróciłem bajty i mtime trzech plików, sprawdziłem MD5 i różnicę Git.

Własny pomiar SPRZED awarii: pełna suita miała 4408 zaliczonych i 1 porażkę (fixture NadawcaPocztyNieJestNoreplyTest z tokenem bez rekordu). Fixture poprawiono; wszystkie pięć testów nadawcy wchodzi w późniejszy zielony zestaw 66 testów. Nie przedstawiam tego jako w całości zielonego przebiegu pełnej suity. W tej chwili ponownie sprawdziłem Git: oba commity istnieją, pliki śledzone nie mają niezapisanych zmian; nieśledzone output/ zawiera lokalne dowody pomiarów. Nie przejmuję cudzych wyników jako własnych; wzorzec ce394d8c był odczytem kodu, nie pomiarem.

CZEGO NIE ZROBIŁEM I DLACZEGO: nie wykonałem push ani nie otworzyłem PR — obowiązuje szeregowa kolejka koordynatora. Nie uruchamiałem CI na GitHubie ani nie scalałem gałęzi. Nie wysłałem rzeczywistego maila, nie badałem doręczenia EmailLabs, otwarć ani skrzynek odbiorców — zakres obejmuje wyłącznie zachowanie aplikacji. Po odtworzeniu nie powtarzałem całej suity: powtórzyłem wymagany najważniejszy pomiar czerwony/zielony i siedem klas testów poczty. ProbaOdtworzeniaTest był pominięty również w pełnym przebiegu, zgodnie z jawnym wyjątkiem zlecenia dotyczącym wspólnej bazy. Nie przenosiłem do tej gałęzi osobnej poprawki LinkDoLogowania z ce394d8c; zadanie obejmowało reset i zaproszenie, a ten commit służył wyłącznie jako wzorzec. Nie zmieniałem schematu bazy, nie odtwarzałem cudzych gałęzi i nie używałem worktree prune. Nie usunąłem kopii -PLIKI ani dowodów. Nie tworzyłem pliku zleceń za koordynatora.

Decyzje: brak nierozstrzygniętej decyzji produktowej — pomijanie wygasłych listów jest wprost zlecone. Do koordynacji pozostaje publikacja: wariant A — przekazać oba commity do szeregowej kolejki (koszt: przegląd i CI); wariant B — pozostawić lokalnie do kolejnego zlecenia (koszt: poprawka jeszcze nie trafi na produkcję). Sam nie wybieram terminu push.

Raport na gałęzi: C:\Users\matma\Documents\kuking-flota\gpt-tokeny-zaproszen\docs\security\TERMIN_RESETU_I_ZAPROSZENIA_889.md
Dowody po odtworzeniu: C:\Users\matma\Documents\kuking-flota\gpt-tokeny-zaproszen\output\tokeny-zaproszen-889-odtworzenie\czerwien.txt oraz zielen.txt.

ZATRZYMANIE: dotychczasowe zadanie zakończone lokalnie. Plik zleceń gpt-tokeny-zaproszen.md nie istnieje przy sporządzaniu meldunku. Jestem gotowy na kolejne zlecenie; nie odpytuję skrzynki w pętli i nie improwizuję prac na współdzielonym repozytorium.
