## D-127 · Droga równorzędna nigdy nie schodzi na warstwę wgłębioną

**Data:** 11 września 2026 · Rozstrzygnął właściciel · Status: **obowiązuje**

Warstwa wgłębiona (`.ramka-pomocnicza`) mówi wizualnie **„to jest coś obok"**.
Postawienie na niej drogi, która jest równorzędna, byłoby cofnięciem tamtej decyzji
w warstwie wyglądu.

Cofnięte na sekcję i objęte tą regułą: logowanie linkiem (D-056) · wejścia Google
i Facebooka (D-113) · pouczenie DSA art. 16 ust. 5 (D-042) · opis skutków usunięcia
konta (D-022) · oczekująca zmiana adresu (D-048) · kod do ręcznego wpisania
przy włączaniu 2FA.

### Rozstrzygnięcie z 11 września: kod zapasowy przy logowaniu 2FA

`auth/two_factor_challenge.blade.php` trzymał **pełny, działający formularz
logowania kodem zapasowym** („Nie mam dostępu do telefonu") w `<details>` na warstwie
wgłębionej. Kod w tym miejscu uzasadniał to tym, że kod z aplikacji jest metodą
podstawową, a zapasowy — ratunkową.

Właściciel rozstrzygnął: **sekcja**. Człowiek, który stracił telefon, jest
w najgorszym momencie kontaktu z serwisem, a wgłębiona ramka mówi mu „to jest coś
obok". Strukturalnie to ta sama sytuacja, którą rozstrzyga D-056.
