# Zdjęcia po awarii i droga wgrywania — #617 / #602

20 września 2026. Baza badania: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
gałąź `gpt/dr-zdjecia`. **Propozycja do decyzji właściciela, nie wdrożona ochrona.**
Nie odczytywano produkcyjnego R2, nie pozyskiwano dostępu, niczego nie wysłano
na GitHub. Narzędzie w tym pakiecie pracuje wyłącznie na lokalnym MinIO.

## Co wiadomo przed zmianami

**Odczyt własny kodu:** `StoreUploadedImage` wywołuje `UsunGps::zBajtow()`
przed `Storage::put()`. Oryginał zachowuje pozostały EXIF (D-023), podgląd
i warianty powstają przez kodowanie WebP. W `config/filesystems.php` dyski
zdjęć dzielą poświadczenie `AWS_ACCESS_KEY_ID`; `r2_kopie` służy kopiom BAZY.
Nie znaleziono wykonywalnego procesu drugiej kopii zdjęć w konfiguracji
ani skryptach kopii. Sam kod nie rozstrzyga konfiguracji konta Cloudflare.

**Pomiar własny nietkniętego drzewa:** `--filter OryginalTraci` — 18 testów,
63 asercje, wszystkie zielone; JPEG oraz kontenery PNG/WebP. Pierwszy start
przed zakończeniem przygotowania runtime nie znalazł `vendor/autoload.php`;
nie liczy się jako wynik. Powtórzenie wykonano po zakończeniu przygotowania.

