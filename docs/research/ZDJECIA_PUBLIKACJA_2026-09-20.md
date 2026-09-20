# Zdjęcia w formularzach publikacji — 20 września 2026

Zakres: #873, #872, #871, #874. Baza kodu: `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`; gałąź `gpt/zdjecia-publikacja`.
Wszystkie wyniki poniżej są własnymi pomiarami lokalnymi. Opisy zgłoszeń były hipotezami do odtworzenia, nie dowodami wykonania.

## Zmiana zachowania

- **#873:** kontrolery rozpoznają zakończone wysłanie danej osoby przed przetwarzaniem zdjęć. Sprawdzają dostęp do znalezionego rezultatu; „Ugotowałem” najpierw nadal sprawdza prawo do gotowania przepisu. Dotychczasowe indeksy UNIQUE i obsługa ich zderzenia pozostają rozstrzygające dla wyścigu. Inna osoba lub nowy klucz nadal tworzą osobny rezultat.
- **#872:** poprawne zdjęcia „Ugotowałem” są zapisywane przed walidacją tekstu. Kolejny błąd przenosi te same identyfikatory. Formularz pokazuje podgląd i przycisk usunięcia zdjęcia z przygotowywanego wykonania. Usunięcie nie publikuje wykonania ani nie kasuje wpisanego tekstu. Odpięty plik pozostaje dla istniejącego sprzątania osieroconych zdjęć; nie dodano nowego mechanizmu sprzątania. Czas z formularza jest jawnie zamieniany na liczbę po walidacji — próba poprawnego wysłania z `"90"` ujawniła wcześniej TypeError przy wejściu do akcji domenowej.
- **#871:** kolekcję zachowanych zdjęć przygotowuje kontroler przez `RecoveredFormPhotos`. Zapytanie dostaje wyłącznie ograniczoną, płaską listę UUID. Własność, usunięcie oraz wszystkie istniejące odwołania do mediów są sprawdzane przed odtworzeniem. Dotyczy wpisu, pytania i nowego odtwarzania w „Ugotowałem”.
- **#874:** jawna mapa celów w tych trzech formularzach kieruje błędy `photos.*` i `media_ids` do `f-photos`. Input ma `aria-invalid` i powiązany tekst błędu. Pytanie pokazuje pole wyboru również przy zachowanym zdjęciu, jeżeli trzeba poprawić błąd zdjęć. Pozostałe indeksowane pola, np. `steps.0.instruction`, zachowują dotychczasowe kotwice.

Nie ma migracji, nowych zależności ani zmian infrastruktury. Wersja: Alfa 0.68.

## #873 — rzeczywisty koszt przed i po

Fixture: syntetyczny JPG 800 × 600, za każdym razem nowy obiekt przesłanego pliku, ten sam klucz w parze żądań. Pomiar wykonuje pełny kernel HTTP aplikacji, zapis do własnej bazy PostgreSQL i lokalnego storage; kolejka jest przechwycona, więc liczymy zlecone `ProcessUploadedImage`, bez wykonywania wariantów w tle. Dwa pliki jednego zdjęcia to oryginał i synchroniczny podgląd. To liczby operacji/skutków, nie pomiar czasu CPU, transferu R2 ani częstości ponowień w produkcji.

Poniższe liczby są sumą po **dwóch** żądaniach i są identyczne osobno dla wpisu, pytania oraz „Ugotowałem”:

| Scenariusz | Media | Obiekty storage | Zlecone zadania | Wpis/wykonanie | Powiadomienie „Ugotowałem” |
|---|---:|---:|---:|---:|---:|
| Kolejno, przed | 2 | 4 | 2 | 1 | 1 |
| Kolejno, po | 1 | 2 | 1 | 1 | 1 |
| Jednocześnie, przed | 2 | 4 | 2 | 1 | 1 |
| Jednocześnie, po | 2 | 4 | 2 | 1 | 1 |

Dla wpisu i pytania kolumna powiadomienia o ugotowaniu nie ma zastosowania (w JSON wynosi 0). Obie odpowiedzi to 302 do tego samego rezultatu.

