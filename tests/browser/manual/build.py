#!/usr/bin/env python3
"""Generator of the Saan manual and the audit file, from scenarios + results.json + pictures. No browser needed.

Writes (default --out docs/manual):
  th/<module>.md     one manual page per module that has a scenario (steps, pictures, expected result, known limits)
  th/index.md        modules grouped by area of school work, with status
  th/images/<module>/*.webp   the pictures the pages use (copied from <run>/images; stale ones removed)
  audit.th.md        audit table (workflows total / works / fails / blocked, skeleton items, verdict) and evidence per module

Verdict rule (the only place a verdict is made): it comes from results.json of a COMPLETE run of the module, meaning every
workflow of the scenario has a result produced from the exact scenario file now on disk (SHA-256 match). Otherwise the verdict
is "ยังไม่ได้ตรวจ", whatever else is known.

Usage: python -I tests/browser/manual/build.py [--results docs/manual/run/results.json] [--accounts accounts.txt] [--out docs/manual]
Exit code: 0 = pages written, 3 = could not get the run lock, 1 = a password or secret was found in generated text (nothing is kept), 2 = bad input.
"""
import argparse
import datetime
import json
import os
import re
import shutil
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', line_buffering=True)
import manuallib as ML  # noqa: E402

ROLE_TH = {
    'admin': 'ผู้ดูแลระบบ', 'principal': 'ผู้อำนวยการ', 'homeroom': 'ครูประจำชั้น', 'teacher': 'ครูประจำชั้น', 'subject': 'ครูผู้สอน',
    'finance': 'เจ้าหน้าที่การเงิน', 'payroll': 'เจ้าหน้าที่เงินเดือน', 'clerk': 'ธุรการ', 'editor': 'ผู้ดูแลเว็บไซต์',
    'student1': 'นักเรียน', 'student2': 'นักเรียน', 'guardian1': 'ผู้ปกครอง', 'guardian2': 'ผู้ปกครอง', 'anonymous': 'ผู้เข้าชมทั่วไป (ไม่ต้องเข้าสู่ระบบ)',
}
VERDICT_DEF = [
    ('ใช้งานได้', 'ทุกงาน (workflow) ของโมดูลผ่านการทดสอบด้วยเบราว์เซอร์จริงทั้งขั้นตอนและการตรวจผล และไม่พบรายการ "มีแต่โครง" ในโมดูลนี้'),
    ('ใช้ได้บางส่วน', 'มีบางงานผ่านการทดสอบ แต่บางงานไม่ผ่านหรือถูกบล็อก หรือมีรายการ "มีแต่โครง" ที่ยืนยันแล้วหรือยังไม่ได้ยืนยัน รายการที่ใช้ไม่ได้แสดงไว้ใต้ตาราง'),
    ('มีแต่โครง', 'ไม่มีงานใดทำสำเร็จตั้งแต่ต้นจนจบ หรืองานหลักของโมดูล (core) ไม่ผ่าน หรือโมดูลไม่มีหน้าจอให้ใช้งาน'),
    ('ยังไม่ได้ตรวจ', 'ยังไม่มีผลการรันที่ครบทุกงานและตรงกับไฟล์ scenario ปัจจุบัน'),
]


def md_text(s):
    """Plain text safe for a Markdown paragraph / image alt: no brackets, no pipes, one line."""
    s = re.sub(r'\s+', ' ', str(s)).strip()
    return s.replace('[', '(').replace(']', ')').replace('|', '/')


def fmt_dt(iso):
    try:
        d = datetime.datetime.fromisoformat(iso)
        return '%d %s %d เวลา %02d:%02d น.' % (d.day, ML.THAI_MONTHS_SHORT[d.month - 1], d.year + 543, d.hour, d.minute)
    except Exception:
        return str(iso)


