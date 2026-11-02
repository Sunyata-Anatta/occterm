#!/usr/bin/env bash
#
# OCCTerm for Nextcloud.
#
# SPDX-FileCopyrightText: 2026 AllV01d
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Installs the newest OCCTerm release from GitHub into a Nextcloud instance.
# Does nothing when the installed version already matches. Keeps a backup and
# puts it back if anything after the swap fails.
#
#   occterm-update            update if there is a newer release
#   occterm-update --dry-run  say what it would do, change nothing
#
# Settings come from the environment, or from /etc/occterm-update.conf if that
# file exists. A private repository needs GITHUB_TOKEN with read access.
#
# Run it from outside the app directory: it replaces that directory wholesale.

set -euo pipefail

CONFIG_FILE="${CONFIG_FILE:-/etc/occterm-update.conf}"
if [ -r "$CONFIG_FILE" ]; then
	# shellcheck source=/dev/null
	. "$CONFIG_FILE"
fi

NEXTCLOUD_PATH="${NEXTCLOUD_PATH:-/var/www/nextcloud}"
WEB_USER="${WEB_USER:-www-data}"
REPO="${REPO:-Sunyata-Anatta/occterm}"
APP_ID="${APP_ID:-occterm}"
BACKUP_DIR="${BACKUP_DIR:-/var/tmp/occterm-backups}"
GITHUB_TOKEN="${GITHUB_TOKEN:-}"

DRY_RUN=0
if [ "${1:-}" = "--dry-run" ]; then
	DRY_RUN=1
fi

