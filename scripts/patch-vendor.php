<?php
/**
 * Patches vendor/wp-phpunit/wp-phpunit to be compatible with PHPUnit 10+.
 *
 * wp-phpunit/wp-phpunit ≤7.0.0 calls PHPUnit\Util\Test::parseTestMethodAnnotations()
 * which was removed in PHPUnit 10. This script replaces the incompatible expectDeprecated()
 * implementation with a PHPUnit-10/11 safe version.
 *
 * Run via composer post-install-cmd / post-update-cmd.
 */

$file = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit/includes/abstract-testcase.php';

if (!file_exists($file)) {
    echo "patch-vendor.php: wp-phpunit abstract-testcase.php not found, skipping.\n";
    exit(0);
}

$content = file_get_contents($file);

$needle = 'if ( method_exists( $this, \'getAnnotations\' ) ) {
			// PHPUnit < 9.5.0.
			$annotations = $this->getAnnotations();
		} else {
			// PHPUnit >= 9.5.0.
			$annotations = \PHPUnit\Util\Test::parseTestMethodAnnotations(
				static::class,
				$this->getName( false )
			);
		}

		foreach ( array( \'class\', \'method\' ) as $depth ) {
			if ( ! empty( $annotations[ $depth ][\'expectedDeprecated\'] ) ) {
				$this->expected_deprecated = array_merge(
					$this->expected_deprecated,
					$annotations[ $depth ][\'expectedDeprecated\']
				);
			}

			if ( ! empty( $annotations[ $depth ][\'expectedIncorrectUsage\'] ) ) {
				$this->expected_doing_it_wrong = array_merge(
					$this->expected_doing_it_wrong,
					$annotations[ $depth ][\'expectedIncorrectUsage\']
				);
			}
		}';

$replacement = '// PHPUnit 10+ removed \PHPUnit\Util\Test::parseTestMethodAnnotations() and getAnnotations().
		// Annotation-based @expectedDeprecated/@expectedIncorrectUsage are not used in this plugin\'s
		// tests, so we skip annotation parsing entirely for PHPUnit 10+ compatibility.
		if ( method_exists( $this, \'getAnnotations\' ) ) {
			// PHPUnit < 9.5.0.
			$annotations = $this->getAnnotations();
			foreach ( array( \'class\', \'method\' ) as $depth ) {
				if ( ! empty( $annotations[ $depth ][\'expectedDeprecated\'] ) ) {
					$this->expected_deprecated = array_merge(
						$this->expected_deprecated,
						$annotations[ $depth ][\'expectedDeprecated\']
					);
				}

				if ( ! empty( $annotations[ $depth ][\'expectedIncorrectUsage\'] ) ) {
					$this->expected_doing_it_wrong = array_merge(
						$this->expected_doing_it_wrong,
						$annotations[ $depth ][\'expectedIncorrectUsage\']
					);
				}
			}
		}
		// PHPUnit >= 10.0: annotation-based @expectedDeprecated is not supported; skip.';

if (str_contains($content, $needle)) {
    $patched = str_replace($needle, $replacement, $content);
    file_put_contents($file, $patched);
    echo "patch-vendor.php: Patched wp-phpunit abstract-testcase.php for PHPUnit 10+ compatibility.\n";
} elseif (str_contains($content, 'PHPUnit 10+ removed')) {
    echo "patch-vendor.php: Already patched, skipping.\n";
} else {
    echo "patch-vendor.php: Target code not found in abstract-testcase.php — may need manual review.\n";
}
