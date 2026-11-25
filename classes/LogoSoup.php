<?php

namespace tobimori;

use Imagick;
use ImagickPixel;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\FileVersion;
use Kirby\Filesystem\Asset;
use Kirby\Filesystem\F;
use Kirby\Filesystem\Filename;
use RuntimeException;
use Throwable;

/**
 * @psalm-type ContentBox = array{x: int, y: int, width: int, height: int}
 * @psalm-type Metrics = array{width: int, height: int, sampleWidth: int, sampleHeight: int, sampleScaleX: float|int, sampleScaleY: float|int, contentBox: ContentBox, contentRatio: float|int, pixelDensity: float, visualCenter: array{x: float|int, y: float|int, offsetX: float, offsetY: float}, visualCenterX: float, visualCenterY: float, backgroundLuminance?: float|int}
 * @psalm-type PartialMetrics = array{width?: int, height?: int, contentBox?: ContentBox, contentRatio?: float|int, pixelDensity?: float, visualCenterX?: float, visualCenterY?: float, backgroundLuminance?: float|int, ...}
 * @psalm-type Normalized = array{width: int, height: int, offsetX: float, offsetY: float}
 */
class LogoSoup
{
	private const CACHE_VERSION = 3;

	/** @return Metrics|null */
	public static function analyze(File $file): array|null
	{
		$kirby = App::instance();
		[$pixelBudget, $threshold] = static::measurementOptions();
		$id = static::getCacheId($file, $pixelBudget, $threshold);
		$cache = $kirby->cache('tobimori.logo-soup');

		/** @var Metrics|null $cached */
		$cached = $cache->get($id);
		if ($cached !== null) {
			return $cached;
		}

		try {
			[
				$pixels,
				$sampleWidth,
				$sampleHeight,
				$sourceWidth,
				$sourceHeight
			] = static::extractPixels($file, $pixelBudget);
			$metrics = static::measurePixels(
				$pixels,
				$sampleWidth,
				$sampleHeight,
				$sourceWidth,
				$sourceHeight,
				$threshold
			);
			$cache->set($id, $metrics);

			return $metrics;
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * @param PartialMetrics $metrics
	 * @return Normalized
	 */
	public static function normalize(
		array $metrics,
		float $baseSize = 48,
		float $scaleFactor = 0.5,
		float $densityFactor = 0.5
	): array {
		if (isset($metrics['contentRatio']) === false) {
			return [
				'width' => (int)$baseSize,
				'height' => (int)$baseSize,
				'offsetX' => 0.0,
				'offsetY' => 0.0
			];
		}

		$ratio = $metrics['contentRatio'];
		$width = ($ratio ** $scaleFactor) * $baseSize;
		$height = $width / $ratio;

		if (isset($metrics['backgroundLuminance'])) {
			$irradiationScale = 1 - ((1 - $metrics['backgroundLuminance']) * ($metrics['pixelDensity'] ?? 0.5) * 0.08);
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
			'width' => (int)round($width),
			'height' => (int)round($height),
			'offsetX' => round(-($metrics['visualCenterX'] ?? 0) * $width, 1),
			'offsetY' => round(-($metrics['visualCenterY'] ?? 0) * $height, 1)
		];
	}

	/** @param PartialMetrics $metrics */
	public static function cropRaster(File $file, array $metrics): FileVersion|File|Asset
	{
		if (
			strtolower($file->extension()) === 'svg' ||
			isset($metrics['contentBox']) === false
		) {
			return $file;
		}

		$box = $metrics['contentBox'];
		$x = max(0, $box['x']);
		$y = max(0, $box['y']);
		$width = max(1, $box['width']);
		$height = max(1, $box['height']);
		$sourceWidth = max(1, $metrics['width'] ?? $file->width());
		$sourceHeight = max(1, $metrics['height'] ?? $file->height());
		$width = min($width, $sourceWidth - $x);
		$height = min($height, $sourceHeight - $y);

		if ($x === 0 && $y === 0 && $width === $sourceWidth && $height === $sourceHeight) {
			return $file;
		}

		return $file->thumb([
			'width' => $width,
			'height' => $height,
			'crop' => implode('-', ['logo-soup', $x, $y, $width, $height]),
			'format' => match (strtolower($file->extension())) {
				'jpeg' => 'jpg',
				default => strtolower($file->extension())
			},
			'logoSoupCrop' => compact('x', 'y', 'width', 'height')
		]);
	}

	public static function createThumb(
		App $kirby,
		string $src,
		string $dst,
		array $options
	): string {
		if (isset($options['logoSoupCrop']) === false) {
			/** @var \Closure(App, string, string, array): string $thumb */
			$thumb = $kirby->nativeComponent('thumb');
			return $thumb($kirby, $src, $dst, $options);
		}

		/** @var array<string, mixed> $thumbs */
		$thumbs = $kirby->option('thumbs', []);
		$darkroom = new ExactCropImagick($thumbs);
		$options = $darkroom->preprocess($src, $options);
		$root = (new Filename($src, $dst, $options))->toString();
		$extension = F::extension($root);
		$tempRoot = F::dirname($root) . '/' . F::name($root) . '.tmp-' . uniqid();

		if ($extension !== '') {
			$tempRoot .= '.' . $extension;
		}

		F::copy($src, $tempRoot, true);

		try {
			$darkroom->process($tempRoot, $options);
			F::move($tempRoot, $root, true);
		} catch (Throwable $error) {
			F::remove($tempRoot);
			throw $error;
		}

		return $root;
	}

	public static function clearCache(File $file): void
	{
		[$pixelBudget, $threshold] = static::measurementOptions();
		App::instance()
			->cache('tobimori.logo-soup')
			->remove(static::getCacheId($file, $pixelBudget, $threshold));
	}

	/** @return list{int, int} */
	private static function measurementOptions(): array
	{
		$kirby = App::instance();

		return [
			max(1, (int)$kirby->option('tobimori.logo-soup.samplePixelBudget', 2048)),
			max(0, (int)$kirby->option('tobimori.logo-soup.contrastThreshold', 10))
		];
	}

	private static function getCacheId(File $file, int $pixelBudget, int $threshold): string
	{
		return implode('-', [
			'v' . static::CACHE_VERSION,
			$pixelBudget,
			$threshold,
			// Kirby types File::uuid() as never null, but it is null with disabled UUIDs
			// @mago-expect analysis:redundant-nullsafe-operator,redundant-null-coalesce
			$file->uuid()?->id() ?? $file->id()
		]);
	}

	/** @return array{0: list<int>, 1: int, 2: int, 3: int, 4: int} */
	private static function extractPixels(File $file, int $pixelBudget): array
	{
		$imagick = new Imagick();

		if (strtolower($file->extension()) === 'svg') {
			$imagick->setBackgroundColor(new ImagickPixel('transparent'));
			$imagick->setResolution(96, 96);
		}

		$imagick->readImage($file->root() ?? throw new RuntimeException('File has no root'));
		$imagick->setIteratorIndex(0);

		if (strtolower($file->extension()) !== 'svg') {
			static::autoOrient($imagick);
			$imagick->setImagePage(0, 0, 0, 0);
		}

		if ($imagick->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
			$imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
		}

		if (!$imagick->getImageAlphaChannel()) {
			$imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
		}

		$sourceWidth = $imagick->getImageWidth();
		$sourceHeight = $imagick->getImageHeight();
		$sampleWidth = $sourceWidth;
		$sampleHeight = $sourceHeight;

		if (($sampleWidth * $sampleHeight) > $pixelBudget) {
			$scale = sqrt($pixelBudget / ($sampleWidth * $sampleHeight));
			$sampleWidth = max(1, (int)round($sampleWidth * $scale));
			$sampleHeight = max(1, (int)round($sampleHeight * $scale));
			$imagick->resizeImage($sampleWidth, $sampleHeight, Imagick::FILTER_LANCZOS, 1);
		}

		/** @var list<int> $pixels */
		$pixels = $imagick->exportImagePixels(0, 0, $sampleWidth, $sampleHeight, 'RGBA', Imagick::PIXEL_CHAR);
		$imagick->clear();
		$imagick->destroy();

		return [$pixels, $sampleWidth, $sampleHeight, $sourceWidth, $sourceHeight];
	}

	/**
	 * @param list<int> $pixels
	 * @return Metrics
	 */
	private static function measurePixels(
		array $pixels,
		int $sampleWidth,
		int $sampleHeight,
		int $sourceWidth,
		int $sourceHeight,
		int $threshold
	): array {
		[$alphaOnly, $background] = static::analyzePerimeter($pixels, $sampleWidth, $sampleHeight);
		$thresholdSquared = $threshold * $threshold * 3;
		$minX = $sampleWidth;
		$minY = $sampleHeight;
		$maxX = 0;
		$maxY = 0;
		$filledPixels = 0;
		$totalOpacity = 0.0;
		$totalWeight = 0.0;
		$weightedX = 0.0;
		$weightedY = 0.0;

		for ($y = 0; $y < $sampleHeight; $y++) {
			for ($x = 0; $x < $sampleWidth; $x++) {
				$i = (($y * $sampleWidth) + $x) * 4;
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
					$distanceSquared = ($dr * $dr) + ($dg * $dg) + ($db * $db);

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

		$scaleX = $sourceWidth / $sampleWidth;
		$scaleY = $sourceHeight / $sampleHeight;
		$base = [
			'width' => $sourceWidth,
			'height' => $sourceHeight,
			'sampleWidth' => $sampleWidth,
			'sampleHeight' => $sampleHeight,
			'sampleScaleX' => $scaleX,
			'sampleScaleY' => $scaleY
		];

		if ($minX > $maxX || $minY > $maxY) {
			return $base +
				[
					'contentBox' => [
						'x' => 0,
						'y' => 0,
						'width' => $sourceWidth,
						'height' => $sourceHeight
					],
					'contentRatio' => $sourceWidth / $sourceHeight,
					'pixelDensity' => 0.5,
					'visualCenter' => [
						'x' => $sourceWidth / 2,
						'y' => $sourceHeight / 2,
						'offsetX' => 0.0,
						'offsetY' => 0.0
					],
					'visualCenterX' => 0.0,
					'visualCenterY' => 0.0
				];
		}

		$contentX = (int)floor($minX * $scaleX);
		$contentY = (int)floor($minY * $scaleY);
		$contentWidth = min((int)ceil(($maxX + 1) * $scaleX), $sourceWidth) - $contentX;
		$contentHeight = min((int)ceil(($maxY + 1) * $scaleY), $sourceHeight) - $contentY;
		$centerX = $totalWeight > 0
			? ($weightedX / $totalWeight) * $scaleX
			: $contentX + ($contentWidth / 2);
		$centerY = $totalWeight > 0
			? ($weightedY / $totalWeight) * $scaleY
			: $contentY + ($contentHeight / 2);
		$offsetX = $centerX - $contentX - ($contentWidth / 2);
		$offsetY = $centerY - $contentY - ($contentHeight / 2);
		$metrics = $base +
		[
			'contentBox' => [
				'x' => $contentX,
				'y' => $contentY,
				'width' => $contentWidth,
				'height' => $contentHeight
			],
			'contentRatio' => $contentWidth / $contentHeight,
			'pixelDensity' => $totalOpacity / (255 * ($maxX - $minX + 1) * ($maxY - $minY + 1)),
			'visualCenter' => [
				'x' => $centerX,
				'y' => $centerY,
				'offsetX' => $offsetX,
				'offsetY' => $offsetY
			],
			'visualCenterX' => $offsetX / $contentWidth,
			'visualCenterY' => $offsetY / $contentHeight
		];

		if (!$alphaOnly) {
			$metrics['backgroundLuminance'] = (($background['r'] * 299) + ($background['g'] * 587) + ($background['b'] * 114)) / 255000;
		}

		return $metrics;
	}

	private static function autoOrient(Imagick $image): void
	{
		switch ($image->getImageOrientation()) {
			case Imagick::ORIENTATION_TOPRIGHT:
				$image->flopImage();
				break;
			case Imagick::ORIENTATION_BOTTOMRIGHT:
				$image->rotateImage(new ImagickPixel('transparent'), 180);
				break;
			case Imagick::ORIENTATION_BOTTOMLEFT:
				$image->flopImage();
				$image->rotateImage(new ImagickPixel('transparent'), 180);
				break;
			case Imagick::ORIENTATION_LEFTTOP:
				$image->flopImage();
				$image->rotateImage(new ImagickPixel('transparent'), -90);
				break;
			case Imagick::ORIENTATION_RIGHTTOP:
				$image->rotateImage(new ImagickPixel('transparent'), 90);
				break;
			case Imagick::ORIENTATION_RIGHTBOTTOM:
				$image->flopImage();
				$image->rotateImage(new ImagickPixel('transparent'), 90);
				break;
			case Imagick::ORIENTATION_LEFTBOTTOM:
				$image->rotateImage(new ImagickPixel('transparent'), -90);
				break;
		}

		$image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
	}

	/**
	 * @param list<int> $pixels
	 * @return array{0: bool, 1: array{r: int, g: int, b: int}}
	 */
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
			$key = (((($r >> 5) * 8) + ($g >> 5)) * 8) + ($b >> 5);
			$buckets[$key] ??= [0, 0, 0, 0];
			$buckets[$key][0]++;
			$buckets[$key][1] += $r;
			$buckets[$key][2] += $g;
			$buckets[$key][3] += $b;
		};

		for ($x = 0; $x < $width; $x++) {
			$sample($x);
			if ($height > 1) {
				$sample((($height - 1) * $width) + $x);
			}
		}

		for ($y = 1; $y < ($height - 1); $y++) {
			$sample($y * $width);
			if ($width > 1) {
				$sample(($y * $width) + $width - 1);
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
				'r' => (int)round($dominant[1] / $dominant[0]),
				'g' => (int)round($dominant[2] / $dominant[0]),
				'b' => (int)round($dominant[3] / $dominant[0])
			]
			: ['r' => 255, 'g' => 255, 'b' => 255];

		return [
			($opaque + $transparent) > 0 && $transparent > (($opaque + $transparent) * 0.1),
			$background
		];
	}
}
