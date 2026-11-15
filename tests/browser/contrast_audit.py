#!/usr/bin/env python3
"""Contrast audit of a running Saan install, measured on the rendered pixels (Playwright, Chrome).

CSS-only checkers cannot judge text on glass: the colour behind a glass panel is the panel over a blurred scene. This tool opens the same
pages and states as layout_audit.py (same arguments, plus --scheme light|dark), and for every visible line of text:
  1. reads the computed text colour (with the opacity of its ancestors),
  2. hides all text on the page, takes a screenshot, and reads the pixels behind the text box,
  3. computes the WCAG 2.x contrast of the text colour against each of those pixels and keeps the worst case (3rd percentile),
  4. fails under 4.5:1 (3:1 for large text: >= 24px, or >= 18.66px and bold).
It also checks the boundary of every bordered control (input, select, textarea, bordered button): the border or the fill must reach 3:1
against what is outside the control, and the focus ring of the first focusable controls of a page (outline >= 2px, colour 3:1 against
the pixel next to it). Disabled controls are exempt.

Exit code 0 = zero findings, 1 = at least one finding or a page that could not be checked.
Needs: playwright, numpy, Pillow. Usage: python -I tests/browser/contrast_audit.py --base URL --accounts FILE --out DIR [--scheme dark] [--jobs 4]
"""
import io
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import layout_audit as la  # noqa: E402

try:
    import numpy as np
    from PIL import Image
except ImportError:  # pragma: no cover
    print('[ERR] contrast_audit.py needs numpy and Pillow (pip install numpy pillow)')
    sys.exit(2)

