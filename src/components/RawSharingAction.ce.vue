<!--
  - SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="rawAction">
		<NcCheckboxRadioSwitch v-model="enabled">
			{{ t('files_sharing_raw', 'Enable raw link') }}
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script setup>
/* global OC */

import { computed, defineExpose, onMounted, ref, watch } from 'vue'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

const props = defineProps({
	node: { type: Object, required: true },
	share: { type: Object, required: false, default: undefined },
	onSave: { type: Function, required: false, default: undefined },
})

const enabled = ref(false)
const loadedOnce = ref(false)
const saveRegistrar = ref(null)

function dbg(...args) {
	// Enable via DevTools: window.__filesSharingRawDebug = true
	if (window.__filesSharingRawDebug) {
//		console.debug('[files_sharing_raw]', ...args)
		console.log('[files_sharing_raw]', ...args)
	}
}

// robust: share id can be number or string
const shareId = computed(() => {
	let id = props.share?.id ?? props.share?._share?.id
	if (typeof id === 'string' && id.includes(':')) {
		id = id.slice(id.lastIndexOf(':') + 1)
	}
	const n = Number(id)
	return Number.isFinite(n) ? n : 0
})

function reqToken() {
	// Use OC if available; fallback to meta for safety
	return OC?.requestToken || document.querySelector('meta[name="requesttoken"]')?.getAttribute('content') || ''
}

async function loadStateFromBackend() {
	if (!shareId.value || loadedOnce.value) return
	const url = OC.generateUrl('/apps/files_sharing_raw/api/v1/raw-share/' + shareId.value)
	dbg('GET state', { shareId: shareId.value, url })
	const res = await fetch(url, {
		method: 'GET',
		credentials: 'same-origin',
		// fetch will set Content-Type for URLSearchParams automatically
		headers: {
			Accept: 'application/json',
			requesttoken: reqToken(),
		},
	})
	if (!res.ok) return
	const data = await res.json().catch(() => null)
	if (!data) return
	enabled.value = !!data.enabled
	loadedOnce.value = true
}

async function save() {
	if (!shareId.value) {
		showError(t('files_sharing_raw', 'No share id found.'))
		return
	}
	const url = OC.generateUrl('/apps/files_sharing_raw/api/v1/raw-share/' + shareId.value)
	dbg('POST save', { shareId: shareId.value, url, enabled: !!enabled.value })

	const body = new URLSearchParams()
	body.set('enabled', enabled.value ? '1' : '0')

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

		// ALWAYS print server response for 500 debugging
		console.log('[files_sharing_raw] POST failed', {
			status: res.status,
			contentType: ct,
			body: txt.slice(0, 2000),
		})

		// If JSON, try to extract a message for the toast
		let extra = ''
		if (ct.includes('application/json')) {
			try {
				const j = JSON.parse(txt)
				extra = j?.message ? (': ' + String(j.message)) : ''
			} catch (e) {}
		}
		showError(t('files_sharing_raw', 'Failed to save raw setting.') + ' (HTTP ' + res.status + ')')
		return
	}
	dbg('POST ok', { status: res.status })

	// Ensure subsequent open reads the persisted state
	loadedOnce.value = false
}

// Expose to the custom element (sharing-sidebar.js calls this via element.onSave())
defineExpose({ save })

watch(
	() => props.onSave,
	(fn) => {
		// Register our save handler with the files_sharing wrapper
		if (typeof fn !== 'function') return
		if (saveRegistrar.value === fn) return
		saveRegistrar.value = fn
		dbg('onSave registrar set', { fnLen: fn.length, shareId: shareId.value })

		try {
			fn(async () => {
				await save()
			})
		} catch (e) {
			// ignore
		}
	},
	{ immediate: true }
)

onMounted(() => {
	dbg('mounted', { shareId: shareId.value, shareIdRaw: props.share?.id ?? props.share?._share?.id })
	loadStateFromBackend()
})

watch(shareId, () => {
	loadedOnce.value = false
	loadStateFromBackend()
})
</script>

<style scoped>
.rawAction :deep(label) {
	/* Match files_sharing advanced section label normalization */
	padding-inline-start: 0 !important;
	background-color: initial !important;
	border: none !important;
}

.rawAction :deep(.checkbox-radio-switch__content) {
	/* Match surrounding list item height more closely */
	min-height: 44px;
}

.rawAction :deep(.checkbox-radio-switch__icon) {
	transform: scale(1.05);
	transform-origin: left center;
}
</style>
