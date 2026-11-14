# complaints (F1: ITA O13 / O14)

Public complaint form, tracking page and the staff case desk. Edition `both`: the form and the status page run on the public edition, everything else on the internal one.

## Flow
1. `/portal/complaints` (public): category (general, corruption, suggestion, info_request), optional anonymous mode, contact fields when not anonymous, subject, detail, up to 5 attachments (same limits and checks as the inbox). Anti-bot: honeypot `website`, per /64 (5 per 10 min) and /48 (30 per hour) limits that count every try, optional Turnstile.
2. The public side makes the receipt number (`C<BE yy>-<8 chars>`) and a 12 character tracking code (alphabet without 0/O/1/I), shows them once in the response and seals them inside the envelope (`Inbox` kind `complaint`). It keeps no row, no flash, no log. A failed validation does not flash input into the session (`Validator::make`, not `$request->validate()`).
3. The internal edition pulls the envelope, `InboxHandler` creates `cmp_cases` (idempotent on the envelope nonce, unique receipt number, ceilings per minute / per day / attachment MB per day that no payload field can change, `saan.inbox.complaint_*`). Only `HMAC(receipt|code)` keyed with `HashKey::publicUnlock()` is stored.
4. Status rows reach the public side only through the derived projection `pub_complaint_status` (receipt, hash, category label, status, received/status date, public reply). `cmp_*` tables are forbidden for export (`Projection::FORBIDDEN`). `/portal/complaints/status` takes receipt + code by POST, counts every try per /64 and /48, compares with `hash_equals` against a dummy hash when the receipt does not exist, and answers "not found" identically for unknown receipt, wrong code and malformed input.

## Access (`Access.php`)
- Officers (`complaints.officer_user_ids`, empty list = admin and principal) work all categories except corruption.
- Executives (`complaints.executive_user_ids`) work corruption cases only; with an empty list nobody sees corruption cases. An admin who is not listed does not see them.
- Accused (`cmp_accused`, per case): never sees, finds, exports or is assigned that case, whatever role or list entry the account holds. One query scope (`Access::scope`) serves every read; a hidden case answers 404 like a missing one. Naming oneself as accused is refused. The audit trail records nothing about accused accounts.

## Annual report
Thai fiscal year (1 Oct to 30 Sep, Buddhist era labels). Counts by category and status and average handling days of closed cases; counts every case (a statistic, not a case view); CSV with BOM. Publishing freezes a counts-only snapshot (`cmp_reports`), projected as `pub_complaint_reports` after being rebuilt from whitelisted numbers. web-ita topics with code O13 / O14 (or items whose title starts with it) get automatic links to the form, the status page and the latest published report (`ItaLinks`), nothing school specific is hard-coded.

## Notes
- Tracking hash and key rotation: rotating the unlock key (`saan:keys-rotate`) makes tracking codes issued before the rotation stop matching on the public side (they are keyed with the unlock key). Rotate only when needed and tell the school. Recovery: staff can issue a new tracking code for a case (POST /api/complaints/cases/{id}/track-code, button on the case page); the old code stops working at once and the new one is shown once to the staff member, who passes it on to a complainant who left contact details. An anonymous complainant has no channel, so tell them on the public pages to submit again.
- Turnstile secret is read from `.env` of the edition that serves the form; it is never stored in the database or projected.
- `cms_messages` (contact form) is NOT migrated to this module yet; it keeps its own inbox kind `contact`. A later task can import old messages as `general` cases.
- Case creation by staff (a complaint received by phone or on paper) is not part of this version.

- Audit: every write of this module puts ONE neutral action (complaints.case_updated, empty detail) in audit_events; the step by step story is in cmp_actions behind Access. The generic audit feeds (dashboard feed and /api/site/audit) never list cmp_* entities or complaints.* actions (App\Support\Saan\AuditFeed). Opening a case (once per user and case per hour) and exporting the list are logged.
- Status codes: the public form can carry a nonce; receipt and code are then derived from nonce + text, so a double click or resend is the same case. A refused complaint kept in the inbox quarantine is redacted (no tracking code; words and contact sealed with Crypt).
