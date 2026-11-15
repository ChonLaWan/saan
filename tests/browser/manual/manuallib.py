"""Shared helpers of the manual pipeline (run.py, build.py, selftest.py): accounts, scenario loading and validation,
variable expansion, results file. No browser code lives here, so build.py can run without Playwright."""
import atexit
import ctypes
import datetime
import glob
import hashlib
import json
import os
import re
import tempfile
import threading
import time
from urllib.parse import urlparse

HERE = os.path.dirname(os.path.abspath(__file__))
SCHOOL_APP = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
DOCS = os.path.join(SCHOOL_APP, 'docs', 'manual')
DEFAULT_SCENARIOS = os.path.join(DOCS, 'scenarios')
SCHEMA_FILE = os.path.join(HERE, 'scenario.schema.json')

THAI_MONTHS_SHORT = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.']
THAI_MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม']

# Emoji / pictographs are forbidden in every text of a scenario (project rule); the validator rejects them.
EMOJI_RE = re.compile('[☀-➿\U0001f000-\U0001faff️‍⭐⬆✅❌]')

VERDICTS = ('ใช้งานได้', 'ใช้ได้บางส่วน', 'มีแต่โครง', 'ยังไม่ได้ตรวจ')


# ---------------------------------------------------------------------------------------------------------------------
# accounts
# ---------------------------------------------------------------------------------------------------------------------
def parse_accounts_by_role(path):
    """Reads the accounts text file (same format as tests/browser/layout_audit.py parse_accounts) and returns
    {role_key: {'login', 'password', 'url', 'kind', 'label'}}.

    Role keys: for staff the part of the e-mail before '@' ('admin', 'principal', 'homeroom', 'subject', 'finance', 'payroll',
    'clerk', 'editor', 'guardian1', 'guardian2'); 'student1', 'student2' for the student accounts in file order;
    'learn1', 'learn4'... for the online-class code entries (login '-' and the code as password) and 'complaint' for the
    complaint tracking entry. 'teacher' is an alias of 'homeroom'. Nothing here prints a password."""
    raw = open(path, encoding='utf-8-sig').read().splitlines()
    blocks, cur, label = [], {}, ''
    for line in raw:
        s = line.strip()
        m = re.match(r'^(เข้าที่|อีเมล|ชื่อเข้าระบบ|รหัสผ่าน)\s*[:：]\s*(.*)$', s)
        if not m:
            if s and not s.startswith(('ที่อยู่', 'สร้างเมื่อ', 'สาน (')) and not re.match(r'^(ผู้ดูแลระบบ|บัญชีทดลอง)', s):
                label = s
            elif re.match(r'^ผู้ดูแลระบบ', s):
                label = 'ผู้ดูแลระบบ'
            continue
        k, v = m.group(1), m.group(2).strip()
        if k == 'เข้าที่':
            cur = {'url': v, 'label': label}
        elif k in ('อีเมล', 'ชื่อเข้าระบบ'):
            cur['login'] = v
        elif k == 'รหัสผ่าน':
            cur['password'] = v
            blocks.append(cur)
            cur = {}
    out, nstudent = {}, 0
    for b in blocks:
        if not b.get('login') or not b.get('password') or not b.get('url'):
            continue
        p = urlparse(b['url']).path.rstrip('/')
        if b['login'] == '-':
            mm = re.search(r'/learn/(\d+)$', p)
            if mm:
                out['learn' + mm.group(1)] = {'login': '', 'password': b['password'], 'url': b['url'], 'kind': 'learn', 'label': b['label']}
            continue
        if p.endswith('/portal/complaints/status'):
            out['complaint'] = {'login': b['login'], 'password': b['password'], 'url': b['url'], 'kind': 'complaint', 'label': b['label']}
            continue
        if p.endswith('/student'):
            nstudent += 1
            key, kind = 'student%d' % nstudent, 'student'
        elif p.endswith('/guardian'):
            key, kind = b['login'].split('@')[0], 'guardian'
        elif p.endswith('/login'):
            key, kind = b['login'].split('@')[0], 'staff'
        else:
            continue
        out[key] = {'login': b['login'], 'password': b['password'], 'url': b['url'], 'kind': kind, 'label': b['label']}
    if 'homeroom' in out:
        out['teacher'] = out['homeroom']
    return out


