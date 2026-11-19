<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The form that runs the next part of a long task (M3 spec part 2
 * sections 5.1 and 7): a POST with the CSRF field and one large primary
 * button. With `auto`, the form carries data-auto-continue="1", and
 * import-continue.js submits it once, 400 ms after the page loads, showing
 * the button's data-working text in the progress status; without
 * JavaScript, or with `auto` false (the final "Save the rows", which
 * always waits for a press), the person presses the button.
 *
 * Data: `action` (application path of the POST), `label` (string key of
 * the button), `auto` (bool), optional `working` (string key of the text
 * shown while the next part runs).
 */

declare(strict_types=1);

?>
<?php if (($data['auto'] ?? false) === true) : ?>
<form class="continue-form" method="post" action="<?= $this->url($data['action']) ?>" data-auto-continue="1">
<?php else : ?>
<form class="continue-form" method="post" action="<?= $this->url($data['action']) ?>">
<?php endif; ?>
<?= $this->partial('csrf') ?>
<?php if (is_string($data['working'] ?? null)) : ?>
<button class="button button-primary button-large" type="submit" data-working="<?= $this->t($data['working']) ?>"><?= $this->t($data['label']) ?></button>
<?php else : ?>
<button class="button button-primary button-large" type="submit"><?= $this->t($data['label']) ?></button>
<?php endif; ?>
</form>
