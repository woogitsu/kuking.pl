# Pobranie wyłącznie publicznych tekstów wskazanych przez właściciela.
# Nie podążamy za adresami cudzych blogów, nie pobieramy zdjęć ani profili.
$ErrorActionPreference = 'Stop'
$landing = Invoke-WebRequest -Uri 'https://kuking.pl/odkryj' -UseBasicParsing
$articles = [regex]::Matches($landing.Content, '(?s)<article\b.*?</article>')
$cases = @()
foreach ($article in $articles) {
    $url = [regex]::Match($article.Value, 'href="(https://kuking.pl/wpisy/[a-f0-9-]+)"').Groups[1].Value
    if (-not $url -or $cases.source -contains $url) { continue }
    $detail = Invoke-WebRequest -Uri $url -UseBasicParsing
    $body = [regex]::Match($detail.Content, '(?s)<div class="post-card-body">(.*?)</div>').Groups[1].Value
    if (-not $body) { throw "Brak treści na stronie wpisu; popraw selektor przed pomiarem." }
    $text = [Net.WebUtility]::HtmlDecode([regex]::Replace($body, '<[^>]+>', ''))
    # Adresy zewnętrznych źródeł zostają poza wejściem modelu; żadnego importu.
    $text = [regex]::Replace($text, 'https?://\S+', '').Trim()
    if ($text.Length -gt 4000) { throw 'Opis przekracza limit. Wybierz go ręcznie, bez cichego obcinania.' }
    $cases += [ordered]@{ id = 'P{0:d2}' -f ($cases.Count + 1); text = $text; source = $url; provenance = 'publiczny wpis wskazany przez właściciela; autorstwo przepisu niezweryfikowane'; transformation = 'usunięto znaczniki HTML i zewnętrzne URL; zachowano tekst i pisownię'; fetched_utc = [DateTime]::UtcNow.ToString('o') }
}
if ($cases.Count -lt 12) { throw 'Zbyt mały zbiór: sprawdź faktyczne pobranie wpisów.' }
$target = Join-Path $PSScriptRoot 'corpus/public-recipe.json'
[IO.File]::WriteAllText($target, (ConvertTo-Json -InputObject $cases -Depth 8), [Text.UTF8Encoding]::new($false))
Write-Output "Pobrano publiczne opisy: $($cases.Count). Zdjęcia i profile: 0."
