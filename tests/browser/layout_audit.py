#!/usr/bin/env python3
"""Layout audit of a running Saan install in a real browser (Playwright, Chrome).

PHPUnit never opens a browser, so it cannot see text printed over other text, a table that pushes the page sideways or a request
that fails only in the page's JavaScript. This tool opens every staff page, the public pages of the school and the student and
guardian portals at four widths, drives each page a little (placeholder selects, the page's own tabs, the first add/edit form)
and reports:

  spill     text drawn outside the box that should hold it, and visible to the user
  overlap   two visible texts drawn on top of each other (a text only counts as hidden when what covers it is opaque)
  clipped   text cut by overflow:hidden (a scroll container is fine, a cut is not)
  hscroll   the page itself scrolls sideways
  http      a same-origin request answered with status 400 or higher while the page was open (422 is a validation answer, e.g. an administrator without a teacher record, and is not counted; a GET that answered 5xx is asked once more and counts only if it fails again)
  js-error  an uncaught JavaScript error on the page

Exit code 0 = zero findings, 1 = at least one finding or a page that could not be checked. There is no baseline: the target is zero.
Every write request (POST, PUT, PATCH, DELETE) after sign-in is aborted, so driving a page can never change the install's data.

Usage:  python -I layout_audit.py --base http://localhost/saan --accounts accounts.txt --out ./out [--only SUBSTRING] [--widths 1440,1024,768,390]
See README.md in this folder for the accounts file and the environment variables.
"""
import argparse
import json
import os
import re
import sys
import time
from urllib.parse import urlparse

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', line_buffering=True)

try:
    from playwright.sync_api import sync_playwright
except ImportError:  # pragma: no cover
    print('[ERR] the playwright package is not installed for this python (pip install playwright)')
    sys.exit(2)

SIZES = {1440: 900, 1024: 768, 768: 1024, 390: 844}

