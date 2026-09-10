{{--
    E-mail „Odpowiedź na Twoją wiadomość do Kuking" (D-058).

    Jedna kolumna, duży tekst, bez obrazków i bez ani jednego przycisku —
    tu nie ma czego klikać, jest co przeczytać. Styl inline, bo Gmail
    i Outlook usuwają <style> z <head>.

    TREŚĆ ODPOWIEDZI WYCHODZI DOKŁADNIE TAKA, JAKĄ MODERATOR WPISAŁ.
    `{{ }}` (nie `{!! !!}`) — to jest tekst od człowieka i nie renderujemy
    z niego HTML-a, nawet gdy autorem jest moderator. `white-space: pre-line`
    zachowuje akapity, które ktoś rozdzielił enterem: bez tego odpowiedź na
    pytanie w trzech punktach dochodziłaby jako jedna ściana tekstu.

    Bez gry słowem „kuKING" — D-009 zabrania jej w wiadomościach do ludzi
    w sprawach, w których coś nie działa.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Odpowiedź na Twoją wiadomość do Kuking</title>
</head>
<body style="margin:0;padding:0;background:#FAF6F0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FAF6F0;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background:#FFFFFF;border:1px solid #E4DACB;border-radius:12px;">
                <tr>
                    <td style="padding:32px 28px;font-family:Georgia,'Times New Roman',serif;font-size:19px;line-height:1.6;color:#2B241D;">

                        <p style="margin:0 0 20px;font-size:26px;line-height:1.3;font-weight:bold;color:#2B241D;">
                            Odpowiedź na Twoją wiadomość
                        </p>

                        <p style="margin:0 0 20px;">Dzień dobry.</p>

                        {{--
                            Rozpoznanie sprawy po dacie i rodzaju, bez cytatu
                            oryginalnej wiadomości — uzasadnienie w nagłówku
                            `App\Mail\OdpowiedzNaWiadomosc`.
                        --}}
                        <p style="margin:0 0 20px;">
                            Dostaliśmy wiadomość wysłaną do nas
                            @if($napisanaKiedy)
                                {{ \App\Support\Czas::data($napisanaKiedy, 'j F Y') }}
                            @endif
                            przez formularz „Napisz do nas" ({{ mb_strtolower($rodzaj) }}).
                            Oto nasza odpowiedź:
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="margin:0 0 28px;background:#FAF6F0;border-left:4px solid #B3401F;border-radius:8px;">
                            <tr>
                                <td style="padding:20px 22px;white-space:pre-line;">{{ $tresc }}</td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#5C5347;">
                            Jeśli to nie wyjaśnia sprawy, odpisz na tę wiadomość —
                            odpowiedź trafi do nas na
                            <strong>{{ $adresKontaktowy }}</strong> i przeczyta ją człowiek.
                        </p>

                        <p style="margin:0;font-size:18px;color:#5C5347;">
                            Zespół Kuking.pl
                        </p>

                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
