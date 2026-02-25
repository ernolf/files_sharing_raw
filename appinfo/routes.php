<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-FileCopyrightText: 2018-2019 Gerben
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// All app routes must be reachable ONLY under /raw/ (root routes).
		// Require a core configuration allowlist entry (Nextcloud rootUrlApps including files_sharing_raw)
		// in the file lib/private/AppFramework/Routing/RouteParser.php

		['name' => 'rawPublicUrl#getTokenUrl', 'url' => '/api/v1/raw-public-url', 'verb' => 'GET'],

		// Raw share registry API (used by Files sidebar UI)
		['name' => 'rawShareApi#get', 'url' => '/api/v1/raw-share/{shareId}', 'verb' => 'GET'],
		['name' => 'rawShareApi#set', 'url' => '/api/v1/raw-share/{shareId}', 'verb' => 'POST'],
		['name' => 'rawShareApi#listByFileId', 'url' => '/api/v1/raw-shares/{fileId}', 'verb' => 'GET',
			'requirements' => array('fileId' => '\d+')
		],

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

		// Public URLs (root alias): /raw/{token} and /raw/{token}/{path}
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
	]
];
