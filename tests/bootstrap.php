<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

define('PHPUNIT_RUN', 1);

$ncBase = __DIR__ . '/../../../lib/base.php';
if (file_exists($ncBase)) {
	// Running inside a Nextcloud server: boot it so tests may use OCP/OC.
	require_once $ncBase;

	\OC::$composerAutoloader->addPsr4('Test\\', \OC::$SERVERROOT . '/tests/lib/', true);
	\OC::$composerAutoloader->addPsr4('Tests\\', \OC::$SERVERROOT . '/tests/', true);

	\OC_App::loadApp('files_sharing_raw');

	\OC_Hook::clear();
} else {
	// Standalone checkout (no server alongside): the app's own autoloader plus
	// the OCP api package are enough for pure unit tests.
	require_once __DIR__ . '/../vendor/autoload.php';
}
