# Audyt ekranów Kuking.pl dla osób 60+

**Data audytu:** 2026-09-10  
**Bazowy commit `main`:** `9074d724a46d99bd8d4a33e0c14a8afb7be85419`  
**Zakres:** ekrany Kuking.pl, kryteria WCAG 2.2 nieweryfikowane skutecznie przez obecny automat oraz użyteczność dla osób 60+, zwłaszcza 65–75.  
**Metoda:** przegląd `AGENTS.md`, `docs/UX_50_PLUS.md`, `resources/css/tokens.css`, `scripts/dostepnosc.mjs`, `docs/product/PROSTOTA_JAK_GARNEK.md`, tytułów D-001…D-06x w `docs/DECISIONS.md`, kodu Blade/CSS/JS/PHP oraz źródeł W3C, NN/g i badań HCI.

## Wniosek

Kuking ma mocną bazę dostępności: wspólny `aria-live`, jawne kontrolki karuzeli, brak znalezionych mechanizmów drag-only, logowanie linkiem e-mail, możliwość wklejania kodu TOTP oraz bardzo dobry ekran 419 z odzyskiwaniem po błędzie. Nie ma podstaw do sztucznego produkowania ustaleń w obszarach, które przechodzą.

Najważniejsze ryzyka poza obecnym automatem są następujące:

1. mobilna dolna nawigacja jest `fixed` i może zawinąć się przy dużym tekście, a rezerwa pod treścią nie jest związana z jej rzeczywistą wysokością — dla WCAG 2.2 2.4.11 brakuje dziś binarnego dowodu runtime;
2. rejestracja mówi „Cztery pola i gotowe”, po czym konto trafia na „Krok 1 z 3”, co może sugerować, że rejestracja nadal trwa;
3. TOTP technicznie wspiera wklejenie/autouzupełnienie, ale instrukcja każe kod „przepisać”, czyli promuje trudniejszą poznawczo drogę;
4. awaryjna konfiguracja 2FA używa technicznego języka „sekret (klucz TOTP)” właśnie wtedy, gdy użytkownikowi nie zadziałała droga przez QR;
5. pierwszy formularz wymaga zrozumienia i wymyślenia dwóch podobnych identyfikatorów: nazwy wyświetlanej i nazwy użytkownika;
6. anonimowy landing pokazuje gęstą tablicę interaktywną przed prostym wyjaśnieniem „Jak działa”.

## Świadomie wyłączone z audytu

Nie raportuję jako ustaleń: kontrastu, nominalnego rozmiaru tekstu, poziomego reflow, etykiet pól, `alt`, hierarchii nagłówków ani czterech problemów naprawionych po sesji z 63-letnią użytkowniczką. Nie proponuję zmiany dwóch dróg „Dodaj” ani pokazywania zdjęcia od razu po publikacji.

Wyjątkiem od wyłączenia reflow jest **pionowe zasłonięcie fokusu przez `fixed`/`sticky`**. Obecny automat mierzy szerokość dokumentu, ale nie przecinanie prostokąta fokusu z nakładkami.

## Źródła

### WCAG 2.2

- 3.3.8 Accessible Authentication (Minimum): https://www.w3.org/TR/WCAG22/#accessible-authentication-minimum  
  Understanding: https://www.w3.org/WAI/WCAG22/Understanding/accessible-authentication-minimum.html
- 3.3.7 Redundant Entry: https://www.w3.org/TR/WCAG22/#redundant-entry  
  Understanding: https://www.w3.org/WAI/WCAG22/Understanding/redundant-entry.html
- 3.2.6 Consistent Help: https://www.w3.org/TR/WCAG22/#consistent-help  
  Understanding: https://www.w3.org/WAI/WCAG22/Understanding/consistent-help.html
- 2.4.11 Focus Not Obscured (Minimum): https://www.w3.org/TR/WCAG22/#focus-not-obscured-minimum  
  Understanding: https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html
- 2.5.7 Dragging Movements: https://www.w3.org/TR/WCAG22/#dragging-movements  
  Understanding: https://www.w3.org/WAI/WCAG22/Understanding/dragging-movements.html
- 2.5.8 Target Size (Minimum): https://www.w3.org/TR/WCAG22/#target-size-minimum  
  Understanding: https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html
