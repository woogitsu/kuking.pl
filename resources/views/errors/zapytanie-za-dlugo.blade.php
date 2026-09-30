{{--
    Zapytanie przerwane przez `statement_timeout` żądania HTTP (#2290,
    `App\Support\Baza\LimitCzasuZapytanHttp`). Samodzielny ekran jak 500 i 503:
    baza właśnie nie zdążyła, więc strona nie może niczego od niej chcieć.
    Nie obiecuje stanu danych — przerwane zapytanie cofa swoją transakcję,
    ale ekran nie wie, co ta osoba robiła wcześniej.
--}}
@php
    $requestId = request()->attributes->get(\App\Http\Middleware\CorrelateRequest::ATTRIBUTE);
@endphp
@include('errors._prosty', [
    'tytul' => 'Strona ładuje się za długo',
    'naglowek' => 'Strona ładuje się za długo',
    'akapity' => [
        'Serwis nie zdążył przygotować tej strony i przerwał jej ładowanie.',
        'Odczekaj pół minuty i otwórz ją jeszcze raz.',
        'Jeśli to się powtarza, napisz do nas na '.config('kuking.community.contact_email').'.',
        ...($requestId ? ['Kod błędu: '.$requestId.'. Podaj go, gdy do nas napiszesz.'] : []),
    ],
    'adresPowrotu' => '/',
    'etykietaPowrotu' => 'Strona główna',
])
