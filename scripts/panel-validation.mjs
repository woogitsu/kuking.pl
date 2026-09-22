/* #581: Real HTTP server validation on an isolated fixture.
 * POST uses context.request: native browser validation is not under test.
 * Snapshot callback reads all seven domain tables before/after every POST.
 * Full rows (including credentials) remain in memory and never enter reports.
 * The runner owns database isolation and excludes concurrent writers.
 * Allowed environments and database names: panel-validation-isolation.mjs.
 */
import { validationIsolation } from './panel-validation-isolation.mjs';
import { isDeepStrictEqual } from 'node:util';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const read = value => typeof value === 'string' ? JSON.parse(readFileSync(value, 'utf8')) : value;
const requireThat = (condition, code) => { if (!condition) throw new Error(code); };
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value);
const scenarios = [
  { name: 'report-custom', family: 'report', payload: { action: 'suspend', suspend_days: 'wlasny', suspend_days_custom: '0', reason_code: '', note: 'Kontrola lokalna A', user_message: 'Kontrola lokalna B' }, errors: ['reason_code', 'suspend_days_custom'], inline: 2 },
  { name: 'report-no-term', family: 'report', payload: { action: 'suspend', suspend_days: 'brak', reason_code: 'spam-reklama', note: 'Kontrola lokalna C' }, errors: ['suspend_days'], inline: 1 },
  { name: 'restore-no-reason', family: 'restore', payload: { reason_code: '', user_message: 'Kontrola lokalna D' }, errors: ['reason_code'], inline: 1 },
  { name: 'appeal-short', family: 'appeal', payload: { outcome: 'overturned', decision_note: 'test' }, errors: ['decision_note'], inline: 1 },
  { name: 'appeal-no-outcome', family: 'appeal', payload: { decision_note: 'Kontrolowane lokalne uzasadnienie.' }, errors: ['outcome'], inline: 1 },
];
const messages = {
  reason_code: 'Wybierz podstawę decyzji — autor treści zobaczy ją w powiadomieniu.',
  restore: 'Podaj powód przywrócenia — w logu musi zostać ślad, dlaczego zdjęto ukrycie.',
  suspend_days_custom: 'Najkrótsze zawieszenie to 1 dzień. Jeśli chcesz tylko zwrócić uwagę, wybierz decyzję „Ostrzeżenie".',
  suspend_days: 'Przy decyzji „Zawieś konto" zaznacz jeszcze, na jak długo. „Bez zawieszenia" znaczy, że kary nie ma.',
  decision_note: 'Uzasadnienie ma być zdaniem, nie jednym słowem.',
  outcome: 'Wybierz, czy podtrzymujesz decyzję, czy ją cofasz.',
};

