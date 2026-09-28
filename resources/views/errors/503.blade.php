{{-- Samodzielny ekran niedostępności: bez obietnicy terminu przywrócenia usługi. --}}
@include('errors._prosty', [
    'tytul' => 'Przerwa techniczna',
    'naglowek' => 'Robimy przerwę techniczną',
    'akapity' => [
        'Kuking jest teraz niedostępny.',
        'Spróbuj otworzyć stronę później.',
    ],
    'adresPowrotu' => '/',
    // Przycisk prowadzi na stronę główną, więc tak się nazywa — domyślne
    // „Spróbuj jeszcze raz” obiecywało ponowienie, a przenosiło gdzie indziej
    // (audyt B9). Samo ponowienie radzi akapit wyżej.
    'etykietaPowrotu' => 'Strona główna',
])
