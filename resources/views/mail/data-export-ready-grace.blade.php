{{--
    E-mail „Twoja paczka danych jest gotowa” — dla konta w karencji usunięcia.

    Ten sam układ co `data-export-ready`: jedna kolumna, duży tekst, JEDEN
    przycisk. Tyle że przycisk NIE prowadzi do paczki — konto w karencji się
    nie zaloguje, więc link do pobrania byłby martwy. Prowadzi do cofnięcia
    usunięcia; paczka czeka potem w ustawieniach.

    Style są inline, bo Gmail i Outlook usuwają <style> z <head>.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Twoja paczka danych z Kuking jest gotowa</title>
</head>
<body style="margin:0;padding:0;background:#F3F4F1;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F4F1;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background:#FFFFFF;border:1px solid #DDE0D8;border-radius:24px;">
                <tr>
                    <td style="padding:32px 28px;font-family:Arial,Helvetica,sans-serif;font-size:19px;line-height:1.6;color:#151714;">

                        <p style="margin:0 0 20px;font-size:28px;line-height:1.3;font-weight:bold;color:#151714;">
                            Twoja paczka danych jest gotowa
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        {{-- Zdanie z decyzji właściciela z 23 września 2026. --}}
                        <p style="margin:0 0 20px;">
                            Twoja paczka danych jest gotowa. Żeby ją pobrać, cofnij usunięcie konta
                            @if($deadline)
                                do <strong>{{ \App\Support\Czas::data($deadline, 'j F Y, H:i') }}</strong>.
                            @else
                                jak najszybciej.
                            @endif
                        </p>

                        <p style="margin:0 0 28px;">
                            Twoje konto czeka teraz na usunięcie, więc nie da się na nie zalogować —
                            a paczkę wydajemy tylko po zalogowaniu. Tak musi być: w paczce jest
                            kopia całego Twojego konta.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $cancelUrl }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Cofnij usunięcie konta
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;">
                            Po cofnięciu zaloguj się i otwórz <strong>Ustawienia → Twoje dane</strong>.
                            Paczka będzie tam czekać.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            @if($packageExpiresFirst)
                                Tego dnia paczka zostanie usunięta z naszych serwerów, choć konto
                                @if($graceEndsAt)
                                    da się odzyskać aż do {{ \App\Support\Czas::data($graceEndsAt, 'j F Y, H:i') }}.
                                @else
                                    da się jeszcze odzyskać.
                                @endif
                                Jeśli nie zdążysz, po cofnięciu usunięcia poproś w ustawieniach o nową paczkę.
                            @else
                                Tego dnia usuniemy konto na stałe, a razem z nim tę paczkę.
                            @endif
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Jeśli paczka nie jest już potrzebna i konto ma zniknąć — nic nie rób.
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            O tę paczkę poproszono w ustawieniach Kuking. Jeśli to nie Ty —
                            napisz do nas na {{ config('kuking.community.contact_email') }}.
                        </p>

                    </td>
                </tr>
            </table>

            <p style="max-width:600px;margin:20px auto 0;font-family:Arial,Helvetica,sans-serif;
                      font-size:18px;line-height:1.5;color:#555E53;">
                Kuking.pl — pokaż, co dziś {{ \App\Support\Forma::dla(($export ?? null)?->user, 'ugotowałaś', 'ugotowałeś', 'gotujesz') }}.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
