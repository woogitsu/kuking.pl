# Inwentarz ekranów marki — Alfa 0.14

13 września 2026. **Inwentaryzacja kodu, odbiór w raporcie [AUDYT_KOMPLETNOSCI_MARKI_ALFA_014.md](AUDYT_KOMPLETNOSCI_MARKI_ALFA_014.md).** Ta lista nie przypisuje trasom wyniku wizualnego ani testowego.

Źródła: `output/routes-audyt.json` (zarejestrowane trasy), `output/audyt-kompletnosci-inwentarz.md`, kontrolery oraz widoki bieżącego checkoutu. Stan bazowy inwentaryzacji: `651def47122a3bd07896c165c32d2706d2462a3d`. Lista obejmuje każdą trasę przyjmującą GET, również trasy wielometodowe, frameworkowe i zasoby. Pliki statyczne bez rejestracji w routerze są wymienione oddzielnie.

Kategoria określa podstawową rolę endpointu. Ekran może przekierować przez middleware; przekierowanie OAuth może także pokazać stan braku adresu. Widoki zapisano nazwami Blade: `pages.home` oznacza `resources/views/pages/home.blade.php`. Dla odpowiedzi bez szablonu podano rodzaj wyniku. Istotne stany wyznaczają zakres do odbioru, nie twierdzenie, że każda kombinacja została uruchomiona.

Każda trasa `/admin/*` ma dodatkowo stan bramki `pages.admin.wymagane_2fa` (403) dla moderatora bez potwierdzonego drugiego składnika; zwykłe konto nie dostaje dostępu do panelu.

Wspólne stany ekranów: jasny/ciemny motyw, 320/360/390/414 px i desktop, powiększony tekst, długie nazwy, fokus i klawiatura; formularze dodatkowo mają błąd przy polu i podsumowanie, zachowanie danych, wysyłanie/sukces, 419/429 oraz brak skryptu. Stany konta i blokady należy rozpatrywać zgodnie z polityką danego zasobu. Kategorie plik/system nie dziedziczą wymagań geometrii ekranu.

Prefiks `livewire-b7a314a9` w tabeli jest snapshotem lokalnej instancji, nie stałym adresem wszystkich wdrożeń. `Livewire\Mechanisms\HandleRequests\EndpointResolver::prefix()` tworzy go z pierwszych ośmiu cyfr szesnastkowych SHA-256 wartości `app.key` z dopiskiem `livewire-endpoint`. Inna instalacja lub zmiana klucza może dać inny prefiks; stabilne są wymienione sufiksy tras. Inwentaryzacja zachowuje zaobserwowane adresy, a test porównania tras normalizuje taki ośmiocyfrowy prefiks Livewire oraz dokładny wariant głównego skryptu: `livewire.js` przy debugowaniu i `livewire.min.js` bez debugowania. Nie normalizuje dowolnych nazw plików ani dodatkowych segmentów.

## Lista tras GET (103)

