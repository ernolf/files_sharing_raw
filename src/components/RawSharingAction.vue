<!--
  - SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="rawAction">
		<!-- Toggle in Advanced settings panel -->
		<NcCheckboxRadioSwitch v-model="enabled">
			{{ t('files_sharing_raw', 'Enable raw link') }}
		</NcCheckboxRadioSwitch>

		<!-- Raw link entry — visible only when raw is enabled and URL is known -->
		<transition name="raw-link-fade">
			<ul v-if="enabled && rawUrl" class="rawAction__link-list">
				<li class="sharing-entry sharing-entry__link rawAction__link-entry">

					<!-- Avatar: identical style to SharingEntryLink -->
					<NcAvatar
						class="sharing-entry__avatar"
						:is-no-user="true"
						icon-class="avatar-link-share icon-public-white" />

					<div class="sharing-entry__summary">
						<div class="sharing-entry__desc">
							<span class="sharing-entry__title">
								{{ t('files_sharing_raw', 'Raw link') }}
							</span>
						</div>

						<div class="sharing-entry__actions">
							<!-- Copy button -->
							<NcActions ref="copyButton" class="sharing-entry__copy">
								<NcActionButton
									:aria-label="t('files_sharing_raw', 'Copy raw link to clipboard')"
									:title="copySuccess ? t('files_sharing_raw', 'Successfully copied raw link') : undefined"
									:href="rawUrl"
									@click.prevent="copyRawLink">
									<template #icon>
										<NcIconSvgWrapper
											class="sharing-entry__copy-icon"
											:class="{ 'sharing-entry__copy-icon--success': copySuccess }"
											:path="copySuccess ? mdiCheck : mdiContentCopy" />
									</template>
								</NcActionButton>
							</NcActions>

							<!-- Three-dot menu -->
							<NcActions
								menu-align="right"
								:aria-label="t('files_sharing_raw', 'Raw link options')">

								<NcActionButton
									v-if="canEditCsp"
									:close-after-click="true"
									@click.prevent="showCspEditor = !showCspEditor">
									<template #icon>
										<Tune :size="20" />
									</template>
									{{ t('files_sharing_raw', 'Edit CSP') }}
								</NcActionButton>

								<NcActionCheckbox
									:model-value="rawOnly"
									@update:model-value="onRawOnlyChange">
									{{ t('files_sharing_raw', 'Raw only') }}
								</NcActionCheckbox>

								<NcActionSeparator />

								<NcActionButton @click.prevent="disableRaw">
									<template #icon>
										<CloseIcon :size="20" />
									</template>
									{{ t('files_sharing_raw', 'Disable raw link') }}
								</NcActionButton>
							</NcActions>
						</div>
					</div>
				</li>

				<!-- CSP editor panel — inline below the entry -->
				<transition name="raw-link-fade">
					<li v-if="showCspEditor" class="rawAction__csp-editor">
						<label class="rawAction__csp-label" :for="cspInputId">
							{{ t('files_sharing_raw', 'Custom CSP for this link') }}
						</label>

						<!-- Preset selector -->
						<div class="rawAction__csp-preset-row">
							<label class="rawAction__csp-preset-label">
								{{ t('files_sharing_raw', 'Preset') }}
							</label>
							<select
								class="rawAction__csp-preset"
								:value="selectedPresetId"
								@change="onPresetChange">
								<option
									v-for="p in CSP_PRESETS"
									:key="p.id"
									:value="p.id">
									{{ p.label }}
								</option>
							</select>
						</div>

						<div class="rawAction__csp-row">
							<NcTextField
								:id="cspInputId"
								v-model="cspInput"
								:placeholder="t('files_sharing_raw', 'Leave empty for server default')"
								:label="t('files_sharing_raw', 'Custom CSP')"
								:label-visible="false" />
							<NcButton
								:disabled="savingCsp"
								type="primary"
								@click="saveCsp">
								<template #icon>
									<NcLoadingIcon v-if="savingCsp" :size="20" />
									<CheckIcon v-else :size="20" />
								</template>
								{{ t('files_sharing_raw', 'Save') }}
							</NcButton>
						</div>
						<p class="rawAction__csp-hint">
							{{ t('files_sharing_raw', 'Overrides the server-wide CSP for this share only. Leave empty to use the server default.') }}
						</p>
					</li>
				</transition>
			</ul>
		</transition>
	</div>
</template>

<script setup>
/* global OC */

import { computed, defineExpose, onMounted, ref, watch } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { mdiCheck, mdiContentCopy } from '@mdi/js'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CheckIcon from 'vue-material-design-icons/CheckBold.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import Tune from 'vue-material-design-icons/Tune.vue'

const props = defineProps({
	node: { type: Object, required: true },
	share: { type: Object, required: false, default: undefined },
	onSave: { type: Function, required: false, default: undefined },
})

