# Upgrade notes: symfony/html-sanitizer 7.4 -> 8.1

Date: 2026-11-14. Branch: `upgrade/symfony-html-sanitizer-8`. One major package, nothing else in this change.

## Versions

| Package | Before | After |
|---|---|---|
| symfony/html-sanitizer | v7.4.23 (`^7.4`) | v8.1.11 (`^8.1`) |
| masterminds/html5 | 2.12.0 | removed (only the old sanitizer needed it) |

No other package moved (`composer update symfony/html-sanitizer -W`: 1 update, 1 removal). The rest of Symfony in the lock
file was already on 8.1.

## Requirements

- 8.1 needs PHP >= 8.4.1 and ext-dom. Saan already requires PHP ^8.4.1, so no supported server is cut off.
- 8.x parses with PHP's own HTML5 parser (`Dom\HTMLDocument`). On PHP 8.4 version 7.4 already used the same parser, so the
  parser that schools run does not change with this upgrade.

## Breaking items in 8.0 and whether they touch Saan

| Item (Symfony UPGRADE-8.0, HtmlSanitizer) | In Saan |
|---|---|
| `MastermindsParser` removed, use `NativeParser` | not used anywhere |
| `ParserInterface::parse()` got a `$context` argument | no own parser |

The only code that uses the component is `app/Domain/Cms/SafeContent.php` (`HtmlSanitizer`, `HtmlSanitizerConfig`:
`withMaxInputLength`, `allowLinkSchemes`, `allowRelativeLinks`, `forceAttribute`, `allowElement`, `dropElement`). None of
these changed. No application code was edited.

## Behaviour check, old against new

64 inputs (allowed markup, tables, every link scheme the config refuses, script/style/iframe/object/img/svg/form, event
attributes, comments, CDATA, broken markup, entities, Thai text, invalid UTF-8, the size limit) were run through
`SafeContent::html()` on 7.4.23 and on 8.1.11 with PHP 8.4.27: 0 of 64 outputs differ.

The security-relevant part of that list is now a permanent test, `tests/Unit/SafeContentTest.php` (exact output per input,
second pass changes nothing, size limit, old HTML5 library absent). A later version that changes what gets through fails
there.

Behaviour that was already there and stays (not caused by the upgrade): a protocol-relative link (`//host/path`) is kept
because relative links are allowed; on an HTTPS site it resolves to an https link, which the policy allows anyway.

## Database

No migration. Stored content is not rewritten; it is sanitized when saved, as before.

## Roll back

Code only: check out the previous commit's `composer.json` and `composer.lock`, `composer install`. For an installed
school: the previous release package through the updater's rollback.
