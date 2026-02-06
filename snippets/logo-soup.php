<?php

/**
 * Logo soup - visually harmonized inline logo strip.
 * Server-side port of react-logo-soup.
 *
 * @var iterable<Kirby\Cms\File> $logos
 * @var float|null $baseSize Target size for normalization (default 48)
 * @var float|null $scaleFactor Aspect ratio weight 0-1 (default 0.5)
 * @var float|null $densityFactor Density compensation 0-1 (default 0.5)
 * @var int|null $gap Gap between logos in px (default 28)
 * @var string|null $alignBy Alignment: 'bounds', 'visual-center', 'visual-center-x', 'visual-center-y' (default 'visual-center-y')
 * @var string|array|null $class Additional classes for the container
 */

$baseSize ??= 48;
$scaleFactor ??= 0.5;
$densityFactor ??= 0.5;
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
		<?php $norm = $logo->logoNormalize($baseSize, $scaleFactor, $densityFactor) ?>
		<?php
		$transform = null;
		if ($alignBy !== 'bounds') {
			$ox = ($alignBy === 'visual-center' || $alignBy === 'visual-center-x') ? $norm['offsetX'] : 0;
			$oy = ($alignBy === 'visual-center' || $alignBy === 'visual-center-y') ? $norm['offsetY'] : 0;
			if (abs($ox) > 0.5 || abs($oy) > 0.5) {
				$transform = "transform: translate({$ox}px, {$oy}px);";
			}
		}
		?>
		<span style="display: inline-block; vertical-align: middle; padding: <?= $halfGap ?>px">
			<img <?= attr([
				'src' => $logo->url(),
				'alt' => $logo->alt()->value(),
				'width' => $norm['width'],
				'height' => $norm['height'],
				'style' => 'display: block; object-fit: contain;' . ($transform ? ' ' . $transform : ''),
			]) ?>>
		</span>
	<?php endforeach ?>
</div>