- 4.1.3 Status Messages: https://www.w3.org/TR/WCAG22/#status-messages  
  Understanding: https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html
- 1.4.13 Content on Hover or Focus: https://www.w3.org/TR/WCAG22/#content-on-hover-or-focus  
  Understanding: https://www.w3.org/WAI/WCAG22/Understanding/content-on-hover-or-focus.html
- Cloudflare Turnstile, dokumentacja dostępna 2026-09-10: https://developers.cloudflare.com/turnstile/concepts/widget/ oraz https://developers.cloudflare.com/cloudflare-challenges/challenge-types/turnstile/

### Badania i materiały o starszych użytkownikach

- Jakob Nielsen, NN/g, **2013-05-28**, „Usability for Senior Citizens: Improved, But Still Lacking”: https://www.nngroup.com/articles/usability-seniors-improvements/ — osoby 65+ miały w badaniu 55,3% skuteczności wobec 74,5% w grupie 21–55 oraz średnio 2,4 błędu wobec 1,1. To sygnał projektowy, nie twierdzenie o każdym seniorze.
- Lexie Kane, NN/g, **2019-09-08**, „Usability for Older Adults: Challenges and Changes”: https://www.nngroup.com/articles/usability-for-senior-citizens/ — starsze kohorty są coraz bardziej obyte cyfrowo; nie należy projektować na podstawie stereotypu, lecz usuwać konkretne koszty interakcji.
- W3C WAI, **aktualizacja 2025-11-20**, „Older Users and Web Accessibility”: https://www.w3.org/WAI/older-users/ — wskazuje m.in. możliwe pogorszenie pamięci krótkotrwałej, koncentracji i odporności na rozproszenie.
- W3C WAI, **aktualizacja 2018-02-22**, „Overview of Web Accessibility for Older Users: A Literature Review”: https://www.w3.org/WAI/older-users/literature/ — łączy ograniczenia pamięci krótkotrwałej i koncentracji z trudnościami w nawigacji i kończeniu zadań.
- W3C WAI, „Developing Websites for Older People”: https://www.w3.org/WAI/older-users/developing/ — **data publikacji/aktualizacji [do weryfikacji]**, dostęp 2026-09-10; materiał zaleca m.in. prosty język i konsekwentną organizację informacji.
- A. Chevalier, A. Dommes, D. Martins, **publikacja online 2012-05-15**, „The effects of ageing and website ergonomic quality on internet information searching”, *Ageing & Society*: https://www.cambridge.org/core/journals/ageing-and-society/article/abs/effects-of-ageing-and-website-ergonomic-quality-on-internet-information-searching/F249F5E722F88B4951AD43BAE6A60BF6 — autorzy odnoszą wyniki do mniejszej pojemności pamięci roboczej, słabszego hamowania informacji nieistotnych i wolniejszego przetwarzania; jakość ergonomiczna wpływa na wyszukiwanie informacji.
- Mead i in., **1996; dokładny dzień publikacji [do weryfikacji]**, „Online library catalogs: age-related differences in query construction and error recovery”: https://experts.illinois.edu/en/publications/online-library-catalogs-age-related-differences-in-query-construc/ — starsi uczestnicy popełniali więcej błędów przy budowie zapytań i mniej sprawnie z nich wychodzili. Źródło stare i domenowo wąskie; używam go tylko pomocniczo przy projektowaniu odzyskiwania po błędzie.

## A. WCAG 2.2 poza zakresem obecnego automatu

