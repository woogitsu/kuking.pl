/* Pomiar bezpiecznego obszaru w Chromium (issue #987, D-260).
 *
 * Chromium potrafi udawać insety wycięcia przez CDP
 * (`Emulation.setSafeAreaInsetsOverride`) — to jedyna emulacja, która zmienia
 * `env(safe-area-inset-*)`; `page.setViewportSize()` ich nie rusza. To wciąż
 * NIE jest iPhone: Safari i tryb `standalone` odbiera człowiek z telefonem
 * (kryteria w issue #987). Skrypt mierzy to, co da się zmierzyć w repozytorium:
 * czy przy zadanych czterech insetach żaden brzeg nie wpada pod wycięcie
 * i czy cele dotykowe mają ≥ 48 px.
 *
 * Użycie (aplikacja lokalnie, zalogowany użytkownik testowy):
 *   ADRES=http://127.0.0.1:8988 LOGIN=... HASLO=... \
 *   CHROMIUM_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node scripts/bezpieczny-obszar-pomiar.mjs
 * Skrypt loguje się kilka razy; po wielokrotnym uruchomieniu limiter logowania
 * potrafi zablokować kolejne próby (wtedy: `php artisan cache:clear` lokalnie).
 * Kod wyjścia 1, gdy którakolwiek asercja oblewa.
 */
import { chromium } from "playwright";

const adres = process.env.ADRES ?? "http://127.0.0.1:8000";
if (!["localhost", "127.0.0.1"].includes(new URL(adres).hostname))
    throw Error("POMIAR_987_TYLKO_LOKALNIE");

const sceny = [
    {
        nazwa: "bez wycięcia (komputer/Android)",
        w: 390,
        h: 844,
        ins: { top: 0, right: 0, bottom: 0, left: 0 },
    },
    {
        nazwa: "iPhone pion (Dynamic Island + Home)",
        w: 393,
        h: 852,
        ins: { top: 59, right: 0, bottom: 34, left: 0 },
    },
    {
        nazwa: "iPhone poziom (wycięcie po lewej)",
        w: 852,
        h: 393,
        ins: { top: 0, right: 59, bottom: 21, left: 59 },
    },
];

const wyniki = [];
const sprawdz = (ok, opis) => {
    wyniki.push({ ok, opis });
    console.log((ok ? "OK    " : "BŁĄD ") + opis);
};

const przegladarka = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || undefined,
});

async function zaloguj(strona) {
    await strona.goto(adres + "/login");
    await strona.fill(
        "#f-login",
        process.env.LOGIN ?? "pomiar987@example.test",
    );
    await strona.fill("#f-password", process.env.HASLO ?? "Haslo-pomiar-987");
    await Promise.all([
        strona.waitForLoadState("load"),
        strona
            .locator("form:has(#f-password) button[type=submit]")
            .first()
            .click(),
    ]);
}

async function zmierz(strona) {
    return strona.evaluate(() => {
        const r = (el) => {
            if (!el) return null;
            const b = el.getBoundingClientRect();
            return {
                l: b.left,
                t: b.top,
                r: b.right,
                b: b.bottom,
                w: b.width,
                h: b.height,
            };
        };
        const cs = getComputedStyle(document.documentElement);
        const sonda = document.createElement("div");
        sonda.style.cssText =
            "position:fixed;visibility:hidden;padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left)";
        document.body.append(sonda);
        const p = getComputedStyle(sonda);
        const env = {
            top: parseFloat(p.paddingTop),
            right: parseFloat(p.paddingRight),
            bottom: parseFloat(p.paddingBottom),
            left: parseFloat(p.paddingLeft),
        };
        sonda.remove();
        const nav = document.querySelector(".bottom-nav");
        const elementy = [...document.querySelectorAll(".bottom-nav-item")].map(
            r,
        );
        const belka =
            document.querySelector(".marka-topbar") ??
            document.querySelector(".topbar");
        const kontener = document.querySelector(
            ".topbar-inner, .marka-topbar > *",
        );
        return {
            env,
            tokeny: ["top", "right", "bottom", "left"].map((k) =>
                cs.getPropertyValue("--safe-" + k).trim(),
            ),
            vw: document.documentElement.clientWidth,
            vh: window.innerHeight,
            przewijanieX:
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth,
            meta:
                document.querySelector('meta[name="viewport"]')?.content ?? "",
            belka: r(belka),
            zawartoscBelki: r(kontener),
            nav: r(nav),
            navPozycja: nav ? getComputedStyle(nav).position : "",
            wysokoscStrony: document.documentElement.scrollHeight,
            wysStopki: r(document.querySelector(".site-footer"))?.b ?? 0,
            pozycje: elementy,
            wyglad: r(document.querySelector(".szybki-wyglad")),
            bodyPadL: parseFloat(getComputedStyle(document.body).paddingLeft),
            bodyPadR: parseFloat(getComputedStyle(document.body).paddingRight),
        };
    });
}

