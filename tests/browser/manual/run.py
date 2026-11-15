#!/usr/bin/env python3
"""Scenario runner of the Saan manual pipeline.

Executes scenario files (docs/manual/scenarios/<module>.json, format: README.md and scenario.schema.json) in a real Chrome
through Playwright, signs in as the role each workflow names, and writes
  <out>/results.json        status per module / workflow / step (works | fails | blocked), timings, error text
  <out>/images/<module>/<workflow>-<nn>.webp   one picture per step that asks for one (ring + number drawn with Pillow)
  <out>/failures/...        full-page PNG of the step that failed

Nothing is called "works" unless every step and every verify check passed AND no HTTP 5xx / uncaught JavaScript error happened
while the workflow ran. Passwords are never printed, stored or photographed (see README.md, "Secrets").

Usage (PowerShell):
  $env:PLAYWRIGHT_BROWSERS_PATH="$env:USERPROFILE\\AppData\\Local\\ms-playwright"
  C:\\Users\\ChonLaWan\\anaconda3\\envs\\ml313\\python.exe -I tests\\browser\\manual\\run.py --base http://localhost/saan-audit-070 \\
      --accounts D:\\AI-Project\\School\\.private\\saan-audit-accounts.txt --scenarios docs\\manual\\scenarios --out docs\\manual\\run [--only attendance]

Exit code: 0 = every selected workflow works, 1 = a workflow failed or is blocked, 2 = bad scenario / arguments / missing package,
3 = could not get the machine-wide run lock within --lock-wait seconds (another run or build holds it).
"""
import argparse
import datetime
import io
import json
import os
import re
import sys
import tempfile
import time
import traceback
from contextlib import ExitStack
from urllib.parse import urlparse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', line_buffering=True)

import manuallib as ML  # noqa: E402

try:
    from playwright.sync_api import sync_playwright, expect, Error as PWError, TimeoutError as PWTimeout
except ImportError:  # pragma: no cover
    print('[ERR] the playwright package is not installed for this python')
    sys.exit(2)
from PIL import Image, ImageDraw, ImageFilter, ImageFont  # noqa: E402

DEFAULT_TIMEOUT = 8000
RING = (225, 29, 72)
MAX_W = 1366

BUSY_JS = r'''() => {
  const b = document.body; if (!b) return true;
  if (document.querySelector('[aria-busy="true"]')) return true;
  const t = b.innerText || '';
  if (/กำลังโหลด|กำลังบันทึก|กำลังส่ง|กำลังค้นหา|Loading\.\.\./.test(t)) return true;
  try { if (document.getAnimations().some(a => a.playState === 'running' && a.effect && a.effect.getComputedTiming().iterations !== Infinity && (a.effect.getComputedTiming().endTime || 0) < 3000)) return true; } catch (e) {}
  return false;
}'''

PANEL_JS = r'''(el) => {
  const v = el.getBoundingClientRect();
  const sel = 'dialog,[role=dialog],form,section,article,fieldset,table,.glass,[class*=panel],[class*=card],main';
  let cur = el.parentElement, best = null;
  while (cur && cur !== document.body && cur !== document.documentElement) {
    const r = cur.getBoundingClientRect();
    if (cur.matches(sel) && r.height >= v.height + 30 && r.width >= 280) { best = cur; break; }
    cur = cur.parentElement;
  }
  const b = (best || el).getBoundingClientRect();
  return {x: b.x, y: b.y, w: b.width, h: b.height};
}'''

MASK_JS = r'''() => {
  const out = [];
  document.querySelectorAll('input[type=password], [data-mask], input[autocomplete="one-time-code"], [data-secret]').forEach(e => {
    const r = e.getBoundingClientRect(); const s = getComputedStyle(e);
    if (r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none') out.push({x: r.x, y: r.y, w: r.width, h: r.height});
  });
  return {rects: out, sx: window.scrollX, sy: window.scrollY};
}'''


class StepFail(Exception):
    pass