Wyścig: dwa procesy PHP i dwa różne `pg_backend_pid()`. Bariera przy utworzeniu Media wymusza, że oba żądania rozpoczęły upload, zanim którekolwiek opublikuje rezultat. To świadomie najgorszy układ dla dodatkowego SELECT-a. **Nie usunięto kosztu jednoczesnych uploadów.** Pomiar nie jest testem wymuszającym, aby ten koszt pozostał na zawsze.

Pierwsza czerwień sekwencyjna była zmierzona przed implementacją. Dodatkowo końcowe porównanie odtworzyło w izolowanym runtime oba kontrolery z bazowego SHA, używając tych samych generatorów fixture. Po pomiarze przywrócono bieżące bajty i mtime kontrolerów. Pozostały kod podczas tego porównania był bieżący — nie opisujemy go jako pełnego checkoutu bazowego SHA.

Surowe liczniki: [przed kolejno](zdjecia-publikacja-dowody/przed-kolejno.json), [po kolejno](zdjecia-publikacja-dowody/po-kolejno.json), [przed równocześnie](zdjecia-publikacja-dowody/przed-rownoczesnie.json), [po równocześnie](zdjecia-publikacja-dowody/po-rownoczesnie.json).

Skrypty pomiarowe są obok wyników: `pomiar-873-sekwencyjny.php` i `pomiar-873.php`. Uruchamia się je z korzenia przygotowanego runtime przez PHP 8.4, z `APP_ENV=testing` i jawnym połączeniem do opisanej niżej bazy. Odmawiają innego hosta/portu/nazwy. Tworzą dane syntetyczne i własne katalogi `storage/app/pomiar-873-*`; nie uruchamiać ich równolegle z testami niszczącymi tę bazę.

## Regresje i kontrole ujemne

Nowe testy: `PonowieniePublikacjiNieWgrywaZdjecTest` oraz `ZdjeciaFormularzyPublikacjiTest`.

Przed poprawką: dodatkowe pliki po ponowieniu; brak zachowanych `media_ids` po błędzie innego pola; HTTP 500 z PostgreSQL przy `nie-uuid`; kotwica `f-photos-0` bez celu. Końcowe testy sprawdzają też dwa kolejne błędy, błędny czas oraz obie za długie notatki, usunięcie zdjęcia, obce/przypięte/usunięte media i poprawną kontrolę własnego pliku.

Macierz kotwic obejmuje 3 formularze × błędy `photos.0`, `photos.1`, `photos` × obecność/brak odzyskanego zdjęcia. Osobno sprawdzono, że mapa nie zmienia kotwicy kroku przepisu. Pełny POST → przekierowanie → GET sprawdza nie-UUID, tablicę zagnieżdżoną, null i pustą listę na PostgreSQL, razem z zachowaniem tekstu.

Pięć fizycznych mutacji przeszło PASS → FAIL z oczekiwanej przyczyny → PASS:

1. Wyłączenie wcześniejszego zwrotu wpisu/pytania.
2. Wyłączenie wcześniejszego zwrotu wykonania.
3. Usunięcie zachowanych identyfikatorów po walidacji tekstu.
4. Usunięcie filtrowania UUID przed zapytaniem.
5. Usunięcie mapowania kotwicy zdjęcia.

[Wyniki i log kontroli](zdjecia-publikacja-dowody/kontrole.txt). Każda mutacja została potwierdzona różnicą źródła i zmianą MD5. Trap przywrócił źródła; zewnętrzny pomiar dodatkowo porównał MD5 i mtime wszystkich plików przed i po całym przebiegu: [przywrocenie.json](zdjecia-publikacja-dowody/przywrocenie.json). Pole `przywrocenie` w pojedynczych JSON-ach narzędzia powstaje przed trapem, dlatego nie zastępuje tego końcowego pomiaru.

Dwie korekty wyłącznie oprzyrządowania runtime: warunek `grep` korzysta z here-string zamiast potoku podatnego na SIGPIPE, a każda próba czyści cache widoków. Bez drugiej korekty przywrócenie dawnego mtime pozwalało użyć skompilowanego, zmutowanego Blade. Nie zmieniono repozytoryjnego skryptu kontroli.

## Przeglądarka

Własna baza przeglądarkowa, konto syntetyczne, Chromium, rzeczywiste formularze i przesłanie pliku:

