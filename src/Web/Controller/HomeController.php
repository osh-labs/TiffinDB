<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\View;

/**
 * The M0 start page (M0 spec sections 6.9 and 9.3).
 */
final class HomeController
{
    /** Theme name => query for its preview link, in display order. */
    private const PREVIEW_THEMES = [
        'light' => [],
        'dark' => ['theme' => 'dark'],
        'hc-light' => ['theme' => 'hc-light'],
        'hc-dark' => ['theme' => 'hc-dark'],
    ];

    public function __construct(private readonly View $view)
    {
    }

    public function show(Request $request): Response
    {
        return $this->view->page('home', [
            'title' => null,
            'preview' => $this->view->config('appearance_preview') === true ? $this->previewLinks() : null,
        ]);
    }

    /**
     * One link per theme: application path, query array, string key for the
     * link text, and whether it is the theme currently shown.
     *
     * @return list<array{path: string, query: array<string, string>, label: string, current: bool}>
     */
    private function previewLinks(): array
    {
        $current = $this->view->appearance()->theme();
        $links = [];
        foreach (self::PREVIEW_THEMES as $theme => $query) {
            $links[] = [
                'path' => '/',
                'query' => $query,
                'label' => 'home.preview.' . $theme,
                'current' => $theme === $current,
            ];
        }

        return $links;
    }
}
