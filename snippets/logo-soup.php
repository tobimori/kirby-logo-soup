<?php

/**
 * Logo soup - visually harmonized inline logo strip.
 * Server-side port of react-logo-soup.
 *
 * @var iterable<Kirby\Cms\File> $logos
 * @var float|null $baseSize Target size for normalization (default 48)
 * @var float|null $scaleFactor Aspect ratio weight 0-1 (default 0.5)
 * @var float|null $densityFactor Density compensation 0-1 (default 0.5)
 * @var bool|null $cropToContent Crop each source to its detected content bounds (default false)
 * @var int|null $gap Gap between logos in px (default 28)
 * @var string|null $alignBy Alignment: 'bounds', 'visual-center', 'visual-center-x', 'visual-center-y' (default 'visual-center-y')
 * @var string|array|null $class Additional classes for the container
 */

$baseSize ??= 48;
$scaleFactor ??= 0.5;
$densityFactor ??= 0.5;
$cropToContent ??= false;
$gap ??= 28;
$alignBy ??= 'visual-center-y';
$class ??= null;

$halfGap = $gap / 2;
?>

<div <?= attr([
	'class' => $class,
	'style' => 'text-align: center; text-wrap: balance',
]) ?>>
	<?php foreach ($logos as $logo) : ?>
		<?php $metrics = $logo->logoMetrics() ?? [] ?>
		<?php $norm = \tobimori\LogoSoup::normalize($metrics, $baseSize, $scaleFactor, $densityFactor) ?>
		<?php
		$transform = null;
		if ($alignBy !== 'bounds') {
			$ox = ($alignBy === 'visual-center' || $alignBy === 'visual-center-x') ? $norm['offsetX'] : 0;
			$oy = ($alignBy === 'visual-center' || $alignBy === 'visual-center-y') ? $norm['offsetY'] : 0;
			if (abs($ox) > 0.5 || abs($oy) > 0.5) {
				$transform = "transform: translate({$ox}px, {$oy}px);";
			}
		}
		$style = "display: block; object-fit: contain; width: {$norm['width']}px; height: {$norm['height']}px;" . ($transform ? ' ' . $transform : '');
		$isSvgCrop = $cropToContent && strtolower($logo->extension()) === 'svg' && isset($metrics['contentBox']);
		?>
		<span style="display: inline-block; vertical-align: middle; padding: <?= $halfGap ?>px">
			<?php if ($isSvgCrop) : ?>
				<svg viewBox="<?= implode(' ', array_map('intval', $metrics['contentBox'])) ?>" <?= attr([
					'xmlns' => 'http://www.w3.org/2000/svg',
					'width' => $norm['width'],
					'height' => $norm['height'],
					'style' => $style,
					'role' => $logo->alt()->isNotEmpty() ? 'img' : null,
					'aria-label' => $logo->alt()->isNotEmpty() ? $logo->alt()->value() : null,
					'aria-hidden' => $logo->alt()->isEmpty() ? 'true' : null,
					'focusable' => 'false',
				]) ?>>
					<image preserveAspectRatio="none" <?= attr([
						'href' => $logo->url(),
						'x' => 0,
						'y' => 0,
						'width' => $metrics['width'],
						'height' => $metrics['height'],
						'aria-hidden' => 'true',
					]) ?> />
				</svg>
			<?php else : ?>
				<?php $source = $cropToContent ? \tobimori\LogoSoup::cropRaster($logo, $metrics) : $logo ?>
				<img <?= attr([
					'src' => $source->url(),
					'alt' => $logo->alt()->value(),
					'width' => $norm['width'],
					'height' => $norm['height'],
					'style' => $style,
				]) ?>>
			<?php endif ?>
		</span>
	<?php endforeach ?>
</div>
