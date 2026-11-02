# OCCTerm

Run Nextcloud's `occ` maintenance commands from a browser terminal, without shell
access to the server.

![The terminal, with a command being completed](docs/screenshot.png)

## Using it

Type a command and press Enter. The line is coloured as you type, so you can
see what the app understood before you commit to it:

| Colour | Meaning |
|---|---|
| cyan | a command occ recognises |
| orange | an option that command accepts |
| white | an argument or a value |
| red, underlined | a name that matches nothing |

Red only appears once a word can no longer become anything valid, so a
half-typed `files:sc` stays neutral while `files:sq` is marked at once.

**Tab** completes, and works the same way in both halves of the line: command
names while you are typing the first word, that command's options afterwards.
One match is inserted; several extend to their common prefix and open a list
that narrows as you keep typing. Tab again steps through the list, Enter takes
the highlighted entry, Esc closes it.

Once a command is recognised, its usage line is shown above the prompt. That is
occ's own summary, so it is the authoritative list of what the command accepts.

**Up** and **Down** recall earlier commands. `clear` empties the transcript.

Commands that can lock you out of the interface you are typing into, such as
`maintenance:mode`, ask for confirmation first.

## Requirements

- Nextcloud 34 or later (`info.xml` declares `min-version="34"` and no upper bound; see *Will it survive the next Nextcloud release?*)
- PHP 8.1 or newer

## Install

### From a release

The release archive contains a single `occterm/` folder, which is what Nextcloud
expects. Extract it directly into your apps directory, then enable it.

```bash
cd /path/to/nextcloud/apps
curl -L -o occterm.tar.gz \
  https://github.com/Sunyata-Anatta/occterm/releases/download/v1.3.0/occterm-1.3.0.tar.gz
tar xzf occterm.tar.gz && rm occterm.tar.gz

sudo -u www-data php /path/to/nextcloud/occ app:enable occterm
```

Replace `www-data` with whichever user runs your web server. On some setups the
apps directory is `apps-extra/` instead; either works, as long as the folder is
named `occterm`.

If you unpack the archive as root, hand it back to the web server user or
Nextcloud will not be able to read it:

```bash
sudo chown -R www-data:www-data /path/to/nextcloud/apps/occterm
```

### From source

The repository does not carry a built frontend, so a checkout needs one build
step. Node 20, 22 or 24 is required.

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/Sunyata-Anatta/occterm.git
cd occterm
npm ci && npm run build