def role_catalog(accounts):
    """Role key -> short Thai label, with no secret (safe to print)."""
    return {k: v.get('label', '') for k, v in accounts.items()}


# ---------------------------------------------------------------------------------------------------------------------
# variables
# ---------------------------------------------------------------------------------------------------------------------
def last_weekday(today, back=0):
    """The most recent Monday-Friday on or before `today`; back=1 is the weekday before that, and so on."""
    d = today
    while d.weekday() >= 5:
        d -= datetime.timedelta(days=1)
    for _ in range(back):
        d -= datetime.timedelta(days=1)
        while d.weekday() >= 5:
            d -= datetime.timedelta(days=1)
    return d


def th_short(d):
    return '%d %s %d' % (d.day, THAI_MONTHS_SHORT[d.month - 1], d.year + 543)


def th_month(d):
    return '%s %d' % (THAI_MONTHS[d.month - 1], d.year + 543)


def base_vars(run_id, today=None):
    today = today or datetime.date.today()
    lw = last_weekday(today)
    return {
        'run_id': run_id,
        'marker': 'ทดสอบคู่มือ ' + run_id,
        'today': today.isoformat(),
        'last_weekday': lw.isoformat(),
        'month_differs': '1' if lw.strftime('%Y-%m') != today.strftime('%Y-%m') else '0',
    }


_VAR_RE = re.compile(r'\{\{\s*([^{}]+?)\s*\}\}')


def expand(value, vars_, secrets=None, today=None):
    """Replaces {{name}}, {{name|filter}}, {{last_weekday:N}}, {{next_weekday:N}} and {{secret:role.field}} in strings (recursively in lists/dicts).
    Filters: th_short ('13 พ.ย. 2569'), th_month ('พฤศจิกายน 2569'), month ('2026-11'), day ('13'), iso.
    Raises KeyError for an unknown variable so a typo fails loudly instead of typing '{{x}}' into a form."""
    if isinstance(value, list):
        return [expand(v, vars_, secrets, today) for v in value]
    if isinstance(value, dict):
        return {k: expand(v, vars_, secrets, today) for k, v in value.items()}
    if not isinstance(value, str) or '{{' not in value:
        return value
    today = today or datetime.date.today()

    def sub(m):
        expr = m.group(1)
        name, _, flt = expr.partition('|')
        name = name.strip()
        if name.startswith('secret:'):
            role, _, field = name[7:].partition('.')
            if secrets is None or role not in secrets or field not in ('login', 'password'):
                raise KeyError('unknown secret ' + name)
            return secrets[role][field]
        mm = re.match(r'^last_weekday:(\d+)$', name)
        nx = re.match(r'^next_weekday:(\d+)$', name)
        if mm:
            val = last_weekday(today, int(mm.group(1))).isoformat()
        elif nx:
            # the Nth Monday-Friday AFTER today (N >= 1): for things that must lie in the future, e.g. a leave that has not started
            d, left = today, max(1, int(nx.group(1)))
            while left:
                d += datetime.timedelta(days=1)
                if d.weekday() < 5:
                    left -= 1
            val = d.isoformat()
        elif name in vars_:
            val = str(vars_[name])
        else:
            raise KeyError('unknown variable {{%s}}' % name)
        flt = flt.strip()
        if flt:
            try:
                d = datetime.date.fromisoformat(val[:10])
            except ValueError:
                raise KeyError('filter %s needs a date, got %r' % (flt, val))
            val = {'th_short': th_short(d), 'th_month': th_month(d), 'month': d.strftime('%Y-%m'), 'day': str(d.day), 'iso': d.isoformat()}[flt]
        return val
    return _VAR_RE.sub(sub, value)


def redact(text, secrets):
    """Hides every account password / login that appears in a string (for results.json and console output)."""
    if not text or not secrets:
        return text
    for v in secrets.values():
        for f in ('password',):
            s = v.get(f) or ''
            if len(s) >= 4:
                text = text.replace(s, '***')
    return text


