<?php

declare(strict_types=1);

namespace Gamee\Monolog\DI;

use Monolog\Logger;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Psr\Log\LoggerAwareInterface;

class MonologExtension extends CompilerExtension
{

	private const TAG_HANDLER = 'monolog.handler';

	private const TAG_PROCESSOR = 'monolog.processor';

	private const TAG_PRIORITY = 'monolog.priority';

	/**
	 * @var array|mixed[]
	 */
	private $defaults = [
		'handlers' => [],
		'processors' => [],
		'name' => 'app',
	];


	public function loadConfiguration()
	{
		parent::loadConfiguration();

		$containerBuilder = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		foreach ($config['handlers'] as $handlerName => $implementation) {
			Compiler::loadDefinitions($containerBuilder, [
				$serviceName = $this->prefix('handler.' . $handlerName) => $implementation,
			]);
			$containerBuilder->getDefinition($serviceName)
				->addTag(self::TAG_HANDLER)
				->addTag(self::TAG_PRIORITY, ctype_digit($handlerName) ? $handlerName : 0)
			;
		}

		$containerBuilder->addDefinition($this->prefix('logger'))
			->setClass(Logger::class, [$config['name']])
		;

		foreach ($config['processors'] as $processorName => $implementation) {
			Compiler::loadDefinitions($containerBuilder, [
				$serviceName = $this->prefix('processor.' . $processorName) => $implementation,
			]);
			$containerBuilder->getDefinition($serviceName)
				->addTag(self::TAG_PROCESSOR)
				->addTag(self::TAG_PRIORITY, ctype_digit($processorName) ? $processorName : 0)
			;
		}
	}


	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();
		$logger = $builder->getDefinition($this->prefix('logger'));

		foreach ($handlers = $this->findByTagSorted(self::TAG_HANDLER) as $serviceName => $meta) {
			$logger->addSetup('pushHandler', ['@' . $serviceName]);
		}

		foreach ($this->findByTagSorted(self::TAG_PROCESSOR) as $serviceName => $meta) {
			$logger->addSetup('pushProcessor', ['@' . $serviceName]);
		}

		foreach ($builder->findByType(LoggerAwareInterface::class) as $service) {
			$service->addSetup('setLogger', ['@' . $this->prefix('logger')]);
		}
	}


	protected function findByTagSorted($tag)
	{
		$builder = $this->getContainerBuilder();
		$services = $builder->findByTag($tag);
		uksort($services, function ($nameA, $nameB) use ($builder) {
			$priorityA = $builder->getDefinition($nameA)->getTag(self::TAG_PRIORITY) ?: 0;
			$priorityB = $builder->getDefinition($nameB)->getTag(self::TAG_PRIORITY) ?: 0;

			return $priorityA <=> $priorityB;
		});

		return $services;
	}

}
