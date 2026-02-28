/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global customElements */

import { registerSidebarAction } from '@nextcloud/sharing/ui'
import { createApp, h, reactive } from 'vue'
import RawSharingAction from './components/RawSharingAction.vue'

const ELEMENT_NAME = 'oca_files_sharing_raw-sharing_action'

if (typeof customElements !== 'undefined' && !customElements.get(ELEMENT_NAME)) {
	class RawSharingActionElement extends HTMLElement {
		constructor() {
			super()
			this._app = null
			this._vm = null
			this._saveHookInstalled = false
			this._state = reactive({
				node: null,
				share: undefined,
				onSave: undefined,
			})
		}

		set node(v) { this._state.node = v }
		get node() { return this._state.node }

		set share(v) { this._state.share = v }
		get share() { return this._state.share }

		set onSave(v) {
			this._state.onSave = v

			// files_sharing passes a registrar function: onSave((cb) => { ... })
			// Register ONCE so "Update share" triggers our save().
			if (!this._saveHookInstalled && typeof v === 'function') {
				this._saveHookInstalled = true
				try {
					v(async () => await this.save())
				} catch (e) {}
			}
		}
		get onSave() { return this._state.onSave }

		connectedCallback() {
			this._mount()
		}

		disconnectedCallback() {
			try { this._app?.unmount() } catch (e) {}
			this._app = null
			this._vm = null
		}

		_mount() {
			if (this._app) return
			this._app = createApp({
				render: () => h(RawSharingAction, {
					node: this._state.node,
					share: this._state.share,
					onSave: this._state.onSave,
				}),
			})
			this._vm = this._app.mount(this)
		}

		// keep methods if you want; they are not required by files_sharing
		async save() {
			// Called by files_sharing when the user clicks "Update share"
			if (this._vm && typeof this._vm.save === 'function') {
				return await this._vm.save()
			}
			return undefined
		}
	}

	customElements.define(ELEMENT_NAME, RawSharingActionElement)
}

// Prevent double registration if the bundle is loaded twice
if (!window.__filesSharingRawSidebarActionRegistered) {
	window.__filesSharingRawSidebarActionRegistered = true

	// Register the sidebar action (rendered inside "Advanced settings" for share details)
	registerSidebarAction({
		id: 'files_sharing_raw',
		element: ELEMENT_NAME,
		order: 50,

		enabled(share) {
			const token = share?.token ?? share?.shareToken ?? share?.share_token
			if (!token) return false

			const t = share?.shareType ?? share?.share_type ?? share?.type
			if (t == null) return true
			return Number(t) === 3
		},
	})
}