say() { printf '%s\n' "$*" >&2; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

APP_DIR="$NEXTCLOUD_PATH/apps/$APP_ID"
OCC="$NEXTCLOUD_PATH/occ"

for cmd in curl tar php; do
	command -v "$cmd" >/dev/null || die "$cmd is required and not on PATH"
done
[ -f "$OCC" ] || die "no occ at $OCC; set NEXTCLOUD_PATH"

# occ has to run as the user that owns the data directory. Skip sudo when we
# already are that user, so the script works in a container without sudo.
run_occ() {
	if [ "$(id -un)" = "$WEB_USER" ]; then
		php "$OCC" "$@"
	else
		command -v sudo >/dev/null || die "need sudo to run occ as $WEB_USER"
		sudo -u "$WEB_USER" php "$OCC" "$@"
	fi
}

# Reads <version> out of an info.xml without needing xmllint.
read_version() {
	php -r '
		$f = $argv[1];
		if (!is_file($f)) { exit(1); }
		$x = @simplexml_load_file($f);
		if ($x === false || !isset($x->version)) { exit(1); }
		echo trim((string)$x->version);
	' "$1"
}

# Refuses an archive that is not the app: the next step deletes a directory.
verify_archive() {
	local archive="$1" expected="$2" listing roots tmp found

	# One listing, held in a variable. Piping tar into `grep -q` looks tidier but
	# grep exits on the first match, tar dies of SIGPIPE, and `pipefail` then
	# calls the whole pipeline a failure, so every valid archive gets rejected.
	listing=$(tar tzf "$archive")
	roots=$(awk -F/ 'NF && $1 != "" {print $1}' <<<"$listing" | sort -u)
	[ "$roots" = "$APP_ID" ] || die "archive root is '$roots', expected a single '$APP_ID/'"
	grep -qx "$APP_ID/appinfo/info.xml" <<<"$listing" \
		|| die "archive has no $APP_ID/appinfo/info.xml"

	tmp=$(mktemp -d)
	tar xzf "$archive" -C "$tmp" "$APP_ID/appinfo/info.xml"
	found=$(read_version "$tmp/$APP_ID/appinfo/info.xml") \
		|| die "cannot read a version out of the archive"
	rm -rf "$tmp"
	[ "$found" = "$expected" ] \
		|| die "archive says version $found but the release is tagged $expected"
}

api() {
	local url="$1"; shift
	if [ -n "$GITHUB_TOKEN" ]; then
		curl -fsSL -H "Authorization: Bearer $GITHUB_TOKEN" \
			-H 'X-GitHub-Api-Version: 2022-11-28' "$@" "$url"
	else
		curl -fsSL -H 'X-GitHub-Api-Version: 2022-11-28' "$@" "$url"
	fi
}

installed=$(read_version "$APP_DIR/appinfo/info.xml" 2>/dev/null || true)
say "installed: ${installed:-not present}"

release=$(api "https://api.github.com/repos/$REPO/releases/latest" \
	-H 'Accept: application/vnd.github+json') \
	|| die "cannot reach the release API for $REPO (private repository without GITHUB_TOKEN?)"

# Declared before the eval that fills them, so a typo in the PHP below shows up
# as an empty value caught by the checks after it rather than as an unbound name.
tag=""; asset_id=""; asset_name=""
eval "$(printf '%s' "$release" | php -r '
	$r = json_decode(stream_get_contents(STDIN), true);
	$id = ""; $name = "";
	foreach ($r["assets"] ?? [] as $a) {
		if (str_ends_with($a["name"] ?? "", ".tar.gz")) { $id = $a["id"]; $name = $a["name"]; break; }
	}
	printf("tag=%s\nasset_id=%s\nasset_name=%s\n",
		escapeshellarg($r["tag_name"] ?? ""), escapeshellarg($id), escapeshellarg($name));
')"

[ -n "$tag" ] || die "the latest release has no tag"
[ -n "$asset_id" ] || die "the latest release ($tag) has no .tar.gz asset"
latest="${tag#v}"
say "latest   : $latest  ($asset_name)"

if [ "$installed" = "$latest" ]; then
	say "up to date, nothing to do"
	exit 0
fi

if [ "$DRY_RUN" -eq 1 ]; then
	say "would install $latest over ${installed:-nothing} in $APP_DIR"
	exit 0
fi

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
archive="$work/$asset_name"

say "downloading $asset_name"
api "https://api.github.com/repos/$REPO/releases/assets/$asset_id" \
	-H 'Accept: application/octet-stream' -o "$archive" || die "download failed"

verify_archive "$archive" "$latest"
say "archive verified"

backup=""
if [ -d "$APP_DIR" ]; then
	[ -f "$APP_DIR/appinfo/info.xml" ] \
		|| die "$APP_DIR exists but has no appinfo/info.xml; refusing to replace it"
	mkdir -p "$BACKUP_DIR"
	backup="$BACKUP_DIR/$APP_ID-${installed:-unknown}-$(date +%Y%m%d%H%M%S)"
	cp -a "$APP_DIR" "$backup"
	say "backed up to $backup"
fi

# From here a failure would leave a half-installed app, so put the old one back.
restore() {
	if [ -n "$backup" ]; then
		say "restoring $backup"
		rm -rf "$APP_DIR"
		cp -a "$backup" "$APP_DIR"
		chown -R "$WEB_USER:$WEB_USER" "$APP_DIR" || true
	fi
	rm -rf "$work"
}
trap restore EXIT

rm -rf "$APP_DIR"
tar xzf "$archive" -C "$NEXTCLOUD_PATH/apps"
chown -R "$WEB_USER:$WEB_USER" "$APP_DIR"

run_occ app:enable "$APP_ID" >/dev/null

# Ask occ in a form that distinguishes enabled from disabled. Grepping the
# human-readable listing matches the app under "Disabled:" just as happily.
state=$(run_occ app:list --output=json 2>/dev/null | php -r '
	$l = json_decode(stream_get_contents(STDIN), true);
	$a = $argv[1];
	if (isset($l["enabled"][$a])) { echo "enabled ", $l["enabled"][$a]; }
	elseif (isset($l["disabled"][$a])) { echo "disabled"; }
	else { echo "missing"; }
' "$APP_ID")
case "$state" in
	enabled*) ;;
	*) die "$APP_ID is $state after install" ;;
esac

trap 'rm -rf "$work"' EXIT
say "installed $latest, $state"
if [ -n "$backup" ]; then
	say "the previous version is still at $backup"
fi
