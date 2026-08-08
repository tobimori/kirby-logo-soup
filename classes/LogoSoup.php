<?php

namespace tobimori;

use Imagick;
use ImagickPixel;
use Kirby\Cms\App;
use Kirby\Cms\File;

class LogoSoup
{
	private const CACHE_VERSION = 2;

	public static function analyze(File $file): ?array
	{
		$kirby = App::instance();
		[$pixelBudget, $threshold] = static::measurementOptions();
		$id = static::getCacheId($file, $pixelBudget, $threshold);
		$cache = $kirby->cache('tobimori.logo-soup');

		if ($cached = $cache->get($id)) {
			return $cached;
		}

		try {
			[$pixels, $width, $height] = static::extractPixels($file, $pixelBudget);
			$metrics = static::measurePixels($pixels, $width, $height, $threshold);
			$cache->set($id, $metrics);

			return $metrics;
		} catch (\Exception) {
			return null;
		}
	}

	public static function normalize(
		array $metrics,
		float $baseSize = 48,
		float $scaleFactor = 0.5,
		float $densityFactor = 0.5,
	): array {
		if (isset($metrics['contentRatio']) === false) {
			return [
				'width' => (int) $baseSize,
				'height' => (int) $baseSize,
				'offsetX' => 0.0,
				'offsetY' => 0.0,
			];
		}

		$ratio = $metrics['contentRatio'];
		$width = $ratio ** $scaleFactor * $baseSize;
		$height = $width / $ratio;

		if (isset($metrics['backgroundLuminance'])) {
			$irradiationScale = 1 - (1 - $metrics['backgroundLuminance']) * ($metrics['pixelDensity'] ?? 0.5) * 0.08;
			$width *= $irradiationScale;
			$height *= $irradiationScale;
		}

		if ($densityFactor > 0 && ($density = $metrics['pixelDensity'] ?? 0) > 0) {
			$densityScale = (0.35 / $density) ** ($densityFactor * 0.5);
			$densityScale = max(0.5, min(2.0, $densityScale));
			$width *= $densityScale;
			$height *= $densityScale;
		}

		return [
			'width' => (int) round($width),
			'height' => (int) round($height),
			'offsetX' => round(- ($metrics['visualCenterX'] ?? 0) * $width, 1),
			'offsetY' => round(- ($metrics['visualCenterY'] ?? 0) * $height, 1),
		];
	}

	public static function clearCache(File $file): void
	{
		[$pixelBudget, $threshold] = static::measurementOptions();
		App::instance()
			->cache('tobimori.logo-soup')
			->remove(static::getCacheId($file, $pixelBudget, $threshold));
	}

	private static function measurementOptions(): array
	{
		$kirby = App::instance();

		return [
			max(1, (int) $kirby->option('tobimori.logo-soup.samplePixelBudget', 2048)),
			max(0, (int) $kirby->option('tobimori.logo-soup.contrastThreshold', 10)),
		];
	}

	private static function getCacheId(File $file, int $pixelBudget, int $threshold): string
	{
		return implode('-', [
			'v' . static::CACHE_VERSION,
			$pixelBudget,
			$threshold,
			$file->uuid()?->id() ?? $file->id(),
		]);
	}

	/** @return array{0: list<int>, 1: int, 2: int} */
	private static function extractPixels(File $file, int $pixelBudget): array
	{
		$imagick = new Imagick();

		if (strtolower($file->extension()) === 'svg') {
			$imagick->setBackgroundColor(new ImagickPixel('transparent'));
			$imagick->setResolution(96, 96);
		}

		$imagick->readImage($file->root());

		if ($imagick->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
			$imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
		}

		if (!$imagick->getImageAlphaChannel()) {
			$imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
		}

		$width = $imagick->getImageWidth();
		$height = $imagick->getImageHeight();

		if ($width * $height > $pixelBudget) {
			$scale = sqrt($pixelBudget / ($width * $height));
			$width = max(1, (int) round($width * $scale));
			$height = max(1, (int) round($height * $scale));
			$imagick->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
		}

		$pixels = $imagick->exportImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR);
		$imagick->clear();
		$imagick->destroy();

