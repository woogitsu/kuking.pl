{{-- Samodzielny ekran niedostępności: bez obietnicy terminu przywrócenia usługi. --}}
@include('errors._prosty', [
    'tytul' => 'Przerwa techniczna',
    'naglowek' => 'Robimy przerwę techniczną',
    'akapity' => [
        'Kuking jest teraz niedostępny.',
        'Spróbuj otworzyć stronę później.',
    ],
    'adresPowrotu' => '/',
])
