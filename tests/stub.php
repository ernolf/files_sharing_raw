<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Psalm stubs for internal (OC\…) classes that are not part of the public OCP
// surface provided by nextcloud/ocp. Add entries here only when Psalm reports a
// genuinely missing class that the app legitimately relies on.

namespace {
	class OC {
		/** @var string */
		public static $SERVERROOT = '';
	}
}

namespace OC\Hooks {
	// OCP\Files\IRootFolder extends this internal interface.
	interface Emitter {
	}
}

namespace OCA\Files\Event {
	// Dispatched by the files app; not part of the OCP package.
	class LoadAdditionalScriptsEvent extends \OCP\EventDispatcher\Event {
	}
}