# ---------------------------------------------------------------------------------------------------------------------
# scenarios
# ---------------------------------------------------------------------------------------------------------------------
def sha256_file(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(65536), b''):
            h.update(chunk)
    return h.hexdigest()


def find_scenario_files(spec):
    """`spec` is a list of files and/or directories; a directory contributes every *.json inside it."""
    files = []
    for s in spec or [DEFAULT_SCENARIOS]:
        if os.path.isdir(s):
            files += sorted(glob.glob(os.path.join(s, '*.json')))
        else:
            files.append(s)
    return files


def load_scenario(path):
    with open(path, encoding='utf-8-sig') as f:
        data = json.load(f)
    return data


STEP_ACTIONS = ('goto', 'click', 'fill', 'type', 'select', 'check', 'uncheck', 'upload', 'press', 'hover', 'scroll', 'wait_for', 'expect', 'capture', 'close_tab', 'reload', 'note')
NEEDS_TARGET = ('click', 'fill', 'type', 'select', 'check', 'uncheck', 'upload', 'hover', 'scroll', 'capture')
TARGET_KEYS = ('role', 'text', 'label', 'placeholder', 'alt', 'title', 'attr', 'id', 'css')


def _walk_strings(obj, path=''):
    if isinstance(obj, str):
        yield path, obj
    elif isinstance(obj, list):
        for i, v in enumerate(obj):
            yield from _walk_strings(v, '%s[%d]' % (path, i))
    elif isinstance(obj, dict):
        for k, v in obj.items():
            yield from _walk_strings(v, '%s.%s' % (path, k))


def _check_target(t, where, errs):
    if isinstance(t, str):
        errs.append('%s: a target must be an object, e.g. {"role":"button","name":"บันทึก"}' % where)
        return
    if not isinstance(t, dict) or not any(k in t for k in TARGET_KEYS):
        errs.append('%s: target needs one of %s' % (where, ', '.join(TARGET_KEYS)))
        return
    if 'role' in t and 'name' not in t and 'has_text' not in t and 'nth' not in t and 'first' not in t:
        errs.append('%s: a role target without "name" is ambiguous; add name, has_text, nth or first' % where)
    if 'within' in t:
        _check_target(t['within'], where + '.within', errs)


