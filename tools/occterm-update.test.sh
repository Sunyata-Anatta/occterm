#!/usr/bin/env bash
# Exercises the parts of occterm-update.sh that decide whether to delete a
# directory. Those are the only parts that can be run without a Nextcloud.
set -uo pipefail
# pipefail is on here on purpose: it is what made the original verify_archive
# reject every valid archive, and this suite exists to keep that from returning.

APP_ID=occterm
die() { printf 'DIED: %s\n' "$*"; return 1; }

read_version() {
	php -r '
		$f = $argv[1];
		if (!is_file($f)) { exit(1); }
		$x = @simplexml_load_file($f);
		if ($x === false || !isset($x->version)) { exit(1); }
		echo trim((string)$x->version);
	' "$1"
}
verify_archive() {
	local archive="$1" expected="$2" roots tmp found
	local listing
	listing=$(tar tzf "$archive")
	roots=$(awk -F/ 'NF && $1 != "" {print $1}' <<<"$listing" | sort -u)
	[ "$roots" = "$APP_ID" ] || die "archive root is '$roots', expected a single '$APP_ID/'" || return 1
	grep -qx "$APP_ID/appinfo/info.xml" <<<"$listing" || die "no info.xml" || return 1
	tmp=$(mktemp -d)
	tar xzf "$archive" -C "$tmp" "$APP_ID/appinfo/info.xml"
	found=$(read_version "$tmp/$APP_ID/appinfo/info.xml") || { rm -rf "$tmp"; die "unreadable version"; return 1; }
	rm -rf "$tmp"
	[ "$found" = "$expected" ] || die "archive says $found, release tagged $expected" || return 1
	return 0
}

t=$(mktemp -d); trap 'rm -rf "$t"' EXIT
pass=0; fail=0
expect() { # expect <0|1> <label> ...cmd
	local want="$1" label="$2"; shift 2
	local out; out=$("$@" 2>&1); local got=$?
	if [ "$got" -eq "$want" ]; then pass=$((pass+1)); printf 'PASS  %s\n' "$label"
	else fail=$((fail+1)); printf 'FAIL  %s (exit %s, wanted %s)\n      %s\n' "$label" "$got" "$want" "$out"; fi
}

REAL="${1:?usage: occterm-update.test.sh <path to occterm-X.Y.Z.tar.gz>}"
VERSION=$(basename "$REAL" | sed -E "s/^occterm-(.*)\.tar\.gz$/\1/")
expect 0 "a real release archive verifies against its own version" verify_archive "$REAL" "$VERSION"
expect 1 "a version that does not match the tag is refused"        verify_archive "$REAL" 9.9.9

# An archive with no single app root: exactly what a stray tarball looks like.
mkdir -p "$t"/bad1/{a,b} && echo x > "$t"/bad1/a/f && echo x > "$t"/bad1/b/f
tar -C "$t"/bad1 -czf "$t"/bad-multiroot.tar.gz a b
expect 1 "an archive with two top-level folders is refused" verify_archive "$t"/bad-multiroot.tar.gz 1.3.0

# Right root, no info.xml: a directory named occterm that is not the app.
mkdir -p "$t"/bad2/occterm/lib && echo x > "$t"/bad2/occterm/lib/f
tar -C "$t"/bad2 -czf "$t"/bad-noinfo.tar.gz occterm
expect 1 "an archive without appinfo/info.xml is refused" verify_archive "$t"/bad-noinfo.tar.gz 1.3.0

# read_version on things that are not an app manifest
expect 1 "read_version rejects a missing file"  read_version "$t"/does-not-exist.xml
echo 'not xml at all' > "$t"/junk.xml
expect 1 "read_version rejects a non-XML file"  read_version "$t"/junk.xml
printf '<?xml version="1.0"?><info><id>x</id></info>' > "$t"/noversion.xml
expect 1 "read_version rejects XML with no <version>" read_version "$t"/noversion.xml

printf '\n%s passed, %s failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
