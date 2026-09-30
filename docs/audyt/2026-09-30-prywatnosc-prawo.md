# Audyt 2026-09-30 — prywatność i prawo

**Stan kodu:** `origin/claude/paczka-i-kandydat` @ `5548c7e16` (BAZA, przyszły `main`).
**Rodzaj pracy:** odczyt kodu, konfiguracji i dokumentów oraz odtworzenia na lokalnej bazie
testowej PostgreSQL 18. Kodu aplikacji nie zmieniałem. Nie łączyłem się z produkcją, Railway,
Cloudflare, R2 ani EmailLabs.

**Zakres:** zgodność `resources/legal/polityka-prywatnosci.md`, `resources/legal/regulamin.md`
i `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` z tym, co kod naprawdę robi. Sprawdziłem tabele
z danymi osobowymi, odbiorców, ciasteczka i pamięć przeglądarki, DSA oraz drogi w interfejsie
do praw osoby.

**Czego świadomie nie zgłaszam (D-333):** braku opinii prawnika (#8) i pytań do prawnika.
Serwis nie ma jeszcze prawdziwych użytkowników. Nie zgłaszam też spraw, które już opisują
otwarte issues: #2214 (flagi importu do OpenAI), #2217 (dowód akceptacji regulaminu), #2218
(potwierdzenie odbioru zgłoszenia DSA), #2219 (art. 14 przy zaproszeniach), #2221 (gość bez
„Zgłoś”) i #2222 (anonimowe zgłoszenie DSA).

**Odtworzenia.** Znaleziska Z1–Z4 odtworzyłem jednym tymczasowym testem PHPUnit.
Uruchomiłem go na lokalnej bazie `kuking_test_*` poleceniem
`APP_BASE_PATH=$(pwd) php artisan test` i nie commitowałem go. Wynik: 4 z 4 testów zielone,
co potwierdza każde z tych znalezisk. Kod testu jest w opisie każdego znaleziska.
Zgłoszenia znalazłem ręcznie w pełnej liście issues (`/issues?state=all`, 904 pozycje),
bo wyszukiwarka API GitHuba zwracała 403.

## Podsumowanie

- **Znaleziska:** 11 — P0: 0, P1: 0, P2: 6 (Z1–Z6), P3: 5 (Z7–Z11).
- **Najważniejsze:**
  - Z1: obietnica sprzeciwu wobec statystyk nie ma drogi w kodzie.
  - Z2: polityka mówi o sesji do 30 dni, a przeglądarka loguje ponad rok przez ciasteczko
    „zapamiętaj mnie”, którego polityka nie wymienia.
  - Z3: zdjęcia i notatki „Ugotowałem” oraz publiczne zeszyty nie mają przycisku „Zgłoś”.
  - Z4: zdanie o „jednej, nadpisywanej wartości” jest nieprawdziwe — w bazie jest historia
    wysyłek, która przeżywa wymazanie konta.
  - Z5: tabela celów nie nadąża za funkcjami V2.
  - Z6: pośrednik całego ruchu (Cloudflare jako CDN i proxy) nie ma wiersza wśród odbiorców.
- **Otwarte issues, które na BAZIE wyglądają na zrobione** (do sprawdzenia i zamknięcia
  przez koordynatora):
  - #2214 — domyślne `KUKING_IMPORT_URL`, `_PDF` i `_ZDJECIE` to `false`
    (`config/kuking.php:3817,3826,3861`);
  - #2217 — dziennik akceptacji regulaminu
    (`database/migrations/2026_09_29_234500_dziennik_zgod_akceptacja_regulaminu.php`,
    `app/Domain/Zgody/ZapiszAkceptacjeRegulaminu.php`);
  - #2219 — informacja z art. 14 w mailu zaproszenia
    (`resources/views/mail/zaproszenie-do-zalozenia-konta.blade.php:109`);
  - #2220 — regulamin ma już §13 i §14.

## Tabela znalezisk