def _check_step(st, where, errs, wf_ids, fixtures_dir, in_verify=False):
    a = st.get('do')
    if a not in STEP_ACTIONS:
        errs.append('%s: unknown action %r (one of %s)' % (where, a, ', '.join(STEP_ACTIONS)))
        return
    if a in NEEDS_TARGET:
        if 'target' not in st:
            errs.append('%s: action %s needs "target"' % (where, a))
        else:
            _check_target(st['target'], where + '.target', errs)
    if a == 'goto' and not st.get('url'):
        errs.append('%s: goto needs "url"' % where)
    if a in ('fill', 'type') and 'value' not in st:
        errs.append('%s: %s needs "value"' % (where, a))
    if a == 'select' and 'value' not in st and 'label' not in st:
        errs.append('%s: select needs "value" or "label"' % where)
    if a == 'upload':
        f = st.get('file')
        if not f:
            errs.append('%s: upload needs "file" (a fixture name: cover.png, sample.pdf, sample.csv)' % where)
        elif f not in ('cover.png', 'sample.pdf', 'sample.csv', 'sample.txt'):
            # a writer's own fixture: a small fictional file kept in docs/manual/fixtures/ under a plain name
            if not re.match(r'^[a-z0-9][a-z0-9._-]{1,60}\.(csv|txt|pdf|png|jpg|xlsx|json|zip)$', f) or not os.path.isfile(os.path.join(fixtures_dir or os.path.join(DOCS, 'fixtures'), f)):
                errs.append('%s: unknown fixture %r (built in: cover.png, sample.pdf, sample.csv, sample.txt; or add your own file to docs/manual/fixtures/)' % (where, f))
    if a == 'press' and not st.get('key'):
        errs.append('%s: press needs "key"' % where)
    if a == 'wait_for' and not any(k in st for k in ('target', 'url', 'text')):
        errs.append('%s: wait_for needs target, url or text' % where)
    if a == 'expect':
        that = st.get('that')
        conds = that if isinstance(that, list) else [that] if isinstance(that, dict) else None
        if not conds:
            errs.append('%s: expect needs "that" (an object or a list of objects)' % where)
        else:
            known = ('visible', 'hidden', 'text', 'not_text', 'value', 'count', 'url', 'checked', 'unchecked', 'enabled', 'disabled', 'http_status')
            for i, c in enumerate(conds):
                ks = [k for k in c if k in known and not (k == 'url' and 'http_status' in c)]
                if len(ks) != 1:
                    errs.append('%s.that[%d]: exactly one condition key of %s' % (where, i, ', '.join(known)))
                    continue
                k = ks[0]
                if k in ('visible', 'hidden', 'checked', 'unchecked', 'enabled', 'disabled'):
                    _check_target(c[k], '%s.that[%d].%s' % (where, i, k), errs)
                if k in ('value', 'count') and 'of' not in c:
                    errs.append('%s.that[%d]: %s needs "of": target' % (where, i, k))
                for aux in ('of', 'in'):
                    if aux in c:
                        _check_target(c[aux], '%s.that[%d].%s' % (where, i, aux), errs)
                if k == 'http_status' and 'url' not in c:
                    errs.append('%s.that[%d]: http_status needs "url"' % (where, i))
    if a == 'capture' and not (st.get('var') and re.match(r'^[a-z][a-z0-9_]*$', st['var'])):
        errs.append('%s: capture needs "var" (lowercase name)' % where)
    if st.get('shot') and not st.get('say'):
        errs.append('%s: a step with a picture needs "say" (the picture alt text and caption)' % where)
    if st.get('say') is not None and not str(st['say']).strip():
        errs.append('%s: "say" is empty' % where)
    for k in ('highlight', 'wait_after', 'mask'):
        if k in st and st[k] is not False:
            for j, t in enumerate(st[k] if isinstance(st[k], list) else [st[k]]):
                _check_target(t, '%s.%s[%d]' % (where, k, j), errs)
    crop = st.get('crop')
    if isinstance(crop, dict):
        _check_target(crop, where + '.crop', errs)
    elif crop is not None and crop not in ('viewport', 'page', 'panel', 'tight'):
        errs.append('%s: crop must be viewport|page|panel|tight or a target object' % where)
    ru = st.get('repeat_until')
    if ru is not None:
        if a != 'click' or not isinstance(ru, dict) or 'visible' not in ru:
            errs.append('%s: repeat_until is for click steps: {"visible": target, "max": n}' % where)
        else:
            _check_target(ru['visible'], where + '.repeat_until.visible', errs)
    when = st.get('when')
    if when is not None and not (isinstance(when, dict) and len([k for k in when if k in ('equals', 'not_equals')]) == 1):
        errs.append('%s: when must be {"equals":[a,b]} or {"not_equals":[a,b]}' % where)
    unknown = set(st) - {'do', 'target', 'url', 'value', 'label', 'key', 'file', 'say', 'shot', 'highlight', 'crop', 'crop_pad', 'mask', 'that', 'timeout', 'wait_after', 'var', 'regex', 'text', 'state',
                        'expect_download', 'expect_popup', 'expect_response', 'via_chooser', 'when', 'force', 'exact', 'settle_ms', 'secret', 'comment', 'optional', 'dialog', 'dialog_text', 'logins_ok', 'repeat_until', 'from_attr'}
    if unknown:
        errs.append('%s: unknown keys %s' % (where, ', '.join(sorted(unknown))))


