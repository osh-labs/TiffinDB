<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * M7-WP4/WP5 security review: the typed project name on the deletion
 * page is compared after trimming, ignoring ASCII case only (M7 spec
 * section 6.2 step 1). Inner white space, look-alike letters, invisible
 * characters, and non-ASCII case variants are refused through the
 * routes, and the project stays. Synthetic data only.
 */
#[CoversNothing]
final class M7Wp45ReviewTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const NAME = 'Café Spare Parts';

    private CoreFixture $core;
    private Operations $ops;
    private Kernel $kernel;
    private string $project;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $this->ops = $this->core->operations();
        $services = new FakeServices();
        $services->operations = $this->ops;
        $actor = $this->admin();
        $services->sessions[self::ADMIN] = new SignedIn(
            $actor,
            new SessionRecord(str_repeat('0', 64), $actor->userId, FakeServices::SESSION_CSRF, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'),
            false,
        );
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $services);
        $this->project = $this->ops->projects()->create($actor, self::NAME, 'Items')->key;
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    /** @return array<string, array{0: string}> */
    public static function refusedNames(): array
    {
        return [
            'double inner space' => ['Café  Spare Parts'],
            'inner spaces removed' => ['CaféSpareParts'],
            'tab for a space' => ["Café\tSpare Parts"],
            'no-break space inside' => ["Café\u{00A0}Spare Parts"],
            'zero-width space inside' => ["Café Spare\u{200B} Parts"],
            'right-to-left override' => ["Café Spare Parts\u{202E}"],
            'Cyrillic a' => ["C\u{0430}fé Spare Parts"],
            'decomposed e acute' => ["Cafe\u{0301} Spare Parts"],
            'non-ASCII case variant' => ['CAFÉ SPARE PARTS'],
            'fullwidth letters' => ['Ｃａｆé Spare Parts'],
            'one letter short' => ['Café Spare Part'],
        ];
    }

    #[DataProvider('refusedNames')]
    public function testALookAlikeNameIsRefused(string $typed): void
    {
        $response = $this->post(['confirm_name' => $typed, 'expected' => '0']);

        self::assertSame(422, $response->status);
        self::assertTrue($this->exists(), 'The project stays.');
    }

    public function testTrimmedAsciiCaseVariantIsAccepted(): void
    {
        $response = $this->post(['confirm_name' => " \u{00A0}cAFé sPARE pARTS\t ", 'expected' => '0']);

        self::assertSame(303, $response->status);
        self::assertFalse($this->exists());
    }

    private function admin(): Actor
    {
        return $this->core->actor('admin');
    }

    private function exists(): bool
    {
        foreach ($this->ops->projects()->listFor($this->admin()) as $summary) {
            if ($summary->project->key === $this->project) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $post */
    private function post(array $post): Response
    {
        $post['_csrf'] = FakeServices::SESSION_CSRF;
        [$response, $log] = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', '/projects/' . $this->project . '/delete', $post, ['tdb_session' => self::ADMIN]));
        self::assertNotSame(500, $response->status, $log);

        return $response;
    }
}