| kryterium | ocena | dowód w kodzie | wniosek |
|---|---|---|---|
| **3.3.8 Accessible Authentication (Minimum)** | **spełnione w sprawdzonym kodzie; UX TOTP do poprawy** | `resources/views/auth/login.blade.php` używa `autocomplete="current-password"`; `resources/views/auth/two_factor_challenge.blade.php` ma `autocomplete="one-time-code"`, brak blokady wklejania i kod zapasowy; `resources/views/auth/login-link.blade.php` daje logowanie podpisanym linkiem; `resources/views/components/turnstile.blade.php` używa Managed Turnstile. | Hasło i transkrypcja OTP dotykają funkcji poznawczych, ale WCAG dopuszcza mechanizm wspomagający. Kuking nie blokuje menedżera haseł/wklejenia/autouzupełnienia. E-mailowy link jest alternatywą bez zapamiętywania sekretu. Problemem jest copy „przepisz”, nie sama implementacja pola TOTP. |
| **3.3.7 Redundant Entry** | **spełnione** | `register.blade.php` zbiera dane raz; `RegisterController.php` prowadzi do onboardingu; `onboarding/interests.blade.php` i `people.blade.php` zbierają inne, opcjonalne dane; `verify-email.blade.php` pokazuje zapisany e-mail; `settings/profile.blade.php` prewypełnia dane. | Nie znalazłem obowiązkowego ponownego wpisywania informacji już podanych w tym procesie. `reset-password.blade.php` prosi o powtórzenie nowego hasła, ale Understanding 3.3.7 przewiduje wyjątek dla celu bezpieczeństwa. |
| **3.2.6 Consistent Help** | **spełnione architektonicznie na ekranach używających `<x-layout>`** | `resources/views/components/layout.blade.php` centralizuje footer „Pomoc i kontakt”, `Napisz do nas` i `Pomoc`. | Pomoc ma stałe miejsce i kolejność. Ryzykiem jest przyszły ekran omijający layout; warto zabezpieczyć to testem. |
| **2.4.11 Focus Not Obscured (Minimum)** | **brak dowodu zgodności / wysokie ryzyko warunkowe** | `resources/css/app.css`: `.bottom-nav` jest `position: fixed` i ma `flex-wrap: wrap`; komentarze przewidują dodatkowy rząd przy dużym tekście. Rezerwa treści bazuje na stałym spacingu; brak znalezionego `scroll-padding`/`scroll-margin`. | Z samego CSS nie wolno uczciwie oznaczyć FAIL: minimum AA oblewa dopiero, gdy fokusowany element jest całkowicie zakryty treścią autora. Kod nie zapewnia jednak inwariantu „rezerwa ≥ rzeczywista wysokość belki”, więc potrzebny jest test geometrii przy Tab. |
| **2.5.7 Dragging Movements** | **spełnione w sprawdzonym kodzie** | `resources/views/components/karuzela-zdjec.blade.php` ma jawne poprzedni/następny, a istniejący automat sprawdza karuzelę bez JS. Nie znaleziono użytkowego `draggable` ani `type="range"` wymagającego drag. | Nie ma funkcji dostępnej wyłącznie przez przeciąganie. |
| **2.5.8 Target Size (Minimum)** | **nie znaleziono naruszenia; brak pełnego dowodu automatycznego** | Komponenty kontrolne są projektowane powyżej 24×24 CSS px, m.in. `.bottom-nav-item` ma produktową minimalną wysokość dotykową. | Warto mierzyć realne prostokąty wszystkich kontrolek i obsłużyć wyjątki WCAG, bo samo axe nie daje kompletnego kontraktu. |
| **4.1.3 Status Messages** | **spełnione na sprawdzonych ścieżkach** | `layout.blade.php`: globalny status w `aria-live="polite"`; `recipe-wizard.blade.php`: autosave w live region; `pages/recipes/cooking.blade.php`: statusy/progress live; `resources/js/app.js`: dynamiczne komunikaty uploadu live/alert. | Czytnik ekranu może usłyszeć zmianę bez przenoszenia fokusu. |
| **1.4.13 Content on Hover or Focus** | **nie znaleziono naruszenia** | Nie znaleziono autorskiego tooltipu/panelu ujawnianego wyłącznie hoverem ani ważnej funkcji zależnej tylko od `title`. | Nowy komponent tego typu powinien być automatycznie flagowany do runtime-checku: dismissible, hoverable, persistent. |

### 3.3.8 — uwierzytelnianie w Kuking

**Hasło min. 10 znaków.** Zapamiętywanie hasła jest przykładem zadania poznawczego, ale wyjątek „Mechanism” pozwala osiągnąć zgodność przez menedżer haseł, autouzupełnianie lub wklejanie. `login.blade.php` używa `autocomplete="current-password"` i nie blokuje paste. Sama długość 10 znaków nie powoduje naruszenia.

**Logowanie linkiem e-mail — D-056.** To mocna alternatywa dla 60+: użytkownik nie musi zapamiętywać ani przepisywać sekretu. Understanding 3.3.8 wymienia uwierzytelnianie linkiem jako wystarczającą technikę (G218). Nie raportuję wskazanego w zleceniu, właśnie poprawianego problemu copy na tym ekranie.

