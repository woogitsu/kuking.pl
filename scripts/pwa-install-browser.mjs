import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

// Prawdziwy Laravel i jego baza. Wyłącznie API instalacji jest syntetyczne:
// ten pomiar nie dowodzi instalacji przez system operacyjny.
const fixture = String.raw`
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cfg = config('database.connections.'.config('database.default'));
if (!app()->environment(['local', 'testing']) || ($cfg['driver'] ?? '') !== 'pgsql'
    || !in_array($cfg['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
    || !preg_match('/^(kuking_port_[a-z0-9_]+|kuking_278_browser)$/D', $cfg['database'] ?? '')) {
    throw new RuntimeException('PWA: wymagana izolowana lokalna baza pomiarowa');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = App\Models\User::findByLogin($input['konto']);
if (!$user) throw new RuntimeException('PWA: brak konta pomiarowego');
$users = Illuminate\Support\Facades\DB::table('users')->where('id', $user->id);
$signals = Illuminate\Support\Facades\DB::table('product_signals')->where('user_id', $user->id)
    ->whereIn('signal_name', ['pwa_prompt_shown', 'pwa_install_requested', 'pwa_prompt_dismissed', 'pwa_installed']);
switch ($input['action']) {
case 'snapshot':
    echo json_encode(['state' => $user->pwa_prompt_state, 'ostatnio_widziany_at' => $users->value('ostatnio_widziany_at'),
        'signals' => $signals->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()]); break;
case 'reset':
    Illuminate\Support\Facades\DB::transaction(function () use ($users, $signals) {
        $signals->delete(); $users->update(['pwa_prompt_state' => 'eligible']);
    }); echo '{}'; break;
case 'read':
    echo json_encode(['state' => $users->value('pwa_prompt_state'),
        'signals' => $signals->orderBy('signal_name')->pluck('signal_name')->all()]); break;
case 'restore':
    Illuminate\Support\Facades\DB::transaction(function () use ($input, $users, $signals) {
        $signals->delete();
        if ($input['snapshot']['signals']) Illuminate\Support\Facades\DB::table('product_signals')->insert($input['snapshot']['signals']);
        $users->update(['pwa_prompt_state' => $input['snapshot']['state'], 'ostatnio_widziany_at' => $input['snapshot']['ostatnio_widziany_at']]);
    }); echo '{}'; break;
default: throw new RuntimeException('PWA: nieznana operacja');
}`;

async function syntheticPrompt(page, outcome = 'accepted') {
    await page.evaluate(outcome => {
        window.__pwaPromptCalls = 0;
        const event = new Event('beforeinstallprompt', { cancelable: true });
        event.userChoice = Promise.resolve({ outcome });
        event.prompt = () => { window.__pwaPromptCalls++; return Promise.resolve(); };
        window.dispatchEvent(event);
    }, outcome);
}

async function decision(page, action, trigger) {
    const response = page.waitForResponse(r => r.request().method() === 'POST'
        && r.request().postDataJSON()?.action === action);
    await trigger();
    const result = await response;
    assert.equal(result.status(), 200, `PWA_HTTP_${action}`);
    assert.equal((await result.json()).changed, true, `PWA_CHANGED_${action}`);
}

