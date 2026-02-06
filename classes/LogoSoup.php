<?php

namespace tobimori;

use Imagick;
use ImagickPixel;
use Kirby\Cms\App;
use Kirby\Cms\File;

class LogoSoup
{
	/**
	 * Analyze a logo image and return cached metrics.
	 * Returns content aspect ratio, pixel density, and visual center offsets.
	 */
	public static function analyze(File $file): ?array
	{
		$kirby = App::instance();
		$id = static::getId($file);

		if ($kirby->option('tobimori.logo-soup.cache.metrics')) {
			$cache = $kirby->cache('tobimori.logo-soup.metrics');
			if ($cached = $cache->get($id)) {
				return $cached;
			}
		}

		try {
			$maxSize = $kirby->option('tobimori.logo-soup.sampleMaxSize', 200);
			$threshold = $kirby->option('tobimori.logo-soup.contrastThreshold', 10);

			[$pixels, $width, $height, $alphaOnly, $bg] = static::extractPixels($file, $maxSize);

			$contentBox = static::detectContentBoundingBox($pixels, $width, $height, $threshold, $alphaOnly, $bg);
			if ($contentBox['width'] === 0 || $contentBox['height'] === 0) {
				return null;
			}

			$visualCenter = static::calculateVisualCenter($pixels, $width, $height, $contentBox, $threshold, $alphaOnly, $bg);
			$density = static::measurePixelDensity($pixels, $width, $height, $contentBox, $threshold, $alphaOnly);

			$metrics = [
				'contentRatio' => $contentBox['width'] / $contentBox['height'],
				'pixelDensity' => $density,
				'visualCenterX' => $visualCenter['offsetX'] / $contentBox['width'],
				'visualCenterY' => $visualCenter['offsetY'] / $contentBox['height'],
			];

			if ($kirby->option('tobimori.logo-soup.cache.metrics')) {
				$kirby->cache('tobimori.logo-soup.metrics')->set($id, $metrics);
			}

			return $metrics;
		} catch (\Exception) {
			return null;
		}
	}

