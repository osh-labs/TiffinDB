<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Reading and display settings (docs/design/m2/settings.md sections 2
 * and 4): five fieldsets of radios and the reduced-movement checkbox,
 * Save settings and Back to standard (formaction /settings/reset), and
 * the preview, whose class list the settings-preview script changes.
 *
 * Every class name and every radio value here is a literal: the template
 * only compares the stored values with them, so no class is ever taken
 * from a request (ADR 0008 decision 11). The preview's classes are those
 * of the checked values; the accent is left out for a strong contrast
 * theme.
 *
 * Data: `values` (column => checked value: `theme`, `accent`, `typeface`,
 * `text_size`, `line_spacing` as stored, `reduce_motion` '1' or ''),
 * `errors` (fieldset id => problem text, at 422).
 */

declare(strict_types=1);

?>
<?php $v = $data['values']; ?>
<?php $hc = in_array($v['theme'], ['hc-light', 'hc-dark'], true); ?>
<div>
<h1><?= $this->t('settings.title') ?></h1>
<p class="lead"><?= $this->t('settings.lead') ?></p>
</div>
<div class="settings-layout">
<form id="settings-form" class="form settings-form" method="post" action="<?= $this->url('/settings') ?>">
<?= $this->partial('csrf') ?>
<?php if (is_string($data['errors']['settings-theme'] ?? null)) : ?>
<fieldset class="fieldset-card settings-section" id="settings-theme" aria-describedby="settings-theme-error">
<?php else : ?>
<fieldset class="fieldset-card settings-section" id="settings-theme">
<?php endif; ?>
<legend class="legend"><?= $this->t('settings.theme') ?></legend>
<?php if (is_string($data['errors']['settings-theme'] ?? null)) : ?>
<p class="error-text" id="settings-theme-error"><?= $this->partial('field-error', ['text' => $data['errors']['settings-theme']]) ?></p>
<?php endif; ?>
<div class="radio-grid radio-grid-210">
<label class="radio-card"><input type="radio" name="theme" value="light" data-preview=""<?php if ($v['theme'] === 'light') : ?> checked="checked"<?php endif; ?> /><span class="swatch" aria-hidden="true"><?= $this->t('settings.swatch') ?></span><span><?= $this->t('settings.theme.light') ?></span></label>
<label class="radio-card"><input type="radio" name="theme" value="dark" data-preview="t-dark"<?php if ($v['theme'] === 'dark') : ?> checked="checked"<?php endif; ?> /><span class="swatch t-dark" aria-hidden="true"><?= $this->t('settings.swatch') ?></span><span><?= $this->t('settings.theme.dark') ?></span></label>
<label class="radio-card"><input type="radio" name="theme" value="hc-light" data-preview="t-hcl"<?php if ($v['theme'] === 'hc-light') : ?> checked="checked"<?php endif; ?> /><span class="swatch t-hcl" aria-hidden="true"><?= $this->t('settings.swatch') ?></span><span><?= $this->t('settings.theme.hc_light') ?></span></label>
<label class="radio-card"><input type="radio" name="theme" value="hc-dark" data-preview="t-hcd"<?php if ($v['theme'] === 'hc-dark') : ?> checked="checked"<?php endif; ?> /><span class="swatch t-hcd" aria-hidden="true"><?= $this->t('settings.swatch') ?></span><span><?= $this->t('settings.theme.hc_dark') ?></span></label>
</div>
</fieldset>
<?php if (is_string($data['errors']['settings-accent'] ?? null)) : ?>
<fieldset class="fieldset-card settings-section" id="settings-accent" aria-describedby="settings-accent-error">
<?php else : ?>
<fieldset class="fieldset-card settings-section" id="settings-accent">
<?php endif; ?>
<legend class="legend"><?= $this->t('settings.accent') ?></legend>
<?php if (is_string($data['errors']['settings-accent'] ?? null)) : ?>
<p class="error-text" id="settings-accent-error"><?= $this->partial('field-error', ['text' => $data['errors']['settings-accent']]) ?></p>
<?php endif; ?>
<div class="radio-grid radio-grid-150">
<label class="radio-card"><input type="radio" name="accent" value="teal" data-preview=""<?php if ($v['accent'] === 'teal') : ?> checked="checked"<?php endif; ?> /><span class="swatch-dot" aria-hidden="true"></span><span><?= $this->t('settings.accent.teal') ?></span></label>
<label class="radio-card"><input type="radio" name="accent" value="indigo" data-preview="a-indigo"<?php if ($v['accent'] === 'indigo') : ?> checked="checked"<?php endif; ?> /><span class="swatch-dot a-indigo" aria-hidden="true"></span><span><?= $this->t('settings.accent.indigo') ?></span></label>
<label class="radio-card"><input type="radio" name="accent" value="plum" data-preview="a-plum"<?php if ($v['accent'] === 'plum') : ?> checked="checked"<?php endif; ?> /><span class="swatch-dot a-plum" aria-hidden="true"></span><span><?= $this->t('settings.accent.plum') ?></span></label>
<label class="radio-card"><input type="radio" name="accent" value="graphite" data-preview="a-graphite"<?php if ($v['accent'] === 'graphite') : ?> checked="checked"<?php endif; ?> /><span class="swatch-dot a-graphite" aria-hidden="true"></span><span><?= $this->t('settings.accent.graphite') ?></span></label>
</div>
<p class="help"><?= $this->t('settings.accent_help') ?></p>
</fieldset>
<?php if (is_string($data['errors']['settings-size'] ?? null)) : ?>
<fieldset class="fieldset-card settings-section settings-section-tight" id="settings-size" aria-describedby="settings-size-error">
<?php else : ?>
<fieldset class="fieldset-card settings-section settings-section-tight" id="settings-size">
<?php endif; ?>
<legend class="legend"><?= $this->t('settings.size') ?></legend>
<?php if (is_string($data['errors']['settings-size'] ?? null)) : ?>
<p class="error-text" id="settings-size-error"><?= $this->partial('field-error', ['text' => $data['errors']['settings-size']]) ?></p>
<?php endif; ?>
<div class="size-row">
<label class="check size-option size-small"><input type="radio" name="text_size" value="small" data-preview="pv-s-small"<?php if ($v['text_size'] === 'small') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('settings.size.small') ?></span></label>
<label class="check size-option size-standard"><input type="radio" name="text_size" value="standard" data-preview="pv-s-standard"<?php if ($v['text_size'] === 'standard') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('settings.size.standard') ?></span></label>
<label class="check size-option size-large"><input type="radio" name="text_size" value="large" data-preview="pv-s-large"<?php if ($v['text_size'] === 'large') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('settings.size.large') ?></span></label>
<label class="check size-option size-larger"><input type="radio" name="text_size" value="larger" data-preview="pv-s-larger"<?php if ($v['text_size'] === 'larger') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('settings.size.larger') ?></span></label>
<label class="check size-option size-largest"><input type="radio" name="text_size" value="largest" data-preview="pv-s-largest"<?php if ($v['text_size'] === 'largest') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('settings.size.largest') ?></span></label>
</div>
</fieldset>
<?php if (is_string($data['errors']['settings-spacing'] ?? null)) : ?>
<fieldset class="fieldset-card settings-section settings-section-tight" id="settings-spacing" aria-describedby="settings-spacing-error">
<?php else : ?>
<fieldset class="fieldset-card settings-section settings-section-tight" id="settings-spacing">
<?php endif; ?>
<legend class="legend"><?= $this->t('settings.spacing') ?></legend>
<?php if (is_string($data['errors']['settings-spacing'] ?? null)) : ?>
<p class="error-text" id="settings-spacing-error"><?= $this->partial('field-error', ['text' => $data['errors']['settings-spacing']]) ?></p>
<?php endif; ?>
<div class="size-row">
<label class="check"><input type="radio" name="line_spacing" value="standard" data-preview="pv-l-standard"<?php if ($v['line_spacing'] === 'standard') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('settings.spacing.standard') ?></span></label>
<label class="check"><input type="radio" name="line_spacing" value="roomy" data-preview="pv-l-roomy"<?php if ($v['line_spacing'] === 'roomy') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('settings.spacing.roomy') ?></span></label>
<label class="check"><input type="radio" name="line_spacing" value="very-roomy" data-preview="pv-l-very-roomy"<?php if ($v['line_spacing'] === 'very-roomy') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('settings.spacing.very_roomy') ?></span></label>
</div>
</fieldset>
<?php if (is_string($data['errors']['settings-lettering'] ?? null)) : ?>
<fieldset class="fieldset-card settings-section settings-section-tight" id="settings-lettering" aria-describedby="settings-lettering-error">
<?php else : ?>
<fieldset class="fieldset-card settings-section settings-section-tight" id="settings-lettering">
<?php endif; ?>
<legend class="legend"><?= $this->t('settings.lettering') ?></legend>
<?php if (is_string($data['errors']['settings-lettering'] ?? null)) : ?>
<p class="error-text" id="settings-lettering-error"><?= $this->partial('field-error', ['text' => $data['errors']['settings-lettering']]) ?></p>
<?php endif; ?>
<div class="radio-grid">
<label class="radio-card face-option f-atk"><input type="radio" name="typeface" value="atkinson" data-preview="f-atk"<?php if ($v['typeface'] === 'atkinson') : ?> checked="checked"<?php endif; ?> /><span><span class="face-name"><?= $this->t('settings.face.atkinson') ?></span><span class="face-hint"><?= $this->t('settings.face.atkinson_hint') ?></span></span></label>
<label class="radio-card face-option f-lexend"><input type="radio" name="typeface" value="lexend" data-preview="f-lexend"<?php if ($v['typeface'] === 'lexend') : ?> checked="checked"<?php endif; ?> /><span><span class="face-name"><?= $this->t('settings.face.lexend') ?></span><span class="face-hint"><?= $this->t('settings.face.lexend_hint') ?></span></span></label>
<label class="radio-card face-option"><input type="radio" name="typeface" value="inter" data-preview=""<?php if ($v['typeface'] === 'inter') : ?> checked="checked"<?php endif; ?> /><span><span class="face-name"><?= $this->t('settings.face.inter') ?></span><span class="face-hint"><?= $this->t('settings.face.inter_hint') ?></span></span></label>
<label class="radio-card face-option f-serif4"><input type="radio" name="typeface" value="source-serif" data-preview="f-serif4"<?php if ($v['typeface'] === 'source-serif') : ?> checked="checked"<?php endif; ?> /><span><span class="face-name"><?= $this->t('settings.face.source_serif') ?></span><span class="face-hint"><?= $this->t('settings.face.source_serif_hint') ?></span></span></label>
<label class="radio-card face-option f-sans"><input type="radio" name="typeface" value="device-sans" data-preview="f-sans"<?php if ($v['typeface'] === 'device-sans') : ?> checked="checked"<?php endif; ?> /><span><span class="face-name"><?= $this->t('settings.face.device_sans') ?></span><span class="face-hint"><?= $this->t('settings.face.device_sans_hint') ?></span></span></label>
<label class="radio-card face-option f-serif"><input type="radio" name="typeface" value="device-serif" data-preview="f-serif"<?php if ($v['typeface'] === 'device-serif') : ?> checked="checked"<?php endif; ?> /><span><span class="face-name"><?= $this->t('settings.face.device_serif') ?></span><span class="face-hint"><?= $this->t('settings.face.device_serif_hint') ?></span></span></label>
</div>
<label class="check settings-divider"><input type="checkbox" name="reduce_motion" value="1"<?php if ($v['reduce_motion'] === '1') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('settings.motion') ?></span></label>
</fieldset>
<div class="form-actions">
<button class="button button-large button-primary" type="submit"><?= $this->t('settings.save') ?></button>
<button class="button button-large" type="submit" formaction="<?= $this->url('/settings/reset') ?>"><?= $this->t('settings.reset') ?></button>
</div>
</form>
<section class="settings-preview" aria-labelledby="preview-title">
<h2 id="preview-title" class="preview-heading"><?= $this->t('settings.preview.heading') ?></h2>
<div id="settings-preview" class="preview-body<?php if ($v['theme'] === 'dark') : ?> t-dark<?php elseif ($v['theme'] === 'hc-light') : ?> t-hcl<?php elseif ($v['theme'] === 'hc-dark') : ?> t-hcd<?php endif; ?><?php if (!$hc && $v['accent'] === 'indigo') : ?> a-indigo<?php elseif (!$hc && $v['accent'] === 'plum') : ?> a-plum<?php elseif (!$hc && $v['accent'] === 'graphite') : ?> a-graphite<?php endif; ?><?php if ($v['typeface'] === 'atkinson') : ?> f-atk<?php elseif ($v['typeface'] === 'lexend') : ?> f-lexend<?php elseif ($v['typeface'] === 'source-serif') : ?> f-serif4<?php elseif ($v['typeface'] === 'device-sans') : ?> f-sans<?php elseif ($v['typeface'] === 'device-serif') : ?> f-serif<?php endif; ?><?php if ($v['text_size'] === 'small') : ?> pv-s-small<?php elseif ($v['text_size'] === 'large') : ?> pv-s-large<?php elseif ($v['text_size'] === 'larger') : ?> pv-s-larger<?php elseif ($v['text_size'] === 'largest') : ?> pv-s-largest<?php else : ?> pv-s-standard<?php endif; ?><?php if ($v['line_spacing'] === 'roomy') : ?> pv-l-roomy<?php elseif ($v['line_spacing'] === 'very-roomy') : ?> pv-l-very-roomy<?php else : ?> pv-l-standard<?php endif; ?>">
<div class="preview-title"><?= $this->t('settings.preview.title') ?></div>
<div><div class="preview-label"><?= $this->t('settings.preview.room_label') ?></div><div><?= $this->t('settings.preview.room') ?></div></div>
<div><div class="preview-label"><?= $this->t('settings.preview.date_label') ?></div><div><?= $this->t('settings.preview.date') ?></div></div>
<div><div class="preview-label"><?= $this->t('settings.preview.notes_label') ?></div><div><?= $this->t('settings.preview.notes') ?></div></div>
<div class="preview-button"><?= $this->t('settings.preview.button') ?></div>
</div>
</section>
</div>
