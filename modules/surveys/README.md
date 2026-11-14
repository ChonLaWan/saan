# surveys (M19 + G7)

No-login public surveys with one response per person and verifiable certificates.

- Public: `/portal/surveys/{slug}`, `/portal/certificates/{no}?k=` (printable A4), `/portal/certificates/verify?no=`.
- Admin (admin, principal, editor): CMS page `#surveys`; API under `/api/surveys/*`.
- Identity modes
  - `name_email`: the response counts only after the respondent opens the signed link mailed to them (valid 24 h) and presses the confirm button. The GET only shows the button (mail scanners prefetch links); the POST counts the response and is idempotent: opening the link again shows the same result and certificate. The e-mail is never stored, only a per-survey HMAC. Mail ceilings: 3 per address per 10 min, 20 per network per hour, 300 per survey per minute.
  - `roster_code`: closed list pasted by the admin as `code,name`; each code works once. Only HMAC hashes of codes are stored. Re-submitting a used code shows the holder their existing certificate. Wrong codes are counted per browser session (15 per 10 min) with a higher per-network ceiling (200 per 10 min), so one typo does not lock a computer lab.
  - `anonymous`: signed (encrypted) cookie plus a per-IP hourly budget, set per survey (`anon_ip_hour`, default 300). This mode cannot guarantee uniqueness: a person who clears cookies or changes network can answer again. Certificates are not available in this mode.
- CGNAT note: Thai mobile carriers (AIS/True/DTAC) and school networks put many people behind one public IP, so IP budgets must stay generous. Behind Cloudflare, configure TrustedProxies so `request()->ip()` is the visitor address; otherwise everybody shares one IP and the budgets apply to the whole site.
- Certificate number: `<school short code or school id>-<BE year>-<6-digit sequence>`, taken from `srv_counters` inside the response transaction (row lock until commit) and protected by a unique index. Short code comes from setting `school.short_code`.
- Certificates are snapshotted when issued (`srv_responses.certificate_snapshot`: title, rendered body, signer, survey and school name, date). Editing or disabling the certificate later never changes certificates already handed out.
- Numbers are sequential, so the full certificate page (with the name, often a minor) needs the `k` token (HMAC of the number with the app key) carried by the recipient's link. Without `k` it is a 404. The verify page without `k` says valid/invalid with survey, date and a partially hidden name; with a matching `k` (link printed on the certificate) it shows the full name. Both pages are throttled.
- Responses are never cascaded away: deleting a survey that has responses is refused and the FK is `restrict`. Structure and identity mode are frozen while responses exist or an unexpired verification link is pending.

## S5 inbox (public edition)
In the public edition the survey form seals the answer for the internal edition (`Inbox` kind `survey`); the verification e-mail is sent by the internal edition after it pulled the envelope, and the link opens on the internal host (`/survey-confirm/{slug}/{pending}`). The public edition needs no SMTP settings.
