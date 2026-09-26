"""Preview i IaC nie zgadują stanu (#1389, #1390). Przeniesione z monolitu po podziale.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


# Preview i IaC nie zgadują stanu (#1389, #1390). Strażnik czyta workflow
# i railway.ts; mutacje przywracają: test dymny bez czekania na `success`,
# bez sondy wydania, apply bez przekazanej bramki CI i `=== "true"`.
PREVIEW_WORKFLOW = ".github/workflows/preview.yml"
IAC_WORKFLOW = ".github/workflows/railway-iac.yml"
IAC_RAILWAY_TS = ".railway/railway.ts"
PREVIEW_IAC_TEST = "PreviewIIacNieZgadujaStanuTest"
IAC_BRAMKA_ENV = "    env:\n      KUKING_WAIT_FOR_CI: ${{ vars.KUKING_WAIT_FOR_CI }}\n"


def apply_bez_bramki_ci(source):
    """KONTROLA DODATNIA: zdejmij mapowanie `KUKING_WAIT_FOR_CI` z joba apply.

    Plan i apply mają je po razie; mutacja zdejmuje drugie (apply), czyli
    dokładnie ścieżkę, która po scaleniu wyłączała „Wait for CI” (#1390).
    """
    if source.count(IAC_BRAMKA_ENV) != 2:
        raise RuntimeError("Kontrola nie znalazła dokładnie dwóch mapowań bramki CI.")
    poczatek = source.rindex(IAC_BRAMKA_ENV)
    return source[:poczatek] + source[poczatek + len(IAC_BRAMKA_ENV):]


KONTROLE_DODATNIE = [PREVIEW_IAC_TEST]

KONTROLE = [
    Kontrola("Preview gotowe po samym adresie", PREVIEW_WORKFLOW, PREVIEW_IAC_TEST,
     lambda s: replace_once(s, 'if ! czekaj_na_preview "$REPO" "$SHA"; then', 'if false; then')),
    Kontrola("Preview bez sondy wydania przed sprawdzeniami", PREVIEW_WORKFLOW, PREVIEW_IAC_TEST,
     lambda s: replace_once(s, 'if ! sonda_wydanie "$BASE_URL" "$OCZEKIWANY_SHA"; then', 'if false; then')),
    Kontrola("Apply IaC bez przekazanej bramki CI", IAC_WORKFLOW, PREVIEW_IAC_TEST,
     apply_bez_bramki_ci),
    Kontrola("Brak KUKING_WAIT_FOR_CI jako wyłączenie", IAC_RAILWAY_TS, PREVIEW_IAC_TEST,
     lambda s: replace_once(s, 'if (bramkaCI !== "true" && bramkaCI !== "false") {', 'if (false) {')),
]
