/* Kontrast prawdziwych podpowiedzi: własny wpis, szkice i komentarze gościa. */
import { execFileSync } from "node:child_process";
import {
    mkdtempSync,
    readFileSync,
    writeFileSync,
    statSync,
    mkdirSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { createHash } from "node:crypto";
/* Pomiar wywoływany po prawdziwym GET i ustaleniu motywu. */
export async function sprawdzKontrastNotice(page, wymagane = []) {
    await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all(
            document.getAnimations().map((a) => a.finished.catch(() => {})),
        );
    });
    const rows = await page.locator(".notice a").evaluateAll((links) => {
        const rgb = (s) => s.match(/[\d.]+/g).map(Number);
        const lum = (c) =>
            c
                .slice(0, 3)
                .map((v) => v / 255)
                .map((v) =>
                    v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4,
                )
                .reduce(
                    (sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i],
                    0,
                );
        const canvas = document.createElement("canvas");
        canvas.width = canvas.height = 1;
        const paint = canvas.getContext("2d");
        const normalize = (s) => {
            paint.clearRect(0, 0, 1, 1);
            paint.fillStyle = s;
            paint.fillRect(0, 0, 1, 1);
            return [...paint.getImageData(0, 0, 1, 1).data].slice(0, 3);
        };
        return links
            .filter((a) => a.getClientRects().length)
            .map((a) => {
                const cs = getComputedStyle(a);
                let bg = [255, 255, 255];
                const ancestors = [];
                for (let n = a; n; n = n.parentElement) ancestors.unshift(n);
                for (const n of ancestors) {
                    const c = rgb(getComputedStyle(n).backgroundColor),
                        alpha = c[3] ?? 1;
                    bg = c
                        .slice(0, 3)
                        .map((v, i) => v * alpha + bg[i] * (1 - alpha));
                }
                const type = a.matches(".btn-primary")
                    ? "primary"
                    : a.matches(".btn-secondary")
                      ? "secondary"
                      : "plain";
                const token =
                    type === "primary"
                        ? "--color-ink-inverse"
                        : type === "secondary"
                          ? "--color-ink"
                          : "--color-accent-tint-ink";
                const color = normalize(cs.color),
                    expected = normalize(cs.getPropertyValue(token).trim()),
                    x = lum(color),
                    y = lum(bg);
                return {
                    type,
                    text: a.textContent.trim(),
                    color,
                    expected,
                    bg,
                    ratio: (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05),
                };
            });
    });
    for (const type of wymagane)
        if (!rows.some((r) => r.type === type))
            throw Error("NOTICE_BRAK_PRZYPADKU " + type);
    for (const r of rows) {
        if (r.ratio < 4.5) throw Error("NOTICE_KONTRAST " + JSON.stringify(r));
        if (JSON.stringify(r.color) !== JSON.stringify(r.expected))
            throw Error("NOTICE_KOLOR " + JSON.stringify(r));
    }
    return rows;
}

