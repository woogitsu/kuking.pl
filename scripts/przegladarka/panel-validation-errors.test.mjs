import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { liczBledyWalidacji } from '../panel-validation.mjs';

// #2377/#581: po dołożeniu hostów mikrofonu panel ma nadal liczyć dokładnie
// błędy walidacji serwera, również gdy status dyktowania ma klasę field-error.
test('dwa błędy walidacji i dwa ukryte statusy dyktowania nie maskują brakującego błędu', async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.setContent(`
      <form id="aktywny">
        <span class="field-error" id="blad-podstawy">Wybierz podstawę.</span>
        <span class="field-error" id="blad-czasu">Podaj czas.</span>
        <div data-dyktowanie><p class="field-error dyktowanie-blad" hidden></p></div>
        <div data-dyktowanie><p class="field-error dyktowanie-blad" hidden></p></div>
      </form>
      <form id="sasiedni">
        <div data-dyktowanie><p class="field-error dyktowanie-blad" hidden></p></div>
      </form>
    `);
    const form = page.locator('#aktywny');
    const sprawdzDwa = async () => assert.equal(await liczBledyWalidacji(form), 2);

    assert.equal(await form.locator('.field-error').count(), 4, 'Fixture zawiera oba statusy dyktowania.');
    await sprawdzDwa();
    assert.equal(await liczBledyWalidacji(page.locator('#sasiedni')), 0);

    // Kontrola ujemna: usunięcie prawdziwego błędu nadal oblewa tę samą asercję.
    await page.locator('#blad-czasu').evaluate(element => element.remove());
    await assert.rejects(sprawdzDwa, { code: 'ERR_ASSERTION' });
  } finally {
    await browser.close();
  }
});