for (const s of sceny) {
    console.log(
        "\n== " +
            s.nazwa +
            " " +
            s.w +
            "×" +
            s.h +
            " insety " +
            JSON.stringify(s.ins),
    );
    const kontekst = await przegladarka.newContext({
        viewport: { width: s.w, height: s.h },
        deviceScaleFactor: 3,
        isMobile: true,
        hasTouch: true,
    });
    const strona = await kontekst.newPage();
    const cdp = await kontekst.newCDPSession(strona);
    await zaloguj(strona);
    await cdp.send("Emulation.setSafeAreaInsetsOverride", { insets: s.ins });
    await strona.goto(adres + "/");
    await strona.waitForSelector(".bottom-nav");
    await strona.waitForTimeout(300);
    const m = await zmierz(strona);

    sprawdz(
        /viewport-fit=cover/.test(m.meta),
        "meta viewport wybiera cover (" + m.meta + ")",
    );
    sprawdz(
        m.env.top === s.ins.top &&
            m.env.bottom === s.ins.bottom &&
            m.env.left === s.ins.left &&
            m.env.right === s.ins.right,
        "env(safe-area-inset-*) = " +
            JSON.stringify(m.env) +
            " (emulacja działa)",
    );
    sprawdz(!m.przewijanieX, "brak poziomego przewijania strony");
    sprawdz(
        m.bodyPadL === s.ins.left && m.bodyPadR === s.ins.right,
        "body: wcięcie boków = lewy/prawy inset",
    );
    if (m.belka) {
        sprawdz(
            m.belka.t >= 0 &&
                m.belka.l >= s.ins.left - 0.5 &&
                m.belka.r <= m.vw - s.ins.right + 0.5,
            "górna belka mieści się w bezpiecznych bokach",
        );
        const tekstTop = m.zawartoscBelki ? m.zawartoscBelki.t : m.belka.t;
        sprawdz(
            tekstTop >= s.ins.top - 0.5,
            "zawartość górnej belki zaczyna się pod insetem górnym (t=" +
                tekstTop.toFixed(1) +
                " ≥ " +
                s.ins.top +
                ")",
        );
    }
    sprawdz(
        m.nav.l >= s.ins.left - 0.5 && m.nav.r <= m.vw - s.ins.right + 0.5,
        "dolna belka: lewa/prawa krawędź poza insetami",
    );
    // Niski widok (poziom) odpina belkę do przepływu strony (marka-rama.css):
    // wtedy dół mierzymy względem końca dokumentu, nie okna.
    const dolGranica = m.navPozycja === "fixed" ? m.vh : m.wysokoscStrony;
    sprawdz(
        m.nav.b +
            (m.navPozycja === "fixed"
                ? 0
                : await strona.evaluate(() => scrollY)) <=
            dolGranica - s.ins.bottom + 0.5,
        "dolna belka (" +
            m.navPozycja +
            "): dolna krawędź nad wskaźnikiem Home (b=" +
            m.nav.b.toFixed(1) +
            ", granica " +
            (dolGranica - s.ins.bottom) +
            ")",
    );
    sprawdz(
        m.pozycje.every(
            (p) =>
                p.l >= s.ins.left - 0.5 &&
                p.r <= m.vw - s.ins.right + 0.5 &&
                p.b <= dolGranica - s.ins.bottom + 0.5,
        ),
        "każda pozycja dolnej nawigacji (" +
            m.pozycje.length +
            ") w bezpiecznym obszarze",
    );
    sprawdz(
        m.pozycje.every((p) => p.h >= 47.5 && p.w >= 47.5),
        "każda pozycja dolnej nawigacji ≥ 48 × 48 px",
    );
    if (m.wyglad) {
        sprawdz(
            m.wyglad.r <= m.vw - s.ins.right + 0.5 &&
                m.wyglad.b <= m.vh - s.ins.bottom + 0.5,
            "przycisk szybkiego wyglądu poza insetami",
        );
        sprawdz(
            m.wyglad.h >= 47.5 && m.wyglad.w >= 47.5,
            "przycisk szybkiego wyglądu ≥ 48 × 48 px",
        );
    }
    // Obrót bez przeładowania: zmiana insetów i rozmiaru ma przeliczyć odstępy.
    await strona.evaluate(() => (window.__bezPrzeladowania = 1));
    await cdp.send("Emulation.setSafeAreaInsetsOverride", {
        insets: { top: 0, right: 0, bottom: 0, left: 0 },
    });
    await strona.waitForTimeout(150);
    const po = await zmierz(strona);
    sprawdz(
        (await strona.evaluate(() => window.__bezPrzeladowania)) === 1 &&
            po.env.bottom === 0 &&
            (m.navPozycja !== "fixed" || po.nav.b > m.nav.b - 0.5),
        "zmiana insetów przelicza odstępy bez przeładowania (dolna belka: " +
            m.nav.b.toFixed(1) +
            " → " +
            po.nav.b.toFixed(1) +
            ")",
    );
    await strona
        .screenshot({
            path:
                (process.env.ZRZUTY ?? ".") +
                "/zrzut-987-" +
                s.w +
                "x" +
                s.h +
                "-" +
                s.ins.top +
                "-" +
                s.ins.left +
                ".png",
        })
        .catch(() => {});
    await kontekst.close();
}