**Turnstile — D-050.** Obecny Managed Turnstile wybiera interakcję automatycznie; dokumentacja Cloudflare opisuje ścieżki bez interakcji lub prostą interakcję, nie klasyczne rozpoznawanie obrazków/tekstu. Sam checkbox nie jest testem funkcji poznawczej wymienionym w 3.3.8. Dostawca pozostaje jednak zależnością: jeśli na ekranie auth pojawi się łamigłówka, rozpoznawanie obiektów albo transkrypcja, kryterium trzeba ocenić ponownie.

**TOTP moderatorów.** Understanding 3.3.8 zalicza transkrypcję do testów funkcji poznawczych, ale przy kodzie z drugiego urządzenia zakłada możliwość przeniesienia wartości przez schowek; oceniana strona nie może uniemożliwiać wklejenia. `two_factor_challenge.blade.php` nie blokuje paste i ma `autocomplete="one-time-code"`. Dlatego nie oznaczam implementacji jako naruszenia AA. Copy „przepisz sześciocyfrowy kod” jest jednak gorsze niż możliwości formularza i powinno promować wpisanie **lub wklejenie/autouzupełnienie**.

### 3.3.7 — rejestracja → potwierdzenie e-mail → profil

Ścieżka jest zgodna: e-mail nie jest wymagany drugi raz przy potwierdzeniu, profil ma wartości wstępnie wypełnione, a onboarding zbiera inne, opcjonalne informacje. Powtórzenie nowo ustawianego hasła mieści się w wyjątku bezpieczeństwa z Understanding 3.3.7. Problem „Cztery pola i gotowe” → „Krok 1 z 3” jest więc problemem oczekiwania i pamięci roboczej, nie Redundant Entry.

### 3.2.6 — pomoc

`layout.blade.php` centralizuje footer z „Pomoc i kontakt”. Widoki auth, onboarding, ustawienia i główne strony używają wspólnego układu. To daje stałe położenie na poziomie architektury. Najlepsza ochrona przed regresją to porównywanie mechanizmów i kolejności pomocy w całej macierzy tras.

### 2.4.11 — belki stałe

Górna belka jest `sticky`, dolna na telefonie `fixed`. Samo to nie narusza WCAG. Ryzyko powstaje, gdy po przejściu Tabem element aktywny trafia całkowicie pod autorską nakładkę.

`app.css` świadomie pozwala `.bottom-nav` zawijać elementy przy powiększeniu tekstu. To poprawia poziomy reflow, ale zwiększa wysokość belki. Rezerwa treści nie jest wyliczana z jej rzeczywistej wysokości. Wynik audytu to **brak dowodu PASS i wysokie ryzyko**, nie statycznie potwierdzony FAIL. CI powinno rozstrzygnąć to geometrycznie na 320/360/414 px i wszystkich trzech istniejących skalach tekstu.

### 2.5.7, 2.5.8, 4.1.3 i 1.4.13

Karuzela ma alternatywę single-pointer, nie znaleziono drag-only ani suwaka wymagającego przeciągania. Nie znaleziono też konkretnego celu poniżej formalnego minimum 24×24, lecz to powinien mierzyć runtime. Statusy po zmianach dynamicznych są live. Nie znaleziono autorskiej treści dostępnej tylko hoverem. Te cztery wyniki powinny zostać utrwalone w CI zamiast przepisane na fałszywe „usterki”.

## B. Badania 60+ przełożone na konkretne ekrany