def validate_scenario(data, path, roles=None):
    """Semantic validation on top of the JSON schema. Returns a list of error strings (empty = valid)."""
    errs = []
    try:
        import jsonschema
        schema = json.load(open(SCHEMA_FILE, encoding='utf-8'))
        v = jsonschema.Draft202012Validator(schema)
        for e in sorted(v.iter_errors(data), key=lambda e: list(e.path)):
            errs.append('schema: /%s: %s' % ('/'.join(str(p) for p in e.path), e.message[:200]))
    except ImportError:
        errs.append('[WARN] python package jsonschema missing; only semantic checks were run')
    if errs and any(not e.startswith('[WARN]') for e in errs):
        return errs
    mid = data.get('module')
    base = os.path.splitext(os.path.basename(path))[0]
    if mid != base:
        errs.append('module id %r must equal the file name %r' % (mid, base))
    wf_ids = [w['id'] for w in data.get('workflows', [])]
    if len(set(wf_ids)) != len(wf_ids):
        errs.append('duplicate workflow ids')
    for w in data.get('workflows', []):
        wid = w['id']
        if roles is not None and w['role'] != 'anonymous' and w['role'] not in roles:
            errs.append('workflow %s: role %r is not in the accounts file (known: %s)' % (wid, w['role'], ', '.join(sorted(roles))))
        for d in w.get('depends_on', []):
            if '.' not in d and d not in wf_ids:
                errs.append('workflow %s: depends_on %r is not a workflow of this module (use module.workflow for other modules)' % (wid, d))
            if d == wid:
                errs.append('workflow %s depends on itself' % wid)
        if not w.get('steps'):
            errs.append('workflow %s: no steps' % wid)
        if not w.get('verify'):
            errs.append('workflow %s: a workflow needs a "verify" block (how the run proves the task really did something)' % wid)
        for i, st in enumerate(w.get('steps', [])):
            _check_step(st, '%s.%s.steps[%d]' % (mid, wid, i), errs, wf_ids, None)
        for j, vb in enumerate(w.get('verify', [])):
            if vb.get('as') and roles is not None and vb['as'] != 'anonymous' and vb['as'] not in roles:
                errs.append('workflow %s verify[%d]: role %r unknown' % (wid, j, vb['as']))
            if not vb.get('steps'):
                errs.append('workflow %s verify[%d]: no steps' % (wid, j))
            for i, st in enumerate(vb.get('steps', [])):
                _check_step(st, '%s.%s.verify[%d].steps[%d]' % (mid, wid, j, i), errs, wf_ids, None, True)
            if not any(s.get('do') in ('expect', 'wait_for') or s.get('expect_download') or s.get('expect_response') for s in vb.get('steps', [])):
                errs.append('workflow %s verify[%d]: contains no expect/wait_for/expect_download, so it proves nothing' % (wid, j))
    for path_, s in _walk_strings(data):
        if EMOJI_RE.search(s):
            errs.append('emoji in %s' % path_)
        if re.search(r'password|รหัสผ่าน\s*[:：]\s*\S+', s, re.I) and not re.search(r'\{\{secret:', s) and path_.split('.')[-1] in ('say', 'value'):
            # "say" text may mention the word; only a literal secret assignment is refused
            if re.search(r'รหัสผ่าน\s*[:：]\s*\S+', s):
                errs.append('a literal password appears in %s' % path_)
    return errs


# ---------------------------------------------------------------------------------------------------------------------
# results
# ---------------------------------------------------------------------------------------------------------------------
def load_results(path):
    if os.path.exists(path):
        try:
            return json.load(open(path, encoding='utf-8'))
        except Exception:
            pass
    return {'schema': 1, 'modules': {}}


def save_results(path, res):
    tmp = '%s.%d.tmp' % (path, os.getpid())
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(res, f, ensure_ascii=False, indent=1)
    os.replace(tmp, path)


def module_complete(scn, scn_sha, mres):
    """A module result is complete when every workflow of the scenario has a result made from this exact scenario file."""
    if not mres:
        return False
    for w in scn.get('workflows', []):
        r = mres.get('workflows', {}).get(w['id'])
        if not r or r.get('scenario_sha256') != scn_sha:
            return False
    return True



# ---------------------------------------------------------------------------------------------------------------------
# machine-wide lock: one run.py / build.py (one Chrome) at a time on this PC
# ---------------------------------------------------------------------------------------------------------------------
LOCK_DIR = os.path.join(os.environ.get('PUBLIC') or tempfile.gettempdir(), 'saan-manual')
# SAAN_MANUAL_LOCK = a name: runs that give the same name wait for each other, runs with different names do not. For writers that each
# have their own install AND their own --out folder (results.json is only safe to share under one lock). Default: one lock for the PC.
_lock_name = re.sub(r'[^A-Za-z0-9_-]', '', os.environ.get('SAAN_MANUAL_LOCK', ''))
LOCK_FILE = os.path.join(LOCK_DIR, 'run-%s.lock' % _lock_name if _lock_name else 'run.lock')


