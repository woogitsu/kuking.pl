## D-061 · Zdjęcie profilowe przechodzi przez model, a celem oznaczenia jest PLIK, nie konto

**Data:** 10 września 2026 · Issue #237 · Status: **obowiązuje**

> **Adnotacja (25 września 2026, B6-06):** **D-240** (22 września 2026)
> uchyla tę decyzję w części „zdjęcie profilowe idzie do modelu" — awatar
> **nie** wychodzi do OpenAI (`app/Jobs/PrzeanalizujAwatar.php`, celowo
> pusty job). Część o celu oznaczenia (`target_type = 'media'`) i o tym,
> że awatar jest ważniejszy do ochrony niż wpis, zostaje w mocy.

Pytanie właściciela było jednozdaniowe: *„czy zdjęcie profilowe jest
przetwarzane przez moderation omni model?"*. Odpowiedź brzmiała **nie** —
i to była luka większa, niż wyglądała.

Model oceniał zdjęcia **wpisów**. Awatar szedł zupełnie inną drogą
(`AvatarSettingsController` → `StoreUploadedImage` → `ProcessUploadedImage`)
i nikt na niej nie zlecał analizy, więc w kolejce automatu nie pojawiał się
nigdy, dopóki ktoś nie zgłosił go ręcznie.

### 1. Dlaczego awatar jest ważniejszy niż wpis

**Jest widoczny częściej.** Wpis widzą obserwujący i ci, którzy trafią na
niego w feedzie. Awatar chodzi za człowiekiem po całym serwisie: przy każdym
komentarzu pod cudzym przepisem, na tablicy dnia, na listach obserwujących,
w wynikach szukania osób. Jedno zdjęcie trafia przed oczy większej liczby
osób niż wpis, w którym stało.

**I jest najtańszym miejscem dla kogoś, kto chce zaszkodzić:** nie wymaga
napisania ani jednego słowa, więc nie rusza `WykrywaczSygnalow` (pracuje na
tekście). Przy fali migracyjnej nikt nie przejrzy kilkuset nowych awatarów
po kolei — a od D-054 ustawienie zdjęcia jest o trzy kliknięcia krótsze,
czyli częstsze.

### 2. Celem oznaczenia jest KONKRETNE ZDJĘCIE

`reports.target_type = 'media'`, `target_id` = `media.id`. To jest cała
decyzja tego wpisu i jedyna rzecz, którą łatwo zrobić źle.

Indeks `reports_jeden_automat_na_tresc` przepuszcza **jedno** oznaczenie
automatu na (typ, identyfikator) — na zawsze, także po odrzuceniu. Przy celu
`user` znaczyłoby to: oceniony pierwszy awatar konta i **żaden następny**.
A podmiana zdjęcia to sekunda pracy, więc cała funkcja dałaby się obejść
jednym klikiem. Ma to własny test (`test_drugie_zdjecie_tego_samego_konta…`).

Nazwa typu to `media`, a nie `avatar`, bo `ModeratedContent::TYPY` mapuje
**klasę** modelu, a klasa jest ta sama dla awatara i dla zdjęcia we wpisie.
`avatar` byłoby prawdą dziś i nieprawdą pierwszego dnia, w którym oznaczymy
zdjęcie z wpisu osobno. Że chodzi o zdjęcie profilowe, mówi treść powodu
(„Zdjęcie profilowe: …") i podgląd w kolejce.

Osoba, której to dotyczy, siedzi w `autor_tresci_id` — kolejka grupuje po
człowieku, bo kara zawsze dotyczy człowieka, nie pliku.

### 3. Zadanie CZEKA na warianty, zamiast cicho nie zrobić nic

Model dostaje wariant `thumb` (przekodowany, bez EXIF-u), a wariant powstaje
w `ProcessUploadedImage` — w innym zadaniu, na kolejce `media`. Gdyby
`PrzeanalizujAwatar` kończyło się powodzeniem przy zdjęciu w stanie
`processing`, funkcja działałaby wyłącznie wtedy, gdy worker mediów wyprzedzi
worker kolejki `low` — **czyli losowo, i nikt by tego nie zauważył**. Dlatego
zadanie wraca do kolejki (`release(30)`, do trzech prób), a nie kończy się
powodzeniem. To jest ta sama klasa usterki, którą w tym repozytorium tępimy
od pierwszego dnia: narzędzie melduje sukces, nie robiąc nic.

### 4. Co moderator może zrobić — i czego NIE MOŻE

`ModerationAction::DOZWOLONE['media']` = `none`, `warn`, `suspend`, `ban`.
Świadomie **bez `hide`** i **bez `remove`**:

| Decyzja | Dlaczego jej nie ma |
|---|---|
| `hide` | `Media` nie ma statusu w rozumieniu moderacji. Przycisk robiłby to, co robił przy „Ugotowałem": nic, przy powiadomieniu „ukryliśmy Twoją treść" |
| `remove` | `$target->delete()` na zdjęciu jest nieodwracalne (brak miękkiego kasowania), a odwołanie od `remove` ma treść **przywrócić** (DSA art. 17, `ResolveAppeal`). Decyzja, od której nie da się skutecznie odwołać, nie może stać na tym ekranie |

Zostaje ostrzeżenie (od D-058 razem z odpowiedzią pocztą wprost z panelu),
zawieszenie i ban. **Usunięcie cudzego zdjęcia profilowego przez moderatora
wymaga najpierw miękkiego kasowania zdjęć** — osobna praca, świadomie nie
w tym wpisie. Do tego czasu na ekranie nie ma przycisku, który by tego nie
robił.

### 5. Granica bez zmian: automat podnosi rękę, nigdy nie zamyka drzwi

Awatar **zostaje widoczny**, autor niczego się nie dowiaduje, decyzję
podejmuje człowiek (D-052 poz. 3.6 i 3.10, D-055). Przy zdjęciu profilowym
pokusa jest większa niż zwykle — „przecież wystarczy podmienić na literę" —
ale ciche podmienienie komuś awatara przez maszynę to jest dokładnie shadow
filtering z poz. 3.16, odrzucone jako sprzeczne z art. 17 DSA.

### 6. Co poszło do OpenAI i co o tym mówimy

Ta sama droga co przy zdjęciach wpisów: wariant `thumb` przekodowany do
JPEG, wysłany jako `data:` (nie adres — publiczny adres dla OpenAI byłby
publiczny dla wszystkich). **Zakres wysyłanych danych się rozszerzył**, więc
polityka prywatności mówi o tym wprost, w akapicie „Co wysyłamy do OpenAI",
i wiersz w tabeli dostawców też. Dokument nie może milczeć o danych, które
wychodzą z serwisu.

**Pliki:** `app/Jobs/PrzeanalizujAwatar.php` ·
`app/Domain/Moderation/Actions/AlarmujModeratora.php` (wyjęte z
`PrzeanalizujTresc`, bo alarmują teraz dwa zadania) ·
`app/Moderacja/OcenaModelem::dlaZdjecia()` ·
`app/Domain/Moderation/ModeratedContent.php` · `app/Models/ModerationAction.php`
· `app/Models/Report.php` · `app/Http/Controllers/Admin/SygnalyController.php`
· migracja `2026_09_10_300000_zdjecie_jako_cel_oznaczenia` ·
`docs/legal/SYGNALY_AUTOMATU.md` §9 · `docs/DATABASE.md` ·
`resources/legal/polityka-prywatnosci.md`
