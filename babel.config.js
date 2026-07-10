/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const babelConfig = require('@nextcloud/babel-config')

babelConfig.presets[0][1].modules = 'auto'

module.exports = babelConfig
