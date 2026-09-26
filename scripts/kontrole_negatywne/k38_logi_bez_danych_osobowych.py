"""Log serwera i logi operacyjne bez danych osobowych i bez komunikatu obcego wyjątku
(audyt prywatności 23.09.2026, #973). Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


# Log serwera bez danych osobowych (audyt prywatności 23.09.2026). Test czyta
# zapisany plik logu i asertuje na jego treści — bez mutacji nic nie dowodzi,
# że asercje „nie zawiera e-maila/hasha" potrafią w ogóle zapalić.
LOG_SERWERA = "app/Logging/BezDanychOsobowychWLogu.php"
LOG_SERWERA_TEST = "LogSerweraBezDanychOsobowychTest"

# Logi operacyjne bez komunikatu obcego wyjątku (#973). Test skanuje kod
# `app/` i czyta kontekst loggera — mutacja przywraca surowe getMessage().
LOG_OPERACYJNY = "app/Domain/Media/KasujZdjecie.php"
LOG_OPERACYJNY_TEST = "LogOperacyjnyBezKomunikatuWyjatkuTest"


KONTROLE_DODATNIE = [LOG_SERWERA_TEST, LOG_OPERACYJNY_TEST]

KONTROLE = [
    Kontrola("Komunikat bazy z wartościami w logu serwera", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "return $this->komunikatBazy($e);", "return $e->getMessage();")),
    Kontrola("Błąd PCRE w filtrze logu po cichu zeruje treść", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "return $czysty ?? self::BLAD_FILTRA.' (długość: '.strlen($tekst).' B)';",
                            "return (string) $czysty;")),
    Kontrola("Filtr logu na loggerze zabiera webhookowi obiekt wyjątku", "app/Logging/FiltrDanychOsobowych.php", LOG_SERWERA_TEST,
     lambda s: replace_once(s, "if ($handler instanceof ProcessableHandlerInterface) {", "if (false) {")),
    Kontrola("Surowy komunikat wyjątku w logu kasowania zdjęcia", LOG_OPERACYJNY, LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "'error' => BezpiecznyBlad::kontekst($e),", "'error' => $e->getMessage(),")),
    Kontrola("Wzorzec e-maila z katastrofalnym nawracaniem", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, r"'/(?<![A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]++@(?=[A-Za-z0-9\-.]*?\.[A-Za-z]{2})[A-Za-z0-9\-]++(?:\.[A-Za-z0-9\-]++)*+/'",
                            r"'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/'")),
    Kontrola("Klucz tablicy z adresem przechodzi do logu", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "$klucz = $this->oczyscTekst($klucz);", "$klucz = (string) $klucz;")),
    Kontrola("Obiekt w kontekście logu idzie do formatera w całości", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "return $this->obiekt($wartosc, $glebokosc);", "return $wartosc;")),
    Kontrola("Za głęboka tablica przechodzi surowa", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "return self::ZA_GLEBOKO;", "return $wartosc;")),
    Kontrola("Połknięty wyjątek bez miejsca przyczyny", "app/Logging/BezpiecznyBlad.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "$miejscaPrzyczyn[] = self::miejsceWApp($p);", "$miejscaPrzyczyn[] = null;")),
    Kontrola("Komunikat wyjątku w kontekście budowanym przez metodę pomocniczą", "app/Turnstile/KlientTurnstile.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "'Cloudflare nie odpowiedział na weryfikację Turnstile.', [\n                'error' => BezpiecznyBlad::kontekst($e),",
                            "'Cloudflare nie odpowiedział na weryfikację Turnstile.', [\n                'komunikat' => $e->getMessage(),")),
    Kontrola("Komunikat transportu przez BezpiecznyKomunikat w logu listu o paczce", "app/Jobs/NotifyUserExportReady.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "'error' => BezpiecznyBlad::kontekst($e),", "'error' => \\App\\Poczta\\BezpiecznyKomunikat::z($e->getMessage()),")),
    Kontrola("Komunikat bazy przez BezpiecznyKomunikat w logu śladu listu", "app/Poczta/ZapiszNieudanyList.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "'error' => BezpiecznyBlad::kontekst($e),", "'error' => BezpiecznyKomunikat::z($e->getMessage()),")),
    Kontrola("Skaner logów ślepy na report()", "tests/Feature/LogOperacyjnyBezKomunikatuWyjatkuTest.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "(?:logger|report)", "(?:logger)")),
]
