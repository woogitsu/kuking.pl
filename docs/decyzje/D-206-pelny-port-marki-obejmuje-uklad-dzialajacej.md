## D-206 · Pełny port marki obejmuje układ działającej aplikacji

**Data:** 13 września 2026 · PR #488 · Status: **obowiązuje**

### Decyzja

Alfa 0.9 przenosi zaakceptowany projekt do istniejących ekranów Laravel:
pływającą ramę, menu desktop w nagłówku, pięć pozycji mobilnych, ciemny
kafel publikacji i własnego profilu, mocną typografię oraz wspólne karty
i formularze. Zwykły użytkownik nie ma widocznego lewego paska; panel
moderacji zachowuje swój tryb roboczy. Wejście do niego w menu konta
pokazuje sumę kolejek wyłącznie poza panelem.

Konstytucja v1.2 opisuje całą rodzinę ekranów i głos marki. Garnek z koroną
i uśmiechem pozostaje znakiem; ikony instalowanej aplikacji i udostępniania
korzystają z nowej palety. Statyczne osoby, liczniki i funkcje demonstracji
nie są przenoszone jako dane lub zachowanie produkcji.

### Dowód i granice

Kod scalono jako `66980acc83ea8298b76771682e4bda96264a6484`.
Pomiar nowej ramy sprawdza jej własną szerokość, wyśrodkowanie, padding
i zawartość, zamiast wymagać wyrównania szerszej belki do węższej treści.
Kontrole ujemne wykrywają rzeczywiste regresje. Wymagane są zielone CI
oraz sprawdzenie dostarczonego HTML i CSS po wdrożeniu. Publiczny test
dymny nie zastępuje odbioru ekranu zalogowanego.

Sześć ostrzeżeń częściowego zasłonięcia fokusu długiej nazwy przy
powiększeniu pozostaje jawnie w #485; test nie podnosi progów ani nie
usuwa nazw. Wyniki i zakres oglądanych zrzutów zapisuje raport Alfa 0.9.

📄 `docs/brand/KONSTYTUCJA_MARKI.md` · `docs/design/PORT_PROJEKTU.md` ·
`docs/design/WERYFIKACJA_ALFA_09.md`
