<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Bridges\ApplicationTracy;

use Nette;
use Nette\Application\UI\Presenter;
use Nette\Routing;
use Tracy;


/**
 * Routing debugger for Debug Bar.
 */
final class RoutingPanel implements Tracy\IBarPanel
{
	use Nette\SmartObject;

	/** @var Routing\Router */
	private $router;

	/** @var Nette\Http\IRequest */
	private $httpRequest;

	/** @var Nette\Application\IPresenterFactory */
	private $presenterFactory;

	/** @var \stdClass[] */
	private $routers = [];

	/** @var array|null */
	private $matched;

	/** @var \ReflectionClass|\ReflectionMethod */
	private $source;


	public static function initializePanel(Nette\Application\Application $application): void
	{
		$blueScreen = Tracy\Debugger::getBlueScreen();
		$blueScreen->addPanel(function (?\Throwable $e) use ($application, $blueScreen): ?array {
			$dumper = $blueScreen->getDumper();
			return $e ? null : [
				'tab' => 'Nette Application',
				'panel' => '<h3>Requests</h3>' . $dumper($application->getRequests())
					. '<h3>Presenter</h3>' . $dumper($application->getPresenter()),
			];
		});
	}


	public function __construct(Routing\Router $router, Nette\Http\IRequest $httpRequest, Nette\Application\IPresenterFactory $presenterFactory)
	{
		$this->router = $router;
		$this->httpRequest = $httpRequest;
		$this->presenterFactory = $presenterFactory;
	}


	/**
	 * Renders tab.
	 */
	public function getTab(): string
	{
		$this->analyse($this->router);
		return Nette\Utils\Helpers::capture(function () {
			$matched = $this->matched;
			require __DIR__ . '/templates/RoutingPanel.tab.phtml';
		});
	}


	/**
	 * Renders panel.
	 */
	public function getPanel(): string
	{
		return Nette\Utils\Helpers::capture(function () {
			$matched = $this->matched;
			$routers = $this->routers;
			$source = $this->source;
			$hasModule = (bool) array_filter($routers, function (\stdClass $rq): string { return $rq->module; });
			$url = $this->httpRequest->getUrl();
			$method = $this->httpRequest->getMethod();
			require __DIR__ . '/templates/RoutingPanel.panel.phtml';
		});
	}


	/**
	 * Analyses simple route.
	 */
	private function analyse(Routing\Router $router, string $module = '', bool $parentMatches = true, int $level = -1): void
	{
		if ($router instanceof Routing\RouteList) {
			$parentMatches = $parentMatches && $router->match($this->httpRequest) !== null;
			$next = count($this->routers);
			foreach ($router->getRouters() as $subRouter) {
				$this->analyse($subRouter, $module . $router->getModule(), $parentMatches, $level + 1);
			}

			if ($info = $this->routers[$next] ?? null) {
				$info->gutterTop = abs(max(0, $level) - $info->level);
			}
			if ($info = end($this->routers)) {
				$info->gutterBottom = abs(max(0, $level) - $info->level);
			}
			return;
		}

		$matched = 'no';
		$params = $e = null;
		try {
			$params = $parentMatches ? $router->match($this->httpRequest) : null;
		} catch (\Exception $e) {
		}
		if ($params !== null) {
			if ($module) {
				$params['presenter'] = $module . ($params['presenter'] ?? '');
			}
			$matched = 'may';
			if ($this->matched === null) {
				$this->matched = $params;
				$this->findSource();
				$matched = 'yes';
			}
		}

		$this->routers[] = (object) [
			'level' => max(0, $level),
			'matched' => $matched,
			'class' => get_class($router),
			'defaults' => $router instanceof Routing\Route || $router instanceof Routing\SimpleRouter ? $router->getDefaults() : [],
			'mask' => $router instanceof Routing\Route ? $router->getMask() : null,
			'params' => $params,
			'module' => rtrim($module, ':'),
			'error' => $e,
		];
	}


	private function findSource(): void
	{
		$params = $this->matched;
		$presenter = $params['presenter'] ?? '';
		try {
			$class = $this->presenterFactory->getPresenterClass($presenter);
		} catch (Nette\Application\InvalidPresenterException $e) {
			return;
		}
		$rc = new \ReflectionClass($class);

		if ($rc->isSubclassOf(Nette\Application\UI\Presenter::class)) {
			if (isset($params[Presenter::SIGNAL_KEY])) {
				$method = $class::formatSignalMethod($params[Presenter::SIGNAL_KEY]);

			} elseif (isset($params[Presenter::ACTION_KEY])) {
				$action = $params[Presenter::ACTION_KEY];
				$method = $class::formatActionMethod($action);
				if (!$rc->hasMethod($method)) {
					$method = $class::formatRenderMethod($action);
				}
			}
		}

		$this->source = isset($method) && $rc->hasMethod($method) ? $rc->getMethod($method) : $rc;
	}
}
