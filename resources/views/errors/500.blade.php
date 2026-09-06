{{--
    500 — awaria po naszej stronie (issue #81).

    Żadnych szczegółów technicznych: komunikat wyjątku bywa ścieżką na dysku
    albo fragmentem zapytania i nie ma prawa trafić na ekran. Człowiekowi
    potrzebne są dwa zdania: że to nasza wina i że wiemy o tym bez jego
    zgłoszenia.

    Świadomie NIE mówimy „spróbuj za chwilę i zadziała", bo tego nie wiemy.
--}}
@include('errors._prosty', [
    'tytul' => 'Coś się u nas zepsuło',
    'naglowek' => 'Coś się u nas zepsuło',
    'akapity' => [
        'To nie jest wina Twojego komputera ani Twojego kliknięcia. Awaria jest po naszej stronie i już o niej wiemy — nie trzeba jej zgłaszać.',
        'Odczekaj kilka minut i otwórz stronę jeszcze raz. Twoje wpisy, przepisy i zdjęcia są bezpieczne, nawet jeśli teraz ich nie widać.',
        'Jeśli to się powtarza, napisz do nas na '.config('kuking.community.contact_email').' — odpisuje człowiek.',
    ],
    'adresPowrotu' => '/',
])