	/**
	 * Calculate normalized display dimensions from cached metrics.
	 * Uses Dan Paquette's aspect ratio normalization with optional density compensation.
	 */
	public static function normalize(
		array $metrics,
		float $baseSize = 48,
		float $scaleFactor = 0.5,
		float $densityFactor = 0.5,
	): array {
		if (empty($metrics) || !isset($metrics['contentRatio'])) {
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

		// density compensation: dense logos scale down, airy logos scale up
		if ($densityFactor > 0 && isset($metrics['pixelDensity']) && $metrics['pixelDensity'] > 0) {
			$referenceDensity = 0.35;
			$densityRatio = $metrics['pixelDensity'] / $referenceDensity;
			$densityScale = (1 / $densityRatio) ** ($densityFactor * 0.5);
			$clampedScale = max(0.5, min(2.0, $densityScale));

			$width *= $clampedScale;
			$height *= $clampedScale;
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
		App::instance()->cache('tobimori.logo-soup.metrics')->remove(static::getId($file));
	}

	private static function getId(File $file): string
	{
		return $file->uuid()?->id() ?? $file->id();
	}

	/**
	 * Load image with Imagick, rasterize SVGs, resize to sample dimensions,
	 * and return flat RGBA pixel array.
	 *
	 * @return array{0: list<int>, 1: int, 2: int, 3: bool, 4: array{r: int, g: int, b: int}}
	 */
	private static function extractPixels(File $file, int $maxSize): array
	{
		$imagick = new Imagick();
		$isSvg = strtolower($file->extension()) === 'svg';

		if ($isSvg) {
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

		$w = $imagick->getImageWidth();
		$h = $imagick->getImageHeight();
		if ($w > $maxSize || $h > $maxSize) {
			$imagick->thumbnailImage($maxSize, $maxSize, true);
		}

		$width = $imagick->getImageWidth();
		$height = $imagick->getImageHeight();

		$pixels = $imagick->exportImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR);

		$imagick->clear();
		$imagick->destroy();

		// detect transparency and background color from corner pixels
		$hasTransparency = false;
		$totalPixels = $width * $height;
		for ($i = 0; $i < $totalPixels; $i++) {
			if (($pixels[$i * 4 + 3] & 0xFF) < 250) {
				$hasTransparency = true;
				break;
			}
		}

		// sample corner pixels to detect background color for opaque images
		$bg = ['r' => 255, 'g' => 255, 'b' => 255];
		if (!$hasTransparency) {
			$corners = [
				0,
				($width - 1) * 4,
				(($height - 1) * $width) * 4,
				(($height - 1) * $width + $width - 1) * 4,
			];

			$sumR = $sumG = $sumB = 0;
			foreach ($corners as $ci) {
				$sumR += $pixels[$ci] & 0xFF;
				$sumG += $pixels[$ci + 1] & 0xFF;
				$sumB += $pixels[$ci + 2] & 0xFF;
			}

			$bg = [
				'r' => (int) round($sumR / 4),
				'g' => (int) round($sumG / 4),
				'b' => (int) round($sumB / 4),
			];
		}

		return [$pixels, $width, $height, $hasTransparency, $bg];
	}

	/**
	 * @param array{r: int, g: int, b: int} $bg
	 */
	private static function isContentPixel(int $r, int $g, int $b, int $a, int $threshold, bool $alphaOnly = false, array $bg = ['r' => 255, 'g' => 255, 'b' => 255]): bool
	{
		if ($alphaOnly) {
			return $a > $threshold;
		}

		return $a > $threshold && (
			abs($r - $bg['r']) > $threshold ||
			abs($g - $bg['g']) > $threshold ||
			abs($b - $bg['b']) > $threshold
		);
	}

	/**
	 * Scan all pixels to find the tight bounding box of actual content,
	 * excluding whitespace and transparent areas.
	 *
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	private static function detectContentBoundingBox(array $pixels, int $width, int $height, int $threshold, bool $alphaOnly = false, array $bg = ['r' => 255, 'g' => 255, 'b' => 255]): array
	{
		$minX = $width;
		$minY = $height;
		$maxX = 0;
		$maxY = 0;

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$i = ($y * $width + $x) * 4;
				$r = $pixels[$i] & 0xFF;
				$g = $pixels[$i + 1] & 0xFF;
				$b = $pixels[$i + 2] & 0xFF;
				$a = $pixels[$i + 3] & 0xFF;

				if (static::isContentPixel($r, $g, $b, $a, $threshold, $alphaOnly, $bg)) {
					if ($x < $minX) $minX = $x;
					if ($y < $minY) $minY = $y;
					if ($x > $maxX) $maxX = $x;
					if ($y > $maxY) $maxY = $y;
				}
			}
		}

		if ($minX > $maxX || $minY > $maxY) {
			return ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height];
		}

		return [
			'x' => $minX,
			'y' => $minY,
			'width' => $maxX - $minX + 1,
			'height' => $maxY - $minY + 1,
		];
	}

	/**
	 * Calculate the weighted visual center of the logo within its content box.
	 * Darker/more opaque pixels carry more weight.
	 *
	 * @return array{offsetX: float, offsetY: float}
	 */
	private static function calculateVisualCenter(array $pixels, int $width, int $height, array $contentBox, int $threshold, bool $alphaOnly = false, array $bg = ['r' => 255, 'g' => 255, 'b' => 255]): array
	{
		$totalWeight = 0.0;
		$weightedX = 0.0;
		$weightedY = 0.0;

		$bx = $contentBox['x'];
		$by = $contentBox['y'];
		$bw = $contentBox['width'];
		$bh = $contentBox['height'];

		for ($y = 0; $y < $bh; $y++) {
			for ($x = 0; $x < $bw; $x++) {
				$i = (($by + $y) * $width + ($bx + $x)) * 4;

				$r = $pixels[$i] & 0xFF;
				$g = $pixels[$i + 1] & 0xFF;
				$b = $pixels[$i + 2] & 0xFF;
				$a = $pixels[$i + 3] & 0xFF;

				if (!static::isContentPixel($r, $g, $b, $a, $threshold, $alphaOnly, $bg)) {
					continue;
				}

				if ($alphaOnly) {
					$weight = $a / 255;
				} else {
					$dr = $r - $bg['r'];
					$dg = $g - $bg['g'];
					$db = $b - $bg['b'];
					$colorDistance = sqrt($dr * $dr + $dg * $dg + $db * $db);
					$weight = sqrt($colorDistance) * ($a / 255);
				}
				$totalWeight += $weight;
				$weightedX += ($x + 0.5) * $weight;
				$weightedY += ($y + 0.5) * $weight;
			}
		}

		if ($totalWeight === 0.0) {
			return ['offsetX' => 0.0, 'offsetY' => 0.0];
		}

		return [
			'offsetX' => $weightedX / $totalWeight - $bw / 2,
			'offsetY' => $weightedY / $totalWeight - $bh / 2,
		];
	}

	/**
	 * Measure how solid/dense the logo is within its content box.
	 * Returns 0-1 where higher values mean denser/more solid logos.
	 */
	private static function measurePixelDensity(array $pixels, int $width, int $height, array $contentBox, int $threshold, bool $alphaOnly = false): float
	{
		$bx = $contentBox['x'];
		$by = $contentBox['y'];
		$bw = $contentBox['width'];
		$bh = $contentBox['height'];
		$totalPixels = $bw * $bh;

		if ($totalPixels === 0) {
			return 0.5;
		}

		$filledPixels = 0;
		$totalOpacity = 0.0;

		for ($y = 0; $y < $bh; $y++) {
			for ($x = 0; $x < $bw; $x++) {
				$i = (($by + $y) * $width + ($bx + $x)) * 4;

				$r = $pixels[$i] & 0xFF;
				$g = $pixels[$i + 1] & 0xFF;
				$b = $pixels[$i + 2] & 0xFF;
				$a = $pixels[$i + 3] & 0xFF;

				if (static::isContentPixel($r, $g, $b, $a, $threshold, $alphaOnly)) {
					$filledPixels++;
					$totalOpacity += $a / 255;
				}
			}
		}

		if ($filledPixels === 0) {
			return 0.0;
		}

		return ($filledPixels / $totalPixels) * ($totalOpacity / $filledPixels);
	}
}
