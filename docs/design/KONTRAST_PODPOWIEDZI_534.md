# Odbiór lokalny poprawki #534

Zakres: wyłącznie kolor tekstu linków-przycisków w `.notice`. Selektor wyłącza `.btn-primary` i `.btn-secondary`, które odzyskują własne kolory. Zwykłe linki i ewentualne quiet nadal korzystają z koloru podpowiedzi. Nie zmieniono widoków, danych produkcji, uprawnień ani zachowania formularzy.

## Rzeczywiste strony i pomiary

Izolowana kopia `/tmp/kuking-notice534-exec`, nowa baza `kuking_port_notice534` na 55439, osobne storage/vendor, DemoSeeder. Chromium 151.0.7922.34 (katalog chromium-1234). Własny wpis Ani: primary „Dodaj kolejne zdjęcie”; `/dodaj`: secondary „Dokończ”; komentarze tego samego wpisu dla gościa: zwykłe linki logowania/rejestracji. Jedyny nowy szkic jest tworzony na potrzeby pomiaru i usuwany w finally przez dokładnie zwrócony UUID, autora, tytuł i status; brak usuwania cudzych szkiców.

24 konfiguracje: 320/1440 × dwa motywy × tekst100/140 × trzy typy. Każda obejmuje normalny stan, hover i programowy `.focus()` (72 pomiary, nie test Tab). Odczytywane są computed styles, złożone tło przodków, kontrast >=4,5 i właściwy token koloru. Brak wymaganego rzeczywistego linku kończy pomiar błędem. Wyniki JSON i zrzuty znajdują się w storage/port-projektu/notice oraz kopii output/notice534.

Rzeczywisty zoom2 potwierdzony chrome.tabs.getZoom, fizyczne640×1000 → CSS320×500: 12 konfiguracji (trzy typy, motywy2, tekst100/140), PASS. Nie jest to pełny odbiór geometrii ani Tab. Zrzuty pokazują ograniczoną wysokość między stałymi nawigacjami przy tekst140; ta poprawka nie zmienia nawigacji. Obejrzano zrzut primary dark140 zoom oraz secondary dark desktop.

Kontrast normalny po zmianie (jasny / ciemny): primary5,771 /5,096; secondary18,033 /14,038; zwykły link6,533 /6,982. Primary ma biały tekst; secondary odpowiednio #151714 i #F4F5F1; zwykły link #6B4E0C i #3A2C0C. Hover i fokus również przechodzą próg4,5.

## Kontrole ujemne rzeczywistego CSS

Każda po dodatniej macierzy: kopia cp-p w zewnętrznym /tmp/kuking-notice-*, mutacja resources/css/app.css, rzeczywisty build, oczekiwany NOTICE_KONTRAST, przywrócenie MD5+mtime, ponowny build i dodatni pomiar. Końcowy hash źródła LF: `6d8ec7cb97547c84fd6e15b4fc9a17d0`; mtime `1789380206073.5535 ms`. Negatywy:

1. Powrót `.notice a`: primary dark2,666.
2. Usunięcie ochrony zwykłego linku: dark1,090 (ochrona historycznej poprawki #89).
3. Nadpisanie secondary kolorem podpowiedzi: dark1,131.

Pełne komunikaty zapisane w notice534-lf-final.log. Początkowa próba secondary trafiała na uproszczony formularz recipes.create i poprawnie oblała NOTICE_BRAK_PRZYPADKU; fixture poprawiono na rzeczywistą stronę `/dodaj`.

Guard sprawdza local/testing oraz driver i nazwę faktycznego DB::connection() przed pierwszym zapytaniem. Próby DB_CONNECTION=sqlite i DB_URL z nazwą bez kuking_port odmówiły własnym komunikatem i kodem1 przed zapytaniem. Nie sprawdzano ich przez połączenie do cudzej bazy.

## Integracja i granice

Nowy moduł wywołuje istniejący pełny port-projektu; zachowano wcześniejsze kontrole. Oba rzeczywiste filtry workflow rozszerzono o kontrast-notice.mjs; fixtures już obejmował filtr katalogu. Artefakty trafiają do istniejącego storage/port-projektu. Build, Pint fixture i node --check PASS; nie uruchamiano pełnego PHP ani całego portu w tym izolowanym odbiorze. Integracja w głównym katalogu zachowuje identyczne bajty pięciu plików implementacji z kopią poddaną odbiorowi. Pełne kontrole przed wysyłką pozostają do zakończenia.

Dodatkowe miejsca secondary: posts/show przy wielu zdjęciach oraz historyczny warunek w wizard; pozytywny pomiar secondary dotyczy /dodaj. Nie znaleziono rzeczywistego quiet/danger wewnątrz notice; nie stworzono fikcyjnych widoków udających ich użycie. Nie opublikowano zdjęcia, przepisu ani komentarza. Produkcja i canonical/native pozostają poza zakresem zmian tego wykonania.
Końcowe powtórzenie po poprawie guarda: 24 konfiguracje i trzy negatywy PASS, czas31502ms. Pint fixture PASS. Całość źródła CSS przywrócona po mutacjach.

## Integracja końców linii

Pierwszy hook odmówił wysyłki: CSS skopiowany z Windows miał CRLF, a istniejący PanelSzerokiTelefonTest analizuje źródło z LF. Odtworzono błąd z pełnym logiem (1920 testów przeszło przed zatrzymaniem). Przywrócono LF zgodne z `.gitattributes`, bez zmiany testu ani drzewa Git implementacji. Ta rodzina ponownie: **6 testów / 57 asercji PASS**. Na końcowych bajtach ponowiono 24 konfiguracje / 72 pomiary i wszystkie trzy negatywy: PASS, 37,346 s, z kontrolą MD5 i mtime. CSS w kopii wykonawczej i głównym katalogu jest identyczny.
