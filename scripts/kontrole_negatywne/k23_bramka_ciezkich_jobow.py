"""Ciężkie joby wąskiego obszaru zawężane TYLKO na PR-ach (decyzja 24.09.2026).

Strażnicy pytają prawdziwy skrypt bramki z `ci.yml`; mutacje dowodzą, że
zapalają się w obie strony: gdy job wypada przy zmianie własnego wejścia,
gdy zawężenie przecieka poza PR i gdy wzorzec przestaje cokolwiek zawężać.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


BRAMKA_CI = ".github/workflows/ci.yml"
BRAMKA_WEJSCIA_TEST = "test_ciezki_job_rusza_przy_zmianie_kazdego_pliku_ktory_czyta"
BRAMKA_POZA_PR_TEST = "test_poza_pull_requestem_kazdy_job_rusza_przy_zmianie_kodu"
BRAMKA_OBOK_TEST = "test_na_pull_requescie_zmiana_obok_pomija_ciezkie_joby"
WIDOK_POZA_PR_TEST = "test_poza_pull_requestem_filtr_widoku_nie_zaweza"


def dockerfile_poza_wzorcem_obrazu(source):
    """KONTROLA DODATNIA: `Dockerfile` wypada ze wzorca `obraz`.

    Build obrazu byłby pomijany na PR-ze zmieniającym sam Dockerfile, a ten
    plik strażnik czyta z `ci.yml` jako wejście joba — ma zapalić.
    """
    return replace_once(source, "ciezki obraz '^(Dockerfile$|", "ciezki obraz '^(")


def grupa_wyscigow_poza_wzorcem(source):
    """KONTROLA DODATNIA: `tests/Dwa/` wypada ze wzorca `wyscigi`.

    Pliki grupy strażnik zbiera z dysku (atrybut `#[Group(...)]` grupy
    wołanej przez `--group=` w skrypcie joba) — ma zapalić.
    """
    return replace_once(source, "tests/(Dwa/|Support/|", "tests/(Support/|")


def zawezanie_takze_poza_pr(source):
    """KONTROLA DODATNIA: ciężkie joby zawężane także na `main`.

    Bez warunku na zdarzenie push na `main` pomijałby build obrazu, przyrząd
    #605 i wyścigi przy zmianie obok ich obszaru — wbrew decyzji właściciela.
    """
    return replace_once(
        source,
        'if [ "${ZDARZENIE:-}" != "pull_request" ] || grep -Eq "$2" <<< "${ZMIENIONE}"; then',
        'if grep -Eq "$2" <<< "${ZMIENIONE}"; then',
    )


def wzorzec_przyrzadu_lapie_wszystko(source):
    """KONTROLA UJEMNA ZAWĘŻENIA: wzorzec `obciazenie` pasuje do każdej ścieżki.

    Kontrole „job rusza" przeszłyby wtedy śpiewająco, a oszczędności nie ma.
    Strażnik zmiany obok ma zapalić.
    """
    return replace_once(source, "ciezki obciazenie '^(scripts/", "ciezki obciazenie '^(|scripts/")


def widok_zawezany_poza_pr(source):
    """KONTROLA DODATNIA: filtr widoku zawęża także na `main`."""
    return replace_once(
        source,
        """if [ "${ZDARZENIE:-}" != "pull_request" ] || grep -qE '""",
        """if grep -qE '""",
    )


KONTROLE_DODATNIE = [BRAMKA_WEJSCIA_TEST, BRAMKA_POZA_PR_TEST, BRAMKA_OBOK_TEST, WIDOK_POZA_PR_TEST]

KONTROLE = [
    Kontrola("Dockerfile poza wzorcem builda obrazu", BRAMKA_CI, BRAMKA_WEJSCIA_TEST,
             dockerfile_poza_wzorcem_obrazu,
             oczekuj=r"Bramka pomija job `docker-build` na PR-ze przy zmianie `Dockerfile`"),
    Kontrola("Pliki grupy wyścigów poza wzorcem joba", BRAMKA_CI, BRAMKA_WEJSCIA_TEST,
             grupa_wyscigow_poza_wzorcem,
             oczekuj=r"Bramka pomija job `dwa-polaczenia` na PR-ze przy zmianie `tests/Dwa/"),
    Kontrola("Ciężkie joby zawężane także poza PR-em", BRAMKA_CI, BRAMKA_POZA_PR_TEST,
             zawezanie_takze_poza_pr,
             oczekuj=r"Job `[\w-]+` jest pomijany poza PR-em"),
    Kontrola("Wzorzec przyrządu #605 łapie każdą zmianę", BRAMKA_CI, BRAMKA_OBOK_TEST,
             wzorzec_przyrzadu_lapie_wszystko,
             oczekuj=r"Bramka uruchamia `przyrzad_605` na PR-ze przy zmianie"),
    Kontrola("Filtr widoku zawężany także poza PR-em", BRAMKA_CI, WIDOK_POZA_PR_TEST,
             widok_zawezany_poza_pr,
             oczekuj=r"zakres: filtr widoku zawęża poza PR-em"),
]
