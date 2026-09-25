## D-054 · Zdjęcie profilowe ma własny, krótki ekran `/ustawienia/zdjecie` — pole zostało z formularza profilu PRZENIESIONE, nie skopiowane

**Data:** 9 września 2026 · **Prośba właściciela** · Status: **obowiązuje**

Właściciel: *„zdjęcie profilowe łatwiej niż teraz, trzeba teraz wyklikać
ustawienia, coś tam, profil, coś tam, zjechać itp."*

Funkcja istniała od dawna — brakowało **drogi** do niej. Pole `name="avatar"`
stało jako **szóste** pole formularza `/ustawienia/profil`, pod imieniem,
nazwą użytkownika, opisem, regionem i specjalnością.

Trzy rzeczy, w tej kolejności:

1. **Własny awatar na `/@ja` jest odnośnikiem** wprost do ustawienia zdjęcia.
   To jest miejsce, w które człowiek klika instynktownie, a do tej zmiany nie
   robiło ono nic.
2. **Podpis pod awatarem jest widoczny zawsze** — „Dodaj zdjęcie profilowe"
   albo „Zmień zdjęcie profilowe". Bez zdjęcia stoi tam sama litera i nic nie
   mówi, że da się to zmienić; klikalna ikona bez opisu łamie AGENTS.md §5.
   Nazwa jest długa CELOWO: wiersz niżej stoi „Dodaj zdjęcie", które prowadzi
   do dodania WPISU ze zdjęciem potrawy.
3. **Osobny ekran, nie kotwica.** `#f-avatar` w starym formularzu wyrzucałaby
   na telefonie w środek ekranu pełnego cudzych pól.

### DLACZEGO PRZENIESIONE, A NIE SKOPIOWANE

Zostawienie pola w obu miejscach dałoby dwa formularze robiące to samo —
drugą okazję do rozjazdu, tę samą, przed którą broni się `LimityZdjec`
i `KasujZdjecie::ODWOLANIA`. Przy okazji znika usterka, o którą nikt nie
pytał: formularz profilu wysyła wszystkie pola naraz, więc zmiana samego
zdjęcia odbijała się od błędu przy **nazwie użytkownika** (zajęta,
zastrzeżona) — czyli od czegoś, czego człowiek nie dotykał.

**Potok zdjęć nie zmienia się ani o krok**: `StoreUploadedImage` →
`UsunGps` → `ProcessUploadedImage`, oryginał pod `incoming/`, status
`pending`, warianty dopiero z zadania w tle. Pilnuje tego osobny test na tej
konkretnej trasie (`test_zdjecie_profilowe_idzie_tym_samym_potokiem_i_traci_gps`),
bo „ta sama akcja jest wołana" i „ta trasa naprawdę przez nią idzie" to dwa
różne zdania.

### USUNIĘCIE ZDJĘCIA — FUNKCJA, KTÓREJ NIE BYŁO WCALE

Dało się tylko podmienić. Kto wgrał zdjęcie przez pomyłkę, nie miał jak go
zdjąć. Usunięcie kasuje pliki **od razu** (`KasujZdjecie`), a nie zostawia ich
dobowej karencji `kuking:sprzataj-osierocone-zdjecia`: serwis odpowiada
„Zdjęcie usunięte", a plik z czyjąś twarzą otwierałby się dalej pod tym samym
adresem (ta sama klasa błędu co issue #93). **Podmiana** zostaje przy
karencji — tam takiej obietnicy nie ma, a kasowanie plików to ruch po sieci
doklejony do żądania, które właśnie przyjęło kilkumegabajtowy plik.

**Zmiana wymaga:** pomiaru mówiącego, że ludzie szukają zdjęcia w formularzu
profilu i go tam nie znajdują. Wtedy właściwą odpowiedzią i tak nie jest drugie
pole, tylko wyraźniejszy odnośnik — który już tam stoi, z podglądem awatara.

📄 `app/Http/Controllers/Settings/AvatarSettingsController.php` ·
`app/Policies/ProfilePolicy.php` (`update`) ·
`resources/views/pages/settings/avatar.blade.php` ·
`resources/views/pages/profile/show.blade.php` ·
`routes/web.php` · `config/kuking.php` (`limits.ustawienia_profil`) ·
`tests/Feature/ZdjecieProfiloweNaSkrotyTest.php` ·
`tests/Support/JpegZeWspolrzednymiGps.php`