- **JavaScript wyłączony:** zdjęcie + notatka 2001 znaków → błąd, widoczny podgląd i zachowany identyfikator; poprawienie tylko notatki i wpisanie 90 minut → zapisane wykonanie z pierwotnym zdjęciem. [Zrzut przy szerokości 320 px](zdjecia-publikacja-dowody/872-zachowane-bez-js.png).
- **JavaScript włączony:** zachowanie zdjęcia po błędzie; przycisk „Usuń zdjęcie z wykonania” usuwa identyfikator i podgląd, zostawia 2001 znaków notatki oraz formularz bez publikacji.
- **Każdy z 3 formularzy, JS włączony:** plik tekstowy odrzucony jako zdjęcie; fokus odnośnika + Enter daje `document.activeElement.id === 'f-photos'`, `aria-invalid="true"` i `aria-describedby` wskazujące komunikat. Przy 1280 px brak poziomego przepełnienia.
- Bez JS odtworzono również odrzucenie pliku we wpisie i poprawny adres kotwicy. Próba automatycznego odczytu fokusu w kontekście bez JS zawiesiła narzędzie; tej części nie uznano za zaliczoną. Nie wykonano próby sprzętowym czytnikiem ekranu — zweryfikowano DOM, ARIA i klawiaturę, nie odsłuch.

## Weryfikacja końcowa i środowisko

- Standardowy pełny zestaw: **4428 testów, 83784 asercje, 365,83 s**, bez porażek.
- Polecenie: `php artisan test --exclude-group=dwa-polaczenia --filter '^(?!.*ProbaOdtworzeniaTest)'`.
- `ProbaOdtworzeniaTest` pominięto zgodnie z jawnym wyjątkiem zlecenia (wspólna baza źródłowa). Grupa `dwa-polaczenia` jest osobnym przebiegiem wyłączonym także w domyślnej konfiguracji; zamiast deklarować jej wykonanie, powyżej podano własny pomiar wyścigu dla wszystkich 3 zmienianych ścieżek.
- Końcowy przebieg nowych regresji po porządkowaniu widoków: **45 testów, 297 asercji**, bez porażek.
- Pint wykonany na zmienianych plikach PHP; końcowe `pint --test`: **1162 pliki, PASS**. Pierwsza próba wykazała tylko formatowanie trzech własnych skryptów pomiarowych w `output/`; zostały sformatowane i kontrola powtórzona. PHPStan całego projektu bez błędów; `npm run build` zakończony powodzeniem.
- Testy: `127.0.0.1:55439`, baza `kuking_flota_gpt-zdjecia-publikacja`, właściciel `kuking`.
- Przeglądarka: ten sam host/port PostgreSQL, osobna baza `kuking_flota_gpt-zdjecia-publikacja_browser`; lokalny serwer HTTP port 18732. Serwer został zatrzymany po próbach.
- Runtime: `/home/mateusz/flota/gpt-zdjecia-publikacja-run`; `vendor` i `node_modules` skopiowane, bez dowiązań. Wspólne skrypty wymagają rzeczywistej nazwy katalogu `gpt-zdjecia-publikacja`.

## Ryzyka, wycofanie i decyzje właściciela

Pozostaje zmierzony koszt równoczesnych uploadów. Dalsze ograniczenie wymaga osobnego wyboru: blokada per osoba/klucz obejmująca zapis plików (dłużej zajęte połączenie i obsługa oczekiwania) albo trwała rezerwacja wysłania (nowy stan, odzyskiwanie po awarii, ewentualna migracja). Pozostawienie obecnego UNIQUE wraz z optymalizacją zakończonych wysłań jest najmniejszą zmianą i realizuje kierunek #873. Nie wybrano za właściciela nowego mechanizmu ani nie dodano go przy okazji.

Wycofanie: `git revert 6718d527` (commit implementacji); brak zmiany schematu i potrzeby cofania bazy. Przywróci to opisane usterki formularzy. Zachowane, nieprzypięte zdjęcia podlegają dotychczasowym porządkom osieroconych mediów.

Nie wykonywano push, PR, CI zdalnego, zmian produkcji, wysyłki wiadomości ani operacji w cudzych worktree. Nie mierzono ruchu produkcyjnego ani zewnętrznego storage.
