// #2025: alarm po wdrożeniu. To nie jest bramka przed zmianą ruchu.
export function czyCiByloZielonePrzedDeployem(runs, sha, wdrozoneO) {
    const granica = Date.parse(wdrozoneO);
    if (!/^[0-9a-f]{40}$/i.test(sha) || !Number.isFinite(granica)) return false;

    return runs.some((run) => run.name === 'CI'
        && run.event === 'push'
        && run.head_sha === sha
        && run.status === 'completed'
        && run.conclusion === 'success'
        && Number.isFinite(Date.parse(run.updated_at))
        && Date.parse(run.updated_at) <= granica);
}

async function sprawdz() {
    const { GITHUB_TOKEN: token, GITHUB_REPOSITORY: repo, DEPLOY_SHA: sha,
        DEPLOYED_AT: wdrozoneO, DEPLOY_ENV: srodowisko } = process.env;
    // Railway raportuje „nazwa projektu / production”. Preview i staging
    // nie są przedmiotem tej kontroli.
    if (srodowisko?.split('/').at(-1)?.trim() !== 'production') return;
    if (!token || !/^[^/]+\/[^/]+$/.test(repo || '')) throw new Error('Brak tokenu lub repozytorium GitHub.');

    const url = new URL(`/repos/${repo}/actions/runs`, process.env.GITHUB_API_URL || 'https://api.github.com');
    url.searchParams.set('head_sha', sha);
    url.searchParams.set('event', 'push');
    url.searchParams.set('per_page', '100');
    const response = await fetch(url, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/vnd.github+json',
            'X-GitHub-Api-Version': '2022-11-28' },
    });
    if (!response.ok) throw new Error(`Nie udało się odczytać CI z GitHub API: HTTP ${response.status}.`);
    const { workflow_runs: runs } = await response.json();
    if (!Array.isArray(runs)) throw new Error('GitHub API nie zwróciło listy przebiegów CI.');
    if (!czyCiByloZielonePrzedDeployem(runs, sha, wdrozoneO)) {
        throw new Error(`Produkcja wdrożyła ${sha}, choć CI nie miało sukcesu przed ${wdrozoneO}. Zobacz issue #2025.`);
    }
    console.log(`CI dla ${sha} było zielone przed wdrożeniem produkcyjnym.`);
}

if (process.argv[1]?.endsWith('ci-po-wdrozeniu.mjs')) {
    sprawdz().catch((error) => { console.error(`::error title=Produkcja bez zielonego CI::${error.message}`); process.exitCode = 1; });
}
