# Tabela stacku opisuje stan, nie zamiar (D-104)

Przeniesione z `AGENTS.md` §3 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

Feed, wyszukiwanie, komentarze i „Ugotowałem” korzystają z kontrolerów
i widoków Blade, z JavaScriptem jako ulepszeniem. Livewire obsługuje złożony
formularz kreatora przepisu (`resources/views/components/recipe-wizard.blade.php`).
Dodanie `wire:poll` do często odwiedzanych ekranów wymaga osobnej decyzji
i pomiaru kosztu żądań; nie wynika z wyboru Livewire dla kreatora.

### Ta tabela opisuje STAN, nie zamiar — i trzecia kolumna jest sprawdzana testem (D-104)

Tabela nosi nagłówek „Stack” i jest czytana jako odpowiedź na pytanie „co
w tym projekcie JEST”. Do 10 września 2026 stało w niej `| Monitoring | Sentry |`,
a Sentry'ego tu nie ma i nigdy nie było: zero trafień w `composer.json`, brak
`config/sentry.php`, a `SENTRY_LARAVEL_DSN` jest przewleczone przez
`.env.example`, `.railway/railway.ts` i `ci.yml`, ale **nie czyta go ani jedna
linijka PHP**. Autor PR-a #253 zbudował na tym wierszu całe zdanie o tym, że
„właściciel ma szansę dowiedzieć się o awarii bez zaglądania” — założył, że
`Log::error()` dojdzie do Sentry. Nie dochodzi. Prostowanie tego zajęło komuś
innemu trzy pliki i wpis w dzienniku decyzji.

Dlatego wiersz `Monitoring` mówi teraz, co **działa dziś**, a Sentry jako wybór
docelowy stoi tam, gdzie zamiary mają stać: **D-041** w `docs/DECISIONS.md`
i `docs/ROADMAP.md` §0. Tak samo postępuj z każdym następnym wierszem: do tabeli
wchodzi rzecz wdrożona, do roadmapy — zamiar.

Trzecia kolumna nie jest ozdobą. Czyta ją
`tests/Feature/TabelaStackuMowiPrawdeTest.php` i sprawdza, czy rzecz naprawdę
jest tam, gdzie wiersz obiecuje. Dozwolone są **cztery kształty wpisu i nic
poza nimi**:

| Kształt | Znaczenie | Co sprawdza test |
|---|---|---|
| `composer.json`: nazwa pakietu | zależność PHP | klucz jest w `require` albo `require-dev` |
| `package.json`: nazwa pakietu | zależność npm | klucz jest w `dependencies`, `devDependencies` albo `optionalDependencies` |
| w repozytorium: ścieżka | rzecz jest naszym kodem | plik albo katalog istnieje |
| usługa zewnętrzna | konto u kogoś — w repozytorium nie ma czego sprawdzać | tylko to, że wiersz to MÓWI |

Kilka lokalizatorów w jednym wierszu rozdziela `·`. Kształt piąty nie przejdzie:
test oblewa i podaje wiersz z nazwy. Nowy wiersz nie wejdzie więc do tabeli bez
odpowiedzi na pytanie „a gdzie to jest”, i odpowiada na nie ten, kto go dopisuje.

**„usługa zewnętrzna” nie jest wytrychem.** Wpisanie tego przy pakiecie PHP
(Sentry jest SDK, nie usługą) test przepuści — ale wtedy tabela kłamie JAWNIE,
w jednym widocznym wierszu, zamiast po cichu przez zwykłą nazwę w kolumnie
„Wybór”. Żeby nie dało się uciszyć testu przepisaniem wszystkich wierszy na tę
wartość, test wymaga minimalnej liczby wierszy sprawdzalnych w repozytorium.
