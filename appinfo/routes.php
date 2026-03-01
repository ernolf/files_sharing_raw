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

		// Note: when files_sharing_raw is NOT listed in rootUrlApps, Nextcloud automatically
		// registers the root-alias routes above at /apps/files_sharing_raw/... instead of /raw/...
		// No separate fallback routes are needed.
	]
];
