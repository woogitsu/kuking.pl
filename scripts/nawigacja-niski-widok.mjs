/* Rzeczywisty zoom, klawiatura i czytanie treści po odpięciu niskiego nagłówka. */
import { chromium } from "playwright";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
    mkdtempSync,
    mkdirSync,
    readFileSync,
    writeFileSync,
    statSync,
    existsSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { sprawdzTab } from "./zoom-marki.mjs";

export async function sprawdzNawigacje492({
    adres,
    sesja,
    phpEnv = process.env,
    negatywy = true,
}) {
    if (!["localhost", "127.0.0.1"].includes(new URL(adres).hostname))
        throw Error("N492_LOKALNIE");
    const started = Date.now();
    const temporary = mkdtempSync(tmpdir() + "/kuking-nav492-");
    const fixture = (...args) =>
        execFileSync("php", ["scripts/fixtures/nawigacja-492.php", ...args], {
            env: phpEnv,
        });
    const snapshot = temporary + "/dane.json";
    try {
        const data = JSON.parse(fixture("utworz", snapshot).toString());
        const directory = "storage/port-projektu/nawigacja492";
        mkdirSync(directory, { recursive: true });
        const results = [];
        const extension = temporary + "/extension";
        mkdirSync(extension);
        writeFileSync(
            extension + "/manifest.json",
            JSON.stringify({
                manifest_version: 3,
                name: "Pomiar nawigacji",
                version: "1.0",
                permissions: ["tabs"],
                background: { service_worker: "worker.js" },
            }),
        );
        writeFileSync(
            extension + "/worker.js",
            "chrome.runtime.onInstalled.addListener(() => {});",
        );
        let sequence = 0;
        async function contextFor(javaScriptEnabled) {
            const context = await chromium.launchPersistentContext(
                temporary + "/profil-" + sequence++,
                {
                    executablePath: process.env.CHROMIUM_PATH || undefined,
                    channel: "chromium",
                    headless: true,
                    javaScriptEnabled,
                    args: [
                        "--no-sandbox",
                        "--disable-extensions-except=" + extension,
                        "--load-extension=" + extension,
                    ],
                },
            );
            try {
                await context.addCookies(sesja.cookies);
                const page = await context.newPage();
                await page.goto(adres + "/home", { waitUntil: "load" });
                const worker =
                    context.serviceWorkers()[0] ||
                    (await context.waitForEvent("serviceworker"));
                const tab = (
                    await worker.evaluate(() => chrome.tabs.query({}))
                ).find((tab) => tab.url?.startsWith(adres));
                if (!tab) throw Error("N492_BRAK_KARTY");
                return { context, page, worker, tab };
            } catch (error) {
                await context.close();
                throw error;
            }
        }
        async function ustaw(view, variant, dark) {
            await view.worker.evaluate(
                ({ id, zoom }) => chrome.tabs.setZoom(id, zoom),
                { id: view.tab.id, zoom: variant.zoom },
            );
            await view.page.setViewportSize({
                width: variant.width * variant.zoom,
                height: variant.height * variant.zoom,
            });
            const cdp = await view.context.newCDPSession(view.page);
            await cdp.send("Page.setFontSizes", {
                fontSizes: { standard: variant.font, fixed: variant.font },
            });
            await cdp.detach();
            await view.page.evaluate(
                ({ dark, scale }) => {
                    document.documentElement.dataset.theme = dark
                        ? "dark"
                        : "light";
                    document.documentElement.dataset.textScale = String(scale);
                },
                { dark, scale: variant.scale },
            );
            await view.page.waitForFunction(
                ({ dark, variant }) =>
                    innerWidth === variant.width &&
                    Math.abs(
                        parseFloat(getComputedStyle(document.body).fontSize) -
                            (variant.font * 1.125 * variant.scale) / 100,
                    ) < 0.15 &&
                    getComputedStyle(document.body).color ===
                        (dark ? "rgb(244, 245, 241)" : "rgb(21, 23, 20)"),
                { dark, variant },
            );
            if (
                (await view.worker.evaluate(
                    (id) => chrome.tabs.getZoom(id),
                    view.tab.id,
                )) !== variant.zoom
            )
                throw Error("N492_ZOOM");
        }
        async function rama(page, release, compact = false) {
            const measurement = await page.evaluate(() => {
                const header = document.querySelector(".marka-topbar");
                const notification = document.querySelector(
                    ".marka-powiadomienia-link",
                );
                const account = document.querySelector(
                    ".topbar-konto-przycisk",
                );
                const rect = (element) => {
                    const r = element.getBoundingClientRect();
                    return {
                        top: r.top,
                        bottom: r.bottom,
                        width: r.width,
                        height: r.height,
                    };
                };
                return {
                    header: rect(header),
                    bottom: rect(document.querySelector(".bottom-nav")),
                    bottomPosition: getComputedStyle(
                        document.querySelector(".bottom-nav"),
                    ).position,
                    bottomPadding: parseFloat(
                        getComputedStyle(document.documentElement)
                            .scrollPaddingBottom,
                    ),
                    font: parseFloat(
                        getComputedStyle(document.documentElement).fontSize,
                    ),
                    height: innerHeight,
                    position: getComputedStyle(header).position,
                    padding: parseFloat(
                        getComputedStyle(document.documentElement)
                            .scrollPaddingTop,
                    ),
                    notification: rect(notification),
                    account: rect(account),
                    width: innerWidth,
                    scroll: document.documentElement.scrollWidth,
                };
            });
            if (measurement.position !== (release ? "relative" : "sticky"))
                throw Error("N492_PRZYPIECIE " + JSON.stringify(measurement));
            if (
                release &&
                measurement.padding !==
                    (await page.evaluate(
                        () =>
                            parseFloat(
                                getComputedStyle(document.documentElement)
                                    .fontSize,
                            ) / 2,
                    ))
            )
                throw Error("N492_REZERWA " + JSON.stringify(measurement));
            if (measurement.scroll > measurement.width + 1)
                throw Error("N492_PRZEPELNIENIE");
            if (release && measurement.bottomPosition !== "relative")
                throw Error("N492_DOL_PRZYPIECIE");
            if (release && measurement.bottomPadding !== measurement.font / 2)
                throw Error("N492_DOL_REZERWA");
            if (!release && measurement.bottomPosition !== "fixed")
                throw Error("N492_NORMALNY_DOL");
            if (
                measurement.notification.height < 48 ||
                measurement.account.height < 48
            )
                throw Error("N492_CEL_DOTYKU");
            if (
                compact &&
                Math.min(
                    measurement.notification.bottom,
                    measurement.account.bottom,
                ) <=
                    Math.max(
                        measurement.notification.top,
                        measurement.account.top,
                    )
            )
                throw Error("N492_WIERSZ " + JSON.stringify(measurement));
            return measurement;
        }
        async function calyTekst(page, selector) {
            const target = page.locator(selector).first();
            await target.scrollIntoViewIfNeeded();
            await target.evaluate((element) =>
                window.scrollBy(0, element.getBoundingClientRect().top - 12),
            );
            const result = await target.evaluate((element) => {
                const range = document.createRange();
                range.selectNodeContents(element);
                const fragments = [...range.getClientRects()].filter(
                    (r) => r.width && r.height,
                );
                const navigation = document.querySelector(".bottom-nav");
                const nav = {
                    top:
                        getComputedStyle(navigation).position === "fixed"
                            ? navigation.getBoundingClientRect().top
                            : innerHeight,
                };
                const header = document.querySelector(".topbar");
                const top =
                    getComputedStyle(header).position === "sticky"
                        ? header.getBoundingClientRect().bottom
                        : 0;
                return {
                    text: element.textContent.trim(),
                    top,
                    bottom: nav.top,
                    fragments: fragments.map((r) => ({
                        top: r.top,
                        bottom: r.bottom,
                        left: r.left,
                        right: r.right,
                    })),
                    complete:
                        fragments.length > 0 &&
                        fragments.every(
                            (r) =>
                                r.top >= top + 2 &&
                                r.bottom <= nav.top - 2 &&
                                r.left >= 0 &&
                                r.right <= innerWidth,
                        ),
                };
            });
            if (!result.complete)
                throw Error("N492_PELNY_TEKST " + JSON.stringify(result));
            return result;
        }
        async function screenshot(view, name) {
            const cdp = await view.context.newCDPSession(view.page);
            const shot = await cdp.send("Page.captureScreenshot", {
                format: "png",
                captureBeyondViewport: false,
                fromSurface: true,
            });
            writeFileSync(
                directory + "/" + name + ".png",
                Buffer.from(shot.data, "base64"),
            );
            await cdp.detach();
        }
        async function dolTab(page) {
            const expected = await page.locator(".bottom-nav a[href]").count();
            if (expected !== 5) throw Error("N492_PIEC_LINKOW");
            const visited = new Set();
            for (let step = 0; step < 160 && visited.size < expected; step++) {
                await page.keyboard.press("Tab");
                await page.waitForTimeout(40);
                const link = await page.evaluate(() => {
                    const element = document.activeElement;
                    if (!element.matches(".bottom-nav a[href]")) return null;
                    const r = element.getBoundingClientRect(),
                        s = getComputedStyle(element);
                    return {
                        href: element.getAttribute("href"),
                        top: r.top,
                        bottom: r.bottom,
                        left: r.left,
                        right: r.right,
                        height: r.height,
                        width: innerWidth,
                        viewport: innerHeight,
                        focus: element.matches(":focus-visible"),
                        outline: s.outlineStyle,
                        shadow: s.boxShadow,
                    };
                });
                if (!link) continue;
                if (
                    link.top < 0 ||
                    link.bottom > link.viewport ||
                    link.left < 0 ||
                    link.right > link.width ||
                    link.height < 48 ||
                    link.right - link.left < 48 ||
                    !link.focus ||
                    (link.outline === "none" && link.shadow === "none")
                )
                    throw Error("N492_DOL_TAB " + JSON.stringify(link));
                visited.add(link.href);
            }
            if (visited.size !== expected) throw Error("N492_DOL_KOMPLETNOSC");
            return [...visited];
        }
        const normal = [
            {
                width: 320,
                height: 740,
                zoom: 1,
                font: 16,
                scale: 100,
                release: false,
            },
            {
                width: 390,
                height: 844,
                zoom: 1,
                font: 16,
                scale: 100,
                release: false,
            },
        ];
        const short = {
            width: 320,
            height: 500,
            zoom: 2,
            font: 16,
            scale: 140,
            release: true,
        };
        const largeFont = {
            width: 320,
            height: 740,
            zoom: 1,
            font: 32,
            scale: 140,
            release: true,
        };
        for (const javaScriptEnabled of [true, false]) {
            const view = await contextFor(javaScriptEnabled);
            try {
                for (const variant of [...normal, short, largeFont])
                    for (const dark of [false, true]) {
                        await view.page.goto(adres + "/home", {
                            waitUntil: "load",
                        });
                        await ustaw(view, variant, dark);
                        const frame = await rama(
                            view.page,
                            variant.release,
                            variant.width === 390,
                        );
                        try {
                            await sprawdzTab(view.page, "/home", {
                                bezJs: !javaScriptEnabled,
                            });
                        } catch (error) {
                            await screenshot(
                                view,
                                `failure-${javaScriptEnabled}-${dark}-${variant.font}`,
                            );
                            throw error;
                        }
                        const bottomLinks = await dolTab(view.page);
                        const heading = variant.release
                            ? await calyTekst(view.page, ".composer-title")
                            : null;
                        if (variant === short)
                            await screenshot(
                                view,
                                `home-${javaScriptEnabled}-${dark}`,
                            );
                        results.push({
                            javaScriptEnabled,
                            dark,
                            variant,
                            path: "/home",
                            frame,
                            bottomLinks,
                            heading,
                        });
                    }
                // Jeden rzeczywisty błędny POST na kontekst; motyw zmieniamy na tym samym wyniku.
                await view.page.goto(adres + data.path, { waitUntil: "load" });
                const form = view.page.locator(
                    'form[action$="' + data.path + '"]',
                );
                const payload = await form.evaluate((form) =>
                    Object.fromEntries(new FormData(form)),
                );
                payload.body = "x";
                const response = await view.page.request.post(
                    adres + data.path,
                    {
                        form: payload,
                        maxRedirects: 0,
                        headers: { Referer: adres + data.path },
                    },
                );
                if (response.status() !== 302)
                    throw Error("N492_WALIDACJA_HTTP " + response.status());
                await view.page.goto(response.headers().location, {
                    waitUntil: "load",
                });
                for (const dark of [false, true]) {
                    await ustaw(view, short, dark);
                    const frame = await rama(view.page, true);
                    await sprawdzTab(view.page, data.path, {
                        bezJs: !javaScriptEnabled,
                    });
                    const message = await calyTekst(
                        view.page,
                        ".error-summary li",
                    );
                    await screenshot(view, `blad-${javaScriptEnabled}-${dark}`);
                    const link = view.page.locator(".error-summary a").first();
                    await link.click();
                    if (
                        !(await view.page
                            .locator("textarea[name=body]")
                            .evaluate(
                                (element) => document.activeElement === element,
                            ))
                    )
                        throw Error("N492_LINK_BLEDU");
                    results.push({
                        javaScriptEnabled,
                        dark,
                        variant: short,
                        path: data.path,
                        frame,
                        message,
                    });
                }
                await view.page.goto(adres + "/home", { waitUntil: "load" });
                await ustaw(view, short, false);
                await Promise.all([
                    view.page.waitForURL(
                        (url) => url.pathname === "/dodaj/zdjecie",
                    ),
                    view.page.locator(".composer").click(),
                ]);
                if (!(await view.page.locator("#f-photos").count()))
                    throw Error("N492_KLIK_KAFLA");
            } finally {
                await view.context.close();
            }
        }
        writeFileSync(
            directory + "/wyniki.json",
            JSON.stringify(results, null, 2),
        );
        if (negatywy) {
            const source = "resources/css/marka-rama.css";
            const original = readFileSync(source, "utf8");
            const hash = (path) =>
                createHash("md5").update(readFileSync(path)).digest("hex");
            const before = hash(source),
                mtime = statSync(source).mtimeMs,
                backup = temporary + "/marka-rama.css";
            execFileSync("cp", ["-p", source, backup]);
            const mutations = [
                [
                    "dol-objaw",
                    "ZOOM_FOCUS_OCCLUDED",
                    (text) =>
                        text.replace(
                            "[data-marka] .bottom-nav { position: relative; inset: auto; margin: calc(8px * var(--user-layout-scale, 1)); margin-bottom: calc(calc(8px * var(--user-layout-scale, 1)) + env(safe-area-inset-bottom, calc(0px * var(--user-layout-scale, 1)))); }",
                            "[data-marka] .bottom-nav { position: fixed; }",
                        ),
                ],
                [
                    "pierscien-objaw",
                    "ZOOM_FOCUS_OCCLUDED",
                    (text) => text.replaceAll(": .5rem;", ": 0rem;"),
                ],
                [
                    "dol",
                    "N492_DOL_PRZYPIECIE",
                    (text) =>
                        text.replace(
                            "[data-marka] .bottom-nav { position: relative; inset: auto; margin: calc(8px * var(--user-layout-scale, 1)); margin-bottom: calc(calc(8px * var(--user-layout-scale, 1)) + env(safe-area-inset-bottom, calc(0px * var(--user-layout-scale, 1)))); }",
                            "[data-marka] .bottom-nav { position: fixed; }",
                        ),
                ],
                [
                    "rezerwa-dol",
                    "N492_DOL_REZERWA",
                    (text) =>
                        text.replace(
                            "scroll-padding-bottom: .5rem;",
                            "scroll-padding-bottom: 0rem;",
                        ),
                ],
                [
                    "przypiecie",
                    "N492_PRZYPIECIE",
                    (text) =>
                        text.replace(
                            "@media (max-width: 30rem) and (max-height: 40rem) {\n  [data-marka] .marka-topbar { position: relative; top: 0; }",
                            "@media (max-width: 30rem) and (max-height: 40rem) {\n  [data-marka] .marka-topbar { position: sticky; top: 0; }",
                        ),
                ],
                [
                    "rezerwa",
                    "N492_REZERWA",
                    (text) =>
                        text.replace(
                            "@media (max-width: 15rem), (max-width: 30rem) and (max-height: 40rem)",
                            "@media (max-width: 15rem)",
                        ),
                ],
                [
                    "padding",
                    "N492_WIERSZ",
                    (text) =>
                        text.replace(
                            "[data-marka] .topbar-konto > summary { padding-inline: calc(4px * var(--user-layout-scale, 1)); }",
                            "[data-marka] .topbar-konto > summary { padding-inline: 16px; }",
                        ),
                ],
            ];
            for (const [name, code, mutate] of mutations) {
                let error;
                const probe = async () => {
                    const view = await contextFor(true);
                    try {
                        const variant =
                            name === "padding"
                                ? normal[1]
                                : name === "dol" ||
                                    name === "dol-objaw" ||
                                    name === "pierscien-objaw"
                                  ? largeFont
                                  : short;
                        if (name === "rezerwa-dol")
                            await view.page.goto(adres + data.path, {
                                waitUntil: "load",
                            });
                        await ustaw(view, variant, false);
                        if (name === "dol-objaw" || name === "pierscien-objaw")
                            await sprawdzTab(view.page, "/home");
                        await rama(
                            view.page,
                            variant.release,
                            name === "padding",
                        );
                    } finally {
                        await view.context.close();
                    }
                };
                try {
                    const changed = mutate(original);
                    if (changed === original)
                        throw Error("N492_MUTACJA_NIE_ZMIENIA");
                    writeFileSync(source, changed);
                    execFileSync("npm", ["run", "build"], { stdio: "pipe" });
                    try {
                        await probe();
                    } catch (failure) {
                        error = failure;
                    }
                } finally {
                    execFileSync("cp", ["-p", backup, source]);
                    if (
                        hash(source) !== before ||
                        statSync(source).mtimeMs !== mtime
                    )
                        throw Error("N492_PRZYWROCENIE");
                    execFileSync("npm", ["run", "build"], { stdio: "pipe" });
                }
                await probe();
                if (!error?.message.startsWith(code))
                    throw Error("N492_NEGATYW " + name + " " + error?.message);
                console.log(
                    `N492_NEGATIVE_OK ${name} MD5=${before} mtime=${mtime}; ${error.message}`,
                );
            }
        }
        writeFileSync(
            directory + "/wyniki.json",
            JSON.stringify(results, null, 2),
        );
        console.log(
            "N492_OK " +
                results.length +
                " konfiguracji; czas=" +
                (Date.now() - started) / 1000 +
                "s",
        );
    } finally {
        if (existsSync(snapshot)) fixture("przywroc", snapshot);
    }
}