# ---------------------------------------------------------------------------------------------------------------------
# In-page measurement. Everything is computed from the rendered page: text line boxes, the clipping of scroll containers,
# the topmost element at the overlap point and whether what covers a text is really opaque.
# ---------------------------------------------------------------------------------------------------------------------
CHECK_JS = r'''async () => {
  try { await document.fonts.ready; } catch (e) {}
  window.scrollTo(0, 0);
  const css = (e) => getComputedStyle(e);
  const W = document.documentElement.clientWidth;
  const path = (el) => { const p = []; for (let e = el; e && e.nodeType === 1 && p.length < 5; e = e.parentElement) p.unshift(e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : '')); return p.join(' > '); };
  const shown = (el) => { if (el.checkVisibility && !el.checkVisibility({contentVisibilityAuto: true, opacityProperty: true, visibilityProperty: true})) return false; const s = css(el); return s.visibility === 'visible' && s.display !== 'none' && +s.opacity >= 0.05; };

  // alpha of one colour string (rgb/rgba/color()/oklab/hsl all end in "/ a" or a 4th component; no alpha = opaque)
  const alphaOf = (c) => { c = (c || '').trim(); if (c === 'transparent') return 0; const m = c.match(/\/\s*([\d.]+)(%?)\s*\)/); if (m) return parseFloat(m[1]) / (m[2] ? 100 : 1); const p = c.match(/^(?:rgba|hsla)\(([^)]*)\)/); if (p) { const parts = p[1].split(','); return parts.length >= 4 ? parseFloat(parts[3]) : 1; } return 1; };
  const COLOR_TOKEN = /(?:rgba?|hsla?|color|oklab|oklch|lab|lch|hwb)\([^)]*\)|transparent|#[0-9a-fA-F]{3,8}\b/g;
  const layers = (bg) => { const out = []; let depth = 0, cur = ''; for (const ch of bg) { if (ch === '(') depth++; if (ch === ')') depth--; if (ch === ',' && depth === 0) { out.push(cur.trim()); cur = ''; } else cur += ch; } if (cur.trim()) out.push(cur.trim()); return out; };
  // a background layer hides what is below only if it has no transparent stop we can detect; a raster url() cannot be inspected and counts as opaque
  const layerOpaque = (l) => { if (l === 'none') return false; if (l.startsWith('url(')) return true; const toks = l.match(COLOR_TOKEN); if (!toks || !toks.length) return false; return toks.every(t => t !== 'transparent' && alphaOf(t) >= 0.95); };
  const opaque = (el) => { const s = css(el); if (+s.opacity < 0.95) return false; if (alphaOf(s.backgroundColor) >= 0.95) return true; return layers(s.backgroundImage).some(layerOpaque); };

  // the part of a text line the user can see after every scrolling/clipping ancestor, and how many px overflow:hidden cuts off
  const visibleRect = (el, r) => {
    const v = {l: r.l, t: r.t, r: r.r, b: r.b}; let cut = 0, abs = false;
    for (let e = el; e && e !== document.documentElement; e = e.parentElement) {
      if (e === document.body) continue;
      const s = css(e);
      const applies = !abs || s.position !== 'static' || (s.transform && s.transform !== 'none') || e === el;
      if (applies && abs && e !== el) abs = false;
      if (e === el || applies) {
        const ox = s.overflowX, oy = s.overflowY;
        if (ox !== 'visible' || oy !== 'visible') {
          const a = e.getBoundingClientRect();
          if (ox !== 'visible') { const c = Math.max(v.r - a.right, a.left - v.l, 0); if ((ox === 'hidden' || ox === 'clip') && c > 3 && !(s.textOverflow === 'ellipsis') && !s.webkitLineClamp) cut = Math.max(cut, c); v.l = Math.max(v.l, a.left); v.r = Math.min(v.r, a.right); }
          if (oy !== 'visible') { v.t = Math.max(v.t, a.top); v.b = Math.min(v.b, a.bottom); }
          if (v.r - v.l < 1 || v.b - v.t < 1) return {vis: null, cut};
        }
      }
      if (s.position === 'fixed') break;
      if (s.position === 'absolute') abs = true;
    }
    return {vis: v, cut};
  };

  const modal = (() => { try { return document.querySelector('dialog:modal'); } catch (e) { return null; } })();
  const frags = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const text = n.nodeValue.replace(/\s+/g, ' ').trim(); if (text.length < 2) continue;
    const el = n.parentElement; if (!el || el.closest('script,style,noscript,template,[aria-hidden="true"],.sr-only,.visually-hidden,option,textarea,[hidden]')) continue;
    if (modal && !modal.contains(el)) continue;
    if (!shown(el)) continue;
    const range = document.createRange(); range.selectNodeContents(n);
    const rects = [...range.getClientRects()].filter(r => r.width > 1 && r.height > 1).sort((a, b) => a.top - b.top || a.left - b.left);
    const lines = [];
    for (const r of rects) { const last = lines[lines.length - 1]; if (last && Math.abs(last.t - r.top) < 3 && Math.abs(last.b - r.bottom) < 3) { last.l = Math.min(last.l, r.left); last.r = Math.max(last.r, r.right); } else lines.push({l: r.left, t: r.top, r: r.right, b: r.bottom}); }
    for (const ln of lines) { if (ln.r < -50 || ln.b < -50) continue; const vr = visibleRect(el, ln); frags.push({el, text, raw: ln, vis: vr.vis, cut: vr.cut}); }
  }
  const out = {spill: [], overlap: [], clipped: [], hscroll: document.documentElement.scrollWidth - document.documentElement.clientWidth, culprits: [], texts: frags.length};
  const seen = new Set();

  // 1) spill: a line of text wider than the box that holds it, with the excess visible
  for (const f of frags) {
    let box = f.el; while (box.parentElement && (css(box).display === 'inline' || css(box).display === 'contents')) box = box.parentElement;
    const br = box.getBoundingClientRect(); const s = css(box);
    const over = Math.max(f.raw.r - br.right, br.left - f.raw.l);
    if (over <= 3 || s.overflowX !== 'visible') continue;
    if (!f.vis) continue;
    const visOver = Math.max(f.vis.r - br.right, br.left - f.vis.l); if (visOver <= 3) continue;
    const key = path(box) + '|' + f.text.slice(0, 20); if (seen.has(key)) continue; seen.add(key);
    out.spill.push({text: f.text.slice(0, 50), by: Math.round(over), box: path(box), at: [Math.round(f.raw.l + scrollX), Math.round(f.raw.t + scrollY)]});
  }
  // 2) clipped: overflow:hidden cuts part of a line that is otherwise visible
  for (const f of frags) {
    if (!f.vis || f.cut <= 3) continue;
    const key = 'c|' + path(f.el) + '|' + f.text.slice(0, 20); if (seen.has(key)) continue; seen.add(key);
    out.clipped.push({text: f.text.slice(0, 50), by: Math.round(f.cut), box: path(f.el), at: [Math.round(f.raw.l + scrollX), Math.round(f.raw.t + scrollY)]});
  }
  // 3) overlap: two visible lines of text drawn on top of each other and nothing opaque between them
  const vis = frags.filter(f => f.vis).sort((a, b) => a.vis.t - b.vis.t);
  const inter = (a, b) => { const w = Math.min(a.r, b.r) - Math.max(a.l, b.l), h = Math.min(a.b, b.b) - Math.max(a.t, b.t); return w > 3 && h > 3 ? w * h : 0; };
  const cand = [];
  for (let i = 0; i < vis.length; i++) for (let j = i + 1; j < vis.length; j++) {
    const a = vis[i], b = vis[j]; if (b.vis.t >= a.vis.b) break;
    if (a.el === b.el) continue;
    const area = inter(a.vis, b.vis); if (!area) continue;
    const small = Math.min((a.vis.r - a.vis.l) * (a.vis.b - a.vis.t), (b.vis.r - b.vis.l) * (b.vis.b - b.vis.t)); if (area / small < 0.25) continue;
    cand.push([a, b]);
  }
  for (const [a, b] of cand) {
    if (out.overlap.length >= 40) break;
    const x0 = Math.max(a.vis.l, b.vis.l), x1 = Math.min(a.vis.r, b.vis.r), y0 = Math.max(a.vis.t, b.vis.t), y1 = Math.min(a.vis.b, b.vis.b);
    const cx = (x0 + x1) / 2, cy = (y0 + y1) / 2;
    // bring the point into the viewport so elementFromPoint can answer
    const docY = cy + scrollY; window.scrollTo(0, Math.max(0, docY - innerHeight / 2));
    const top = document.elementFromPoint(Math.min(Math.max(cx, 0), innerWidth - 1), Math.min(Math.max(docY - window.scrollY, 0), innerHeight - 1));
    window.scrollTo(0, 0);
    let covered = false;
    if (top) {
      const rel = (f) => f.el === top || f.el.contains(top) || top.contains(f.el);
      const upper = rel(a) ? a : (rel(b) ? b : null);
      if (upper) { const lower = upper === a ? b : a; for (let e = upper.el; e && !e.contains(lower.el); e = e.parentElement) if (opaque(e)) { covered = true; break; } }
      else { for (let e = top; e && e !== document.body; e = e.parentElement) if (opaque(e)) { covered = true; break; } }
    }
    if (covered) continue;
    const key = 'o|' + a.text.slice(0, 16) + '|' + b.text.slice(0, 16); if (seen.has(key)) continue; seen.add(key);
    out.overlap.push({a: a.text.slice(0, 40), b: b.text.slice(0, 40), boxA: path(a.el), boxB: path(b.el), at: [Math.round(cx + scrollX), Math.round(docY)]});
  }
  // 4) sideways page scroll: name the elements that stick out
  if (out.hscroll > 2) {
    const list = [];
    for (const el of document.body.querySelectorAll('*')) { if (list.length > 400) break; if (!shown(el)) continue; const r = el.getBoundingClientRect(); if (r.width < 2 || r.right <= W + 2) continue; const vr = visibleRect(el, {l: r.left, t: r.top, r: r.right, b: r.bottom}); if (vr.vis && vr.vis.r > W + 2) list.push({el, over: Math.round(vr.vis.r - W)}); }
    list.sort((x, y) => y.over - x.over);
    out.culprits = list.slice(0, 4).map(x => ({box: path(x.el), over: x.over}));
  }
  return out;
}'''

