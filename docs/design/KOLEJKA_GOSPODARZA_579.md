# Kolejka gospodarza — #579

Podstawa: main `61360bf6178f4ba2b96a928d37c88371adcd7aa7`. Robocza Alfa 0.39.

## Problem i zakres

Kontroler pobierał tylko Post. Przepisy i wykonania bez odzewu nie trafiały do panelu. Przy ręcznym POST odpowiedzi sprawdzano rolę moderatora, ale nie bieżącą kwalifikację wpisu. Nowe zakładki prowadzą do istniejących komentarzy; nie ma automatycznych odpowiedzi ani nowej wysyłki e-mail.

Wspólna reguła `UnansweredContent` wyklucza własną treść gospodarza, prywatne i nieopublikowane treści, zamknięte konta i blokady. Followers wymagają rzeczywistego dostępu. Wykonanie wymaga również dostępnego przepisu. Odzew to widoczny dla odbiorcy komentarz innej osoby; dopisek autora oraz komentarz ukryty/usunięty albo pod niewidocznym korzeniem nie zamykają kolejki. Podziękowanie już jest zwykłym komentarzem.

Zakładki przepisów i wykonań mają strony po 25 pozycji. Wpisy zachowują pierwszeństwo pierwszego wpisu w dotychczasowej liście maksymalnie 50 pozycji; opis jawnie podaje ograniczenie. Wykonania sortuje data publikacji `created_at`, nie historyczna data gotowania. Mediana dotyczy dostępnych gospodarzowi wpisów z 30 dni i używa tej samej definicji odzewu.

Licznik jest mapą per gospodarz we wspólnym cache, przeliczaną istniejącym harmonogramem. Brak nowego haka przy publikacji. Odczyt nie dodaje COUNT; stary cache i nowy gospodarz pokazują brak plakietki do przeliczenia. Opóźnienie do odświeżenia pozostaje ograniczeniem. Nie zmieniono schematu bazy.

## Wykonane lokalnie

- 43 testy / 274 asercje: rodziny kolejki, widoczności, liczników, menu i braku N+1. Istniejące endpointy komentarza rzeczywiście zdejmują przepisy i wykonania z kolejki.
- Rzeczywiste kliknięcia „Otwórz i odpowiedz” w obu nowych zakładkach prowadzą do istniejącej sekcji `#komentarze`; potwierdzono adres i obecność sekcji w przeglądarce.
- Pint i PHPStan bez błędów również po końcowym dopisaniu testu endpointów. Build Vite i 72 pary kontrastu przeszły; CSS `app-BPMggr5p.css`, JS `app-BlSF1GKB.js`. Końcowy hook pozostaje do wykonania.
- 72 konfiguracje, powtórzone po końcowym buildzie: trzy zakładki × 320/360/390/414/768/1440 × dwa motywy × tekst 100/140. Bez poziomego przepełnienia. Dane wyłącznie w lokalnej bazie kuking_560_browser, PG55439.
- Rzeczywisty zoom karty Chromium 200%, getZoom=2, DPR=2, viewport CSS320, tekst140: oba motywy. Pełne Tab: wpisy340/340, przepisy31/31, wykonania9/9; badany widoczny i niezasłonięty fokus. Nie jest to emulacja font32.
- Pięć fizycznych negatywów PHP: brak przepisów, obejście prywatności rodzica wykonania, samoodpowiedź, pominięcie kwalifikacji POST, zgubiona mapa gospodarza. Kopie poza repo; po każdym przywrócone MD5 i mtime. Końcowa kontrola dodatnia27/146. Pierwszy wariant negatywu prywatności był za słaby: usunięcie whereIn nie obchodziło drugiego filtra widoczności; nie zaliczono go i zastąpiono rzeczywistym obejściem.
- Niezależny review subagenta bez blokera. Pierwsze błędy testów wynikały z niewłaściwego ustawienia chronionego statusu i brakującej daty erased; poprawiono fixture, nie ochronę aplikacji.

## Granice

Odbiór nie obejmuje fizycznego telefonu, klawiatury ekranowej ani czytnika ekranu. Zrzuty pełnostronicowe były nieczytelnie wysokie i zastąpiono je widokiem okna; zamknięto prawdziwym przyciskiem podpowiedź pierwszej wizyty przed oglądem samej kolejki.

CI, push, PR, scalenie i produkcja tego pakietu: jeszcze niewykonane. Produkcja potwierdzona osobno na Alfa0.38/61360bf. Pełny port marki pozostaje CZĘŚCIOWO.

Rollback: cofnięcie commita aplikacji; bez migracji. Przy cofnięciu przeliczyć cache kolejek dotychczasowym poleceniem. Treści i komentarze pozostają w bazie.

## Aktualizacja dostarczenia — 15 września 2026

Zwykły push z obowiązkowym hookiem zakończył się sukcesem. PR #580, head ac5ff9d7716d000318870a2120522fdd7930303a, przeszedł wszystkie 11 zadań CI 34996570461. Log zadania PHP104474252355 potwierdza 3850 testów / 77000 asercji. Zakończone zostały również rozszerzony port, dostępność, wydajność i wyścigi na dwóch połączeniach.

PR scalono normalnie jako 108bc93f809ff904baf694b04e96c956d17bdbd3 po sprawdzeniu dokładnego head, wszystkich wyników i stanu PR. Przy odczycie po scaleniu main CI34999668844 oczekiwało, Railway6464297399 było in_progress, a wstępny workflow Deploy34999677073 został pominięty. To jeszcze nie dowód wdrożenia Alfa0.39; pozostaje końcowy odczyt Railway, HTTP i zalogowanej produkcji. Powyższe historyczne „jeszcze niewykonane” opisuje etap przed wysyłką.

### Odbiór produkcji po PR #580

Railway 6464297399 zakończył się success 15.09.2026 o 17:39:24 UTC. Main CI 34999668844 zakończył się success. Produkcja przez HTTP oraz zalogowaną przeglądarkę pokazuje Alfa 0.39 / 108bc93 (pełny SHA 108bc93f809ff904baf694b04e96c956d17bdbd3).

W zalogowanej przeglądarce otwarto kolejkę wpisów, następnie kliknięto zakładki Przepisy i Ugotowałem. Pierwsza pokazała dostępny przepis z odnośnikiem do komentarzy, druga prawidłowy pusty stan. Nie wysyłano odpowiedzi ani nie zmieniano treści produkcyjnych.

Pozostaje oddzielna awaria smoke testu: Deploy 35002625751 / job 104494492772 nie wykonał kroków, ponieważ docker-runner-05 nie mógł utworzyć /home/runner/_work/_tool (Permission denied). Awaria inicjalizacji runnera nie jest wynikiem testów aplikacji. Ten test należy ponowić po usunięciu przyczyny; bieżący HTTP200 i /health200 go nie zastępują.
