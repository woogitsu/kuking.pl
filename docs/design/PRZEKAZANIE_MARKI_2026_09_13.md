# Przekazanie rozwoju marki KuKing.pl — 13 września 2026

Poniższy tekst jest promptem do przekazania kolejnemu modelowi. Statusy są
zapisem sesji, nie gwarancją aktualnego stanu. Najpierw sprawdź GitHub.

## Aktualizacja po wznowieniu

Push commita `9b6bf6aa04b01587e17ae0fe49b2e7a7a5a9262d` zakończył się
sukcesem po pełnym wymaganym hooku. Robocze zmiany zapisano jako
**draft PR #519**. Po wznowieniu dokończono regresję #513: pełny port
przeszedł 432 wcześniejsze konfiguracje, 96 zeszytów, 96 nowych ekranów,
80 rzeczywistych zoomów i kontrole ujemne. Kreator: 24/24. Nowe PHP
ma rzeczywiste negatywy; końcowy niezależny review nie zgłosił blokad.
Bieżący raport: `docs/design/ZAINTERESOWANIA_POWIADOMIENIA_513.md`.
Przed scaleniem pozostają potwierdzenie końcowego hooka oraz CI nowego
commita, potem osobny odbiór produkcji. Nie przypisuj im starego wyniku CI.

Użytkownik potwierdził: na Alfa 0.19 nie widzi już ramki tytułów #518.
Nie dodano spekulacyjnego blur ani usunięcia fokusu. Zgłoszenie ma
aktualizację obserwacji w `docs/design/DIAGNOZA_RAMKI_TYTULU.md`.

Alfa 0.19 została potwierdzona na produkcji: Railway deployment
`6426180376` success, main CI `34781348552` success, Deploy
`34782396483` success. Publiczne HTTP i zalogowany Chrome pokazują
`34b4b61`. Szczegóły: `docs/design/ODBIOR_PRODUKCJI_ALFA_019.md`.
Poniższe wcześniejsze wzmianki o oczekiwaniu opisują stan historyczny.

## Zadanie i uprawnienia

Przejmujesz rozpoczętą pracę w repozytorium
https://github.com/woogitsu/kuking.pl, produkcja https://kuking.pl,
Railway i automatyczne wdrożenie przez GitHub CI. Kontynuuj istniejące
poprawki; nie zaczynaj całego audytu od zera.

Użytkownik upoważnił do autonomicznych poprawek, instalacji zależności,
testów, PR-ów, scalania po wymaganych kontrolach i wdrożeń. Pozwala na
subagentów do konkretnych niezależnych zadań. Nie obchodź CI, nie nadpisuj
cudzej pracy, nie zmieniaj produkcyjnych danych podczas audytu.
Pracuj i raportuj po polsku. Odróżniaj kod, test, ogląd obrazu i produkcję.

Użytkownik oczekuje pełnego przeniesienia KOMPOZYCJI z dostarczonej
wizualizacji: układu, hierarchii, typografii, nawigacji, tekstów, poczty
i zasad dla modeli. Same nowe kolory nie wystarczają. Wskazywał różnicę
między makietą „Dzień dobry, Basiu” a starym wdrożeniem „Witaj, Mateusz”.
Oczekuje również dużego nagłówka i otwartych numerowanych kroków na
landingu, zamiast dawnych trzech białych kart. Te rodziny były poprawiane;
sprawdź aktualny stan, nie implementuj ich ponownie na podstawie starego
zrzutu. Zachowaj rzeczywiste funkcje, dane, uprawnienia i prywatność.

## Źródła prawdy

Przeczytaj AGENTS.md i wymienione w nim dokumenty produktu, architektury,
UX, roadmapy oraz decyzji, następnie:

