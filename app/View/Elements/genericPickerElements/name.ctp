<?php if (isset($preIcon)): ?>
    <i class="<?= sprintf('%s %s', $this->FontAwesome->findNamespace(str_replace('fa-', '', $preIcon)), h($preIcon)) ?>"></i>
<?php endif;

if (is_array($name)) {
    if (isset($name['label'])) {
        $label = $name['label'];
        $name = $name['name'];
        $background_color = isset($label['background']) ? h($label['background']) : '#ffffff';
        $color = isset($label['background']) ? $this->TextColour->getTextColour($label['background']) : '#0088cc';

        echo '<span href="#" class="tagComplete" style="background-color:' .  h($background_color) . ';color: ' . h($color) . '">' . h($name) . '</span>';
    } else {
        echo h($name);
    }
} else {
    echo h($name);
}

if (isset($infoExtra) && !is_array($infoExtra)): ?>
    <?php if (strlen($infoExtra) < 50 && !(isset($forceIcon) && $forceIcon)): ?>
        <i style="float:right; font-size: smaller;margin-right: 5px;"><?php echo h($infoExtra); ?></i>
    <?php else: ?>
        <i class="fa fa-info-circle generic-picker-item-element-info" style="float:right;margin-left: 5px;line-height:13px;" title="<?php echo h($infoExtra); ?>"></i>
    <?php endif; ?>

<?php elseif (isset($infoExtra['type'])): // same as if infoExtra is not an array ?>
    <?php if ($infoExtra['type'] === 'text'): ?>
        <?php if (strlen($infoExtra) < 50 && !(isset($forceIcon) && $forceIcon)): ?>
            <i style="float:right;font-size: smaller;margin-right: 5px;"><?php echo h($infoExtra); ?></i>
        <?php else: ?>
            <i class="fa fa-info-circle generic-picker-item-element-info" style="float:right;line-height:13px;margin-left: 5px;" title="<?php echo h($infoExtra); ?>"></i>
        <?php endif; ?>

    <?php elseif ($infoExtra['type'] === 'check'): ?>
        <?php $checkType = isset($infoExtra['checked']) && $infoExtra['checked'] == true ? 'fa-check' : 'fa-times'; ?>
        <?php $checkColor = isset($infoExtra['checked']) && $infoExtra['checked'] == true ? '#449d44' : '#c9302c'; ?>
        <i class="generic-picker-item-element-check" style="background-color: <?php echo $checkColor; ?>">
            <i>
                <?php echo isset($infoExtra['text']) ? h($infoExtra['text']) : ""; ?>
            </i>
            <i style="margin-right: 0;line-height:13px;" class="fa <?php echo $checkType; ?>"></i>
        </i>

    <?php elseif ($infoExtra['type'] === 'table'): ?>

    <?php endif; ?>
<?php endif; ?>

<?php if (isset($infoContextual)): ?>
    <div class="apply_css_arrow" style="padding-left: 5px; font-size: smaller;"><i><?= h($infoContextual) ?></i></div>
<?php endif; ?>
