# Browser layout audit

PHPUnit never opens a browser. It cannot see text printed over other text, a table that pushes a page sideways, or a request that
fails only inside a page's JavaScript. `layout_audit.py` opens a running install in a real Chrome (Playwright), at four widths
(1440, 1024, 768, 390), and reports what a person would see.

`tests/` is not part of release packages: nothing here ships.

## What it checks

| Finding    | Meaning |
|------------|---------|
| `spill`    | a line of text is wider than the box that holds it and the excess is visible |
| `overlap`  | two visible texts are drawn on top of each other |
| `clipped`  | text is cut by `overflow:hidden` (a box that scrolls inside itself is fine) |
| `hscroll`  | the page scrolls sideways (the widest elements are named) |
| `http`     | a same-origin request was answered with status 400 or higher (422 = validation answer, not counted; a GET that answers 5xx is asked once more and counts only if it fails again) |
| `js-error` | an uncaught JavaScript error |

Rules that matter when you read a result:

- Text inside a scroll box (a list with `overflow:auto`) that is currently scrolled out of view is not visible, so it is never reported.
- An element hides the text under it only when its own background is opaque (alpha >= 0.95). A gradient counts only when no stop is
  transparent; a `url()` image cannot be inspected and counts as opaque. Anything translucent (glass, a fading sticky footer) does
  not hide text, so text under it is reported.
- When a modal `<dialog>` is open only its content is judged.
- Each line of a wrapped paragraph is measured on its own, so inline code that wraps is not reported against the text around it.

## What it visits

- Staff: every link of the staff navigation (`/work#...`, `/cms#...`, `/operations#...`, `/admin/...`, `/account`, `/whats-new`).
  After the first look at a page it drives the page a little and measures again: the first real option of up to five `<select>`s
  that have a placeholder; each tab of the page's own toolbar (`role=tab`, `data-tab`, `data-device`; never navigation, never anything
  whose label means save, delete, send, approve, reset or sign out); the first "add" and the first "edit" form, which is then closed.
- Public: the school's home page and the fixed public routes (news, albums, downloads, events, jobs, procurement, pages, newsletters,
  videos, people, services, groups, directory, ITA, contact, complaint form and status, certificate check, search, sign-in pages,
  `/manual/`), the first detail page of every public list (news article, album, download, ...), and an open survey when the site links one.
  A route that answers 4xx on this install is skipped with a `[SKIP]` line (the module is off or empty).
- Student and guardian portals: signs in with the accounts file and visits every tab, and the day and month buttons of a tab.

Every write request (POST, PUT, PATCH, DELETE) after sign-in is aborted by the tool, so driving a page cannot change data in the
install. The count is printed as `blocked-writes`.

## Run it

Requirements: Python 3 with `playwright` and Google Chrome (or `--channel msedge`, or `--channel bundled` for Playwright's own Chromium).

```
python -I tests/browser/layout_audit.py --base http://localhost/saan --accounts /path/to/accounts.txt --out ./layout-audit-out
```

Arguments (each has an environment variable):

| Argument      | Environment            | Meaning |
|---------------|------------------------|---------|
| `--base`      | `SAAN_AUDIT_BASE`      | base URL of the install, including a sub-folder if there is one (required) |
| `--accounts`  | `SAAN_AUDIT_ACCOUNTS`  | accounts file, see below; without it only the public pages are checked |
| `--out`       | `SAAN_AUDIT_OUT`       | output folder (default `./layout-audit-out`) |
| `--only TEXT` |                        | only pages whose path contains TEXT; repeat for several, e.g. `--only /work#behavior --only /manual/` |
| `--widths`    | `SAAN_AUDIT_WIDTHS`    | default `1440,1024,768,390` |
| `--channel`   | `SAAN_AUDIT_CHANNEL`   | `chrome` (default), `msedge`, `bundled` |
| `--jobs N`    | `SAAN_AUDIT_JOBS`      | N parallel browser processes (default 1; 4 is a good choice, a full run is long) |
| `--no-states` |                        | first state of every page only |

Output folder: `findings.txt` (readable list, empty when clean), `report.json` (every check, with counts), one screenshot per
page/width/state that has a finding (framed at the first finding). With `--jobs`, screenshots are in `shard-N/`.

Exit code: 0 = zero findings, 1 = at least one finding or a page that could not be checked, 2 = the Playwright package is missing.
There is no baseline file on purpose: the target is zero findings.

## Accounts file

Either the text file the trial installer writes (`saan:demo install` / the "full trial" installer choice, lines `เข้าที่:`, `อีเมล:` or
`ชื่อเข้าระบบ:`, `รหัสผ่าน:` per account; the first administrator, student and guardian entries are used), or JSON:

```
{"staff": {"login": "admin@example.test", "password": "..."},
 "student": {"login": "69001", "password": "..."},
 "guardian": {"login": "parent@example.test", "password": "..."}}
```

The staff account should be an administrator so that every page is shown. Passwords are only read from the file; they are never
printed or written to the output. Keep the file out of the repository.

Point it at a trial install, never at a school's real data: it opens every page of every module.

## Reading a finding

```
## /work#documents @ 390px [first]
  spill by 96px: 'ซักซ้อมแนวทาง...' in div.doc-table-wrap > table.doc-table > tbody > tr > td
```

The first line is page, width and state (`first`, `select <name>=<option>`, `tab <label>`, `add form ...`, `edit form ...`). The path is the
box that holds the text. Fix the cause in CSS (let the text wrap with `overflow-wrap:anywhere`, give a grid column `minmax(0,1fr)`, make a
sticky bar opaque), not with `overflow:hidden` or a smaller font. Then copy the file into the install and run only that page:

```
python -I tests/browser/layout_audit.py --base ... --accounts ... --only /work#documents
```