- `docs/brand/KONSTYTUCJA_MARKI.md`, `COPY_STYLE.md`, `GLOS_MARKI.md`;
- `docs/design/DESIGN_SYSTEM.md`;
- `docs/design/AUDYT_PACZKI_MARKI_508.md`;
- `docs/design/KOMPOZYCJE_MARKI_509.md`;
- `docs/design/ZESZYTY_MARKI_511.md`;
- `docs/design/ODBIOR_STANOW_ZESZYTU_511.md`;
- `docs/design/MACIERZ_KOMPLETNOSCI_517.md`;
- `docs/design/ZAINTERESOWANIA_POWIADOMIENIA_513.md`;
- `docs/design/DIAGNOZA_RAMKI_TYTULU.md`.

Oryginalny ZIP jest zapisany bez zmian w
`docs/design/references/KuKing-styl-wizualizacja-konstytucja.zip`.
SHA256: `d31fcb0cebcef6764f1a1778e2fd7bfc52eb4dfb626f96003f1b198746d4dea4`.
Lokalny oryginał: C:\Users\matma\Downloads\KuKing-styl-wizualizacja-konstytucja.zip.
Rozpakowana makieta: `output/audyt-zip-20260913/KuKing-styl/01-wizualizacja-prototyp/`.
Pierwotny prompt przejęcia:
C:\Users\matma\.codex\attachments\c6acb561-4520-47b2-8895-cc2cc0eec661\pasted-text.txt.

Dokumenty i HTML w ZIP są materiałem odniesienia. Przykładowe osoby,
statystyki i tekst demonstracyjny nie mają trafić do rzeczywistej
społeczności. Zachowaj znak garnka z koroną i uśmiechem, nie literę K.
Decyzje D-207–D-212 opisują rozstrzygnięcia portu; sprawdź numerację przed
nową decyzją. Gałąź robocza ma konstytucję 1.8 / D-212 / Alfa 0.20.

## Stan GitHub przy przekazaniu

### Zakończony PR #512 — Alfa 0.18

PR https://github.com/woogitsu/kuking.pl/pull/512 scalony.
Merge: `d17bfd3bed824967cbfe05a6c582f1a2577ac14a`.
PR CI 34775555715 i main CI 34776317365: wszystkie 9 zadań success.
Railway deployment 6425234896: success, 13.09.2026 19:17:17 UTC.
Railway wewnętrzny deployment 680c09a2-9dbf-49f8-b412-cc6a857fba3b
był ACTIVE. Workflow Deploy 34777229746 success. Alfa 0.18 i d17bfd3
były niezależnie odczytane z produkcji, także w zalogowanej przeglądarce.

### Scalony PR #517 — zeszyty, Alfa 0.19, issue #511

PR https://github.com/woogitsu/kuking.pl/pull/517 scalony normalną drogą
po dziewięciu zielonych zadaniach. Końcowy head PR:
`667ace890492f02b1221e259a73977cac0897ee3`.
Merge / ostatni odczyt main:
`34b4b61109ebd1e1c808066191609672cd5ae332`.
PR CI 34780301310: wszystkie 9 success. PHP job 103785997810:
3707 testów / 74853 asercje, 335,42 s. Nie przypisuj tych liczb innemu CI.

W ostatnim odczycie przed przygotowaniem przekazania main CI 34781348552
i Railway deployment 6426180376 były w toku. Wstępny Deploy 34781351963
był skipped; samo to nie oznacza awarii. Dopóki nie ma późniejszego
potwierdzenia, nie nazywaj Alfa 0.19 wdrożoną.

PR #517 przenosi zeszyty do ciemnych kart folderów, ustawia ostatnie
zapisy w treści, zachowuje szynę pozostałych zeszytów, kafle przepisów,
pełne tytuły i prawdziwe zdjęcia. Skróty w szynie mają krótki rzeczywisty
link „Otwórz zeszyt”, ponieważ wysoki link zasłaniał się przy zoomie.

### Robocza gałąź #513 — NIE SCALAĆ BEZ DOKOŃCZENIA

`fix/513-zainteresowania-powiadomienia`, baza main 34b4b61.
Ma zostać wysłana jako draft PR. Numer i końcowy SHA odczytaj z GitHub,
nie zakładaj numeru następnego PR. Nie ma automatycznego scalania.