		return [$pixels, $width, $height];
	}

	private static function measurePixels(array $pixels, int $width, int $height, int $threshold): array
	{
		[$alphaOnly, $background] = static::analyzePerimeter($pixels, $width, $height);
		$thresholdSquared = $threshold * $threshold * 3;
		$minX = $width;
		$minY = $height;
		$maxX = 0;
		$maxY = 0;
		$filledPixels = 0;
		$totalOpacity = 0.0;
		$totalWeight = 0.0;
		$weightedX = 0.0;
		$weightedY = 0.0;

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$i = ($y * $width + $x) * 4;
				$r = $pixels[$i] & 0xFF;
				$g = $pixels[$i + 1] & 0xFF;
				$b = $pixels[$i + 2] & 0xFF;
				$a = $pixels[$i + 3] & 0xFF;

				if ($a <= $threshold) {
					continue;
				}

				if ($alphaOnly) {
					$weight = $a * $a;
					$opacity = $a;
				} else {
					$dr = $r - $background['r'];
					$dg = $g - $background['g'];
					$db = $b - $background['b'];
					$distanceSquared = $dr * $dr + $dg * $dg + $db * $db;

					if ($distanceSquared < $thresholdSquared) {
						continue;
					}

					$weight = $distanceSquared * $a;
					$opacity = min($a, sqrt($distanceSquared));
				}

				$minX = min($minX, $x);
				$minY = min($minY, $y);
				$maxX = max($maxX, $x);
				$maxY = max($maxY, $y);
				$filledPixels++;
				$totalOpacity += $opacity;
				$totalWeight += $weight;
				$weightedX += ($x + 0.5) * $weight;
				$weightedY += ($y + 0.5) * $weight;
			}
		}

		if ($filledPixels === 0) {
			return [
				'contentRatio' => $width / $height,
				'pixelDensity' => 0.5,
				'visualCenterX' => 0.0,
				'visualCenterY' => 0.0,
			];
		}

		$contentWidth = $maxX - $minX + 1;
		$contentHeight = $maxY - $minY + 1;
		$centerX = $totalWeight > 0 ? $weightedX / $totalWeight : $minX + $contentWidth / 2;
		$centerY = $totalWeight > 0 ? $weightedY / $totalWeight : $minY + $contentHeight / 2;
		$metrics = [
			'contentRatio' => $contentWidth / $contentHeight,
			'pixelDensity' => $totalOpacity / (255 * $contentWidth * $contentHeight),
			'visualCenterX' => ($centerX - $minX - $contentWidth / 2) / $contentWidth,
			'visualCenterY' => ($centerY - $minY - $contentHeight / 2) / $contentHeight,
		];

		if (!$alphaOnly) {
			$metrics['backgroundLuminance'] = ($background['r'] * 299 + $background['g'] * 587 + $background['b'] * 114) / 255000;
		}

		return $metrics;
	}

	/** @return array{0: bool, 1: array{r: int, g: int, b: int}} */
	private static function analyzePerimeter(array $pixels, int $width, int $height): array
	{
		$buckets = [];
		$opaque = 0;
		$transparent = 0;

		$sample = static function (int $pixel) use ($pixels, &$buckets, &$opaque, &$transparent): void {
			$i = $pixel * 4;
			$a = $pixels[$i + 3] & 0xFF;

			if ($a < 128) {
				$transparent++;
				return;
			}

			$opaque++;
			$r = $pixels[$i] & 0xFF;
			$g = $pixels[$i + 1] & 0xFF;
			$b = $pixels[$i + 2] & 0xFF;
			$key = (($r >> 5) * 8 + ($g >> 5)) * 8 + ($b >> 5);
			$buckets[$key] ??= [0, 0, 0, 0];
			$buckets[$key][0]++;
			$buckets[$key][1] += $r;
			$buckets[$key][2] += $g;
			$buckets[$key][3] += $b;
		};

		for ($x = 0; $x < $width; $x++) {
			$sample($x);
			if ($height > 1) {
				$sample(($height - 1) * $width + $x);
			}
		}

		for ($y = 1; $y < $height - 1; $y++) {
			$sample($y * $width);
			if ($width > 1) {
				$sample($y * $width + $width - 1);
			}
		}

		$dominant = [0, 255, 255, 255];
		foreach ($buckets as $bucket) {
			if ($bucket[0] > $dominant[0]) {
				$dominant = $bucket;
			}
		}

		$background = $dominant[0] > 0
			? [
				'r' => (int) round($dominant[1] / $dominant[0]),
				'g' => (int) round($dominant[2] / $dominant[0]),
				'b' => (int) round($dominant[3] / $dominant[0]),
			]
			: ['r' => 255, 'g' => 255, 'b' => 255];

		return [
			$opaque + $transparent > 0 && $transparent > ($opaque + $transparent) * 0.1,
			$background,
		];
	}
}
