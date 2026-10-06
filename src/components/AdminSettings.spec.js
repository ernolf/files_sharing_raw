/**
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSettings from './AdminSettings.vue'

vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: { name: 'NcSelect', props: ['modelValue', 'options'], emits: ['update:modelValue'], template: '<div />' },
}))
vi.mock('@nextcloud/vue/components/NcSettingsSection', () => ({
	default: { name: 'NcSettingsSection', template: '<div><slot /></div>' },
}))

const groups = [
	{ id: 'admin', label: 'Admins' },
	{ id: 'csp', label: 'CSP editors' },
]

function mountWith(current) {
	const state = { all_groups: groups, csp_editor_group: current }
	loadState.mockImplementation((app, key, def) => (key in state ? state[key] : def))
	return mount(AdminSettings)
}

const select = (wrapper) => wrapper.findComponent({ name: 'NcSelect' })

beforeEach(() => {
	vi.clearAllMocks()
})

describe('AdminSettings', () => {
	it('selects the configured group', () => {
		const wrapper = mountWith('csp')
		expect(select(wrapper).props('modelValue')).toEqual(groups[1])
		expect(select(wrapper).props('options')).toEqual(groups)
	})

	it('shows a configured group that no longer exists by its id', () => {
		const wrapper = mountWith('gone')
		expect(select(wrapper).props('modelValue')).toEqual({ id: 'gone', label: 'gone' })
	})

	it('saves a newly selected group', async () => {
		axios.post.mockResolvedValue({ data: { group: 'csp' } })
		const wrapper = mountWith('admin')
		select(wrapper).vm.$emit('update:modelValue', groups[1])
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith('/apps/files_sharing_raw/api/v1/admin/csp-editor-group', { group: 'csp' })
		expect(select(wrapper).props('modelValue')).toEqual(groups[1])
	})

	it('does not save when the same group is selected again', async () => {
		const wrapper = mountWith('admin')
		select(wrapper).vm.$emit('update:modelValue', groups[0])
		await flushPromises()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('restores the previous group when saving fails', async () => {
		axios.post.mockRejectedValue(new Error('400'))
		const wrapper = mountWith('admin')
		select(wrapper).vm.$emit('update:modelValue', groups[1])
		await flushPromises()
		expect(showError).toHaveBeenCalledTimes(1)
		expect(select(wrapper).props('modelValue')).toEqual(groups[0])
	})
})