| ekran (plik) | co jest źle | podstawa (kryterium WCAG z numerem albo źródło z linkiem) | jak to boli osobę 65-letnią | co zmienić | jak sprawdzić, że pomogło |
|---|---|---|---|---|---|
| **Wszystkie zalogowane ekrany mobilne** — `resources/css/app.css` (`.bottom-nav`, `.app-main`, footer) + `resources/views/components/layout.blade.php` | Fixed bottom-nav może zwiększyć wysokość po zawinięciu podpisów, a rezerwa treści nie jest sprzężona z tą wysokością. Brak dowodu, że fokus nigdy nie schowa się całkowicie pod paskiem. | **WCAG 2.2 2.4.11**: https://www.w3.org/TR/WCAG22/#focus-not-obscured-minimum ; Understanding: https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html | Użytkownik klawiatury/dużej czcionki może „zgubić” aktywną kontrolkę. NN/g 2013-05-28 pokazuje w grupie 65+ więcej błędów i większy koszt odzyskania: https://www.nngroup.com/articles/usability-seniors-improvements/ | Najpierw test geometrii; potem CSS-owa gwarancja miejsca pod rzeczywistą wysokość belki lub wariant układu, który przy dużym tekście nie nakłada na treść. Nie dodawać JS do podstawowej nawigacji tylko po to, by naprawić layout. | Playwright: 320/360/414 × normalny/140%/browser-font 200%; Tab przez wszystkie widoczne kontrolki. Formalny FAIL, gdy `focusedRect` jest w 100% przykryty przez `.topbar`/`.bottom-nav`; osobny produktowy warning przy częściowym przecięciu. |
| **Rejestracja → onboarding** — `resources/views/auth/register.blade.php` („Cztery pola i gotowe”), `app/Http/Controllers/Auth/RegisterController.php`, `resources/views/pages/onboarding/interests.blade.php` („Krok 1 z 3”) | Obietnica końca procesu jest sprzeczna z wyglądem następnego ekranu. Dane nie są powtarzane, więc to nie 3.3.7. | W3C Older Users, **2025-11-20**: https://www.w3.org/WAI/older-users/ ; W3C literature review, **2018-02-22**: https://www.w3.org/WAI/older-users/literature/ ; Chevalier i in., **2012-05-15**, link w źródłach. | Po wysłaniu formularza trzeba ponownie odpowiedzieć sobie „czy konto już istnieje?” i „czy te trzy kroki są obowiązkowe?”. To dokłada koszt pamięci roboczej tuż po pierwszym sukcesie. | W rejestracji zapowiedzieć opcjonalny onboarding; na pierwszym onboardingu napisać wprost „Konto jest już gotowe; te ustawienia są opcjonalne.” | Test 5 osób 60–75: po wejściu na „Krok 1 z 3” zapytać bez podpowiedzi, czy konto już istnieje i czy trzeba kończyć onboarding. Produktowy próg 4/5 poprawnych odpowiedzi — **próg jest propozycją, nie wynikiem badania**. |
| **Logowanie 2FA moderatora** — `resources/views/auth/two_factor_challenge.blade.php` | Pole wspiera paste/autofill, ale instrukcja „przepisz sześciocyfrowy kod” promuje ręczną transkrypcję. | **WCAG 2.2 3.3.8** + Understanding: https://www.w3.org/WAI/WCAG22/Understanding/accessible-authentication-minimum.html ; W3C Older Users **2025-11-20**. | Ręczne przeniesienie cyfr między telefonem a komputerem wymaga przełączania uwagi i zwiększa ryzyko pomyłki. | Copy: „Otwórz aplikację Authenticator i wpisz lub wklej sześciocyfrowy kod. Jeśli urządzenie podpowiada kod automatycznie, możesz wybrać podpowiedź.” | Test techniczny: wklejenie pełnego kodu musi wypełnić pole; UX: mierzyć błędne pierwsze próby i odsetek osób ręcznie przepisujących kod. |
| **Włączanie 2FA** — `resources/views/pages/settings/two_factor/enable.blade.php`, „Pokaż sekret do ręcznego wpisania”, „Sekret (klucz TOTP)” | Awaryjna ścieżka po QR używa żargonu, którego użytkownik nie potrzebuje do wykonania zadania. | W3C „Developing Websites for Older People”: https://www.w3.org/WAI/older-users/developing/ — **data [do weryfikacji]**, dostęp 2026-09-10; W3C literature review **2018-02-22**. | To moment po niepowodzeniu podstawowej ścieżki; dodatkowe pojęcia „sekret/TOTP” zwiększają ryzyko przerwania konfiguracji. | „Pokaż kod do ręcznego wpisania” oraz „Kod do wpisania w aplikacji”. Termin TOTP, jeśli potrzebny wsparciu, przenieść do informacji drugorzędnej. | Scenariusz z 5 osobami 60–75 „nie możesz zeskanować QR”; próg produktowy 4/5 kończy konfigurację bez pytania, co oznacza sekret/TOTP. |
| **Rejestracja** — `resources/views/auth/register.blade.php`, `display_name` i `username` | Przed pierwszym wejściem trzeba wymyślić dwa podobne identyfikatory i zrozumieć różnicę. To nie jest naprawiony już problem języka walidacji username. | Chevalier i in., **2012-05-15**; NN/g **2013-05-28**: https://www.nngroup.com/articles/usability-seniors-improvements/ | Dwa nowe pojęcia zwiększają obciążenie na najbardziej krytycznym formularzu i mogą powodować dodatkowe walidacje przed doświadczeniem wartości serwisu. | Minimum: podpowiadać `username` z `display_name` i jasno opisać, że to adres/nick profilu. Wariant wymagający decyzji: generować username automatycznie i pozwolić zmienić później. | Mierzyć liczbę poprawek obu pól, walidacji i porzuceń. W teście zapytać po formularzu, która nazwa będzie widoczna przy zdjęciach i do czego służy druga. |
| **Landing gościa** — `resources/views/pages/landing.blade.php` (`<x-kuking-board>` przed `#jak-dziala`), `resources/views/components/kuking-board.blade.php`, inwentarz `docs/product/PROSTOTA_JAK_GARNEK.md` | Najgęstsza interaktywna część pojawia się przed prostym modelem produktu „Jak działa”. | W3C literature review **2018-02-22**; NN/g **2013-05-28**; NN/g **2019-09-08**: https://www.nngroup.com/articles/usability-for-senior-citizens/ | Nowy, metodyczny użytkownik musi odfiltrować wiele bodźców zanim zbuduje model „co tu robię i jaki jest następny krok”. | Bez rankingów i bez zmiany chronologii: przenieść krótkie `#jak-dziala` przed tablicę albo, decyzją właściciela, skrócić anonimową próbkę tablicy. | Zadanie „wchodzisz pierwszy raz; dowiedz się, co tu można robić i załóż konto”: czas do poprawnej akcji, liczba wejść w poboczne linki i cofnięć; porównać warianty. |

