<?php

declare(strict_types=1);

namespace Gamee\Monolog\DI;

use Monolog\Logger;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Definitions\ServiceDefinition;
use Psr\Log\LoggerAwareInterface;

/**
 * @property-read array $config
 */
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


	public function loadConfiguration(): void
	{
		parent::loadConfiguration();

		$builder = $this->getContainerBuilder();
		$this->validateConfig($this->defaults);

		foreach ($this->config['handlers'] as $handlerName => $implementation) {
			$this->compiler->loadDefinitionsFromConfig([
				$serviceName = $this->prefix('handler.' . $handlerName) => $implementation,
			]);

			$builder->getDefinition($serviceName)
				->addTag(self::TAG_HANDLER)
				->addTag(self::TAG_PRIORITY, ctype_digit($handlerName) ? $handlerName : 0);
		}

		foreach ($this->config['processors'] as $processorName => $implementation) {
			$this->compiler->loadDefinitionsFromConfig([
				$serviceName = $this->prefix('processor.' . $processorName) => $implementation,
			]);

			$builder->getDefinition($serviceName)
				->addTag(self::TAG_PROCESSOR)
				->addTag(self::TAG_PRIORITY, ctype_digit($processorName) ? $processorName : 0);
		}
	}


	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();

		$logger = $builder->addDefinition($this->prefix('logger'))
			->setFactory(Logger::class, [$this->config['name']]);

		foreach ($handlers = $this->findByTagSorted(self::TAG_HANDLER) as $serviceName => $meta) {
			$logger->addSetup('pushHandler', ['@' . $serviceName]);
		}

		foreach ($this->findByTagSorted(self::TAG_PROCESSOR) as $serviceName => $meta) {
			$logger->addSetup('pushProcessor', ['@' . $serviceName]);
		}

		foreach ($builder->findByType(LoggerAwareInterface::class) as $service) {
			if ($service instanceof ServiceDefinition) {
				$service->addSetup('setLogger', ['@' . $this->prefix('logger')]);
			} elseif ($service instanceof FactoryDefinition) {
				$service->getResultDefinition()->addSetup('setLogger', ['@' . $this->prefix('logger')]);
			} else {
				throw new \UnexpectedValueException;
			}
		}
	}


	protected function findByTagSorted(string $tag): array
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
