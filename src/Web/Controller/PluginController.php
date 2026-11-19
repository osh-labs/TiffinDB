<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\PluginOperations;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The plugins screens (docs/design/m3/plugins.md; M3 spec part 2 section
 * 3.1): the list, a plugin's page, and the confirmation before turning on
 * a plugin that did not come with TiffinDB.
 *
 * PluginOperations refuses everyone but an active Admin before it looks
 * any plugin up (the 403 page, so a Member learns nothing about what is
 * installed), and gives NotFound for a kind and id it did not discover.
 * Turning on and off are POST only (the kernel checks the CSRF token);
 * turning on an added plugin also needs the confirmation form's
 * `confirm` field, so the plain "Turn on" form of a shipped plugin
 * cannot be replayed against an added one.
 *
 * Every text from a manifest (name, version, licence, description) is
 * plain text, printed with e() and passed to t() only as a parameter,
 * never used as a string key (P133). Status and reason texts are chosen
 * from the core's codes through the literal tables below.
 */
final class PluginController
{
    /** Status code => string key and template status word. */
    private const STATUSES = [
        'enabled' => 'plugins.status.enabled',
        'disabled' => 'plugins.status.disabled',
        'unavailable' => 'plugins.status.unavailable',
        'broken' => 'plugins.status.broken',
    ];

    /** Broken reason code (ManifestInvalid and PluginScanner) => string key. */
    private const REASONS = [
        'manifest-missing' => 'plugins.reason.manifest_missing',
        'manifest-link' => 'plugins.reason.manifest_link',
        'too-large' => 'plugins.reason.too_large',
        'not-utf8' => 'plugins.reason.not_utf8',
        'php-tag' => 'plugins.reason.php_tag',
        'duplicate-member' => 'plugins.reason.duplicate_member',
        'not-json' => 'plugins.reason.not_json',
        'too-deep' => 'plugins.reason.too_deep',
        'not-an-object' => 'plugins.reason.not_an_object',
        'unknown-member' => 'plugins.reason.unknown_member',
        'missing-member' => 'plugins.reason.missing_member',
        'bad-api' => 'plugins.reason.bad_api',
        'bad-id' => 'plugins.reason.bad_id',
        'id-mismatch' => 'plugins.reason.id_mismatch',
        'kind-mismatch' => 'plugins.reason.kind_mismatch',
        'bad-name' => 'plugins.reason.bad_name',
        'bad-description' => 'plugins.reason.bad_description',
        'bad-version' => 'plugins.reason.bad_version',
        'bad-class' => 'plugins.reason.bad_class',
        'bad-licence' => 'plugins.reason.bad_licence',
        'bad-extensions' => 'plugins.reason.bad_extensions',
        'bad-media-type' => 'plugins.reason.bad_media_type',
        'bad-scopes' => 'plugins.reason.bad_scopes',
        'bad-requires-extensions' => 'plugins.reason.bad_requires_extensions',
        'src-missing' => 'plugins.reason.src_missing',
        'src-outside' => 'plugins.reason.src_outside',
        'class-file-missing' => 'plugins.reason.class_file_missing',
        'too-many' => 'plugins.reason.too_many',
        'namespace-clash' => 'plugins.reason.namespace_clash',
        'unreadable' => 'plugins.reason.unreadable',
    ];

    private const REASON_OTHER = 'plugins.reason.other';

    public function __construct(private readonly Context $context)
    {
    }

    /** GET /admin/plugins. */
    public function index(Request $request): Response
    {
        $import = [];
        $export = [];
        foreach ($this->plugins()->list($this->context->actor()) as $info) {
            $row = $this->describe($info);
            if ($row['kind'] === 'import') {
                $import[] = $row;
            } else {
                $export[] = $row;
            }
        }
        $view = $this->context->view();

        return $this->context->page('plugins/index', [
            'title' => $view->text('plugins.title'),
            'layout' => 'io',
            'breadcrumb' => [['label' => $view->text('plugins.crumb'), 'path' => null]],
            'import' => $import,
            'export' => $export,
        ], 200, 'plugins');
    }

    /** @param array<string, string> $params GET /admin/plugins/{kind}/{plugin} */
    public function show(Request $request, array $params): Response
    {
        return $this->showPage($this->find($params), [], 200);
    }

    /** @param array<string, string> $params GET /admin/plugins/{kind}/{plugin}/enable */
    public function enableForm(Request $request, array $params): Response
    {
        $plugin = $this->find($params);
        if ($plugin['status'] !== 'disabled' || $plugin['builtIn'] === true) {
            return $this->context->redirect($plugin['path']);
        }
        $view = $this->context->view();

        return $this->context->page('plugins/enable', [
            'title' => $view->text('plugins.confirm.title', ['name' => $plugin['name']]),
            'layout' => 'io',
            'breadcrumb' => $this->crumbs($plugin),
            'plugin' => $plugin,
            'facts' => [
                ['term' => 'plugins.version', 'value' => $plugin['version']],
                ['term' => 'plugins.licence', 'value' => $plugin['licence']],
                ['term' => 'plugins.folder', 'value' => $plugin['folder']],
            ],
        ], 200, 'plugins');
    }

