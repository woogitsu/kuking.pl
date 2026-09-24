import { chromium } from "playwright";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
    mkdtempSync,
    mkdirSync,
    readFileSync,
    writeFileSync,
    existsSync,
    statSync,
} from "node:fs";
import { tmpdir } from "node:os";

const adres = process.env.AUDYT_URL || "http://127.0.0.1:8037";
if (!["localhost", "127.0.0.1"].includes(new URL(adres).hostname))
    throw Error("434 wymaga lokalnego adresu");
const tmp = mkdtempSync(tmpdir() + "/kuking434-"),
    snapshot = tmp + "/stan.json",
    out = "storage/port-projektu/odbior434";
mkdirSync(out, { recursive: true });
const php = (...args) =>
    execFileSync("php", ["scripts/fixtures/awatar-kafel434.php", ...args], {
        env: process.env,
    }).toString();
const wyniki = { próbki: [], trasy: [], kafel: [] };
let browser;
try {
    const data = JSON.parse(php("utworz", snapshot));
    browser = await chromium.launch({
        executablePath: process.env.CHROMIUM_PATH,
        channel: "chromium",
        headless: true,
        args: ["--no-sandbox"],
    });
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(adres + "/login", { waitUntil: "load" });
    await page.fill("[name=login]", "ania");
    await page.fill("[name=password]", "haslo-testowe-123");
    await Promise.all([
        page.waitForURL((u) => !u.pathname.endsWith("/login")),
        page.locator("button.btn-primary[type=submit]").click(),
    ]);
    async function otworz(path, width, dark, scale) {
        await page.setViewportSize({ width, height: 844 });
        const response = await page.goto(adres + path, { waitUntil: "load" });
        if (response.status() !== 200)
            throw Error("434_HTTP " + path + " " + response.status());
        await page.evaluate(
            ({ dark, scale }) => {
                document.documentElement.dataset.theme = dark
                    ? "dark"
                    : "light";
                document.documentElement.dataset.textScale = String(scale);
            },
            { dark, scale },
        );
        await page.evaluate(() => document.fonts.ready);
        await page.waitForFunction(
            ({ dark, scale }) =>
                Math.abs(
                    parseFloat(getComputedStyle(document.body).fontSize) -
                        (18 * scale) / 100,
                ) < 0.15 &&
                getComputedStyle(document.body).color ===
                    (dark ? "rgb(244, 245, 241)" : "rgb(21, 23, 20)"),
            { dark, scale },
        );
    }
    async function awatar(locator, requested, expected, context) {
        await locator.scrollIntoViewIfNeeded();
        if (await locator.evaluate((e) => e.tagName === "IMG"))
            await locator.evaluate((e) => e.decode());
        const r = await locator.evaluate((e) => {
            const b = e.getBoundingClientRect(),
                p = e.parentElement.getBoundingClientRect();
            return {
                tag: e.tagName,
                requested: Number(e.dataset.rozmiar),
                width: b.width,
                height: b.height,
                parentWidth: p.width,
                shrink: getComputedStyle(e).flexShrink,
                loaded: e.tagName !== "IMG" || e.naturalWidth > 0,
            };
        });
        if (
            !r.loaded ||
            Math.abs(r.width - r.height) > 0.15 ||
            Math.abs(r.width - expected) > 0.15
        )
            throw Error(
                "434_AWATAR " +
                    JSON.stringify({ context, requested, expected, r }),
            );
        return r;
    }
    const paths = [
        [data.postPath, [32, 40, 52]],
        [data.recipePath, [44]],
        ["/powiadomienia", [48]],
        ["/@ania/obserwowani", [56]],
        ["/ustawienia/profil", [64]],
        ["/ustawienia/zdjecie", [88]],
        ["/odkryj", [120]],
    ];
    for (const width of [320, 390])
        for (const dark of [false, true])
            for (const scale of [100, 140]) {
                await otworz(data.samplePath, width, dark, scale);
                for (const kind of ["zdjecie", "inicjal"])
                    for (const size of [
                        32, 40, 44, 48, 52, 56, 64, 88, 120, 128,
                    ]) {
                        const target = page.locator(
                            `[data-proba="${kind}"][data-size="${size}"] .avatar`,
                        );
                        const r = await awatar(
                            target,
                            size,
                            size,
                            "kontrolowany komponent",
                        );
                        if (r.tag !== (kind === "zdjecie" ? "IMG" : "SPAN"))
                            throw Error("434_BRAK_WARIANTU " + kind);
                        wyniki.próbki.push({ viewportWidth: width, dark, scale, kind, ...r });
                    }
                for (const [path, sizes] of paths) {
                    await otworz(path, width, dark, scale);
                    for (const size of sizes) {
                        const list = page.locator(
                            `main .avatar[data-rozmiar="${size}"]`,
                        );
                        let target = null;
                        for (let i = 0; i < (await list.count()); i++)
                            if (await list.nth(i).isVisible()) {
                                target = list.nth(i);
                                break;
                            }
                        if (!target)
                            throw Error(
                                "434_BRAK_REALNEGO_AWATARA " +
                                    path +
                                    " " +
                                    size,
                            );
                        const r = await awatar(
                            target,
                            size,
                            size === 120 ? 52 : size,
                            path,
                        );
                        let stress = null;
                        if (size === 52) {
                            const head = page
                                .locator(".post-card-head")
                                .first();
                            const text = await head.innerText();
                            if (
                                !text.includes(
                                    "Małgorzata Konstantynopolitańczykowianka",
                                ) ||
                                !text.includes("Tylko dla obserwujących") ||
                                r.tag !== "IMG"
                            )
                                throw Error("434_BRAK_NAZWY_PLAKIETKI_ZDJECIA");
                            stress = text;
                            await page.screenshot({
                                path: `${out}/awatar52-${width}-${dark}-${scale}.png`,
                            });
                        }
                        wyniki.trasy.push({
                            viewportWidth: width,
                            dark,
                            scale,
                            path,
                            stress,
                            ...r,
                        });
                    }
                }
                console.log(`434_AWATARY_OK ${width} ${dark} ${scale}`);
            }
    async function kafel(width, dark, scale) {
        await otworz("/home", width, dark, scale);
        await page.evaluate(() => scrollTo(0, 0));
        return page.evaluate(() => {
            const box = (selector) => {
                const r = document
                    .querySelector(selector)
                    .getBoundingClientRect();
                return { x: r.x, y: r.y, width: r.width, height: r.height };
            };
            const label = document.querySelector(".tab-napis");
            const text = label?.firstChild;
            let space = null;
            if (text?.nodeType === 3 && text.textContent.endsWith(" ")) {
                const r = document.createRange();
                r.setStart(text, text.textContent.length - 1);
                r.setEnd(text, text.textContent.length);
                space = r.getBoundingClientRect().width;
            }
            return {
                card: box(".marka-publikacja"),
                composer: box(".composer"),
                header: box(".topbar"),
                notification: box(".marka-powiadomienia-link"),
                account: box(".topbar-konto-przycisk"),
                space,
            };
        });
    }
    const current = [];
    for (const width of [320, 390])
        for (const dark of [false, true])
            for (const scale of [100, 140]) {
                const result = await kafel(width, dark, scale);
                if (!(result.space > 0))
                    throw Error("434_BRAK_WIDOCZNEJ_SPACJI");
                current.push({ width, dark, scale, after: result });
                if (!dark && scale === 100)
                    await page.screenshot({
                        path: `${out}/kafel-po-${width}.png`,
                    });
            }
    const source = "resources/css/marka-rama.css",
        backup = tmp + "/marka-rama.css",
        original = readFileSync(source, "utf8"),
        hash = (p) => createHash("md5").update(readFileSync(p)).digest("hex"),
        beforeHash = hash(source),
        mtime = statSync(source).mtimeMs;
    execFileSync("cp", ["-p", source, backup]);
    try {
        const changed = original.replace(
            "  [data-marka] .marka-powiadomienia-link,\n  [data-marka] .topbar-konto > summary { padding-inline: 4px; }\n",
            "",
        );
        if (changed === original) throw Error("434_MUTACJA_NIE_ZMIENIA");
        writeFileSync(source, changed);
        execFileSync("npm", ["run", "build:assets"], { stdio: "pipe" });
        for (const row of current) {
            const before = await kafel(row.width, row.dark, row.scale);
            wyniki.kafel.push({
                ...row,
                before,
                deltaCard: before.card.y - row.after.card.y,
                deltaComposer: before.composer.y - row.after.composer.y,
            });
            if (!row.dark && row.scale === 100)
                await page.screenshot({
                    path: `${out}/kafel-przed-${row.width}.png`,
                });
        }
    } finally {
        execFileSync("cp", ["-p", backup, source]);
        if (hash(source) !== beforeHash || statSync(source).mtimeMs !== mtime)
            throw Error("434_RESTORE");
        execFileSync("npm", ["run", "build:assets"], { stdio: "pipe" });
    }
    for (const row of current) {
        const restored = await kafel(row.width, row.dark, row.scale);
        if (Math.abs(restored.card.y - row.after.card.y) > 0.15)
            throw Error("434_PO_PRZYWROCENIU");
    }
    if (
        wyniki.kafel
            .filter((r) => r.width === 390 && r.scale === 100)
            .some((r) => r.deltaCard <= 0)
    )
        throw Error("434_BRAK_ZYSKU_390");
    wyniki.restore = { md5: beforeHash, mtime, backup };
    writeFileSync(out + "/wyniki.json", JSON.stringify(wyniki, null, 2));
    console.log(
        "434_OK " +
            JSON.stringify({
                probki: wyniki.próbki.length,
                trasy: wyniki.trasy.length,
                kafel: wyniki.kafel.map((r) => ({
                    width: r.width,
                    dark: r.dark,
                    scale: r.scale,
                    delta: r.deltaCard,
                })),
                restore: wyniki.restore,
            }),
    );
} finally {
    try {
        if (browser) await browser.close();
    } finally {
        if (existsSync(snapshot)) php("przywroc", snapshot);
    }
}