sudo -u www-data php /path/to/nextcloud/occ app:enable occterm
```

### Confirm it works

```bash
sudo -u www-data php /path/to/nextcloud/occ app:list | grep occterm
```

Then open **OCCTerm** in the administrator navigation and run `status`. If the
page loads but stays empty, the frontend bundle is missing: check that
`js/occterm-main.js` exists, and run `npm ci && npm run build` if it does not.

### Updating

`tools/occterm-update.sh` installs the newest release and does nothing when the
installed version already matches, so it is safe to run on a timer. Put it
somewhere outside the app directory, because it replaces that directory whole:

```bash
sudo install -m 755 occterm/tools/occterm-update.sh /usr/local/sbin/occterm-update
```

Settings come from the environment or from `/etc/occterm-update.conf`:

```bash
# /etc/occterm-update.conf   (chmod 600 if it holds a token)
NEXTCLOUD_PATH=/var/www/nextcloud
WEB_USER=www-data
REPO=Sunyata-Anatta/occterm
GITHUB_TOKEN=            # only for a private repository, read access is enough
```

```bash
occterm-update --dry-run   # say what it would do
occterm-update             # do it
```

It refuses an archive that is not this app before deleting anything, keeps a
copy of the old version under `/var/tmp/occterm-backups`, and puts that copy
back if the install fails. It reads `occ app:list --output=json` afterwards, so
an app that installs but will not enable is reported as a failure rather than a
success.

### Uninstall

```bash
sudo -u www-data php /path/to/nextcloud/occ app:disable occterm
rm -rf /path/to/nextcloud/apps/occterm
```

The app stores nothing: no database tables, no configuration, no app data.
Removing the folder removes it completely.

## Security

This app runs `occ` as the web server user. Anyone who can reach it can run any
`occ` command, including ones that read and modify every user's data. Enabling it
is equivalent to handing out a shell on the server.

Access is restricted to administrators by the framework: no controller method
carries `NoAdminRequired`, so the security middleware rejects everyone else
before any application code runs. That is the only thing standing between a
logged-in user and the command line, so treat administrator accounts on an
instance running this app accordingly. Enable it when you need it, disable it
when you don't.

A short list of commands that can lock you out of the interface you are typing
into, `maintenance:mode` among them, asks for confirmation first. That is a
guard against slips, not a security boundary.

## What it cannot do

PHP gives Nextcloud no native support for asynchronous operations, so a web
terminal cannot run interactive or long-running commands:

- Commands that prompt will hang. There is no way to answer them.
- Long commands hit the PHP execution limit. `occ files:scan` on a large
  instance will fail partway, and a half-finished command can leave real damage.
- There is no streaming output. A command's transcript arrives when it finishes.

For anything in those categories, use `occ` on the command line.
[nextcloud/server#16726](https://github.com/nextcloud/server/issues/16726)
explains why this is unlikely to change.

## Will it survive the next Nextcloud release?

Not on its own, and that is a property of Nextcloud rather than of this code.
There is no public API for running console commands, so the one thing this app
exists to do can only be done through the server's private namespace, which
carries no compatibility guarantee. The constructor it depends on already
changed between Nextcloud 29 and 31.

The design response is containment, not a fix: every private dependency lives in
`lib/Service/OccRunner.php`, so a server-side change breaks one file whose
expected signature is recorded in `tests/stubs/nextcloud.php`.
[docs/architecture.md](docs/architecture.md) has the details.

Nextcloud 35 was the first test of that. The private constructor turned out
unchanged, but the server moved from Symfony Console 6 to 7, which adds a return
type to a method this app implements. Nothing had to be rewritten, and the whole
check was reading two upstream files and running the suite against both Symfony
majors. Expect that much work at every major release, and expect it to be more
than that eventually.

What broke on that upgrade was not the code but the version range. Nextcloud
disables an app whose `max-version` is behind the server, so 1.1.1 vanished from
the navigation with its files untouched.

This app therefore declares no `max-version` at all. Nextcloud skips the upper
bound entirely when the attribute is absent, so an upgrade no longer disables it.
That trades a guard rail for a louder failure, which is only reasonable because
the failure is survivable: starting the console is caught separately from running
a command, so a release that moves the console out from under this app produces a
sentence naming the server version and saying the app needs updating. Disappearing
without a word was the worse of the two behaviours.

## Development

```
composer install     # dev-only, for the tests
composer test        # 9 checks: the runner and the command index
npm ci && npm run build
npm test             # 20 checks: colouring and completion
bash tools/occterm-update.test.sh <path to a release tar.gz>   # 7 checks
```

Neither suite needs PHPUnit, Nextcloud, or a browser. The PHP tests run against
stub classes copied from `nextcloud/server`, checked against `stable34` and
`stable35`, so they check this
app's own logic and its assumptions about the server. They cannot tell you the
app works on a real instance; only installing it can.

Colouring and completion live in `src/highlight.js` as pure functions, away from
the component, which is why they can be tested without a DOM.

The third suite covers the part of the update script that decides whether to
delete a directory. It exists because the first version of that check rejected
every valid archive: `tar | grep -q` plus `set -o pipefail` reports the pipeline
as failed when grep exits early and tar takes a SIGPIPE.

## Changes

[CHANGELOG.md](CHANGELOG.md) records what each release changed.

## Licence

AGPL-3.0-or-later. See [COPYING](COPYING).