# Tab-like controls of the page's own toolbar (never navigation, never anything that writes).
MARK_TABS_JS = r'''() => {
  const deny = /ลบ|ลืม|ออกจากระบบ|บันทึก|เก็บ|เผยแพร่|ส่ง|ยืนยัน|อนุมัติ|ปฏิเสธ|รีเซ็ต|ออก PIN|ออกรหัส|ยกเลิก|เพิกถอน|ปิดบัญชี|ใช้การตั้งค่า|คืน|logout|delete|remove|save|submit|publish/i;
  const root = document.querySelector('#work-view, #cms-view, #gp-root, main') || document.body;
  const sel = '[role=tab], [role=tablist] button, [role=tablist] a, [data-tab], [data-device], nav.gp-tabs button';
  const out = []; let i = 0;
  for (const el of root.querySelectorAll(sel)) {
    if (el.closest('header, footer, dialog, form[method=post]') || el.disabled || el.type === 'submit') continue;
    if (el.tagName === 'A' && !(el.getAttribute('href') || '#').startsWith('#')) continue;
    const label = (el.innerText || el.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 30);
    if (!label || deny.test(label)) continue;
    const r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) continue;
    const active = ['aria-selected', 'aria-current', 'aria-pressed'].some(a => ['true', 'page'].includes(el.getAttribute(a)));
    el.setAttribute('data-audit-tab', String(i)); out.push({i, label, active}); i++;
  }
  return out;
}'''

# Selects with a placeholder option: the first real option is picked.
MARK_SELECTS_JS = r'''() => {
  const root = document.querySelector('#work-view, #cms-view, #gp-root, main') || document.body;
  const out = []; const names = new Set(); let i = 0;
  for (const el of root.querySelectorAll('select')) {
    if (el.disabled || el.closest('dialog') || !el.options.length || el.options[0].value !== '' || el.options.length < 2) continue;
    const r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) continue;
    const key = el.name || el.id || ('options:' + el.options.length + ':' + (el.options[1].text || '').slice(0, 12)); if (names.has(key)) continue; names.add(key);
    el.setAttribute('data-audit-select', String(i)); out.push({i, label: key + '=' + (el.options[1].text || '').replace(/\s+/g, ' ').trim().slice(0, 24)}); i++;
    if (i >= 5) break;
  }
  return out;
}'''

