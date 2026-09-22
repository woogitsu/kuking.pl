import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Czytamy kod aplikacji, nie kopię palety w danych testu.
// Ten pomiar nie zastępuje axe ani sprawdzenia tekstu na zdjęciach.
const css = readFileSync(new URL('../resources/css/tokens.css', import.meta.url), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');
const lightBlock = css.match(/@theme\s*\{([^}]+)\}/);
const darkBlock = css.match(/:root\[data-theme="dark"\],\s*\.blok-ciemny\s*\{([^}]+)\}/);
assert(lightBlock && darkBlock, 'Brakuje palety jasnej albo wspólnej palety ciemnej.');
const parse = block => Object.fromEntries([...block.matchAll(/--color-([\w-]+):\s*(#[\da-f]{6})\s*;/gi)]
    .map(([, key, value]) => [key, value]));
const light = parse(lightBlock[1]);
const dark = { ...light, ...parse(darkBlock[1]) };
assert(Object.keys(light).length >= 25, 'Odczytano za mało kolorów; pomiar jest niepełny.');
assert(Object.keys(parse(darkBlock[1])).length >= 25, 'Odczytano za mało kolorów ciemnego motywu.');
function luminance(hex) {
    assert.match(hex ?? '', /^#[\da-f]{6}$/i, 'Brak wymaganego tokenu koloru.');
    const channels = hex.slice(1).match(/../g).map(c => parseInt(c, 16) / 255)
        .map(c => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
}
function contrast(a, b) {
    const x = luminance(a), y = luminance(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}
// Kontrola samego obliczenia — nie wolno przejść ze stałym wynikiem.
assert.equal(contrast('#000000', '#FFFFFF'), 21);
assert.equal(contrast('#FFFFFF', '#FFFFFF'), 1);
const pairs = [];
for (const background of ['surface', 'surface-raised', 'surface-sunken', 'surface-brand-wash']) {
    for (const foreground of ['ink', 'ink-muted', 'brand', 'danger', 'success']) {
        pairs.push([foreground, background, 4.5]);
    }
    pairs.push(['border-strong', background, 3], ['focus', background, 3]);
}
for (const role of ['brand', 'danger', 'success', 'accent']) {
    pairs.push([`${role}-tint-ink`, `${role}-tint`, 4.5]);
}
for (const solid of ['brand-solid', 'brand-solid-hover', 'danger-solid', 'danger-solid-hover']) {
    pairs.push(['ink-inverse', solid, 4.5]);
}
const results = [];
for (const [name, palette] of [['jasny', light], ['ciemny', dark]]) {
    for (const [foreground, background, minimum] of pairs) {
        const ratio = contrast(palette[foreground], palette[background]);
        results.push({ motyw: name, tekst: foreground, tlo: background, kontrast: +ratio.toFixed(3), minimum });
        // Porównujemy pełną wartość, nie zaokrąglenie drukowane w raporcie.
        assert(ratio >= minimum, `${name}: ${foreground}/${background} = ${ratio.toFixed(3)}; wymagane ${minimum}.`);
    }
}
assert.equal(results.length, 72, 'Zmienił się zasięg pomiaru par kolorów.');
if (process.argv.includes('--json')) console.log(JSON.stringify(results, null, 2));
else console.log(`Kontrast marki: ${results.length} par spełnia przypisane progi.`);
