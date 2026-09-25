{{-- Samodzielny ekran awarii. Nie obiecuje diagnozy ani stanu danych bez ich sprawdzenia. --}}
@php
    $requestId = request()->attributes->get(\App\Http\Middleware\CorrelateRequest::ATTRIBUTE);
@endphp
@include('errors._prosty', [
    'tytul' => 'Coś się u nas zepsuło',
    'naglowek' => 'Coś się u nas zepsuło',
    'akapity' => [
        'Nie udało się otworzyć tej strony z powodu błędu serwisu.',
        'Odczekaj chwilę i spróbuj jeszcze raz.',
        'Jeśli to się powtarza, napisz do nas na '.config('kuking.community.contact_email').'.',
        ...($requestId ? ['Kod błędu: '.$requestId.'. Podaj go, gdy do nas napiszesz.'] : []),
    ],
    'adresPowrotu' => '/',
    // Przycisk prowadzi na stronę główną, więc tak się nazywa — domyślne
    // „Spróbuj jeszcze raz” obiecywało ponowienie, a przenosiło gdzie indziej
    // (audyt B9). Samo ponowienie radzi akapit wyżej.
    'etykietaPowrotu' => 'Strona główna',
])