def verdict(scn, sha, mres):
    """Returns (verdict, counts dict, complete flag)."""
    wfs = scn.get('workflows', [])
    counts = {'total': len(wfs), 'works': 0, 'fails': 0, 'blocked': 0, 'missing': 0}
    complete = ML.module_complete(scn, sha, mres)
    for w in wfs:
        r = (mres or {}).get('workflows', {}).get(w['id']) if mres else None
        if not r or r.get('scenario_sha256') != sha:
            counts['missing'] += 1
        else:
            counts[{'works': 'works', 'fails': 'fails', 'blocked': 'blocked'}.get(r['status'], 'fails')] += 1
    sk = len(scn.get('skeleton', []))
    if not complete:
        return 'ยังไม่ได้ตรวจ', counts, False
    if counts['total'] == 0:
        return ('มีแต่โครง' if sk else 'ยังไม่ได้ตรวจ'), counts, True
    core_bad = any(w.get('core') and mres['workflows'][w['id']]['status'] != 'works' for w in wfs)
    if counts['works'] == counts['total'] and sk == 0:
        return ('ใช้ได้บางส่วน' if scn.get('coverage') == 'partial' else 'ใช้งานได้'), counts, True
    if counts['works'] == 0 or core_bad:
        return 'มีแต่โครง', counts, True
    return 'ใช้ได้บางส่วน', counts, True


def wf_result(scn, sha, mres, wid):
    r = ((mres or {}).get('workflows') or {}).get(wid)
    return r if r and r.get('scenario_sha256') == sha else None


def step_error_plain(r):
    e = r.get('error') or ''
    e = re.sub(r'\s+', ' ', e)
    return md_text(e)[:300]


def status_line(r):
    if r is None:
        return 'ยังไม่ได้ตรวจ: ยังไม่มีผลการรันที่ตรงกับ scenario ปัจจุบัน'
    when = fmt_dt(r.get('finished', '')) if r.get('finished') else ''
    if r['status'] == 'works':
        return 'ทดสอบด้วยเบราว์เซอร์แล้ว: ใช้งานได้ ผ่านทุกขั้นตอนและการตรวจผล (%s, %s วินาที)' % (when, r.get('seconds'))
    if r['status'] == 'blocked':
        return 'ยังไม่ได้ทดสอบ: งานที่ต้องทำก่อนไม่ผ่าน (%s)' % md_text(', '.join(r.get('blocked_by', [])))
    return 'ทดสอบด้วยเบราว์เซอร์แล้ว: ใช้งานไม่ได้ (%s) สิ่งที่พบ: %s' % (when, step_error_plain(r))


def build_workflow_section(L, scn, sha, mres, w, images_used):
    mod = scn['module']
    r = wf_result(scn, sha, mres, w['id'])
    L.append('## %s' % md_text(w['title']))
    L.append('')
    L.append('> %s' % status_line(r))
    L.append('')
    if w.get('intro'):
        L.append(w['intro'])
        L.append('')
    L.append('บัญชีที่ใช้ในตัวอย่างนี้: %s' % ROLE_TH.get(w['role'], w['role']))
    L.append('')
    start = w['start']
    L.append('เริ่มจากหน้า: `%s`' % re.sub(r'\{\{[^}]*\}\}', '...', start))
    L.append('')
    if w.get('cleanup'):
        L.append('> หมายเหตุ: งานนี้ล้างข้อมูลที่งานอื่นสร้างขึ้นระหว่างการทดสอบ ใช้ในการทดสอบเท่านั้น')
        L.append('')
    L.append('### ขั้นตอน')
    L.append('')
    steps_res = (r or {}).get('steps', [])
    by_idx = {}
    # runner records one result per scenario step in order, 'setup' failures aside
    real = [s for s in steps_res if s.get('do') != 'setup']
    for i, st in enumerate(w['steps']):
        by_idx[i] = real[i] if i < len(real) else None
    n = 0
    for i, st in enumerate(w['steps']):
        say = st.get('say')
        rec = by_idx.get(i)
        if rec and rec.get('status') == 'skipped':
            continue
        if not say:
            continue
        n += 1
        text = (rec or {}).get('say') or say_clean(say, st)
        L.append('**ขั้นที่ %d** %s' % (n, text))
        L.append('')
        if rec and rec.get('image'):
            L.append('![%s](%s)' % (md_text(text), image_ref(rec['image'])))
            images_used.append(rec['image'])
            L.append('')
        elif rec and rec.get('status') == 'failed':
            L.append('> ขั้นตอนนี้ไม่ผ่านการทดสอบ: %s' % md_text(rec.get('error', ''))[:300])
            L.append('')
    if n == 0:
        L.append('(ไม่มีขั้นตอนที่ต้องทำ)')
        L.append('')
    L.append('### ผลที่ควรเห็น')
    L.append('')
    if w.get('expected'):
        L.append(w['expected'])
        L.append('')
    vres = (r or {}).get('verify', [])
    for j, vb in enumerate(w['verify']):
        vrec = vres[j] if j < len(vres) else None
        shown = False
        for i, st in enumerate(vb['steps']):
            if not st.get('say'):
                continue
            rec = vrec['steps'][i] if vrec and i < len(vrec['steps']) else None
            if rec and rec.get('status') == 'skipped':
                continue
            if not shown:
                L.append('**%s**' % md_text(vb['title']))
                L.append('')
                shown = True
            vtext = (rec or {}).get('say') or say_clean(st['say'], st)
            L.append(vtext)
            L.append('')
            if rec and rec.get('image'):
                L.append('![%s](%s)' % (md_text(vtext), image_ref(rec['image'])))
                images_used.append(rec['image'])
                L.append('')
        if not shown:
            L.append('- %s' % md_text(vb['title']))
    L.append('')


