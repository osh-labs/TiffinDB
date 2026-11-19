<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use LogicException;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Install\DataFolderResult;
use TiffinDB\Core\Install\InstallMessage;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Core\Install\ProbeTarget;
use TiffinDB\Core\Install\Requirement;
use TiffinDB\Core\Install\RequirementReport;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The installer screens (`docs/design/m1/installer.md`; M1 spec part 2
 * section 3.2), all under `/setup`:
 *
 * - show (GET /setup): step 1, the requirement check, in NeedsDataFolder;
 *   step 3, the first-Admin form, in NeedsAdmin (after ensureSetupCode()).
 * - dataFolder (POST /setup/data-folder): prepares the data folder; a safe
 *   result redirects to /setup, any other renders step 2 with status 409.
 * - admin (POST /setup/admin): creates the first Admin, signs them in, and
 *   redirects to /projects; problems render step 3 with status 422. A POST
 *   that loses the race to another request that created the first Admin
 *   redirects to /sign-in (T-CF-3).
 *
 * The installation state is read once per request through
 * Services::installState() (Installer::state() is not read-only). A POST
 * that arrives in the wrong state redirects to /setup without calling the
 * installer. Passwords and the setup code are never printed back.
 *
 * Probe target: built with ProbeTarget::tryCreate(); null (so the verdict
 * is `inconclusive`) when the Host, SERVER_ADDR, or SERVER_PORT value is
 * missing or invalid, and also when SERVER_PORT is not the default port of
 * the request's scheme (80, or 443 when secure) and does not equal the port
 * written in the Host header (M1-WP6 review F2).
 *
 * This port rule gives no protection under Apache `UseCanonicalName Off`:
 * there SERVER_PORT follows the client's Host port, so a client that names
 * a port in Host gets it accepted. Its only effect is to fail closed when
 * SERVER_PORT is not the default and the Host header names no port. The
 * probe's positive control (P75) keeps a forged port from giving `safe`;
 * an operator-configured probe port is planned for M7 (P80).
 */
final class SetupController
{
    public const TEMPLATE_CHECK = 'setup/check';
    public const TEMPLATE_DATA_FOLDER = 'setup/data-folder';
    public const TEMPLATE_ADMIN = 'setup/admin';

    /** Core field names on the first-Admin form => element ids (installer.md section 5). */
    public const FIELD_IDS = [
        'setup_code' => 'setup-code',
        'display_name' => 'setup-name',
        'username' => 'setup-username',
        'password' => 'setup-password',
    ];

    public const REPEAT_ID = 'setup-repeat';