/** Pomiar obydwu krawędzi pierścienia po prawdziwym Tab i najechaniu. */
export async function sprawdzFokusNotice(page, kind) {
    await page.evaluate(async () => {
        await Promise.all(document.activeElement.getAnimations().map(a => a.finished));
    });
    const result = await page.evaluate((kind) => {
        const el = document.activeElement;
        if (!el?.matches('.notice .btn-' + kind) || !el.matches(':focus-visible') || !el.matches(':hover')) {
            throw Error('NOTICE_FOKUS_BRAK_STANU ' + kind);
        }
        const css = getComputedStyle(el);
        const rgb = value => {
            const channels = value.match(/[\d.]+/g).map(Number);
            if (channels.length === 4 && channels[3] !== 1) throw Error('NOTICE_FOKUS_PRZEZROCZYSTOSC ' + value);
            return channels.slice(0, 3);
        };
        for (let p = el; p; p = p.parentElement) {
            if (Number(getComputedStyle(p).opacity) !== 1) throw Error('NOTICE_FOKUS_PRZEZROCZYSTOSC');
        }
        const lum = color => color.map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4)
            .reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
        const ratio = (a, b) => (Math.max(lum(a), lum(b)) + .05) / (Math.min(lum(a), lum(b)) + .05);
        const shadows = [...css.boxShadow.matchAll(/(rgba?\([^)]+\))\s+0px\s+0px\s+0px\s+([\d.]+)px/g)]
            .map(m => ({ color: rgb(m[1]), spread: Number(m[2]) })).sort((a, b) => a.spread - b.spread);
        if (shadows.length !== 2 || shadows[0].spread < 2 || shadows[1].spread - shadows[0].spread < 3) {
            throw Error('NOTICE_FOKUS_BRAK_PIERSCIENIA ' + css.boxShadow);
        }
        const [halo, ring] = shadows;
        const background = rgb(getComputedStyle(el.closest('.notice')).backgroundColor);
        return { kind, halo, ring, background, button: rgb(css.backgroundColor),
            inside: ratio(ring.color, halo.color), outside: ratio(ring.color, background) };
    }, kind);
    if (result.inside < 3 || result.outside < 3) throw Error('NOTICE_FOKUS_KONTRAST ' + JSON.stringify(result));
    return result;
}