## Dobre wzorce, których nie warto cofać

- `resources/views/errors/419.blade.php` dobrze wspiera odzyskanie po błędzie: wyjaśnia stan, zachowuje tekst tam, gdzie może, daje drogę ponownego logowania i mówi wprost, czego odzyskać się nie da.
- `resources/views/components/karuzela-zdjec.blade.php` nie uzależnia funkcji od swipe/drag.
- `resources/views/components/layout.blade.php` centralizuje pomoc i statusy.
- `resources/views/auth/login-link.blade.php` daje drogę bez pamiętania hasła.
- `resources/views/auth/two_factor_challenge.blade.php` ma jedno pole OTP, `autocomplete="one-time-code"`, możliwość paste i kod zapasowy; problemem jest wyłącznie copy zachęcające do transkrypcji.

## Ranking napraw — od „jedna linijka” do „wymaga decyzji właściciela”

1. **Jedna linijka:** `two_factor_challenge.blade.php` — „przepisz” → „wpisz lub wklej”, z informacją o podpowiedzi systemu.
2. **Kilka linijek copy:** `settings/two_factor/enable.blade.php` — „sekret (klucz TOTP)” → język zadania „kod do ręcznego wpisania”.
3. **Kilka linijek copy:** `register.blade.php` + `onboarding/interests.blade.php` — jasno powiedzieć, że konto już istnieje, a onboarding jest opcjonalny.
4. **Mała/średnia poprawka CSS po pomiarze:** `app.css` — zagwarantować Focus Not Obscured przy zwiększonej/zawiniętej `.bottom-nav`.
5. **Wymaga decyzji właściciela:** czy `username` musi być ręcznie wybierany już w rejestracji, czy można go podpowiedzieć/generować i edytować później.
6. **Wymaga decyzji właściciela:** czy „Jak działa” ma wejść przed tablicę, czy anonimowa tablica ma zostać skrócona.
7. **Wymaga decyzji i większej implementacji:** rozważyć WebAuthn/passkey jako alternatywny drugi czynnik moderatorów. Nie jest to warunek obecnego PASS 3.3.8, ale usuwa transkrypcję kodu.

## Co dopisać do `scripts/dostepnosc.mjs`