# The first "add" and the first "edit" trigger of the page (opens a form or a dialog; the write itself is blocked by the route guard).
MARK_FORMS_JS = r'''() => {
  const root = document.querySelector('#work-view, #cms-view, main') || document.body;
  const addAct = /^(new|add|add-open|form-open|create-open)$/i, editAct = /^(edit|edit-open|enroll-open)$/i;
  const addTxt = /^(เพิ่ม|ยื่นใบลาใหม่|แจ้งซ่อมใหม่|สร้าง.*ใหม่)/, editTxt = /^แก้ไข/;
  const found = {add: null, edit: null};
  for (const el of root.querySelectorAll('button, a')) {
    if (el.closest('header, footer, dialog, form[method=post]') || el.disabled || el.type === 'submit') continue;
    if (el.tagName === 'A' && !(el.getAttribute('href') || '').startsWith('#')) continue;
    const act = el.getAttribute('data-act') || ''; const t = (el.innerText || '').replace(/\s+/g, ' ').trim();
    if (/ลบ|ยกเลิกเวร|ออก PIN|รีเซ็ต|เพิกถอน|secret/.test(t)) continue;
    const r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) continue;
    let kind = null; if (addAct.test(act) || (el.tagName === 'A' && addTxt.test(t)) || (!act && addTxt.test(t))) kind = 'add'; else if (editAct.test(act) || editTxt.test(t)) kind = 'edit';
    if (kind && !found[kind]) { found[kind] = el; el.setAttribute('data-audit-form', kind); }
  }
  return Object.entries(found).filter(([, v]) => v).map(([k, v]) => ({kind: k, label: (v.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 30)}));
}'''

CANCEL_JS = r'''() => {
  const scope = document.querySelector('dialog[open]') || document.querySelector('#work-view, #cms-view, main') || document.body;
  for (const b of scope.querySelectorAll('button, a')) { const t = (b.innerText || '').replace(/\s+/g, ' ').trim(); if (/^(ยกเลิก|ปิด|ปิดหน้าต่าง|close|cancel)$/i.test(t) && !(b.type === 'submit' && b.closest('form[method=post]') && !b.closest('dialog'))) { b.click(); return true; } }
  return false;
}'''

REVEAL_JS = r'''async () => {
  const h = Math.max(innerHeight * 0.8, 200); const total = document.documentElement.scrollHeight;
  for (let y = 0, n = 0; y < total && n < 40; y += h, n++) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 90)); }
  window.scrollTo(0, 0); await new Promise(r => setTimeout(r, 450));
}'''

DENY_URL = re.compile(r'logout|signout', re.I)


# ---------------------------------------------------------------------------------------------------------------------
def parse_accounts(path):
    """Returns {'staff': {...}, 'student': {...}, 'guardian': {...}} from the accounts file (JSON, or the text file the demo installer writes)."""
    raw = open(path, encoding='utf-8-sig').read()
    if raw.lstrip().startswith('{'):
        data = json.loads(raw)
        return {k: v for k, v in data.items() if isinstance(v, dict) and v.get('login') and v.get('password')}
    lines = [l.rstrip() for l in raw.splitlines()]
    blocks, cur = [], {}
    for l in lines:
        s = l.strip()
        m = re.match(r'^(เข้าที่|อีเมล|ชื่อเข้าระบบ|รหัสผ่าน)\s*[:：]\s*(.*)$', s)
        if not m:
            continue
        k, v = m.group(1), m.group(2).strip()
        if k == 'เข้าที่':
            cur = {'url': v}
        elif k in ('อีเมล', 'ชื่อเข้าระบบ'):
            cur['login'] = v
        elif k == 'รหัสผ่าน':
            cur['password'] = v
            blocks.append(cur)
            cur = {}
    out = {}
    for b in blocks:
        if not b.get('login') or b['login'] == '-' or not b.get('password') or not b.get('url'):
            continue
        p = urlparse(b['url']).path.rstrip('/')
        kind = 'student' if p.endswith('/student') else 'guardian' if p.endswith('/guardian') else 'staff' if p.endswith('/login') else None
        if kind and kind not in out:
            out[kind] = {'login': b['login'], 'password': b['password']}
    return out


