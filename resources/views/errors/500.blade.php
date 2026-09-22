{{-- Samodzielny ekran awarii. Nie obiecuje diagnozy ani stanu danych bez ich sprawdzenia. --}}
@include('errors._prosty', [
    'tytul' => 'Coś się u nas zepsuło',
    'naglowek' => 'Coś się u nas zepsuło',
    'akapity' => [
        'Nie udało się otworzyć tej strony z powodu błędu serwisu.',
        'Odczekaj chwilę i spróbuj jeszcze raz.',
        'Jeśli to się powtarza, napisz do nas na '.config('kuking.community.contact_email').'.',
    ],
    'adresPowrotu' => '/',
])
