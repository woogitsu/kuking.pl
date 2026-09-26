## D-049 · Zrzut szyfrujemy KLUCZEM PUBLICZNYM, a podpis do R2 liczymy sami w powłoce

**Data:** 9 września 2026 · Status: **obowiązuje** · Wykonanie: issue #193

> **To nie jest nowa decyzja o kierunku — kierunek rozstrzyga D-043.**
> To są cztery rozstrzygnięcia W ŚRODKU tamtej decyzji, podjęte przy pisaniu
> `docker/kopia/`. Trafiają do dziennika, bo każde z nich będzie kiedyś
> wyglądało na dziwne i zaproszy do „uproszczenia", które cofnie własność,
> o którą chodziło.

**1. SZYFROWANIE KLUCZEM PUBLICZNYM (CMS/PKCS#7), NIE HASŁEM.**
Zrzut to komplet danych osobowych wszystkich kont w jednym pliku. Gdyby
szyfrował go `openssl enc` z hasłem, to hasło musiałoby leżeć w Railwayu —
czyli w tym samym miejscu, co baza i co bucket. Przejęcie konta Railway
dawałoby wtedy jednocześnie bazę, kopie i klucz do kopii. Klucz publiczny
odwraca to: w Railwayu leży certyfikat, którym **da się tylko zaszyfrować**.
Klucz prywatny nie istnieje w żadnym środowisku uruchomieniowym.

Cena jest symetryczna i trzeba ją znać: **utrata klucza prywatnego czyni
wszystkie kopie nieczytelnymi na zawsze.** Dlatego dwie kopie klucza,
w dwóch różnych miejscach — dokładnie ta sama zasada, co przy `APP_KEY`
(`KOPIE_I_ODTWORZENIE.md` §1.1 i §7.1).

**Skrypt odmawia pracy, gdy w tej zmiennej znajdzie klucz prywatny** (kod
wyjścia 64). Dopisane przy przeglądzie tego PR-a, bo sama deklaracja „w
Railwayu leży tylko część publiczna" nie miała w kodzie żadnego oparcia:
`openssl x509` przechodzi również na wartości będącej wynikiem
`cat kuking-kopie-publiczny.pem kuking-kopie-PRYWATNY.pem`, a to jest jedna
z dwóch najprawdopodobniejszych pomyłek przy wklejaniu do panelu (drugą,
pomylenie plików o jedną literę w nazwie, §7.1 wymienia wprost). Kopie
powstawałyby dalej — tylko klucz do ich odczytu leżałby od tego momentu w tym
samym Railwayu, co baza i co bucket, czyli cała własność z tego punktu byłaby
cofnięta i **nic by o tym nie powiedziało**. Cisza jest tu droższa niż brak
kopii, bo brak kopii widać w panelu.

**DLACZEGO CMS, A NIE `age` ANI `gpg`.** Bo `openssl` jest wszędzie.
Odtworzenie kopii ma się udać w dniu, w którym wszystko inne się wali,
z dowolnego komputera, bez instalowania czegokolwiek — jedną komendą
(`KOPIE_I_ODTWORZENIE.md` §7.4). `age` to binarka do pobrania, `gpg` to
keyring i cała ceremonia. Obie dokładają krok „najpierw zdobądź narzędzie"
do procedury awaryjnej, a to jest najgorsze możliwe miejsce na taki krok.

**2. PODPIS AWS SIGV4 LICZYMY SAMI, NAD `openssl` i `curl` — bez `aws` CLI
i bez `rclone`.** Ten kontener trzyma w rękach zrzut całej bazy; każda
dołożona paczka to kod z prawem przeczytania tego pliku i prawem gadania po
sieci. `aws` CLI to Python i botocore z własnym łańcuchem zależności,
`rclone` to binarka pobierana z internetu przy budowie obrazu. SigV4 to sto
linii nad narzędziami, które w tym obrazie i tak muszą być (openssl szyfruje,
curl dzwoni na webhook). Ta sama zasada, z której powstał własny transport
poczty (D-047): nie dokładamy paczki, gdy da się bez niej.

**Warunek, na którym to stoi:** algorytm jest sprawdzany **urzędowymi
wektorami AWS** (klucz podpisu z dokumentacji Signature Version 4 i sygnatura
`get-vanilla` z `aws-sig-v4-test-suite`) w `tests/skrypty/kopia-bazy.sh`,
a nie porównaniem z drugą własną implementacją. Dwie implementacje jednej
osoby potwierdzają wspólne nieporozumienie równie chętnie, jak poprawność.
**Gdyby te wektory kiedykolwiek zniknęły z testów, ta decyzja przestaje
obowiązywać** — wtedy lepsza jest paczka od nieweryfikowanego podpisu.

**3. RAILWAY CRON, MIMO ŻE `railway.ts` ODRZUCA GO PRZY SCHEDULERZE.**
Nie jest to niespójność. Przy schedulerze Laravela problemem była
GRANULACJA: `everyMinute()` wymaga odpytywania co minutę, a Railway Cron ma
minimum 5 minut. Tutaj granulacja nie ma żadnego znaczenia — kopia raz na
dobę może wystartować pięć minut później. Cron jest za to jedyną formą, w
której kontener wstaje, robi swoje i **umiera**, nie płacąc za czas
pomiędzy. `restartPolicyType: "NEVER"`, bo nieudany zrzut ma zostać nieudany
i zaalarmować, a nie wstawać w pętli i zrzucać całą bazę co kilkadziesiąt
sekund.

**4. CZUJKA W APLIKACJI JEST OSOBNYM ZABEZPIECZENIEM, NIE DUBLOWANIEM.**
Serwis kopii alarmuje, gdy jego przebieg się nie udał. Nie zaalarmuje, gdy
przebiegu NIE BYŁO: serwis skasowany, harmonogram wyłączony, limit konta
wyczerpany, token wygasł. **Kod, który wtedy nie chodzi, nie może o sobie
donieść** — a #193 nazywa ten stan najgorszym z możliwych, bo „myślisz, że
masz kopię". Dlatego `kuking:sprawdz-kopie` patrzy z drugiej strony: raz na
dobę listuje bucket i dzwoni, gdy najnowsza kopia jest za stara.

Aplikacja dostaje do tego bucketu token **TYLKO DO CZYTANIA**, osobny od
tokenu serwisu kopii. Gdyby miała prawo zapisu, udany atak na nią mógłby
**skasować kopie** — czyli dokładnie to, przed czym ta warstwa ma chronić.
Kopia, którą da się zniszczyć z zaatakowanego serwisu, nie jest kopią
offsite.

**PIĄTA RZECZ, KTÓREJ NIE MA I TRZEBA O NIEJ WIEDZIEĆ.** Nie ma trzeciego,
niezależnego świadka. Jeśli padnie i serwis kopii, i aplikacja, milczenie
będzie zupełne — do cotygodniowego przeglądu z `KOPIE_I_ODTWORZENIE.md` §6.
Zewnętrzny „dead man's switch" (usługa, która dzwoni, gdy PRZESTANIE
dostawać sygnał) byłby na to właściwą odpowiedzią i jest świadomie odłożony:
to szósta usługa w projekcie obsługiwanym przez jedną osobę, a przegląd raz
na tydzień zamyka lukę do tygodnia, nie do nieskończoności.

**Zmiana wymaga:** przeczytania D-043 najpierw. Punkty 1 i 4 są granicami
bezpieczeństwa, nie preferencjami — hasło zamiast klucza publicznego albo
token z prawem zapisu w aplikacji cofają całą własność, o którą chodziło.

📄 `docker/kopia/Dockerfile` · `docker/kopia/kopia-bazy.sh` ·
`docker/kopia/s3.sh` · `.railway/railway.ts` (serwis `kopia-bazy`) ·
`app/Domain/Kopie/StanKopiiBazy.php` · `app/Domain/Kopie/AlarmKopii.php` ·
`app/Console/Commands/SprawdzKopieBazy.php` ·
`tests/skrypty/kopia-bazy.sh` ·
`docs/infra/KOPIE_I_ODTWORZENIE.md` §7 · D-043 · D-041 · D-047 · #193 · #120
