{{--
    E-mail „Twoje dane są gotowe”.

    Jedna kolumna, duży tekst, jeden wyraźny przycisk. Bez obrazków, bez
    kolumn, bez ikon bez podpisu — klient pocztowy i tak połowę tego wycina,
    a osoba czytająca to na telefonie musi zobaczyć JEDNĄ rzecz do kliknięcia.

    Style są inline, bo Gmail i Outlook usuwają <style> z <head>.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Twoje dane z Kuking są gotowe</title>
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
                            Twoje dane są gotowe do pobrania
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        {{-- Gotowy napis z docs/brand/COPY_STYLE.md §6. Nie zmieniamy go. --}}
                        <p style="margin:0 0 20px;">
                            przygotowaliśmy paczkę ze wszystkim, co tu masz. Otworzysz ją
                            na swoim komputerze, także wtedy, gdyby Kuking kiedyś przestał istnieć.
                        </p>

                        <p style="margin:0 0 28px;">
                            To plik ZIP. Po pobraniu kliknij go dwa razy, a potem otwórz
                            <strong>index.html</strong> — tam jest spis wszystkiego.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $downloadUrl }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Pobierz swoje dane
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            @if($expiresAt)
                                Link działa do <strong>{{ \App\Support\Czas::data($expiresAt, 'j F Y') }}</strong>.
                                Potem paczka zostanie usunięta z naszych serwerów —
                                nie trzymamy kopii Twojego konta bez końca.
                                Jeśli nie zdążysz, po prostu poproś o nową paczkę w ustawieniach.
                            @endif
                            @if($sizeText)
                                Rozmiar pliku: {{ $sizeText }}.
                            @endif
                        </p>

                        {{-- „zostaniesz poproszona" przypisywało czytelnikowi rodzaj
                             żeński (issue #38, COPY_STYLE.md §2) — strona czynna
                             w pierwszej osobie liczby mnogiej mówi to samo
                             bez rodzaju. --}}
                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Zanim zaczniesz pobierać, poprosimy Cię o zalogowanie się.
                            Tak musi być: w tej paczce jest kopia całego Twojego konta i nie może
                            jej otworzyć ktoś, kto tylko zobaczył ten e-mail.
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            O tę paczkę poproszono w ustawieniach Kuking. Jeśli to nie Ty —
                            napisz do nas na {{ config('kuking.community.contact_email') }}.
                        </p>

                    </td>
                </tr>
            </table>

            <p style="max-width:600px;margin:20px auto 0;font-family:Arial,Helvetica,sans-serif;
                      font-size:15px;line-height:1.5;color:#555E53;">
                Kuking.pl — pokaż, co dziś ugotowałeś.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