// --- CSP presets (id, label, csp value; null csp = "Custom" sentinel) ---
const CSP_PRESETS = [
	{
		id: 'server_default',
		label: t('files_sharing_raw', 'Server default'),
		csp: '',
	},
	{
		id: 'sandbox',
		label: t('files_sharing_raw', 'Sandbox (strict)'),
		csp: "sandbox; default-src 'none'; form-action 'none'",
	},
	{
		id: 'images',
		label: t('files_sharing_raw', 'Images only'),
		csp: "default-src 'none'; img-src 'self' data: blob:; form-action 'none'",
	},
	{
		id: 'documents',
		label: t('files_sharing_raw', 'Documents (PDF / text)'),
		csp: "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; form-action 'none'",
	},
	{
		id: 'media',
		label: t('files_sharing_raw', 'Audio / Video'),
		csp: "default-src 'none'; media-src 'self' data: blob:; img-src 'self' data:; form-action 'none'",
	},
	{
		id: 'custom',
		label: t('files_sharing_raw', 'Custom'),
		csp: null,
	},
]

// --- state ---
const enabled = ref(false)
const rawOnly = ref(false)
const loadedOnce = ref(false)
const saveRegistrar = ref(null)

const rawUrl = ref('')
const copySuccess = ref(false)
const showCspEditor = ref(false)
const canEditCsp = ref(false)
const cspInput = ref('')
const savingCsp = ref(false)

const cspInputId = computed(() => `rawAction-csp-${shareId.value}`)

// Derive the active preset id from the current cspInput value.
const selectedPresetId = computed(() => {
	if (cspInput.value === '') return 'server_default'
	const match = CSP_PRESETS.find(p => p.csp !== null && p.csp === cspInput.value)
	return match ? match.id : 'custom'
})

// --- helpers ---

function dbg(...args) {
	// Enable via DevTools: window.__filesSharingRawDebug = true
	if (window.__filesSharingRawDebug) {
		console.log('[files_sharing_raw]', ...args)
	}
}

// Robust: share id can be a number or "type:id" string (e.g. "ocinternal:42")
const shareId = computed(() => {
	let id = props.share?.id ?? props.share?._share?.id
	if (typeof id === 'string' && id.includes(':')) {
		id = id.slice(id.lastIndexOf(':') + 1)
	}
	const n = Number(id)
	return Number.isFinite(n) ? n : 0
})

function reqToken() {
	return OC?.requestToken
		|| document.querySelector('meta[name="requesttoken"]')?.getAttribute('content')
		|| ''
}

// Apply a CSP preset: fill the text field, leave it unchanged for "custom".
function onPresetChange(event) {
	const presetId = event.target.value
	const preset = CSP_PRESETS.find(p => p.id === presetId)
	if (!preset || preset.csp === null) return // "custom" — keep existing text
	cspInput.value = preset.csp
}

// Toggle rawOnly and immediately persist.
function onRawOnlyChange(val) {
	rawOnly.value = val
	save()
}

// --- backend calls ---

async function loadStateFromBackend() {
	if (!shareId.value || loadedOnce.value) return
	const url = OC.generateUrl('/apps/files_sharing_raw/api/v1/raw-share/' + shareId.value)
	dbg('GET state', { shareId: shareId.value, url })
	const res = await fetch(url, {
		method: 'GET',
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			requesttoken: reqToken(),
		},
	})
	if (!res.ok) return
	const data = await res.json().catch(() => null)
	if (!data) return

	enabled.value = !!data.enabled
	rawOnly.value = !!data.rawOnly
	canEditCsp.value = !!data.canEditCsp
	// GET already returns rawUrl and csp — no second request needed
	rawUrl.value = data.rawUrl ?? ''
	cspInput.value = data.csp ?? ''
	loadedOnce.value = true
	dbg('state loaded', { enabled: enabled.value, rawOnly: rawOnly.value, rawUrl: rawUrl.value, csp: cspInput.value })
}

async function save() {
	if (!shareId.value) {
		showError(t('files_sharing_raw', 'No share id found.'))
		return
	}
	const url = OC.generateUrl('/apps/files_sharing_raw/api/v1/raw-share/' + shareId.value)
	dbg('POST save', { shareId: shareId.value, enabled: enabled.value, rawOnly: rawOnly.value })

	const body = new URLSearchParams()
	body.set('enabled', enabled.value ? '1' : '0')
	body.set('rawOnly', rawOnly.value ? '1' : '0')

	const res = await fetch(url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			requesttoken: reqToken(),
		},
		body,
	})
	if (!res.ok) {
		const ct = res.headers.get('content-type') || ''
		const txt = await res.text().catch(() => '')
		console.log('[files_sharing_raw] POST failed', {
			status: res.status,
			contentType: ct,
			body: txt.slice(0, 2000),
		})
		showError(t('files_sharing_raw', 'Failed to save raw setting.') + ' (HTTP ' + res.status + ')')
		return
	}

	// POST also returns rawUrl — update directly from response
	const data = await res.json().catch(() => null)
	rawUrl.value = data?.rawUrl ?? (enabled.value ? rawUrl.value : '')
	loadedOnce.value = false
	dbg('POST ok', { rawUrl: rawUrl.value })
}

