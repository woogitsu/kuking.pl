"""Kontrole ujemne #895–900; uruchamiaj w izolowanym runtime po zwykłych testach.

Przywracanie źródła, MD5 i mtime zapewnia scripts/kontrola-ujemna.sh.
Parametry bazy muszą być jawnie ustawione przez środowisko stanowiska.
"""

import os
from pathlib import Path
import subprocess


database = os.environ.get("DB_DATABASE", "")
if not database.startswith(("kuking_flota_", "kuking_race")):
    raise SystemExit("Wybierz własną bazę floty albo wyścigów.")

root = Path(__file__).resolve().parent.parent
os.chdir(root)
output = root / "storage/kontrole-wersji"
output.mkdir(parents=True, exist_ok=True)


def check(name, file, before, after, expected, *test_args):
    source = Path(file).read_text()
    if source.count(before) != 1:
        raise SystemExit(f"Odmowa: {name}, wzorzec występuje {source.count(before)} razy.")
    subprocess.run([
        "bash", "scripts/kontrola-ujemna.sh", "--nazwa", name,
        "--plik", file, "--zamien", before, "--na", after,
        "--oczekuj", expected, "--json", str(output / f"{name}.json"),
        # Przywrócenie mtime jest celowe; Blade wymaga wtedy usunięcia
        # skompilowanego widoku z mutacji przed KAŻDĄ fazą pomiaru.
        "--", "bash", "-c", 'php artisan view:clear >/dev/null && exec php vendor/bin/phpunit --colors=never "$@"',
        "kontrola-wersji", *test_args,
    ], check=True)


publish = "app/Domain/Recipes/Actions/PublishRecipe.php"
source = Path(publish).read_text()
start = source.index("            if ($publish) {\n                $this->snapshots->handle")
end = source.index("\n            return $recipe;", start)
block = source[start:end]
mutated = source[:start] + source[end:]
outside = "\n".join(line[4:] if line.startswith("    ") else line for line in block.splitlines())
mutated = mutated.replace("        return $recipe;\n    }", outside + "\n        return $recipe;\n    }", 1)
check("895-granica-transakcji", publish, source, mutated, "Przed zmian|Entries found: 1", "tests/Feature/PublikacjaPrzepisuAtomowaTest.php")

snapshot = "app/Domain/Recipes/Actions/SnapshotRecipeVersion.php"
check("895-blokada-numerowania", snapshot, "->lockForUpdate()", "", "23505", "--group=dwa-polaczenia", "tests/Dwa/PublikacjaPrzepisuWyscigTest.php")
check("896-adres", snapshot, "                    'source_url' => $recipe->source_url,", "", "source_url", "tests/Feature/MigawkaPrzepisuPelnaTest.php")
check("896-bez-ilosci", snapshot, "                        'no_amount' => $i->no_amount,", "", "no_amount", "tests/Feature/MigawkaPrzepisuPelnaTest.php")
check("898-pole-uwagi", "resources/views/pages/recipes/szczegoly.blade.php", 'name="ingredients[{{ $i }}][note]"', 'data-pominiete="note"', "PROBA_UWAGI", "tests/Feature/UwagiSkladnikowFormularzTest.php")
check("900-protokol", "app/Http/Controllers/RecipeController.php", "'url:http,https'", "'url'", "Session is missing expected key", "tests/Feature/AdresZrodlaPrzepisuTest.php")
check("900-link", "resources/views/pages/recipes/show.blade.php", "\\Illuminate\\Support\\Str::isUrl($recipe->source_url, ['http', 'https'])", "true", 'href="ftp:', "tests/Feature/AdresZrodlaPrzepisuTest.php")