# ---------------------------------------------------------------------------------------------------------------------
# fixtures (generated, tiny, fictional)
# ---------------------------------------------------------------------------------------------------------------------
def make_fixtures(dest):
    os.makedirs(dest, exist_ok=True)
    png = os.path.join(dest, 'cover.png')
    if not os.path.exists(png):
        im = Image.new('RGB', (1200, 630), (214, 233, 222))
        d = ImageDraw.Draw(im)
        for i in range(0, 1200, 60):
            d.rectangle([i, 420, i + 40, 630], fill=(31, 107, 77))
        d.ellipse([880, 70, 1040, 230], fill=(246, 196, 83))
        d.rectangle([0, 0, 1200, 24], fill=(31, 107, 77))
        im.save(png)
    pdf = os.path.join(dest, 'sample.pdf')
    if not os.path.exists(pdf):
        body = b'BT /F1 18 Tf 72 720 Td (Sample document for the manual run) Tj ET'
        objs = [b'<< /Type /Catalog /Pages 2 0 R >>', b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
                b'<< /Length %d >>\nstream\n' % len(body) + body + b'\nendstream', b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>']
        out, offs = b'%PDF-1.4\n', []
        for i, o in enumerate(objs, 1):
            offs.append(len(out))
            out += b'%d 0 obj\n' % i + o + b'\nendobj\n'
        x = len(out)
        out += b'xref\n0 %d\n0000000000 65535 f \n' % (len(objs) + 1) + b''.join(b'%010d 00000 n \n' % o for o in offs)
        out += b'trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n' % (len(objs) + 1, x)
        open(pdf, 'wb').write(out)
    csv = os.path.join(dest, 'sample.csv')
    if not os.path.exists(csv):
        open(csv, 'w', encoding='utf-8-sig', newline='').write('รหัส,ชื่อ,หมายเหตุ\n001,ตัวอย่างหนึ่ง,ข้อมูลสมมติ\n002,ตัวอย่างสอง,ข้อมูลสมมติ\n')
    txt = os.path.join(dest, 'sample.txt')
    if not os.path.exists(txt):
        open(txt, 'w', encoding='utf-8').write('ไฟล์ตัวอย่างสำหรับคู่มือ ข้อมูลสมมติ\n')
    return dest


# ---------------------------------------------------------------------------------------------------------------------
# picture composing (Pillow only: nothing is injected into the page)
# ---------------------------------------------------------------------------------------------------------------------
def _font(size):
    for f in ('arialbd.ttf', 'tahomabd.ttf', 'segoeuib.ttf'):
        try:
            return ImageFont.truetype(f, size)
        except Exception:
            continue
    try:
        return ImageFont.load_default(size)
    except Exception:
        return ImageFont.load_default()


def blur_regions(im, rects, radius=9):
    """Blurs every rect (x, y, w, h in image pixels) in place and returns the image. Used for password fields and one-time codes."""
    for (x, y, w, h) in rects:
        box = (max(0, int(x) - 2), max(0, int(y) - 2), min(im.width, int(x + w) + 2), min(im.height, int(y + h) + 2))
        if box[2] <= box[0] or box[3] <= box[1]:
            continue
        region = im.crop(box).filter(ImageFilter.GaussianBlur(radius))
        # flatten the region further so that glyph shapes cannot be recovered
        region = region.resize((max(1, region.width // 6), max(1, region.height // 6)), Image.BILINEAR).resize(region.size, Image.BILINEAR)
        im.paste(region, box)
    return im


def draw_ring(im, rect, number=None):
    """Draws the 3px ring (white halo + coloured ring) around rect=(x, y, w, h) and, when `number` is given, a numbered marker."""
    d = ImageDraw.Draw(im)
    x, y, w, h = rect
    pad = 3
    box = [x - pad, y - pad, x + w + pad, y + h + pad]
    d.rounded_rectangle([box[0] - 2, box[1] - 2, box[2] + 2, box[3] + 2], radius=8, outline=(255, 255, 255), width=7)
    d.rounded_rectangle(box, radius=7, outline=RING, width=3)
    if number is not None:
        r = 13
        cx = min(max(box[0] + 2, r + 2), im.width - r - 2)
        cy = min(max(box[1] + 2, r + 2), im.height - r - 2)
        d.ellipse([cx - r - 2, cy - r - 2, cx + r + 2, cy + r + 2], fill=(255, 255, 255))
        d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=RING)
        f = _font(16 if number < 100 else 12)
        d.text((cx, cy), str(number), fill=(255, 255, 255), font=f, anchor='mm')


def compose(shot_png, crop_rect, rings, masks, number, dest, fmt='webp'):
    """shot_png: bytes. crop_rect: (x, y, w, h) in image pixels or None. rings/masks: lists of (x, y, w, h) in image pixels."""
    im = Image.open(io.BytesIO(shot_png)).convert('RGB')
    blur_regions(im, masks)
    ox = oy = 0
    if crop_rect:
        x, y, w, h = crop_rect
        x0, y0 = max(0, int(x)), max(0, int(y))
        x1, y1 = min(im.width, int(x + w)), min(im.height, int(y + h))
        if x1 - x0 >= 40 and y1 - y0 >= 30:
            im = im.crop((x0, y0, x1, y1))
            ox, oy = x0, y0
    for i, r in enumerate(rings):
        draw_ring(im, (r[0] - ox, r[1] - oy, r[2], r[3]), number if i == 0 else None)
    if im.width > MAX_W:
        im = im.resize((MAX_W, int(im.height * MAX_W / im.width)), Image.LANCZOS)
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    if fmt == 'webp':
        im.save(dest, 'WEBP', quality=80, method=4)
    else:
        im.save(dest, 'PNG')
    return im.size


# ---------------------------------------------------------------------------------------------------------------------
class WF:
    """State of one workflow run (one browser context at a time)."""

    def __init__(self, module, w, vars_):
        self.module, self.w, self.vars = module, w, vars_
        self.ctx = None
        self.page = None
        self.tabs = []
        self.contexts = []
        self.js_errors, self.console, self.http5xx, self.failed_requests, self.warn4xx = [], [], [], [], []
        self.inflight = {}
        self.dialogs = []
        self.step_no = 0
        self.manual_no = 0
        self.images = []
        self.dialog_policy = 'accept'
        self.dialog_text = None
        self.settle_reason = ''
        self.slow_reason = ''
        self.settle_timeouts = []


class Runner:
    def __init__(self, args):
        self.a = args
        self.base = args.base.rstrip('/')
        self.origin = '{0.scheme}://{0.netloc}'.format(urlparse(self.base))
        self.out = os.path.abspath(args.out)
        self.vw, self.vh = [int(x) for x in args.viewport.split('x')]
        self.shots = args.shots == 'on'
        self.accounts = ML.parse_accounts_by_role(args.accounts)
        self.secrets = {k: v for k, v in self.accounts.items()}
        self.run_id = args.run_id or datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
        self.fixtures = make_fixtures(os.path.join(ML.DOCS, 'fixtures'))
        self.status = {}  # (module, workflow) -> status in this run
        self.pw = None
        self.browser = None
        self.tmp = tempfile.mkdtemp(prefix='saan-manual-')

    # -- small helpers ---------------------------------------------------------------------------------------------
    def clean(self, s):
        return ML.redact(str(s), self.secrets)

    def log(self, tag, msg):
        print('[%s] %s' % (tag, self.clean(msg)))

    def x(self, wf, v):
        """Expands variables in a step value (raises StepFail on an unknown variable)."""
        try:
            return ML.expand(v, wf.vars, self.secrets)
        except KeyError as e:
            raise StepFail('scenario error: %s' % e)

    # -- locators --------------------------------------------------------------------------------------------------
    def loc(self, wf, t, root=None):
        t = self.x(wf, t)
        root = root if root is not None else wf.page
        if 'within' in t:
            root = self.loc(wf, t['within'], root)
            # `within` resolves to every match; the child is searched inside all of them
        exact = t.get('exact', False)
        if 'role' in t:
            kw = {}
            if 'name' in t:
                kw = {'name': t['name'], 'exact': exact}
            L = root.get_by_role(t['role'], **kw)
        elif 'text' in t:
            L = root.get_by_text(t['text'], exact=exact)
        elif 'label' in t:
            L = root.get_by_label(t['label'], exact=exact)
        elif 'placeholder' in t:
            L = root.get_by_placeholder(t['placeholder'], exact=exact)
        elif 'alt' in t:
            L = root.get_by_alt_text(t['alt'], exact=exact)
        elif 'title' in t:
            L = root.get_by_title(t['title'], exact=exact)
        elif 'attr' in t:
            css = ''.join('[%s="%s"]' % (k, v) if v != '' else '[%s]' % k for k, v in t['attr'].items())
            L = root.locator(css)
        elif 'id' in t:
            L = root.locator('[id="%s"]' % t['id'])
        else:
            L = root.locator(t['css'])
        if 'has_text' in t:
            L = L.filter(has_text=t['has_text'])
        if not t.get('include_hidden'):
            L = L.filter(visible=True)
        if 'nth' in t:
            L = L.nth(t['nth'])
        elif t.get('first'):
            L = L.first
        return L

    def describe(self, t):
        parts = ['%s=%r' % (k, v) for k, v in t.items() if k != 'within']
        s = ', '.join(parts)
        if 'within' in t:
            s += ' within {%s}' % self.describe(t['within'])
        return s

    def go(self, wf, url, timeout=15000):
        """page.goto that survives 'interrupted by another navigation' (a redirect still in flight right after sign-in)."""
        for attempt in range(3):
            try:
                return wf.page.goto(url, wait_until='domcontentloaded', timeout=timeout)
            except PWError as e:
                if 'interrupted by another navigation' in str(e) and attempt < 2:
                    time.sleep(0.6)
                    continue
                raise

    # -- waiting ---------------------------------------------------------------------------------------------------
    def settle(self, wf, cap=5.0, quiet=0.25):
        """Waits until the page has loaded, no fetch/xhr started in the last 3 s is still running, no loading text or aria-busy
        and no finite animation is running, for `quiet` seconds in a row. Never uses networkidle (pages that poll never reach it)."""
        page = wf.page
        try:
            page.wait_for_load_state('domcontentloaded', timeout=10000)
        except Exception:
            pass
        start = time.time()
        end = start + cap
        calm_since = None
        wf.slow_reason = ''
        while time.time() < end:
            now = time.time()
            busy = any(now - v[0] < 3 for v in wf.inflight.values())
            wf.settle_reason = ('request in flight: ' + ', '.join(v[1] for v in wf.inflight.values())[:200]) if busy else ''
            if not busy:
                try:
                    busy = bool(page.evaluate(BUSY_JS))
                    wf.settle_reason = 'page busy (aria-busy / loading text / animation)' if busy else ''
                except Exception:
                    busy = True  # navigation in progress
                    wf.settle_reason = 'navigation in progress'
            if busy:
                wf.slow_reason = wf.settle_reason
                calm_since = None
            elif calm_since is None:
                calm_since = now
            elif now - calm_since >= quiet:
                if now - start > 1.5:
                    wf.settle_timeouts.append('slow settle %.1fs: %s' % (now - start, wf.slow_reason))
                return True
            time.sleep(0.05)
        wf.settle_timeouts.append(wf.settle_reason)
        return False

    # -- browser/context -------------------------------------------------------------------------------------------
    def hook(self, wf, page):
        page.set_default_timeout(DEFAULT_TIMEOUT)
        page.on('pageerror', lambda e: wf.js_errors.append(self.clean(str(e))[:300]))
        page.on('console', lambda m: wf.console.append(self.clean(m.text)[:300]) if m.type == 'error' else None)

        def on_response(r):
            if not r.url.startswith(self.origin):
                return
            if r.status >= 500:
                wf.http5xx.append('%d %s %s' % (r.status, r.request.method, r.url.replace(self.origin, '')[:160]))
            elif r.status >= 400 and r.status not in (401, 403, 404, 419, 422):
                wf.warn4xx.append('%d %s %s' % (r.status, r.request.method, r.url.replace(self.origin, '')[:160]))
        page.on('response', on_response)

        def on_req(r):
            if r.resource_type in ('fetch', 'xhr', 'document') and r.url.startswith(self.origin):
                wf.inflight[id(r)] = (time.time(), r.method + ' ' + r.url.replace(self.origin, '')[:80])

        def on_done(r):
            wf.inflight.pop(id(r), None)

        def on_fail(r):
            wf.inflight.pop(id(r), None)
            if r.url.startswith(self.origin) and 'ERR_ABORTED' not in (r.failure or ''):
                wf.failed_requests.append('%s %s %s' % (r.method, r.url.replace(self.origin, '')[:160], r.failure))
        page.on('request', on_req)
        page.on('requestfinished', on_done)
        page.on('requestfailed', on_fail)

        def on_dialog(d):
            wf.dialogs.append('%s: %s' % (d.type, d.message[:200]))
            try:
                if wf.dialog_policy != 'accept':
                    d.dismiss()
                elif d.type == 'prompt' and wf.dialog_text is not None:
                    # a list answers several prompts of one step in order (the last text is reused if more prompts come)
                    t = wf.dialog_text
                    d.accept((t.pop(0) if len(t) > 1 else t[0]) if isinstance(t, list) else t)
                else:
                    d.accept()
            except Exception:
                pass
        page.on('dialog', on_dialog)
        # A security product on the PC may inject its own script into every page (a "password strength" bubble over password fields
        # ends up in the pictures). The runner's browser does not load that script; nothing on the PC is switched off.
        page.route(re.compile(r'^https?://[^/]*kaspersky[^/]*/', re.I), lambda route: route.abort())

    def open_context(self, wf, role, viewport=None):
        vw, vh = viewport or (self.vw, self.vh)
        ctx = self.browser.new_context(viewport={'width': vw, 'height': vh}, locale='th-TH', timezone_id='Asia/Bangkok', color_scheme=self.a.scheme, accept_downloads=True)
        ctx.add_init_script("try{{ for (const k of ['{0}','{0}/']) localStorage.setItem('school-appearance:' + k, '{1}'); }}catch(e){{}}".format(self.base, self.a.scheme))
        wf.contexts.append(ctx)
        wf.ctx = ctx
        wf.inflight.clear()
        page = ctx.new_page()
        wf.page = page
        wf.tabs = [page]
        self.hook(wf, page)
        if role != 'anonymous':
            self.sign_in(wf, role)

    def sign_in(self, wf, role):
        acc = self.accounts.get(role)
        if not acc or not acc.get('login'):
            raise StepFail('sign-in: no account for role %r' % role)
        page = wf.page
        page.goto(acc['url'], wait_until='domcontentloaded')
        user = page.locator('input[name=code], input[name=email], input[name=login]').filter(visible=True).first
        user.fill(acc['login'])
        page.locator('input[type=password]').filter(visible=True).first.fill(acc['password'])
        login_path = urlparse(acc['url']).path.rstrip('/')
        page.locator('input[type=password]').filter(visible=True).first.press('Enter')
        try:
            # staff pages leave /login, the guardian and student portals stay on the same URL: the sign-in form disappearing is the common signal
            page.locator('input[type=password]').filter(visible=True).first.wait_for(state='hidden', timeout=20000)
        except Exception:
            err = ''
            try:
                err = page.locator('[role=alert], .error, .alert, .flash').first.inner_text(timeout=1500)
            except Exception:
                pass
            raise StepFail('sign-in as %s did not complete (still on %s)%s' % (role, login_path, (': ' + err[:120]) if err else ''))
        self.settle(wf)

    # -- screenshots -----------------------------------------------------------------------------------------------
    def shows_secrets(self, txt, logins_ok=False):
        for k, a in self.accounts.items():
            pw = a.get('password') or ''
            if len(pw) >= 8 and pw in txt:
                return 'an account password is visible as text'
        if logins_ok:
            # the user-management page lists sign-in names beside a "password" label by design; a password itself is still refused above
            return ''
        logins = [a['login'] for a in self.accounts.values() if a.get('login')]
        if sum(1 for l in set(logins) if l in txt) >= 3 and re.search(r'รหัสผ่าน|password', txt, re.I):
            return 'the page lists several account logins next to the word password (the accounts list)'
        return ''

    @staticmethod
    def fit(rect, rings, bounds=None):
        """Keeps a crop readable: at least 640x340, at most 720 tall (a window around the ring inside `bounds`)."""
        x, y, w, h = rect
        if w < 640:
            x, w = x + w / 2 - 320, 640
        if h < 340:
            y, h = y + h / 2 - 170, 340
        if h > 720:
            cy = (min(r[1] for r in rings) + max(r[1] + r[3] for r in rings)) / 2 if rings else y + h / 2
            ny = cy - 360
            if bounds:
                ny = max(bounds[1], min(ny, bounds[1] + bounds[3] - 720))
            y, h = ny, 720
        return (x, y, w, h)

    def targets(self, wf, spec):
        if not spec:
            return []
        return spec if isinstance(spec, list) else [spec]

    def box_of(self, wf, t, scroll=False):
        L = self.loc(wf, t).first
        if scroll:
            try:
                # centre the element (both axes: tables scroll sideways inside their own box) so the ring is not squeezed against an edge or a sticky bar
                L.evaluate("el => el.scrollIntoView({block: 'center', inline: 'center'})", timeout=3000)
            except Exception:
                try:
                    L.scroll_into_view_if_needed(timeout=3000)
                except Exception:
                    pass
        b = L.bounding_box(timeout=3000)
        return b

    def shoot(self, wf, st, default_targets, number, dest, fmt='webp', full=False):
        page = wf.page
        if st and st.get('wait_after'):
            # wait_after names the element that proves the effect is on screen (a toast that vanishes after 2.6 s, freshly loaded rows):
            # take the picture right away, with only a very short settle, so a toast is still there
            for t in self.targets(wf, st['wait_after']):
                self.loc(wf, t).first.wait_for(state='visible', timeout=st.get('timeout', DEFAULT_TIMEOUT))
            self.settle(wf, cap=0.4, quiet=0.05)
        else:
            self.settle(wf)
        if st and st.get('settle_ms'):
            time.sleep(st['settle_ms'] / 1000)
        why = self.shows_secrets(page.evaluate('document.body ? document.body.innerText : ""'), bool(st and st.get('logins_ok')))
        if why:
            raise StepFail('refused to take the picture: ' + why)
        hl = st.get('highlight', None) if st else None
        ring_targets = [] if hl is False else self.targets(wf, hl if hl else default_targets)
        crop = (st.get('crop') if st else None) or ('page' if full else 'viewport')
        pad = st.get('crop_pad') if st else None
        rings, first_loc = [], None
        for i, t in enumerate(ring_targets):
            try:
                b = self.box_of(wf, t, scroll=(i == 0))
            except Exception:
                b = None
            if b:
                rings.append((b['x'], b['y'], b['width'], b['height']))
                if first_loc is None:
                    first_loc = t
        if rings:
            time.sleep(0.15)
            # scrolling happened for the first target: measure again so all boxes share one scroll position
            rings = []
            for t in ring_targets:
                try:
                    b = self.box_of(wf, t)
                except Exception:
                    b = None
                if b:
                    rings.append((b['x'], b['y'], b['width'], b['height']))
        mk = page.evaluate(MASK_JS)
        masks = [(r['x'], r['y'], r['w'], r['h']) for r in mk['rects']]
        for t in self.targets(wf, st.get('mask') if st else None):
            try:
                for e in self.loc(wf, t).all():
                    b = e.bounding_box()
                    if b:
                        masks.append((b['x'], b['y'], b['width'], b['height']))
            except Exception:
                pass
        sx, sy = mk['sx'], mk['sy']
        crop_rect = None
        need_full = crop == 'page' or full
        if crop == 'tight' and rings:
            p = pad if pad is not None else 120
            x0 = min(r[0] for r in rings) - p
            y0 = min(r[1] for r in rings) - p
            x1 = max(r[0] + r[2] for r in rings) + p
            y1 = max(r[1] + r[3] for r in rings) + p
            crop_rect = self.fit((x0, y0, x1 - x0, y1 - y0), rings)
        elif crop == 'panel' and first_loc:
            try:
                r = self.loc(wf, first_loc).first.evaluate(PANEL_JS)
                p = pad if pad is not None else 16
                crop_rect = (r['x'] - p, r['y'] - p, r['w'] + 2 * p, r['h'] + 2 * p)
                crop_rect = self.fit(crop_rect, rings, crop_rect)
            except Exception:
                crop_rect = None
        elif isinstance(crop, dict):
            b = self.box_of(wf, crop, scroll=not rings)
            if b:
                p = pad if pad is not None else 24
                crop_rect = self.fit((b['x'] - p, b['y'] - p, b['width'] + 2 * p, b['height'] + 2 * p), rings)
        vp = page.viewport_size or {'width': self.vw, 'height': self.vh}
        if crop_rect and not need_full:
            # a crop larger than the viewport needs the whole page
            if crop_rect[1] < 0 or crop_rect[1] + crop_rect[3] > vp['height'] + 2:
                need_full = True
        png = page.screenshot(full_page=need_full, animations='disabled')
        if need_full:
            off = (sx, sy)
            rings = [(r[0] + off[0], r[1] + off[1], r[2], r[3]) for r in rings]
            masks = [(m[0] + off[0], m[1] + off[1], m[2], m[3]) for m in masks]
            if crop_rect:
                crop_rect = (crop_rect[0] + off[0], crop_rect[1] + off[1], crop_rect[2], crop_rect[3])
        compose(png, crop_rect, rings, masks, number, dest, fmt)
        return bool(rings)

    # -- actions ---------------------------------------------------------------------------------------------------
    def check_cond(self, wf, c, timeout):
        T = lambda t: self.loc(wf, t)
        page = wf.page
        if 'http_status' in c:
            self.check_status(wf, c, timeout)
        elif 'visible' in c:
            expect(T(c['visible']).first).to_be_visible(timeout=timeout)
        elif 'hidden' in c:
            expect(T(c['hidden'])).to_have_count(0, timeout=timeout)
        elif 'text' in c:
            scope = T(c['in']).first if 'in' in c else page.locator('body')
            expect(scope).to_contain_text(self.x(wf, c['text']), timeout=timeout)
        elif 'not_text' in c:
            scope = T(c['in']).first if 'in' in c else page.locator('body')
            expect(scope).not_to_contain_text(self.x(wf, c['not_text']), timeout=timeout)
        elif 'value' in c:
            v = self.x(wf, c['value'])
            if c.get('contains'):
                expect(T(c['of']).first).to_have_value(re.compile(re.escape(v)), timeout=timeout)
            else:
                expect(T(c['of']).first).to_have_value(v, timeout=timeout)
        elif 'count' in c:
            n = c['count']
            L = T(c['of'])
            if isinstance(n, int):
                expect(L).to_have_count(n, timeout=timeout)
            else:
                end = time.time() + timeout / 1000
                while True:
                    k = L.count()
                    if k >= n.get('min', 0) and k <= n.get('max', 10 ** 9):
                        break
                    if time.time() > end:
                        raise StepFail('count of %s is %d, wanted %s' % (self.describe(c['of']), k, n))
                    time.sleep(0.2)
        elif 'url' in c:
            expect(page).to_have_url(re.compile(self.x(wf, c['url'])), timeout=timeout)
        elif 'checked' in c:
            expect(T(c['checked']).first).to_be_checked(timeout=timeout)
        elif 'unchecked' in c:
            expect(T(c['unchecked']).first).not_to_be_checked(timeout=timeout)
        elif 'enabled' in c:
            expect(T(c['enabled']).first).to_be_enabled(timeout=timeout)
        elif 'disabled' in c:
            expect(T(c['disabled']).first).to_be_disabled(timeout=timeout)

    def check_status(self, wf, c, timeout):
            url = self.x(wf, c['url'])
            url = url if url.startswith('http') else self.base + url
            want = c['http_status']
            want = want if isinstance(want, list) else [want]
            if c.get('anonymous'):
                rc = self.pw.request.new_context()
                try:
                    r = rc.get(url, max_redirects=0, timeout=timeout)
                finally:
                    pass
                got = r.status
                rc.dispose()
            else:
                got = wf.ctx.request.get(url, max_redirects=0, timeout=timeout).status
            if got not in want:
                raise StepFail('GET %s answered %d, wanted %s' % (url.replace(self.origin, ''), got, want))

    def default_highlight(self, st):
        a = st['do']
        if a in ('click', 'fill', 'type', 'select', 'check', 'uncheck', 'upload', 'hover', 'scroll', 'capture'):
            return [st['target']]
        if a == 'wait_for' and 'target' in st:
            return [st['target']]
        if a == 'expect':
            conds = st['that'] if isinstance(st['that'], list) else [st['that']]
            for c in conds:
                for k in ('visible', 'of', 'in', 'checked', 'unchecked', 'enabled', 'disabled'):
                    if k in c and isinstance(c[k], dict):
                        return [c[k]]
        return []

    def check_download(self, wf, dl, spec, rec):
        spec = self.x(wf, spec)
        path = os.path.join(self.tmp, '%s-%s' % (self.run_id, dl.suggested_filename))
        dl.save_as(path)
        data = open(path, 'rb').read()
        name = dl.suggested_filename
        rec['download'] = {'file': name, 'bytes': len(data)}
        if len(data) < spec.get('min_bytes', 1):
            raise StepFail('download %s has %d bytes (wanted at least %d)' % (name, len(data), spec.get('min_bytes', 1)))
        if spec.get('ext') and not name.lower().endswith(spec['ext'].lower()):
            raise StepFail('download name %s does not end with %s' % (name, spec['ext']))
        if spec.get('name_regex') and not re.search(spec['name_regex'], name):
            raise StepFail('download name %s does not match %s' % (name, spec['name_regex']))
        kind = spec.get('kind')
        sig = {'pdf': data[:5] == b'%PDF-', 'png': data[:8] == b'\x89PNG\r\n\x1a\n', 'jpeg': data[:3] == b'\xff\xd8\xff', 'xlsx': data[:2] == b'PK', 'zip': data[:2] == b'PK'}
        if kind in sig and not sig[kind]:
            raise StepFail('download %s is not a real %s file (signature check failed)' % (name, kind))
        text = None
        if kind in ('csv', 'text') or spec.get('contains'):
            try:
                text = data.decode('utf-8-sig')
            except UnicodeDecodeError:
                raise StepFail('download %s is not UTF-8 text' % name)
        if kind == 'csv' and (text is None or ',' not in text or '\n' not in text.strip()):
            raise StepFail('download %s does not look like CSV (no comma or only one line)' % name)
        if spec.get('contains') and spec['contains'] not in text:
            raise StepFail('download %s does not contain %r' % (name, spec['contains']))
        rec['download']['kind'] = kind or ''
        os.remove(path)

    def act(self, wf, st, rec):
        a = st['do']
        timeout = st.get('timeout', DEFAULT_TIMEOUT)
        page = wf.page
        wf.dialog_policy = {'dismiss': 'dismiss'}.get(st.get('dialog'), 'accept')
        wf.dialog_text = None
        if 'dialog_text' in st:
            dt = st['dialog_text']
            wf.dialog_text = [self.x(wf, v) for v in dt] if isinstance(dt, list) else self.x(wf, dt)
        if a == 'note':
            return
        if a == 'goto':
            url = self.x(wf, st['url'])
            url = url if url.startswith('http') else self.base + url
            if '#' in url and page.url.split('#')[0].rstrip('/') != url.split('#')[0].rstrip('/'):
                pass
            self.go(wf, url, max(timeout, 15000))
            if '#' in url:
                self.settle(wf, cap=1.0)
            return
        if a == 'reload':
            page.reload(wait_until='domcontentloaded')
            self.settle(wf, cap=1.0)
            return
        if a == 'close_tab':
            if len(wf.tabs) > 1:
                wf.tabs.pop().close()
                wf.page = wf.tabs[-1]
            return
        if a == 'wait_for':
            if 'target' in st:
                self.loc(wf, st['target']).first.wait_for(state=st.get('state', 'visible'), timeout=timeout)
            if 'url' in st:
                page.wait_for_url(re.compile(self.x(wf, st['url'])), timeout=timeout)
            if 'text' in st:
                expect(page.locator('body')).to_contain_text(self.x(wf, st['text']), timeout=timeout)
            return
        if a == 'expect':
            conds = st['that'] if isinstance(st['that'], list) else [st['that']]
            for c in conds:
                self.check_cond(wf, c, timeout)
            return
        if a == 'press':
            if 'target' in st:
                self.loc(wf, st['target']).first.press(st['key'], timeout=timeout)
            else:
                page.keyboard.press(st['key'])
            return
        L = self.loc(wf, st['target'])
        try:
            n = L.count()
        except Exception:
            n = 1
        if n > 1 and not ('nth' in st['target'] or st['target'].get('first')):
            names = []
            for i in range(min(n, 4)):
                try:
                    names.append((L.nth(i).inner_text(timeout=500) or L.nth(i).get_attribute('aria-label') or '').strip().replace('\n', ' ')[:40])
                except Exception:
                    names.append('?')
            raise StepFail('target is ambiguous: %d elements match {%s} (e.g. %s); add name, has_text, within, nth or first' % (n, self.describe(st['target']), ' | '.join(names)))
        if a == 'capture':
            txt = L.first.get_attribute(st['from_attr'], timeout=timeout) or '' if st.get('from_attr') else L.first.inner_text(timeout=timeout)
            if st.get('regex'):
                m = re.search(st['regex'], txt)
                if not m:
                    raise StepFail('capture: regex %r not found in %r' % (st['regex'], txt[:80]))
                txt = m.group(1) if m.groups() else m.group(0)
            wf.vars[st['var']] = txt.strip()
            return
        with ExitStack() as stack:
            dl = pp = rs = None
            if st.get('expect_download'):
                dl = stack.enter_context(page.expect_download(timeout=max(timeout, 15000)))
            if st.get('expect_popup'):
                pp = stack.enter_context(page.expect_popup(timeout=max(timeout, 15000)))
            if st.get('expect_response'):
                er = self.x(wf, st['expect_response'])
                rs = stack.enter_context(page.expect_response(
                    lambda r: er['url_contains'] in r.url and (not er.get('method') or r.request.method == er['method'].upper()), timeout=max(timeout, 15000)))
            if a == 'click' and st.get('repeat_until'):
                ru = self.x(wf, st['repeat_until'])
                tries = 0
                while self.loc(wf, ru['visible']).count() == 0 and tries < ru.get('max', 6):
                    L.click(timeout=timeout, force=bool(st.get('force')))
                    tries += 1
                    self.settle(wf, cap=1.0, quiet=0.1)
                if self.loc(wf, ru['visible']).count() == 0:
                    raise StepFail('repeat_until: %s still not visible after %d click(s)' % (self.describe(ru['visible']), tries))
                rec['clicks'] = tries
            elif a == 'click':
                L.click(timeout=timeout, force=bool(st.get('force')))
            elif a == 'fill':
                L.fill(self.x(wf, st['value']), timeout=timeout)
            elif a == 'type':
                L.click(timeout=timeout)
                L.press_sequentially(self.x(wf, st['value']), delay=0)
            elif a == 'select':
                if 'label' in st:
                    L.select_option(label=self.x(wf, st['label']), timeout=timeout)
                else:
                    L.select_option(value=self.x(wf, st['value']), timeout=timeout)
            elif a == 'check':
                L.check(timeout=timeout)
            elif a == 'uncheck':
                L.uncheck(timeout=timeout)
            elif a == 'hover':
                L.hover(timeout=timeout)
            elif a == 'scroll':
                L.scroll_into_view_if_needed(timeout=timeout)
            elif a == 'upload':
                path = os.path.join(self.fixtures, st['file'])
                if st.get('via_chooser'):
                    with page.expect_file_chooser(timeout=timeout) as fc:
                        L.click(timeout=timeout)
                    fc.value.set_files(path)
                else:
                    L.set_input_files(path, timeout=timeout)
        if dl is not None:
            self.check_download(wf, dl.value, st['expect_download'], rec)
        if pp is not None:
            newp = pp.value
            try:
                newp.wait_for_load_state('domcontentloaded', timeout=15000)
            except Exception:
                pass
            want = self.x(wf, st['expect_popup']).get('url_regex')
            if want and not re.search(want, newp.url):
                raise StepFail('a new tab opened at %s, expected a URL matching %s' % (newp.url.replace(self.origin, ''), want))
            self.hook_new(wf, newp)
            rec['popup'] = newp.url.replace(self.origin, '')
        if rs is not None:
            er = self.x(wf, st['expect_response'])
            r = rs.value
            rec['response'] = '%s %d' % (r.request.method, r.status)
            if er.get('status') and r.status != er['status']:
                raise StepFail('%s %s answered %d, wanted %d' % (r.request.method, r.url.replace(self.origin, '')[:100], r.status, er['status']))

    def hook_new(self, wf, p):
        if p not in wf.tabs:
            wf.tabs.append(p)
            wf.page = p
            if not getattr(p, '_hooked', False):
                self.hook(wf, p)
                try:
                    p._hooked = True
                except Exception:
                    pass

    # -- steps -----------------------------------------------------------------------------------------------------
    def run_steps(self, wf, steps, phase, modname, wid, results_steps, numbered):
        """Runs a list of steps; returns True when all passed. Failures write a full-page PNG and stop the list."""
        ok = True
        for st in steps:
            wf.step_no += 1
            n = wf.step_no
            rec = {'n': n, 'do': st['do'], 'status': 'passed', 'seconds': 0.0, 'image': None, 'error': None}
            say = st.get('say')
            if say:
                try:
                    rec['say'] = self.clean(ML.expand(say, wf.vars, None))
                except KeyError:
                    rec['say'] = say
            w = st.get('when')
            if w:
                key = 'equals' if 'equals' in w else 'not_equals'
                try:
                    a, b = [self.x(wf, v) for v in w[key]]
                except StepFail:
                    a, b = '?', '?'
                if (a == b) != (key == 'equals'):
                    rec['status'] = 'skipped'
                    results_steps.append(rec)
                    continue
            if numbered and say:
                # numbered only now: a step skipped by `when` is not in the manual, so it must not use up a number
                wf.manual_no += 1
                rec['manual_no'] = wf.manual_no
            if not ok:
                rec['status'] = 'not_run'
                results_steps.append(rec)
                continue
            t0 = time.time()
            n_http, n_js = len(wf.http5xx), len(wf.js_errors)
            n_to = len(wf.settle_timeouts)
            try:
                self.act(wf, st, rec)
                rec['t_act'] = round(time.time() - t0, 2)
                if st['do'] not in ('note',) and not (st.get('shot') and st.get('wait_after') and self.shots):
                    self.settle(wf)
                rec['t_settle'] = round(time.time() - t0, 2)
                if wf.dialogs:
                    rec['dialog'] = wf.dialogs[-1]
                if st.get('shot') and self.shots:
                    dest = os.path.join(self.out, 'images', modname, '%s-%02d.webp' % (wid, n))
                    self.shoot(wf, st, self.default_highlight(st), rec.get('manual_no') if phase == 'steps' else None, dest)
                    rec['image'] = 'images/%s/%s-%02d.webp' % (modname, wid, n)
                    wf.images.append(rec['image'])
            except Exception as e:
                if st.get('optional') and not isinstance(e, KeyboardInterrupt):
                    rec['status'] = 'warning'
                    rec['error'] = self.clean(str(e).split('\n')[0])[:300]
                else:
                    rec['status'] = 'failed'
                    rec['error'] = self.clean(self.brief(e))
                    ok = False
                    rec['failure_image'] = self.fail_picture(wf, modname, wid, n)
            rec['seconds'] = round(time.time() - t0, 2)
            if len(wf.settle_timeouts) > n_to:
                rec['settle_timeout'] = wf.settle_timeouts[-1]
            results_steps.append(rec)
            if rec['status'] == 'failed':
                self.log('ERR', '  step %d (%s): %s' % (n, st['do'], rec['error'][:240]))
        return ok

    @staticmethod
    def brief(e):
        s = str(e)
        s = re.sub(r'\x1b\[[0-9;]*m', '', s)
        lines = [l for l in s.split('\n') if l.strip()]
        head = lines[0] if lines else repr(e)
        extra = [l.strip() for l in lines[1:] if 'waiting for' in l or 'intercepts pointer' in l or 'strict mode' in l.lower() or 'Locator' in l or 'Expected' in l or 'Received' in l]
        return (head + ' | ' + ' | '.join(extra[:3]))[:500]

    def fail_picture(self, wf, modname, wid, n):
        try:
            dest = os.path.join(self.out, 'failures', modname, '%s-step%02d.png' % (wid, n))
            os.makedirs(os.path.dirname(dest), exist_ok=True)
            txt = wf.page.evaluate('document.body ? document.body.innerText : ""')
            if self.shows_secrets(txt):
                return None
            mk = wf.page.evaluate(MASK_JS)
            png = wf.page.screenshot(full_page=True, animations='disabled')
            masks = [(r['x'] + mk['sx'], r['y'] + mk['sy'], r['w'], r['h']) for r in mk['rects']]
            compose(png, None, [], masks, None, dest, 'png')
            return 'failures/%s/%s-step%02d.png' % (modname, wid, n)
        except Exception:
            return None

    # -- workflows -------------------------------------------------------------------------------------------------
    def vars_for(self, scn, w):
        v = ML.base_vars(self.run_id)
        for src in (scn.get('vars', {}), w.get('vars', {})):
            for k, val in src.items():
                v[k] = ML.expand(val, v, None)
        return v

    def run_workflow(self, scn, w):
        modname, wid = scn['module'], w['id']
        vars_ = self.vars_for(scn, w)
        wf = WF(modname, w, vars_)
        res = {'status': 'fails', 'run_id': self.run_id, 'started': datetime.datetime.now().isoformat(timespec='seconds'), 'seconds': 0, 'scheme': self.a.scheme,
               'viewport': w.get('viewport') or self.a.viewport, 'shots': self.shots, 'role': w['role'], 'steps': [], 'verify': [], 'error': None, 'failed_step': None,
               'console_errors': [], 'failed_requests': [], 'http_5xx': [], 'js_errors': [], 'warnings_4xx': []}
        t0 = time.time()
        # clear this workflow's previous pictures
        for sub in ('images', 'failures'):
            d = os.path.join(self.out, sub, modname)
            if os.path.isdir(d):
                for f in os.listdir(d):
                    if f.startswith(wid + '-') and re.match(r'^%s-(step)?\d\d\.(webp|png)$' % re.escape(wid), f):
                        os.remove(os.path.join(d, f))
        vp = w.get('viewport') or self.a.viewport
        vpt = tuple(int(x) for x in vp.split('x'))
        ok = True
        try:
            self.open_context(wf, w['role'], vpt)
            start = self.x(wf, w['start'])
            self.go(wf, start if start.startswith('http') else self.base + start)
            self.settle(wf)
            ok = self.run_steps(wf, w['steps'], 'steps', modname, wid, res['steps'], True)
            if ok:
                for vb in w['verify']:
                    vrec = {'title': vb['title'], 'as': vb.get('as') or w['role'], 'steps': []}
                    res['verify'].append(vrec)
                    if vb.get('as') and vb['as'] != w['role']:
                        self.open_context(wf, vb['as'], vpt)
                        self.go(wf, self.base + '/')
                    if vb.get('start'):
                        s = self.x(wf, vb['start'])
                        self.go(wf, s if s.startswith('http') else self.base + s)
                        self.settle(wf)
                    if not self.run_steps(wf, vb['steps'], 'verify', modname, wid, vrec['steps'], False):
                        ok = False
                        break
        except Exception as e:
            ok = False
            res['error'] = self.clean(self.brief(e))
            try:
                res['steps'].append({'n': wf.step_no + 1, 'do': 'setup', 'status': 'failed', 'error': res['error'], 'seconds': 0, 'image': None,
                                     'failure_image': self.fail_picture(wf, modname, wid, wf.step_no + 1)})
            except Exception:
                pass
        if not ok and not res['error']:
            bad = next((s for s in res['steps'] + [s for v in res['verify'] for s in v['steps']] if s['status'] == 'failed'), None)
            if bad:
                res['failed_step'] = bad['n']
                res['error'] = 'step %d (%s) failed: %s' % (bad['n'], bad['do'], bad['error'])
        res['console_errors'] = wf.console[-15:]
        res['failed_requests'] = wf.failed_requests[-15:]
        res['http_5xx'] = wf.http5xx[-15:]
        res['js_errors'] = wf.js_errors[-15:]
        res['warnings_4xx'] = wf.warn4xx[-15:]
        res['dialogs'] = wf.dialogs[-5:]
        if ok and (wf.http5xx or wf.js_errors):
            ok = False
            res['error'] = 'the steps passed but the page raised %s' % ('; '.join(['HTTP 5xx: ' + ', '.join(wf.http5xx[:3])] * bool(wf.http5xx) + ['JS error: ' + wf.js_errors[0]] * bool(wf.js_errors)))
        res['status'] = 'works' if ok else 'fails'
        res['seconds'] = round(time.time() - t0, 1)
        res['finished'] = datetime.datetime.now().isoformat(timespec='seconds')
        for c in wf.contexts:
            try:
                c.close()
            except Exception:
                pass
        return res

    # -- orchestration ---------------------------------------------------------------------------------------------
    def select(self, scns):
        """Resolves --only (module id, module.workflow, or a workflow id) to (module, workflow) pairs plus dependencies, in run order."""
        index = {(s['module'], w['id']): (s, w) for s in scns.values() for w in s['workflows']}
        if not self.a.only:
            want = list(index)
        else:
            want = []
            for o in self.a.only:
                hits = [k for k in index if k[0] == o or '%s.%s' % k == o or k[1] == o]
                if not hits:
                    raise SystemExit('[ERR] --only %r matches no module or workflow' % o)
                want += hits
        order, seen = [], set()

        def deps_of(k):
            s, w = index[k]
            ds = list(w.get('depends_on', []))
            for p in s.get('prerequisites', []):
                ds += ['%s.%s' % (p['module'], x) for x in p.get('workflows', [])]
            out = []
            for d in ds:
                key = tuple(d.split('.', 1)) if '.' in d else (k[0], d)
                if key not in index:
                    raise SystemExit('[ERR] %s.%s depends on unknown workflow %s' % (k[0], k[1], d))
                out.append(key)
            return out

        def visit(k, stack=()):
            if k in seen:
                return
            if k in stack:
                raise SystemExit('[ERR] dependency cycle at %s.%s' % k)
            for d in deps_of(k):
                visit(d, stack + (k,))
            seen.add(k)
            order.append(k)
        # cleanup workflows run after every other selected workflow (of every module): they undo what the others created
        for k in [k for k in want if not index[k][1].get('cleanup')] + [k for k in want if index[k][1].get('cleanup')]:
            visit(k)
        order = [k for k in order if not index[k][1].get('cleanup')] + [k for k in order if index[k][1].get('cleanup')]
        return order, index, deps_of

    def persist(self, res_path, mine, touched):
        """Read-merge-write: re-read results.json from disk and replace only the modules this run touched (atomic replace), so another
        writer's modules are never lost."""
        disk = ML.load_results(res_path)
        disk['base'] = self.base
        disk['schema'] = 1
        for m in touched:
            disk.setdefault('modules', {})[m] = mine['modules'][m]
        ML.save_results(res_path, disk)

    def run(self):
        files = ML.find_scenario_files(self.a.scenarios)
        scns, bad = {}, 0
        for f in files:
            try:
                data = ML.load_scenario(f)
            except Exception as e:
                print('[ERR] %s: cannot read JSON: %s' % (f, e))
                bad += 1
                continue
            errs = [e for e in ML.validate_scenario(data, f, self.accounts) if not e.startswith('[WARN]')]
            if errs:
                bad += 1
                print('[ERR] %s: %d problem(s)' % (os.path.basename(f), len(errs)))
                for e in errs[:25]:
                    print('       ' + self.clean(e))
                continue
            data['_file'] = f
            data['_sha'] = ML.sha256_file(f)
            scns[data['module']] = data
        if bad:
            print('[ERR] scenario validation failed; nothing was run')
            return 2
        if self.a.validate_only:
            print('[OK] %d scenario file(s) valid' % len(scns))
            return 0
        os.makedirs(self.out, exist_ok=True)
        lock = ML.RunLock(self.a.group, self.a.lock_wait, log=lambda m: print('[..] ' + m))
        if not lock.acquire():
            print('[ERR] gave up after %d s: another run still holds the machine-wide run lock (%s). Nothing was run. Try again later or raise --lock-wait.' % (self.a.lock_wait, lock.holder()))
            return 3
        try:
            return self.execute(scns)
        finally:
            lock.release()

    def execute(self, scns):
        """Runs the selected workflows. Called while holding the run lock, so reading and writing results.json cannot race with another run."""
        order, index, deps_of = self.select(scns)
        res_path = os.path.join(self.out, 'results.json')
        results = ML.load_results(res_path)  # fresh from disk, we hold the lock
        touched = set()
        print('[..] run %s: %d workflow(s), scheme=%s, viewport=%s, shots=%s' % (self.run_id, len(order), self.a.scheme, self.a.viewport, self.a.shots))
        self.pw = sync_playwright().start()
        try:
            self.browser = self.pw.chromium.launch(channel='chrome', args=['--lang=th-TH'])
            for (m, wid) in order:
                scn, w = index[(m, wid)]
                mres = results['modules'].get(m)
                if not mres or mres.get('scenario_sha256') != scn['_sha']:
                    mres = {'scenario_file': os.path.basename(scn['_file']), 'scenario_sha256': scn['_sha'], 'workflows': {}}
                    results['modules'][m] = mres
                touched.add(m)
                blockers = [] if w.get('cleanup') else [d for d in deps_of((m, wid)) if self.status.get(d) != 'works']
                if blockers:
                    r = {'status': 'blocked', 'run_id': self.run_id, 'blocked_by': ['%s.%s' % d for d in blockers], 'steps': [], 'verify': [], 'seconds': 0,
                         'error': 'prerequisite did not work: ' + ', '.join('%s.%s' % d for d in blockers), 'role': w['role'], 'shots': self.shots,
                         'finished': datetime.datetime.now().isoformat(timespec='seconds')}
                    self.log('BLOCK', '%s.%s  %s' % (m, wid, r['error']))
                else:
                    self.log('..', '%s.%s (%s) ...' % (m, wid, w['role']))
                    try:
                        r = self.run_workflow(scn, w)
                    except Exception as e:  # runner bug: never let one workflow kill the run silently
                        r = {'status': 'fails', 'run_id': self.run_id, 'error': 'runner error: ' + self.clean(traceback.format_exc()[-400:]), 'steps': [], 'verify': [], 'seconds': 0, 'role': w['role'], 'shots': self.shots}
                    npass = sum(1 for s in r['steps'] if s['status'] == 'passed')
                    self.log('OK' if r['status'] == 'works' else 'ERR', '%s.%s  %s  (%d steps passed, %.1fs)%s' % (m, wid, r['status'], npass, r['seconds'], '' if r['status'] == 'works' else '  -> ' + str(r.get('error'))[:200]))
                r['scenario_sha256'] = scn['_sha']
                self.status[(m, wid)] = r['status']
                mres['workflows'][wid] = r
                mres['updated'] = datetime.datetime.now().isoformat(timespec='seconds')
                self.persist(res_path, results, touched)
        finally:
            try:
                if self.browser:
                    self.browser.close()
            finally:
                self.pw.stop()
        # console summary
        print('\n%-28s %-28s %s' % ('module', 'workflow', 'status'))
        for (m, wid) in order:
            print('%-28s %-28s %s' % (m, wid, self.status.get((m, wid))))
        counts = {}
        for v in self.status.values():
            counts[v] = counts.get(v, 0) + 1
        print('[..] summary: ' + ', '.join('%s=%d' % kv for kv in sorted(counts.items())) + '  results: ' + res_path)
        return 0 if all(v == 'works' for v in self.status.values()) else 1


def parse_args(argv=None):
    ap = argparse.ArgumentParser(description='Run Saan manual scenarios in a real browser.')
    ap.add_argument('--base', default=os.environ.get('SAAN_AUDIT_BASE'), required=not os.environ.get('SAAN_AUDIT_BASE'), help='install base URL, e.g. http://localhost/saan-audit-070')
    ap.add_argument('--accounts', default=os.environ.get('SAAN_AUDIT_ACCOUNTS'), required=not os.environ.get('SAAN_AUDIT_ACCOUNTS'), help='accounts text file (never printed)')
    ap.add_argument('--scenarios', nargs='+', default=[ML.DEFAULT_SCENARIOS], help='directory and/or files (default docs/manual/scenarios)')
    ap.add_argument('--out', default=os.path.join(ML.DOCS, 'run'), help='output folder (results.json, images/, failures/)')
    ap.add_argument('--only', action='append', help='module id, module.workflow or workflow id; repeatable; dependencies run too')
    ap.add_argument('--scheme', choices=['light', 'dark'], default='light')
    ap.add_argument('--viewport', default='1366x900', help='WxH, default 1366x900 (a workflow may declare its own)')
    ap.add_argument('--shots', choices=['on', 'off'], default='on')
    ap.add_argument('--run-id', default=None, help='marker id used in created data (default: timestamp)')
    ap.add_argument('--lock-wait', type=int, default=1800, help='seconds to wait for the machine-wide run lock (default 1800); exit code 3 when it gives up')
    ap.add_argument('--group', default=os.environ.get('SAAN_MANUAL_GROUP', 'run'), help='label shown to other writers while this run holds the lock (e.g. G2)')
    ap.add_argument('--validate-only', action='store_true', help='validate scenario files and exit')
    return ap.parse_args(argv)


def main(argv=None):
    a = parse_args(argv)
    return Runner(a).run()


if __name__ == '__main__':
    sys.exit(main())