| Trasa | Kategoria | Widok lub wynik | Istotne stany |
|---|---|---|---|
| `/` | ekran | `pages.landing` | gość/zalogowany; pusty/pełny strumień; kolaż; wspomnienie; widoczność |
| `/@{username}` | ekran | `pages.profile.show` | własny/cudzy profil; relacje; blokada; pusto/treści; stan konta |
| `/@{username}/obserwowani` | ekran | `pages.profile.connections` | własny/cudzy profil; relacje; blokada; pusto/treści; stan konta |
| `/@{username}/obserwujacy` | ekran | `pages.profile.connections` | własny/cudzy profil; relacje; blokada; pusto/treści; stan konta |
| `/admin/bez-odpowiedzi` | ekran | `pages.admin.bez-odpowiedzi` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/kolaz-powitalny` | ekran | `pages.admin.kolaz-powitalny` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/kuking-na-dzis` | ekran | `pages.admin.daily-board` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/odwolania` | ekran | `pages.admin.appeals` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/sygnaly` | ekran | `pages.admin.sygnaly` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/tagi-promowane` | ekran | `pages.admin.tag-promotions` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/uzytkownicy` | ekran | `pages.admin.uzytkownicy` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/uzytkownicy/{user}` | ekran | `pages.admin.uzytkownik` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/wiadomosci` | ekran | `pages.admin.wiadomosci` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/wiadomosci/{wiadomosc}` | ekran | `pages.admin.wiadomosc` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/admin/zgloszenia` | ekran | `pages.admin.reports` | moderator; bramka 2FA; pusto/pełno; filtry; niedostępny obiekt; wynik zapisu |
| `/cofnij-usuniecie-konta` | ekran | `pages.account.cancel-deletion` | walidacja; poczta dostępna/niedostępna; potwierdzony/niepotwierdzony adres; token/stan konta |
| `/dodaj` | ekran | `pages.add` | nowy/szkic/edycja; upload; walidacja; zachowanie danych; publikacja; własność |
| `/dodaj/przepis` | ekran | `pages.recipes.create; pages.recipes.wizard` | nowy/szkic/edycja; upload; walidacja; zachowanie danych; publikacja; własność |
| `/dodaj/przepis/jedna-strona` | ekran | `pages.recipes.szczegoly` | nowy/szkic/edycja; upload; walidacja; zachowanie danych; publikacja; własność |
| `/dodaj/zdjecie` | ekran | `pages.posts.create` | nowy/szkic/edycja; upload; walidacja; zachowanie danych; publikacja; własność |
| `/health` | system | `JSON stanu usług` | sprawne/niesprawne zależności; kod odpowiedzi |
| `/home` | ekran | `pages.home` | gość/zalogowany; pusty/pełny strumień; kolaż; wspomnienie; widoczność |
| `/livewire-b7a314a9/css/{component}.css` | plik | `zasób Livewire (bez widoku aplikacji)` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/livewire-b7a314a9/css/{component}.global.css` | plik | `zasób Livewire (bez widoku aplikacji)` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/livewire-b7a314a9/js/{component}.js` | plik | `zasób Livewire (bez widoku aplikacji)` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/livewire-b7a314a9/livewire.csp.min.js.map` | plik | `zasób Livewire (bez widoku aplikacji)` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/livewire-b7a314a9/livewire.js` | plik | `zasób Livewire (bez widoku aplikacji)` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/livewire-b7a314a9/livewire.min.js.map` | plik | `zasób Livewire (bez widoku aplikacji)` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/livewire-b7a314a9/preview-file/{filename}` | plik | `podgląd przesłanego pliku` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/login` | ekran | `auth.login` | walidacja; poczta dostępna/niedostępna; potwierdzony/niepotwierdzony adres; token/stan konta |
| `/logowanie/kod` | ekran | `auth.two_factor_challenge` | 2FA włączone/wyłączone; błędny kod; kody zapasowe; potwierdzenie |
| `/logowanie/link` | ekran | `auth.login-link; auth.login-link-unavailable` | walidacja; poczta dostępna/niedostępna; potwierdzony/niepotwierdzony adres; token/stan konta |
| `/logowanie/link/{token}` | ekran | `auth.login-link-confirm; auth.login-link-unavailable` | token ważny/zużyty/wygasły; potwierdzenie POST; 2FA; stan konta |
| `/napisz-do-nas` | ekran | `pages.napisz-do-nas` | gość/zalogowany; walidacja; potwierdzenie; niedostępna poczta |
| `/napisz-do-nas/dziekujemy` | ekran | `pages.napisz-do-nas-potwierdzenie` | gość/zalogowany; walidacja; potwierdzenie; niedostępna poczta |
| `/nie-pamietam-hasla` | ekran | `auth.forgot-password` | walidacja; poczta dostępna/niedostępna; potwierdzony/niepotwierdzony adres; token/stan konta |
| `/nowe-haslo/{token}` | ekran | `auth.reset-password` | walidacja; poczta dostępna/niedostępna; potwierdzony/niepotwierdzony adres; token/stan konta |
| `/o-kuking` | ekran | `pages.static.about` | treść; dostęp; brak zasobu; błąd odpowiedzi |
| `/odkryj` | ekran | `pages.discover` | pusto/wyniki; fraza; filtry; paginacja; widoczność |
| `/odwolanie` | ekran | `pages.appeals.guest` | formularz/potwierdzenie; autor/zgłaszający/gość; podpis; termin; wynik sprawy |
| `/odwolanie/{action}` | ekran | `pages.appeals.create` | formularz/potwierdzenie; autor/zgłaszający/gość; podpis; termin; wynik sprawy |
| `/podsumowanie/wracam/{user}` | ekran | `pages.podsumowanie-wracam` (GET, tylko pytanie), `pages.podsumowanie-wrocono` (po POST) | podpis; GET niczego nie zapisuje, zgodę włącza tylko POST z CSRF (#1403); ponowne otwarcie |
| `/podsumowanie/wypisz/{user}` | ekran | `pages.podsumowanie-wypisano` | podpis; wypisanie/powrót; ponowne otwarcie; także POST |
| `/pomoc` | ekran | `pages.static.help` | treść; dostęp; brak zasobu; błąd odpowiedzi |
| `/potwierdz-email` | ekran | `auth.verify-email` | walidacja; poczta dostępna/niedostępna; potwierdzony/niepotwierdzony adres; token/stan konta |
| `/potwierdz-email/{id}/{hash}` | przekierowanie | `przekierowanie do home ze statusem` | walidacja; poczta dostępna/niedostępna; potwierdzony/niepotwierdzony adres; token/stan konta |
| `/powiadomienia` | ekran | `pages.notifications` | pusto/pełno; odczyt; cel dostępny/usunięty; paginacja |
| `/prywatnosc` | ekran | `pages.static.legal` | treść; dostęp; brak zasobu; błąd odpowiedzi |
| `/przepisy/{recipe}` | ekran | `pages.recipes.show` | publiczny/prywatny/szkic; autor/gość; media; komentarze; zapis; blokady |
| `/przepisy/{recipe}/edycja` | ekran | `pages.recipes.szczegoly` | nowy/szkic/edycja; upload; walidacja; zachowanie danych; publikacja; własność |
| `/przepisy/{recipe}/gotuj` | ekran | `pages.recipes.cooking` | kroki; odhaczanie; minutnik; Wake Lock/brak wsparcia; powrót |
| `/przepisy/{recipe}/szczegoly` | ekran | `pages.recipes.wizard` | nowy/szkic/edycja; upload; walidacja; zachowanie danych; publikacja; własność |
| `/przepisy/{recipe}/ugotowalem` | ekran | `pages.cooked.create` | publiczny/prywatny/szkic; autor/gość; media; komentarze; zapis; blokady |
| `/register` | ekran | `auth.register` | walidacja; poczta dostępna/niedostępna; potwierdzony/niepotwierdzony adres; token/stan konta |
| `/regulamin` | ekran | `pages.static.legal` | treść; dostęp; brak zasobu; błąd odpowiedzi |
| `/robots.txt` | plik | `tekst robots.txt` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/sitemap.xml` | plik | `sitemap (XML)` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/storage/{path}` | plik | `plik lokalnego storage` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/szukaj` | ekran | `pages.search` | pusto/wyniki; fraza; filtry; paginacja; widoczność |
| `/tag/{tag}` | ekran | `pages.tags.show` | pusto/wyniki; fraza; filtry; paginacja; widoczność |
| `/tagi` | ekran | `pages.tags.index` | pusto/wyniki; fraza; filtry; paginacja; widoczność |
| `/ugotowane/{cookedEvent}` | ekran | `pages.cooked.show` | zdjęcie/brak; notatka/brak; autor/kucharz/gość; podziękowanie |
| `/ugotowane/{cookedEvent}/wyszlo` | ekran | `pages.cooked.celebrate` | zdjęcie/brak; notatka/brak; autor/kucharz/gość; podziękowanie |
| `/up` | system | `health frameworka` | sprawne/niesprawne zależności; kod odpowiedzi |
| `/ustawienia` | ekran | `pages.settings.index` | treść; dostęp; brak zasobu; błąd odpowiedzi |
| `/ustawienia/2fa` | ekran | `pages.settings.two_factor.index` | 2FA włączone/wyłączone; błędny kod; kody zapasowe; potwierdzenie |
| `/ustawienia/2fa/kody-zapasowe` | ekran | `pages.settings.two_factor.codes` | 2FA włączone/wyłączone; błędny kod; kody zapasowe; potwierdzenie |
| `/ustawienia/2fa/wlacz` | ekran | `pages.settings.two_factor.enable` | 2FA włączone/wyłączone; błędny kod; kody zapasowe; potwierdzenie |
| `/ustawienia/bezpieczenstwo` | ekran | `pages.settings.security` | wartość bieżąca; walidacja; zapis; potwierdzenie; uprawnienia |
| `/ustawienia/czytelnosc` | ekran | `pages.settings.accessibility` | wartość bieżąca; walidacja; zapis; potwierdzenie; uprawnienia |
| `/ustawienia/e-mail` | ekran | `pages.settings.email` | wartość bieżąca; walidacja; zapis; potwierdzenie; uprawnienia |
| `/ustawienia/e-mail/potwierdz/{zmiana}` | przekierowanie | `przekierowanie do settings.email ze statusem` | wartość bieżąca; walidacja; zapis; potwierdzenie; uprawnienia |
| `/ustawienia/profil` | ekran | `pages.settings.profile` | wartość bieżąca; walidacja; zapis; potwierdzenie; uprawnienia |
| `/ustawienia/prywatnosc` | ekran | `pages.settings.privacy` | wartość bieżąca; walidacja; zapis; potwierdzenie; uprawnienia |
| `/ustawienia/tagi` | ekran | `pages.settings.tags` | wartość bieżąca; walidacja; zapis; potwierdzenie; uprawnienia |
| `/ustawienia/twoje-dane` | ekran | `pages.settings.data` | eksport brak/pending/ready/failed/expired; zakres usunięcia; potwierdzenie; właściciel |
| `/ustawienia/twoje-dane/pobierz/{export}` | plik | `plik eksportu` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/ustawienia/zdjecie` | ekran | `pages.settings.avatar` | wartość bieżąca; walidacja; zapis; potwierdzenie; uprawnienia |
| `/wejdz/facebook` | przekierowanie | `przekierowanie do dostawcy lub login` | dostawca wyłączony; odmowa; sesja wygasła; nowe/istniejące konto; brak adresu; połączenie |
| `/wejdz/facebook/domknij` | ekran | `auth.facebook-finish` | dostawca wyłączony; odmowa; sesja wygasła; nowe/istniejące konto; brak adresu; połączenie |
| `/wejdz/facebook/polacz` | ekran | `auth.facebook-link` | dostawca wyłączony; odmowa; sesja wygasła; nowe/istniejące konto; brak adresu; połączenie |
| `/wejdz/facebook/wroc` | przekierowanie | `auth.facebook-bez-adresu lub przekierowanie` | dostawca wyłączony; odmowa; sesja wygasła; nowe/istniejące konto; brak adresu; połączenie |
| `/wejdz/google` | przekierowanie | `przekierowanie do dostawcy lub login` | dostawca wyłączony; odmowa; sesja wygasła; nowe/istniejące konto; brak adresu; połączenie |
| `/wejdz/google/domknij` | ekran | `auth.google-finish` | dostawca wyłączony; odmowa; sesja wygasła; nowe/istniejące konto; brak adresu; połączenie |
| `/wejdz/google/polacz` | ekran | `auth.google-link` | dostawca wyłączony; odmowa; sesja wygasła; nowe/istniejące konto; brak adresu; połączenie |
| `/wejdz/google/wroc` | przekierowanie | `przekierowanie: login, domknięcie, połączenie lub home` | dostawca wyłączony; odmowa; sesja wygasła; nowe/istniejące konto; brak adresu; połączenie |
| `/witaj/gotowe` | ekran | `pages.onboarding.done` | wybory; pusta lista; szukanie; pominięcie; zakończenie |
| `/witaj/ludzie` | ekran | `pages.onboarding.people` | wybory; pusta lista; szukanie; pominięcie; zakończenie |
| `/witaj/zainteresowania` | ekran | `pages.onboarding.interests` | wybory; pusta lista; szukanie; pominięcie; zakończenie |
| `/wpisy/{post}` | ekran | `pages.posts.show` | publiczny/prywatny/szkic; autor/gość; media; komentarze; zapis; blokady |
| `/wpisy/{post}/edycja` | ekran | `pages.posts.edit` | nowy/szkic/edycja; upload; walidacja; zachowanie danych; publikacja; własność |
| `/wpisy/{post}/zdjecia` | ekran | `pages.posts.zdjecia` | nowy/szkic/edycja; upload; walidacja; zachowanie danych; publikacja; własność |
| `/zaproszenie/{token}` | ekran | `auth.zaproszenie; auth.zaproszenie-nieaktualne` | ważne/zużyte/wygasłe; przyjęcie; gość/zalogowany |
| `/zasady` | ekran | `pages.static.legal` | treść; dostęp; brak zasobu; błąd odpowiedzi |
| `/zdjecia/{media}/{wariant}` | plik | `plik zdjęcia` | dostępny/brak; wymagane uprawnienia/podpis; typ odpowiedzi |
| `/zeszyt` | ekran | `pages.collections.index` | pusto/treści; prywatny/publiczny; cudzy/błędny wybór; notatka |
| `/zeszyt/{collection}` | ekran | `pages.collections.show` | pusto/treści; prywatny/publiczny; cudzy/błędny wybór; notatka |
| `/zglos-nielegalna-tresc` | ekran | `pages.zglos-nielegalna-tresc` | formularz/potwierdzenie; autor/zgłaszający/gość; podpis; termin; wynik sprawy |
| `/zglos-nielegalna-tresc/przyjete` | ekran | `pages.zglos-nielegalna-tresc-potwierdzenie` | formularz/potwierdzenie; autor/zgłaszający/gość; podpis; termin; wynik sprawy |
| `/zglos/{type}/{id}` | ekran | `pages.report` | formularz/potwierdzenie; autor/zgłaszający/gość; podpis; termin; wynik sprawy |
| `/zgloszenia` | ekran | `pages.zgloszenia.lista` | formularz/potwierdzenie; autor/zgłaszający/gość; podpis; termin; wynik sprawy |
| `/zgloszenia/{report}` | ekran | `pages.zgloszenia.szczegoly` | formularz/potwierdzenie; autor/zgłaszający/gość; podpis; termin; wynik sprawy |
| `/zgloszenie/{report}/odwolanie` | ekran | `pages.appeals.reporter` | formularz/potwierdzenie; autor/zgłaszający/gość; podpis; termin; wynik sprawy |


## Powierzchnie bez odrębnej trasy GET

- Wspólna rama i komponenty: `components/layout`, nawigacja konta i moderacji, `post-card`, `recipe-card`, `cooked-card`, `comment-thread`, `photo`, karuzela, kolaż, `empty-state`, `error-summary`, `field`, `blad-grupy`, `confirm-button`, `show-more`, `podziel-sie`, kreator i Turnstile. Są częścią ekranów powyżej, także w stanach menu, dialogu, ładowania i błędu.
- Błędy HTTP: `errors/403`, `404`, `419`, `429`, `500`, `503` i samodzielna obudowa `_prosty`. Odbiór obejmuje poprawny kod, instrukcję dalszego działania oraz niezależność awarii od bazy i assetów tam, gdzie przewidziana.
- Poczta: wszystkie `resources/views/mail/*` (11 własnych HTML oraz wersja tekstowa digestu), `resources/views/vendor/mail/html/*` i treści `app/Notifications`. Odbiór obejmuje realny render, linki, terminy, czytelność i dane warunkowe; sama zgodność palety nie wystarcza.
- Eksport: `resources/views/exports/{index,posts,recipe,readme,styles}.blade.php`; gotowy plik jest osiągalny trasą pobrania, ale strony wewnątrz archiwum wymagają osobnego otwarcia bez sieci.
- PWA i pliki statyczne: `public/offline.html`, `public/sw.js`, `public/manifest.webmanifest`, ikony aplikacji i udostępniania. Należy rozróżnić świeżą instalację od aktualizacji istniejącej.
- Wyniki POST/PUT/PATCH/DELETE: publikacja, komentarz, wykonanie, podziękowanie, zapis do zeszytu, obserwowanie/blokada, ustawienia, moderacja, kontakt i odzyskiwanie konta. Ich walidacja, komunikaty sukcesu, konflikty i ponowne wysłanie należą do ekranów powrotu; lista GET nie zastępuje macierzy tych operacji.

Ten dokument nie ustanawia nowych decyzji. Wymagania marki określają AGENTS.md, KONSTYTUCJA_MARKI, COPY_STYLE, GLOS_MARKI i obowiązująca historia DECISIONS; wynik wykonanych kontroli jest wyłącznie w raporcie odbioru.