def say_clean(say, st):
    """The instruction as printed: variables are shown as what the reader would type (marker -> a sample note)."""
    s = say
    s = re.sub(r'\{\{marker\}\}', 'ทดสอบคู่มือ', s)
    s = re.sub(r'\{\{last_weekday\|th_short\}\}', 'วันที่เลือก', s)
    s = re.sub(r'\{\{last_weekday\|day\}\}', 'ที่เลือก', s)
    s = re.sub(r'\{\{[^}]*\}\}', '', s)
    return re.sub(r'\s+', ' ', s).strip()


def image_ref(rel):
    # rel is images/<module>/<file>.webp relative to the run folder; the page lives in th/ next to th/images/
    return rel


def module_page(scn, sha, mres, modmeta, images_used, scenarios_all, modmeta_all=None):
    mod = scn['module']
    v, counts, complete = verdict(scn, sha, mres)
    L = ['# %s' % md_text(scn['title']), '']
    L.append('> สถานะการตรวจโมดูลนี้: **%s** (ดูความหมายและตารางรวมที่ [ผลการตรวจระบบ](../audit.th.md))' % v)
    L.append('')
    if mres and complete and mres.get('updated'):
        L.append('ทดสอบล่าสุด: %s บนระบบทดลอง (โรงเรียนสมมติ) ของรุ่นที่ตรวจ' % fmt_dt(mres['updated']))
        L.append('')
    if scn.get('coverage') == 'partial':
        L.append('> ขอบเขตของการตรวจ: ครอบคลุมบางส่วนของโมดูล %s' % md_text(scn.get('coverage_note', '')))
        L.append('')
    L.append('## ใช้ทำอะไร')
    L.append('')
    L.append(scn['purpose'])
    L.append('')
    L.append('## ใครใช้')
    L.append('')
    for r in scn['roles']:
        L.append('- %s' % r)
    L.append('')
    L.append('## อยู่ที่ไหนในเมนู')
    L.append('')
    for m in scn['menu']:
        L.append('- %s' % m)
    L.append('')
    L.append('## ก่อนเริ่มใช้งาน')
    L.append('')
    pre = scn.get('prerequisites', [])
    if not pre:
        L.append('ไม่มีข้อกำหนดล่วงหน้า')
    for p in pre:
        title = scenarios_all.get(p['module'], {}).get('title') or (modmeta_all or {}).get(p['module'], {}).get('name') or p['module']
        link = '[%s](%s.md)' % (md_text(title), p['module']) if p['module'] in scenarios_all else '%s (%s)' % (md_text(title), p['module'])
        L.append('- %s: %s' % (link, p['text']))
    L.append('')
    for w in scn['workflows']:
        build_workflow_section(L, scn, sha, mres, w, images_used)
    # limits
    L.append('## ข้อจำกัดที่ทราบ')
    L.append('')
    any_limit = False
    bad = []
    for w in scn['workflows']:
        r = wf_result(scn, sha, mres, w['id'])
        if r and r['status'] in ('fails', 'blocked'):
            bad.append((w, r))
    if bad:
        any_limit = True
        L.append('### งานที่ทดสอบแล้วใช้งานไม่ได้')
        L.append('')
        for w, r in bad:
            L.append('- **%s** (%s): %s' % (md_text(w['title']), 'ไม่ผ่าน' if r['status'] == 'fails' else 'ถูกบล็อก', step_error_plain(r)))
        L.append('')
    # a writer's note may name a workflow by its internal id; the reader gets the title of that task instead
    ids = sorted(((w['id'], w.get('title') or w['id']) for w in scn.get('workflows', [])), key=lambda x: -len(x[0]))

    def reader(text):
        text = str(text)
        for wid, title in ids:
            text = re.sub(r'(?<![\w-])%s(?![\w-])' % re.escape(wid), lambda m: '"%s"' % title, text)
        return md_text(text)
    sk = scn.get('skeleton', [])
    if sk:
        any_limit = True
        L.append('### สิ่งที่หน้าจอหรือเมนูบอกว่ามี แต่ยังไม่มีการทำงานจริง')
        L.append('')
        for s in sk:
            L.append('- **%s** %s' % (s['id'], reader(s['promised'])))
            L.append('  - ตำแหน่ง: %s' % reader(s['where']))
            L.append('  - สิ่งที่เกิดจริง: %s' % reader(s['observed']))
            L.append('  - หลักฐาน: %s' % reader(s['evidence']))
            if not s.get('confirmed'):
                L.append('  - ยังไม่ได้ยืนยัน: %s' % reader(s.get('confirm_how', '')))
        L.append('')
    un = scn.get('untested', [])
    if un:
        any_limit = True
        L.append('### ส่วนที่ยังไม่ได้ตรวจด้วยเบราว์เซอร์')
        L.append('')
        for u in un:
            L.append('- %s' % reader(u))
        L.append('')
    if not any_limit:
        L.append('ไม่พบข้อจำกัดจากการตรวจครั้งนี้')
        L.append('')
    return '\n'.join(L).rstrip() + '\n'