**[pomiar cudzy: komentarze #617 z 17–18.09.2026]** wcześniejszy restore
dotyczył dwóch katalogów i 20 obiektów; nie sprawdzał odmowy tokenu ani HTTP.
Twierdzenia o produkcyjnym braku kopii i historycznych brakach zdjęć pochodzą
z tych komentarzy; w tej sesji nie zostały zweryfikowane na produkcji.
Nie przenosimy ich czasów do wyników obecnej próby.

## #617 — wybór do zatwierdzenia

| Wariant | Korzyść | Koszt i ograniczenie |
|---|---|---|
| Blokada aktywnych oryginałów | Odrzuca DELETE/overwrite w okresie ochrony | Koliduje z normalnym kasowaniem przez aplikację; nie rekomendujemy |
| Osobne konto R2, prywatna kopia z Bucket Lock | Błąd aplikacji nie dosięga kopii; token kopiujący nie zdejmie blokady | Administrator konta kopii nadal może zdjąć regułę; potrzebne kopiowanie, retencja i alarm |
| Osobne konto AWS S3, Versioning + Object Lock COMPLIANCE | Chroni konkretne wersje także przed administratorem w okresie ochrony | Inny dostawca, rachunek za transfer przy odtwarzaniu, trudniejsze usuwanie danych; wymaga odrębnej decyzji |
| Akceptacja ryzyka | Brak nowej usługi i opłat | Logicznie usunięte zdjęcia mogą być nieodzyskiwalne; zapisać właściciela ryzyka i datę ponownej oceny |

**Rekomendacja:** osobne konto R2 z ograniczonymi tokenami i czasową blokadą
kopii. Jeśli wymaganiem jest odporność także na administratora konta kopii,
ten wariant nie wystarcza — rozważyć S3 COMPLIANCE. Nie ogłaszamy decyzji
za właściciela i nie ustawiamy retencji produkcyjnej.

### Co włączyć po stronie dostawcy

Na **osobnym koncie Cloudflare**, z odrębnym dostępem administracyjnym i MFA,
utworzyć prywatny bucket kopii w uzgodnionej jurysdykcji. Wyłączyć `r2.dev`,
nie przypisywać domeny ani publicznego Workera. W Settings → Bucket lock
rules dodać regułę na `snapshots/` na 30 dni. Oddzielnie w Object lifecycles
dodać kasowanie tego prefiksu po 31 dniach. To propozycja okresów do decyzji,
nie obietnica terminu usunięcia: lifecycle wykonuje się asynchronicznie.
Blokada nie jest kasowaniem; po terminie trzeba zmierzyć zniknięcie obiektu.
[Bucket Locks](https://developers.cloudflare.com/r2/buckets/bucket-locks/),
[lifecycle](https://developers.cloudflare.com/r2/buckets/object-lifecycles/).

**Nie wydawać polecenia `put-bucket-versioning` na R2.** Operacje wersjonowania
i S3 Object Lock nie są obsługiwane przez jego API S3. Bucket Locks to osobna
funkcja Cloudflare. W wariancie AWS włączyć Versioning i Object Lock z domyślną
retencją COMPLIANCE na buckecie kopii; zachowywać `VersionId` w manifeście.
[Zgodność API R2](https://developers.cloudflare.com/r2/api/s3/api/),
[S3 Object Lock](https://docs.aws.amazon.com/AmazonS3/latest/userguide/object-lock.html).

### Kto ma jakie poświadczenie

| Rola | Zakres w R2 | Gdzie wolno trzymać |
|---|---|---|
| Aplikacja | Object Read & Write wyłącznie na aktywnych bucketach; żadnego dostępu do konta kopii | Serwis aplikacji |
| Czytanie źródła dla kopii | Object Read tylko na bucketach zdjęć; bez eksportów kont i kopii bazy | Osobny proces kopii, poza aplikacją |
| Zapis kopii | Object Read & Write tylko na buckecie kopii, bez uprawnień administracji | Osobny proces kopii |
| Odtwarzanie | Object Read tylko na buckecie kopii | Stanowisko operatora na czas odtworzenia |
| Zapis odtworzonych plików | Osobne, czasowe Object Read & Write do docelowych aktywnych bucketów | Operator; nie proces zwykłego kopiowania |
| Administracja kopią | Edycja konfiguracji bucketu, reguł i tokenów | Właściciel konta kopii; nigdy aplikacja ani harmonogram kopii |

Standardowy token R2 **Object Read & Write obejmuje kasowanie** — nie nazywać
go „tylko dopisującym”. Przed skasowaniem młodej kopii chroni reguła, nie nazwa
tokenu. Po upływie ochrony ten token może ją usunąć. Object Read służy odczytowi
i listowaniu; nie przypisywać zwykłym tokenom R2 dowolnych polityk IAM z MinIO.
[Uprawnienia R2](https://developers.cloudflare.com/r2/api/tokens/).

### Jak kopiować, żeby kopia nie znikała razem ze źródłem

Prosty wariant do wyceny: codzienny kompletny snapshot wszystkich objętych
kopią oryginałów i wariantów, pod **nowym** prefiksem
`snapshots/<UTC>-<losowy-id>/<dysk>/<klucz>`. Nigdy synchronizacja z opcją
usuwania brakujących plików, nigdy nadpisanie jedynej starej kopii.

Manifest zawiera identyfikator zdjęcia, dysk i klucz źródła, klucz kopii,
SHA-256 oraz rzeczywistą liczbę bajtów pobranego obiektu. Nie ufać `media.bytes`
(może opisywać wejściowy upload), nie zastępować SHA przez ETag. Po każdym
zapisie pobrać kopię i porównać hash oraz rozmiar. Manifest zapisać i potwierdzić
na końcu w tym samym chronionym prefiksie. Brak jednego obiektu lub zmiana
źródła w trakcie odczytu oznacza niekompletny snapshot i alarm, nie sukces.

**W tym pakiecie nie ma harmonogramu produkcyjnego ani kompletnego programu
kopiowania zasobu produkcyjnego.** Przyrząd wykonuje ten model na trzech
kontrolnych mediach; nie implementuje stronicowania całego R2, wznowień ani
trwałego katalogu manifestów. Ich wdrożenie następuje po decyzji.

Odczyt źródła opierać na stanie aplikacji, z wyłączeniem danych objętych
usunięciem. Nie skanować starej kopii jako źródła kolejnej kopii, bo odnowiłoby
to retencję usuniętych zdjęć. Monitor poza aplikacją powinien mierzyć wiek
ostatniego kompletnego manifestu, braki obiektów, zgodność reguł, postęp
retencji i okresowe odtworzenie. Przekroczenie doby plus czasu kopiowania
wymaga alarmu; zielony proces bez kompletnego manifestu nie jest kopią.

### Retencja i koszt — bez ukrytego mnożnika

30 dni ochrony + kasowanie po 31 dniach oznacza około **32 pełnych zestawów**
przy codziennym snapshotcie i typowym opóźnieniu lifecycle, nie jeden zestaw.
To przybliżenie, nie górna gwarantowana granica czasu ani kosztu.
Dla stałych 111 GB daje około 3552 GB kopii: **53,28 USD/mies.** za sam
Standard storage przed darmowym progiem; 111 GB jednej kopii to 1,665 USD,
ale pojedyncza kopia starzeje się i traci ochronę. Nie podawać jej ceny jako
kosztu miesięcznego modelu snapshotów.

Do tego liczba obiektów × liczba przebiegów: dla 150 tys. obiektów i 30 dni
to 4,5 mln PUT oraz co najmniej 9 mln GET (źródło + kontrola kopii), plus
listowania i manifesty. Stawki Standard: 4,50 USD/mln klasy A, 0,36 USD/mln
klasy B, 0,015 USD/GB-mies.; transfer wychodzący R2 bez opłaty. Progi darmowe
są współdzielone z innym użyciem konta, a jednostki rozliczeniowe zaokrąglane.
Dochodzi koszt hosta procesu i monitorowania. To model, nie odczyt rachunku.
[Cennik odczytany 20.09.2026](https://developers.cloudflare.com/r2/pricing/).

Mniejszy koszt daje kopia przyrostowa, ale potrzebuje zarządzania odniesieniami,
odnawiania ochrony nadal używanych obiektów oraz usuwania nieużywanych. Zwykłe
„kopiuj raz, pomijaj istniejące” po 30 dniach zostawia stare zdjęcia bez rygla.
Nie dokładamy tego mechanizmu bez wyboru kosztu i retencji przez właściciela.

### Odtwarzanie a świadome usunięcie

Nie włączać blokady na aktywnym `incoming/` ani na paczkach eksportu kont.
Kopię traktować jako niedostępne techniczne archiwum; nie podłączać jej jako
dysku serwującego. Przed publikacją odtworzonych bajtów uzgodnić manifest ze
stanem aplikacji i rejestrem żądań usunięcia **nowszym od odtwarzanej bazy**.
Stary dump nie wystarcza: odtworzyłby także dawne zgody i skasowane treści.
Przy braku wiarygodnego rejestru wstrzymać udostępnienie; odzysk techniczny
do prywatnego środowiska nie jest przywróceniem treści użytkownikom.

Wymaga decyzji z #8: okres utrzymania archiwum, zapis w polityce prywatności,
operator nadzorujący rzeczywiste wygasanie oraz sposób zachowania rejestru
usunięć po awarii bazy. Ten dokument nie stwierdza zgodności prawnej.

## Próba odmowy na R2 — odbiór po zatwierdzeniu, nie wykonany tutaj

Użyć **oddzielnego bucketu odbiorowego**, tego samego rodzaju tokenów i reguł
co projektowana kopia. Włożyć wyłącznie własne syntetyczne zdjęcie. Zapisać
datę, zakres tokenu, konfigurację reguły, rozmiar i SHA-256. Sekrety poza raportem.

1. Tokenem kopiującym zapisać obiekt `snapshots/proba/<losowy-id>.jpg`.
2. Tym samym tokenem wykonać `DeleteObject` oraz `PutObject` pod tym samym
   kluczem z inną treścią. Obie operacje muszą zostać odrzucone. Następny GET
   ma zwrócić pierwotny hash, nie tylko status 200.
3. Kontrola dodatnia: tym tokenem utworzyć i usunąć obiekt pod `control/`,
   poza prefiksem rygla. Jeżeli to też odmawia, nie udowodniono działania rygla.
4. Tokenem aplikacji spróbować odczytu i kasowania kopii, tokenem czytelnika
   zapisu i kasowania, tokenem kopiującym zmiany reguł przez API zarządzające.
   Wszystkie mają odmówić. Zapisać faktyczne statusy i kody błędów, nie sekret
   ani cały podpisany URL. Nie zakładać identycznego kodu błędu jak w MinIO.
5. Administrator na **osobnym** prefiksie testowym sprawdza zdjęcie reguły
   i skuteczny DELETE. To dowód granicy R2, nie obejście zabezpieczenia produkcji.
6. Na własnym obiekcie z krótką regułą odbiorową sprawdzić HEAD przed i po
   terminie lifecycle aż do rzeczywistego zniknięcia; zanotować opóźnienie.
   Nie skracać przy tym reguł właściwej kopii.
7. Utracić własne źródła testowe, odtworzyć z manifestu, sprawdzić sumy,
   rozmiary, wpisy i wszystkie warianty przez autoryzowaną trasę aplikacji.

Przykład poleceń S3 dla skonfigurowanego profilu **odbiorowego**, po sprawdzeniu
konta, nazwy bucketu i syntetycznego klucza (nie wykonywano na R2):

```bash
aws --profile dr-proba-pisarz --endpoint-url "$DR_PROBA_ENDPOINT" s3api put-object \
  --bucket "$DR_PROBA_BUCKET" --key "$DR_PROBA_KEY" --body ./kontrola.jpg
aws --profile dr-proba-pisarz --endpoint-url "$DR_PROBA_ENDPOINT" s3api delete-object \
  --bucket "$DR_PROBA_BUCKET" --key "$DR_PROBA_KEY"
aws --profile dr-proba-pisarz --endpoint-url "$DR_PROBA_ENDPOINT" s3api put-object \
  --bucket "$DR_PROBA_BUCKET" --key "$DR_PROBA_KEY" --body ./inna-kontrola.jpg
aws --profile dr-proba-czytelnik --endpoint-url "$DR_PROBA_ENDPOINT" s3api get-object \
  --bucket "$DR_PROBA_BUCKET" --key "$DR_PROBA_KEY" ./odczyt.jpg
sha256sum ./kontrola.jpg ./odczyt.jpg
```

R2 nie daje tu `VersionId`; generacje są osobnymi kluczami. W S3/MinIO testować
**DELETE z VersionId**, bo zwykły DELETE może utworzyć delete marker i zwrócić
sukces mimo chronionej wersji. Nadpisanie może dodać nową wersję; odzyskiwać
wersję wskazaną w zatwierdzonym manifeście, nie „najnowszą”.

## #602 — nie rekomendujemy direct upload do kwarantanny

Kto usuwa GPS dziś: `UsunGps`, synchronicznie na serwerze przed zapisem do
R2. Pozostały EXIF oryginału zostaje na mocy D-023. Warianty czyści koder.
Istnieją znane granice sanitatora (XMP, profile tekstowe PNG, szczególna obsługa
AVIF); **nie ma dowodu, że obecny kod usuwa każdy możliwy zapis lokalizacji**.
Nie maskujemy tych ograniczeń nową asercją ani rozszerzoną obietnicą.

W proponowanej drodze przeglądarka → R2 → worker GPS usuwałby **worker po
utrwaleniu surowego pliku**. W razie braku potwierdzenia, awarii lub zatrzymania
kolejki GPS zostaje do wykonania sprzątania. Prywatność bucketu ogranicza
dostęp, ale nie usuwa danych. Lifecycle też nie gwarantuje zerowego okna.
Nie potrafimy wykazać, że lokalizacja nigdy nie przeleży w kwarantannie;
przeciwnie, lokalny PUT jest bezpośrednim kontrprzykładem.

| Wariant | Koszt / konsekwencja | Ocena |
|---|---|---|
| Obecny serwer przed R2 | Transfer i praca PHP; zachowanie obecnej kolejności | Rekomendowany na teraz |
| Czyszczenie w przeglądarce | Można ominąć lub podmienić klienta; brak zaufanej bariery | Nie daje wymaganej gwarancji |
| Prywatna kwarantanna + worker | Dodatkowy odczyt/zapis, sprzątanie, kontrola rozmiaru i porzuconych uploadów; surowy GPS w storage | Nie rekomendujemy przy obecnym wymaganiu |
| Zaufany serwer/proxy czyszczący przed R2 | Nowa infrastruktura dekodowania, pamięć, kolejka i monitoring | Może zachować kolejność, ale nie jest direct uploadem surowych bajtów do R2 |

Nie przedstawiamy wyniku jako benchmarku przepustowości. Nie mierzono
nasycenia web tier ani kosztu produkcyjnego request-path. Powrót do tematu
wymaga wyniku #605 i oddzielnej decyzji o dopuszczalności surowej kwarantanny.
Nie zmieniono formularzy, endpointów, konfiguracji Livewire ani obietnic prywatności.

## Uruchomienie lokalnego odpowiednika i zakres dowodu

Z Git Bash w worktree, po przygotowaniu zależności i własnej bazy skryptami floty:

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-dr-zdjecia
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-dr-zdjecia --filter OryginalTraci
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /home/mateusz/flota/gpt-dr-zdjecia-run/scripts/proba-dr-zdjec.sh
```

Wymagania: Docker z dostępem do quay.io, Python 3 (biblioteka standardowa),
runtime PHP projektu i PostgreSQL **127.0.0.1:55439**, baza
`kuking_flota_gpt-dr-zdjecia`. Nie instaluje pakietów aplikacji. Obrazy MinIO
i mc są przypięte digestami. Port MinIO wybiera Docker, tylko na loopback;
kontener i wszystkie poświadczenia są losowe. Przyrząd nie przyjmuje endpointu,
bucketu ani tokenu produkcyjnego. Sprząta własny kontener także po czerwieni.

MinIO jest lokalnym odpowiednikiem **mechanizmu S3**, nie emulatorem
konfiguracji Cloudflare. Jednodniowe COMPLIANCE w próbie nie ustanawia okresu
retencji Kuking. Oddzielne role w jednym MinIO sprawdzają polityki dostępu,
nie izolację dwóch prawdziwych kont dostawcy. Testy w `tests/Dr/` są uruchamiane
jawnie przez przyrząd, nie w standardowej suicie bez MinIO.

Wyniki, kontrola ujemna i ograniczenia: [raport pomiaru](evidence/dr617/RAPORT.md).
Rollback pakietu: usunąć przyrząd i dokumentację; brak migracji, zmiany
aplikacji ani konfiguracji dostawcy do cofnięcia. Po przyszłym wdrożeniu
zatrzymanie kopii nie może usuwać istniejącego archiwum; cofnięcie polityki
retencji wymaga osobnego rozstrzygnięcia właściciela.