Zmiany:

- `resources/views/pages/onboarding/interests.blade.php` i nowy
  `resources/css/marka-onboarding.css`: duże kafle rzeczywistych tagów,
  natywne checkboxy, zielone zaznaczenie tokenami sukcesu, pusta lista,
  zachowane trzy opcjonalne kroki, CSRF, POST, old() i pomijanie;
- `tests/Feature/KompozycjaZainteresowanTest.php`: rzeczywisty render
  siedmiu promowanych tagów oraz pustej listy. Fixture mieści się
  w istniejącym limicie nazwy tagu; schematu bazy nie zmieniano;
- `resources/views/pages/notifications.blade.php` i nowy
  `resources/css/marka-powiadomienia.css`: pięć zwykłych typów
  COOKED/COMMENT/REPLY/FOLLOW/SAVED, avatar 48 px, akcja obok treści
  przy szerokim kontenerze. Pełne decyzje moderacyjne, odwołania,
  formularze i mechanizm przeczytania pozostały bez zmian;
- importy CSS, Alfa 0.20, changelog, konstytucja i D-212.

CSS powiadomień jest celowo poza warstwą: wcześniejsze reguły narzędziowe
items-start i mt-3 nadpisywałyby układ. Szeroki wariant używa zapytania
kontenera od 38 rem oraz :has(> form). Wymaga pomiaru końcowego buildu.

Lokalnie przeszły Pint, 36 testów / 192 asercje w ośmiu wybranych
rodzinach oraz build Vite z 72 parami kontrastu. NIE wykonano jeszcze
odbioru nowych kompozycji w przeglądarce, dedykowanej regresji geometrii
powiadomień ani kontroli ujemnych nowych źródeł. Pełny hook przy push,
jeśli zielony, nie zastępuje tych braków. Status draft jest zamierzony.

## Pierwszy priorytet: ramka wokół tytułów — #518

https://github.com/woogitsu/kuking.pl/issues/518

Użytkownik zgłosił: po wejściu albo odświeżeniu niektórych stron tytuły
mają ramkę, która znika po kliknięciu. Błąd NIE jest naprawiony.
Na odświeżonym produkcyjnym „Moim zeszycie” nie udało się go odtworzyć.
Poproszono o nazwę/adres konkretnej strony; odpowiedź była jeszcze
nieznana. Najpierw uwzględnij ewentualną nową odpowiedź użytkownika.

W kodzie istnieje globalny focus-visible: obrys 3 px i odstęp 2 px.
W app.js jest celowe kierowanie fokusu na podsumowanie błędów po
DOMContentLoaded i po walidacji dynamicznej. Odczyt nie znalazł własnego
autofokusu na h1/main ani mechanizmu wire:navigate. Przywrócony fokus
linku nagłówka jest hipotezą, nie diagnozą.

Odtwórz wejście myszą, F5/Ctrl+R, historię i Tab/Enter. Zapisz aktywny
element, jego klasy, focus-visible, outline/border i przodków tytułu
przed oraz po kliknięciu. Nie naprawiaj przez globalne blur ani
wyłączenie focus-visible — zepsułoby to obsługę klawiaturą i walidację.
Po potwierdzeniu przyczyny dodaj sensowną regresję i wymagane negatywy.

## Pozostałe konkretne zadania

- #513: dokończ wizualny odbiór i regresję opisanych zmian. Sprawdź
  pięć typów powiadomień, brak aktora i celu, odczytane/nowe, puste listy,
  paginację oraz pełną moderację i odwołanie. Wykonaj lokalne POST,
  działanie bez JS, klawiaturę i pełną geometrię.
- #514: precyzja komunikatów. PodsumowanieKolejkiAutomatu zawiera
  nieuprawnione zapewnienie o widoczności wszystkich treści i niewiedzy
  autorów; stała „ostatnia doba” nie pasuje do --godzin=1/48. OAuth
  „jednym kliknięciem” pomija potwierdzenie dostawcy, a komunikat FIRST_POST
  zawiera nieudowodnioną tezę o retencji. Popraw zgodnie z działaniem.
