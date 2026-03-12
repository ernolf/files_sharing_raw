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
			'requirements' => array('fileId' => '\d+')
		],

		// Root alias routes: /raw/{token} and /raw/{token}/{path}
		// Require 'files_sharing_raw' in rootUrlApps (Nextcloud core RouteParser.php).
		// Requests via fallback URLs below are 307-redirected to these when root aliases are active.
		['name' => 'privatePage#getByPath', 'url' => '/u/{userId}/{path}', 'root' => '/raw',
			'requirements' => array(
				'userId' => '[^/]+',
				'path' => '.+'
			)
		],

		// Root namespace: /rss -> fixed token "rss"
		// (requires core allowlist for rootUrlApps incl. 'files_sharing_raw')
		['name' => 'pubPage#getRssRoot', 'url' => '/rss', 'root' => '', 'verb' => 'GET'],
		['name' => 'pubPage#getRssRootPath', 'url' => '/rss/{path}', 'root' => '', 'verb' => 'GET',
			'requirements' => array('path' => '.*'),
			'defaults' => array('path' => ''),
		],

		['name' => 'pubPage#getByTokenRoot', 'url' => '/{token}', 'root' => '/raw', 'verb' => 'GET',
			'requirements' => array('token' => '[A-Za-z0-9-]+')
		],
		['name' => 'pubPage#getByTokenAndPathRoot', 'url' => '/{token}/{path}', 'root' => '/raw',
			'verb' => 'GET',
			'requirements' => array(
				'token' => '[A-Za-z0-9-]+',
				'path' => '.+'
			)
		],

		// Legacy routes — always registered at /apps/files_sharing_raw/... (no root parameter).
		// With root aliases active: these serve as redirect shims (307 → /raw/... or /rss/...).
		// Without root aliases: the root-alias routes above are registered here automatically,
		//   so these legacy entries are shadowed and behave identically — harmless duplication.
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
