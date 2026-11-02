# Changelog

This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.3.0

### Changed

- No `max-version` is declared any more, so a Nextcloud upgrade no longer
  disables the app. `DependencyAnalyzer::analyzeServerVersion()` skips the
  upper-bound check entirely when the attribute is absent.

  A force-enable would not have done this. `Updater::doUpgrade()` deletes the
  `app_install_overwrite` system value on every major upgrade, and
  `checkAppsRequirements()` then disables the app with the default
  `$ignoreMax = false`.

### Fixed

- A Nextcloud release that changes the console constructor used to produce an
  uncaught `ArgumentCountError`. Building the console is now caught separately
  from running a command, and reports the server version with a note that the
  app needs updating. The front end shows that message instead of a generic
  line about completion being unavailable.

  This is the other half of dropping `max-version`: giving up the guard rail is
  only reasonable if the fall is survivable.

### Added

- `tools/occterm-update.sh` installs the newest release and does nothing when
  the installed version already matches. It verifies the archive before
  deleting anything, keeps a backup, restores it if the install fails, and
  reads `occ app:list --output=json` so a disabled app is not mistaken for a
  successful install.
- `tools/occterm-update.test.sh`, which covers the archive checks. The first
  version of those checks rejected every valid archive, because `tar | grep -q`
  under `set -o pipefail` reports the pipeline as failed when grep exits early
  and tar takes a SIGPIPE.

## 1.2.0

### Added

- Nextcloud 35 support. The version range is now `34` to `35`.

### Notes

An app whose `max-version` is behind the server is disabled on upgrade, so 1.1.1
disappeared from the navigation on a Nextcloud 35 instance with its files
untouched. Nothing in the app was broken; the range was.

Checking that the range could honestly be widened meant reading what Nextcloud 35
changed underneath:

- `OC\Console\Application::__construct` is unchanged from 34, still eight
  arguments.
- `OCP\IRequest` still declares the same 23 methods, so `ConsoleRequest` needs
  no new ones. A single added method would have made that class abstract and
  fatal at load.
- The server moved from Symfony Console 6.4 to 7.4, which types
  `ConsoleOutputInterface::setErrorOutput()` as `void`. `BufferedConsoleOutput`
  already declared that type for this reason. The suite now runs against both
  majors.
- Nextcloud 35 removed the `oc_webroot`, `oc_requesttoken`, `oc_config` and
  similar front-end aliases. The bundled `@nextcloud` packages read
  `_oc_webroot`, `_nc_auth_requestToken` and `_oc_config`, which are different
  names and still present.

## 1.1.1

### Fixed

- The typed command sat above the `occ $` prompt instead of on the same line.
  Nextcloud styles every text input with a min-height, padding and a border
  through rules more specific than a class, so the input grew taller than the
  coloured layer drawn over it, and the two stopped agreeing on where the text
  goes. The prompt is now one line tall and the input is forced into exactly
  that box.

  This only appeared on a real instance. The render used to check the interface
  had no Nextcloud stylesheet, so it could not show the conflict; it now carries
  a stand-in for those input rules.

## 1.1.0

### Added

- The command line is coloured as you type. Commands, options and values each
  get their own colour, and a name that matches nothing is marked. Marking waits
  until a word can no longer become anything valid, so a half-typed `files:sc`
  stays neutral while `files:sq` is called out at once.
- Tab completes command names and, once a command is recognised, that command's
  options. Both go through one routine, so filtering and narrowing behave the
  same either way. A single match is inserted; several extend to their common
  prefix and open a list that narrows as you keep typing.
- The usage line occ reports for the current command is shown above the prompt,
  as the example of what that command accepts.

### Changed

- `GET /api/v1/commands` now answers with a command index,
  `{commands: {name: {o, u}}, global: []}`, in place of the flat list of names
  it returned in 1.0.0. Options shared by every command are sent once under
  `global` rather than repeated. The endpoint has one consumer, this app's own
  frontend, which ships with it.
- The terminal keeps a fixed dark surface instead of following the Nextcloud
  theme, so the token colours hold in light mode too.
- `webpack.config.js` became `webpack.config.cjs`, because `package.json` now
  declares `"type": "module"` so that Node's test runner can import
  `src/highlight.js` directly.

## 1.0.0

First release. A Nextcloud 34 web terminal for occ commands, written from
scratch.

Nextcloud publishes no API for running console commands, so this app depends on
the server's private namespace. Every such dependency is confined to
`lib/Service/OccRunner.php`. See [docs/architecture.md](docs/architecture.md).
