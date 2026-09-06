{{--
    503 — przerwa techniczna (issue #81).

    Ta strona pojawia się przy `php artisan down`, czyli wtedy, gdy przerwa
    jest ZAPLANOWANA przez nas. Dlatego jej jedynym zadaniem jest obietnica
    powrotu: „wrócimy". Bez tego zdania człowiek zakłada, że serwis się
    skończył, i nie wraca.

    Nie może dotykać bazy ani sesji — w trakcie `down` nie ma pewności,
    że cokolwiek z tego działa.
--}}
@include('errors._prosty', [
    'tytul' => 'Przerwa techniczna',
    'naglowek' => 'Robimy przerwę techniczną',
    'akapity' => [
        'Kuking jest teraz wyłączony na kilka minut, bo szykujemy coś, co ma działać lepiej. Wrócimy dziś — nic nie zostało zamknięte na stałe.',
        'Twoje wpisy, przepisy i zdjęcia czekają na miejscu. Zajrzyj tu za kwadrans.',
    ],
    'adresPowrotu' => '/',
])
