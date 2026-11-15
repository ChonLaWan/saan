# Joins the output folders of several writers (each ran the scenarios on their own install with their own --out) into one run
# folder that build.py can read: results.json with every module, images/<module>/ copied.
# A module that appears in more than one folder is taken from the folder whose result is newest; a module is only taken when its
# recorded scenario_sha256 still equals the scenario file on disk (a result of an older file is stale and is reported, not merged).
#
#   python -I tests\browser\manual\merge_runs.py --out docs\manual\run-final docs\manual\run-m1 docs\manual\run-m2 ...
#
# Exit code: 0 merged, 1 something was stale or missing (still writes what is valid), 2 bad arguments.
import argparse
import hashlib
import json
import os
import shutil
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import manuallib as ML  # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')
ap = argparse.ArgumentParser()
ap.add_argument('runs', nargs='+', help='run folders to join')
ap.add_argument('--out', required=True, help='joined run folder (emptied first)')
ap.add_argument('--scenarios', default=ML.DEFAULT_SCENARIOS, help='scenario folder the results must match')
ap.add_argument('--base', default='http://localhost/saan-manual', help='address written into the joined results (the installs differ only by name)')
a = ap.parse_args()

picked, problems = {}, []
for run in a.runs:
    path = os.path.join(run, 'results.json')
    if not os.path.isfile(path):
        problems.append('no results.json in ' + run)
        continue
    with open(path, encoding='utf-8') as f:
        res = json.load(f)
    for mid, m in (res.get('modules') or {}).items():
        if mid not in picked or str(m.get('updated', '')) > str(picked[mid][1].get('updated', '')):
            picked[mid] = (run, m)

modules, counts = {}, {}
for mid in sorted(picked):
    run, m = picked[mid]
    scn = os.path.join(a.scenarios, mid + '.json')
    if not os.path.isfile(scn):
        problems.append('%s: no scenario file' % mid)
        continue
    with open(scn, 'rb') as f:
        sha = hashlib.sha256(f.read()).hexdigest()
    if m.get('scenario_sha256') != sha:
        problems.append('%s: result in %s is from an older scenario file (rerun it)' % (mid, run))
        continue
    with open(scn, encoding='utf-8') as f:
        if not ML.module_complete(json.load(f), sha, m):
            problems.append('%s: result in %s does not cover every workflow of the scenario (run the whole unit)' % (mid, run))
            continue
    wf = m.get('workflows') or {}
    items = list(wf.values()) if isinstance(wf, dict) else list(wf)
    c = {}
    for w in items:
        c[w.get('status')] = c.get(w.get('status'), 0) + 1
    counts[mid] = c
    modules[mid] = m

have = set(modules)
for name in sorted(os.listdir(a.scenarios)):
    if name.endswith('.json') and name[:-5] not in have:
        problems.append('%s: no usable result in any run folder' % name[:-5])

if os.path.isdir(a.out):
    shutil.rmtree(a.out)
os.makedirs(os.path.join(a.out, 'images'))
images = 0
for mid in modules:
    src = os.path.join(picked[mid][0], 'images', mid)
    if os.path.isdir(src):
        shutil.copytree(src, os.path.join(a.out, 'images', mid))
        images += len(os.listdir(src))
ML.save_results(os.path.join(a.out, 'results.json'), {'schema': 1, 'base': a.base, 'modules': modules})

total = {}
for mid, c in counts.items():
    for k, v in c.items():
        total[k] = total.get(k, 0) + v
    print('%-24s %-16s %s' % (mid, os.path.basename(picked[mid][0]), c))
print('joined %d modules, %d pictures, totals %s -> %s' % (len(modules), images, total, a.out))
for p in problems:
    print('[WARN] ' + p)
sys.exit(1 if problems else 0)