export async function sprawdzPodpowiedzi({
    browser,
    adres,
    sesja,
    phpEnv = process.env,
    negatywy = true,
}) {
    if (!["localhost", "127.0.0.1"].includes(new URL(adres).hostname))
        throw Error("NOTICE_LOKALNIE");
    const started = Date.now();
    const wyniki = [];
    const fixture = (...args) =>
        execFileSync("php", ["scripts/fixtures/kontrast-notice.php", ...args], {
            env: phpEnv,
        });
    const data = JSON.parse(fixture().toString());
    mkdirSync("storage/port-projektu/notice", { recursive: true });
    async function measure(width, dark, scale, kind, screenshot = false) {
        const context = await browser.newContext({
            storageState: kind === "plain" ? undefined : sesja,
            viewport: { width, height: 900 },
            reducedMotion: "reduce",
        });
        try {
            const page = await context.newPage();
            await page.addInitScript(
                ({ dark, scale }) =>
                    document.addEventListener("DOMContentLoaded", () => {
                        document.documentElement.dataset.theme = dark
                            ? "dark"
                            : "light";
                        document.documentElement.dataset.textScale =
                            String(scale);
                    }),
                { dark, scale },
            );
            const response = await page.goto(
                adres + (kind === "secondary" ? data.szkice : data.wpis),
                { waitUntil: "networkidle" },
            );
            if (response.status() !== 200)
                throw Error("NOTICE_HTTP " + response.status());
            await page.waitForFunction(
                ({ dark, scale }) =>
                    getComputedStyle(document.body).color ===
                        (dark ? "rgb(244, 245, 241)" : "rgb(21, 23, 20)") &&
                    Math.abs(
                        parseFloat(getComputedStyle(document.body).fontSize) -
                            (18 * scale) / 100,
                    ) < 0.15,
                { dark, scale },
            );
            wyniki.push({
                stan: "normalny",
                width,
                dark,
                scale,
                kind,
                pomiar: await sprawdzKontrastNotice(page, [kind]),
            });
            const target = page
                .locator(
                    kind === "plain"
                        ? ".notice a:not(.btn)"
                        : ".notice a.btn-" + kind,
                )
                .first();
            await target.hover();
            wyniki.push({
                stan: "najechanie",
                width,
                dark,
                scale,
                kind,
                pomiar: await sprawdzKontrastNotice(page, [kind]),
            });
            await page.mouse.move(0, 0);
            await target.focus();
            wyniki.push({
                stan: "fokus-programowy",
                width,
                dark,
                scale,
                kind,
                pomiar: await sprawdzKontrastNotice(page, [kind]),
            });
            if (kind !== "plain") {
                // Cofnięcie i powrót klawiszem uruchamiają heurystykę :focus-visible;
                // programowe focus() powyżej nie jest dowodem tego stanu.
                await page.keyboard.press("Shift+Tab");
                await page.keyboard.press("Tab");
                await target.hover();
                wyniki.push({
                    stan: "najechanie-i-fokus-klawiatury", width, dark, scale, kind,
                    pomiar: await sprawdzFokusNotice(page, kind),
                });
            }
            if (screenshot)
                await page.screenshot({
                    path: `storage/port-projektu/notice/${kind}-${width}-${dark}-${scale}.png`,
                });
        } finally {
            await context.close();
        }
    }
    try {
        let count = 0;
        for (const width of [320, 1440])
            for (const dark of [false, true])
                for (const scale of [100, 140])
                    for (const kind of ["primary", "secondary", "plain"]) {
                        await measure(width, dark, scale, kind, scale === 100);
                        count++;
                    }
        if (negatywy) {
            const mainRows = wyniki.length;
            const source = "resources/css/app.css",
                dir = mkdtempSync(tmpdir() + "/kuking-notice-");
            const selector = ".notice a:not(.btn-primary):not(.btn-secondary)";
            for (const [name, kind, mutation] of [
                [
                    "szeroki-selektor",
                    "primary",
                    (text) => text.replace(selector, ".notice a"),
                ],
                [
                    "brak-ochrony-linku",
                    "plain",
                    (text) =>
                        text.replace(selector, ".nieistniejaca-podpowiedz a"),
                ],
                [
                    "secondary",
                    "secondary",
                    (text) =>
                        text +
                        "\n.notice a.btn-secondary { color: var(--color-accent-tint-ink); }\n",
                ],
                ...["primary", "secondary"].map(kind => [
                    "stary-fokus-" + kind, kind,
                    text => text.replace(".notice .btn:focus-visible {", ".nieistniejaca-notice .btn:focus-visible {"),
                ]),
            ]) {
                const original = readFileSync(source, "utf8"),
                    mtime = statSync(source).mtimeMs;
                if (!original.includes(selector))
                    throw Error("NOTICE_NEGATYW_ZRODLO");
                const hash = (p) =>
                        createHash("md5").update(readFileSync(p)).digest("hex"),
                    before = hash(source),
                    backup = dir + "/" + name + ".css";
                execFileSync("cp", ["-p", source, backup]);
                let error;
                try {
                    writeFileSync(source, mutation(original));
                    execFileSync("npm", ["run", "build:assets"], { stdio: "pipe" });
                    try {
                        await measure(320, true, 100, kind);
                    } catch (e) {
                        error = e;
                    }
                } finally {
                    execFileSync("cp", ["-p", backup, source]);
                    if (
                        hash(source) !== before ||
                        statSync(source).mtimeMs !== mtime
                    )
                        throw Error("NOTICE_RESTORE");
                    execFileSync("npm", ["run", "build:assets"], { stdio: "pipe" });
                }
                await measure(320, true, 100, kind);
                // JSON macierzy opisuje 24 właściwe konfiguracje, nie częściowe
                // wiersze przerwanych negatywów i ich dodatkowe kontrole dodatnie.
                wyniki.splice(mainRows);
                const expected = name.startsWith("stary-fokus-") ? "NOTICE_FOKUS_KONTRAST " : "NOTICE_KONTRAST ";
                if (!error?.message.startsWith(expected))
                    throw Error(
                        "NOTICE_NEGATYW " + name + " " + error?.message,
                    );
                console.log(
                    `NOTICE_NEGATIVE_OK ${name} backup=${backup} MD5=${before} mtime=${mtime} restored; ${error.message}`,
                );
            }
        }
        writeFileSync(
            "storage/port-projektu/notice/pomiary.json",
            JSON.stringify(wyniki, null, 2),
        );
        console.log(
            `NOTICE_OK ${count} konfiguracje normal/hover/focus; primary/secondary także hover+Tab czas_ms=${Date.now() - started}`,
        );
    } finally {
        fixture("sprzataj", data.draft);
    }
}
