<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The text of a field's error message (M1 spec part 2 sections 4.4 and
 * 4.5). Ids cannot come from a partial (section 4.3), so the calling
 * template writes the element with its literal id around it, for example
 * `p.error-text#name-error` holding this partial's output, and lists that id in the control's aria-describedby, with
 * aria-invalid="true" on the control.
 *
 * Data: `text`, the problem's plain text.
 */

declare(strict_types=1);

?>
<?= $this->e($data['text']) ?>
