<!--
  - SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcSettingsSection
		class="raw-admin-section"
		:name="t('files_sharing_raw', 'Raw Fileserver')"
		:description="t('files_sharing_raw', 'Members of this group can edit the Content Security Policy of their raw shares in the Files sidebar.')"
		:docUrl="docUrl">
		<div class="raw-admin__field">
			<NcSelect
				:modelValue="selected"
				:options="allGroups"
				label="label"
				:clearable="false"
				:disabled="saving"
				:inputLabel="t('files_sharing_raw', 'CSP editor group')"
				@update:modelValue="onGroupChange" />
		</div>
	</NcSettingsSection>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import '@nextcloud/dialogs/style.css'

const docUrl = 'https://github.com/ernolf/files_sharing_raw/wiki/Content-Security-Policy#-csp-editors'

const allGroups = loadState('files_sharing_raw', 'all_groups', [])
const group = ref(loadState('files_sharing_raw', 'csp_editor_group', 'admin'))
const saving = ref(false)

// A configured group that no longer exists is still shown by its id.
const selected = computed(() => allGroups.find((g) => g.id === group.value) ?? { id: group.value, label: group.value })

/**
 * Persist the selected group; revert the selection when saving fails.
 *
 * @param {{id: string, label: string}|null} option the selected group
 */
async function onGroupChange(option) {
	if (!option || option.id === group.value) {
		return
	}
	const previous = group.value
	group.value = option.id
	saving.value = true
	try {
		await axios.post(generateUrl('/apps/files_sharing_raw/api/v1/admin/csp-editor-group'), { group: option.id })
	} catch {
		group.value = previous
		showError(t('files_sharing_raw', 'Could not save the CSP editor group.'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
/* Full-width section + closing separator (NcSettingsSection caps width at 900px). */
.raw-admin-section {
	width: auto !important;
	border-bottom: 1px solid var(--color-border);
}

.raw-admin__field {
	max-width: 600px;
	margin-bottom: 12px;
}
</style>
