{{--
    ZAPASOWY EKRAN DLA POZOSTAŁYCH BŁĘDÓW 4xx.

    Laravel szuka widoku `errors/{kod}`, a gdy go nie ma — `errors/4xx`
    (`Handler::getHttpExceptionView()`). Bez tego pliku wszystko poza
    403, 404, 419 i 429 dostawało ANGIELSKĄ stronę frameworka. Zmierzone
    20 września 2026 przy `APP_DEBUG=false`: `PUT /dodaj/zdjecie` oddawał
    stronę z nagłówkiem „Oops! An Error Occurred" i „Method Not Allowed",
    po angielsku, w obcej typografii, bez jednego odnośnika prowadzącego
    z powrotem do serwisu.

    Najczęstsze kody, które tu trafiają, i skąd się biorą:
      405  metoda niezgodna z trasą — stary formularz w otwartej karcie,
           przycisk „wstecz" po wysłaniu, bot chodzący po adresach;
      400  żądanie, którego framework nie umie rozłożyć (m.in. `TrustHosts`);
      410  adres świadomie wycofany.

    KOD BŁĘDU NIE JEST TREŚCIĄ EKRANU — tak samo jak na 404. Człowiek,
    który tu trafił, nie dowie się niczego z liczby „405"; potrzebuje
    wiedzieć, że to nie on coś zepsuł, i dostać drogę powrotną.

    `_prosty`, a nie `x-layout`, bo 4xx bywa rzucone PRZED grupą `web`
    (routing, `TrustHosts`) — czyli zanim ruszy sesja. Layout serwisu pyta
    wtedy o rzeczy, których jeszcze nie ma, i strona błędu sama by się
    wywróciła.
--}}
@include('errors._prosty', [
    'tytul' => 'Nie udało się otworzyć tej strony',
    'naglowek' => 'Nie udało się otworzyć tej strony',
    'akapity' => [
        'Ten adres nie zadziałał tak, jak powinien. To nie jest Twoja wina i nic się nie zepsuło.',
        'Wróć na stronę główną i dojdź tu jeszcze raz — z menu albo z wyszukiwarki, zamiast z zapisanego adresu.',
        'Jeśli to się powtarza, napisz do nas na '.config('kuking.community.contact_email').'.',
    ],
    'adresPowrotu' => '/',
    // NIE „Spróbuj jeszcze raz": przy 4xx powtórzenie tego samego żądania
    // skończy się tym samym błędem, a przycisk obiecywałby coś przeciwnego.
    'etykietaPowrotu' => 'Strona główna',
])