// Informacyjnie: co robi Chromium z insetami przy viewport-fit=auto.
{
    const kontekst = await przegladarka.newContext({
        viewport: { width: 393, height: 852 },
        isMobile: true,
        hasTouch: true,
        serviceWorkers: "block",
    });
    const strona = await kontekst.newPage();
    const cdp = await kontekst.newCDPSession(strona);
    await zaloguj(strona);
    await strona.route("**/*", async (route) => {
        const odp = await route.fetch();
        const typ = odp.headers()["content-type"] ?? "";
        if (!typ.includes("text/html")) return route.fulfill({ response: odp });
        const html = (await odp.text()).replace(
            "viewport-fit=cover",
            "viewport-fit=auto",
        );
        const naglowki = { ...odp.headers() };
        delete naglowki["content-encoding"];
        delete naglowki["content-length"];
        return route.fulfill({
            status: odp.status(),
            headers: naglowki,
            body: html,
        });
    });
    await cdp.send("Emulation.setSafeAreaInsetsOverride", {
        insets: { top: 59, right: 0, bottom: 34, left: 0 },
    });
    await strona.goto(adres + "/");
    const m = await zmierz(strona);
    console.log(
        "\n== INFORMACYJNIE viewport-fit=auto (te same insety 59/0/34/0): env=" +
            JSON.stringify(m.env) +
            ", meta=" +
            m.meta,
    );
    await kontekst.close();
}

await przegladarka.close();
const bledy = wyniki.filter((w) => !w.ok);
console.log(
    "\n" + (wyniki.length - bledy.length) + "/" + wyniki.length + " asercji OK",
);
process.exit(bledy.length ? 1 : 0);
