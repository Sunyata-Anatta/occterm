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

				<div class="occterm__bottom">
					<p v-if="usage" class="occterm__usage">{{ usage }}</p>

					<ul v-if="candidates.length" class="occterm__candidates">
						<li v-for="(name, index) in candidates" :key="name">
							<button
								class="occterm__candidate"
								:class="{ 'occterm__candidate--on': index === candidateIndex }"
								type="button"
								@click.stop="accept(name)">
								{{ name }}
							</button>
						</li>
					</ul>

					<div class="occterm__prompt">
						<span class="occterm__label">occ $</span>

						<div class="occterm__field">
							<!-- Coloured copy laid under the input. Hidden while an input
							     method is composing, because the pre-edit text lives in the
							     input alone and would otherwise be invisible. -->
							<pre
								v-show="!composing"
								ref="mirror"
								class="occterm__mirror"
								aria-hidden="true"><span
									v-for="(token, index) in tokens"
									:key="index"
									:class="'occterm__tok--' + token.kind">{{ token.text }}</span></pre>

							<input
								id="occterm-input"
								ref="input"
								v-model="command"
								class="occterm__input"
								:class="{ 'occterm__input--visible': composing }"
								type="text"
								autocomplete="off"
								autocapitalize="off"
								autocorrect="off"
								spellcheck="false"
								:disabled="running"
								:placeholder="running ? 'running…' : ''"
								@scroll="syncScroll"
								@compositionstart="composing = true"
								@compositionend="composing = false"
								@keydown.enter.prevent="onEnter"
								@keydown.tab.prevent="onTab"
								@keydown.esc="closeCandidates"
								@keydown.up.prevent="onUp"
								@keydown.down.prevent="onDown">

							<NcLoadingIcon v-if="running" class="occterm__spinner" :size="20" />
						</div>
					</div>
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
import { applyCompletion, suggest, tokenize } from './highlight.js'

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
			composing: false,
			index: { commands: {}, global: [] },
			open: false,
			candidateIndex: -1,
			history: [],
			historyIndex: 0,
		}
	},

	computed: {
		tokens() {
			return tokenize(this.command, this.index)
		},

		suggestion() {
			return suggest(this.command, this.index)
		},

		/** The example for whatever the line has resolved to so far. */
		usage() {
			return this.suggestion.usage
		},

		/**
		 * Everything still reachable from what is typed. Deriving this from the
		 * line rather than freezing it on Tab is what makes the list narrow as
		 * you keep typing.
		 */
		candidates() {
			return this.open ? this.suggestion.candidates : []
		},
	},

	watch: {
		command() {
			// The highlighted copy has to follow the input when it scrolls.
			this.$nextTick(this.syncScroll)
		},

		candidates(list) {
			if (list.length === 0) {
				this.open = false
			}
			if (this.candidateIndex >= list.length) {
				this.candidateIndex = -1
			}
		},
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
			this.index = {
				commands: data?.ocs?.data?.commands ?? {},
				global: data?.ocs?.data?.global ?? [],
			}
		} catch (error) {
			this.write('Could not load the command list. Completion and colouring are off.')
			this.write('')
		}
	},

	methods: {
		focusInput() {
			this.$refs.input?.focus()
		},

		/** Keep the coloured copy lined up when the input scrolls sideways. */
		syncScroll() {
			const { input, mirror } = this.$refs
			if (input && mirror) {
				mirror.scrollLeft = input.scrollLeft
			}
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

		closeCandidates() {
			this.open = false
			this.candidateIndex = -1
		},

		accept(name) {
			const { word } = this.suggestion
			const head = word === '' ? this.command : this.command.slice(0, this.command.length - word.length)
			this.command = head + name + ' '
			this.closeCandidates()
			this.focusInput()
		},

		onTab() {
			// Tab a second time steps through what is on offer.
			if (this.open && this.candidates.length > 1) {
				this.moveCandidate(1)
				return
			}

			const result = applyCompletion(this.command, this.suggestion)
			this.command = result.line
			this.open = result.open
			this.candidateIndex = -1
		},

		moveCandidate(step) {
			const count = this.candidates.length
			if (count === 0) {
				return
			}
			this.candidateIndex = (this.candidateIndex + step + count) % count
		},

		onUp() {
			if (this.candidates.length) {
				this.moveCandidate(-1)
				return
			}
			this.recall(-1)
		},

		onDown() {
			if (this.candidates.length) {
				this.moveCandidate(1)
				return
			}
			this.recall(1)
		},

		onEnter() {
			if (this.candidateIndex >= 0 && this.candidates[this.candidateIndex]) {
				this.accept(this.candidates[this.candidateIndex])
				return
			}
			this.submit()
		},

		async submit() {
			const command = this.command.trim()
			if (command === '' || this.running) {
				return
			}

			this.closeCandidates()
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
/**
 * A terminal keeps its own dark surface rather than following the Nextcloud
 * theme, so that the token colours below hold in both light and dark modes.
 */
.occterm {
	--occterm-bg: #17171b;
	--occterm-dim: #c9d1d9;
	--occterm-plain: #ffffff;
	--occterm-command: #5ccfe6;
	--occterm-option: #ffa657;
	--occterm-unknown: #ff7b72;
	--occterm-prompt: #7ee787;

	// The mirror and the input must agree on every metric or the colours drift
	// away from the characters.
	--occterm-font: ui-monospace, "SF Mono", "Cascadia Mono", "Liberation Mono", monospace;
	--occterm-size: 13px;
	--occterm-line: 20px;

	display: flex;
	flex-direction: column;
	height: 100%;
	padding: 12px;
	box-sizing: border-box;
	background: var(--occterm-bg);
	color: var(--occterm-dim);
	font-family: var(--occterm-font);
	cursor: text;
}

.occterm__transcript {
	flex: 1;
	margin: 0;
	overflow-y: auto;
	white-space: pre-wrap;
	word-break: break-word;
	font-family: inherit;
	font-size: var(--occterm-size);
	line-height: 1.45;
	color: var(--occterm-dim);
}

.occterm__bottom {
	position: relative;
	flex: none;
}

.occterm__candidates {
	position: absolute;
	bottom: 100%;
	left: 0;
	right: 0;
	max-height: 40vh;
	margin: 0 0 6px;
	padding: 4px;
	overflow-y: auto;
	list-style: none;
	background: #22222a;
	border: 1px solid #3a3a44;
	border-radius: 6px;
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
}

.occterm__candidate {
	padding: 2px 8px;
	border: none;
	border-radius: 4px;
	background: transparent;
	color: var(--occterm-command);
	font-family: inherit;
	font-size: var(--occterm-size);
	line-height: var(--occterm-line);
	cursor: pointer;

	&:hover,
	&--on {
		background: #3a3a44;
		color: var(--occterm-plain);
	}
}

.occterm__prompt {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-top: 8px;
	border-top: 1px solid #2c2c34;
}

.occterm__label {
	flex: none;
	color: var(--occterm-prompt);
	font-size: var(--occterm-size);
	line-height: var(--occterm-line);
	white-space: nowrap;
}

.occterm__field {
	position: relative;
	flex: 1;
	display: flex;
	align-items: center;
	min-width: 0;
}

// Every property below is shared by the mirror and the input on purpose.
.occterm__mirror,
.occterm__input {
	width: 100%;
	margin: 0;
	padding: 0;
	border: none;
	background: transparent;
	font-family: var(--occterm-font);
	font-size: var(--occterm-size);
	line-height: var(--occterm-line);
	letter-spacing: normal;
	white-space: pre;
	overflow-x: auto;
	scrollbar-width: none;
}

.occterm__mirror {
	position: absolute;
	inset: 0;
	pointer-events: none;
	color: var(--occterm-plain);

	&::-webkit-scrollbar {
		display: none;
	}
}

.occterm__input {
	position: relative;
	color: transparent;
	caret-color: var(--occterm-plain);
	outline: none;

	&::placeholder {
		color: #6e7681;
	}

	&:focus {
		box-shadow: none;
	}

	// While an input method is composing, the pre-edit text exists only in the
	// input, so it has to be readable there.
	&--visible {
		color: var(--occterm-plain);
	}
}

.occterm__usage {
	margin: 0 0 6px;
	padding: 4px 8px;
	background: #22222a;
	border-radius: 6px;
	color: #8b949e;
	font-family: inherit;
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-word;
}

.occterm__tok--command {
	color: var(--occterm-command);
}

.occterm__tok--option {
	color: var(--occterm-option);
}

.occterm__tok--option-unknown {
	color: var(--occterm-unknown);
	text-decoration: underline wavy;
	text-underline-offset: 3px;
}

.occterm__tok--unknown {
	color: var(--occterm-unknown);
}

.occterm__tok--plain,
.occterm__tok--space {
	color: var(--occterm-plain);
}

.occterm__spinner {
	flex: none;
	margin-left: 8px;
}
</style>
