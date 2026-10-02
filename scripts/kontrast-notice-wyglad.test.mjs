import assert from "node:assert/strict";
import test from "node:test";
import { ustawWygladPomiaru, ustalStylNotice } from "./kontrast-notice.mjs";

function stronaPomiarowa({ nieUstawiaMotywu = false } = {}) {
    const poprzedniDokument = globalThis.document;
    const poprzedniStyl = globalThis.getComputedStyle;
    const document = {
        documentElement: { dataset: { theme: "light", textScale: "100" } },
        body: {},
    };
    globalThis.document = document;
    globalThis.getComputedStyle = () => ({
        color: document.documentElement.dataset.theme === "dark"
            ? "rgb(244, 245, 241)" : "rgb(21, 23, 20)",
        fontSize: `${18 * Number(document.documentElement.dataset.textScale) / 100}px`,
    });
    let wywolania = 0;
    const page = {
        evaluate: async (callback, arg) => {
            wywolania++;
            if (nieUstawiaMotywu && wywolania === 1) return;
            return callback(arg);
        },
        waitForFunction: async (callback, arg, options) => {
            assert.equal(options.timeout, 10_000, "oczekiwanie musi być ograniczone");
            if (!callback(arg)) throw Error("Timeout 10000ms exceeded");
        },
    };
    return {
        page,
        document,
        przywroc: () => {
            globalThis.document = poprzedniDokument;
            globalThis.getComputedStyle = poprzedniStyl;
        },
    };
}

test("ciemny wariant pomiaru ustawia prawdziwe atrybuty po GET", async () => {
    const fixture = stronaPomiarowa();
    try {
        await ustawWygladPomiaru(fixture.page, { dark: true, scale: 140 });
        assert.deepEqual(fixture.document.documentElement.dataset,
            { theme: "dark", textScale: "140" });
    } finally {
        fixture.przywroc();
    }
});

test("brak wymuszenia ciemnego motywu kończy się nazwanym błędem", async () => {
    const fixture = stronaPomiarowa({ nieUstawiaMotywu: true });
    try {
        await assert.rejects(
            () => ustawWygladPomiaru(fixture.page, { dark: true, scale: 100 }),
            /NOTICE_WYGLAD_NIEZGODNY .*"theme":"light"/,
        );
    } finally {
        fixture.przywroc();
    }
});

function stronaZUstalaniem({ fonty = "loaded", animacje = [], poPierwszejProbce = null } = {}) {
    const poprzedniDokument = globalThis.document;
    const poprzedniePrzejscie = globalThis.CSSTransition;
    class PrzejscieCss {}
    globalThis.CSSTransition = PrzejscieCss;
    const notice = { contains: (element) => element === notice };
    const document = {
        fonts: { status: fonty },
        querySelector: () => notice,
        querySelectorAll: () => [notice],
        getAnimations: () => animacje,
    };
    globalThis.document = document;
    const wywolania = [];
    const page = {
        waitForFunction: async (callback, arg, options) => {
            assert.equal(options.timeout, 10_000, "oczekiwanie musi być ograniczone");
            wywolania.push("czekaj");
            if (!callback(arg)) {
                poPierwszejProbce?.();
                if (callback(arg)) return;
                const error = new Error("Timeout 10000ms exceeded");
                error.name = "TimeoutError";
                throw error;
            }
        },
        evaluate: async (callback, arg) => {
            wywolania.push("pomiar");
            return callback(arg);
        },
    };
    return {
        page, document, wywolania, PrzejscieCss,
        przywroc: () => {
            globalThis.document = poprzedniDokument;
            globalThis.CSSTransition = poprzedniePrzejscie;
        },
    };
}

test("fonty i przejścia są osobnymi ograniczonymi bramkami przed pomiarem", async () => {
    const fixture = stronaZUstalaniem();
    try {
        await ustalStylNotice(fixture.page);
        assert.deepEqual(fixture.wywolania, ["czekaj", "pomiar", "czekaj", "pomiar"]);
    } finally {
        fixture.przywroc();
    }
});