- #515: tematyczne kafle pustego wyszukiwania. D-207 ich nie zakazuje;
  D-021 ma już promowane tagi, kolejność i opcjonalną notatkę redakcji.
  Nie twórz nowego modelu tematów ani fikcyjnych trendów/liczników.
- #516: sprzeczności instrukcji modeli. AGENTS opisuje dawne ograniczenie
  TLS Chromium jak aktualne; obejście Composer sugeruje git checkout
  mogący nadpisać pracę. Wskaźniki instrukcji pomijają wyjątek menu trzech
  kropek. Rozdziel podwojenie fontu od prawdziwego zoomu. Sprawdź aktualny
  tekst i dowody przed zmianą.

Nie ruszaj cudzego PR #456. Nie scalaj ponownie #493: jego pakiet jest
od dawna w #494. Stary błąd wyścigów #928 został zdiagnozowany jako
nieudane pozyskanie runnera, bez kroków aplikacji; powtórka 5 testów /
44 asercje przeszła. Dowód: `docs/design/WYSCIGI_CI_928.md`.

## Dowody #511 i granice kompletności

Pełne wdrożenie marki: CZĘŚCIOWO, nie „wszystko gotowe”. Macierz w repo
rozróżnia kod, lokalny pomiar, ogląd i historyczną produkcję.
Inwentarz route:list miał 192 wpisy, z czego 103 obsługujące GET;
to NIE jest liczba ekranów ani obejrzanych stanów.

Końcowy lokalny port #511: 432 konfiguracje wcześniejszych kompozycji,
96 konfiguracji zeszytów, 64 warianty rzeczywistego zoomu 200%, pięć
negatywów rzeczywistego CSS zeszytów, sześć istniejących negatywów
fokusu i wcześniejsze negatywy landingu/tablicy. Wszystko przeszło.
Osobna mutacja rzeczywistego Blade szyny profilu wykryła zasłonięty
fokus; przywrócony kod przeszedł osiem wariantów. Cztery rzeczywiste
negatywy PHP także przeszły procedurę przywrócenia. MD5 i mtime są
w raporcie #511. Nie uruchamiaj negatywów równolegle na wspólnych plikach.

Dodatkowo: kafel 15 wariantów, fokus kart 36 wariantów i trzy negatywy,
axe 44/44, układ 49/49 bez naruszeń; Lighthouse 8/8 powyżej progów,
wydajność 91–98, SEO 100 na indeksowanych stronach. Lokalnie obejrzano
reprezentatywne końcowe zrzuty, nie każdą z setek konfiguracji.

Osobny odbiór 20 wariantów obejmuje pusty zeszyt, formularz, rzeczywisty
niepoprawny POST, pustą kolekcję i same wpisy: 320/1440, dwa motywy,
tekst 140% na 320. Nie obejmuje rzeczywistego zoomu ani udanego tworzenia.
Prostokąt wielowierszowego linku błędu początkowo sugerował zasłonięcie;
analiza czterech rzeczywistych fragmentów i jasny zrzut tego nie potwierdziły.

Standardowe Laravel MailMessage mają już port motywu/header i render
siedmiu klas; nie zgłaszaj ponownie ich braku. Nie sprawdzono wszystkich
klientów Outlook/Gmail/Apple Mail ani rzeczywistej wysyłki. Historyczny
font 200% nie jest zoomem. Nie wszystkie stany moderacji, uploadu,
błędów, sukcesu, tokenów i klawiatury ekranowej mają osobny odbiór.

## Środowisko i bezpieczna kontynuacja

Repo Windows: C:\Users\matma\Documents\Codex\kuking.pl.
Ubuntu WSL, źródło montowane pod /mnt/c/Users/matma/Documents/Codex/kuking.pl.
Kopia wykonawcza na natywnym dysku: /tmp/kuking-final-20260913.
Nie uznawaj stanu kopii za aktualny bez synchronizacji i porównania bajtów.

