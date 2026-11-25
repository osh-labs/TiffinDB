<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The About page and the licence texts (docs/design/m7/about.md; M7
 * screens spec section 9).
 *
 * AboutOperations decides what each person sees: server facts and plugins
 * that are off only for Admins. Component names, versions, and licences,
 * plugin texts (P133), and licence texts are plain text, printed with e()
 * by the templates. A `{licence}` that does not fit the id pattern gets
 * the 404 page without a call to the core.
 */
final class AboutController
{
    /** A component id (AboutOperations::ID_PATTERN; M7 screens spec section 9). */
    private const LICENCE_ID = '/^[a-z0-9-]{1,40}\z/';

    /** The component that is TiffinDB itself: linked from the facts, not listed under "Made with". */
    private const SELF = 'tiffindb';

    public function __construct(private readonly Context $context)
    {
    }

    /** GET /about. */
    public function show(Request $request): Response
    {
        $actor = $this->context->actor();
        $facts = $this->context->services()->operations()->about()->about($actor);
        $view = $this->context->view();

        $components = [];
        foreach ($facts->components as $component) {
            if ($component->id === self::SELF) {
                continue;
            }
            $components[] = [
                'id' => $component->id,
                'name' => $component->name,
                'facts' => $component->version === ''
                    ? $view->text('about.component.facts_nover', ['licence' => $component->licence])
                    : $view->text('about.component.facts', ['version' => $component->version, 'licence' => $component->licence]),
            ];
        }
        $plugins = [];
        foreach ($facts->plugins as $plugin) {
            $plugins[] = [
                'name' => $plugin->name,
                'facts' => $view->text('about.plugin.facts', [
                    'kind' => $view->text($plugin->kind === 'import' ? 'about.plugin.kind.import' : 'about.plugin.kind.export'),
                    'version' => $plugin->version,
                    'licence' => $plugin->licence,
                ]),
                'enabled' => $plugin->enabled,
            ];
        }

        return $this->context->page('about/index', [
            'title' => $view->text('about.title'),
            'layout' => 'settings',
            'version' => $facts->version,
            'source' => (string) $view->config('source_url'),
            'components' => $components,
            'plugins' => $plugins,
            'admin' => $actor->isAdmin(),
            'server' => $facts->server,
        ]);
    }

    /** @param array<string, string> $params GET /about/licences/{licence} */
    public function licence(Request $request, array $params): Response
    {
        $id = $params['licence'] ?? '';
        if (preg_match(self::LICENCE_ID, $id) !== 1) {
            throw new NotFound('No such licence.');
        }
        $text = $this->context->services()->operations()->about()->licence($this->context->actor(), $id);
        $files = [];
        foreach ($text->files as $file) {
            $slash = strrpos($file['file'], '/');
            $files[] = [
                'name' => $slash === false ? $file['file'] : substr($file['file'], $slash + 1),
                'path' => $file['file'],
                'text' => $file['text'],
                'cut' => $file['cut'],
            ];
        }

        return $this->context->page('about/licence', [
            'title' => $this->context->view()->text('about.licence.title', ['name' => $text->name]),
            'layout' => 'settings',
            'name' => $text->name,
            'files' => $files,
        ]);
    }
}
