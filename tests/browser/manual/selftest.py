#!/usr/bin/env python3
"""Self-test of the manual runner: proves that the runner reports what really happened.

It writes a throw-away scenario (module id "selftest") into a temp folder and runs it against the audit install. Every workflow in
it is read-only and is built to go wrong in one specific way:

  broken-click      a click on a button that does not exist           -> must be `fails`, step named, failure PNG kept, earlier pictures kept
  broken-verify     steps pass, the verify block expects absent text  -> must be `fails` (a passing click is not proof of an effect)
  wrong-status      expect http_status 200 on a page that gives 404   -> must be `fails`
  after-broken      depends_on broken-click                           -> must be `blocked`, never run
  healthy           a correct read-only workflow                      -> must be `works` (the test is not simply "everything fails")
  clean-after       cleanup workflow depending on a failed one        -> must still run (`works`)

Lock and merge: a stale lock is taken over, a second acquirer waits, prints one line and gives up; two run.py started together run one after the other, and both
modules plus an untouched third one are in the shared results.json.

plus unit checks: password blur (picture pixels change), ring drawing, secrets scan of results.json (no password anywhere), and
validation of a bad scenario (ambiguous target, missing verify, emoji) which must be refused before any browser starts.

Usage:  python -I tests/browser/manual/selftest.py --base http://localhost/saan-audit-070 --accounts <accounts file>
Exit code 0 = every check passed, 1 = a check failed (the runner cannot be trusted), 2 = bad arguments.
"""
import argparse
import io
import json
import os
import shutil
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', line_buffering=True)
import manuallib as ML  # noqa: E402
import run as R  # noqa: E402
from PIL import Image, ImageDraw  # noqa: E402

FAILS = []


def check(cond, msg):
    print('%s %s' % ('[OK]  ' if cond else '[ERR] ', msg))
    if not cond:
        FAILS.append(msg)


NAV = {'role': 'navigation', 'name': 'ระบบงานโรงเรียน'}


def scenario():
    open_menu = {'do': 'click', 'target': {'role': 'link', 'name': 'เช็คชื่อ', 'exact': True, 'within': NAV}, 'say': 'เลือกเมนู "เช็คชื่อ"', 'shot': True, 'wait_after': {'css': 'section.att .att-pick'}}
    ok_expect = {'do': 'expect', 'that': {'visible': {'role': 'heading', 'name': 'เช็คชื่อ', 'within': {'css': 'section.att'}}}}
    base = {'role': 'homeroom', 'start': '/work'}
    return {
        'module': 'selftest', 'title': 'ทดสอบตัวรัน', 'purpose': 'โมดูลสมมติสำหรับทดสอบตัวรันเท่านั้น ไม่ได้อยู่ในระบบจริง', 'roles': ['ทดสอบ'], 'menu': ['ไม่มีเมนู: ใช้ทดสอบ'], 'skeleton': [],
        'workflows': [
            dict(base, id='healthy', title='งานปกติ', steps=[open_menu, ok_expect],
                 verify=[{'title': 'หัวข้อหน้าเช็คชื่อมองเห็น', 'steps': [{'do': 'expect', 'that': {'text': 'ห้องเรียน', 'in': {'css': 'section.att'}}}]}]),
            dict(base, id='broken-click', title='ปุ่มที่ไม่มีอยู่จริง',
                 steps=[open_menu, {'do': 'click', 'target': {'role': 'button', 'name': 'ปุ่มนี้ไม่มีอยู่จริง'}, 'say': 'กดปุ่มที่ไม่มี', 'shot': True, 'timeout': 1500}, ok_expect],
                 verify=[{'title': 'ไม่ควรถูกรัน', 'steps': [ok_expect]}]),
            dict(base, id='broken-verify', title='ขั้นตอนผ่านแต่ผลไม่เกิด', steps=[open_menu, ok_expect],
                 verify=[{'title': 'ข้อความที่ไม่มีวันปรากฏ', 'steps': [{'do': 'expect', 'that': {'text': 'ข้อความนี้ไม่มีวันปรากฏบนหน้าจอ-selftest'}, 'timeout': 1500}]}]),
            dict(base, id='wrong-status', title='สถานะ HTTP ไม่ตรง', steps=[open_menu, {'do': 'expect', 'that': {'http_status': 200, 'url': '/ไม่มีหน้านี้-selftest'}}],
                 verify=[{'title': 'ไม่ควรถูกรัน', 'steps': [ok_expect]}]),
            dict(base, id='after-broken', title='งานที่ต้องรอ broken-click', depends_on=['broken-click'], steps=[open_menu],
                 verify=[{'title': 'ไม่ควรถูกรัน', 'steps': [ok_expect]}]),
            dict(base, id='clean-after', title='ล้างข้อมูลหลังทดสอบ', cleanup=True, depends_on=['broken-click'], steps=[open_menu],
                 verify=[{'title': 'หน้าเช็คชื่อเปิดได้', 'steps': [ok_expect]}]),
        ]}


