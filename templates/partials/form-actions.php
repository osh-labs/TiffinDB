<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A form's submit button and optional Cancel link (M1 spec part 2 section
 * 4.4; P50). Every form whose actions are one submit button and an
 * optional Cancel link uses it.
 *
 * Data:
 * - `submit` (string key of the button text) and optionally
 *   `submitParams` (its parameters, plain values, as for t()).
 * - `danger` (bool: the destructive style instead of the primary one) or
 *   `secondary` (bool: the plain `button` style, as for "Save projects");
 *   `danger` wins when both are true.
 * - `size`: `large` (the default, `button-large`) or `normal` (no size
 *   class, as for "Save details"); any other value is `large`.
 * - `block` (bool: adds `button-block`, full width).
 * - `cancel` (application path for Cancel, or null for none), and
 *   optionally `cancelLabel` (string key of the link text instead of
 *   `form.cancel`, as for "Go back without saving") with `cancelParams`,
 *   and optionally `cancelQuery` (string map added to the Cancel link;
 *   default none).
 *
 * Each button class list is a literal alternative (no class is built at
 * run time). With only `submit`, `danger`, and `cancel`, the output is
 * the same as before the options were added.
 */

declare(strict_types=1);

?>
<div class="form-actions">
<?php if (($data['danger'] ?? false) === true) : ?>
<?php if (($data['size'] ?? 'large') === 'normal' && ($data['block'] ?? false) === true) : ?>
<button class="button button-danger button-block" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php elseif (($data['size'] ?? 'large') === 'normal') : ?>
<button class="button button-danger" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php elseif (($data['block'] ?? false) === true) : ?>
<button class="button button-large button-danger button-block" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php else : ?>
<button class="button button-large button-danger" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php endif; ?>
<?php elseif (($data['secondary'] ?? false) === true) : ?>
<?php if (($data['size'] ?? 'large') === 'normal' && ($data['block'] ?? false) === true) : ?>
<button class="button button-block" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php elseif (($data['size'] ?? 'large') === 'normal') : ?>
<button class="button" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php elseif (($data['block'] ?? false) === true) : ?>
<button class="button button-large button-block" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php else : ?>
<button class="button button-large" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php endif; ?>
<?php else : ?>
<?php if (($data['size'] ?? 'large') === 'normal' && ($data['block'] ?? false) === true) : ?>
<button class="button button-primary button-block" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php elseif (($data['size'] ?? 'large') === 'normal') : ?>
<button class="button button-primary" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php elseif (($data['block'] ?? false) === true) : ?>
<button class="button button-large button-primary button-block" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php else : ?>
<button class="button button-large button-primary" type="submit"><?= $this->t($data['submit'], $data['submitParams'] ?? []) ?></button>
<?php endif; ?>
<?php endif; ?>
<?php if (is_string($data['cancel'] ?? null)) : ?>
<?php if (is_string($data['cancelLabel'] ?? null)) : ?>
<a class="form-cancel" href="<?= $this->url($data['cancel'], $data['cancelQuery'] ?? []) ?>"><?= $this->t($data['cancelLabel'], $data['cancelParams'] ?? []) ?></a>
<?php else : ?>
<a class="form-cancel" href="<?= $this->url($data['cancel'], $data['cancelQuery'] ?? []) ?>"><?= $this->t('form.cancel') ?></a>
<?php endif; ?>
<?php endif; ?>
</div>
