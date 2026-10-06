<?php

/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-FileCopyrightText: 2018-2019 Gerben
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// API routes — always reachable under /apps/files_sharing_raw/api/v1/...
		['name' => 'rawPublicUrl#getTokenUrl', 'url' => '/api/v1/raw-public-url', 'verb' => 'GET'],

		// Raw share registry API (used by Files sidebar UI)
		['name' => 'rawShareApi#get', 'url' => '/api/v1/raw-share/{shareId}', 'verb' => 'GET'],
		['name' => 'rawShareApi#set', 'url' => '/api/v1/raw-share/{shareId}', 'verb' => 'POST'],
		['name' => 'rawShareApi#listByFileId', 'url' => '/api/v1/raw-shares/{fileId}', 'verb' => 'GET',
			'requirements' => ['fileId' => '\d+']
		],

		// Admin settings (Sharing section)
		['name' => 'adminSettings#setCspEditorGroup', 'url' => '/api/v1/admin/csp-editor-group', 'verb' => 'POST'],

		// Root alias routes: /raw/{token} and /raw/{token}/{path}.
		// Requests via the legacy URLs below are 307-redirected to these.
		['name' => 'privatePage#getByPath', 'url' => '/u/{userId}/{path}', 'root' => '/raw',
			'requirements' => [
				'userId' => '[^/]+',
				'path' => '.+'
			]
		],

		// Root namespace: /rss -> fixed token "rss"
		['name' => 'pubPage#getRssRoot', 'url' => '/rss', 'root' => '', 'verb' => 'GET'],
		['name' => 'pubPage#getRssRootPath', 'url' => '/rss/{path}', 'root' => '', 'verb' => 'GET',
			'requirements' => ['path' => '.*'],
			'defaults' => ['path' => ''],
		],

		['name' => 'pubPage#getByTokenRoot', 'url' => '/{token}', 'root' => '/raw', 'verb' => 'GET',
			'requirements' => ['token' => '[A-Za-z0-9-]+']
		],
		['name' => 'pubPage#getByTokenAndPathRoot', 'url' => '/{token}/{path}', 'root' => '/raw',
			'verb' => 'GET',
			'requirements' => [
				'token' => '[A-Za-z0-9-]+',
				'path' => '.+'
			]
		],

		// Legacy routes at /apps/files_sharing_raw/... (no root parameter): redirect shims
		// (307 → /raw/... or /rss/...) that keep links in the long form working.
		['name' => 'pubPage#legacyByToken', 'url' => '/{token}', 'verb' => 'GET',
			'requirements' => ['token' => '[A-Za-z0-9-]+']
		],
		['name' => 'pubPage#legacyByTokenAndPath', 'url' => '/{token}/{path}', 'verb' => 'GET',
			'requirements' => ['token' => '[A-Za-z0-9-]+', 'path' => '.+']
		],
		['name' => 'pubPage#legacyRss', 'url' => '/rss', 'verb' => 'GET'],
		['name' => 'pubPage#legacyRssPath', 'url' => '/rss/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '.*'],
			'defaults' => ['path' => '']
		],
		['name' => 'privatePage#legacyByPath', 'url' => '/u/{userId}/{path}', 'verb' => 'GET',
			'requirements' => ['userId' => '[^/]+', 'path' => '.+']
		],
	]
];
