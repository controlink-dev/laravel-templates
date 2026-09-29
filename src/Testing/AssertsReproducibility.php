<?php

namespace Controlink\Templates\Testing;

use Controlink\Templates\Exceptions\ImmutableVersionException;
use Controlink\Templates\Toolkit\Documents;
use Controlink\Templates\Versions\CanonicalHash;

/**
 * Reproducibility conformance-kit: mount it in the consumer's own test case and run the
 * assertions against the consumer's own version model and the toolkit facade.
 *
 * The toolkit leaves the reproducibility and immutability discipline to each consumer;
 * this kit is the safety net that proves the discipline was not silently dropped. The
 * anchor is the input hash (asserted hard); the PDF hash is best-effort (never failed).
 *
 * Requires a PHPUnit TestCase host (uses its assert methods and, for the render, the
 * Laravel container via `app()`).
 */
trait AssertsReproducibility
{
    /**
     * The input hash is deterministic and sensitive: identical inputs hash identically
     * (also regardless of key order) and any change of layout or geometry changes it.
     *
     * @param  array<string, mixed>  $projectData
     * @param  array<string, mixed>|null  $geometry
     */
    protected function assertInputHashStable(array $projectData, ?array $geometry = null): void
    {
        $documents = app(Documents::class);

        $first = $documents->inputHash($projectData, $geometry);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertSame($first, $documents->inputHash($projectData, $geometry), 'The input hash is not deterministic.');
        $this->assertSame($first, CanonicalHash::of($projectData, $geometry), 'The facade diverges from the canonical hash.');

        $this->assertNotSame(
            $first,
            $documents->inputHash($projectData + ['__conformance_probe' => 1], $geometry),
            'A change in the layout did not change the input hash.',
        );

        $this->assertNotSame(
            $first,
            $documents->inputHash($projectData, ($geometry ?? []) + ['__conformance_probe' => 1]),
            'A change in the geometry did not change the input hash.',
        );
    }

    /**
     * A persisted active/archived version refuses to change its layout.
     *
     * @param  callable(): \Illuminate\Database\Eloquent\Model  $makeIssuableVersion  returns a persisted version in an issuable state
     */
    protected function assertVersionImmutableWhenIssuable(callable $makeIssuableVersion): void
    {
        $version = $makeIssuableVersion();

        $this->assertTrue($version->exists, 'The callable must return a persisted version.');

        $before = (string) $version->getOriginal('html');

        try {
            $version->forceFill(['html' => $before.'<!-- tampered -->'])->save();
            $this->fail('An issuable version accepted a layout change.');
        } catch (ImmutableVersionException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, (string) $version->fresh()->html, 'The frozen layout changed in the database.');
    }

    /**
     * Rendering the same input twice reproduces: the input hash (the anchor) matches
     * hard; the normalized PDF hash is best-effort.
     *
     * @param  array{project_data?: array<string, mixed>, html: string, css?: string}  $layout
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $geometry
     * @param  list<array<string, mixed>>  $fields
     * @return bool whether the normalized PDF hash also matched (informational, never a failure)
     */
    protected function assertReRenderReproduces(array $layout, array $data, array $geometry = [], string $css = '', array $fields = []): bool
    {
        $documents = app(Documents::class);
        $projectData = (array) ($layout['project_data'] ?? []);

        $anchorBefore = $documents->inputHash($projectData, $geometry);
        $first        = $documents->renderForActivation($layout, $data, $geometry, $css, $fields);
        $second       = $documents->renderForActivation($layout, $data, $geometry, $css, $fields);
        $anchorAfter  = $documents->inputHash($projectData, $geometry);

        $this->assertStringStartsWith('%PDF', $first['pdf']);
        $this->assertStringStartsWith('%PDF', $second['pdf']);
        $this->assertSame($anchorBefore, $anchorAfter, 'The input hash moved across renders.');

        // Best-effort: the engine may embed non-deterministic bytes beyond the dates.
        return $first['hash'] === $second['hash'];
    }
}
