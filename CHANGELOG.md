# Changelog

This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