    private const STEP_DONE = 'done';
    private const STEP_NOW = 'now';
    private const STEP_LATER = 'later';

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        return match ($this->context->services()->installState()) {
            InstallState::NeedsDataFolder => $this->checkPage($request),
            InstallState::NeedsAdmin => $this->adminPage(),
            InstallState::Installed => $this->context->errorPage(404, 'error.404.title', 'error.404.body'),
        };
    }

    /** @param array<string, string> $params */
    public function dataFolder(Request $request, array $params = []): Response
    {
        $services = $this->context->services();
        if ($services->installState() !== InstallState::NeedsDataFolder) {
            return $this->context->redirect('/setup');
        }
        $result = $services->installer()->prepareDataFolder(
            $request->documentRoot,
            $this->probeTarget($request),
            $services->probe(),
        );
        if ($result->outcome === DataFolderResult::SAFE) {
            return $this->context->redirect('/setup');
        }

        return $this->context->plainPage(self::TEMPLATE_DATA_FOLDER, $this->base(2) + [
            'outcome' => $result->outcome,
            'failureKey' => $result->failureKey,
            // DataDirectory's label (a relative path, or a fixed phrase for a
            // configured folder): printed as a value, escaped, never as a key.
            'label' => $result->label,
        ], 409);
    }

    /** @param array<string, string> $params */
    public function admin(Request $request, array $params = []): Response
    {
        $services = $this->context->services();
        if ($services->installState() !== InstallState::NeedsAdmin) {
            return $this->context->redirect('/setup');
        }
        $values = [
            'display_name' => $request->input('display_name'),
            'username' => $request->input('username'),
        ];
        $password = $request->input('password');
        if (!hash_equals($password, $request->input('repeat'))) {
            return $this->adminPage($values, [], true);
        }

        try {
            $session = $services->installer()->createFirstAdmin(
                $request->input('setup_code'),
                $values['display_name'],
                $values['username'],
                $password,
                $request->clientAddress,
            );
        } catch (ValidationFailed $failed) {
            return $this->adminPage($values, $failed->problems, false);
        } catch (LogicException $e) {
            // T-CF-3: another request created the first Admin after this one
            // read the state. The installer refuses with a LogicException;
            // when a fresh look at the state confirms the installation is
            // complete, the person is sent to sign in instead of a 500.
            if ($services->installer()->state() !== InstallState::Installed) {
                throw $e;
            }

            return $this->context->redirect('/sign-in');
        }

        $response = $this->context->withNewSession($this->context->redirect('/projects'), $session);
        $this->context->flash('notice.setup.done');

        return $response;
    }

    /**
     * The probe target for this request, or null when it cannot be trusted.
     */
    private function probeTarget(Request $request): ?ProbeTarget
    {
        if ($request->host === null || $request->serverPort === null) {
            return null;
        }
        if (!self::portAcceptable($request->serverPort, $request->host, $request->secure)) {
            return null;
        }

        return ProbeTarget::tryCreate(
            $request->host,
            $request->serverAddress,
            $request->serverPort,
            $request->secure,
            $request->documentRoot,
            // The percent-encoded base path, as it appears in a request line.
            substr($this->context->url('/'), 0, -1),
        );
    }

    /**
     * True when $port is the scheme's default port, or equals the port the
     * Host header names explicitly.
     */
    public static function portAcceptable(int $port, string $host, bool $secure): bool
    {
        if ($port === ($secure ? 443 : 80)) {
            return true;
        }

        return self::hostPort($host) === $port;
    }

    /** The port written in a Host value (`name:port`, `[v6]:port`), or null when none is. */
    public static function hostPort(string $host): ?int
    {
        $close = strrpos($host, ']');
        $colon = strrpos($host, ':');
        if ($colon === false || ($close !== false && $colon < $close)) {
            return null;
        }
        $digits = substr($host, $colon + 1);
        if (preg_match('/^\d{1,5}\z/', $digits) !== 1) {
            return null;
        }

        return (int) $digits;
    }

    private function checkPage(Request $request): Response
    {
        $report = $this->context->services()->installer()->requirements($request->secure, $request->documentRoot);

        return $this->context->plainPage(self::TEMPLATE_CHECK, $this->base(1) + [
            'checks' => self::checkRows($report),
            'canContinue' => $report->canContinue(),
        ]);
    }

    /**
     * @param array{display_name: string, username: string} $values
     * @param list<Problem>                                  $problems
     */
    private function adminPage(array $values = ['display_name' => '', 'username' => ''], array $problems = [], bool $mismatch = false): Response
    {
        $installer = $this->context->services()->installer();
        $installer->ensureSetupCode();
        $location = $installer->setupCodeLocation();
        $view = $this->context->view();

        $summary = [];
        $errors = [];
        foreach ($problems as $problem) {
            $text = $view->format()->problem($problem);
            $id = $problem->field === null ? null : (self::FIELD_IDS[$problem->field] ?? null);
            $summary[] = ['field' => $id, 'text' => $text];
            if ($id !== null) {
                $errors[$id] = isset($errors[$id]) ? $errors[$id] . ' ' . $text : $text;
            }
        }
        if ($mismatch) {
            $summary[] = ['field' => self::REPEAT_ID, 'text' => $view->text('setup.admin.mismatch')];
        }

        $data = $this->base(3) + [
            'script' => 'show-password',
            'codeConfigured' => self::codeConfigured($location),
            'codeLocation' => self::codeLocationValue($location),
            'values' => $values,
            'errors' => $errors,
            'mismatch' => $mismatch,
            'problems' => $summary,
        ];

        return $this->context->plainPage(self::TEMPLATE_ADMIN, $data, $summary === [] ? 200 : 422);
    }

    /**
     * What every step shares: the title, the wide main, and the step
     * indicator with $current as the current step.
     *
     * @return array<string, mixed>
     */
    private function base(int $current): array
    {
        $steps = [];
        foreach ([1, 2, 3] as $number) {
            $steps[] = [
                'number' => $number,
                'state' => match (true) {
                    $number < $current => self::STEP_DONE,
                    $number === $current => self::STEP_NOW,
                    default => self::STEP_LATER,
                },
            ];
        }

        return [
            'title' => $this->context->view()->text('setup.title'),
            'wide' => true,
            'steps' => $steps,
        ];
    }

    /**
     * The table rows of step 1, in the report's order.
     *
     * @return list<array{nameKey: string, status: string, key: string, params: array<string, string|int>}>
     */
    private static function checkRows(RequirementReport $report): array
    {
        $rows = [];
        foreach ($report->requirements as $requirement) {
            /** @var Requirement $requirement */
            if (preg_match('/^[a-z]+\z/', $requirement->id) !== 1 || !str_starts_with($requirement->key, 'setup.check.')) {
                throw new LogicException('Unexpected requirement from the installer.');
            }
            $rows[] = [
                'nameKey' => 'setup.check.' . $requirement->id,
                'status' => $requirement->status,
                'key' => $requirement->key,
                'params' => $requirement->params,
            ];
        }

        return $rows;
    }

    private static function codeConfigured(InstallMessage $location): bool
    {
        return match ($location->key) {
            InstallMessage::CODE_LOCATION => false,
            InstallMessage::CODE_LOCATION_CONFIGURED => true,
            default => throw new LogicException('Unknown setup code location message.'),
        };
    }

    /** The relative path (or, for a configured folder, the file name) the location message names. */
    private static function codeLocationValue(InstallMessage $location): string
    {
        $value = self::codeConfigured($location) ? ($location->params['file'] ?? null) : ($location->params['path'] ?? null);
        if (!is_string($value)) {
            throw new LogicException('The setup code location has no value.');
        }

        return $value;
    }
}
