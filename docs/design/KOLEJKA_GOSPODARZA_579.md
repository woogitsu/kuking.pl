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
