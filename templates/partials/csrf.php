<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The CSRF field (M1 spec part 2 section 2.5): the first child of every
 * form with method="post".
 *
 * Data: none; the token comes from the view.
 */

declare(strict_types=1);

?>
<input type="hidden" name="_csrf" value="<?= $this->csrf() ?>" />
