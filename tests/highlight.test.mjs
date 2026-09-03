/**
 * OCCTerm for Nextcloud.
 *
 * SPDX-FileCopyrightText: 2026 AllV01d
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Run with: npm test
 */
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { applyCompletion, commonPrefix, suggest, tokenize } from '../src/highlight.js'

const INDEX = {
	commands: {
		'files:scan': { o: ['--all', '--path', '--unscanned'], u: 'files:scan [--path PATH] [--all] [--] [<user_id>...]' },
		'files:scan-app-data': { o: ['--path'], u: 'files:scan-app-data [--path PATH]' },
		'files:cleanup': { o: [], u: 'files:cleanup' },
		'user:list': { o: ['--info'], u: 'user:list [--info]' },
		status: { o: [], u: 'status [--output FORMAT]' },
	},
	global: ['--help', '--no-ansi', '--quiet', '--verbose'],
}

const kinds = (line) => tokenize(line, INDEX).filter((t) => t.kind !== 'space').map((t) => t.kind)

test('tokenize preserves every character, so the overlay stays aligned', () => {
	for (const line of ['', 'a', 'files:scan --all', '  spaced   out  ', 'user:list ', 'files:scan --path=/srv/x']) {
		assert.equal(tokenize(line, INDEX).map((t) => t.text).join(''), line)
	}
})

test('a known first word is the command, an unknown one is marked', () => {
	assert.deepEqual(kinds('status'), ['command'])
	assert.equal(tokenize('file:scan', INDEX)[0].kind, 'unknown')
})

test('options get their own kind, values stay plain', () => {
	assert.deepEqual(kinds('files:scan --all somevalue'), ['command', 'option', 'plain'])
})

test('global options count as valid on every command', () => {
	assert.deepEqual(kinds('files:cleanup --quiet'), ['command', 'option'])
})

test('an option the command does not have is marked', () => {
	assert.deepEqual(kinds('user:list --all'), ['command', 'option-unknown'])
})

test('--opt=value is two colours in one word', () => {
	const t = tokenize('files:scan --path=/srv', INDEX).filter((x) => x.kind !== 'space')
	assert.deepEqual(t, [
		{ text: 'files:scan', kind: 'command' },
		{ text: '--path', kind: 'option' },
		{ text: '=/srv', kind: 'plain' },
	])
})

test('short flags are never called wrong, since the index has no shortcuts', () => {
	assert.deepEqual(kinds('files:scan -v'), ['command', 'option'])
})

test('a half-typed word is not called wrong while it can still become right', () => {
	assert.deepEqual(kinds('fil'), ['command'], 'a command prefix should stay neutral')
	assert.deepEqual(kinds('files:scan --a'), ['command', 'option'], 'an option prefix should stay neutral')
})

test('a word that can become nothing is marked immediately', () => {
	assert.deepEqual(kinds('zzz'), ['unknown'])
	assert.deepEqual(kinds('files:scan --zz'), ['command', 'option-unknown'])
})

test('once the word is finished with a space, an exact match is required again', () => {
	assert.deepEqual(kinds('fil '), ['unknown'], 'a prefix is wrong once you move past it')
	assert.deepEqual(kinds('files:scan --a '), ['command', 'option-unknown'])
})

test('commonPrefix', () => {
	assert.equal(commonPrefix(['files:scan', 'files:scan-app-data']), 'files:scan')
	assert.equal(commonPrefix(['files:scan', 'user:list']), '')
	assert.equal(commonPrefix([]), '')
})

test('the same routine narrows commands and options alike', () => {
	assert.deepEqual(suggest('files:', INDEX).candidates,
		['files:cleanup', 'files:scan', 'files:scan-app-data'])
	assert.deepEqual(suggest('files:sc', INDEX).candidates,
		['files:scan', 'files:scan-app-data'])
	assert.deepEqual(suggest('files:scan --', INDEX).candidates,
		['--all', '--help', '--no-ansi', '--path', '--quiet', '--unscanned', '--verbose'])
	assert.deepEqual(suggest('files:scan --u', INDEX).candidates, ['--unscanned'])
})

test('a trailing space opens the whole option pool', () => {
	const s = suggest('user:list ', INDEX)
	assert.equal(s.scope, 'option')
	assert.deepEqual(s.candidates, ['--help', '--info', '--no-ansi', '--quiet', '--verbose'])
})

test('the usage line is the example, shown once a command resolves', () => {
	assert.equal(suggest('files:scan ', INDEX).usage, 'files:scan [--path PATH] [--all] [--] [<user_id>...]')
	assert.equal(suggest('user:l', INDEX).usage, 'user:list [--info]', 'a lone candidate should preview its usage')
	assert.equal(suggest('files:', INDEX).usage, '', 'several candidates, no single example')
})

test('an unrecognised command offers nothing for what follows', () => {
	assert.equal(suggest('nope --', INDEX).scope, 'none')
})

test('Tab completes a lone candidate and adds a space', () => {
	const line = 'files:scan --u'
	assert.deepEqual(applyCompletion(line, suggest(line, INDEX)), { line: 'files:scan --unscanned ', open: false })
})

test('Tab extends to the common prefix and leaves the choice open', () => {
	const line = 'files:s'
	assert.deepEqual(applyCompletion(line, suggest(line, INDEX)), { line: 'files:scan', open: true })
})

test('at the common prefix already, Tab changes nothing and keeps the list up', () => {
	const line = 'files:scan'
	assert.deepEqual(applyCompletion(line, suggest(line, INDEX)), { line, open: true })
})

test('Tab on an empty word inserts the lone option rather than duplicating the line', () => {
	const line = 'user:list --i'
	assert.equal(applyCompletion(line, suggest(line, INDEX)).line, 'user:list --info ')
})

test('no candidates leaves the line untouched', () => {
	const line = 'files:scan --zzz'
	assert.deepEqual(applyCompletion(line, suggest(line, INDEX)), { line, open: false })
})