async function saveCsp() {
	if (!shareId.value) return
	savingCsp.value = true
	const url = OC.generateUrl('/apps/files_sharing_raw/api/v1/raw-share/' + shareId.value)

	const body = new URLSearchParams()
	body.set('enabled', enabled.value ? '1' : '0')
	body.set('rawOnly', rawOnly.value ? '1' : '0')
	body.set('csp', cspInput.value.trim())

	const res = await fetch(url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			requesttoken: reqToken(),
		},
		body,
	})
	savingCsp.value = false
	if (!res.ok) {
		showError(t('files_sharing_raw', 'Failed to save CSP.') + ' (HTTP ' + res.status + ')')
		return
	}
	showSuccess(t('files_sharing_raw', 'CSP saved.'))
	showCspEditor.value = false
	dbg('CSP saved', { csp: cspInput.value })
}

async function copyRawLink() {
	try {
		await navigator.clipboard.writeText(rawUrl.value)
		showSuccess(t('files_sharing_raw', 'Raw link copied'))
	} catch (e) {
		dbg('clipboard API failed, falling back to prompt', e)
		window.prompt(
			t('files_sharing_raw', 'Your browser does not support copying. Please copy the link manually:'),
			rawUrl.value,
		)
	} finally {
		copySuccess.value = true
		setTimeout(() => { copySuccess.value = false }, 4000)
	}
}

function disableRaw() {
	enabled.value = false
	rawUrl.value = ''
	showCspEditor.value = false
	// Persist immediately — user should not need to click "Update share" to disable
	save()
}

// --- lifecycle ---

defineExpose({ save })

watch(
	() => props.onSave,
	(fn) => {
		if (typeof fn !== 'function') return
		if (saveRegistrar.value === fn) return
		saveRegistrar.value = fn
		dbg('onSave registrar set', { shareId: shareId.value })
		try {
			fn(async () => { await save() })
		} catch (e) {}
	},
	{ immediate: true },
)

onMounted(() => {
	dbg('mounted', { shareId: shareId.value })
	loadStateFromBackend()
})

watch(shareId, () => {
	loadedOnce.value = false
	rawUrl.value = ''
	rawOnly.value = false
	loadStateFromBackend()
})

// When toggle is switched on interactively and rawUrl is still empty
// (e.g. after a previous save reset loadedOnce), re-fetch from backend.
watch(enabled, (val) => {
	if (val && !rawUrl.value) {
		loadedOnce.value = false
		loadStateFromBackend()
	}
})
</script>

<style scoped>
/* --- Toggle label normalization (unchanged from original) --- */
.rawAction :deep(label) {
	padding-inline-start: 0 !important;
	background-color: initial !important;
	border: none !important;
}

.rawAction :deep(.checkbox-radio-switch__content) {
	min-height: 44px;
}

.rawAction :deep(.checkbox-radio-switch__icon) {
	transform: scale(1.05);
	transform-origin: left center;
}

/* --- Raw link list (resets browser ul defaults) --- */
.rawAction__link-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

/* --- Link entry: mirrors .sharing-entry__link layout from SharingEntryLink --- */
.rawAction__link-entry {
	display: flex;
	align-items: center;
	min-height: 44px;
	margin-block-start: 2px;
}

.rawAction__link-entry .sharing-entry__summary {
	padding: 8px;
	padding-inline-start: 10px;
	display: flex;
	justify-content: space-between;
	flex: 1 0;
	min-width: 0;
}

.rawAction__link-entry .sharing-entry__desc {
	display: flex;
	flex-direction: column;
	line-height: 1.2em;
}

.rawAction__link-entry .sharing-entry__title {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.rawAction__link-entry .sharing-entry__actions {
	display: flex;
	align-items: center;
	margin-inline-start: auto;
}

/* Blue avatar background — matches files_sharing link share style */
.rawAction__link-entry :deep(.avatar-link-share) {
	background-color: var(--color-primary-element);
}

.rawAction__link-entry .sharing-entry__copy-icon--success {
	color: var(--color-success);
}


/* --- CSP editor panel --- */
.rawAction__csp-editor {
	/* Indent to align with the title text (avatar ~44px + 10px padding) */
	padding: 8px 8px 8px 54px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.rawAction__csp-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

/* --- Preset selector row --- */
.rawAction__csp-preset-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.rawAction__csp-preset-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	/* Override the global label reset above for this specific label */
	padding-inline-start: 0 !important;
}

.rawAction__csp-preset {
	flex: 1 1 auto;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 3px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9em;
	cursor: pointer;
	min-width: 0;
}

.rawAction__csp-preset:focus {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -1px;
}

/* --- CSP text field + save button row --- */
.rawAction__csp-row {
	display: flex;
	gap: 6px;
	align-items: center;
}

.rawAction__csp-row :deep(.input-field) {
	flex: 1 1 auto;
	min-width: 0;
}

.rawAction__csp-hint {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

/* --- Fade transition for link entry and CSP panel --- */
.raw-link-fade-enter-active,
.raw-link-fade-leave-active {
	transition: opacity 0.2s ease;
}

.raw-link-fade-enter-from,
.raw-link-fade-leave-to {
	opacity: 0;
}
</style>