def audit_page(rows, generated, base):
    L = ['# ผลการตรวจการใช้งานจริงของระบบสาน', '']
    L.append('ไฟล์นี้สร้างจากการรันขั้นตอนใช้งานจริงในเบราว์เซอร์ (Chrome) บนระบบทดลอง (โรงเรียนสมมติ) ไม่ได้สรุปจากการอ่านโค้ดอย่างเดียว สร้างเมื่อ %s' % generated)
    L.append('')
    L.append('## ความหมายของผลสรุป')
    L.append('')
    for w, d in VERDICT_DEF:
        L.append('- **%s**: %s' % (w, d))
    L.append('')
    L.append('งาน (workflow) คือภารกิจจริงที่ผู้ใช้ทำ เช่น ครูเช็คชื่อห้องหนึ่งของวันหนึ่ง สถานะของงานเขียนโดยตัวรันทดสอบ ไม่ใช่ผู้เขียน scenario: ใช้งานได้ (ทุกขั้นตอนและการตรวจผลผ่าน และไม่มีข้อผิดพลาด HTTP 5xx หรือ JavaScript) / ไม่ผ่าน / ถูกบล็อก (งานที่ต้องทำก่อนไม่ผ่าน)')
    L.append('')
    L.append('## ตารางรวม')
    L.append('')
    L.append('| โมดูล | งานทั้งหมด | ผ่าน | ไม่ผ่าน | ถูกบล็อก | ยังไม่ได้รัน | รายการโครง | ผลสรุป |')
    L.append('|---|---|---|---|---|---|---|---|')
    for r in rows:
        c = r['counts']
        L.append('| [%s](th/%s.md) | %d | %d | %d | %d | %d | %s | **%s** |' % (md_text(r['title']), r['id'], c['total'], c['works'], c['fails'], c['blocked'], c['missing'], r['skeleton'], r['verdict'])
                 if r['has_scenario'] else '| %s (%s) | - | - | - | - | - | - | **%s** |' % (md_text(r['title']), r['id'], r['verdict']))
    L.append('')
    tot = {}
    for r in rows:
        tot[r['verdict']] = tot.get(r['verdict'], 0) + 1
    L.append('รวม %d โมดูล: %s' % (len(rows), ', '.join('%s %d' % (w, tot.get(w, 0)) for w, _ in VERDICT_DEF)))
    L.append('')
    L.append('## หลักฐานรายโมดูล')
    L.append('')
    for r in rows:
        L.append('### %s (%s)' % (md_text(r['title']), r['id']))
        L.append('')
        L.append('ผลสรุป: **%s**' % r['verdict'])
        L.append('')
        if r.get('coverage_note'):
            L.append('ขอบเขตการตรวจ (ครอบคลุมบางส่วน): %s' % md_text(r['coverage_note']))
            L.append('')
        if not r['has_scenario']:
            L.append('ยังไม่มี scenario ของโมดูลนี้ จึงยังไม่มีการรันในเบราว์เซอร์')
            L.append('')
            continue
        if not r['complete']:
            L.append('ยังไม่มีผลการรันที่ครบทุกงานและตรงกับไฟล์ scenario ปัจจุบัน')
            L.append('')
        for w in r['wf']:
            L.append('- %s (`%s`): **%s**%s' % (md_text(w['title']), w['id'], w['status_th'], (' - ' + w['detail']) if w['detail'] else ''))
        L.append('')
        for s in r['skeleton_items']:
            L.append('- โครง %s: %s ที่ %s สิ่งที่เกิดจริง: %s หลักฐาน: %s%s' % (s['id'], md_text(s['promised']), md_text(s['where']), md_text(s['observed']), md_text(s['evidence']),
                                                                        '' if s.get('confirmed') else ' (ยังไม่ได้ยืนยัน: %s)' % md_text(s.get('confirm_how', ''))))
        if r['skeleton_items']:
            L.append('')
        for u in r['untested']:
            L.append('- ยังไม่ได้ตรวจ: %s' % md_text(u))
        if r['untested']:
            L.append('')
    return '\n'.join(L).rstrip() + '\n'