test("niezaładowane fonty kończą pomiar nazwanym błędem i stanem", async () => {
    const fixture = stronaZUstalaniem({ fonty: "loading" });
    try {
        await assert.rejects(() => ustalStylNotice(fixture.page),
            /NOTICE_FONTY.*10000 ms.*"status":"loading"/);
        assert.deepEqual(fixture.wywolania, ["czekaj", "pomiar"]);
    } finally {
        fixture.przywroc();
    }
});

test("trwające przejście kończy pomiar nazwanym błędem i diagnozą", async () => {
    const fixture = stronaZUstalaniem();
    try {
        fixture.document.getAnimations = () => [Object.assign(new fixture.PrzejscieCss(), {
            playState: "running", pending: false, transitionProperty: "background-color",
            effect: { target: { tagName: "DIV", className: "notice" } },
        })];
        await assert.rejects(() => ustalStylNotice(fixture.page),
            /NOTICE_PRZEJSCIA.*10000 ms.*background-color/);
        assert.deepEqual(fixture.wywolania, ["czekaj", "pomiar", "czekaj", "pomiar"]);
    } finally {
        fixture.przywroc();
    }
});

test("skończone przejście jest mierzone dopiero po ustaleniu stylu", async () => {
    const przejscie = { playState: "running", pending: false,
        transitionProperty: "background-color", effect: { target: { tagName: "DIV", className: "notice" } } };
    const fixture = stronaZUstalaniem({ poPierwszejProbce: () => { przejscie.playState = "finished"; } });
    try {
        Object.setPrototypeOf(przejscie, fixture.PrzejscieCss.prototype);
        fixture.document.getAnimations = () => [przejscie];
        await ustalStylNotice(fixture.page);
        assert.equal(przejscie.playState, "finished");
        assert.deepEqual(fixture.wywolania, ["czekaj", "pomiar", "czekaj", "pomiar"]);
    } finally {
        fixture.przywroc();
    }
});

test("niezwiązana animacja strony nie blokuje kontrastu notice", async () => {
    const fixture = stronaZUstalaniem();
    try {
        fixture.document.getAnimations = () => [{
            playState: "running", effect: { target: { contains: () => false },
                getComputedTiming: () => ({ endTime: Infinity }) },
        }];
        await ustalStylNotice(fixture.page);
    } finally {
        fixture.przywroc();
    }
});

test("nierozstrzygnięta animacja notice nie jest po cichu pomijana", async () => {
    const fixture = stronaZUstalaniem();
    try {
        fixture.document.getAnimations = () => [{
            playState: "running", effect: { target: fixture.document.querySelector(),
                getComputedTiming: () => ({ endTime: Infinity }) },
        }];
        await assert.rejects(() => ustalStylNotice(fixture.page),
            /NOTICE_PRZEJSCIA.*"animacjeNotice":\[\{"stan":"running","czas":null\}\]/);
    } finally {
        fixture.przywroc();
    }
});

test("animacja drugiego notice blokuje pomiar wszystkich odnośników", async () => {
    const fixture = stronaZUstalaniem();
    try {
        const drugi = { contains: (element) => element === drugi };
        fixture.document.querySelectorAll = () => [fixture.document.querySelector(), drugi];
        fixture.document.getAnimations = () => [{
            playState: "running", effect: { target: drugi,
                getComputedTiming: () => ({ endTime: Infinity }) },
        }];
        await assert.rejects(() => ustalStylNotice(fixture.page),
            /NOTICE_PRZEJSCIA.*"animacjeNotice":\[\{"stan":"running","czas":null\}\]/);
    } finally {
        fixture.przywroc();
    }
});

test("oczekująca animacja notice blokuje pomiar mimo stanu idle", async () => {
    const fixture = stronaZUstalaniem();
    try {
        fixture.document.getAnimations = () => [{
            playState: "idle", pending: true, effect: { target: fixture.document.querySelector(),
                getComputedTiming: () => ({ endTime: 250 }) },
        }];
        await assert.rejects(() => ustalStylNotice(fixture.page),
            /NOTICE_PRZEJSCIA.*"stan":"idle","oczekuje":true,"czas":250/);
    } finally {
        fixture.przywroc();
    }
});