export async function runCandidate({ browser, origin, session, fullManifest, statesManifest, snapshot, reportPath, appearance, inspectValidation }) {
  const isolation = validationIsolation(process.env);
  const rows = [];
  let context;
  let completed = false;
  try {
    const base = new URL(origin);
    requireThat(base.origin === origin && base.hostname === '127.0.0.1' && base.protocol === 'http:' && base.port !== '' && !base.username && !base.password, 'LOCAL_ORIGIN');
    requireThat(typeof snapshot === 'function' && typeof reportPath === 'string' && !existsSync(reportPath), 'CALLBACK_AND_FRESH_REPORT');
    const full = read(fullManifest), states = read(statesManifest);
    requireThat(full.phase === 'pelny' && states.phase === 'stany' && full.database === isolation.database && states.database === full.database && /^panel581-[a-f0-9]{12}$/.test(full.namespace) && states.marker === 'panel-stany-' + full.namespace, 'MANIFESTS');
    const ids = { report: [full.dane.report, states.ids.second_open_report], appeal: [full.dane.appeal, states.ids.second_open_appeal], restore: [states.ids.resolved_report] };
    requireThat(Object.values(ids).flat().every(uuid) && new Set(Object.values(ids).flat()).size === 5, 'MANIFEST_IDS');
    const requiredIds = {
      reports: [...ids.report, ...ids.restore],
      appeals: [...ids.appeal, states.ids.closed_appeal],
      posts: [states.ids.hidden_post, states.ids.open_post],
      users: [full.konto?.id, full.bramka?.id, full.dane.author],
    };
    requireThat(Object.values(requiredIds).flat().every(uuid), 'SNAPSHOT_FIXTURE_IDS');
    const take = async () => {
      const value = await snapshot();
      const i = value?.isolation;
      requireThat(i?.host === '127.0.0.1' && String(i.port) === isolation.port && i.database === full.database && ['local', 'testing'].includes(i.appEnv) && i.mailer === 'array', 'ACTUAL_ISOLATION');
      const domain = value.domain;
      requireThat(domain && typeof domain === 'object' && !Array.isArray(domain), 'SNAPSHOT_DOMAIN');
      for (const key of ['reports', 'appeals', 'posts', 'users', 'moderation_actions', 'notifications', 'audit']) {
        requireThat(Array.isArray(domain[key]) && domain[key].every(row => row && typeof row === 'object' && !Array.isArray(row) && ['string', 'number'].includes(typeof row.id)), 'SNAPSHOT_SECTION');
        requireThat(new Set(domain[key].map(row => String(row.id))).size === domain[key].length, 'SNAPSHOT_DUPLICATES');
      }
      const contains = (section, id) => typeof id === 'string' && domain[section].some(row => String(row.id) === id);
      for (const [section, expected] of Object.entries(requiredIds)) requireThat(expected.every(id => contains(section, id)), 'SNAPSHOT_MISSING_FIXTURE');
      for (const reportId of requiredIds.reports) {
        const report = domain.reports.find(row => String(row.id) === reportId);
        requireThat(report.target_type === 'post' && contains('posts', report.target_id), 'SNAPSHOT_REPORT_TARGET');
      }
      for (const appealId of requiredIds.appeals) {
        const appeal = domain.appeals.find(row => String(row.id) === appealId);
        requireThat(contains('moderation_actions', appeal.moderation_action_id) && contains('users', appeal.user_id), 'SNAPSHOT_APPEAL_RELATIONS');
      }
      requireThat(domain.moderation_actions.some(row => row.report_id === states.ids.resolved_report && row.target_id === states.ids.hidden_post && row.action === 'hide'), 'SNAPSHOT_RESTORE_ACTION');
      return structuredClone(value.domain);
    };
    await take();
    context = await browser.newContext({ storageState: read(session), serviceWorkers: 'block', ...(appearance ? {viewport:{width:appearance.width,height:900},reducedMotion:'reduce'} : {}) });
    // APIRequestContext nie podlega route(); jego jedyny POST ma jawny, sprawdzony URL.
    await context.route('**/*', route => new URL(route.request().url()).origin === origin && ['GET', 'HEAD'].includes(route.request().method()) ? route.continue() : route.abort());
    const page = await context.newPage();
    if (appearance) await page.addInitScript(({theme,scale}) => document.addEventListener('DOMContentLoaded', () => { document.documentElement.dataset.theme=theme; document.documentElement.dataset.textScale=String(scale); }),appearance);
    for (const scenario of scenarios) for (const [rowIndex, id] of ids[scenario.family].entries()) {
      const isAppeal = scenario.family === 'appeal';
      const list = origin + (isAppeal ? '/admin/odwolania?status=open' : '/admin/zgloszenia?status=' + (scenario.family === 'restore' ? 'resolved' : 'open'));
      const action = origin + (isAppeal ? '/admin/odwolania/' : '/admin/zgloszenia/') + id + (scenario.family === 'restore' ? '/przywroc' : '');
      const row = { scenario: scenario.name, row: rowIndex + 1, expectedErrors: scenario.errors, pass: false };
      rows.push(row);
      const response = await page.goto(list);
      requireThat(response?.status() === 200 && page.url() === list, 'GET_AUTHENTICATED_LIST');
      const formFor = value => page.locator('main form').filter({ has: page.locator('input[name="_wiersz"][value="' + value + '"]') });
      const form = formFor(id);
      requireThat(await form.count() === 1 && new URL(await form.getAttribute('action'), origin).href === action, 'FORM_CONTRACT');
      const siblingId = ids[scenario.family].find(other => other !== id);
      const formState = locator => locator.evaluate(el => [...el.querySelectorAll('input,select,textarea')].filter(e => !['_token', '_wiersz'].includes(e.name)).map(e => ({ name: e.name, value: e.value, checked: e.checked ?? null })));
      const siblingBefore = siblingId ? await formState(formFor(siblingId)) : null;
      const csrf = await form.locator('input[name="_token"]').inputValue();
      requireThat(csrf.length > 0, 'CSRF_MISSING');
      const before = await take();
      try {
        const post = await context.request.post(action, { form: { ...scenario.payload, _wiersz: id, _token: csrf }, headers: { Accept: 'text/html', Referer: list, Origin: origin }, maxRedirects: 0 });
        row.httpStatus = post.status();
        const retryAfter = post.headers()['retry-after'];
        row.retryAfter = /^\d+$/.test(retryAfter || '') && Number.isSafeInteger(Number(retryAfter)) ? Number(retryAfter) : null;
        requireThat(post.status() === 302 && new URL(post.headers().location || '', action).href === list, 'VALIDATION_REDIRECT');
        const returned = await page.goto(list);
        requireThat(returned?.status() === 200 && page.url() === list && (returned.headers()['content-type'] || '').includes('text/html'), 'REDIRECT_HTML');
        const summary = page.locator('main .error-summary');
        requireThat(await summary.count() === 1, 'SUMMARY_COUNT');
        const errors = await summary.locator('li').allTextContents();
        const expected = scenario.errors.map(key => messages[scenario.family === 'restore' ? 'restore' : key]);
        requireThat(isDeepStrictEqual(errors.map(s => s.trim()).sort(), expected.sort()), 'EXPECTED_ERRORS');
        requireThat(await form.locator('.field-error').count() === scenario.inline, 'INLINE_ERRORS');
        for (const [name, value] of Object.entries(scenario.payload)) {
          const field = form.locator('[name="' + name + '"]');
          if (await field.first().getAttribute('type') === 'radio') requireThat(await field.evaluateAll((nodes, selected) => nodes.filter(n => n.checked).length === 1 && nodes.some(n => n.checked && n.value === selected), value), 'OLD_RADIO');
          else requireThat(await field.inputValue() === value, 'OLD_VALUE');
        }
        if (siblingId) requireThat(isDeepStrictEqual(await formState(formFor(siblingId)), siblingBefore) && await formFor(siblingId).locator('.field-error').count() === 0, 'SIBLING_ISOLATION');
        // Odwołania używają wspólnego podsumowania z odnośnikami. Zgłoszenia
        // mają świadomie płaską listę (inne konwencje ID ręcznych pól).
        if (isAppeal) {
          const hrefs = await summary.locator('a').evaluateAll(nodes => nodes.map(n => n.getAttribute('href')).sort());
          requireThat(isDeepStrictEqual(hrefs, scenario.errors.map(key => '#f-' + key + '-' + id).sort()), 'ERROR_LINK_COMPLETENESS');
        }
        // Każdy istniejący link musi prowadzić do pola aktywnego formularza.
        for (const link of await summary.locator('a').all()) {
          const target = await link.getAttribute('href');
          requireThat(target?.startsWith('#'), 'ERROR_LINK_LOCAL');
          const control = page.locator('[id="' + target.slice(1) + '"]');
          requireThat(await control.count() === 1 && await control.evaluate(e => !!e.closest('form')), 'ERROR_LINK_TARGET');
          requireThat(await form.evaluate((e, id) => e.contains(document.getElementById(id)), target.slice(1)), 'ERROR_LINK_ROW');
          await link.click();
          requireThat(await control.evaluate(e => e === document.activeElement), 'ERROR_LINK_FOCUS');
        }
        if (scenario.name === 'appeal-no-outcome') {
          requireThat(await form.locator('[name="outcome"][aria-invalid="true"]').count() === 2, 'OUTCOME_ARIA');
          requireThat(await form.locator('[name="outcome"]').evaluateAll(nodes => nodes.every(n => {
            const error = document.getElementById(n.getAttribute('aria-describedby'));
            return error && n.form.contains(error) && error.classList.contains('field-error');
          })), 'OUTCOME_ERROR_ASSOCIATION');
        }
        if(inspectValidation) await inspectValidation({page, form, summary, scenario:scenario.name, rowIndex});
      } finally {
        requireThat(isDeepStrictEqual(before, await take()), 'DOMAIN_CHANGED');
      }
      row.pass = true;
    }
    requireThat(rows.length === 9 && rows.every(r => r.pass), 'CASE_COUNT');
    completed = true;
    return { status: 'executed', mode: 'HTTP server validation; native browser validation not tested', rows };
  } catch (error) {
    // Nigdy nie wypisuj wyjatkow Playwright/assertion: moga zawierac token/URL/HTML.
    throw new Error(/^[A-Z_]+$/.test(error?.message || '') ? error.message : 'CANDIDATE_FAILED');
  } finally {
    await context?.close();
    if (typeof reportPath === 'string' && !existsSync(reportPath)) writeFileSync(reportPath, JSON.stringify({ pass: completed, complete: completed, scope: '9 invalid server POST; geometry and native validation require separate acceptance', rows }, null, 2), { mode: 0o600, flag: 'wx' });
  }
}