1. **`focus-not-obscured` — najwyższy priorytet.** `/home`, `/szukaj`, przykładowy wpis, `/ustawienia/profil`; 320/360/414 × trzy istniejące skale. Tab przez wszystkie widoczne kontrolki, pomiar `getBoundingClientRect()` fokusu, `.topbar`, `.bottom-nav`. **FAIL 2.4.11**, gdy fokus jest w 100% przykryty autorską nakładką; opcjonalny ostrzejszy projektowy warning przy częściowym przecięciu.
2. **`target-size`.** Na `/`, `/home`, karcie wpisu, ustawieniach i trybie gotowania zebrać widoczne `a`, `button`, formularze i elementy z rolami interaktywnymi. **FAIL 2.5.8**, gdy realny cel ma <24×24 CSS px i nie spełnia jawnego wyjątku inline/spacing/equivalent/UA. Produktowe 48 px raportować osobno, by nie mieszać standardu Kuking z formalnym WCAG.
3. **`auth-paste`.** Na logowaniu i fixture 2FA wkleić wartość do pola. **FAIL**, jeśli paste jest blokowany albo pełna wartość nie trafia do pola. Asercje: hasło ma `autocomplete="current-password"`, OTP `autocomplete="one-time-code"`.
4. **`status-live`.** Autosave przepisu, upload i tryb gotowania: `MutationObserver`, akcja bez nawigacji, wykrycie nowego statusu. **FAIL**, jeśli komunikat pojawia się poza `[aria-live]`, `[role=status]` lub `[role=alert]` i fokus nie został celowo przeniesiony z uzasadnionego powodu.
5. **`consistent-help`.** Dla istniejącej macierzy tras odczytać mechanizmy pomocy z footera i kolejność. **FAIL 3.2.6**, jeśli ekran głównego layoutu nie ma pomocy albo kolejność różni się od wzorca; wyjątki tylko na jawnej liście z komentarzem.
6. **`redundant-entry`.** Fixture `rejestracja → onboarding → verify/profile`; zapamiętać semantycznie wcześniejsze wartości. **FAIL 3.3.7**, jeśli `email`, `display_name`, `username` lub inna poprzednio podana informacja wraca jako puste required bez prefill/wyboru i bez wyjątku bezpieczeństwa. `password_confirmation` przy tworzeniu hasła jawnie whitelistować z linkiem do Understanding.
7. **`dragging-contract`.** Statycznie flagować nowe `draggable`, autorski `pointermove`/`mousemove` zmieniający położenie/wartość i suwaki. Sam hit nie jest FAIL. **FAIL 2.5.7**, jeśli funkcja drag nie ma równoważnej dostępnej drogi klik/tap/klawiatura. Karuzelę zachować jako pozytywny fixture.
8. **`hover-content-contract`.** Statycznie flagować `group-hover`, `peer-hover`, CSS `:hover` zmieniający `display/visibility/opacity` oraz tooltip/popover. Runtime sprawdzić, czy ujawniona treść jest osiągalna także focusem, pozostaje widoczna po najechaniu na nią i daje się zamknąć zgodnie z 1.4.13. `[title]` kierować do review, nie automatycznego FAIL.
9. **`register-onboarding-copy` — projektowy kontrakt 60+.** Jeżeli rejestracja mówi „gotowe”, pierwszy onboarding musi jawnie mówić, że konto jest utworzone i dalsze ustawienia są opcjonalne; „Pomiń” musi pozostać widoczne. **FAIL projektowy** przy regresji „gotowe” → pozornie obowiązkowy proces.
10. **`two-factor-copy` — projektowy kontrakt 60+.** Instrukcja przy `one-time-code` nie może znów nakazywać wyłącznie „przepisać”; powinna dopuszczać paste/autofill. Widoczne `TOTP`/`sekret` w głównej etykiecie czynności bez prostego objaśnienia flagować do review.

## Najważniejsza rekomendacja

Najpierw dopisać do CI **pomiar Focus Not Obscured**. To jedyne miejsce w tym audycie, gdzie kod pokazuje wiarygodny warunek potencjalnego naruszenia AA, a obecny zielony zestaw testów nie potrafi go rozstrzygnąć. Następnie warto dodać pomiar target-size i kontrakty auth/status/help. Trzy ustalenia copy można poprawić szybko, ale bez regresyjnych testów wrócą równie łatwo jak inne problemy języka interfejsu.