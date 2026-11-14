// Runs public/assets/modules/web-notices/notices-public.js against a tiny fake DOM, fake fetch and a fake Turnstile whose reset() passes again by
// itself (what a non-interactive / managed widget does). Prints one JSON line {ok, failures[], log[]} and exits 0/1.
// Usage: node notices-public.harness.mjs <path-to-notices-public.js>
import fs from 'node:fs';
import vm from 'node:vm';

const file = process.argv[2];
const src = fs.readFileSync(file, 'utf8');
const failures = [];
const log = [];
const check = (cond, msg) => { if (!cond) failures.push(msg); };
const tick = (ms = 5) => new Promise((r) => setTimeout(r, ms));

class El {
    constructor(tag = 'div') { this.tag = tag; this.children = []; this.dataset = {}; this.attrs = {}; this.listeners = {}; this._text = ''; this.className = ''; this.elements = {}; }
    set textContent(v) { this._text = String(v); this.children = []; }
    get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }
    appendChild(c) { this.children.push(c); return c; }
    replaceChildren(...c) { this.children = c; this._text = ''; }
    setAttribute(k, v) { this.attrs[k] = v; }
    addEventListener(t, f) { (this.listeners[t] ||= []).push(f); }
    click() { (this.listeners.click || []).forEach((f) => f({})); }
    createTHead() { const t = new El('thead'); this.appendChild(t); t.insertRow = () => { const r = new El('tr'); t.appendChild(r); return r; }; return t; }
    createTBody() { const t = new El('tbody'); this.appendChild(t); t.insertRow = () => { const r = new El('tr'); t.appendChild(r); r.insertCell = () => { const c = new El('td'); r.appendChild(c); return c; }; return r; }; return t; }
    find(tag) { const out = []; const walk = (n) => { if (n.tag === tag) out.push(n); n.children.forEach(walk); }; walk(this); return out; }
}

async function scenario(name, mode, { challengeStatus = 200, rowsStatus = 200 } = {}) {
    const calls = [];
    let widgetOpts = null, resets = 0;
    const box = new El(); box.dataset = {base: '/portal/notices/demo', csrf: 'csrf-demo', mode, sitekey: '1x00000000000000000000AA'};
    const status = new El(), result = new El(), slot = new El();
    const form = mode === 'search' ? new El('form') : null;
    if (form) form.elements = {q: {value: 'ภูริทัต'}};
    const byId = {'notice-gate': box, 'notice-status': status, 'notice-result': result, 'notice-search': form, 'notice-turnstile': slot};
    const window = {};
    const turnstile = {
        render(_slot, opts) { widgetOpts = opts; setTimeout(() => opts.callback('ts-token-1'), 0); return 7; },
        reset() { resets++; if (resets < 50) setTimeout(() => widgetOpts.callback('ts-token-' + (resets + 1)), 0); },
    };
    window.turnstile = turnstile;
    const fetch = async (url, init) => {
        const path = String(url).split('/').pop();
        calls.push(path);
        const ok = path === 'challenge' ? challengeStatus === 200 : rowsStatus === 200;
        const status = path === 'challenge' ? challengeStatus : rowsStatus;
        const body = !ok ? {message: 'ไม่สามารถแสดงรายชื่อได้ในขณะนี้'} : path === 'challenge' ? {token: 'a'.repeat(64)}
            : {columns: [{key: 'order_no', label: 'ลำดับ'}, {key: 'display_name', label: 'ชื่อ'}], rows: [{order_no: 1, display_name: 'เด็กชายมาร์ค ก.'}], full: false, more: false};
        return {ok, status, json: async () => body};
    };
    const document = {getElementById: (id) => byId[id] ?? null, createElement: (t) => new El(t), head: new El('head')};
    const ctx = vm.createContext({document, window, fetch, JSON, String, Error, Promise, setTimeout, console});
    vm.runInContext(src, ctx, {filename: 'notices-public.js'});
    check(typeof window.saanNoticeTurnstileReady === 'function', name + ': ready callback is exposed');
    window.saanNoticeTurnstileReady(); // "the Turnstile API finished loading"
    await tick(30);
    if (mode === 'search') {
        // search mode: the visitor submits; the reset after the search makes a new token available but must not trigger another request
        form.listeners.submit[0]({preventDefault() {}});
        await tick(60);
    } else {
        await tick(60); // list mode: give a looping implementation plenty of time to show itself
    }
    log.push({name, calls: calls.slice(), resets, text: result.textContent.slice(0, 40), status: status.textContent});
    return {calls, get resets() { return resets; }, result, status, widgetOpts};
}

// 1. list mode, success: exactly one challenge and one rows request, no reset, table stays
{
    const s = await scenario('list-success', 'list');
    check(s.calls.join(',') === 'challenge,rows', 'list success: expected exactly challenge,rows but got ' + s.calls.join(','));
    check(s.resets === 0, 'list success: the widget must not be reset after a successful load (resets=' + s.resets + ')');
    check(s.result.find('table').length === 1, 'list success: the table must still be on the page');
    // a late second Turnstile callback (expired / re-solved widget) must be ignored
    s.widgetOpts.callback('late-token');
    await tick(30);
    check(s.calls.length === 2, 'list success: a second callback must not call the server again (calls=' + s.calls.join(',') + ')');
}

// 2. list mode, refusal (403): one attempt, no automatic retry, the visitor gets a button and one click makes exactly one more attempt
{
    const s = await scenario('list-403', 'list', {challengeStatus: 403});
    check(s.calls.join(',') === 'challenge', 'list 403: expected exactly one challenge, got ' + s.calls.join(','));
    check(s.resets === 0, 'list 403: no automatic reset (resets=' + s.resets + ')');
    const btn = s.result.find('button')[0];
    check(!!btn, 'list 403: a manual retry button is offered');
    if (btn) {
        btn.click();
        await tick(60);
        check(s.calls.join(',') === 'challenge,challenge', 'list 403: one click = exactly one more attempt, got ' + s.calls.join(','));
        check(s.resets === 1, 'list 403: the retry resets the widget once (resets=' + s.resets + ')');
    }
}

// 3. list mode, rate limited (429) on rows: the same, and the table is not replaced by a loop
{
    const s = await scenario('list-429', 'list', {rowsStatus: 429});
    check(s.calls.join(',') === 'challenge,rows', 'list 429: expected challenge,rows once, got ' + s.calls.join(','));
}

// 4. search mode: one search = one challenge + one search; the reset gives the next search a fresh token but sends nothing by itself
{
    const s = await scenario('search', 'search');
    check(s.calls.join(',') === 'challenge,search', 'search: expected challenge,search once, got ' + s.calls.join(','));
    check(s.resets === 1, 'search: the widget is reset once after the search (resets=' + s.resets + ')');
}

console.log(JSON.stringify({ok: failures.length === 0, failures, log}));
process.exit(failures.length === 0 ? 0 : 1);