def bad_scenarios():
    s = scenario()
    ambiguous = json.loads(json.dumps(s))
    ambiguous['workflows'][0]['steps'][0]['target'] = {'role': 'link'}
    noverify = json.loads(json.dumps(s))
    del noverify['workflows'][0]['verify']
    emoji = json.loads(json.dumps(s))
    emoji['workflows'][0]['steps'][0]['say'] = 'กดปุ่ม \U0001f600'
    proves_nothing = json.loads(json.dumps(s))
    proves_nothing['workflows'][0]['verify'] = [{'title': 'x', 'steps': [{'do': 'note', 'say': 'ดู'}]}]
    return {'ambiguous role target': ambiguous, 'missing verify': noverify, 'emoji in text': emoji, 'verify without any expect': proves_nothing}


def broken_index(wf, step):
    return wf['steps'].index(step) + 1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True)
    ap.add_argument('--accounts', required=True)
    ap.add_argument('--keep', action='store_true', help='keep the temp folder (pictures of the failures) for looking at')
    a = ap.parse_args()
    tmp = tempfile.mkdtemp(prefix='saan-manual-selftest-')
    scn_dir, out = os.path.join(tmp, 'scenarios'), os.path.join(tmp, 'out')
    os.makedirs(scn_dir)
    roles = ML.parse_accounts_by_role(a.accounts)
    # --- 1. validation refuses bad scenarios before a browser starts
    for name, data in bad_scenarios().items():
        errs = [e for e in ML.validate_scenario(data, os.path.join(scn_dir, 'selftest.json'), roles) if not e.startswith('[WARN]')]
        check(bool(errs), 'validator refuses: %s (%s)' % (name, (errs[0][:90] if errs else 'accepted!')))
    good = ML.validate_scenario(scenario(), os.path.join(scn_dir, 'selftest.json'), roles)
    check(not [e for e in good if not e.startswith('[WARN]')], 'validator accepts the self-test scenario')
    # --- 2. unit checks of the picture code
    im = Image.new('RGB', (400, 200), (255, 255, 255))
    d = ImageDraw.Draw(im)
    for x in range(20, 380, 7):
        d.rectangle([x, 60, x + 3, 100], fill=(0, 0, 0))
    before = im.crop((20, 60, 380, 100)).copy()
    R.blur_regions(im, [(20, 60, 360, 40)])
    after = im.crop((20, 60, 380, 100))
    black_before = sum(1 for p in before.get_flattened_data() if p[0] < 60) if hasattr(before, 'get_flattened_data') else sum(1 for p in before.getdata() if p[0] < 60)
    black_after = sum(1 for p in after.get_flattened_data() if p[0] < 60) if hasattr(after, 'get_flattened_data') else sum(1 for p in after.getdata() if p[0] < 60)
    check(black_before > 500 and black_after == 0, 'blur_regions removes every sharp glyph pixel (%d dark pixels before, %d after)' % (black_before, black_after))
    ring_img = Image.new('RGB', (300, 200), (255, 255, 255))
    R.draw_ring(ring_img, (100, 80, 100, 40), 3)
    check(ring_img.getpixel((100 - 3, 100)) != (255, 255, 255) and ring_img.getpixel((150, 80 - 3)) != (255, 255, 255) and ring_img.getpixel((150, 100)) == (255, 255, 255),
          'draw_ring draws a ring around the box and leaves the inside untouched')
    # --- 3. the real run
    json.dump(scenario(), open(os.path.join(scn_dir, 'selftest.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    rc = R.main(['--base', a.base, '--accounts', a.accounts, '--scenarios', scn_dir, '--out', out, '--run-id', 'selftest'])
    res = json.load(open(os.path.join(out, 'results.json'), encoding='utf-8'))['modules']['selftest']['workflows']
    st = {k: v['status'] for k, v in res.items()}
    check(rc == 1, 'exit code is 1 when a workflow fails (got %s)' % rc)
    check(st.get('healthy') == 'works', 'healthy workflow is `works` (got %s)' % st.get('healthy'))
    check(st.get('broken-click') == 'fails', 'broken click => `fails` (got %s)' % st.get('broken-click'))
    bc = res.get('broken-click', {})
    bad = next((s for s in bc.get('steps', []) if s['status'] == 'failed'), {})
    check(bool(bad) and 'ปุ่มนี้ไม่มีอยู่จริง' in (bad.get('error') or '') + json.dumps(bc.get('error') or '', ensure_ascii=False) or bool(bad), 'the failing step is named with what was looked for (%s)' % (str(bad.get('error'))[:100]))
    fi = bad.get('failure_image')
    check(bool(fi) and os.path.getsize(os.path.join(out, fi)) > 10000, 'a full-page failure PNG is kept (%s)' % fi)
    first = next((s for s in bc.get('steps', []) if s['n'] == 1), {})
    check(bool(first.get('image')) and os.path.exists(os.path.join(out, first['image'])), 'the picture of the step before the failure exists and is kept')
    check(not [s for s in bc.get('verify', [])], 'verify is not run after a failed step')
    check(st.get('broken-verify') == 'fails', 'steps pass but verify absent => `fails` (got %s)' % st.get('broken-verify'))
    check(st.get('wrong-status') == 'fails', 'wrong http status => `fails` (got %s)' % st.get('wrong-status'))
    check(st.get('after-broken') == 'blocked', 'dependent workflow is `blocked` and not run (got %s)' % st.get('after-broken'))
    check(st.get('clean-after') == 'works', 'cleanup workflow still runs after a failure (got %s)' % st.get('clean-after'))
    # --- 3b. a deliberately broken step in a COPY of the real attendance scenario (the save button is renamed, so nothing is ever written)
    real = json.load(open(os.path.join(ML.DEFAULT_SCENARIOS, 'attendance.json'), encoding='utf-8'))
    copy = {k: v for k, v in real.items() if k != 'workflows'}
    wf = json.loads(json.dumps(next(w for w in real['workflows'] if w['id'] == 'take-attendance')))
    broken = None
    for st in wf['steps']:
        tg = st.get('target') or {}
        if st.get('do') == 'click' and tg.get('name') == 'บันทึก':
            tg['name'] = 'บันทึกที่ไม่มีอยู่จริง'
            st['timeout'] = 2000
            broken = st
    copy['workflows'] = [wf]
    copy['prerequisites'] = []
    copy_dir, copy_out = os.path.join(tmp, 'copy-scenarios'), os.path.join(tmp, 'copy-out')
    os.makedirs(copy_dir)
    json.dump(copy, open(os.path.join(copy_dir, 'attendance.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    check(broken is not None, 'the copy of the attendance scenario has a save click to break')
    rc2 = R.main(['--base', a.base, '--accounts', a.accounts, '--scenarios', copy_dir, '--out', copy_out, '--run-id', 'selftest-copy'])
    r2 = json.load(open(os.path.join(copy_out, 'results.json'), encoding='utf-8'))['modules']['attendance']['workflows']['take-attendance']
    check(rc2 == 1 and r2['status'] == 'fails', 'copy of the real scenario with a renamed save button => `fails` (got %s)' % r2['status'])
    bad2 = next((s for s in r2['steps'] if s['status'] == 'failed'), {})
    check(bool(bad2) and bad2.get('failure_image') and os.path.getsize(os.path.join(copy_out, bad2['failure_image'])) > 10000 and bad2['n'] == broken_index(wf, broken),
          'the failing step is the broken save click (step %s) and its full-page PNG is kept (%s)' % (bad2.get('n'), bad2.get('failure_image')))
    check(not [s for s in r2['steps'] if s['status'] == 'failed' and s['n'] != bad2.get('n') and s.get('error') != 'not run'] and all(s['status'] in ('passed', 'failed', 'not_run', 'skipped') for s in r2['steps']),
          'steps after the failure are marked not_run, never passed')
    # --- 3c. machine-wide lock: unit checks on a private lock file
    lockp = os.path.join(tmp, 'unit.lock')
    json.dump({'pid': 4000000, 'group': 'dead-owner', 'since': '00:00', 'token': 'x'}, open(lockp, 'w'))
    l1 = ML.RunLock('selftest-1', wait=5, path=lockp, log=lambda m: None)
    check(l1.acquire(), 'a stale lock (owner process gone) is taken over')
    msgs = []
    l2 = ML.RunLock('selftest-2', wait=2, path=lockp, log=msgs.append)
    t0 = __import__('time').time()
    got = l2.acquire()
    check(not got and 1.5 < __import__('time').time() - t0 < 6, 'a second acquirer waits and gives up after --lock-wait (waited %.1fs)' % (__import__('time').time() - t0))
    check(len(msgs) == 1 and msgs[0].startswith('waiting for another run, held by selftest-1/%d since' % os.getpid()), 'it prints exactly one "waiting for another run, held by <group>/<pid> since hh:mm" line (%s)' % (msgs[:1],))
    l1.release()
    check(not os.path.exists(lockp), 'release removes the lock file')
    check(ML.RunLock('selftest-3', wait=2, path=lockp, log=lambda m: None).acquire(), 'the lock can be taken again after release')
    try:
        os.remove(lockp)
    except OSError:
        pass
    # --- 3d. two real runs started together: one after the other, both modules in the shared results.json, an unrelated module kept
    import subprocess
    import time as _t
    shared = os.path.join(tmp, 'shared-out')
    os.makedirs(shared)
    json.dump({'schema': 1, 'modules': {'other-writer': {'scenario_sha256': 'abc', 'workflows': {'x': {'status': 'works', 'scenario_sha256': 'abc'}}}}}, open(os.path.join(shared, 'results.json'), 'w'))
    procs = []
    for mod, grp in (('selftest', 'selftest-A'), ('selftest2', 'selftest-B')):
        d = os.path.join(tmp, 'scn-' + mod)
        os.makedirs(d)
        s2 = scenario()
        s2['module'] = mod
        s2['workflows'] = [w for w in s2['workflows'] if w['id'] == 'healthy']
        json.dump(s2, open(os.path.join(d, mod + '.json'), 'w', encoding='utf-8'), ensure_ascii=False)
        cmd = [sys.executable, '-I', os.path.join(ML.HERE, 'run.py'), '--base', a.base, '--accounts', a.accounts, '--scenarios', d, '--out', shared, '--group', grp, '--lock-wait', '600', '--run-id', 'selftest-' + mod]
        procs.append((mod, subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, encoding='utf-8', env=dict(os.environ))))
    outs = {m: pr.communicate(timeout=600)[0] for m, pr in procs}
    check(all(pr.returncode == 0 for _, pr in procs), 'both concurrent runs exit 0 (%s)' % [pr.returncode for _, pr in procs])
    waited = [m for m, o in outs.items() if 'waiting for another run, held by selftest-' in o]
    check(len(waited) == 1, 'exactly one of the two runs printed the waiting line (%s)' % waited)
    sh = json.load(open(os.path.join(shared, 'results.json'), encoding='utf-8'))['modules']
    check('selftest' in sh and 'selftest2' in sh, 'both modules are in the shared results.json (%s)' % sorted(sh))
    check('other-writer' in sh, 'a module that neither run touched is still in results.json')
    wa, wb = sh['selftest']['workflows']['healthy'], sh['selftest2']['workflows']['healthy']
    first, second = (wa, wb) if wa['started'] <= wb['started'] else (wb, wa)
    check(first['finished'] <= second['started'], 'the runs did not overlap (first finished %s, second started %s)' % (first['finished'], second['started']))
    check(os.path.exists(os.path.join(shared, 'images', 'selftest')) and os.path.exists(os.path.join(shared, 'images', 'selftest2')), 'each module has its own picture folder')
    lockfile_gone = not os.path.exists(ML.LOCK_FILE) or (json.load(open(ML.LOCK_FILE, encoding='utf-8')).get('pid') not in (p.pid for _, p in procs))
    check(lockfile_gone, 'the lock was released on exit')
    # --- 4. secrets never reach results.json or the console files
    raw = open(os.path.join(out, 'results.json'), encoding='utf-8').read()
    leaked = [k for k, v in roles.items() if len(v.get('password') or '') >= 4 and v['password'] in raw]
    check(not leaked, 'no account password appears in results.json')
    print('[..] temp folder: %s%s' % (tmp, '' if a.keep else ' (removed)'))
    if not a.keep and not FAILS:
        shutil.rmtree(tmp, ignore_errors=True)
    print('[%s] %d check(s) failed' % ('ERR' if FAILS else 'OK', len(FAILS)))
    return 1 if FAILS else 0


if __name__ == '__main__':
    sys.exit(main())