    /** @param array<string, string> $params POST /admin/plugins/{kind}/{plugin}/enable */
    public function enable(Request $request, array $params): Response
    {
        $plugin = $this->find($params);
        if ($plugin['status'] === 'disabled' && $plugin['builtIn'] !== true && $request->input('confirm') !== '1') {
            return $this->context->redirect($plugin['path'] . '/enable');
        }
        try {
            $this->plugins()->enable($this->context->actor(), $plugin['kind'], $plugin['id']);
        } catch (ValidationFailed $failed) {
            return $this->showPage($plugin, $failed->problems, 422);
        }
        $this->context->flash('notice.plugin_enabled', ['name' => $plugin['name']]);

        return $this->context->redirect($plugin['path']);
    }

    /** @param array<string, string> $params POST /admin/plugins/{kind}/{plugin}/disable */
    public function disable(Request $request, array $params): Response
    {
        $plugin = $this->find($params);
        try {
            $this->plugins()->disable($this->context->actor(), $plugin['kind'], $plugin['id']);
        } catch (ValidationFailed $failed) {
            return $this->showPage($plugin, $failed->problems, 422);
        }
        $this->context->flash('notice.plugin_disabled', ['name' => $plugin['name']]);

        return $this->context->redirect($plugin['path']);
    }

    /**
     * The plugin the route names, described for the templates.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function find(array $params): array
    {
        return $this->describe($this->plugins()->get($this->context->actor(), $params['kind'] ?? '', $params['plugin'] ?? ''));
    }

    /**
     * A discovered plugin (Core\Io\Plugin\PluginInfo, read through its
     * public properties) as plain values: every text plain, keys from the
     * literal tables only.
     *
     * @return array<string, mixed>
     */
    private function describe(object $info): array
    {
        $view = $this->context->view();
        $kind = $info->kind === 'export' ? 'export' : 'import';
        $id = (string) $info->id;
        $status = (string) $info->status->value;
        $manifest = $info->manifest;
        $extensions = [];
        foreach ($manifest === null ? [] : $manifest->extensions as $extension) {
            $extensions[] = '.' . $extension;
        }
        $extensionText = implode(', ', $extensions);
        $mediaType = $manifest?->mediaType;
        $version = $manifest === null ? '' : (string) $manifest->version;
        $name = $manifest === null ? $view->text('plugins.unnamed', ['id' => $id]) : (string) $manifest->name;
        $reason = $status === 'broken' ? (self::REASONS[(string) $info->brokenReason] ?? self::REASON_OTHER) : null;

        return [
            'kind' => $kind,
            'id' => $id,
            'path' => '/admin/plugins/' . $kind . '/' . $id,
            'folder' => 'plugins/' . $kind . '/' . $id,
            'name' => $name,
            'status' => isset(self::STATUSES[$status]) ? $status : 'broken',
            'statusKey' => self::STATUSES[$status] ?? self::STATUSES['broken'],
            'builtIn' => $info->builtIn === true,
            'hasManifest' => $manifest !== null,
            'version' => $version,
            'licence' => $manifest === null ? '' : (string) $manifest->licence,
            'description' => $manifest === null ? '' : (string) $manifest->description,
            'facts' => $manifest === null ? null : ($kind === 'export'
                ? $view->text('plugins.row_facts_export', ['version' => $version, 'extension' => $extensionText])
                : $view->text('plugins.row_facts_import', ['version' => $version, 'extensions' => $extensionText])),
            'files' => is_string($mediaType) ? $extensionText . ' (' . $mediaType . ')' : $extensionText,
            'reasonKey' => $reason,
            'missing' => implode(', ', array_map('strval', $info->missingExtensions)),
        ];
    }

    /**
     * @param array<string, mixed> $plugin
     * @return list<array{label: string, path: string|null}>
     */
    private function crumbs(array $plugin): array
    {
        return [
            ['label' => $this->context->view()->text('plugins.crumb'), 'path' => '/admin/plugins'],
            ['label' => $plugin['name'], 'path' => null],
        ];
    }

    /**
     * @param array<string, mixed> $plugin
     * @param list<Problem>        $problems
     */
    private function showPage(array $plugin, array $problems, int $status): Response
    {
        $view = $this->context->view();
        [$list] = ProjectController::problems($this->context, $problems, []);
        $facts = [['term' => 'plugins.kind', 'value' => $view->text($plugin['kind'] === 'export' ? 'plugins.kind.export' : 'plugins.kind.import')]];
        if ($plugin['hasManifest'] === true) {
            $facts[] = ['term' => 'plugins.version', 'value' => $plugin['version']];
            $facts[] = ['term' => 'plugins.licence', 'value' => $plugin['licence']];
            $facts[] = ['term' => 'plugins.files', 'value' => $plugin['files']];
        }
        $facts[] = ['term' => 'plugins.folder', 'value' => $plugin['folder']];
        $facts[] = ['term' => 'plugins.origin', 'value' => $view->text($plugin['builtIn'] === true ? 'plugins.origin.shipped' : 'plugins.origin.added')];

        return $this->context->page('plugins/show', [
            'title' => $plugin['name'],
            'layout' => 'io',
            'breadcrumb' => $this->crumbs($plugin),
            'problems' => $list,
            'plugin' => $plugin,
            'facts' => $facts,
        ], $status, 'plugins');
    }

    private function plugins(): PluginOperations
    {
        return $this->context->services()->operations()->plugins();
    }
}