| ID | Waga | Tytuł | Dowód (plik:linia na BAZIE) | Odtworzenie | Wpływ | Proponowana poprawka i test regresyjny | Rozmiar | Duplikat? |
|---|---|---|---|---|---|---|---|---|
| Z1 | P2 | Polityka obiecuje sprzeciw wobec statystyk, ale serwis nie ma jak go uszanować | `resources/legal/polityka-prywatnosci.md:77` („możesz się temu sprzeciwić (sekcja 4)”), `:108`; skrypt Cloudflare ładuje się każdemu: `resources/views/components/layout.blade.php:369` (warunek: tylko flaga globalna i strona); `app/Domain/Analytics/ZapiszSygnal.php:149` i `ZanotujOstatniaWizyte.php:84` zapisują dla każdego konta; w `users` nie ma pola sprzeciwu (grep `sprzeciw` w `app/`, `resources/views`, `config/` znajduje tylko treść maila zaproszenia) | statyczne: grep + odczyt warunku w layoucie | Prawo (art. 21 ust. 1 RODO): gdy ktoś napisze „sprzeciwiam się”, jedyne, co da się zrobić, to wyłączyć statystyki wszystkim albo nie uszanować sprzeciwu. Dla osoby 50+ obietnica bez przycisku to ślepa uliczka | Pole `users.analytics_opt_out` (migracja + rollback + DATABASE.md), przełącznik „Nie licz mnie w statystykach” w ustawieniach prywatności. Gdy pole jest ustawione, layout pomija beacon Cloudflare dla zalogowanego, a `ZapiszSygnal` i `ZanotujOstatniaWizyte` nic nie zapisują. Gościom polityka mówi wprost, że sprzeciw realizuje blokada skryptów w przeglądarce. Test: konto ze sprzeciwem → brak `<script … beacon>` w HTML, brak wiersza `product_signals`, `ostatnio_widziany_at` bez zmian | M | brak (lista issues: „sprzeciw”, „opt-out” — 0 trafień) |
| Z2 | P2 | Ciasteczko „zapamiętaj mnie” żyje 400 dni, a polityka mówi o sesji do 30 dni i go nie wymienia; pomija też `localStorage` | `Auth::login(..., remember: true)`: `app/Http/Controllers/Auth/LoginController.php:103`, `LoginLinkController.php:329`, `RegisterController.php:259`, `app/Domain/Security/WejsciePrzezDostawce/WejdzPrzezDostawce.php:132`; czas życia z frameworka `vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php:62` (`576000` minut), bez nadpisania w `app/`; polityka: `polityka-prywatnosci.md:45` („Do 30 dni od ostatniej aktywności … potem sesja wygasa”), `:117` (trzy cele ciasteczek, nazwane tylko `kuking_text_scale` i `motyw`); `resources/js/szybki-wyglad.js:34` zapisuje trwale `localStorage['kuking-wyglad-poznany']` | **test** `r1`: POST `/login` → ciasteczko `remember_web_<hash>` z terminem **400 dni**; polityka nie zawiera `remember_web`, „400 dni” ani `localStorage` | Na wspólnym komputerze (biblioteka, rodzina) osoba 50+ czyta, że po 30 dniach bez wizyty zostanie wylogowana. W rzeczywistości przeglądarka loguje ją sama przez ponad rok. Strażnik `PolitykaNazywaCiasteczkaUstawienTest` sam deklaruje zasadę „człowiek ma prawo wiedzieć, co zostaje na jego urządzeniu i jak długo”, a pilnuje tylko dwóch ciasteczek | Decyzja właściciela: skrócić recaller (`Auth::guard('web')->setRememberDuration(...)` w providerze, np. 30 dni) albo opisać go w polityce. W §5 dopisać ciasteczko sesji, `XSRF-TOKEN`, `remember_web_*` z terminem oraz `localStorage` `kuking-wyglad-poznany`, a także pamięć karty (`sessionStorage`) trybu gotowania i pamięć podręczną `sw.js`. Test: rozszerzyć `PolitykaNazywaCiasteczkaUstawienTest` o nazwę i termin recallera czytane z `SessionGuard::getRememberDuration()` oraz o klucze `localStorage` z `resources/js` | S–M | nie (#584 i #930 dotyczą bezpieczeństwa recallera, nie informacji) |
| Z3 | P2 | „Ugotowałem” (zdjęcie + notatka) i publiczny zeszyt nie mają przycisku „Zgłoś”, a regulamin obiecuje go „przy każdej treści” | `resources/legal/regulamin.md:117`; `resources/views/components/cooked-card.blade.php:70-92` renderuje zdjęcia, `note` i `changes_note` bez odnośnika do `reports.create`; `resources/views/pages/cooked/show.blade.php:15-27` też go nie ma; backend przyjmuje `cooked_event`: `app/Http/Controllers/ReportController.php:339`, `app/Domain/Moderation/ModeratedContent.php:43`; zeszyt nie jest celem zgłoszenia (`ModeratedContent.php:38-44`), a `resources/views/pages/collections/show.blade.php:67-68` pokazuje publiczny opis | **test** `r2`: zalogowany widz na `/ugotowane/{id}` widzi notatkę, a w HTML nie ma adresu `/zglos/cooked_event/…`. Sam formularz `GET /zglos/cooked_event/{id}` → 200. **Test** `r3`: publiczny zeszyt 200 z opisem, brak `/zglos/`, a `GET /zglos/collection/{id}` → 404 | DSA art. 16: zgłoszenie ma być „łatwo dostępne”. Zdjęcie i notatka „Ugotowałem” to treść publiczna tak samo jak wpis. Osoba 50+ nie znajdzie drogi i musi przepisywać adres do formularza DSA albo pisać maila | Dodać `Zgłoś` do `cooked-card` (zalogowany: link, gość: `<x-zglos-dla-goscia typ="cooked_event">`). Dodać `collection` do `ModeratedContent::TYPY` i `ReportController`, z przyciskiem na publicznym zeszycie (nazwa, opis, notatki wspólne). Test renderowania dla obu widoków i test przyjęcia zgłoszenia zeszytu | M | nie (#2221 dotyczy wyłącznie gościa przy wpisie, przepisie i komentarzu) |
| Z4 | P2 | Polityka: „jedna, nadpisywana wartość, a nie historia wysyłek”. W bazie jest historia wysyłek podsumowania, bez retencji, i przeżywa wymazanie konta | `polityka-prywatnosci.md:52` (kolumna „Jak długo”); tabela `weekly_digest_sends` z kluczem `(user_id, week_start)`: `docs/DATABASE.md:5432-5456`, wiersz na każdy tydzień: `app/Domain/Digest/OdbiorcyDigestu.php:210-214`; żadna komenda retencji ani `EraseAccountData` nie dotyka tej tabeli (grep `weekly_digest_sends` w `app/` i `routes/`: tylko zapis, eksport i komentarze) | **test** `r4`: trzy rezerwacje → 3 wiersze. Po `markForDeletion()` + `EraseAccountData::handle()` konto ma `data_erased_at`, a 3 wiersze zostają | Nieprawdziwe zdanie w polityce. Po wymazaniu zostaje historia „ta osoba dostawała list w tygodniach X, Y, Z”, przypięta do zanonimizowanego `user_id`, bez terminu (dotyczy tylko włączonego `KUKING_DIGEST_WLACZONY`) | Kasować w `EraseAccountData` wiersze tego konta i dodać nocną retencję, np. 90 dni jak statystyki (klucz idempotencji potrzebuje tylko bieżącego tygodnia). Albo poprawić zdanie w polityce i podać termin. Test: wymazanie → 0 wierszy; wiersz starszy niż termin znika po komendzie | S | brak |
| Z5 | P2 | Tabela celów w polityce (§2) pomija działające funkcje: planer, wspólne zeszyty, zapamiętany postęp gotowania, wczytanie własnej paczki, region i „Na czym się znasz” w profilu | Polityka: `polityka-prywatnosci.md:31-53`. Żadne z tych słów nie opisuje tam funkcji (grep: `planer`, `wspóln`, `synchroniz`, `wczyt`, `region`). Planer: `routes/web.php:929-940` (bez flagi), `meal_plan_entries`. Wspólne zeszyty: `routes/web.php:1000-1030`, `collection_members` i `collection_invitations` (zaproszone konto, status, `responded_at`); odrzuconych i wygasłych zaproszeń nic nie kasuje (jedyny `delete` jest w `app/Domain/Collections/Wspoldzielenie/ZerwijWspoldzielenie.php:87`). Postęp gotowania: `routes/web.php:288-306`, `cooking_progress` (rejestr ma §3.24, polityka nie). Wczytanie paczki: `routes/web.php:1313-1322`, `app/Domain/Users/Import/MagazynPaczek.php:13-22`, `wczytane_z_paczki`. Profil: `resources/views/pages/settings/profile.blade.php:19-21`, pola widoczne publicznie w `profile/show.blade.php:125,149`. Rejestr nie ma planera, wspólnych zeszytów ani wczytania paczki; `planer` pada tylko w liście eksportu (`REJESTR_CZYNNOSCI_PRZETWARZANIA.md:432`) | statyczne: grep w polityce i rejestrze, odczyt tras | Art. 13 ust. 1 lit. c i ust. 2 lit. a RODO: brakuje celu, podstawy i okresu dla danych, które serwis już zbiera. Wspólny zeszyt pokazuje innym, kto do niego należy, i powiadamia zaproszonych. Historia zaproszeń nie ma terminu | Dopisać wiersze do §2 (cel, podstawa, termin) i sekcje do rejestru. Zaproszeniom dać retencję (np. kasować po `expires_at` + 30 dni, gdy status inny niż `accepted`). Strażnik: każda tabela z `InwentarzDanychKonta::KOLUMNY_WSKAZUJACE_NA_KONTO` w trybie `EKSPORT` ma frazę w polityce, a nowa tabela bez frazy zapala test | M | brak |
| Z6 | P2 | Cloudflare jako pośrednik całego ruchu (CDN i proxy) nie ma wiersza w tabeli odbiorców ani w rejestrze, a rejestr twierdzi, że „żadnego nie brakuje” | Polityka wspomina o tym tylko zdaniem pobocznym (`polityka-prywatnosci.md:75`, `:99`). W tabeli `:64-73` są tylko R2, Turnstile i Web Analytics. Rejestr: `REJESTR_CZYNNOSCI_PRZETWARZANIA.md:636-656` („Żaden odbiorca … nie jest martwy i żadnego nie brakuje”). `REJESTR_UMOW_POWIERZENIA.md:116` zna tę rolę, ale nie ma osobnej pozycji DPA | statyczne | Proxy kończy TLS, więc widzi treść każdego żądania: hasła, prywatne wpisy, adresy e-mail. W tabeli przy Cloudflare stoi tylko „adres IP i cechy przeglądarki”. Art. 13 ust. 1 lit. e–f i art. 30 ust. 1 lit. d RODO | Wiersz „Cloudflare — sieć, CDN, ochrona przed atakami (Cloudflare, Inc., USA, DPF + SCC)” w §3 polityki i w §4 rejestru oraz osobna pozycja w rejestrze umów. Rozszerzyć `PolitykaPrywatnosciWymieniaKazdaUslugeTest` o sygnał usługi sieciowej (`KUKING_EDGE_TOKEN`) | S | brak |
| Z7 | P3 | Kanał alarmów Discord działa (D-333), a rejestr i komentarze w kodzie mówią, że go nie ma | `docs/DECISIONS.md:20927` („Discord. Już działa”). `REJESTR_CZYNNOSCI_PRZETWARZANIA.md:486-488`: „jeśli tak, ten kanał jest kolejnym odbiorcą … i musi trafić do polityki i do §4”. Nieaktualne komentarze: `app/Domain/Contact/DzwonekOperatora.php:59`, `app/Domain/Moderation/Actions/AlarmujModeratora.php:52`, `app/Domain/Monitoring/KanalAlarmowy.php:24`, `config/kuking.php:2399` | statyczne | Warunek, który rejestr sam postawił, jest spełniony, a rejestr się nie zmienił. Treść kanału jest filtrowana (`BladTrafiaNaWebhookBezDanychOsobowychTest`), więc ryzyko dla danych jest niskie | Wpisać Discord (Discord Inc., USA) do §3.19 i §4 rejestru jako odbiorcę technicznego bez danych osobowych, pilnowanego testem. Rozstrzygnąć, czy potrzebny jest wiersz w polityce. Poprawić komentarze | S | brak |
| Z8 | P3 | Nieudany list (np. zaproszenie do założenia konta) zostaje w `failed_jobs` razem z adresem przez 30 dni, a polityka obiecuje usunąć adres „najwyżej dobę po wygaśnięciu” | `polityka-prywatnosci.md:51`. `app/Domain/Security/WyslijZaproszenieDoRejestracji.php:230` kolejkuje `ZaproszenieDoZalozeniaKonta` (`ShouldQueue` + `ShouldBeEncrypted`, `app/Notifications/ZaproszenieDoZalozeniaKonta.php:56`). `routes/console.php:342` czyści `failed_jobs` dopiero po 720 h. Ani `kuking:sprzataj-zaproszenia`, ani `EraseAccountData` nie dotykają `failed_jobs` | statyczne; **podejrzenie co do skali**: dotyczy tylko listów, które przepadły po wszystkich próbach | Ładunek zaszyfrowany kluczem aplikacji dalej jest daną osobową. To samo dotyczy linków logowania | Opisać w polityce „ślad nieudanej wysyłki do 30 dni” albo przy sprzątaniu zaproszeń i przy wymazaniu konta kasować wiersz `failed_jobs` po `mail_failures.failed_job_uuid`. Test: nieudane zaproszenie → po sprzątaniu brak ładunku | S | brak |
| Z9 | P3 | Regulamin §2 („Czym jest Kuking”) nie wymienia większości usług | `resources/legal/regulamin.md:33-40` wymienia 7 funkcji. Brakuje: „Poradźcie”, reakcji „Smakowicie wygląda”, wspólnych zeszytów, planera, „Co mam w domu”, „Mój stół”, urodzin, podsumowania tygodnia, logowania Google i Facebook oraz wczytania paczki | statyczne (grep: 0 trafień) | Art. 8 ust. 3 pkt 1 lit. a ustawy o świadczeniu usług drogą elektroniczną wymaga rodzajów i zakresu usług | Dopisać listę do §2 jako zmianę drobną (wzorem D-333) | S | brak |
| Z10 | P3 | Polityka §9 zapowiada e-mail o istotnej zmianie „gdy będziemy już wysyłać wiadomości”. Serwis już wysyła pocztę, a D-306 mówi „bez maili” | `polityka-prywatnosci.md:146`; `app/Domain/Zgody/ZmianaRegulaminu.php:14-15` (tylko pasek, bez maili) | statyczne | Warunek w tekście jest spełniony, a obietnicy nic nie realizuje. Osoba, która się nie loguje, nie dowie się o zmianie | Zmienić zdanie na „komunikatem w serwisie po zalogowaniu” (D-306) albo wysyłać list. Test tekstu w `ZmianaPolitykiTest` | S | brak |
| Z11 | P3 | Logowanie Google prosi o zakres `profile` (imię **i zdjęcie**), a polityka mówi, że prosimy „wyłącznie o trzy rzeczy” | `app/Support/Google.php:66` (`openid email profile`); `polityka-prywatnosci.md:81` | statyczne | Ekran zgody Google mówi „imię i zdjęcie profilowe”, a polityka co innego. Zdjęcia nie zapisujemy, więc rozjazd dotyczy prośby, nie przetwarzania | Dopisać w polityce, że zakres `profile` obejmuje zdjęcie, którego nie zapisujemy | S | brak |

## Sprawdzone i w porządku

- **Eksport (art. 15 i 20):** `app/Domain/Users/Exports/InwentarzDanychKonta.php` klasyfikuje każdą
  kolumnę wskazującą na konto. Porównałem to z kluczami obcymi do `users` w lokalnej bazie
  testowej. Polityka §4 opisuje granice paczki zgodnie z tą klasyfikacją.
- **Wymazanie konta (`EraseAccountData`):** usuwa relacje, ukrycia, reakcje, planer, „Co mam
  w domu”, postęp gotowania, 2FA, zmianę adresu, tożsamości Google i Facebook, Web Push,
  importy, paczki, profil razem z formą zwracania się, urodziny, `ostatnio_widziany_at`,
  sesje i resety haseł. Zgody zamyka wpisem `wycofana`. Tokeny API i linki logowania znikają
  już przy zgłoszeniu usunięcia (`app/Models/User.php:1757,1767`). Jedyny wyjątek to Z4.
- **OpenAI (moderacja):** wychodzi wyłącznie treść publiczna
  (`app/Moderacja/GranicaWysylki.php:57`, `OcenaModelem.php:114`), a zdjęcie ma najwyżej
  320 px. W żądaniu nie ma pola `user`. Import AI wyłączają domyślne flagi i pusty klucz,
  a rejestr §3.23 opisuje go już teraz.
- **EmailLabs:** żądanie niesie tylko nadawcę, adresatów, temat, treść i nagłówki
  (`app/Poczta/TransportEmailLabs.php:191-227`).
- **Turnstile:** lista 7 formularzy zgadza się z widokami i kontrolerami.
- **Web Push i API mobilne:** są wyłączone bez kluczy i flagi
  (`config/kuking.php:1082-1098`, `:4055`). Włączenie wymaga najpierw zmiany polityki
  (`DEPLOYMENT_RUNBOOK.md`, krok 8G; K9).
- **DSA art. 17:** `app/Domain/Moderation/UzasadnienieDecyzji.php` podaje podstawę, informację
  o automacie, odwołanie, drogę pozasądową i sąd. Karencja 24 h jest w `ResolveAppeal.php:34`.
  Raport przejrzystości nie jest obowiązkiem mikroprzedsiębiorstwa i nie jest obiecany.
- **Kanał błędów:** treść powstaje wyłącznie z listy dozwolonych pól
  (`app/Logging/WebhookBleduHandler.php`, `DzwonekOperatora::tresc()`).
- **Adresy IP:** sesje trzymają adres zgrubny (`app/Support/MaskaAdresuIp.php`), dziennik audytu
  skrót HMAC, a klucze limitera są hashowane (`app/Support/KluczeLimitow.php`).
- **Logowanie Facebookiem:** zakres to `public_profile,email` (`app/Support/Facebook.php:53`),
  zgodnie z polityką.
