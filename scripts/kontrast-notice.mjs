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
                    execFileSync("npm", ["run", "build"], { stdio: "pipe" });
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
                    execFileSync("npm", ["run", "build"], { stdio: "pipe" });
                }
                await measure(320, true, 100, kind);
                if (!error?.message.startsWith("NOTICE_KONTRAST "))
                    throw Error(
                        "NOTICE_NEGATYW " + name + " " + error?.message,
                    );
                console.log(
                    `NOTICE_NEGATIVE_OK ${name} MD5=${before} mtime=${mtime} restored; ${error.message}`,
                );
            }
        }
        writeFileSync(
            "storage/port-projektu/notice/pomiary.json",
            JSON.stringify(wyniki, null, 2),
        );
        console.log(
            `NOTICE_OK ${count} konfiguracje normal/hover/focus czas_ms=${Date.now() - started}`,
        );
    } finally {
        fixture("sprzataj", data.draft);
    }
}
