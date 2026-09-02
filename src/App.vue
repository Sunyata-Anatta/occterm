<!--
  - OCCTerm for Nextcloud.
  -
  - SPDX-FileCopyrightText: 2026 AllV01d
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcContent app-name="occterm">
		<NcAppContent>
			<div class="occterm" @click="focusInput">
				<pre ref="transcript" class="occterm__transcript">{{ transcript }}</pre>

				<div class="occterm__prompt">
					<label class="occterm__label" for="occterm-input">occ $</label>
					<input
						id="occterm-input"
						ref="input"
						v-model="command"
						class="occterm__input"
						type="text"
						autocomplete="off"
						autocapitalize="off"
						spellcheck="false"
						:disabled="running"
						:placeholder="running ? 'running…' : ''"
						@keydown.enter.prevent="submit"
						@keydown.tab.prevent="complete"
						@keydown.up.prevent="recall(-1)"
						@keydown.down.prevent="recall(1)">
					<NcLoadingIcon v-if="running" :size="20" />
				</div>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

/**
 * Commands that can lock an administrator out of the very interface they are
 * typing into, or that are hard to undo from a browser. Running one asks first.
 */
const CONFIRM_PREFIXES = [
	'maintenance:mode',
	'maintenance:install',
	'maintenance:repair',
	'app:disable',
	'db:convert-type',
	'user:delete',
]

export default {
	name: 'App',

	components: {
		NcAppContent,
		NcContent,
		NcLoadingIcon,
	},

	data() {
		return {
			command: '',
			transcript: '',
			running: false,
			known: [],
			history: [],
			historyIndex: 0,
		}
	},

	async mounted() {
		this.write('Type a command and press Enter. Tab completes, Up and Down recall.')
		this.write('Long running and interactive commands are not supported; see the README.')
		this.write('')
		this.focusInput()

		try {
			const { data } = await axios.get(generateOcsUrl('/apps/occterm/api/v1/commands'), {
				headers: { 'OCS-APIRequest': 'true' },
			})
			this.known = data?.ocs?.data?.commands ?? []
		} catch (error) {
			this.write('Could not load the command list; completion is unavailable.')
			this.write('')
		}
	},

	methods: {
		focusInput() {
			this.$refs.input?.focus()
		},

		write(line) {
			this.transcript += line + '\n'
			this.$nextTick(() => {
				const el = this.$refs.transcript
				if (el) {
					el.scrollTop = el.scrollHeight
				}
			})
		},

		async submit() {
			const command = this.command.trim()
			if (command === '' || this.running) {
				return
			}

			this.history.push(command)
			this.historyIndex = this.history.length
			this.command = ''
			this.write('occ $ ' + command)

			if (command === 'clear') {
				this.transcript = ''
				return
			}

			if (!this.confirmIfRisky(command)) {
				this.write('Cancelled.')
				this.write('')
				return
			}

			this.running = true
			try {
				const { data } = await axios.post(
					generateOcsUrl('/apps/occterm/api/v1/command'),
					{ command },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				this.write(data?.ocs?.data?.output ?? '')
			} catch (error) {
				const message = error?.response?.data?.ocs?.data?.message
					?? error?.message
					?? 'The command could not be run.'
				this.write('error: ' + message)
			} finally {
				this.running = false
				this.write('')
				this.$nextTick(this.focusInput)
			}
		},

		confirmIfRisky(command) {
			const risky = CONFIRM_PREFIXES.some((prefix) => command.startsWith(prefix))
			if (!risky) {
				return true
			}

			return window.confirm(
				'"' + command + '" can lock you out of this interface or is hard to undo '
				+ 'from a browser. Run it anyway?',
			)
		},

		complete() {
			const prefix = this.command.trim()
			if (prefix === '') {
				return
			}

			const matches = this.known.filter((name) => name.startsWith(prefix))
			if (matches.length === 1) {
				this.command = matches[0] + ' '
				return
			}
			if (matches.length > 1) {
				this.write('occ $ ' + prefix)
				this.write(matches.join('  '))
				this.write('')
			}
		},

		recall(direction) {
			if (this.history.length === 0) {
				return
			}

			this.historyIndex = Math.min(
				this.history.length,
				Math.max(0, this.historyIndex + direction),
			)
			this.command = this.history[this.historyIndex] ?? ''
		},
	},
}
</script>

<style scoped lang="scss">
.occterm {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding: 12px;
	box-sizing: border-box;
	font-family: monospace;
	cursor: text;
}

.occterm__transcript {
	flex: 1;
	margin: 0;
	overflow-y: auto;
	white-space: pre-wrap;
	word-break: break-word;
	font-family: inherit;
	font-size: 13px;
	line-height: 1.45;
}

.occterm__prompt {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.occterm__label {
	color: var(--color-primary-element);
	white-space: nowrap;
}

.occterm__input {
	flex: 1;
	border: none;
	background: transparent;
	font-family: inherit;
	font-size: 13px;

	&:focus {
		box-shadow: none;
	}
}
</style>
