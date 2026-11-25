<?php

// Kirby magic methods for Mago, never loaded. Mago can take a stub as the class declaration, so the stubs repeat the real class headers

namespace Kirby\Cms {
	use Kirby\Content\Field;
	use tobimori\LogoSoup;

	/**
	 * @psalm-import-type Metrics from LogoSoup
	 * @psalm-import-type Normalized from LogoSoup
	 * @method string extension()
	 * @method int width()
	 * @method int height()
	 * @method Field alt()
	 * @method Metrics|null logoMetrics()
	 * @method Normalized logoNormalize(float $baseSize = 48, float $scaleFactor = 0.5, float $densityFactor = 0.5)
	 */
	class File extends ModelWithContent
	{
		use FileActions;
		use FileModifications;
		use HasMethods;
		use HasSiblings;
		use \Kirby\Filesystem\IsFile;
		public static array $methods = [];
	}
}
