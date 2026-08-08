<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use tobimori\LogoSoup;

$kirbyAutoload = getenv('KIRBY_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

if (file_exists($kirbyAutoload) === false) {
	fwrite(STDERR, "Set KIRBY_AUTOLOAD to a Composer autoloader that contains Kirby 5.\n");
	exit(1);
}

require $kirbyAutoload;
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../index.php';

$root = sys_get_temp_dir() . '/kirby-logo-soup-tests-' . getmypid();
foreach (['content', 'media', 'plugins', 'site', 'cache'] as $directory) {
	mkdir($root . '/' . $directory, 0777, true);
}

register_shutdown_function(static function () use ($root): void {
	if (is_dir($root)) {
		dirRemove($root);
	}
});

$kirby = new App([
	'roots' => [
		'index' => $root,
		'content' => $root . '/content',
		'media' => $root . '/media',
		'plugins' => $root . '/plugins',
		'site' => $root . '/site',
		'cache' => $root . '/cache',
	],
	'urls' => [
		'index' => 'https://example.test',
		'media' => 'https://example.test/media',
	],
	'options' => [
		'tobimori.logo-soup.samplePixelBudget' => 50_000,
		'tobimori.logo-soup.contrastThreshold' => 10,
	],
]);

copy(__DIR__ . '/fixtures/tight.svg', $root . '/content/tight.svg');
copy(__DIR__ . '/fixtures/whitespace.svg', $root . '/content/whitespace.svg');
copy(__DIR__ . '/fixtures/visual-center.svg', $root . '/content/visual-center.svg');
createTransparentPng($root . '/content/transparent.png', 100, 80, [20, 10, 40, 30]);
createOpaquePng($root . '/content/opaque.png', 120, 90, [30, 20, 60, 45]);
createTransparentPng($root . '/content/replace.png', 100, 80, [10, 10, 20, 20]);
createTransparentPng($root . '/content/downsampled.png', 1000, 800, [200, 100, 400, 300]);

$tight = fixtureFile($kirby, $root, 'tight.svg');
$whitespace = fixtureFile($kirby, $root, 'whitespace.svg');
$visualCenter = fixtureFile($kirby, $root, 'visual-center.svg');
$transparent = fixtureFile($kirby, $root, 'transparent.png');
$opaque = fixtureFile($kirby, $root, 'opaque.png');
$replace = fixtureFile($kirby, $root, 'replace.png');
$downsampled = fixtureFile($kirby, $root, 'downsampled.png');

$tests = [];
$tests['tight SVG has tight content bounds'] = static function () use ($tight): void {
	$metrics = requiredMetrics($tight);
	assertSame([0, 0, 100, 40], array_values($metrics['contentBox']));
};
$tests['SVG whitespace is detected in source coordinates'] = static function () use ($whitespace): void {
	$metrics = requiredMetrics($whitespace);
	assertSame([200, 100], [$metrics['width'], $metrics['height']]);
	assertSame([50, 30, 100, 40], array_values($metrics['contentBox']));
	assertSame([200, 100], [$metrics['sampleWidth'], $metrics['sampleHeight']]);
	assertSame([1, 1], [$metrics['sampleScaleX'], $metrics['sampleScaleY']]);
};
$tests['transparent PNG whitespace is detected'] = static function () use ($transparent): void {
	assertSame([20, 10, 40, 30], array_values(requiredMetrics($transparent)['contentBox']));
};
$tests['sample coordinates map back to source pixels'] = static function () use ($downsampled): void {
	$metrics = requiredMetrics($downsampled);
	assertSame([1000, 800], [$metrics['width'], $metrics['height']]);
	assertTrue($metrics['sampleWidth'] < $metrics['width']);
	assertSame(
		$metrics['width'] / $metrics['sampleWidth'],
		$metrics['sampleScaleX'],
	);
	assertSame(
		$metrics['height'] / $metrics['sampleHeight'],
		$metrics['sampleScaleY'],
	);
	assertSame([196, 96, 408, 308], array_values($metrics['contentBox']));
};
$tests['opaque raster background is detected'] = static function () use ($opaque): void {
	$metrics = requiredMetrics($opaque);
	assertSame([30, 20, 60, 45], array_values($metrics['contentBox']));
	assertTrue(isset($metrics['backgroundLuminance']));
	assertTrue($metrics['backgroundLuminance'] > 0.99);
};
$tests['cropToContent false keeps source and inline dimensions'] = static function () use ($kirby, $transparent): void {
	$html = renderSoup($kirby, $transparent, false);
	assertContains('src="' . $transparent->url() . '"', $html);
	assertContains('<img ', $html);
	assertContains('width: ', $html);
	assertContains('px; height: ', $html);
};
$tests['cropToContent true uses an SVG content viewport'] = static function () use ($kirby, $whitespace): void {
	$html = renderSoup($kirby, $whitespace, true);
	assertContains('<svg ', $html);
	assertContains('viewBox="50 30 100 40"', $html);
	assertContains('href="' . $whitespace->url() . '"', $html);
	assertContains('role="img"', $html);
	assertContains('aria-label="Fixture logo"', $html);
};
$tests['cropToContent true creates an exact Kirby raster thumb'] = static function () use ($transparent): void {
	$metrics = requiredMetrics($transparent);
	$thumb = LogoSoup::cropRaster($transparent, $metrics);
	$thumb->save();
	$image = new Imagick($thumb->root());
	assertSame([40, 30], [$image->getImageWidth(), $image->getImageHeight()]);
	assertContains('/media/', $thumb->url());
	assertContains('crop-logo-soup-20-10-40-30', $thumb->url());
};
$tests['PNG alpha is preserved by the cropped thumb'] = static function () use ($root, $kirby): void {
	createTransparentEllipse($root . '/content/alpha.png');
	$file = fixtureFile($kirby, $root, 'alpha.png');
	$thumb = LogoSoup::cropRaster($file, requiredMetrics($file));
	$thumb->save();
	$image = new Imagick($thumb->root());
	assertTrue($image->getImageAlphaChannel());
	assertSame(0, $image->getImagePixelColor(0, 0)->getColor()['a']);
};
$tests['visual-center transform remains on a cropped SVG'] = static function () use ($kirby, $visualCenter): void {
	$html = renderSoup($kirby, $visualCenter, true, 'visual-center');
	assertContains('<svg ', $html);
	assertContains('transform: translate(', $html);
};
$tests['file replacement invalidates measurement cache'] = static function () use ($kirby, $root, $replace): void {
	$before = requiredMetrics($replace)['contentBox'];
	createTransparentPng($root . '/replacement.png', 100, 80, [50, 30, 30, 25]);
	$kirby->trigger('file.replace:before', [
		'file' => $replace,
		'upload' => $replace->asset($root . '/replacement.png'),
	]);
	copy($root . '/replacement.png', $replace->root());
	$after = requiredMetrics($replace)['contentBox'];
	assertSame([10, 10, 20, 20], array_values($before));
	assertSame([50, 30, 30, 25], array_values($after));
};
$tests['inline dimensions override Tailwind-style image height'] = static function () use ($kirby, $tight): void {
	$html = '<style>img { height: auto; }</style>' . renderSoup($kirby, $tight, false);
	assertContains('img { height: auto; }', $html);
	assertMatches('/<img[^>]+style="[^"]*width: \d+px; height: \d+px;/', $html);
};

$failures = 0;
foreach ($tests as $name => $test) {
	try {
		$test();
		echo "PASS  {$name}\n";
	} catch (Throwable $error) {
		$failures++;
		fwrite(STDERR, "FAIL  {$name}\n      {$error->getMessage()}\n");
	}
}

printf("\n%d tests, %d failures\n", count($tests), $failures);
exit($failures === 0 ? 0 : 1);

function fixtureFile(App $kirby, string $root, string $filename): File
{
	return new File([
		'filename' => $filename,
		'parent' => $kirby->site(),
		'root' => $root . '/content/' . $filename,
		'content' => ['alt' => 'Fixture logo'],
	]);
}

function requiredMetrics(File $file): array
{
	return $file->logoMetrics() ?? throw new RuntimeException('Image measurement failed for ' . $file->filename());
}

function renderSoup(App $kirby, File $file, bool $cropToContent, string $alignBy = 'visual-center-y'): string
{
	return (string) $kirby->snippet('logo-soup', [
		'logos' => [$file],
		'cropToContent' => $cropToContent,
		'alignBy' => $alignBy,
	]);
}

function createTransparentPng(string $root, int $width, int $height, array $box): void
{
	$image = new Imagick();
	$image->newImage($width, $height, new ImagickPixel('transparent'), 'png');
	$draw = new ImagickDraw();
	$draw->setFillColor('black');
	$draw->rectangle($box[0], $box[1], $box[0] + $box[2] - 1, $box[1] + $box[3] - 1);
	$image->drawImage($draw);
	$image->writeImage($root);
}

function createOpaquePng(string $root, int $width, int $height, array $box): void
{
	$image = new Imagick();
	$image->newImage($width, $height, new ImagickPixel('white'), 'png');
	$draw = new ImagickDraw();
	$draw->setFillColor('black');
	$draw->rectangle($box[0], $box[1], $box[0] + $box[2] - 1, $box[1] + $box[3] - 1);
	$image->drawImage($draw);
	$image->setImageAlphaChannel(Imagick::ALPHACHANNEL_OFF);
	$image->writeImage($root);
}

function createTransparentEllipse(string $root): void
{
	$image = new Imagick();
	$image->newImage(100, 80, new ImagickPixel('transparent'), 'png');
	$draw = new ImagickDraw();
	$draw->setFillColor('black');
	$draw->ellipse(50, 40, 25, 20, 0, 360);
	$image->drawImage($draw);
	$image->writeImage($root);
}

function assertSame(mixed $expected, mixed $actual): void
{
	if ($expected !== $actual) {
		throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function assertTrue(bool $condition): void
{
	if ($condition === false) {
		throw new RuntimeException('Expected true, got false');
	}
}

function assertContains(string $needle, string $haystack): void
{
	if (str_contains($haystack, $needle) === false) {
		throw new RuntimeException('Missing text: ' . $needle);
	}
}

function assertMatches(string $pattern, string $value): void
{
	if (preg_match($pattern, $value) !== 1) {
		throw new RuntimeException('Pattern did not match: ' . $pattern);
	}
}

function dirRemove(string $root): void
{
	$items = array_diff(scandir($root), ['.', '..']);
	foreach ($items as $item) {
		$path = $root . '/' . $item;
		is_dir($path) ? dirRemove($path) : unlink($path);
	}
	rmdir($root);
}
