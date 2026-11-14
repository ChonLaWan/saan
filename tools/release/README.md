# tools/release

Release tooling (not shipped inside any package).

## Signing key

The release key signs every core and module package; each school trusts one public key (`release_public_key` in `config/saan.php`).
Whoever holds the secret key can run code on every school, so it must not sit on a development PC as plain text.

1. Encrypt the key once (passphrase comes from the environment, never from an argument):

   ```
   set SAAN_SIGNING_PASSPHRASE=<at least 12 characters>
   php tools/release/encrypt-key.php <plain-key.json> <saan-release-signing.enc.json>
   ```

   The format is `saan-key-v1`: argon2id (sodium) derives a secretbox key from the passphrase; the file holds only the ciphertext and the public key.
   After the self-check passes, delete the plain file or move it offline.
2. Build with the encrypted file; the same variable (or `SAAN_SIGNING_PASSPHRASE_FILE`, first line of a file) unlocks it:

   ```
   php tools/release/build-release.php <out-dir> <saan-release-signing.enc.json> --vendor-from=<dir with a composer install --no-dev tree>
   ```

   A plain JSON key still works but prints a `[WARN]`.
3. `build-release.php` refuses: a `vendor/` with dev packages (unless `--allow-dev`), a `test_only` key (unless `--allow-test-key`, CI only),
   and any package that `verify-release.php` rejects (the index is deleted). `verify-release.php` without `--public-key` pins the key in `config/saan.php`.

Rotation and revocation are not possible yet: the Updater accepts exactly one public key. Treat the key as long-lived and keep it off shared machines.

## Release procedure (in this order)

Everything below is what the scripts in this folder do; nothing here publishes anything by itself.

1. Choose the version. Write it in `VERSION` (the core package `saan-core-<version>.zip` is named after it). For every module whose files changed,
   raise `version` in `modules/<id>/module.json`; each module with `"package": "separate"` gets its own `saan-mod-<id>-<version>.zip` built from its own
   version on every run, and an installed school refuses a package older than what it has installed. `--min-from=<version>` (optional) records the oldest release
   that may update straight to this one (`requires.from`); a school on an older release is told to install the stepping-stone release first.
2. Add the heading at the top of `CHANGELOG.md`, in the existing style: `## [<version>] — <date>` (the `/whats-new` page shows the section whose heading equals `VERSION`).
3. Check the tree: `php tools/release/scan-secrets.php --fail` (no finding of level high), `composer audit --locked`, and the test suite on MariaDB/MySQL (CI does this per PR).
4. Make a production vendor tree in a staging copy, never in the working tree: copy `composer.json` and `composer.lock` to an empty folder and run
   `composer install --no-dev --optimize-autoloader --working-dir=<staging>`. The build refuses a `vendor/` that contains dev packages.
5. Build: `php tools/release/build-release.php <out-dir> <saan-release-signing.enc.json> --vendor-from=<staging>` (passphrase in `SAAN_SIGNING_PASSPHRASE` or `SAAN_SIGNING_PASSPHRASE_FILE`, see above).
   It writes `saan-core-<version>.zip`, one `saan-mod-<id>-<version>.zip` per separate module and `release-index.json` (file name, size and SHA-256 of each zip, plus the public key).
6. Verify. The build already runs `verify-release.php` and deletes `release-index.json` when it fails. Run it again on the final folder:
   `php tools/release/verify-release.php <out-dir>` (checks the signature against the key pinned in `config/saan.php`, every file size and SHA-256, and that no `.env`, key, database, log, archive or dev package is inside).
7. Try the core package once on a copy of a real install with `php artisan saan:update <zip> --check`, then without `--check` on a staging site, before it goes anywhere.
8. Publish on https://saan.linuxthai.org (the project website, not part of this repository): the zip files and their SHA-256 values for the `/download/` page, the matching source for `/source/`,
   and `/.well-known/security.txt` for the security contact. Schools that switch the update check on read `https://saan.linuxthai.org/releases/feed.json` and `feed.sig`
   (`config/saan.php`, `update_feed`): `feed.json` has the form described in `app/Support/Modules/UpdateFeed.php` and `feed.sig` is the base64 Ed25519 signature of the exact bytes of `feed.json`
   made with the same release key. No script in this folder produces the feed: it is written and signed by hand today.
9. Keep the signing key off shared machines (see Signing key). Do not publish a package that `verify-release.php` rejected or that was built with `--allow-test-key` or `--allow-dev`.
