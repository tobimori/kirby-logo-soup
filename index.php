<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use tobimori\LogoSoup;

App::plugin('tobimori/logo-soup', [
	'options' => [
		'cache.metrics' => false,
		'sampleMaxSize' => 200,
		'contrastThreshold' => 10,
	],
	'fileMethods' => [
		/** @kql-allowed */
		'logoMetrics' => fn(): ?array => LogoSoup::analyze($this),
		/** @kql-allowed */
		'logoNormalize' => fn(
			float $baseSize = 48,
			float $scaleFactor = 0.5,
			float $densityFactor = 0.5,
		): array =>  LogoSoup::normalize(
			LogoSoup::analyze($this) ?? [],
			$baseSize,
			$scaleFactor,
			$densityFactor,
		),
	],
	'hooks' => [
		'file.update:before' => [LogoSoup::class, 'clearCache'],
		'file.replace:before' => [LogoSoup::class, 'clearCache'],
	],
]);
