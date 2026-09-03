/**
 * OCCTerm for Nextcloud.
 *
 * SPDX-FileCopyrightText: 2026 AllV01d
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Colouring and completion for a command line. Both read the same index that
 * `GET /api/v1/commands` returns:
 *
 *   { commands: { "files:scan": { o: ["--path"], u: "files:scan [--path PATH] …" } },
 *     global:   ["--help", "--quiet", …] }
 */

/** The options valid for a command: its own, plus the ones every command has. */
function poolFor(name, index) {
	const own = index?.commands?.[name]?.o ?? []
	return own.concat(index?.global ?? [])
}

/** The command a line starts with, or '' if the first word is not one. */
function commandOf(line, index) {
	const first = line.trim().split(/\s+/)[0] ?? ''
	return index?.commands?.[first] ? first : ''
}

/**
 * Split a command line into coloured tokens, preserving every character so the
 * result can be laid under a real <input> and stay aligned with it.
 *
 * @param {string} line the raw input
 * @param {object} index the command index
 * @return {{text: string, kind: string}[]}
 */
export function tokenize(line, index = {}) {
	const commands = index?.commands ?? {}
	const tokens = []
	// The word under the caret is half-typed, so judging it by an exact match
	// would flash it as wrong on the way to being right. It is only marked once
	// nothing it could still become remains.
	const typing = line !== '' && !/\s$/.test(line)
	// Whitespace is kept as its own token: dropping it would shift the overlay.
	const parts = line.split(/(\s+)/).filter((part) => part !== '')

	let first = true
	let pool = null

	for (const part of parts) {
		if (/^\s+$/.test(part)) {
			tokens.push({ text: part, kind: 'space' })
			continue
		}

		if (first) {
			first = false
			const known = Object.prototype.hasOwnProperty.call(commands, part)
			pool = known ? poolFor(part, index) : []
			tokens.push({ text: part, kind: known ? 'command' : 'unknown' })
			continue
		}

		if (part.startsWith('-')) {
			// "--path=/srv" is one word but two colours.
			const cut = part.indexOf('=')
			const flag = cut === -1 ? part : part.slice(0, cut)
			// Short flags are not in the index, so they are never called wrong.
			const bad = flag.startsWith('--') && pool !== null && !pool.includes(flag)
			tokens.push({ text: flag, kind: bad ? 'option-unknown' : 'option' })
			if (cut !== -1) {
				tokens.push({ text: part.slice(cut), kind: 'plain' })
			}
			continue
		}

		tokens.push({ text: part, kind: 'plain' })
	}

	if (typing) {
		const last = tokens[tokens.length - 1]
		if (last && last.kind === 'unknown' && Object.keys(commands).some((n) => n.startsWith(last.text))) {
			last.kind = 'command'
		}
		if (last && last.kind === 'option-unknown' && (pool ?? []).some((o) => o.startsWith(last.text))) {
			last.kind = 'option'
		}
	}

	return tokens
}

/**
 * The longest string every candidate starts with.
 *
 * @param {string[]} candidates
 * @return {string}
 */
export function commonPrefix(candidates) {
	if (candidates.length === 0) {
		return ''
	}

	let prefix = candidates[0]
	for (const candidate of candidates.slice(1)) {
		while (!candidate.startsWith(prefix)) {
			prefix = prefix.slice(0, -1)
			if (prefix === '') {
				return ''
			}
		}
	}

	return prefix
}

/**
 * What could come next, given where the caret has got to.
 *
 * One routine serves both halves of the line. Only the pool differs: command
 * names while the first word is being typed, that command's options afterwards.
 * Filtering, narrowing and the example all behave the same either way, so the
 * list shrinks as you type in both cases.
 *
 * @param {string} line the raw input
 * @param {object} index the command index
 * @return {{scope: string, word: string, candidates: string[], usage: string}}
 */
export function suggest(line, index = {}) {
	const commands = index?.commands ?? {}
	const afterFirstWord = /\s/.test(line.trim()) || /\s$/.test(line)
	const command = commandOf(line, index)

	let pool
	let scope
	if (!afterFirstWord) {
		pool = Object.keys(commands)
		scope = 'command'
	} else if (command !== '') {
		pool = poolFor(command, index)
		scope = 'option'
	} else {
		// An unrecognised first word: nothing useful to offer for what follows.
		return { scope: 'none', word: '', candidates: [], usage: '' }
	}

	const word = /\s$/.test(line) ? '' : (line.trim().split(/\s+/).pop() ?? '')
	const candidates = pool.filter((name) => name.startsWith(word)).sort()

	// The example: the resolved command's usage, or a lone command candidate's.
	let usage = command !== '' ? commands[command].u : ''
	if (usage === '' && scope === 'command' && candidates.length === 1) {
		usage = commands[candidates[0]]?.u ?? ''
	}

	return { scope, word, candidates, usage }
}

/**
 * Fold a suggestion back into the line, the way Tab would.
 *
 * @param {string} line the raw input
 * @param {{scope: string, word: string, candidates: string[]}} suggestion
 * @return {{line: string, open: boolean}} the new line, and whether a choice remains
 */
export function applyCompletion(line, suggestion) {
	const { word, candidates } = suggestion
	if (!candidates || candidates.length === 0) {
		return { line, open: false }
	}

	const head = word === '' ? line : line.slice(0, line.length - word.length)

	if (candidates.length === 1) {
		return { line: head + candidates[0] + ' ', open: false }
	}

	const prefix = commonPrefix(candidates)
	if (prefix.length > word.length) {
		return { line: head + prefix, open: true }
	}

	return { line, open: true }
}
