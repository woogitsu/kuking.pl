import assert from "node:assert/strict";
import test from "node:test";
import { ustawWygladPomiaru } from "./kontrast-notice.mjs";

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
