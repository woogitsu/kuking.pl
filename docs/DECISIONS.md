# Dziennik decyzji

Decyzje, które **zostały podjęte** i których nie należy otwierać na nowo bez
nowej informacji. Każdy agent AI i każda osoba dołączająca do projektu czyta
ten indeks i wpisy, do których prowadzi, żeby nie proponować rzeczy już
rozstrzygniętych.

**Od 25 września 2026 każda decyzja to osobny plik** w
[`docs/decyzje/`](decyzje/), nazwany `D-NNN-krotki-slug.md`. Ten plik jest
indeksem: numer, tytuł, status i odnośnik. Odnośnik „D-NNN w
`docs/DECISIONS.md`” w kodzie i dokumentach dalej działa — numer znajdziesz
w tabeli niżej. Treść decyzji przeniesiono bez zmian; historia sprzed podziału
jest w historii gita tego pliku (`git log -- docs/DECISIONS.md`).

Format: co, kiedy, kto zdecydował, dlaczego, i **co musiałoby się stać**,
żeby decyzję zmienić.

> ### Numeracja przeskakuje D-108 … D-112 — i to jest celowe
>
> System projektowy w `docs/design/system-v3.1/` ma **własny, niezależny
> dziennik** z numerami `D-101 … D-112`. Pięć z nich (D-103 … D-107) zajmuje
> już oba dzienniki naraz i cytat „D-105" znaczy co innego w jednym, a co
> innego w drugim. Numery są darmowe, a odplątywanie takiej dwuznaczności
> po fakcie nie jest — więc dziennik główny przechodzi z **D-107 od razu na
> D-113**, czyli pierwszy numer wolny w obu miejscach.
>
> **Numery 108 – 112 w TYM pliku zostają na zawsze puste.** Nie są luką do
> uzupełnienia; są odstępem od cudzej numeracji. Osobno i wcześniej puste
> są **D-084, D-086 i D-094**.
>
> Decyzje systemu projektowego cytujemy z nazwą jego dziennika
> („system-v3.1 D-111"), nigdy samym numerem.

---

## Jak dodać decyzję

Nowa decyzja trafia tutaj, gdy: zamyka dyskusję, którą ktoś mógłby otworzyć
ponownie, albo gdy odrzuca oczywiste na pierwszy rzut oka rozwiązanie.

Rzeczy, które **nie są** decyzją do zapisania: wybór nazwy zmiennej, kolejność
pól w formularzu, sposób sformułowania jednego komunikatu.

Mechanika — **nowa decyzja to nowy plik, nigdy dopisek w tym indeksie**:

1. Numer: `php scripts/decyzje-indeks.php --nastepny` (największy numer plus
   jeden; puste numery z ramki wyżej zostają puste).
2. Nowy plik `docs/decyzje/D-NNN-krotki-slug.md` — slug to kilka słów tytułu,
   małe litery bez polskich znaków, cyfry i myślniki. Pierwszy wiersz:
   `## D-NNN · Tytuł decyzji`, podsekcje jako `### `. Format treści jak we
   wpisach obok: data, kto zdecydował, `Status: **obowiązuje**`, dlaczego,
   co musiałoby się stać, żeby decyzję zmienić, linia `📄` z plikami.
3. Odśwież tabelę: `php scripts/decyzje-indeks.php` — i zacommituj oba pliki.
   Tabeli nie edytuj ręcznie; po konflikcie w niej weź dowolną stronę
   i uruchom skrypt jeszcze raz.
4. Sprawdzenie: `php scripts/decyzje-indeks.php --sprawdz`. W CI to samo
   pilnuje `DziennikDecyzjiZgodnyZIndeksemTest` (unikalne numery, numer
   w nazwie = numer w nagłówku, indeks zgodny z plikami, brak treści decyzji
   w tym pliku).

Dwa PR-y z tym samym numerem nie dają konfliktu w gicie (różne slugi), tylko
czerwony test po scaleniu drugiego: młodszy bierze następny wolny numer.
Gałąź, która dopisała decyzję do starego, jednoplikowego dziennika, przenosi
ją według [`docs/flota/PRZENIESIENIE_PO_PODZIALE.md`](flota/PRZENIESIENIE_PO_PODZIALE.md).

## Indeks

<!-- indeks-decyzji:poczatek — generuje php scripts/decyzje-indeks.php, nie edytuj ręcznie -->

| Numer | Decyzja | Status | Plik |
|---|---|---|---|
| D-001 | Modularny monolit Laravel, bez mikroserwisów | obowiązuje | [D-001-modularny-monolit-laravel-bez-mikroserwisow.md](decyzje/D-001-modularny-monolit-laravel-bez-mikroserwisow.md) |
| D-002 | Testy na PostgreSQL, nigdy na SQLite | obowiązuje | [D-002-testy-na-postgresql-nigdy-na-sqlite.md](decyzje/D-002-testy-na-postgresql-nigdy-na-sqlite.md) |
| D-003 | Własny model `media` zamiast Spatie MediaLibrary | obowiązuje | [D-003-wlasny-model-media-zamiast-spatie-medialibrary.md](decyzje/D-003-wlasny-model-media-zamiast-spatie-medialibrary.md) |
| D-004 | Wyszukiwarka na PostgreSQL, bez Scout i bez osobnego silnika | obowiązuje | [D-004-wyszukiwarka-na-postgresql-bez-scout-i.md](decyzje/D-004-wyszukiwarka-na-postgresql-bez-scout-i.md) |
| D-005 | Brak `UNIQUE (user_id, recipe_id)` w `cooked_events` | obowiązuje, nienaruszalne | [D-005-brak-unique-w-cooked-events.md](decyzje/D-005-brak-unique-w-cooked-events.md) |
| D-006 | `status` i `role` użytkownika poza `$fillable` | obowiązuje | [D-006-status-i-role-uzytkownika-poza-fillable.md](decyzje/D-006-status-i-role-uzytkownika-poza-fillable.md) |
| D-007 | Ważne funkcje działają bez JavaScriptu | ZMIENIONE PRZEZ D-053 (9 września 2026) | [D-007-wazne-funkcje-dzialaja-bez-javascriptu.md](decyzje/D-007-wazne-funkcje-dzialaja-bez-javascriptu.md) |
| D-008 | `kuKING` to nazwa mieszkańca, nie komplement | obowiązuje | [D-008-kuking-to-nazwa-mieszkanca-nie-komplement.md](decyzje/D-008-kuking-to-nazwa-mieszkanca-nie-komplement.md) |
| D-009 | Dawka gry słowem: umiarkowana | zmienione przez D-145 (limit „raz na ekran") i D-147 (odrzucenie czasownika). Lista miejsc zakazanych zostaje w mocy | [D-009-dawka-gry-slowem-umiarkowana.md](decyzje/D-009-dawka-gry-slowem-umiarkowana.md) |
| D-010 | CI na runnerach GitHuba, repozytorium w nowej organizacji | zmienione przez D-028 | [D-010-ci-na-runnerach-githuba-repozytorium-w.md](decyzje/D-010-ci-na-runnerach-githuba-repozytorium-w.md) |
| D-011 | Deploy odłożony, praca idzie w kodzie | NIEAKTUALNE — serwis JEST na produkcji (zmierzone 7 września 2026) | [D-011-deploy-odlozony-praca-idzie-w-kodzie.md](decyzje/D-011-deploy-odlozony-praca-idzie-w-kodzie.md) |
| D-012 | Tryb zamkniętej alfy, bez publicznej bety | obowiązuje do odwołania | [D-012-tryb-zamknietej-alfy-bez-publicznej-bety.md](decyzje/D-012-tryb-zamknietej-alfy-bez-publicznej-bety.md) |
| D-013 | „kuKINGi na dziś" zostaje, z weryfikacją w testach | obowiązuje warunkowo | [D-013-kukingi-na-dzis-zostaje-z-weryfikacja.md](decyzje/D-013-kukingi-na-dzis-zostaje-z-weryfikacja.md) |
| D-014 | Nie budujemy API „pod przyszłą aplikację mobilną" | do decyzji właściciela | [D-014-nie-budujemy-api-pod-przyszla-aplikacje.md](decyzje/D-014-nie-budujemy-api-pod-przyszla-aplikacje.md) |
| D-015 | Logotyp brzmi „KuKing.pl", teksty dalej piszą „Kuking" | zmienione przez D-145 — tekst ciągły pisze dziś kuKING dwukolorowo, a akcent koloru w samym logotypie leży wyłącznie na „King" (PR #394). Rozróżnienie logotypu od zapisu w zdaniu zostaje w mocy | [D-015-logotyp-brzmi-kuking-pl-teksty-dalej.md](decyzje/D-015-logotyp-brzmi-kuking-pl-teksty-dalej.md) |
| D-016 | Odwołanie składa się w produkcie, formularzem zamkniętym hasłem | obowiązuje | [D-016-odwolanie-sklada-sie-w-produkcie-formularzem.md](decyzje/D-016-odwolanie-sklada-sie-w-produkcie-formularzem.md) |
| D-017 | Przepis zostaje wolnym tekstem; to kit dopasowuje się do danych | częściowo nieaktualna — patrz D-033 | [D-017-przepis-zostaje-wolnym-tekstem-to-kit.md](decyzje/D-017-przepis-zostaje-wolnym-tekstem-to-kit.md) |
| D-018 | Usunięcie konta kasuje wszystkie zdjęcia, tekst zostaje zanonimizowany | obowiązuje | [D-018-usuniecie-konta-kasuje-wszystkie-zdjecia-tekst.md](decyzje/D-018-usuniecie-konta-kasuje-wszystkie-zdjecia-tekst.md) |
| D-019 | Jasny motyw zawsze domyślny; ciemny wyłącznie na jawne życzenie | obowiązuje | [D-019-jasny-motyw-zawsze-domyslny-ciemny-wylacznie.md](decyzje/D-019-jasny-motyw-zawsze-domyslny-ciemny-wylacznie.md) |
| D-020 | Adresem zdjęcia jest trasa aplikacji, a bucket wariantów traci domenę | obowiązuje · | [D-020-adresem-zdjecia-jest-trasa-aplikacji-a.md](decyzje/D-020-adresem-zdjecia-jest-trasa-aplikacji-a.md) |
| D-021 | Tematy znikają, zostają same tagi | obowiązuje · | [D-021-tematy-znikaja-zostaja-same-tagi.md](decyzje/D-021-tematy-znikaja-zostaja-same-tagi.md) |
| D-022 | Zakres usunięcia konta wybiera człowiek; domyślnie tekst zostaje | obowiązuje | [D-022-zakres-usuniecia-konta-wybiera-czlowiek-domyslnie.md](decyzje/D-022-zakres-usuniecia-konta-wybiera-czlowiek-domyslnie.md) |
| D-023 | Oryginał zdjęcia traci współrzędne GPS przy wgraniu | obowiązuje | [D-023-oryginal-zdjecia-traci-wspolrzedne-gps-przy.md](decyzje/D-023-oryginal-zdjecia-traci-wspolrzedne-gps-przy.md) |
| D-024 | Dokumenty prawne idą na produkcję poprawione, a nieprawda z nich wypada od razu | obowiązuje | [D-024-dokumenty-prawne-ida-na-produkcje-poprawione.md](decyzje/D-024-dokumenty-prawne-ida-na-produkcje-poprawione.md) |
| D-025 | Treść zaląźkowa wchodzi na produkcję, ale jawnie oznaczona | obowiązuje; wygląd plakietki odwrócony | [D-025-tresc-zalazkowa-wchodzi-na-produkcje-ale.md](decyzje/D-025-tresc-zalazkowa-wchodzi-na-produkcje-ale.md) |
| D-026 | Baza tagów pochodzi ze słownika w pliku, a stare nazwy są scalane, nie dublowane | obowiązuje | [D-026-baza-tagow-pochodzi-ze-slownika-w.md](decyzje/D-026-baza-tagow-pochodzi-ze-slownika-w.md) |
| D-027 | Jedno wysłanie formularza to jeden zapis — klucz wysłania, nie okno czasowe | obowiązuje | [D-027-jedno-wyslanie-formularza-to-jeden-zapis.md](decyzje/D-027-jedno-wyslanie-formularza-to-jeden-zapis.md) |
| D-028 | CI wraca na własne runnery — wybierane etykietami, nie nazwą | obowiązuje | [D-028-ci-wraca-na-wlasne-runnery-wybierane.md](decyzje/D-028-ci-wraca-na-wlasne-runnery-wybierane.md) |
| D-029 | Numer sprawy ma własną kolumnę z UNIQUE, nie jest wycinkiem UUID-a | obowiązuje | [D-029-numer-sprawy-ma-wlasna-kolumne-z.md](decyzje/D-029-numer-sprawy-ma-wlasna-kolumne-z.md) |
| D-030 | Wpis nie dostaje pola „tytuł" — tytuł należy do przepisu | obowiązuje | [D-030-wpis-nie-dostaje-pola-tytul-tytul.md](decyzje/D-030-wpis-nie-dostaje-pola-tytul-tytul.md) |
| D-031 | Zeszyt przyjmuje wpisy, nie tylko przepisy | obowiązuje | [D-031-zeszyt-przyjmuje-wpisy-nie-tylko-przepisy.md](decyzje/D-031-zeszyt-przyjmuje-wpisy-nie-tylko-przepisy.md) |
| D-032 | Plakietka „konto przykładowe" jest krótka i cicha; głośna wolno raz na ekran | obowiązuje | [D-032-plakietka-konto-przykladowe-jest-krotka-i.md](decyzje/D-032-plakietka-konto-przykladowe-jest-krotka-i.md) |
| D-033 | Składniki dostają grupy, a przepis przeliczanie porcji | przyjęta | [D-033-skladniki-dostaja-grupy-a-przepis-przeliczanie.md](decyzje/D-033-skladniki-dostaja-grupy-a-przepis-przeliczanie.md) |
| D-034 | Kreator przepisu dostaje trzy adresy, po jednym na krok | przyjęta | [D-034-kreator-przepisu-dostaje-trzy-adresy-po.md](decyzje/D-034-kreator-przepisu-dostaje-trzy-adresy-po.md) |
| D-035 | Natywne pole wyboru pliku znika za własnym obszarem | przyjęta | [D-035-natywne-pole-wyboru-pliku-znika-za.md](decyzje/D-035-natywne-pole-wyboru-pliku-znika-za.md) |
| D-036 | Zapisanie do Zeszytu nazywa się „Zapisuję" | obowiązuje | [D-036-zapisanie-do-zeszytu-nazywa-sie-zapisuje.md](decyzje/D-036-zapisanie-do-zeszytu-nazywa-sie-zapisuje.md) |
| D-037 | Gospodarzem, który podpisuje wiadomości, jest Ula | obowiązuje | [D-037-gospodarzem-ktory-podpisuje-wiadomosci-jest-ula.md](decyzje/D-037-gospodarzem-ktory-podpisuje-wiadomosci-jest-ula.md) |
| D-038 | Gdy dokument i kod mówią co innego, poprawiamy to, co jest nieprawdą | obowiązuje | [D-038-gdy-dokument-i-kod-mowia-co.md](decyzje/D-038-gdy-dokument-i-kod-mowia-co.md) |
| D-039 | Odwołanie zamyka administrator, nie rola pierwszej linii | obowiązuje | [D-039-odwolanie-zamyka-administrator-nie-rola-pierwszej.md](decyzje/D-039-odwolanie-zamyka-administrator-nie-rola-pierwszej.md) |
| D-040 | Kuking prowadzi spółka SAMSUFI, nie osoba fizyczna | obowiązuje | [D-040-kuking-prowadzi-spolka-samsufi-nie-osoba.md](decyzje/D-040-kuking-prowadzi-spolka-samsufi-nie-osoba.md) |
| D-041 | Błędy 500 dziś idą webhookiem na Slack/Discord, nie Sentry | obowiązuje do czasu, aż composer | [D-041-bledy-500-dzis-ida-webhookiem-na.md](decyzje/D-041-bledy-500-dzis-ida-webhookiem-na.md) |
| D-042 | Zgłaszający ze zwykłego formularza dostaje pouczenie, nie formularz skargi | obowiązuje | [D-042-zglaszajacy-ze-zwyklego-formularza-dostaje.md](decyzje/D-042-zglaszajacy-ze-zwyklego-formularza-dostaje.md) |
| D-043 | Kopia poza Railwayem robi osobny serwis Railway, nie scheduler aplikacji | obowiązuje | [D-043-kopia-poza-railwayem-robi-osobny-serwis.md](decyzje/D-043-kopia-poza-railwayem-robi-osobny-serwis.md) |
| D-044 | „Podziel się": arkusz systemowy nad jawną listą, bez Messengera w wersji podstawowej | obowiązuje | [D-044-podziel-sie-arkusz-systemowy-nad-jawna.md](decyzje/D-044-podziel-sie-arkusz-systemowy-nad-jawna.md) |
| D-045 | „Napisz do nas" to strona pod własnym adresem, nie dymek w rogu | obowiązuje | [D-045-napisz-do-nas-to-strona-pod.md](decyzje/D-045-napisz-do-nas-to-strona-pod.md) |
| D-046 | Wyszukiwarka pyta operatorem `<%` (`word_similarity`) z progiem 0,5, nie `%` z 0,12 | obowiązuje | [D-046-wyszukiwarka-pyta-operatorem-z-progiem-0.md](decyzje/D-046-wyszukiwarka-pyta-operatorem-z-progiem-0.md) |
| D-047 | Pocztę wysyłamy przez API HTTPS EmailLabs, własnym transportem Symfony | obowiązuje | [D-047-poczte-wysylamy-przez-api-https-emaillabs.md](decyzje/D-047-poczte-wysylamy-przez-api-https-emaillabs.md) |
| D-048 | Nowy adres e-mail obowiązuje po kliknięciu w link, a zajętość adresu rozstrzyga się dopiero tam | obowiązuje | [D-048-nowy-adres-e-mail-obowiazuje-po.md](decyzje/D-048-nowy-adres-e-mail-obowiazuje-po.md) |
| D-049 | Zrzut szyfrujemy KLUCZEM PUBLICZNYM, a podpis do R2 liczymy sami w powłoce | obowiązuje | [D-049-zrzut-szyfrujemy-kluczem-publicznym-a-podpis.md](decyzje/D-049-zrzut-szyfrujemy-kluczem-publicznym-a-podpis.md) |
| D-050 | Cloudflare Turnstile na sześciu formularzach publicznych — warunek wysłania, nie filtr. Brak tokenu odrzuca | obowiązuje | [D-050-cloudflare-turnstile-na-szesciu-formularzach.md](decyzje/D-050-cloudflare-turnstile-na-szesciu-formularzach.md) |
| D-051 | Stopka: metryczka wersji 8 px i przełącznik motywu bez widocznego napisu — świadomy wyjątek od AGENTS.md §5 | obowiązuje | [D-051-stopka-metryczka-wersji-8-px-i.md](decyzje/D-051-stopka-metryczka-wersji-8-px-i.md) |
| D-052 | Automat oznacza podejrzane treści do przeglądu — trzecie źródło w `reports`, nigdy konsekwencja dla autora | obowiązuje | [D-052-automat-oznacza-podejrzane-tresci-do-przegladu.md](decyzje/D-052-automat-oznacza-podejrzane-tresci-do-przegladu.md) |
| D-053 | JavaScript jest wymagany na formularzach chronionych captchą, a nigdzie nie wolno zostawić martwego przycisku | obowiązuje | [D-053-javascript-jest-wymagany-na-formularzach.md](decyzje/D-053-javascript-jest-wymagany-na-formularzach.md) |
| D-054 | Zdjęcie profilowe ma własny, krótki ekran `/ustawienia/zdjecie` — pole zostało z formularza profilu PRZENIESIONE, nie skopiowane | obowiązuje | [D-054-zdjecie-profilowe-ma-wlasny-krotki-ekran.md](decyzje/D-054-zdjecie-profilowe-ma-wlasny-krotki-ekran.md) |
| D-055 | Druga para oczu to model OpenAI, który podnosi rękę — nigdy nie zamyka drzwi | obowiązuje | [D-055-druga-para-oczu-to-model-openai.md](decyzje/D-055-druga-para-oczu-to-model-openai.md) |
| D-056 | Logowanie linkiem e-mail: link prowadzi na ekran z przyciskiem, ważny 30 minut, hasło zostaje drogą równoległą | obowiązuje | [D-056-logowanie-linkiem-e-mail-link-prowadzi.md](decyzje/D-056-logowanie-linkiem-e-mail-link-prowadzi.md) |
| D-057 | Tygodniowe podsumowanie: dobowy sufit 60 listów, wysyłka rozłożona na dni, wypisanie bez logowania | obowiązuje | [D-057-tygodniowe-podsumowanie-dobowy-sufit-60-listow.md](decyzje/D-057-tygodniowe-podsumowanie-dobowy-sufit-60-listow.md) |
| D-058 | Na wiadomość z „Napisz do nas" odpisuje się Z PANELU, synchronicznie, ze stanem wysyłki przy każdym liście | obowiązuje | [D-058-na-wiadomosc-z-napisz-do-nas.md](decyzje/D-058-na-wiadomosc-z-napisz-do-nas.md) |
| D-059 | Newslettera redakcyjnego nie budujemy — tygodniowe podsumowanie (D-057) jest odpowiedzią na to pytanie | obowiązuje | [D-059-newslettera-redakcyjnego-nie-budujemy-tygodniowe.md](decyzje/D-059-newslettera-redakcyjnego-nie-budujemy-tygodniowe.md) |
| D-060 | Kolejka z terminem sama się zgłasza: powiadomienie dla administratora i liczniki przy pozycjach panelu liczone poza żądaniem | obowiązuje | [D-060-kolejka-z-terminem-sama-sie-zglasza.md](decyzje/D-060-kolejka-z-terminem-sama-sie-zglasza.md) |
| D-061 | Zdjęcie profilowe przechodzi przez model, a celem oznaczenia jest PLIK, nie konto | obowiązuje | [D-061-zdjecie-profilowe-przechodzi-przez-model-a.md](decyzje/D-061-zdjecie-profilowe-przechodzi-przez-model-a.md) |
| D-062 | List, który nie wyszedł, zostawia ślad w bazie i zapala `/health` — a alarmu pocztą o awarii poczty nie wysyłamy | obowiązuje | [D-062-list-ktory-nie-wyszedl-zostawia-slad.md](decyzje/D-062-list-ktory-nie-wyszedl-zostawia-slad.md) |
| D-063 | PostHog: nie teraz — statystyki zostają własne, w naszej bazie | obowiązuje do spełnienia warunków powrotu niżej | [D-063-posthog-nie-teraz-statystyki-zostaja-wlasne.md](decyzje/D-063-posthog-nie-teraz-statystyki-zostaja-wlasne.md) |
| D-064 | HEIC zostaje odrzucany, z komunikatem mówiącym co zrobić — libheif do obrazu Dockera NIE wchodzi teraz | obowiązuje | [D-064-heic-zostaje-odrzucany-z-komunikatem-mowiacym.md](decyzje/D-064-heic-zostaje-odrzucany-z-komunikatem-mowiacym.md) |
| D-065 | Trzy pakiety zostają na później albo na nie: role w kolumnie, flagi w `.env`, audyt własny (issue #21) | obowiązuje | [D-065-trzy-pakiety-zostaja-na-pozniej-albo.md](decyzje/D-065-trzy-pakiety-zostaja-na-pozniej-albo.md) |
| D-069 | Wejście kontem Google: własny kod, `email_verified` jako warunek, konta łączymy tylko za zgodą człowieka, bez Turnstile | obowiązuje | [D-069-wejscie-kontem-google-wlasny-kod-email.md](decyzje/D-069-wejscie-kontem-google-wlasny-kod-email.md) |
| D-071 | Granica zaufania do nagłówka `Host` jest zamknięta z dwóch stron: `X-Forwarded-Host` wypada z zaufanych nagłówków, a `Host` przechodzi przez `TrustHosts` | — | [D-071-granica-zaufania-do-naglowka-host-jest.md](decyzje/D-071-granica-zaufania-do-naglowka-host-jest.md) |
| D-072 | Zgoda na tygodniowy digest ma dziennik append-only `dziennik_zgod`; rollback migracji zgody nie przywraca `DEFAULT true` | obowiązuje | [D-072-zgoda-na-tygodniowy-digest-ma-dziennik.md](decyzje/D-072-zgoda-na-tygodniowy-digest-ma-dziennik.md) |
| D-075 | Wymiana tokenu logowania linkiem idzie pod blokadą wiersza konta — a konflikt unikalności kończy się tą samą neutralną odpowiedzią co adres bez konta | obowiązuje | [D-075-wymiana-tokenu-logowania-linkiem-idzie-pod.md](decyzje/D-075-wymiana-tokenu-logowania-linkiem-idzie-pod.md) |
| D-076 | Dobowy budżet listów jest twardym sufitem: jedna atomowa rezerwacja pod blokadą `Cache::lock()`, bez nowej tabeli | obowiązuje | [D-076-dobowy-budzet-listow-jest-twardym-sufitem.md](decyzje/D-076-dobowy-budzet-listow-jest-twardym-sufitem.md) |
| D-077 | Tygodniowe podsumowanie ma trwały klucz idempotencji w bazie: rezerwacja `(osoba, tydzień)` PRZED wysłaniem, a przy awarii wolimy pominięcie niż duplikat | obowiązuje | [D-077-tygodniowe-podsumowanie-ma-trwaly-klucz.md](decyzje/D-077-tygodniowe-podsumowanie-ma-trwaly-klucz.md) |
| D-078 | Sygnał digestu mówi „zakolejkowano", a „jeden aktywny eksport na konto" pilnuje baza, nie `exists()` | obowiązuje | [D-078-sygnal-digestu-mowi-zakolejkowano-a-jeden.md](decyzje/D-078-sygnal-digestu-mowi-zakolejkowano-a-jeden.md) |
| D-079 | Operacje na jednej rzeczy tego samego konta idą przez JEDNĄ kolejność blokad, a pod blokadą sprawdzamy stan jeszcze raz | obowiązuje | [D-079-operacje-na-jednej-rzeczy-tego-samego.md](decyzje/D-079-operacje-na-jednej-rzeczy-tego-samego.md) |
| D-080 | Blokada i obserwowanie nie mogą współistnieć: jedna kolejność blokad na PARZE osób, rewalidacja pod blokadą i twarda bariera w bazie | obowiązuje | [D-080-blokada-i-obserwowanie-nie-moga-wspolistniec.md](decyzje/D-080-blokada-i-obserwowanie-nie-moga-wspolistniec.md) |
| D-081 | Pod wpisem widać, ile OSÓB zapisało go do zeszytu — autor od pierwszej, obcy od trzeciej; liczba, nie imiona; nigdzie sortowania | obowiązuje | [D-081-pod-wpisem-widac-ile-osob-zapisalo.md](decyzje/D-081-pod-wpisem-widac-ile-osob-zapisalo.md) |
| D-082 | Dolna belka zostaje `position: fixed`, a rezerwa miejsca pod nią jest LICZONA — 2.4.11 nie kupujemy kosztem 2.5.8 | obowiązuje | [D-082-dolna-belka-zostaje-position-fixed-a.md](decyzje/D-082-dolna-belka-zostaje-position-fixed-a.md) |
| D-083 | Zdjęcie przypina się i kasuje pod JEDNĄ blokadą wiersza `media`, a pliki znikają dopiero PO commicie — wiersz ze znacznikiem `deleted` jest uchwytem do ponowienia | — | [D-083-zdjecie-przypina-sie-i-kasuje-pod.md](decyzje/D-083-zdjecie-przypina-sie-i-kasuje-pod.md) |
| D-085 | Adres bez konta dostaje zaproszenie do rejestracji, a nie ciszę — z adresem potwierdzonym klikiem i jednorazowością liczoną na utworzeniu konta | obowiązuje | [D-085-adres-bez-konta-dostaje-zaproszenie-do.md](decyzje/D-085-adres-bez-konta-dostaje-zaproszenie-do.md) |
| D-087 | Spis wszystkich tematów (`tags.index`) — dwie sekcje, zero rankingu | obowiązuje | [D-087-spis-wszystkich-tematow-dwie-sekcje-zero.md](decyzje/D-087-spis-wszystkich-tematow-dwie-sekcje-zero.md) |
| D-088 | Rollback migracji ODMAWIA, zamiast po cichu zamienić „usuń wszystko" na „usuń minimum" (MIG-01, #287) | obowiązuje | [D-088-rollback-migracji-odmawia-zamiast-po-cichu.md](decyzje/D-088-rollback-migracji-odmawia-zamiast-po-cichu.md) |
| D-089 | Panel moderacji na szerokim ekranie: bez zarezerwowanej pustej szyny, a z dwóch „Wróć do Kuking" zostaje jedno — to które jest `position: fixed` | obowiązuje | [D-089-panel-moderacji-na-szerokim-ekranie-bez.md](decyzje/D-089-panel-moderacji-na-szerokim-ekranie-bez.md) |
| D-090 | `BlockUser` wchodzi przez `ZamekPary` — dokończenie D-080, bo dwie strony tej samej pary brały wiersze `users` w przeciwnych kolejnościach | obowiązuje | [D-090-blockuser-wchodzi-przez-zamekpary-dokonczenie-d.md](decyzje/D-090-blockuser-wchodzi-przez-zamekpary-dokonczenie-d.md) |
| D-091 | Liczby o osobie idą do prawej szyny na szerokim ekranie, a na wąskim zostają w karcie — dwa egzemplarze w HTML, jeden na ekranie, bez JavaScriptu | — | [D-091-liczby-o-osobie-ida-do-prawej.md](decyzje/D-091-liczby-o-osobie-ida-do-prawej.md) |
| D-092 | Analityka odwiedzin to Cloudflare Web Analytics — bo cena, a przy okazji żaden nowy dostawca i żaden nowy przepływ danych | — | [D-092-analityka-odwiedzin-to-cloudflare-web-analytics.md](decyzje/D-092-analityka-odwiedzin-to-cloudflare-web-analytics.md) |
| D-093 | Kasowanie konta bierze wiersze `follows` i `blocks` po jednym, w kolejności ustalonej PRZEZ DANE — `ZamekPary` się tu nie da i to jest zmierzone | obowiązuje | [D-093-kasowanie-konta-bierze-wiersze-follows-i.md](decyzje/D-093-kasowanie-konta-bierze-wiersze-follows-i.md) |
| D-098 | Powiązania z dostawcami tożsamości mieszkają w tabeli `tozsamosci_zewnetrzne`, a nie w kolumnach na `users` — bo drugi dostawca jest zamówiony, nie wyobrażony | obowiązuje | [D-098-powiazania-z-dostawcami-tozsamosci-mieszkaja-w.md](decyzje/D-098-powiazania-z-dostawcami-tozsamosci-mieszkaja-w.md) |
| D-099 | Automat dostępności mierzy stronę W TYM STANIE, W KTÓRYM WYDAJE JĄ PRODUKT — czerwone 2.4.11 na `main` było usterką POMIARU, nie belki | obowiązuje | [D-099-automat-dostepnosci-mierzy-strone-w-tym.md](decyzje/D-099-automat-dostepnosci-mierzy-strone-w-tym.md) |
| D-103 | Cztery ostatnie drogi zdjęcia idą pod tę samą blokadę co wpis — a awatar dostaje osobne rozwiązanie na ODPINANIE | — | [D-103-cztery-ostatnie-drogi-zdjecia-ida-pod.md](decyzje/D-103-cztery-ostatnie-drogi-zdjecia-ida-pod.md) |
| D-104 | Tabela stacku w `AGENTS.md` §3 opisuje STAN, ma trzecią kolumnę „Gdzie to sprawdzić" i jest sprawdzana testem | obowiązuje | [D-104-tabela-stacku-w-agents-md-3.md](decyzje/D-104-tabela-stacku-w-agents-md-3.md) |
| D-105 | Grupa testów `dwa-polaczenia`: osobna baza, osobne procesy, osobny przebieg — i wyłączona ze zwykłego `php artisan test` | obowiązuje | [D-105-grupa-testow-dwa-polaczenia-osobna-baza.md](decyzje/D-105-grupa-testow-dwa-polaczenia-osobna-baza.md) |
| D-106 | Automat dostępności stawia serwer z DZIAŁAJĄCĄ pocztą — bo dwa ekrany odzyskania dostępu mają w stanie zapasowym nagłówek i dwa przyciski zamiast formularza | obowiązuje | [D-106-automat-dostepnosci-stawia-serwer-z-dzialajaca.md](decyzje/D-106-automat-dostepnosci-stawia-serwer-z-dzialajaca.md) |
| D-107 | Minima dolnej belki są FIZYCZNE, nie typograficzne — przy czcionce przeglądarki 200% belka układa się poziomo i schodzi z 51% ekranu na 25% | obowiązuje | [D-107-minima-dolnej-belki-sa-fizyczne-nie.md](decyzje/D-107-minima-dolnej-belki-sa-fizyczne-nie.md) |
| D-113 | Adres e-mail z Facebooka nigdy nie wchodzi na istniejące konto — a właściciel tego konta dostaje POWIADOMIENIE, nie klucz | obowiązuje | [D-113-adres-e-mail-z-facebooka-nigdy.md](decyzje/D-113-adres-e-mail-z-facebooka-nigdy.md) |
| D-114 | Obietnica z miarą wymaga pomiaru — inaczej jej nie piszemy | obowiązuje | [D-114-obietnica-z-miara-wymaga-pomiaru-inaczej.md](decyzje/D-114-obietnica-z-miara-wymaga-pomiaru-inaczej.md) |
| D-115 | Skala tekstu schodzi do 70% — bo ustawienie czytelności działające w jedną stronę jest ustawieniem połowicznym | obowiązuje | [D-115-skala-tekstu-schodzi-do-70-bo.md](decyzje/D-115-skala-tekstu-schodzi-do-70-bo.md) |
| D-116 | Poczta na planie Hobby idzie wyłącznie przez API HTTPS — runbookowi nie wolno pokazywać SMTP jako drogi domyślnej | obowiązuje | [D-116-poczta-na-planie-hobby-idzie-wylacznie.md](decyzje/D-116-poczta-na-planie-hobby-idzie-wylacznie.md) |
| D-117 | Układ bucketów R2 rozstrzyga `config/filesystems.php`, nie dokument | obowiązuje | [D-117-uklad-bucketow-r2-rozstrzyga-config-filesystems.md](decyzje/D-117-uklad-bucketow-r2-rozstrzyga-config-filesystems.md) |
| D-118 | Bucket R2 istnieje — ryzyko z #120 jest BIEŻĄCE, nie przyszłe | obowiązuje | [D-118-bucket-r2-istnieje-ryzyko-z-120.md](decyzje/D-118-bucket-r2-istnieje-ryzyko-z-120.md) |
| D-119 | Plik-wskaźnik nie powtarza reguły, tylko odsyła — a punkt bez nazwanego wyjątku jest rozjazdem tej samej wagi co punkt nieprawdziwy | obowiązuje | [D-119-plik-wskaznik-nie-powtarza-reguly-tylko.md](decyzje/D-119-plik-wskaznik-nie-powtarza-reguly-tylko.md) |
| D-120 | Nagłówek workflow opisuje stan faktyczny wyzwalaczy; wyłącznikiem wdrożeń jest bramka na jobie, nie blok `on:` | obowiązuje | [D-120-naglowek-workflow-opisuje-stan-faktyczny.md](decyzje/D-120-naglowek-workflow-opisuje-stan-faktyczny.md) |
| D-121 | Runnera wybiera zmienna repozytorium `CI_RUNS_ON`, a dziś wskazuje starą pulę WSL | obowiązuje | [D-121-runnera-wybiera-zmienna-repozytorium-ci-runs.md](decyzje/D-121-runnera-wybiera-zmienna-repozytorium-ci-runs.md) |
| D-122 | Gość dostaje prawą szynę obok treści, a nie pod nią — bo komentarz mówił, że gość szyny nie ma, i był nieprawdziwy od 7 września | obowiązuje | [D-122-gosc-dostaje-prawa-szyne-obok-tresci.md](decyzje/D-122-gosc-dostaje-prawa-szyne-obok-tresci.md) |
| D-123 | Wybór gospodarza na tablicy jest UZUPEŁNIANY automatem do sufitu, a nie zamyka tablicy na resztę serwisu | obowiązuje | [D-123-wybor-gospodarza-na-tablicy-jest-uzupelniany.md](decyzje/D-123-wybor-gospodarza-na-tablicy-jest-uzupelniany.md) |
| D-124 | Sufit tablicy dnia to sześć pozycji — bo panel przyjmował sześć od początku, a automat stawał na czterech | obowiązuje | [D-124-sufit-tablicy-dnia-to-szesc-pozycji.md](decyzje/D-124-sufit-tablicy-dnia-to-szesc-pozycji.md) |
| D-125 | Klasa `.card` niosła 126 ról naraz — sześć warstw powierzchni zamiast jednej | obowiązuje | [D-125-klasa-card-niosla-126-rol-naraz.md](decyzje/D-125-klasa-card-niosla-126-rol-naraz.md) |
| D-126 | Panel formularza nie pojawia się tam, gdzie w danym stanie ekranu nie ma czego wypełnić | obowiązuje | [D-126-panel-formularza-nie-pojawia-sie-tam.md](decyzje/D-126-panel-formularza-nie-pojawia-sie-tam.md) |
| D-127 | Droga równorzędna nigdy nie schodzi na warstwę wgłębioną | obowiązuje | [D-127-droga-rownorzedna-nigdy-nie-schodzi-na.md](decyzje/D-127-droga-rownorzedna-nigdy-nie-schodzi-na.md) |
| D-128 | Rolę powierzchni nadaje MIEJSCE, a nie obiekt | obowiązuje | [D-128-role-powierzchni-nadaje-miejsce-a-nie.md](decyzje/D-128-role-powierzchni-nadaje-miejsce-a-nie.md) |
| D-129 | Menu poza panelem pokazuje JEDNO wejście do moderacji, a liczba z kolejek się sumuje | obowiązuje | [D-129-menu-poza-panelem-pokazuje-jedno-wejscie.md](decyzje/D-129-menu-poza-panelem-pokazuje-jedno-wejscie.md) |
| D-130 | Długi wpis na karcie skraca się do „Czytaj dalej"; próg patrzy na WIERSZE i na znaki | obowiązuje | [D-130-dlugi-wpis-na-karcie-skraca-sie.md](decyzje/D-130-dlugi-wpis-na-karcie-skraca-sie.md) |
| D-131 | Napis przycisku jest JEDNYM elementem — `inline-flex` rozbija tekst na osobne elementy flex | obowiązuje | [D-131-napis-przycisku-jest-jednym-elementem-inline.md](decyzje/D-131-napis-przycisku-jest-jednym-elementem-inline.md) |
| D-132 | Strażnik `down()` bez testu wołającego ten `down()` nie jest strażnikiem | obowiązuje | [D-132-straznik-down-bez-testu-wolajacego-ten.md](decyzje/D-132-straznik-down-bez-testu-wolajacego-ten.md) |
| D-133 | `$this->fail()` nie stoi wewnątrz `try` w teście łapiącym odmowę | obowiązuje | [D-133-this-fail-nie-stoi-wewnatrz-try.md](decyzje/D-133-this-fail-nie-stoi-wewnatrz-try.md) |
| D-134 | Cyfra wersji rośnie przy każdej widocznej zmianie, a każde podbicie ma wpis w `CHANGELOG.md` | obowiązuje | [D-134-cyfra-wersji-rosnie-przy-kazdej-widocznej.md](decyzje/D-134-cyfra-wersji-rosnie-przy-kazdej-widocznej.md) |
| D-135 | Ekran dodawania przepisu pyta o SZEŚĆ rzeczy, a przepis wolno opublikować bez ani jednego składnika | obowiązuje | [D-135-ekran-dodawania-przepisu-pyta-o-szesc.md](decyzje/D-135-ekran-dodawania-przepisu-pyta-o-szesc.md) |
| D-136 | Składniki i kroki wpisuje się jako TEKST w jednym polu; baza dalej trzyma wiersze | obowiązuje | [D-136-skladniki-i-kroki-wpisuje-sie-jako.md](decyzje/D-136-skladniki-i-kroki-wpisuje-sie-jako.md) |
| D-137 | Każde wejście do dodawania pokazuje OBIE drogi, a nie tę, przez którą się weszło | obowiązuje | [D-137-kazde-wejscie-do-dodawania-pokazuje-obie.md](decyzje/D-137-kazde-wejscie-do-dodawania-pokazuje-obie.md) |
| D-138 | Panel moderacji bierze całą szerokość; reguła 45rem broni CZYTANIA, a nie tabeli | obowiązuje | [D-138-panel-moderacji-bierze-cala-szerokosc-regula.md](decyzje/D-138-panel-moderacji-bierze-cala-szerokosc-regula.md) |
| D-139 | Strona przepisu używa drugiej kolumny, ale NIE przez `<x-slot:rail>` | obowiązuje | [D-139-strona-przepisu-uzywa-drugiej-kolumny-ale.md](decyzje/D-139-strona-przepisu-uzywa-drugiej-kolumny-ale.md) |
| D-140 | Dokumenty prawne nie mówią o sobie, że nie były sprawdzone przez prawnika | obowiązuje | [D-140-dokumenty-prawne-nie-mowia-o-sobie.md](decyzje/D-140-dokumenty-prawne-nie-mowia-o-sobie.md) |
| D-141 | Wyścig o binarkę Composera usuwa własny katalog narzędzi per job, a nie kolejkowanie | obowiązuje | [D-141-wyscig-o-binarke-composera-usuwa-wlasny.md](decyzje/D-141-wyscig-o-binarke-composera-usuwa-wlasny.md) |
| D-142 | Bramka R2 sprawdza z serwera to, co się da; pusta lista publicznych adresów to NIEPRZEJŚCIE, nie zieleń | obowiązuje | [D-142-bramka-r2-sprawdza-z-serwera-to.md](decyzje/D-142-bramka-r2-sprawdza-z-serwera-to.md) |
| D-143 | Odtworzenie kopii jest udane przy DWÓCH warunkach naraz, nie przy jednym | obowiązuje | [D-143-odtworzenie-kopii-jest-udane-przy-dwoch.md](decyzje/D-143-odtworzenie-kopii-jest-udane-przy-dwoch.md) |
| D-144 | Panel moderacji wchodzi do pomiaru dostępności Z DANYMI; pusty stan przechodzi każdy audyt | obowiązuje | [D-144-panel-moderacji-wchodzi-do-pomiaru-dostepnosci.md](decyzje/D-144-panel-moderacji-wchodzi-do-pomiaru-dostepnosci.md) |
| D-145 | Dwukolorowy zapis `kuKING` obowiązuje wszędzie, także jako nazwa serwisu w tekście bieżącym | obowiązuje | [D-145-dwukolorowy-zapis-kuking-obowiazuje-wszedzie-takze.md](decyzje/D-145-dwukolorowy-zapis-kuking-obowiazuje-wszedzie-takze.md) |
| D-146 | Kuking nie ma reklam i nie pobiera opłat za korzystanie — na stałe | obowiązuje | [D-146-kuking-nie-ma-reklam-i-nie.md](decyzje/D-146-kuking-nie-ma-reklam-i-nie.md) |
| D-147 | Czasownik od `kuKING` wolno użyć tylko tam, gdzie obok stoi zdanie, które go tłumaczy | obowiązuje | [D-147-czasownik-od-kuking-wolno-uzyc-tylko.md](decyzje/D-147-czasownik-od-kuking-wolno-uzyc-tylko.md) |
| D-148 | Rejestr tekstów zmienia się z tłumaczącego się na zapraszający — zdań nie wycinamy, przepisujemy | obowiązuje | [D-148-rejestr-tekstow-zmienia-sie-z-tlumaczacego.md](decyzje/D-148-rejestr-tekstow-zmienia-sie-z-tlumaczacego.md) |
| D-149 | Tekst dla człowieka nie uzasadnia własnego brzmienia — na ekranie tak samo jak w dokumencie prawnym | obowiązuje | [D-149-tekst-dla-czlowieka-nie-uzasadnia-wlasnego.md](decyzje/D-149-tekst-dla-czlowieka-nie-uzasadnia-wlasnego.md) |
| D-150 | Dowód z audytu nie jest treścią dokumentu prawnego | obowiązuje | [D-150-dowod-z-audytu-nie-jest-trescia.md](decyzje/D-150-dowod-z-audytu-nie-jest-trescia.md) |
| D-151 | Obietnica o układzie ekranu wymaga pomiaru dokładnie tak samo jak obietnica o czasie | obowiązuje | [D-151-obietnica-o-ukladzie-ekranu-wymaga-pomiaru.md](decyzje/D-151-obietnica-o-ukladzie-ekranu-wymaga-pomiaru.md) |
| D-152 | Powód naszej decyzji nie stoi przy kontrolce, której dotyczy | obowiązuje | [D-152-powod-naszej-decyzji-nie-stoi-przy.md](decyzje/D-152-powod-naszej-decyzji-nie-stoi-przy.md) |
| D-153 | Nie doklejamy przyimka ani słowa niosącego przypadek do cudzego tekstu ani do nazwy konta | obowiązuje | [D-153-nie-doklejamy-przyimka-ani-slowa-niosacego.md](decyzje/D-153-nie-doklejamy-przyimka-ani-slowa-niosacego.md) |
| D-154 | Odstęp między blokami należy do JEDNEJ strony pary — w rytmie artykułu do `margin-top` | obowiązuje | [D-154-odstep-miedzy-blokami-nalezy-do-jednej.md](decyzje/D-154-odstep-miedzy-blokami-nalezy-do-jednej.md) |
| D-155 | `/odkryj` dostaje kolumnę szyny tym samym mechanizmem co strona przepisu | obowiązuje · | [D-155-odkryj-dostaje-kolumne-szyny-tym-samym.md](decyzje/D-155-odkryj-dostaje-kolumne-szyny-tym-samym.md) |
| D-156 | Autor w danych strukturalnych to konto, które treść opublikowało — pochodzenie idzie do `citation` | obowiązuje · | [D-156-autor-w-danych-strukturalnych-to-konto.md](decyzje/D-156-autor-w-danych-strukturalnych-to-konto.md) |
| D-157 | Dokument, który cytuje regułę z kodu, jest sprawdzany testem — a wariant odrzucony zostaje w nim JAWNIE | obowiązuje | [D-157-dokument-ktory-cytuje-regule-z-kodu.md](decyzje/D-157-dokument-ktory-cytuje-regule-z-kodu.md) |
| D-158 | Odstęp pod zdjęciem karty wpisu należy do bloku POD zdjęciem i wisi na sąsiedztwie, nie na klasie | obowiązuje · | [D-158-odstep-pod-zdjeciem-karty-wpisu-nalezy.md](decyzje/D-158-odstep-pod-zdjeciem-karty-wpisu-nalezy.md) |
| D-159 | Jedno pojęcie ma na ekranie JEDNO słowo — i nazwa usunięta z modelu danych musi zejść też z napisów | obowiązuje · | [D-159-jedno-pojecie-ma-na-ekranie-jedno.md](decyzje/D-159-jedno-pojecie-ma-na-ekranie-jedno.md) |
| D-160 | Wcięcie boczne karty wpisu niesie każdy blok osobno, a klasa współdzielona z innym ekranem go nie dostaje | obowiązuje | [D-160-wciecie-boczne-karty-wpisu-niesie-kazdy.md](decyzje/D-160-wciecie-boczne-karty-wpisu-niesie-kazdy.md) |
| D-161 | Adres strony źródłowej idzie do `isBasedOn` — bo tu typ encji jest znany | obowiązuje | [D-161-adres-strony-zrodlowej-idzie-do-isbasedon.md](decyzje/D-161-adres-strony-zrodlowej-idzie-do-isbasedon.md) |
| D-162 | Manifest PWA nie deklaruje orientacji w ogóle, zamiast deklarować „any" | obowiązuje · | [D-162-manifest-pwa-nie-deklaruje-orientacji-w.md](decyzje/D-162-manifest-pwa-nie-deklaruje-orientacji-w.md) |
| D-163 | Dział pytań nazywa się „Poradźcie", a osobnego miejsca na rozmowy nie o gotowaniu nie budujemy | obowiązuje · | [D-163-dzial-pytan-nazywa-sie-poradzcie-a.md](decyzje/D-163-dzial-pytan-nazywa-sie-poradzcie-a.md) |
| D-164 | Asercja dodatnia na tekście ekranu idzie po `<main>`, nie po całym dokumencie | obowiązuje | [D-164-asercja-dodatnia-na-tekscie-ekranu-idzie.md](decyzje/D-164-asercja-dodatnia-na-tekscie-ekranu-idzie.md) |
| D-165 | Komentarz w pliku wykonywalnym jest dokumentem i podlega tej samej regule co dokument | obowiązuje · | [D-165-komentarz-w-pliku-wykonywalnym-jest-dokumentem.md](decyzje/D-165-komentarz-w-pliku-wykonywalnym-jest-dokumentem.md) |
| D-166 | `docs/DATABASE.md` nazywa każdą kolumnę TEKSTOWĄ, a pilnuje tego test | obowiązuje · | [D-166-docs-database-md-nazywa-kazda-kolumne.md](decyzje/D-166-docs-database-md-nazywa-kazda-kolumne.md) |
| D-167 | `/health` mówi, gdy obiecana droga wejścia nie istnieje | obowiązuje · | [D-167-health-mowi-gdy-obiecana-droga-wejscia.md](decyzje/D-167-health-mowi-gdy-obiecana-droga-wejscia.md) |
| D-168 | Wejście do Ustawień z telefonu stoi na ekranie profilu, przy „Wyloguj się" | obowiązuje | [D-168-wejscie-do-ustawien-z-telefonu-stoi.md](decyzje/D-168-wejscie-do-ustawien-z-telefonu-stoi.md) |
| D-169 | Gdy o stanie ekranu decyduje kliknięcie, a nie serwer, warstwę wybiera arkusz | obowiązuje · | [D-169-gdy-o-stanie-ekranu-decyduje-klikniecie.md](decyzje/D-169-gdy-o-stanie-ekranu-decyduje-klikniecie.md) |
| D-170 | Dokumenty w `docs/brand/` podlegają własnym regułom tam, gdzie podają tekst do wklejenia | obowiązuje | [D-170-dokumenty-w-docs-brand-podlegaja-wlasnym.md](decyzje/D-170-dokumenty-w-docs-brand-podlegaja-wlasnym.md) |
| D-171 | Automat dostępności mierzy ekrany wejścia z atrapami kluczy dostawców, ale nie z atrapą dostawcy | obowiązuje | [D-171-automat-dostepnosci-mierzy-ekrany-wejscia-z.md](decyzje/D-171-automat-dostepnosci-mierzy-ekrany-wejscia-z.md) |
| D-172 | Menu „więcej" na karcie wpisu to same trzy kropki — nazwany wyjątek od „ikona nigdy sama" | obowiązuje | [D-172-menu-wiecej-na-karcie-wpisu-to.md](decyzje/D-172-menu-wiecej-na-karcie-wpisu-to.md) |
| D-173 | Data wpisu w strumieniu gubi rok — ale tylko wtedy, gdy wolno | obowiązuje | [D-173-data-wpisu-w-strumieniu-gubi-rok.md](decyzje/D-173-data-wpisu-w-strumieniu-gubi-rok.md) |
| D-174 | `/ustawienia` jest kanoniczną stroną ustawień, a menu konta stoi na `<details>` | obowiązuje | [D-174-ustawienia-jest-kanoniczna-strona-ustawien-a.md](decyzje/D-174-ustawienia-jest-kanoniczna-strona-ustawien-a.md) |
| D-175 | 48 px celu dotknięcia należy się rzeczom, w które da się kliknąć — nie każdemu wierszowi tekstu | obowiązuje | [D-175-48-px-celu-dotkniecia-nalezy-sie.md](decyzje/D-175-48-px-celu-dotkniecia-nalezy-sie.md) |
| D-176 | W teście albo data jest stała i zegar przymrożony, albo obie są względne | obowiązuje | [D-176-w-tescie-albo-data-jest-stala.md](decyzje/D-176-w-tescie-albo-data-jest-stala.md) |
| D-177 | Rząd akcji zawija się dopiero, gdy naprawdę nie ma miejsca — a `.field:first-child` nie trafia w formularzu POST | obowiązuje | [D-177-rzad-akcji-zawija-sie-dopiero-gdy.md](decyzje/D-177-rzad-akcji-zawija-sie-dopiero-gdy.md) |
| D-178 | Zawężenie kolumn musi obejmować klucze obce relacji dociąganych dalej | obowiązuje | [D-178-zawezenie-kolumn-musi-obejmowac-klucze-obce.md](decyzje/D-178-zawezenie-kolumn-musi-obejmowac-klucze-obce.md) |
| D-179 | Powitanie na stronie głównej nie zależy od godziny serwera | obowiązuje | [D-179-powitanie-na-stronie-glownej-nie-zalezy.md](decyzje/D-179-powitanie-na-stronie-glownej-nie-zalezy.md) |
| D-180 | Widoki pokazują wariant, nie status — i nigdy oryginału | obowiązuje | [D-180-widoki-pokazuja-wariant-nie-status-i.md](decyzje/D-180-widoki-pokazuja-wariant-nie-status-i.md) |
| D-181 | Podgląd przed wysłaniem idzie z pamięci przeglądarki, a jego układ mieszka w arkuszu | obowiązuje | [D-181-podglad-przed-wyslaniem-idzie-z-pamieci.md](decyzje/D-181-podglad-przed-wyslaniem-idzie-z-pamieci.md) |
| D-182 | Odstęp pod podpisem należy się podpisowi, nie jego podpowiedzi | obowiązuje | [D-182-odstep-pod-podpisem-nalezy-sie-podpisowi.md](decyzje/D-182-odstep-pod-podpisem-nalezy-sie-podpisowi.md) |
| D-183 | Martwe zadanie z kolejki kasuje się po wygaśnięciu żetonu, nie ponawia | obowiązuje | [D-183-martwe-zadanie-z-kolejki-kasuje-sie.md](decyzje/D-183-martwe-zadanie-z-kolejki-kasuje-sie.md) |
| D-184 | Rezerwa nad przypiętym paskiem jest liczona ze zmierzonej wysokości i ma sufit | obowiązuje | [D-184-rezerwa-nad-przypietym-paskiem-jest-liczona.md](decyzje/D-184-rezerwa-nad-przypietym-paskiem-jest-liczona.md) |
| D-185 | Asercję podejrzaną o atrapę się mierzy, a nie przepisuje | obowiązuje | [D-185-asercje-podejrzana-o-atrape-sie-mierzy.md](decyzje/D-185-asercje-podejrzana-o-atrape-sie-mierzy.md) |
| D-186 | Pomiar zmienia stan DOM-u przed motywem, nie po nim | obowiązuje | [D-186-pomiar-zmienia-stan-dom-u-przed.md](decyzje/D-186-pomiar-zmienia-stan-dom-u-przed.md) |
| D-187 | Cytat numeru decyzji wskazuje tę decyzję, a brakującego numeru się nie wymyśla | obowiązuje | [D-187-cytat-numeru-decyzji-wskazuje-te-decyzje.md](decyzje/D-187-cytat-numeru-decyzji-wskazuje-te-decyzje.md) |
| D-188 | Linia 📄 ma strażnika, a martwy odnośnik znika, zamiast zgadywać cel | obowiązuje | [D-188-linia-ma-straznika-a-martwy-odnosnik.md](decyzje/D-188-linia-ma-straznika-a-martwy-odnosnik.md) |
| D-189 | Trasy w dokumentach sprawdza `Route::getRoutes()`, nie nazwa pliku | obowiązuje | [D-189-trasy-w-dokumentach-sprawdza-route-getroutes.md](decyzje/D-189-trasy-w-dokumentach-sprawdza-route-getroutes.md) |
| D-190 | Próbka pomiaru jest powtarzalna, a powtarzalność nie może zabrać zasięgu | obowiązuje | [D-190-probka-pomiaru-jest-powtarzalna-a-powtarzalnosc.md](decyzje/D-190-probka-pomiaru-jest-powtarzalna-a-powtarzalnosc.md) |
| D-191 | Zdjęcie pionowe obok poziomego: jedna kolumna na wąskim ekranie, kwadrat w karuzeli | obowiązuje | [D-191-zdjecie-pionowe-obok-poziomego-jedna-kolumna.md](decyzje/D-191-zdjecie-pionowe-obok-poziomego-jedna-kolumna.md) |
| D-192 | Próba odtworzenia kopii kończy się liczbami i nie dowodzi, że kopia istnieje | obowiązuje | [D-192-proba-odtworzenia-kopii-konczy-sie-liczbami.md](decyzje/D-192-proba-odtworzenia-kopii-konczy-sie-liczbami.md) |
| D-193 | Obserwowanie tagu nie jest obejściem widoczności przepisu | obowiązuje | [D-193-obserwowanie-tagu-nie-jest-obejsciem-widocznosci.md](decyzje/D-193-obserwowanie-tagu-nie-jest-obejsciem-widocznosci.md) |
| D-194 | „Ugotowałem" jest ważniejsze niż lajk | obowiązuje | [D-194-ugotowalem-jest-wazniejsze-niz-lajk.md](decyzje/D-194-ugotowalem-jest-wazniejsze-niz-lajk.md) |
| D-195 | Trasy z identyfikatorem sprawdzamy żądaniem, również poza wiązaniem modelu | obowiązuje | [D-195-trasy-z-identyfikatorem-sprawdzamy-zadaniem.md](decyzje/D-195-trasy-z-identyfikatorem-sprawdzamy-zadaniem.md) |
| D-196 | Koszt zapytań mierzymy przy rosnącej liczbie rzeczy na ekranie | obowiązuje | [D-196-koszt-zapytan-mierzymy-przy-rosnacej-liczbie.md](decyzje/D-196-koszt-zapytan-mierzymy-przy-rosnacej-liczbie.md) |
| D-197 | Komunikat walidacji mierzymy przez wywołanie błędu | obowiązuje | [D-197-komunikat-walidacji-mierzymy-przez-wywolanie-bledu.md](decyzje/D-197-komunikat-walidacji-mierzymy-przez-wywolanie-bledu.md) |
| D-198 | Brak skryptów sprawdzamy na rzeczywistych drogach użytkownika | obowiązuje | [D-198-brak-skryptow-sprawdzamy-na-rzeczywistych-drogach.md](decyzje/D-198-brak-skryptow-sprawdzamy-na-rzeczywistych-drogach.md) |
| D-199 | Wycofanie migracji nie może wymazać znaczenia ustawienia | obowiązuje | [D-199-wycofanie-migracji-nie-moze-wymazac-znaczenia.md](decyzje/D-199-wycofanie-migracji-nie-moze-wymazac-znaczenia.md) |
| D-200 | Duży tekst dostaje szerokość zamiast mniejszej czcionki | obowiązuje | [D-200-duzy-tekst-dostaje-szerokosc-zamiast-mniejszej.md](decyzje/D-200-duzy-tekst-dostaje-szerokosc-zamiast-mniejszej.md) |
| D-201 | Dokumentację tabel porównujemy ze schematem w obie strony | obowiązuje | [D-201-dokumentacje-tabel-porownujemy-ze-schematem-w.md](decyzje/D-201-dokumentacje-tabel-porownujemy-ze-schematem-w.md) |
| D-202 | Obchód odnośników obejmuje stany i drogi bez wejścia z menu | obowiązuje | [D-202-obchod-odnosnikow-obejmuje-stany-i-drogi.md](decyzje/D-202-obchod-odnosnikow-obejmuje-stany-i-drogi.md) |
| D-203 | Nowy styl korzysta z tokenów i zachowuje czytelność | obowiązuje | [D-203-nowy-styl-korzysta-z-tokenow-i.md](decyzje/D-203-nowy-styl-korzysta-z-tokenow-i.md) |
| D-204 | Zły wybór zeszytu daje widoczny komunikat bez ujawniania własności | obowiązuje | [D-204-zly-wybor-zeszytu-daje-widoczny-komunikat.md](decyzje/D-204-zly-wybor-zeszytu-daje-widoczny-komunikat.md) |
| D-205 | Przyrząd kontroli ma sprawdzać źródło i udowadniać wykrycie regresji | obowiązuje | [D-205-przyrzad-kontroli-ma-sprawdzac-zrodlo-i.md](decyzje/D-205-przyrzad-kontroli-ma-sprawdzac-zrodlo-i.md) |
| D-206 | Pełny port marki obejmuje układ działającej aplikacji | obowiązuje | [D-206-pelny-port-marki-obejmuje-uklad-dzialajacej.md](decyzje/D-206-pelny-port-marki-obejmuje-uklad-dzialajacej.md) |
| D-207 | Kompozycja wizualizacji jest kryterium portu, nie sama paleta | — | [D-207-kompozycja-wizualizacji-jest-kryterium-portu-nie.md](decyzje/D-207-kompozycja-wizualizacji-jest-kryterium-portu-nie.md) |
| D-208 | Publiczne kroki i blok „Ugotowałem” według wskazanej wizualizacji | — | [D-208-publiczne-kroki-i-blok-ugotowalem-wedlug.md](decyzje/D-208-publiczne-kroki-i-blok-ugotowalem-wedlug.md) |
| D-209 | Dostarczony oryginał identyfikacji i audyt jej kompletności | — | [D-209-dostarczony-oryginal-identyfikacji-i-audyt-jej.md](decyzje/D-209-dostarczony-oryginal-identyfikacji-i-audyt-jej.md) |
| D-210 | Kompozycje wejścia, przepisu, profilu i własnych treści | — | [D-210-kompozycje-wejscia-przepisu-profilu-i-wlasnych.md](decyzje/D-210-kompozycje-wejscia-przepisu-profilu-i-wlasnych.md) |
| D-211 | Zeszyty i zapisane przepisy w kompozycji marki | — | [D-211-zeszyty-i-zapisane-przepisy-w-kompozycji.md](decyzje/D-211-zeszyty-i-zapisane-przepisy-w-kompozycji.md) |
| D-212 | Kafle zainteresowań i zwykłe powiadomienia | — | [D-212-kafle-zainteresowan-i-zwykle-powiadomienia.md](decyzje/D-212-kafle-zainteresowan-i-zwykle-powiadomienia.md) |
| D-213 | Polecane tagi przed wyszukiwaniem | — | [D-213-polecane-tagi-przed-wyszukiwaniem.md](decyzje/D-213-polecane-tagi-przed-wyszukiwaniem.md) |
| D-214 | Zdjęcia w pustej szynie cudzego profilu | — | [D-214-zdjecia-w-pustej-szynie-cudzego-profilu.md](decyzje/D-214-zdjecia-w-pustej-szynie-cudzego-profilu.md) |
| D-215 | Publiczna tablica zaczyna się od dużych fotografii | — | [D-215-publiczna-tablica-zaczyna-sie-od-duzych.md](decyzje/D-215-publiczna-tablica-zaczyna-sie-od-duzych.md) |
| D-216 | Niezależna wysokość kolumn świeżych wpisów (15 września 2026) | — | [D-216-niezalezna-wysokosc-kolumn-swiezych-wpisow.md](decyzje/D-216-niezalezna-wysokosc-kolumn-swiezych-wpisow.md) |
| D-217 | Szybki wygląd bez wymogu konta (15 września 2026) | — | [D-217-szybki-wyglad-bez-wymogu-konta.md](decyzje/D-217-szybki-wyglad-bez-wymogu-konta.md) |
| D-218 | Panel moderacji korzysta z aktualnej marki (15 września 2026) | — | [D-218-panel-moderacji-korzysta-z-aktualnej-marki.md](decyzje/D-218-panel-moderacji-korzysta-z-aktualnej-marki.md) |
| D-219 | Mniejszy tekst zagęszcza układ (#589, 15 września 2026) | — | [D-219-mniejszy-tekst-zageszcza-uklad.md](decyzje/D-219-mniejszy-tekst-zageszcza-uklad.md) |
| D-220 | Zwijanie narzędzi panelu na telefonie (#581, 17 września 2026) | — | [D-220-zwijanie-narzedzi-panelu-na-telefonie.md](decyzje/D-220-zwijanie-narzedzi-panelu-na-telefonie.md) |
| D-221 | Poradźcie korzysta z wpisów i wspólnej rozmowy (#372, 18 września 2026) | — | [D-221-poradzcie-korzysta-z-wpisow-i-wspolnej.md](decyzje/D-221-poradzcie-korzysta-z-wpisow-i-wspolnej.md) |
| D-222 | Fotograficzny katalog tagów (#681, 18 września 2026) | — | [D-222-fotograficzny-katalog-tagow.md](decyzje/D-222-fotograficzny-katalog-tagow.md) |
| D-223 | Martwe reguły CSS: strażnik pyta o wynik kaskady, nie o tekst arkusza (20 września 2026) | — | [D-223-martwe-reguly-css-straznik-pyta-o.md](decyzje/D-223-martwe-reguly-css-straznik-pyta-o.md) |
| D-224 | Wpis wychodzi z zeszytu tam, gdzie widać, że w nim jest (audyt L1, 20 września 2026) | — | [D-224-wpis-wychodzi-z-zeszytu-tam-gdzie.md](decyzje/D-224-wpis-wychodzi-z-zeszytu-tam-gdzie.md) |
| D-225 | Godzinny podpis zdjęcia publicznego, bez cache sesji (#597/#610) | — | [D-225-godzinny-podpis-zdjecia-publicznego-bez-cache.md](decyzje/D-225-godzinny-podpis-zdjecia-publicznego-bez-cache.md) |
| D-227 | PostgreSQL 18 jest wymaganiem, nie preferencją | — | [D-227-postgresql-18-jest-wymaganiem-nie-preferencja.md](decyzje/D-227-postgresql-18-jest-wymaganiem-nie-preferencja.md) |
| D-229 | Powiadomienie śledzi treść komentarza (#758, 20 września 2026) | — | [D-229-powiadomienie-sledzi-tresc-komentarza.md](decyzje/D-229-powiadomienie-sledzi-tresc-komentarza.md) |
| D-230 | Złożenie `zeszyty` i `jedna-droga`: pytanie na ekranie globalnym wraca, komunikat mówi prawdę o notatce (#775, D-224, D-231, 21 września 2026) | — | [D-230-zlozenie-zeszyty-i-jedna-droga-pytanie.md](decyzje/D-230-zlozenie-zeszyty-i-jedna-droga-pytanie.md) |
| D-231 | Jedna droga wyjęcia wpisu z zeszytu, a zakres wybiera ekran (#775, #776 + D-224, 20 września 2026) | — | [D-231-jedna-droga-wyjecia-wpisu-z-zeszytu.md](decyzje/D-231-jedna-droga-wyjecia-wpisu-z-zeszytu.md) |
| D-232 | Dopisek przy składniku bez ilości: dwa ekrany, dwa świadomie różne zachowania (#878, #764/#1197, #1222) | — | [D-232-dopisek-przy-skladniku-bez-ilosci-dwa.md](decyzje/D-232-dopisek-przy-skladniku-bez-ilosci-dwa.md) |
| D-233 | Rejestr potwierdzeń RODO tak, automatyczne kasowanie wpisów NIE (#1222 nie dotyczy) | — | [D-233-rejestr-potwierdzen-rodo-tak-automatyczne.md](decyzje/D-233-rejestr-potwierdzen-rodo-tak-automatyczne.md) |
| D-236 | Kolejka moderacji czyta się od rzeczy, która nie może czekać (22 września 2026) | — | [D-236-kolejka-moderacji-czyta-sie-od-rzeczy.md](decyzje/D-236-kolejka-moderacji-czyta-sie-od-rzeczy.md) |
| D-238 | Cofnięcie migracji 2FA ODMAWIA, zamiast po cichu zdjąć drugi składnik (DB-01, 22 września 2026) | obowiązuje | [D-238-cofniecie-migracji-2fa-odmawia-zamiast-po.md](decyzje/D-238-cofniecie-migracji-2fa-odmawia-zamiast-po.md) |
| D-239 | Wspólny licznik całej poczty i kolejność wygaszania (#732, 22 września 2026) | — | [D-239-wspolny-licznik-calej-poczty-i-kolejnosc.md](decyzje/D-239-wspolny-licznik-calej-poczty-i-kolejnosc.md) |
| D-240 | Do OpenAI wychodzi wyłącznie pomniejszona, publiczna treść; awatar nie wychodzi wcale (22 września 2026) | obowiązuje | [D-240-do-openai-wychodzi-wylacznie-pomniejszona.md](decyzje/D-240-do-openai-wychodzi-wylacznie-pomniejszona.md) |
| D-241 | Lokalne wzorce spamu sprawdzają także treść niepubliczną; wysyłka do OpenAI bez zmian (22 września 2026) | obowiązuje | [D-241-lokalne-wzorce-spamu-sprawdzaja-takze-tresc.md](decyzje/D-241-lokalne-wzorce-spamu-sprawdzaja-takze-tresc.md) |
| D-242 | Wyjęcie z zeszytu jest odwracalne co do notatki: rdzeń z #1110 na ekranach z #1168 (#775, D-224, D-230, D-231, 22 września 2026) | — | [D-242-wyjecie-z-zeszytu-jest-odwracalne-co.md](decyzje/D-242-wyjecie-z-zeszytu-jest-odwracalne-co.md) |
| D-244 | Nikt nie rozstrzyga własnego zgłoszenia i nie karze konta równej lub wyższej roli (#1408, 23 września 2026) | obowiązuje | [D-244-nikt-nie-rozstrzyga-wlasnego-zgloszenia-i.md](decyzje/D-244-nikt-nie-rozstrzyga-wlasnego-zgloszenia-i.md) |
| D-245 | Włączenie 2FA prosi o obecne hasło, jak jej wyłączenie (#1376, 23 września 2026) | obowiązuje | [D-245-wlaczenie-2fa-prosi-o-obecne-haslo.md](decyzje/D-245-wlaczenie-2fa-prosi-o-obecne-haslo.md) |
| D-246 | Ponowne wysłanie potwierdzenia adresu ma sufit na konto i własną klasę w puli (audyt 23.09, znalezisko 2; 23 września 2026) | — | [D-246-ponowne-wyslanie-potwierdzenia-adresu-ma-sufit.md](decyzje/D-246-ponowne-wyslanie-potwierdzenia-adresu-ma-sufit.md) |
| D-249 | Wpis w dzienniku audytu: atomowy z decyzją albo pomocniczy za nią — i nic pomiędzy (#1343, #1373, #1363, 23 września 2026) | obowiązuje | [D-249-wpis-w-dzienniku-audytu-atomowy-z.md](decyzje/D-249-wpis-w-dzienniku-audytu-atomowy-z.md) |
| D-251 | „Zdejmij z urzędu”: decyzja bez zgłoszenia w tym samym rejestrze, z `report_id = NULL` (G31, 23 września 2026) | obowiązuje | [D-251-zdejmij-z-urzedu-decyzja-bez-zgloszenia.md](decyzje/D-251-zdejmij-z-urzedu-decyzja-bez-zgloszenia.md) |
| D-252 | Aplikacja sama dosyła zaległe potwierdzenia przyjęcia zgłoszeń, co godzinę (#797, DSA art. 16 ust. 4, 23 września 2026) | obowiązuje | [D-252-aplikacja-sama-dosyla-zalegle-potwierdzenia.md](decyzje/D-252-aplikacja-sama-dosyla-zalegle-potwierdzenia.md) |
| D-253 | Decyzja właściciela #926: prywatne czynności podczas zawieszenia (20 września 2026) | — | [D-253-decyzja-wlasciciela-926-prywatne-czynnosci-podczas.md](decyzje/D-253-decyzja-wlasciciela-926-prywatne-czynnosci-podczas.md) |
| D-254 | Adres źródła przepisu: tylko HTTP/HTTPS, dawny adres może zostać (#900, 20 września 2026) | — | [D-254-adres-zrodla-przepisu-tylko-http-https.md](decyzje/D-254-adres-zrodla-przepisu-tylko-http-https.md) |
| D-255 | Adres magazynu R2 za strażnikiem hostów: tylko `<konto>.eu.r2.cloudflarestorage.com` (24 września 2026) | obowiązuje | [D-255-adres-magazynu-r2-za-straznikiem-hostow.md](decyzje/D-255-adres-magazynu-r2-za-straznikiem-hostow.md) |
| D-256 | Poprawiony komentarz przechodzi analizę automatu jeszcze raz (24 września 2026) | obowiązuje | [D-256-poprawiony-komentarz-przechodzi-analize-automatu.md](decyzje/D-256-poprawiony-komentarz-przechodzi-analize-automatu.md) |
| D-257 | Zdjęcia w R2: token per bucket w aplikacji, kopia jako datowane migawki poza jej zasięgiem (#617, 24 września 2026) | część w kodzie obowiązuje po scaleniu; strategia kopii czeka na decyzję właściciela (runbook: docs/infra/DR_ZDJEC_R2.md) | [D-257-zdjecia-w-r2-token-per-bucket.md](decyzje/D-257-zdjecia-w-r2-token-per-bucket.md) |
| D-266 | Dwa runnery zarezerwowane dla `main`: `CI_RUNS_ON_MAIN` przed `CI_RUNS_ON`, ciąg dalszy D-121 (25 września 2026) | obowiązuje | [D-266-dwa-runnery-zarezerwowane-dla-main-ci.md](decyzje/D-266-dwa-runnery-zarezerwowane-dla-main-ci.md) |
| D-267 | Przepis ze WSZYSTKICH zeszytów schodzi dopiero po potwierdzeniu (#775, sprostowanie D-242 pkt 4, 25 września 2026) | — | [D-267-przepis-ze-wszystkich-zeszytow-schodzi-dopiero.md](decyzje/D-267-przepis-ze-wszystkich-zeszytow-schodzi-dopiero.md) |
| D-1009-ROBOCZA | Pierwszy wkład jest jednorazowym zdarzeniem (21 września 2026) | — | [D-1009-robocza-pierwszy-wklad-jest-jednorazowym-zdarzeniem.md](decyzje/D-1009-robocza-pierwszy-wklad-jest-jednorazowym-zdarzeniem.md) |
| Uzupełnienie #369 | Próg prezentacji publicznej aktywności (20 września 2026) | — | [U-369-prog-prezentacji-publicznej-aktywnosci.md](decyzje/U-369-prog-prezentacji-publicznej-aktywnosci.md) |

<!-- indeks-decyzji:koniec -->
