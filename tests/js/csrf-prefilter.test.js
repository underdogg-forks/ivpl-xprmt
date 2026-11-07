"use strict";

/*
 * Runs on the built-in Node test runner, no dependencies:
 *   node --test tests/js/
 *
 * Covers the jQuery ajaxPrefilter in resources/assets/core/js/scripts.js that appends the CSRF token to
 * every POST. The script is a plain browser script, so it is evaluated here with a stub document and a
 * stub jQuery that hands back the registered prefilter.
 */

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const SOURCE = fs.readFileSync(path.join(__dirname, "../../resources/assets/core/js/scripts.js"), "utf8");

function loadPrefilter(tokenValue = "tok123", cookie = "") {
    const absorb = () => new Proxy(function () {}, {
        get: (_t, prop) => (prop === Symbol.toPrimitive ? () => "" : absorb()),
        apply: () => absorb(),
    });
    const meta = {
        content: tokenValue,
        getAttribute() { return this.content; },
        setAttribute(name, value) { if (name === "content") { this.content = value; } },
    };
    let prefilter = null;
    let ajaxComplete = null;
    const chain = new Proxy(function () {}, {
        get: (_t, prop) => (prop === "ajaxComplete" ? (fn) => { ajaxComplete = fn; return chain; } : absorb()),
        apply: () => absorb(),
    });

    const $ = new Proxy(function () {}, {
        get: (_t, prop) => {
            if (prop === "ajaxPrefilter") { return (fn) => { prefilter = fn; }; }
            if (prop === "isPlainObject") { return (value) => Object.prototype.toString.call(value) === "[object Object]"; }

            return absorb();
        },
        apply: (_t, _this, args) => {
            if (typeof args[0] === "function") {
                args[0]();
                return absorb();
            }

            return chain;
        },
    });

    const known = {
        cookie,
        querySelector: (selector) => (selector.includes("csrf_token_name")
            ? { getAttribute: () => "_ip_csrf" }
            : meta),
    };
    const document = new Proxy(known, { get: (target, prop) => (prop in target ? target[prop] : absorb()) });

    const sandbox = { $, jQuery: $, ClipboardJS: function () { return absorb(); }, FormData, document, window: { location: { href: "http://test/" }, FormData }, console, setTimeout, clearTimeout };
    sandbox.window.document = document;
    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);

    assert.equal(typeof prefilter, "function", "scripts.js must register an ajaxPrefilter");

    prefilter.ajaxComplete = ajaxComplete;

    return prefilter;
}

/** Runs the prefilter the way jQuery does (options, originalOptions, jqXHR) and returns the request headers it set. */
function runPrefilter(options, tokenValue = "tok123") {
    const headers = {};
    const jqXHR = { setRequestHeader: (name, value) => { headers[name] = value; } };

    loadPrefilter(tokenValue)(options, options, jqXHR);

    return headers;
}

test("prefilter: appends the token to an existing urlencoded body", () => {
    const options = { type: "POST", data: "filter_query=abc" };

    runPrefilter(options);

    assert.equal(options.data, "filter_query=abc&_ip_csrf=tok123");
});

test("prefilter: sends the token as the X-_ip_csrf request header too", () => {
    const options = { type: "POST", data: "filter_query=abc" };

    const headers = runPrefilter(options);

    assert.deepEqual(headers, { "X-_ip_csrf": "tok123" });
});

test("prefilter: a POST without a usable token sets no header and leaves the body alone", () => {
    const options = { type: "POST", data: "filter_query=abc" };

    const headers = runPrefilter(options, "undefined");

    assert.deepEqual(headers, {});
    assert.equal(options.data, "filter_query=abc");
});

test("prefilter: an empty body becomes the bare token pair, never a query-string fragment", () => {
    const options = { type: "POST", data: "" };

    runPrefilter(options);

    assert.equal(options.data, "_ip_csrf=tok123", "a leading '?' turns the field name into '?_ip_csrf' and the server answers 403");
});

test("prefilter: a POST without any data still carries the token and no literal 'undefined'", () => {
    const options = { type: "POST" };

    runPrefilter(options);

    assert.equal(options.data, "_ip_csrf=tok123");
});

test("prefilter: a FormData body keeps its fields and gains the token as a field", () => {
    const form = new FormData();
    form.append("file_name", "logo.png");
    const options = { type: "POST", data: form, processData: false, contentType: false };

    runPrefilter(options);

    assert.ok(options.data instanceof FormData, "FormData must not be turned into the string '[object FormData]'");
    assert.equal(options.data.get("file_name"), "logo.png");
    assert.equal(options.data.get("_ip_csrf"), "tok123");
});

test("prefilter: GET requests are left alone", () => {
    const options = { type: "GET", data: "q=1" };

    runPrefilter(options);

    assert.equal(options.data, "q=1");
});

test("prefilter: a plain object body gains the token as a property", () => {
    const options = { type: "POST", data: { filter_query: "abc" } };

    runPrefilter(options);

    assert.deepEqual(options.data, { filter_query: "abc", _ip_csrf: "tok123" });
});

test("prefilter: a plain object body that already carries a token keeps it", () => {
    const options = { type: "POST", data: { _ip_csrf: "caller-token" } };

    runPrefilter(options);

    assert.equal(options.data._ip_csrf, "caller-token");
});

test("prefilter: a string body that already carries the token is not given a second one", () => {
    const options = { type: "POST", data: "_ip_csrf=tok123&filter_query=abc" };

    runPrefilter(options);

    assert.equal(options.data, "_ip_csrf=tok123&filter_query=abc");
});

test("prefilter: with no meta token it falls back to the csrf cookie", () => {
    const options = { type: "POST", data: "filter_query=abc" };
    const headers = {};
    const prefilter = loadPrefilter("", "theme=dark; ip_csrf_cookie=cookie%20token; other=1");

    prefilter(options, options, { setRequestHeader: (name, value) => { headers[name] = value; } });

    assert.equal(options.data, "filter_query=abc&_ip_csrf=cookie%20token");
    assert.deepEqual(headers, { "X-_ip_csrf": "cookie token" });
});

test("ajaxComplete: adopts the rotated token from the response header for the next post", () => {
    const prefilter = loadPrefilter();
    const xhr = { getResponseHeader: (name) => (name === "X-_ip_csrf" ? "rotated" : null) };
    const options = { type: "POST", data: "filter_query=abc" };

    prefilter.ajaxComplete({}, xhr, { url: "/x" });
    prefilter(options, options, { setRequestHeader() {} });

    assert.equal(options.data, "filter_query=abc&_ip_csrf=rotated");
});

test("ajaxComplete: keeps the current token when the response carries no token header", () => {
    const prefilter = loadPrefilter();
    const xhr = { getResponseHeader: () => null };
    const options = { type: "POST", data: "filter_query=abc" };

    prefilter.ajaxComplete({}, xhr, { url: "/x" });
    prefilter(options, options, { setRequestHeader() {} });

    assert.equal(options.data, "filter_query=abc&_ip_csrf=tok123");
});