async function focusBounds(button, label) {
    const result = await button.evaluate(async button => {
        await document.fonts.ready;
        // Dekoracyjne animacje nieskończone i pauzowane nie mają końca.
        // Skończone przejścia muszą się uspokoić w ograniczonym czasie.
        const animations = document.getAnimations().filter(a => a.playState === 'running'
            && Number.isFinite(a.effect?.getComputedTiming().endTime));
        let timer;
        try {
            await Promise.race([
                Promise.all(animations.map(a => a.finished.catch(() => {}))),
                new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('PWA_ANIMACJE_NIEUSTABILIZOWANE')), 3000); }),
            ]);
        } finally { clearTimeout(timer); }
        const r = button.getBoundingClientRect(), css = getComputedStyle(button);
        // Oba przyciski systemowe mają pierścień 0 0 0 5px; nie uznajemy
        // samego focus-visible ani dowolnego cienia za widoczny fokus.
        const ring = css.boxShadow.includes('0px 0px 0px 5px');
        const bounds = { left: r.left - 5, top: r.top - 5, right: r.right + 5, bottom: r.bottom + 5 };
        let clipped = false;
        for (let parent = button.parentElement; parent; parent = parent.parentElement) {
            const s = getComputedStyle(parent), p = parent.getBoundingClientRect();
            if (/(hidden|clip|auto|scroll)/.test(s.overflowX) && (bounds.left < p.left || bounds.right > p.right)) clipped = true;
            if (/(hidden|clip|auto|scroll)/.test(s.overflowY) && (bounds.top < p.top || bounds.bottom > p.bottom)) clipped = true;
        }
        // Środki krawędzi leżą wewnątrz także przy przycisku kapsułkowym;
        // narożniki prostokąta mogą prawidłowo należeć do tła.
        const midX = (r.left + r.right) / 2, midY = (r.top + r.bottom) / 2;
        const points = [[r.left + 5, midY], [r.right - 5, midY],
            [midX, r.top + 5], [midX, r.bottom - 5], [midX, midY]];
        const ringPoints = [[r.left - 4, (r.top + r.bottom) / 2], [r.right + 4, (r.top + r.bottom) / 2],
            [(r.left + r.right) / 2, r.top - 4], [(r.left + r.right) / 2, r.bottom + 4]];
        return { ...bounds, width: innerWidth, height: innerHeight, ring, clipped,
            focused: button.matches(':focus-visible'), targetHeight: r.height,
            covered: points.some(([x, y]) => !button.contains(document.elementFromPoint(x, y)))
                || ringPoints.some(([x, y]) => { const hit = document.elementFromPoint(x, y); return !hit || (!hit.contains(button) && !button.contains(hit)); }),
            overflow: document.documentElement.scrollWidth > innerWidth + 1 };
    });
    assert(result.focused && result.ring && !result.clipped && !result.covered && !result.overflow,
        `PWA_FOCUS ${label} ${JSON.stringify(result)}`);
    assert(result.left >= 0 && result.top >= 0 && result.right <= result.width && result.bottom <= result.height
        && result.targetHeight >= 48, `PWA_BOUNDS ${label} ${JSON.stringify(result)}`);
}