COLLECT_JS = r'''() => {
  const css = (e) => getComputedStyle(e);
  const vw = innerWidth, vh = innerHeight;
  const path = (el) => { const p = []; for (let e = el; e && e.nodeType === 1 && p.length < 4; e = e.parentElement) p.unshift(e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : '')); return p.join(' > '); };
  const shown = (el) => { if (el.checkVisibility && !el.checkVisibility({contentVisibilityAuto: true, opacityProperty: true, visibilityProperty: true})) return false; const s = css(el); return s.visibility === 'visible' && s.display !== 'none'; };
  const opacityOf = (el) => { let o = 1; for (let e = el; e && e !== document.documentElement; e = e.parentElement) o *= +css(e).opacity; return o; };
  const clip = (el, r) => { const v = {l: r.left, t: r.top, r: r.right, b: r.bottom};
    for (let e = el; e && e !== document.body && e !== document.documentElement; e = e.parentElement) { const s = css(e); if (s.overflowX !== 'visible' || s.overflowY !== 'visible') { const a = e.getBoundingClientRect(); if (s.overflowX !== 'visible') { v.l = Math.max(v.l, a.left); v.r = Math.min(v.r, a.right); } if (s.overflowY !== 'visible') { v.t = Math.max(v.t, a.top); v.b = Math.min(v.b, a.bottom); } } if (s.position === 'fixed') break; }
    v.l = Math.max(v.l, 0); v.t = Math.max(v.t, 0); v.r = Math.min(v.r, vw); v.b = Math.min(v.b, vh); return v.r - v.l >= 2 && v.b - v.t >= 4 ? v : null; };
  const modal = (() => { try { return document.querySelector('dialog:modal'); } catch (e) { return null; } })();
  const texts = [], seen = new Set();
  // fixed layers (a toast, a floating bar) cover what is under them even when they ignore the pointer; text under one is judged where nothing floats over it
  const floats = [...document.body.querySelectorAll('*')].filter(e => { const s = css(e); if (s.position !== 'fixed' || s.display === 'none' || s.visibility === 'hidden' || +s.opacity < 0.05) return false; const r = e.getBoundingClientRect(); return r.width > 4 && r.height > 4 && r.right > 0 && r.bottom > 0 && r.left < vw && r.top < vh; }).map(e => ({e, r: e.getBoundingClientRect()}));
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const text = n.nodeValue.replace(/\s+/g, ' ').trim(); if (text.length < 1) continue;
    const el = n.parentElement; if (!el || el.closest('script,style,noscript,template,option,[hidden],[disabled],[aria-disabled="true"],.sr-only,.visually-hidden')) continue;
    if (modal && !modal.contains(el)) continue;
    if (!shown(el)) continue;
    const s = css(el); if (s.webkitTextFillColor && s.webkitTextFillColor !== s.color && /rgba\(.*,\s*0\)|transparent/.test(s.webkitTextFillColor)) continue;
    const range = document.createRange(); range.selectNodeContents(n);
    for (const r of range.getClientRects()) {
      if (r.width < 2 || r.height < 4) continue;
      const v = clip(el, r); if (!v) continue;
      // covered by something that is not part of this text (an overlay, a dialog): not what the reader sees here
      // part of the line under something else (a sticky bar it is scrolled below): it is judged where it is fully visible
      const ins = Math.max(1, (v.b - v.t) * 0.3);
      const probes = [[(v.l + v.r) / 2, (v.t + v.b) / 2], [v.l + 1, v.t + ins], [v.r - 1, v.t + ins], [v.l + 1, v.b - ins], [v.r - 1, v.b - ins]];
      if (probes.some(([px, py]) => { const top = document.elementFromPoint(px, py); return top && !(top === el || el.contains(top) || top.contains(el)); })) continue;
      if (floats.some(({e, r: b}) => !e.contains(el) && !el.contains(e) && v.l < b.right && v.r > b.left && v.t < b.bottom && v.b > b.top)) continue;
      const key = path(el) + '|' + text.slice(0, 24) + '|' + Math.round(v.t / 40);
      if (seen.has(key)) continue; seen.add(key);
      texts.push({text: text.slice(0, 36), x: v.l, y: v.t, w: v.r - v.l, h: v.b - v.t, color: s.color, opacity: opacityOf(el), size: parseFloat(s.fontSize), weight: parseInt(s.fontWeight) || 400, box: path(el)});
    }
  }
  const controls = [];
  for (const el of document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]):not([type=file]),select,textarea,button,summary,[role=tab]')) {
    if (el.disabled || el.closest('[hidden],dialog:not([open])') || !shown(el)) continue;
    if (modal && !modal.contains(el)) continue;
    const r = el.getBoundingClientRect(); if (r.width < 12 || r.height < 12) continue;
    const v = clip(el, r); if (!v || v.r - v.l < r.width - 1 || v.b - v.t < r.height - 1) continue;
    const s = css(el), bw = parseFloat(s.borderTopWidth) || 0;
    const bordered = bw > 0 && s.borderTopStyle !== 'none';
    const field = el.matches('input,select,textarea');
    if (!field && !bordered) continue;
    const cx = (v.l + v.r) / 2, cy = (v.t + v.b) / 2, top = document.elementFromPoint(cx, cy); if (top && !(top === el || el.contains(top))) continue;
    controls.push({x: r.left, y: r.top, w: r.width, h: r.height, bw: Math.max(1, Math.round(bw)), box: path(el), name: (el.innerText || el.getAttribute('aria-label') || el.name || el.type || '').replace(/\s+/g, ' ').trim().slice(0, 24)});
  }
  return {texts, controls, height: document.documentElement.scrollHeight, vh};
}'''

FOCUS_JS = r'''() => {
  const out = [];
  const path = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/)[0] : '');
  const cands = [...document.querySelectorAll('main a[href], main button, main input:not([type=hidden]), .school-nav a, .school-nav button')].filter(e => { const r = e.getBoundingClientRect(); return !e.disabled && !e.closest('[inert]') && r.width > 20 && r.height > 20 && r.top > 0 && r.bottom < innerHeight && r.left >= 0 && r.right <= innerWidth && getComputedStyle(e).visibility === 'visible'; });
  const pick = [cands[0], cands.find(e => e.closest('main') && e.tagName === 'BUTTON'), cands.find(e => e.closest('.school-nav'))].filter(Boolean);
  for (const el of [...new Set(pick)].slice(0, 3)) {
    el.focus({focusVisible: true});
    if (document.activeElement !== el) continue;
    const s = getComputedStyle(el), r = el.getBoundingClientRect();
    out.push({x: r.left, y: r.top, w: r.width, h: r.height, style: s.outlineStyle, width: parseFloat(s.outlineWidth) || 0, color: s.outlineColor, offset: parseFloat(s.outlineOffset) || 0, box: path(el)});
  }
  return out;
}'''