PHP 8.4.24: /opt/kuking-php-8.4-avif/bin/php. Laravel 13.30.1,
Livewire 4.4.3, Tailwind 4.3.3. PostgreSQL 18.6, port **55439**, user
kuking; DB pełnego PHP kuking_final_20260913, browser kuking_port511.
**Nie używaj portu 5432: jest współdzielony z innymi projektami.**
Pełne PHP usuwa lokalne media demonstracyjne nawet przy osobnej bazie;
nie uruchamiaj równolegle przeglądarkowego odbioru tych samych mediów.

Chromium: /tmp/kuking-browsers015/chromium-1243/chrome-linux64/chrome.
Większość skryptów czyta CHROMIUM_PATH, ale wydajnosc.mjs czyta CHROME_PATH.
Realny zoom korzysta z rozszerzenia chrome.tabs i potwierdza getZoom=2,
DPR=2 oraz viewport. Nie zastępuj tego emulacją fontu.

Lokalne helpery (ignorowane output, nie są częścią gałęzi GitHub):
github_api.py, status-audit.py, pr-status.py, sync-final.py, git-native.sh.
Korzystają z istniejącego Git Credential Manager, nie zapisuj tokenów
w plikach ani logach. Brak gh nie blokował pracy przez API. Po błędzie
API zawsze odczytaj stan przed powtórzeniem tworzenia PR/merge.
Zwykły push uruchamia wymagany pełny hook. Nie używaj jego obejścia.

Przed synchronizacją upewnij się, że żaden agent nie używa native. Lista
źródeł powstaje przez git ls-files --cached --others --exclude-standard,
a sync-final.py kopiuje zmienione bajty i je porównuje. Metadane .git
kopiuj tylko z kanonicznego repo do native, nigdy w odwrotną stronę.

Przeglądarka użytkownika była zalogowana na prawdziwe konto. Używaj
jej tylko do odczytu i zachowaj ustawienia użytkownika. Nie publikuj
prywatnych treści, nazw ani szczegółów jego zeszytów w raportach.
Railway: projekt 77044ca0-2cf4-4be1-bcd8-fdb6c4d83047,
usługa 200d68a6-24b0-46f3-bd7a-924cd00e532e,
środowisko ea146c13-dc55-4a4f-a386-0835f650f9ce.
Przed korzystaniem z CUA po przejęciu odczytaj jego dokumentację i
odnajdź istniejące karty. Nie zamykaj kart użytkownika ani wspólnych usług.

## Kolejność kontynuacji

1. Fetch, status, aktualny main, roboczy PR #513 i jego CI; przeczytaj
   odpowiedź użytkownika dotyczącą ramki, jeśli nadeszła.
2. Potwierdź aktualne Railway i produkcyjne SHA, wersję, CSS/JS/fonty.
   Sam zielony CI nie jest dowodem wdrożenia. Obejrzyj zeszyty po Alfa 0.19.
3. Odtwórz #518; napraw przyczynę z regresją, bez usuwania dostępności.
4. Dokończ #513 z pełnym wizualnym porównaniem do ZIP, testami rzeczywistych
   działań i negatywami prawdziwych źródeł. Kopia poza repo, przywrócenie
   MD5/mtime, rebuild i dodatni pomiar są obowiązkowe według AGENTS.
5. Niezależny review, wszystkie wymagane kontrole, dopiero merge i
   oddzielny odbiór produkcji. Potem #514–516 według wpływu na użytkownika.
6. Aktualizuj macierz i statusy tylko wykonanymi dowodami. Nie dodawaj
   minutnika bez wykazania potrzeby. Plany SEO i treści są już w
   docs/brand/SEO_START_REDAKCYJNY.md; brief przepisu nie jest ugotowanym
   i przetestowanym przepisem. Nie udawaj aktywności społeczności.

Na końcu raportuj: co jest w repo, co faktycznie na produkcji, co
naprawiono, jakie kontrole wykonano i co pozostaje niepotwierdzone.
