<?php
/**
 * SPDX-FileCopyrightText: 2024-2026 [ernolf] Raphael Gradenwitz
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesSharingRaw\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesSharingRaw\Controller\PrivatePageController;
use OCA\FilesSharingRaw\Controller\PubPageController;
use OCA\FilesSharingRaw\Controller\RawShareApiController;
use OCA\FilesSharingRaw\Db\RawShareMapper;
use OCA\FilesSharingRaw\Listener\FilesLoadAdditionalScriptsListener;
use OCA\FilesSharingRaw\Listener\ShareDeletedListener;
use OCA\FilesSharingRaw\Listener\ShareUpdatedListener;
use OCA\FilesSharingRaw\Middleware\ShareRawOnlyMiddleware;
use OCA\FilesSharingRaw\Service\CspManager;
use OCA\FilesSharingRaw\Service\PublicUrlBuilder;
use OCA\FilesSharingRaw\Service\RawShareRegistry;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\IContainer;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCP\Files\IRootFolder;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\Events\ShareUpdatedEvent;
use OCP\Share\IManager;

class Application extends App implements IBootstrap {
	/**
	 * Application constructor
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct('files_sharing_raw', $urlParams);
		$container = $this->getContainer();

		$this->registerServices($container);
		$this->registerControllers($container);
	}

	public function register(IRegistrationContext $context): void {
		// Files sidebar integration
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesLoadAdditionalScriptsListener::class);

		// Cleanup / consistency
		$context->registerEventListener(ShareDeletedEvent::class, ShareDeletedListener::class);
		$context->registerEventListener(ShareUpdatedEvent::class, ShareUpdatedListener::class);

		// Global middleware: block /s/{token} when raw_only is set
		$context->registerMiddleware(ShareRawOnlyMiddleware::class, true);
	}

	public function boot(IBootContext $context): void {
	}

	/**
	 * Register shared services used by the app.
	 */
	protected function registerServices(IContainer $c) {
		$c->registerService(ShareRawOnlyMiddleware::class, function($container) {
			/** @var IRequest $request */
			$request = $container->query('Request');
			/** @var RawShareMapper $mapper */
			$mapper = $container->query('RawShareMapper');
			/** @var IConfig $config */
			$config = $container->query('OCP\IConfig');
			return new ShareRawOnlyMiddleware($request, $mapper, $config);
		});

		$c->registerService('RawShareMapper', function($container) {
			/** @var \OCP\IDBConnection $db */
			$db = $container->query('OCP\IDBConnection');
			return new RawShareMapper($db);
		});

		$c->registerService('RawShareRegistry', function($container) {
			/** @var RawShareMapper $mapper */
			$mapper = $container->query('RawShareMapper');
			/** @var \OCP\AppFramework\Utility\ITimeFactory $time */
			$time = $container->query('OCP\AppFramework\Utility\ITimeFactory');
			return new RawShareRegistry($mapper, $time);
		});

		$c->registerService('CspManager', function($container) {
			/** @var IConfig $config */
			$config = $container->query('OCP\IConfig');
			/** @var IManager $shareManager */
			$shareManager = $container->query('OCP\Share\IManager');
			/** @var RawShareRegistry $registry */
			$registry = $container->query('RawShareRegistry');
			return new CspManager($config, $shareManager, $registry);
		});

		$c->registerService('PublicUrlBuilder', function($container) {
			/** @var IConfig $config */
			$config = $container->query('OCP\IConfig');
			/** @var IURLGenerator $url */
			$url = $container->query('OCP\IURLGenerator');
			/** @var LoggerInterface $logger */
			$logger = $container->query(LoggerInterface::class);
			return new PublicUrlBuilder($config, $url, $logger);
		});
	}

	/**
	 * Register controller factories that inject dependencies.
	 */
	protected function registerControllers(IContainer $c) {
		$c->registerService('PubPageController', function($container) {
			$appName = $container->getAppName();
			/** @var IRequest $request */
			$request = $container->query('Request');
			/** @var IManager $shareManager */
			$shareManager = $container->query('OCP\Share\IManager');
			/** @var IConfig $config */
			$config = $container->query('OCP\IConfig');
			/** @var CspManager $cspManager */
			$cspManager = $container->query('CspManager');
			/** @var PublicUrlBuilder $publicUrlBuilder */
			$publicUrlBuilder = $container->query('PublicUrlBuilder');
			/** @var RawShareRegistry $registry */
			$registry = $container->query('RawShareRegistry');

			return new PubPageController($appName, $request, $shareManager, $config, $cspManager, $publicUrlBuilder, $registry);
		});

		$c->registerService('PrivatePageController', function($container) {
			$appName = $container->getAppName();
			/** @var IRequest $request */
			$request = $container->query('Request');
			/** @var IRootFolder $rootFolder */
			$rootFolder = $container->query('OCP\Files\IRootFolder');
			/** @var CspManager $cspManager */
			$cspManager = $container->query('CspManager');
			/** @var IConfig $config */
			$config = $container->query('OCP\IConfig');
			/** @var IUserSession $userSession */
			$userSession = $container->query('OCP\IUserSession');

			return new PrivatePageController($appName, $request, $rootFolder, $cspManager, $config, $userSession);
		});

		$c->registerService('RawShareApiController', function($container) {
			$appName = $container->getAppName();
			/** @var IRequest $request */
			$request = $container->query('Request');
			/** @var IManager $shareManager */
			$shareManager = $container->query('OCP\Share\IManager');
			/** @var IUserSession $userSession */
			$userSession = $container->query('OCP\IUserSession');
			/** @var RawShareRegistry $registry */
			$registry = $container->query('RawShareRegistry');
			/** @var PublicUrlBuilder $publicUrlBuilder */
			$publicUrlBuilder = $container->query('PublicUrlBuilder');
			/** @var IRootFolder $rootFolder */
			$rootFolder = $container->query('OCP\Files\IRootFolder');
			/** @var IConfig $config */
			$config = $container->query('OCP\IConfig');
			/** @var IGroupManager $groupManager */
			$groupManager = $container->query('OCP\IGroupManager');

			return new RawShareApiController($appName, $request, $shareManager, $userSession, $registry, $publicUrlBuilder, $rootFolder, $config, $groupManager);
		});
	}
}
