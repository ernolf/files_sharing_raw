/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/sharing/ui', () => ({
	registerSidebarAction: vi.fn(),
}))
vi.mock('./components/RawSharingAction.vue', () => ({
	default: { name: 'RawSharingAction', render: () => null },
}))

import { registerSidebarAction } from '@nextcloud/sharing/ui'

const ELEMENT_NAME = 'oca_files_sharing_raw-sharing_action'

describe('sharing-sidebar', () => {
	beforeAll(async () => {
		await import('./sharing-sidebar.js')
	})

	it('registers the sidebar action once', () => {
		expect(registerSidebarAction).toHaveBeenCalledTimes(1)
		const action = registerSidebarAction.mock.calls[0][0]
		expect(action.id).toBe('files_sharing_raw')
		expect(action.element).toBe(ELEMENT_NAME)
	})

	it('defines the custom element', () => {
		expect(customElements.get(ELEMENT_NAME)).toBeDefined()
	})

	it('does not register twice when the bundle is loaded again', async () => {
		vi.resetModules()
		await import('./sharing-sidebar.js')
		expect(registerSidebarAction).toHaveBeenCalledTimes(1)
	})

	describe('enabled()', () => {
		const enabled = (share) => registerSidebarAction.mock.calls[0][0].enabled(share)

		it('is disabled without a token', () => {
			expect(enabled(undefined)).toBe(false)
			expect(enabled({})).toBe(false)
			expect(enabled({ shareType: 3 })).toBe(false)
		})

		it('accepts every known token field variant', () => {
			expect(enabled({ token: 'aBc123' })).toBe(true)
			expect(enabled({ shareToken: 'aBc123' })).toBe(true)
			expect(enabled({ share_token: 'aBc123' })).toBe(true)
		})

		it('is enabled when the share type is unknown', () => {
			expect(enabled({ token: 'aBc123' })).toBe(true)
		})

		it('is enabled only for link shares when the type is known', () => {
			expect(enabled({ token: 'aBc123', shareType: 3 })).toBe(true)
			expect(enabled({ token: 'aBc123', shareType: '3' })).toBe(true)
			expect(enabled({ token: 'aBc123', shareType: 0 })).toBe(false)
			expect(enabled({ token: 'aBc123', share_type: 4 })).toBe(false)
		})
	})
})
