// Runs public/assets/site-extras.js against a tiny fake DOM and checks the map block: no iframe before the click, one iframe with the validated provider
// address after it, the load button hidden until the script runs, and a refusal of an address that is not one of the two allowed provider sources.
// Prints one JSON line {ok, failures[], log[]} and exits 0/1.
// Usage: node site-extras.harness.mjs <path-to-site-extras.js>
import fs from 'node:fs';
import vm from 'node:vm';

const src = fs.readFileSync(process.argv[2], 'utf8');
const failures = [];
const log = [];
const check = (cond, msg) => { if (!cond) failures.push(msg); };

class El {
    constructor(tag = 'div') { this.tag = tag; this.children = []; this.dataset = {}; this.attrs = {}; this.listeners = {}; this.hidden = false; this.removed = false; this.focused = false; this.classes = new Set(); }
    setAttribute(k, v) { this.attrs[k] = v; }
    addEventListener(t, f) { (this.listeners[t] ||= []).push(f); }
    click() { (this.listeners.click || []).forEach((f) => f({})); }
    insertBefore(c, ref) { this.children.unshift(c); log.push('insert ' + c.tag); return c; }
    get firstChild() { return this.children[0] || null; }
    remove() { this.removed = true; }
    focus() { this.focused = true; }
    get classList() { const s = this.classes; return { add: (c) => s.add(c) }; }
}

const makeBlock = (embed) => {
    const block = new El('section');
    block.dataset.mapEmbed = embed;
    block.dataset.mapProvider = 'Google Maps';
    const button = new El('button'); button.hidden = true;
    const stage = new El('div');
    block.q = { '[data-map-load]': button, '[data-map-stage]': stage };
    block.querySelector = (s) => block.q[s] || null;
    return { block, button, stage };
};

const run = (embeds) => {
    const made = embeds.map(makeBlock);
    const created = [];
    const context = {
        document: {
            querySelectorAll: (s) => (s === '[data-map-block]' ? made.map((m) => m.block) : []),
            createElement: (t) => { const e = new El(t); created.push(e); return e; },
        },
        location: { href: 'https://school.test/' },
        URL,
    };
    vm.runInNewContext(src, context);
    return { made, created };
};

// 1. valid Google address: nothing is created until the click
{
    const { made, created } = run(['https://www.google.com/maps?q=13.756300%2C100.501800&z=16&output=embed']);
    const m = made[0];
    check(created.length === 0, 'an element was created before the click');
    check(m.button.hidden === false, 'the load button must be shown once the script runs');
    m.button.click();
    check(created.length === 1 && created[0].tag === 'iframe', 'exactly one iframe after the click');
    check(created[0].src === 'https://www.google.com/maps?q=13.756300%2C100.501800&z=16&output=embed', 'iframe address is the validated one');
    check(created[0].referrerPolicy === 'strict-origin-when-cross-origin', 'referrer policy');
    check(/Google Maps/.test(created[0].title), 'iframe title names the provider');
    check(m.button.removed === true, 'the button is removed after loading');
    check(created[0].focused === true, 'focus moves to the map');
    check(m.stage.classes.has('is-loaded'), 'stage marked as loaded');
}
// 2. valid OpenStreetMap address
{
    const { made, created } = run(['https://www.openstreetmap.org/export/embed.html?bbox=100.4%2C13.7%2C100.5%2C13.8&layer=mapnik&marker=13.7%2C100.5']);
    made[0].button.click();
    check(created.length === 1 && created[0].src.startsWith('https://www.openstreetmap.org/export/embed.html?'), 'OpenStreetMap iframe');
}
// 3. anything else is refused: the button stays hidden and no iframe can be made
for (const bad of ['https://evil.test/maps?q=1', 'http://www.google.com/maps?q=1', 'https://www.google.com/other?q=1', 'https://www.google.com.evil.test/maps?q=1', 'javascript:alert(1)', 'https://www.google.com:8443/maps?q=1', '']) {
    const { made, created } = run([bad]);
    check(made[0].button.hidden === true, 'refused address leaves the button hidden: ' + bad);
    made[0].button.click();
    check(created.length === 0, 'refused address creates nothing: ' + bad);
}

console.log(JSON.stringify({ ok: failures.length === 0, failures, log }));
process.exit(failures.length === 0 ? 0 : 1);