def index_page(rows, scenarios):
    L = ['# คู่มือการใช้งานระบบสาน', '']
    L.append('คู่มือนี้สร้างจากการรันขั้นตอนจริงในเบราว์เซอร์ ภาพทุกภาพมาจากหน้าจอของระบบที่ใช้งานได้จริงในขณะรัน สถานะของแต่ละโมดูลอยู่ในตารางด้านล่างและอธิบายที่ [ผลการตรวจระบบ](../audit.th.md)')
    L.append('')
    groups = {}
    for r in rows:
        if r['has_scenario']:
            groups.setdefault(r['area'] or 'อื่น ๆ', []).append(r)
    for area in sorted(groups):
        L.append('## %s' % md_text(area))
        L.append('')
        L.append('| โมดูล | ใช้ทำอะไร | ผลการตรวจ |')
        L.append('|---|---|---|')
        for r in groups[area]:
            purpose = md_text(scenarios[r['id']]['purpose'])
            purpose = purpose[:110] + ('...' if len(purpose) > 110 else '')
            L.append('| [%s](%s.md) | %s | %s |' % (md_text(r['title']), r['id'], purpose, r['verdict']))
        L.append('')
    pending = [r for r in rows if not r['has_scenario']]
    if pending:
        L.append('## โมดูลที่ยังไม่มีคู่มือ')
        L.append('')
        L.append('โมดูลเหล่านี้ยังไม่ได้ตรวจและยังไม่มีขั้นตอนใช้งานจริงในคู่มือ')
        L.append('')
        for r in pending:
            L.append('- %s (%s)' % (md_text(r['title']), r['id']))
        L.append('')
    return '\n'.join(L).rstrip() + '\n'


def main(argv=None):
    a = parse_args(argv)
    lock = ML.RunLock(a.group, a.lock_wait, log=lambda m: print('[..] ' + m))
    if not lock.acquire():
        print('[ERR] gave up after %d s: a run or another build holds the machine-wide lock (%s). Nothing was written.' % (a.lock_wait, lock.holder()))
        return 3
    try:
        return build(a)
    finally:
        lock.release()