/** Uruchamiać szeregowo, przy wyłącznym użyciu wskazanej bazy i konta. */
export async function sprawdzInstalacjePwa({ browser, adres, sesja, phpEnv, konto = 'ania' }) {
    assert(['localhost', '127.0.0.1', '[::1]'].includes(new URL(adres).hostname), 'PWA: wymagany lokalny HTTP');
    const db = (action, extra = {}) => JSON.parse(execFileSync('php', ['-r', fixture], {
        env: phpEnv, input: JSON.stringify({ action, konto, ...extra }), encoding: 'utf8',
    }));
    const snapshot = db('snapshot');
    let context;
    let count = 0;
    try {
        db('reset');
        context = await browser.newContext({ storageState: sesja, viewport: { width: 320, height: 900 } });
        const matrixPage = await context.newPage();
        await matrixPage.goto(adres + '/home');
        const panel = matrixPage.locator('[data-pwa-install]');
        assert.equal(await panel.count(), 1, 'PWA_BRAK_KOMPONENTU');
        assert.equal(await panel.isVisible(), false, 'PWA_BEZ_API');
        await decision(matrixPage, 'offer', () => syntheticPrompt(matrixPage));
        await panel.waitFor({ state: 'visible' });
        assert.equal(db('read').state, 'offered', 'PWA_OFFER_DB');
        // Jedna realna propozycja, 24 rozmiary tej samej strony. Nie obchodzimy
        // limitera i nie generujemy 24 jednorazowych decyzji tego samego konta.
        for (const width of [320, 360, 390, 414, 768, 1440]) for (const theme of ['light', 'dark']) for (const scale of [100, 140]) {
            const page = matrixPage;
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(({ theme, scale }) => {
                document.documentElement.dataset.theme = theme;
                document.documentElement.dataset.textScale = String(scale);
            }, { theme, scale });
            // Jawny punkt startowy przed panelem; oba przyciski osiągamy Tab.
            await page.locator('.marka-publikacja a').last().focus();
            const found = new Set();
            for (let tab = 0; tab < 100 && found.size < 2; tab++) {
                await page.keyboard.press('Tab');
                for (const name of ['accept', 'dismiss']) {
                    const button = panel.locator(`[data-pwa-${name}]`);
                    if (await button.evaluate(el => el === document.activeElement)) {
                        await focusBounds(button, `${width}/${theme}/${scale}/${name}`);
                        found.add(name);
                    }
                }
            }
            assert.equal(found.size, 2, 'PWA_TAB_OBA_PRZYCISKI');
            assert(await panel.locator('[data-pwa-dismiss]').evaluate(el => el === document.activeElement), 'PWA_TAB_KOLEJNOSC');
            count++;
        }
        assert.equal(count, 24);
        await decision(matrixPage, 'dismiss', () => matrixPage.keyboard.press('Enter'));
        await panel.waitFor({ state: 'hidden' });
        assert.deepEqual(db('read'), { state: 'dismissed', signals: ['pwa_prompt_dismissed', 'pwa_prompt_shown'] }, 'PWA_DISMISS_DB');
        assert(await matrixPage.evaluate(() => document.activeElement !== document.body && !document.activeElement.closest('[hidden]')), 'PWA_FOCUS_PO_ZAMKNIECIU');
        await matrixPage.reload();
        assert.equal(await matrixPage.locator('[data-pwa-install]').count(), 0, 'PWA_ODMOWA_PO_RELOAD');
        await context.close(); context = null;
        db('reset');
        context = await browser.newContext({ storageState: sesja, viewport: { width: 390, height: 900 } });
        const page = await context.newPage();
        await page.goto(adres + '/home');
        await decision(page, 'offer', () => syntheticPrompt(page));
        await decision(page, 'request', () => page.locator('[data-pwa-accept]').click());
        await page.locator('[data-pwa-install]').waitFor({ state: 'hidden' });
        assert.equal(await page.evaluate(() => window.__pwaPromptCalls), 1, 'PWA_PROMPT_RAZ');
        const requested = db('read');
        assert.equal(requested.state, 'requested', 'PWA_ACCEPTED_NIE_INSTALLED');
        assert(!requested.signals.includes('pwa_installed'), 'PWA_FALSZYWA_INSTALACJA');
        await decision(page, 'installed', () => page.evaluate(() => window.dispatchEvent(new Event('appinstalled'))));
        assert.equal(db('read').state, 'installed', 'PWA_INSTALLED_DB');
        assert.equal(db('read').signals.filter(s => s === 'pwa_installed').length, 1, 'PWA_INSTALLED_METRYKA');
        await context.close(); context = null;
        db('reset');
        context = await browser.newContext({ storageState: sesja });
        const cleanupPage = await context.newPage();
        await cleanupPage.goto(adres + '/home');
        await cleanupPage.evaluate(() => document.dispatchEvent(new Event('livewire:navigating')));
        let writes = 0;
        cleanupPage.on('request', r => { if (r.method() === 'POST') writes++; });
        await syntheticPrompt(cleanupPage);
        await cleanupPage.evaluate(() => window.dispatchEvent(new Event('appinstalled')));
        // Kontrola negatywna wymaga okna obserwacji, nie czekania na zdarzenie, którego ma nie być.
        await cleanupPage.waitForTimeout(300);
        assert.equal(writes, 0, 'PWA_CLEANUP_LISTENERS');
        assert.equal(db('read').state, 'eligible', 'PWA_CLEANUP_DB');
        console.log('PWA: 24/24, Tab, HTTP/DB/reload, accepted != installed, cleanup PASS (API instalacji syntetyczne).');
    } finally {
        try { await context?.close(); }
        finally {
            db('restore', { snapshot });
            assert.deepEqual(db('snapshot'), snapshot, 'PWA_PRZYWROCENIE_FIXTURE');
        }
    }
}