# transitions are switched off in the same rule: a colour that fades out during the screenshot would leave half-visible glyphs in the pixels that are measured
HIDE_TEXT_CSS = '*,*::before,*::after{color:transparent!important;-webkit-text-fill-color:transparent!important;text-shadow:none!important;caret-color:transparent!important;transition:none!important;animation:none!important}input::placeholder,textarea::placeholder{color:transparent!important}'


def parse_color(s):
    """CSS colour string -> (r, g, b, a) with channels 0-255 and alpha 0-1; handles rgb(), rgba() and color(srgb ...)."""
    nums = [float(x) for x in re.findall(r'-?\d*\.?\d+(?:e-?\d+)?', s.split('(', 1)[1].split(')', 1)[0].replace('/', ' '))]
    if s.startswith('color(srgb') and len(nums) >= 3:
        nums = [nums[0] * 255, nums[1] * 255, nums[2] * 255] + nums[3:]
    r, g, b = nums[0], nums[1], nums[2]
    a = nums[3] if len(nums) > 3 else 1.0
    return r, g, b, a


def lum(rgb):
    c = np.asarray(rgb, dtype=np.float64) / 255.0
    c = np.where(c <= 0.03928, c / 12.92, ((c + 0.055) / 1.055) ** 2.4)
    return 0.2126 * c[..., 0] + 0.7152 * c[..., 1] + 0.0722 * c[..., 2]


def ratio(l1, l2):
    hi, lo = np.maximum(l1, l2), np.minimum(l1, l2)
    return (hi + 0.05) / (lo + 0.05)


