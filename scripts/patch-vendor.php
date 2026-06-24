<?php
/**
 * Patches vendor/wp-phpunit/wp-phpunit to be compatible with PHPUnit 10+/11.
 *
 * Patch 1: wp-phpunit/wp-phpunit ≤7.0.0 calls PHPUnit\Util\Test::parseTestMethodAnnotations()
 * which was removed in PHPUnit 10. This script replaces the incompatible expectDeprecated()
 * implementation with a PHPUnit-10/11 safe version.
 *
 * Patch 2: PHPUnit 11 parses @deprecated doc-comment tags on methods as PHPUnit metadata and
 * emits a deprecation notice. The checkRequirements() method in abstract-testcase.php carries
 * such a tag. Remove it so PHPUnit 11 produces pristine output.
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
    $content = str_replace($needle, $replacement, $content);
    file_put_contents($file, $content);
    echo "patch-vendor.php: Patch 1 applied — fixed wp-phpunit abstract-testcase.php for PHPUnit 10+ compatibility.\n";
} elseif (str_contains($content, 'PHPUnit 10+ removed')) {
    echo "patch-vendor.php: Patch 1 already applied, skipping.\n";
} else {
    fwrite(STDERR, "patch-vendor.php: ERROR — patch 1 target code not found in abstract-testcase.php and it does not appear already-patched. Manual review required.\n");
    exit(1);
}

// Patch 2: Remove all PHPUnit-parsed annotation tags from checkRequirements() docblock.
// PHPUnit 11 parses any @tag in a method doc-comment as metadata and emits a
// "PHPUnit Deprecations: 1" notice for unrecognised/deprecated metadata. The
// checkRequirements() docblock contains @since, @deprecated, and mentions of @group in
// prose — all of which trigger this. Strip the docblock down to annotation-free prose.
// Re-read in case patch 1 just wrote the file.
$content = file_get_contents($file);
// All forms the docblock may appear in (original + any intermediate patched states).
$needle2_original = '	/**
	 * Allows tests to be skipped on single or multisite installs by using @group annotations.
	 *
	 * This is a custom extension of the PHPUnit requirements handling.
	 *
	 * @since 3.5.0
	 * @deprecated 5.9.0 This method has not been functional since PHPUnit 7.0.
	 */
	protected function checkRequirements()';
// Intermediate state written by a previous run of this script that still left @-tags in prose.
$needle2_intermediate = '	/**
	 * Allows tests to be skipped on single or multisite installs by using @group annotations.
	 *
	 * This is a custom extension of the PHPUnit requirements handling.
	 * Formerly @deprecated 5.9.0 — doc-comment tag removed so PHPUnit 11 does not emit metadata notice.
	 */
	protected function checkRequirements()';
// The correct final form: no @-tags anywhere in the docblock.
$replacement2 = '	/**
	 * Allows tests to be skipped on single or multisite installs by using group annotations.
	 * This is a custom extension of the PHPUnit requirements handling.
	 * (since 3.5.0; deprecated 5.9.0 — non-functional since PHPUnit 7.0; doc-tags stripped for PHPUnit 11)
	 */
	protected function checkRequirements()';

if (str_contains($content, $replacement2)) {
    echo "patch-vendor.php: Patch 2 already applied, skipping.\n";
} elseif (str_contains($content, $needle2_original)) {
    $content = str_replace($needle2_original, $replacement2, $content);
    file_put_contents($file, $content);
    echo "patch-vendor.php: Patch 2 applied — stripped annotation tags from checkRequirements() docblock.\n";
} elseif (str_contains($content, $needle2_intermediate)) {
    $content = str_replace($needle2_intermediate, $replacement2, $content);
    file_put_contents($file, $content);
    echo "patch-vendor.php: Patch 2 applied (updated from intermediate state) — stripped all annotation tags from checkRequirements() docblock.\n";
} else {
    fwrite(STDERR, "patch-vendor.php: ERROR — patch 2 target code not found in abstract-testcase.php and it does not appear already-patched. Manual review required.\n");
    exit(1);
}
