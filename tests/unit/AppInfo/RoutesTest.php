<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSharingRaw\Tests\Unit\AppInfo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoutesTest extends TestCase {
	/**
	 * @return array<string, array{string}>
	 */
	public static function routeNames(): array {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$names = [];
		foreach ($routes['routes'] as $route) {
			$names[$route['name']] = [$route['name']];
		}
		return $names;
	}

	/**
	 * A route name that does not resolve to a controller method only fails at
	 * request time, so resolve every name the way the server's RouteParser does.
	 */
	#[DataProvider('routeNames')]
	public function testRouteResolvesToControllerMethod(string $name): void {
		$split = explode('#', $name, 3);
		self::assertCount(2, $split, "Invalid route name: $name");
		[$controller, $action] = $split;

		$class = 'OCA\\FilesSharingRaw\\Controller\\' . self::underScoreToCamelCase(ucfirst($controller)) . 'Controller';
		$method = self::underScoreToCamelCase($action);

		self::assertTrue(class_exists($class), "Route $name: class $class does not exist");
		self::assertTrue(method_exists($class, $method), "Route $name: method $class::$method does not exist");
	}

	private static function underScoreToCamelCase(string $str): string {
		return preg_replace_callback('/_[a-z]?/', fn (array $matches): string => strtoupper(ltrim($matches[0], '_')), $str);
	}
}
