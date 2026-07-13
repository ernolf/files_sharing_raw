/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { join, resolve } from 'path'

import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig(
	{
		'sharing-sidebar': resolve(join('src', 'sharing-sidebar.js')),
	}, {
		createEmptyCSSEntryPoints: true,
	},
)