class ContrastAudit(la.Audit):
    def hide_text(self, page, on):
        page.evaluate('''([on, css]) => { let s = document.getElementById('sg-hide-text'); if (on) { if (!s) { s = document.createElement('style'); s.id = 'sg-hide-text'; document.head.appendChild(s); } s.textContent = css; } else if (s) s.remove(); }''', [on, HIDE_TEXT_CSS])

    def shot(self, page):
        self.hide_text(page, True)
        try:
            page.wait_for_timeout(60)
            raw = page.screenshot()
            if os.environ.get('SAAN_CONTRAST_DUMPSHOT'):
                open(os.path.join(self.out, 'hidden-text-last.png'), 'wb').write(raw)
            return np.asarray(Image.open(io.BytesIO(raw)).convert('RGB')).astype(np.float64)
        finally:
            self.hide_text(page, False)

    def stable_sample(self, page):
        # collect the lines of text, take the text-free screenshot, collect again: if anything moved in between (an async list, a banner that was inserted) start over
        for attempt in range(4):
            got = page.evaluate(COLLECT_JS)
            img = self.shot(page)
            again = page.evaluate(COLLECT_JS)
            where = {}
            for t in again['texts']:
                where.setdefault((t['box'], t['text']), []).append((round(t['x']), round(t['y'])))
            moved = [t for t in got['texts'] if (t['box'], t['text']) in where and not any(abs(px - t['x']) <= 1.5 and abs(py - t['y']) <= 1.5 for px, py in where[(t['box'], t['text'])])]
            if not moved:
                return got, img
            page.wait_for_timeout(400)
        return got, img

    def crop(self, region, key):
        # the pixels that were measured, enlarged, so a finding can be looked at (first 40 per run)
        try:
            folder = os.path.join(self.out, 'crops')
            os.makedirs(folder, exist_ok=True)
            if len(os.listdir(folder)) >= 40:
                return
            name = re.sub(r'[^A-Za-z0-9]+', '-', '{}-{}'.format(key[0], key[1]))[-80:]
            h, w = region.shape[:2]
            Image.fromarray(region.astype('uint8')).resize((max(w, 1) * 4, max(h, 1) * 4), Image.NEAREST).save(os.path.join(folder, '{}-{}.png'.format(len(os.listdir(folder)), name)))
        except Exception:
            pass

    def measure(self, page, first):
        found, n_text, seen = {}, 0, set()
        # entrance animations and colour transitions are stopped first: positions and colours are then the settled ones, the same in the measurement and in the screenshot
        page.add_style_tag(content='*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}')
        page.wait_for_timeout(120)
        info = page.evaluate('() => ({h: document.documentElement.scrollHeight, vh: innerHeight})')
        vh = info['vh']
        steps = sorted({int(round(i * max(info['h'] - vh, 0) / 11)) if info['h'] > vh else 0 for i in range(12 if info['h'] > vh else 1)})
        for y in steps:
            page.evaluate('(y) => window.scrollTo(0, y)', y)
            page.wait_for_function('() => new Promise(r => { const a = scrollY; setTimeout(() => r(scrollY === a), 120); })', timeout=5000)
            got, img = self.stable_sample(page)
            H, W, _ = img.shape
            for t in got['texts']:
                # the box of a line is a little taller than its glyphs: the pixels of a pill's own border at the top and bottom edge are not 'behind the text'
                inset = max(1.0, t['h'] * 0.3)
                x0, y0 = int(max(t['x'] + 1, 0)), int(max(t['y'] + inset, 0))
                x1, y1 = int(min(t['x'] + t['w'] - 1, W)), int(min(t['y'] + t['h'] - inset, H))
                if x1 - x0 < 2 or y1 - y0 < 3:
                    continue
                key = (t['box'], t['text'])
                px = img[y0:y1, x0:x1].reshape(-1, 3)
                if os.environ.get('SAAN_CONTRAST_DEBUGBOX') and os.environ['SAAN_CONTRAST_DEBUGBOX'] in t['box']:
                    print('DEBUG', t['text'], 'rect', t['x'], t['y'], t['w'], t['h'], 'scroll step', y, 'img', img.shape, [int(img[int(t['y']) + d, int(t['x']) + 5][0]) for d in range(-20, 40, 4)])
                r, g, b, a = parse_color(t['color'])
                a *= max(0.0, min(1.0, t['opacity']))
                if a <= 0.02:
                    continue
                eff = np.asarray([r, g, b]) * a + px * (1 - a)
                cr = ratio(lum(eff), lum(px))
                worst = float(np.percentile(cr, 3))
                large = t['size'] >= 24 or (t['size'] >= 18.66 and t['weight'] >= 700)
                need = 3.0 if large else 4.5
                n_text += 1
                if worst < need and (key not in found or worst < found[key]['ratio']):
                    self.crop(img[y0:y1, x0:x1], key)
                    if os.environ.get('SAAN_CONTRAST_DUMPSHOT'):
                        full = img.astype('uint8').copy()
                        full[max(y0 - 1, 0):y0, x0:x1] = [255, 0, 255]
                        full[y1:y1 + 1, x0:x1] = [255, 0, 255]
                        Image.fromarray(full).save(os.path.join(self.out, 'hidden-step-{}.png'.format(len(found))))
                    found[key] = ({'ratio': worst, 'need': need, 'what': "'{}' {}px color={} behind rgb({})..rgb({}) in {}".format(t['text'], round(t['size'], 1), t['color'], ','.join(str(int(v)) for v in px.min(axis=0)), ','.join(str(int(v)) for v in px.max(axis=0)), t['box']), 'at': [int(t['x']), int(t['y'] + y)]})
            for c in got['controls']:
                x, yy, w, h = int(round(c['x'])), int(round(c['y'])), int(round(c['w'])), int(round(c['h']))
                if x < 4 or yy < 4 or x + w > W - 4 or yy + h > H - 4 or w < 12 or h < 12:
                    continue
                key = ('c', c['box'], c['name'])
                # per side: the pixel across the border band that differs most from the pixel just outside, then the median of the four sides
                bw = c['bw']
                sides = [(img[yy + h // 2, x - 3], [img[yy + h // 2, x + d] for d in range(-1, bw + 1)]),
                         (img[yy + h // 2, x + w + 2], [img[yy + h // 2, x + w - 1 - d] for d in range(-1, bw + 1)]),
                         (img[yy - 3, x + w // 2], [img[yy + d, x + w // 2] for d in range(-1, bw + 1)]),
                         (img[yy + h + 2, x + w // 2], [img[yy + h - 1 - d, x + w // 2] for d in range(-1, bw + 1)])]
                edge_ratios = [max(float(ratio(lum(px), lum(out))) for px in band) for out, band in sides]
                fill = img[yy + h // 2, x + w // 2]
                outside = np.median(np.stack([s[0] for s in sides]), axis=0)
                best = float(max(np.median(edge_ratios), ratio(lum(fill), lum(outside))))
                if best < 3.0 and (key not in found or best < found[key]['ratio']):
                    found[key] = {'ratio': best, 'need': 3.0, 'what': "control boundary '{}' in {}".format(c['name'], c['box']), 'at': [x, yy + y]}
        findings = list(found.values())
        page.evaluate('() => window.scrollTo(0, 0)')
        if first:
            for f in page.evaluate(FOCUS_JS):
                if f['style'] == 'none' or f['width'] < 2:
                    findings.append({'ratio': 1.0, 'need': 3.0, 'what': 'focus ring missing or thinner than 2px on {}'.format(f['box']), 'at': [0, 0]})
                    continue
                img = np.asarray(Image.open(io.BytesIO(page.screenshot())).convert('RGB')).astype(np.float64)
                H, W, _ = img.shape
                gap = int(round(f['offset'] + f['width'])) + 2
                x, yy, w, h = int(f['x']), int(f['y']), int(f['w']), int(f['h'])
                spots = [(yy + h // 2, x - gap), (yy + h // 2, x + w + gap), (yy - gap, x + w // 2), (yy + h + gap, x + w // 2)]
                spots = [(py, px) for py, px in spots if 0 <= py < H and 0 <= px < W]
                if not spots:
                    continue
                r, g, b, a = parse_color(f['color'])
                outside = np.median(np.stack([img[py, px] for py, px in spots]), axis=0)
                ring = np.asarray([r, g, b]) * a + outside * (1 - a)
                cr = float(ratio(lum(ring), lum(outside)))
                if cr < 3.0:
                    findings.append({'ratio': cr, 'need': 3.0, 'what': 'focus ring {} on {}'.format(f['color'], f['box']), 'at': [x, yy]})
                page.evaluate('() => document.activeElement && document.activeElement.blur()')
        return findings, n_text

    def check(self, page, label, w, state):
        try:
            if state in ('first', 'sign-in'):
                page.evaluate(la.REVEAL_JS)
                self.settle(page, 150)
            findings, n_text = self.measure(page, state == 'first')
        except Exception as e:
            self.report.append({'page': label, 'width': w, 'state': state, 'error': str(e)[:200]})
            print('[ERR] {} @{} [{}]: {}'.format(label, w, state, str(e)[:120]))
            return
        entry = {'page': label, 'width': w, 'state': state, 'texts': n_text, 'contrast': findings, 'hscroll': 0, 'spill': [], 'overlap': [], 'clipped': [], 'http': [], 'js_errors': []}
        self.report.append(entry)
        if len(self.report) % 15 == 0:
            self.dump()
        worst = min([f['ratio'] for f in findings], default=0)
        print('{} {:44} @{:<5} [{}] texts={} contrast-findings={}{}'.format('[WARN]' if findings else '[OK]  ', label[:44], w, state[:34], n_text, len(findings), (' worst={:.2f}'.format(worst) if findings else '')))
        if findings:
            first = findings[0]
            entry['spill'] = []
            try:
                name = re.sub(r'[^a-z0-9]+', '-', '{}-{}-{}'.format(label, w, state).lower()).strip('-')[:120]
                page.evaluate('(y) => window.scrollTo(0, Math.max(0, y - 200))', first['at'][1])
                page.wait_for_timeout(100)
                page.screenshot(path=os.path.join(self.out, name + '.png'))
                entry['screenshot'] = name + '.png'
            except Exception:
                pass


if __name__ == '__main__':
    sys.exit(la.main(ContrastAudit, os.path.abspath(__file__)))
