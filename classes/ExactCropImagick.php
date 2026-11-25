<?php

namespace tobimori;

use Imagick as Image;
use Kirby\Image\Darkroom\Imagick;

class ExactCropImagick extends Imagick
{
	protected function resize(Image $image, array $options): Image
	{
		if (isset($options['logoSoupCrop']) === false) {
			return parent::resize($image, $options);
		}

		$crop = $options['logoSoupCrop'];
		$x = max(0, (int)($crop['x'] ?? 0));
		$y = max(0, (int)($crop['y'] ?? 0));
		$width = min(
			max(1, (int)($crop['width'] ?? 0)),
			$image->getImageWidth() - $x
		);
		$height = min(
			max(1, (int)($crop['height'] ?? 0)),
			$image->getImageHeight() - $y
		);

		// Kirby calls resize() once per frame with the iterator on that frame, so a loop over
		// the frames here would reset the iterator and never let Kirby's loop finish
		$image->cropImage($width, $height, $x, $y);
		$image->setImagePage(0, 0, 0, 0);

		if ($width !== (int)$options['width'] || $height !== (int)$options['height']) {
			$image->resizeImage(
				(int)$options['width'],
				(int)$options['height'],
				Image::FILTER_LANCZOS,
				1,
				true
			);
		}

		return $image;
	}
}
