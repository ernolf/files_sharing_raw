<?php

/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\AppInfo;

use OCA\FilesSharingRaw\Controller\PrivatePageController;
use OCA\FilesSharingRaw\Controller\PubPageController;
use OCA\FilesSharingRaw\Controller\RawShareApiController;
use OCA\FilesSharingRaw\Db\RawShareMapper;
use OCA\FilesSharingRaw\Listener\BeforeTemplateRenderedListener;
use OCA\FilesSharingRaw\Listener\ShareDeletedListener;
use OCA\FilesSharingRaw\Middleware\ShareRawOnlyMiddleware;
use OCA\FilesSharingRaw\Service\CspManager;
use OCA\FilesSharingRaw\Service\PublicUrlBuilder;
use OCA\FilesSharingRaw\Service\RawShareRegistry;
use OCA\FilesSharingRaw\SetupCheck\RootRouteSupportCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'files_sharing_raw';

	/** Appconfig key of the group whose members may edit the per-share CSP. */
	public const CONFIG_CSP_EDITOR_GROUP = 'csp_editor_group';
	public const CSP_EDITOR_GROUP_DEFAULT = 'admin';

	/**
	 * Application constructor
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$this->registerServices($context);
		$this->registerControllers($context);

		// Sharing sidebar integration
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, BeforeTemplateRenderedListener::class);

		// Cleanup / consistency
		$context->registerEventListener(ShareDeletedEvent::class, ShareDeletedListener::class);

		// Global middleware: block /s/{token} when raw_only is set
		$context->registerMiddleware(ShareRawOnlyMiddleware::class, true);

		// Setup check: warn when the core does not grant the app its root routes
		$context->registerSetupCheck(RootRouteSupportCheck::class);
	}

	public function boot(IBootContext $context): void {
	}

	/**
	 * Register shared services used by the app.
	 */
	protected function registerServices(IRegistrationContext $context) {
		$context->registerService(ShareRawOnlyMiddleware::class, function (ContainerInterface $container) {
			/** @var IRequest $request */
			$request = $container->get(IRequest::class);
			/** @var RawShareMapper $mapper */
			$mapper = $container->get('RawShareMapper');
			/** @var IConfig $config */
			$config = $container->get('OCP\IConfig');
			return new ShareRawOnlyMiddleware($request, $mapper, $config);
		});

		$context->registerService('RawShareMapper', function (ContainerInterface $container) {
			/** @var \OCP\IDBConnection $db */
			$db = $container->get('OCP\IDBConnection');
			return new RawShareMapper($db);
		});

		$context->registerService('RawShareRegistry', function (ContainerInterface $container) {
			/** @var RawShareMapper $mapper */
			$mapper = $container->get('RawShareMapper');
			/** @var \OCP\AppFramework\Utility\ITimeFactory $time */
			$time = $container->get('OCP\AppFramework\Utility\ITimeFactory');
			return new RawShareRegistry($mapper, $time);
		});

		$context->registerService('CspManager', function (ContainerInterface $container) {
			/** @var IConfig $config */
			$config = $container->get('OCP\IConfig');
			/** @var IManager $shareManager */
			$shareManager = $container->get('OCP\Share\IManager');
			/** @var RawShareRegistry $registry */
			$registry = $container->get('RawShareRegistry');
			return new CspManager($config, $shareManager, $registry);
		});

		$context->registerService('PublicUrlBuilder', function (ContainerInterface $container) {
			/** @var IConfig $config */
			$config = $container->get('OCP\IConfig');
			/** @var IURLGenerator $url */
			$url = $container->get('OCP\IURLGenerator');
			/** @var LoggerInterface $logger */
			$logger = $container->get(LoggerInterface::class);
			return new PublicUrlBuilder($config, $url, $logger);
		});
	}

	/**
	 * Register controller factories that inject dependencies.
	 */
	protected function registerControllers(IRegistrationContext $context) {
		$context->registerService('PubPageController', function (ContainerInterface $container) {
			$appName = self::APP_ID;
			/** @var IRequest $request */
			$request = $container->get(IRequest::class);
			/** @var IManager $shareManager */
			$shareManager = $container->get('OCP\Share\IManager');
			/** @var IConfig $config */
			$config = $container->get('OCP\IConfig');
			/** @var CspManager $cspManager */
			$cspManager = $container->get('CspManager');
			/** @var PublicUrlBuilder $publicUrlBuilder */
			$publicUrlBuilder = $container->get('PublicUrlBuilder');
			/** @var RawShareRegistry $registry */
			$registry = $container->get('RawShareRegistry');

			return new PubPageController($appName, $request, $shareManager, $config, $cspManager, $publicUrlBuilder, $registry);
		});

		$context->registerService('PrivatePageController', function (ContainerInterface $container) {
			$appName = self::APP_ID;
			/** @var IRequest $request */
			$request = $container->get(IRequest::class);
			/** @var IRootFolder $rootFolder */
			$rootFolder = $container->get('OCP\Files\IRootFolder');
			/** @var CspManager $cspManager */
			$cspManager = $container->get('CspManager');
			/** @var IConfig $config */
			$config = $container->get('OCP\IConfig');
			/** @var IUserSession $userSession */
			$userSession = $container->get('OCP\IUserSession');
			/** @var PublicUrlBuilder $publicUrlBuilder */
			$publicUrlBuilder = $container->get('PublicUrlBuilder');
			/** @var IURLGenerator $url */
			$url = $container->get('OCP\IURLGenerator');

			return new PrivatePageController($appName, $request, $rootFolder, $cspManager, $config, $userSession, $publicUrlBuilder, $url);
		});

		$context->registerService('RawShareApiController', function (ContainerInterface $container) {
			$appName = self::APP_ID;
			/** @var IRequest $request */
			$request = $container->get(IRequest::class);
			/** @var IManager $shareManager */
			$shareManager = $container->get('OCP\Share\IManager');
			/** @var IUserSession $userSession */
			$userSession = $container->get('OCP\IUserSession');
			/** @var RawShareRegistry $registry */
			$registry = $container->get('RawShareRegistry');
			/** @var PublicUrlBuilder $publicUrlBuilder */
			$publicUrlBuilder = $container->get('PublicUrlBuilder');
			/** @var IRootFolder $rootFolder */
			$rootFolder = $container->get('OCP\Files\IRootFolder');
			/** @var IConfig $config */
			$config = $container->get('OCP\IConfig');
			/** @var IGroupManager $groupManager */
			$groupManager = $container->get('OCP\IGroupManager');

			return new RawShareApiController($appName, $request, $shareManager, $userSession, $registry, $publicUrlBuilder, $rootFolder, $config, $groupManager);
		});
	}
}
