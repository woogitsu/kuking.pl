# Zdjęcia w pustej szynie profilu — Alfa 0.30

## Problem i zmiana

Zgłoszenie właściciela #551 odtworzono na zalogowanej produkcji Alfa 0.29:
cudzy profil z wpisami i zdjęciami nie miał prawej szyny. Kod włączał ją
tylko dla właściciela albo przy tagach i zeszytach, natomiast archiwum
pozostawało ograniczone do 750 px w szerszej ramie.

ProfileController wybiera do trzech najnowszych opublikowanych wpisów
z gotowym zdjęciem, wyłącznie jako uzupełnienie cudzego profilu bez tagów
i zeszytów. Stosuje ten sam filtr widoczności co archiwum przed limitem.
Blok „Zdjęcia z tej kuchni” prowadzi do wpisów przez datę i miniaturę.
Fokus pozostaje na dacie, a pseudo-element rozszerza kliknięcie na zdjęcie.
Własny profil i istniejące bloki zachowują pierwszeństwo. Bez danych nie
powstaje pusty blok. D-214 i konstytucja 1.11 opisują tę regułę.

## Testy i ogląd lokalny

- ProfilZdjeciaSzynyTest, KompozycjaProfiluMarkiTest i SzynaKolejneEkranyTest:
  **20 testów / 295 asercji PASS**. Gość, obcy, obserwujący, prywatne wpisy,
  szkice, ukryte wpisy, niedokończone media, limit, kolejność, puste konto,
  pierwszeństwo tagów/zeszytów, zakładki/rok/strona i obustronna blokada.
- Sześć fizycznych negatywów PHP/Blade: widoczność, limit, published,
  pierwszeństwo tagów, obraz i odnośnik. Każdy FAIL → przywrócenie bajtów,
  MD5 i mtime → ten sam test PASS. Negatyw published dopuścił szkic bez daty,
  co test wykrył jako HTTP 500; nie jest to błąd końcowego kodu.
- Build Vite i 72 pary kontrastu PASS.
- Dwa negatywy prawdziwego CSS: usunięcie układu prawej kolumny oraz
  wyłączenie pseudo-elementu nad zdjęciem. Pomiar wykrył odpowiednio
  NO_RIGHT_COLUMN i brak nawigacji po kliknięciu. Po każdej zmianie
  odtworzono bajty/MD5/mtime, wykonano build i ten sam pomiar przeszedł.
- Końcowy odbiór zalogowanego widza: **48 konfiguracji** — CSS 320, 360,
  390, 414, 768, 1440 × dwa motywy × tekst 100/140 × zoom 100/200%.
  Zoom odczytany przez chrome.tabs.getZoom. Przy zoomie 200% fizyczne okno
  ma dwukrotną szerokość, aby zachować podany obszar CSS. Zoom nie jest
  zastąpiony rozmiarem fontu. Przy zoomie 100% małe viewporty są emulowane.
- We wszystkich 24 wariantach tekstu 140% sprawdzono trzy przystanki Tab,
  widoczność i trafienie fokusu oraz rzeczywiste kliknięcie środka każdej
  miniatury z przejściem pod adres wpisu. Razem 72 odwiedzone odnośniki
  w Tab i 72 kliknięcia zdjęć. To kontrola nowego bloku, nie wszystkich
  kontrolek całego profilu.
- Obejrzano osiem końcowych zrzutów 320/1440, oba motywy i zoomy, tekst140.
  Miniatury i pełne daty widoczne, bez poziomego overflow. Osiem kontroli
  axe obejmuje nową sekcję, bez naruszeń w przypisanych regułach.
- Osobne cztery warianty CSS320/tekst140, oba motywy i oba zoomy:
  rzeczywisty Tab do odnośnika, Enter i sprawdzenie docelowego adresu
  wpisu zakończone poprawnie. Nie przypisujemy Enter wszystkim48 wariantom.

## Ograniczenia i przyrządy

Odbiór działał wyłącznie lokalnie na serwerze8033, PG55439,
kuking_publikacja492. Utworzono jedno jawne konto testowe profil551 i trzy
publiczne lokalne wpisy; wszystkie używają tej samej fotografii demonstracyjnej
z wcześniejszego fixture. Skopiowano oryginały pod osobne klucze, a warianty
odwołują się do istniejących plików testowych. Nie są to fikcyjne konta ani
aktywności produkcyjne. Pierwsza próba fixture odbiła się o unikalność klucza
oryginału i została wycofana transakcją; odczyt końcowy potwierdził trzy wpisy.

Pierwszy przyrząd napotkał minimalną szerokość okna Chromium500, zbyt wczesne
zrzuty lazy images i próbę locator.click na obrazie przykrytym prawidłowym
odnośnikiem. Poprawiono viewport, oczekiwanie na faktyczne obrazy oraz klik
myszą w ich geometrię. Przerwany przebieg gościa nie jest pełnym odbiorem:
pozostało12 pomiarów, a jedno decode odrzucono; jego zrzutów nie zaliczamy.
Końcowe48 dotyczy zalogowanego widza, a gościa i ograniczenia widoczności
obejmują odrębne testy PHP. Nie mierzono fizycznego telefonu ani czytnika.

Lokalne przyrządy i PNG: output/profile551. Zestaw dowodów bez sesji
i danych logowania: [evidence/profil551](evidence/profil551/).
Na etapie przygotowania tego raportu nie przypisujemy lokalnych wyników
CI ani produkcji. Końcowy SHA, CI i odbiór zostaną zapisane w powiązanym PR
oraz zgłoszeniu #551; numer PR należy odczytać po jego utworzeniu.