class Audit:
    def __init__(self, a):
        self.base = a.base.rstrip('/')
        self.origin = '{0.scheme}://{0.netloc}'.format(urlparse(self.base))
        self.out = a.out
        self.only = a.only
        self.widths = [int(x) for x in a.widths.split(',') if x.strip()]
        self.states = not a.no_states
        self.shard = tuple(int(x) for x in a.shard.split('/')) if a.shard else (0, 1)
        self.report = []
        self.shots = 0
        self.net = []
        self.errs = []
        self.blocked = 0
        self.open_surveys = []
        os.makedirs(self.out, exist_ok=True)

    # -- browser plumbing --------------------------------------------------------------------------------------------
    def new_page(self, browser, width=1440):
        ctx = browser.new_context(viewport={'width': width, 'height': SIZES.get(width, 900)}, locale='th-TH', color_scheme='light')
        page = ctx.new_page()
        page.on('response', lambda r: self.net.append((r.status, r.request.method, r.url)) if r.status >= 400 and r.status != 422 and r.url.startswith(self.origin) else None)
        page.on('pageerror', lambda e: self.errs.append(str(e)[:200]))
        return ctx, page

    def guard_writes(self, ctx):
        def handler(route):
            if route.request.method in ('GET', 'HEAD', 'OPTIONS'):
                route.continue_()
            else:
                self.blocked += 1
                route.abort()
        ctx.route('**/*', handler)

    @staticmethod
    def settle(page, ms=500):
        try:
            page.wait_for_load_state('networkidle', timeout=15000)
        except Exception:
            pass
        try:
            page.wait_for_function('() => !document.querySelector("[aria-busy=true]")', timeout=8000)
        except Exception:
            pass
        page.wait_for_timeout(ms)

    def goto(self, page, path, fresh_hash=True):
        self.net.clear()
        self.errs.clear()
        if fresh_hash and '#' in path:
            page.goto(self.base + '/account', wait_until='domcontentloaded')
        r = page.goto(self.base + path, wait_until='domcontentloaded')
        self.settle(page, 900 if '#' in path else 400)
        if r is not None and r.status >= 400:
            self.net.insert(0, (r.status, 'GET', r.url))
        return r

    # -- one measurement ---------------------------------------------------------------------------------------------
    def check(self, page, label, w, state):
        try:
            if state in ('first', 'sign-in'):
                page.evaluate(REVEAL_JS)
                self.settle(page, 150)
            r = page.evaluate(CHECK_JS)
        except Exception as e:  # navigation during the measurement, closed page, ...
            self.report.append({'page': label, 'width': w, 'state': state, 'error': str(e)[:200]})
            print('[ERR] {} @{} [{}]: {}'.format(label, w, state, str(e)[:120]))
            return
        # a GET answered with 5xx is asked once more: a file cache that two parallel browsers lock at the same moment answers one request with 500 and the next with 200
        live = []
        for s, m, u in list(self.net):
            if s >= 500 and m == 'GET':
                try:
                    if page.context.request.get(u, timeout=20000).status < 400:
                        continue
                except Exception:
                    pass
            live.append((s, m, u))
        http = ['{} {} {}'.format(s, m, u.replace(self.origin, '')) for s, m, u in live]
        errs = list(self.errs)
        entry = {'page': label, 'width': w, 'state': state, 'texts': r['texts'], 'hscroll': r['hscroll'], 'culprits': r['culprits'],
                 'spill': r['spill'], 'overlap': r['overlap'], 'clipped': r['clipped'], 'http': http, 'js_errors': errs}
        self.report.append(entry)
        if len(self.report) % 15 == 0:
            self.dump()  # partial report: a crashed run still leaves its results
        bad = len(r['spill']) + len(r['overlap']) + len(r['clipped']) + len(http) + len(errs) + (1 if r['hscroll'] > 2 else 0)
        print('{} {:44} @{:<5} [{}] spill={} overlap={} clipped={} hscroll={} http={} js={}'.format('[WARN]' if bad else '[OK]  ', label[:44], w, state[:34], len(r['spill']), len(r['overlap']), len(r['clipped']), r['hscroll'], len(http), len(errs)))
        if bad:
            self.shoot(page, entry)

    def shoot(self, page, entry):
        first = (entry['overlap'] or entry['spill'] or entry['clipped'] or [None])[0]
        y = first['at'][1] if first else 0
        name = re.sub(r'[^a-z0-9]+', '-', '{}-{}-{}'.format(entry['page'], entry['width'], entry['state']).lower()).strip('-')[:120]
        try:
            page.evaluate('(y) => window.scrollTo(0, Math.max(0, y - 220))', y)
            page.wait_for_timeout(120)
            page.screenshot(path=os.path.join(self.out, name + '.png'))
            entry['screenshot'] = name + '.png'
        except Exception:
            pass

    # -- driving ----------------------------------------------------------------------------------------------------
    @staticmethod
    def click_tab(page, label):
        """Clicks the tab-like control with this label, found again on the current DOM (a click re-renders the toolbar and shifts positions)."""
        hit = next((t for t in page.evaluate(MARK_TABS_JS) if t['label'] == label), None)
        if hit is None:
            return False
        page.locator('[data-audit-tab="{}"]'.format(hit['i'])).first.click(timeout=4000)
        return True

    def drive(self, page, path, w, reload):
        """After the first check: placeholder selects, the page's own tabs, the first add and edit form. `reload` brings the page back to its first state."""
        try:
            sels = page.evaluate(MARK_SELECTS_JS)
        except Exception:
            sels = []
        for s in sels:
            reload()
            try:
                page.evaluate(MARK_SELECTS_JS)
                page.evaluate('''(i) => { const el = document.querySelector('[data-audit-select="' + i + '"]'); el.value = el.options[1].value; el.dispatchEvent(new Event('input', {bubbles: true})); el.dispatchEvent(new Event('change', {bubbles: true})); }''', s['i'])
            except Exception as e:
                print('[SKIP] {} select {}: {}'.format(path, s['label'], str(e)[:80]))
                continue
            self.settle(page, 500)
            self.check(page, path, w, 'select ' + s['label'])
        try:
            reload()
            labels = [t['label'] for t in page.evaluate(MARK_TABS_JS) if not t['active']][:10]
        except Exception:
            labels = []
        for label in labels:
            try:
                if not self.click_tab(page, label):
                    print('[SKIP] {} tab {}: no longer on the page'.format(path, label))
                    continue
            except Exception as e:
                print('[SKIP] {} tab {}: {}'.format(path, label, str(e)[:80]))
                continue
            self.settle(page, 500)
            self.check(page, path, w, 'tab ' + label)
        try:
            reload()
            forms = page.evaluate(MARK_FORMS_JS)
        except Exception:
            forms = []
        for f in forms:
            try:
                reload()
                page.evaluate(MARK_FORMS_JS)
                page.locator('[data-audit-form="{}"]'.format(f['kind'])).first.click(timeout=4000)
            except Exception as e:
                print('[SKIP] {} {} form {}: {}'.format(path, f['kind'], f['label'], str(e)[:80]))
                continue
            self.settle(page, 600)
            self.check(page, path, w, '{} form {}'.format(f['kind'], f['label']))
            try:
                if not page.evaluate(CANCEL_JS):
                    page.keyboard.press('Escape')
            except Exception:
                pass

    def run_page(self, page, path, w, driver=None, hashes=True):
        page.set_viewport_size({'width': w, 'height': SIZES.get(w, 900)})
        try:
            self.goto(page, path, hashes)
        except Exception as e:
            self.report.append({'page': path, 'width': w, 'state': 'first', 'error': str(e)[:200]})
            print('[ERR] {} @{}: {}'.format(path, w, str(e)[:100]))
            return
        self.check(page, path, w, 'first')
        if driver and self.states:
            driver(page, path, w, lambda: self.goto(page, path, hashes))

    # -- page lists -------------------------------------------------------------------------------------------------
    def staff_pages(self, page):
        self.goto(page, '/work', False)
        hrefs = page.evaluate('''() => [...new Set([...document.querySelectorAll('a[data-nav-key], a[data-work-key]')].map(a => a.getAttribute('href')))].filter(h => h)''')
        out, seen = [], set()
        for h in hrefs + ['/admin/modules', '/admin/users', '/account', '/whats-new']:
            p = h.replace(self.origin, '')
            if p.startswith(self.base.replace(self.origin, '')):
                p = p[len(self.base.replace(self.origin, '')):]
            if DENY_URL.search(p) or p in seen or not p.startswith('/'):
                continue
            seen.add(p)
            out.append(p)
        return out

    def harvest_surveys(self, page):
        try:
            rows = page.context.request.get(self.base + '/api/surveys', headers={'Accept': 'application/json'}, timeout=20000).json().get('data', [])
            return [r['slug'] for r in rows if r.get('slug') and r.get('status') == 'open'][:2]
        except Exception:
            return []

    def public_pages(self, page):
        """Fixed public routes plus the first detail page of every public list (news article, album, download, event, job...) and an open survey."""
        lists = ['/portal/news', '/portal/albums', '/portal/downloads', '/portal/events', '/portal/jobs', '/portal/procurement', '/portal/pages', '/portal/newsletters', '/portal/videos', '/portal/people', '/portal/services', '/portal/groups', '/portal/notices']
        fixed = ['/', '/portal', '/portal/directory', '/portal/ita', '/portal/contact', '/portal/complaints', '/portal/complaints/status', '/portal/search?q=%E0%B8%82%E0%B9%88%E0%B8%B2%E0%B8%A7', '/portal/certificates/verify', '/login', '/student', '/guardian', '/manual/']
        out = list(fixed)
        prefix = self.base.replace(self.origin, '')
        for lp in lists:
            try:
                r = page.goto(self.base + lp, wait_until='domcontentloaded')
            except Exception:
                continue
            if r is None or r.status >= 400:
                print('[SKIP] public list {} answers {}'.format(lp, r.status if r else '-'))
                continue
            out.append(lp)
            hrefs = page.evaluate('() => [...document.querySelectorAll("a[href]")].map(a => a.href)')
            for h in hrefs:
                p = h.replace(self.origin, '').split('#')[0]
                if p.startswith(prefix + lp + '/') and p[len(prefix):] not in out and not p.endswith('.csv'):
                    out.append(p[len(prefix):])
                    break
        # an open survey (slugs come from the staff survey list, the public site does not link them)
        for slug in self.open_surveys:
            out.append('/portal/surveys/' + slug)
        return out

    # -- portal sign-in and tab walk -----------------------------------------------------------------------------------
    def portal_walk(self, browser, kind, acct):
        for w in self.widths:
            ctx, page = self.new_page(browser, w)
            try:
                self.goto(page, '/' + kind, False)
                page.fill('input[name=code]' if kind == 'student' else 'input[name=email], input[name=login], input[name=code]', acct['login'])
                page.fill('input[type=password]', acct['password'])
                page.click('form button[type=submit]')
                self.settle(page, 800)
                self.guard_writes(ctx)

                def walk(page, path, w, reload):
                    labels = [t['label'] for t in page.evaluate(MARK_TABS_JS) if not t['active']]
                    for label in labels:
                        t = {'label': label}
                        try:
                            if not self.click_tab(page, label):
                                continue
                        except Exception as e:
                            print('[SKIP] /{} tab {}: {}'.format(kind, label, str(e)[:80]))
                            continue
                        self.settle(page, 600)
                        self.check(page, '/' + kind + ' (signed in)', w, 'tab ' + label)
                        # day/month buttons of the tab (read-only) are visited too
                        extra = page.evaluate('''() => [...document.querySelectorAll('#gp-root [data-act=day], #gp-root [data-act=month]:not([disabled])')].map((b, i) => { b.setAttribute('data-audit-x', String(i)); return (b.innerText || '').trim().slice(0, 20); })''')
                        for i, lab in enumerate(extra[:8]):
                            try:
                                page.evaluate('''() => [...document.querySelectorAll('#gp-root [data-act=day], #gp-root [data-act=month]:not([disabled])')].forEach((b, i) => b.setAttribute('data-audit-x', String(i)))''')
                                page.locator('[data-audit-x="{}"]'.format(i)).first.click(timeout=4000)
                            except Exception:
                                continue
                            self.settle(page, 400)
                            self.check(page, '/' + kind + ' (signed in)', w, 'tab {} / {}'.format(t['label'], lab))
                self.net.clear()
                self.errs.clear()
                self.settle(page, 300)
                self.check(page, '/' + kind + ' (signed in)', w, 'first')
                if self.states:
                    walk(page, '/' + kind, w, None)
                    # a guardian with several children: the same tabs for the next child (read-only select, no placeholder)
                    kids = page.evaluate('() => { const s = document.querySelector("#gp-child"); return s ? s.options.length : 0; }')
                    if kids > 1:
                        page.evaluate('() => { const s = document.querySelector("#gp-child"); s.selectedIndex = 1; s.dispatchEvent(new Event("change", {bubbles: true})); }')
                        self.settle(page, 600)
                        self.check(page, '/' + kind + ' (signed in)', w, 'second child')
                        walk(page, '/' + kind, w, None)
            except Exception as e:
                self.report.append({'page': '/' + kind + ' (signed in)', 'width': w, 'state': 'sign-in', 'error': str(e)[:200]})
                print('[ERR] /{} sign-in @{}: {}'.format(kind, w, str(e)[:120]))
            ctx.close()

    # -- main -------------------------------------------------------------------------------------------------------
    def run(self, accounts, channel):
        want = lambda p: (not self.only) or any(o in p for o in self.only)
        with sync_playwright() as p:
            browser = p.chromium.launch() if channel == 'bundled' else p.chromium.launch(channel=channel)
            started = time.time()
            # staff
            if 'staff' in accounts:
                ctx, page = self.new_page(browser)
                try:
                    self.goto(page, '/login', False)
                    page.fill('input[name=email]', accounts['staff']['login'])
                    page.fill('input[name=password]', accounts['staff']['password'])
                    page.click('button[type=submit]')
                    self.settle(page, 600)
                    if '/login' in page.url:
                        raise RuntimeError('staff sign-in failed')
                except Exception as e:
                    print('[ERR] staff sign-in: {}'.format(e))
                    self.report.append({'page': '/login', 'width': 1440, 'state': 'sign-in', 'error': str(e)[:200]})
                    ctx.close()
                else:
                    self.guard_writes(ctx)
                    self.open_surveys = self.harvest_surveys(page)
                    pages = [x for i, x in enumerate(x for x in self.staff_pages(page) if want(x)) if i % self.shard[1] == self.shard[0]]
                    print('[INFO] staff pages: {} x {} widths'.format(len(pages), len(self.widths)))
                    for path in pages:
                        for w in self.widths:
                            self.run_page(page, path, w, self.drive)
                    ctx.close()
            else:
                print('[WARN] no staff account in the accounts file: staff pages not checked')
            # public (and the sign-in pages of the portals)
            ctx, page = self.new_page(browser)
            pub = [x for i, x in enumerate(x for x in self.public_pages(page) if want(x)) if i % self.shard[1] == self.shard[0]]
            print('[INFO] public pages: {} x {} widths'.format(len(pub), len(self.widths)))
            for path in pub:
                for w in self.widths:
                    self.run_page(page, path, w, None, False)
            ctx.close()
            # portals
            for kind in ('student', 'guardian'):
                if not want('/' + kind) or self.shard[0] != (0 if kind == 'student' else 1) % self.shard[1]:
                    continue
                if kind in accounts:
                    self.portal_walk(browser, kind, accounts[kind])
                else:
                    print('[WARN] no {} account in the accounts file: signed-in {} portal not checked'.format(kind, kind))
            browser.close()
        return self.finish(time.time() - started)

    def dump(self):
        json.dump(self.report, open(os.path.join(self.out, 'report.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        json.dump({'blocked': self.blocked}, open(os.path.join(self.out, 'meta.json'), 'w', encoding='utf-8'))

    def finish(self, secs):
        self.dump()
        lines, bad = [], 0
        for e in self.report:
            head = '## {} @ {}px [{}]'.format(e['page'], e['width'], e.get('state', ''))
            body = []
            if e.get('error'):
                body.append('  error: ' + e['error'])
            for s in e.get('spill', []):
                body.append("  spill by {}px: '{}' in {}".format(s['by'], s['text'], s['box']))
            for s in e.get('clipped', []):
                body.append("  clipped by {}px: '{}' in {}".format(s['by'], s['text'], s['box']))
            for o in e.get('overlap', []):
                body.append("  overlap: '{}' x '{}'\n     A={}\n     B={}".format(o['a'], o['b'], o['boxA'], o['boxB']))
            if e.get('hscroll', 0) > 2:
                body.append('  page scrolls sideways by {}px; widest: {}'.format(e['hscroll'], '; '.join('{} (+{})'.format(c['box'], c['over']) for c in e.get('culprits', []))))
            for h in e.get('http', []):
                body.append('  http: ' + h)
            for j in e.get('js_errors', []):
                body.append('  js-error: ' + j)
            if body:
                bad += 1
                lines.append(head)
                lines.extend(body)
        open(os.path.join(self.out, 'findings.txt'), 'w', encoding='utf-8').write('\n'.join(lines) + ('\n' if lines else ''))
        pages = len({e['page'] for e in self.report})
        firsts = sum(1 for e in self.report if e.get('state') == 'first')
        print('[DONE] checks={} (pages={} first-state checks={} driven-state checks={}) with findings={} blocked-writes={} time={:.0f}s out={}'.format(
            len(self.report), pages, firsts, len(self.report) - firsts, bad, self.blocked, secs, self.out))
        return 1 if bad else 0


def run_jobs(a, jobs):
    """Runs the audit in `jobs` browser processes (each takes every n-th page) and merges their reports; same exit code rule as a single run."""
    import subprocess
    import threading
    started = time.time()
    os.makedirs(a.out, exist_ok=True)
    procs = []
    for k in range(jobs):
        cmd = [sys.executable, '-I', os.path.abspath(__file__), '--base', a.base, '--out', os.path.join(a.out, 'shard-{}'.format(k)), '--widths', a.widths, '--channel', a.channel, '--shard', '{}/{}'.format(k, jobs)]
        if a.accounts:
            cmd += ['--accounts', a.accounts]
        for o in a.only:
            cmd += ['--only', o]
        if a.no_states:
            cmd.append('--no-states')
        procs.append(subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=dict(os.environ, PYTHONIOENCODING='utf-8')))

    def pump(k, proc):
        for line in iter(proc.stdout.readline, b''):
            print('[{}] {}'.format(k, line.decode('utf-8', 'replace').rstrip()), flush=True)
    threads = [threading.Thread(target=pump, args=(k, pr), daemon=True) for k, pr in enumerate(procs)]
    for t in threads:
        t.start()
    codes = [pr.wait() for pr in procs]
    for t in threads:
        t.join(timeout=5)
    merged, blocked = [], 0
    for k in range(jobs):
        d = os.path.join(a.out, 'shard-{}'.format(k))
        try:
            rep = json.load(open(os.path.join(d, 'report.json'), encoding='utf-8'))
            blocked += json.load(open(os.path.join(d, 'meta.json'), encoding='utf-8')).get('blocked', 0)
        except Exception:
            rep = [{'page': 'shard {}'.format(k), 'width': 0, 'state': 'crash', 'error': 'the browser process ended without a report (exit code {})'.format(codes[k])}]
        for e in rep:
            if e.get('screenshot'):
                e['screenshot'] = 'shard-{}/{}'.format(k, e['screenshot'])
        merged.extend(rep)
    audit = Audit(a)
    audit.report = merged
    audit.blocked = blocked
    return audit.finish(time.time() - started)


def main():
    ap = argparse.ArgumentParser(description='Layout audit of a running Saan install in a real browser.')
    ap.add_argument('--base', default=os.environ.get('SAAN_AUDIT_BASE'), help='base URL of the install, e.g. http://localhost/saan (env SAAN_AUDIT_BASE)')
    ap.add_argument('--accounts', default=os.environ.get('SAAN_AUDIT_ACCOUNTS'), help='accounts file (env SAAN_AUDIT_ACCOUNTS)')
    ap.add_argument('--out', default=os.environ.get('SAAN_AUDIT_OUT', './layout-audit-out'), help='folder for findings.txt, report.json and screenshots')
    ap.add_argument('--only', action='append', default=[], help='check only pages whose path contains this text (repeatable)')
    ap.add_argument('--widths', default=os.environ.get('SAAN_AUDIT_WIDTHS', '1440,1024,768,390'))
    ap.add_argument('--channel', default=os.environ.get('SAAN_AUDIT_CHANNEL', 'chrome'), help='browser channel: chrome (default), msedge or bundled')
    ap.add_argument('--no-states', action='store_true', help='first state of every page only (no selects, tabs, forms, portal tabs)')
    ap.add_argument('--jobs', type=int, default=int(os.environ.get('SAAN_AUDIT_JOBS', '1')), help='number of parallel browser processes (default 1)')
    ap.add_argument('--shard', default=None, help=argparse.SUPPRESS)
    a = ap.parse_args()
    if not a.base:
        ap.error('--base (or SAAN_AUDIT_BASE) is required')
    if a.jobs > 1 and not a.shard:
        return run_jobs(a, a.jobs)
    accounts = parse_accounts(a.accounts) if a.accounts else {}
    if not accounts:
        print('[WARN] no accounts: only the public pages are checked')
    audit = Audit(a)
    code = audit.run(accounts, a.channel)
    json.dump({'blocked': audit.blocked}, open(os.path.join(a.out, 'meta.json'), 'w', encoding='utf-8'))
    return code


if __name__ == '__main__':
    sys.exit(main())