def parse_args(argv=None):
    ap = argparse.ArgumentParser(description='Build the Saan manual pages and the audit file.')
    ap.add_argument('--scenarios', nargs='+', default=[ML.DEFAULT_SCENARIOS])
    ap.add_argument('--results', default=os.path.join(ML.DOCS, 'run', 'results.json'))
    ap.add_argument('--out', default=ML.DOCS)
    ap.add_argument('--modules-dir', default=os.path.join(ML.SCHOOL_APP, 'modules'))
    ap.add_argument('--applies-to-file', dest='applies_to_file', default=r'D:\AI-Project\School\install-trials\audit-070\VERSION', help='VERSION file of the install the pictures come from (front matter applies_to of the wiki bundle)')
    ap.add_argument('--accounts', default=None, help='accounts file: when given (or found at the default private path) generated text is scanned for passwords')
    ap.add_argument('--lock-wait', type=int, default=1800, help='seconds to wait for the machine-wide lock shared with run.py (exit code 3 when it gives up)')
    ap.add_argument('--group', default=os.environ.get('SAAN_MANUAL_GROUP', 'build'))
    return ap.parse_args(argv)


def build(a):
    results = ML.load_results(a.results)
    run_dir = os.path.dirname(os.path.abspath(a.results))
    scns, shas = {}, {}
    for f in ML.find_scenario_files(a.scenarios):
        d = ML.load_scenario(f)
        errs = [e for e in ML.validate_scenario(d, f, None) if not e.startswith('[WARN]')]
        if errs:
            print('[ERR] %s invalid: %s' % (os.path.basename(f), errs[0]))
            return 2
        scns[d['module']] = d
        shas[d['module']] = ML.sha256_file(f)
    # every module of the product, with or without a scenario
    meta = {}
    if os.path.isdir(a.modules_dir):
        for m in sorted(os.listdir(a.modules_dir)):
            p = os.path.join(a.modules_dir, m, 'module.json')
            if os.path.exists(p):
                meta[m] = json.load(open(p, encoding='utf-8-sig'))
    ids = sorted(set(meta) | set(scns))
    out_th = os.path.join(a.out, 'th')
    os.makedirs(os.path.join(out_th, 'images'), exist_ok=True)
    rows, pages, images_by_mod, complete_by_mod = [], {}, {}, {}
    for mid in ids:
        title = (scns.get(mid) or {}).get('title') or (meta.get(mid) or {}).get('name') or mid
        if mid not in scns:
            rows.append({'id': mid, 'title': title, 'has_scenario': False, 'verdict': 'ยังไม่ได้ตรวจ', 'area': '', 'counts': {}, 'complete': False, 'wf': [], 'skeleton': 0, 'skeleton_items': [], 'untested': []})
            continue
        scn, sha, mres = scns[mid], shas[mid], results.get('modules', {}).get(mid)
        v, counts, complete = verdict(scn, sha, mres)
        used = []
        pages[mid] = module_page(scn, sha, mres, meta.get(mid), used, scns, meta)
        images_by_mod[mid] = used
        complete_by_mod[mid] = complete
        wfrows = []
        for w in scn['workflows']:
            r = wf_result(scn, sha, mres, w['id'])
            if r is None:
                wfrows.append({'id': w['id'], 'title': w['title'], 'status_th': 'ยังไม่ได้ตรวจ', 'detail': ''})
            else:
                st = {'works': 'ผ่าน', 'fails': 'ไม่ผ่าน', 'blocked': 'ถูกบล็อก'}.get(r['status'], r['status'])
                detail = ''
                if r['status'] != 'works':
                    detail = step_error_plain(r)
                    if r.get('steps'):
                        fi = next((s.get('failure_image') for s in r['steps'] + [x for vv in r.get('verify', []) for x in vv['steps']] if s.get('failure_image')), None)
                        if fi:
                            detail += ' ภาพหน้าจอตอนไม่ผ่าน: `run/%s`' % fi
                else:
                    detail = '%s วินาที' % r.get('seconds')
                wfrows.append({'id': w['id'], 'title': w['title'], 'status_th': st, 'detail': detail})
        rows.append({'id': mid, 'title': title, 'has_scenario': True, 'verdict': v, 'area': scn.get('area', ''), 'counts': counts, 'complete': complete, 'wf': wfrows,
                     'skeleton': len(scn.get('skeleton', [])), 'skeleton_items': scn.get('skeleton', []), 'untested': scn.get('untested', []),
                     'coverage_note': scn.get('coverage_note') if scn.get('coverage') == 'partial' else ''})
    generated = fmt_dt(datetime.datetime.now().isoformat(timespec='seconds'))
    audit = audit_page(rows, generated, results.get('base', ''))
    index = index_page(rows, scns)
    # secrets scan before anything is written
    secrets = []
    acc_path = a.accounts or r'D:\AI-Project\School\.private\saan-audit-accounts.txt'
    if os.path.exists(acc_path):
        for k, v in ML.parse_accounts_by_role(acc_path).items():
            if len(v.get('password') or '') >= 4:
                secrets.append(v['password'])
    for name, text in list(pages.items()) + [('audit', audit), ('index', index)]:
        for s in set(secrets):
            if s in text:
                print('[ERR] a password appears in the generated %s; nothing was written' % name)
                return 1
        if re.search(r'\{\{|\}\}', text):
            print('[ERR] an unexpanded template marker is in the generated %s; nothing was written' % name)
            return 2
    # write
    for mid, text in pages.items():
        open(os.path.join(out_th, mid + '.md'), 'w', encoding='utf-8', newline='\n').write(text)
        dest = os.path.join(out_th, 'images', mid)
        keep = set()
        os.makedirs(dest, exist_ok=True)
        for rel in images_by_mod[mid]:
            src = os.path.join(run_dir, rel.replace('/', os.sep))
            if os.path.exists(src):
                shutil.copy2(src, os.path.join(dest, os.path.basename(rel)))
                keep.add(os.path.basename(rel))
            else:
                print('[WARN] picture missing: %s' % rel)
        if complete_by_mod.get(mid):  # a module whose results are stale keeps its older pictures until it is run again
            for f in os.listdir(dest):
                if f not in keep:
                    os.remove(os.path.join(dest, f))
    # wiki bundle for the project site (saan-site/content/wiki): front matter, flat image names, wiki: links
    wb = os.path.join(a.out, 'wiki-bundle')
    shutil.rmtree(wb, ignore_errors=True)
    os.makedirs(os.path.join(wb, 'manual'))
    os.makedirs(os.path.join(wb, 'images'))
    ver_file = a.applies_to_file if os.path.exists(a.applies_to_file) else os.path.join(ML.SCHOOL_APP, 'VERSION')
    applies = open(ver_file, encoding='utf-8').read().strip()
    today = datetime.date.today().isoformat()
    pages_idx = []
    for mid, text in pages.items():
        body = re.sub(r'\]\(images/%s/([^)]+)\)' % re.escape(mid), lambda m: '](images/%s-%s)' % (mid, m.group(1)), text)
        body = re.sub(r'\]\(([a-z0-9-]+)\.md\)', lambda m: '](wiki:manual/%s)' % m.group(1), body)
        body = re.sub(r' \(ดูความหมายและตารางรวมที่ \[ผลการตรวจระบบ\]\(\.\./audit\.th\.md\)\)', '', body)
        purpose = md_text(scns[mid]['purpose'])
        summ = purpose[:137].rsplit(' ', 1)[0] if len(purpose) > 140 else purpose
        fm = '---\napplies_to: %s\nreviewed: %s\nsummary: %s\n---\n' % (applies, today, summ)
        open(os.path.join(wb, 'manual', mid + '.md'), 'w', encoding='utf-8', newline='\n').write(fm + body)
        for f in os.listdir(os.path.join(out_th, 'images', mid)):
            shutil.copy2(os.path.join(out_th, 'images', mid, f), os.path.join(wb, 'images', '%s-%s' % (mid, f)))
        pages_idx.append(mid)
    json.dump([{'id': 'manual', 'title': 'คู่มือการใช้งานแยกตามโมดูล', 'pages': pages_idx}], open(os.path.join(wb, 'index.snippet.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=4)
    open(os.path.join(out_th, 'index.md'), 'w', encoding='utf-8', newline='\n').write(index)
    open(os.path.join(a.out, 'audit.th.md'), 'w', encoding='utf-8', newline='\n').write(audit)
    tot = {}
    for r in rows:
        tot[r['verdict']] = tot.get(r['verdict'], 0) + 1
    print('[OK] %d module page(s), index, audit written to %s  verdicts: %s' % (len(pages), a.out, ', '.join('%s=%d' % kv for kv in tot.items())))
    return 0


if __name__ == '__main__':
    sys.exit(main())