def pid_alive(pid):
    """True while the process exists (Windows: OpenProcess + exit code; never os.kill, which would terminate it)."""
    if not pid:
        return False
    if os.name != 'nt':
        try:
            os.kill(pid, 0)
            return True
        except OSError:
            return False
    k = ctypes.windll.kernel32
    h = k.OpenProcess(0x1000, False, int(pid))  # PROCESS_QUERY_LIMITED_INFORMATION
    if not h:
        return False
    try:
        code = ctypes.c_ulong()
        return bool(k.GetExitCodeProcess(h, ctypes.byref(code))) and code.value == 259  # STILL_ACTIVE
    finally:
        k.CloseHandle(h)


class RunLock:
    """File lock created with O_CREAT|O_EXCL (atomic), content = JSON {pid, group, since, token}. A heartbeat thread touches the file every
    20 s while it is held. It is stale (taken over) when its owner process is gone or the file was not touched for `stale_after` seconds.
    Released by release(), atexit, and the `with` block; a kill -9 leaves a lock that the next run takes over because the owner is gone."""

    def __init__(self, group='run', wait=1800, path=LOCK_FILE, stale_after=900, log=print):
        self.group, self.wait, self.path, self.stale_after, self.log = group, wait, path, stale_after, log
        self.token = '%d-%f' % (os.getpid(), time.time())
        self.held = False
        self._stop = threading.Event()

    def _read(self):
        try:
            return json.load(open(self.path, encoding='utf-8'))
        except Exception:
            return None

    def _stale(self, info):
        if info is None:
            try:  # unreadable or half-written: stale only if old
                return time.time() - os.path.getmtime(self.path) > 30
            except OSError:
                return False
        if not pid_alive(info.get('pid')):
            return True
        try:
            return time.time() - os.path.getmtime(self.path) > self.stale_after
        except OSError:
            return False

    def _try_create(self):
        os.makedirs(os.path.dirname(self.path), exist_ok=True)
        try:
            fd = os.open(self.path, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
        except FileExistsError:
            return False
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump({'pid': os.getpid(), 'group': self.group, 'since': time.strftime('%H:%M'), 'token': self.token}, f)
        return True

    def _take_over(self, info):
        """Moves a stale lock away atomically; if what was moved turns out to be a fresh live lock (a race with another taker) it is put back."""
        mine = '%s.stale.%d' % (self.path, os.getpid())
        try:
            os.replace(self.path, mine)
        except OSError:
            return
        moved = None
        try:
            moved = json.load(open(mine, encoding='utf-8'))
        except Exception:
            pass
        if moved and info and moved.get('token') != info.get('token') and pid_alive(moved.get('pid')):
            try:
                os.replace(mine, self.path)
                return
            except OSError:
                pass
        try:
            os.remove(mine)
        except OSError:
            pass

    def acquire(self):
        end = time.time() + self.wait
        announced = False
        while True:
            if self._try_create():
                self.held = True
                atexit.register(self.release)
                threading.Thread(target=self._beat, daemon=True).start()
                return True
            info = self._read()
            if self._stale(info):
                self._take_over(info)
                continue
            if not announced:
                who = ('%s/%s since %s' % (info.get('group', '?'), info.get('pid', '?'), info.get('since', '?'))) if info else 'unknown'
                self.log('waiting for another run, held by %s' % who)
                announced = True
            if time.time() > end:
                return False
            time.sleep(1.0)

    def holder(self):
        info = self._read() or {}
        return '%s/%s since %s' % (info.get('group', '?'), info.get('pid', '?'), info.get('since', '?'))

    def _beat(self):
        while not self._stop.wait(20):
            try:
                info = self._read()
                if info and info.get('token') == self.token:
                    os.utime(self.path, None)
            except OSError:
                pass

    def release(self):
        if not self.held:
            return
        self.held = False
        self._stop.set()
        try:
            info = self._read()
            if info and info.get('token') == self.token:
                os.remove(self.path)
        except OSError:
            pass

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.release()
