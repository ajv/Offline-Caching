<?php echo $OUTPUT->doctype() ?>
<html <?php echo $OUTPUT->htmlattributes() ?>>
<head>
    <?php echo $OUTPUT->standard_head_html() ?>
    <title><?php echo $PAGE->title ?></title>
    <link rel="shortcut icon" href="<?php echo $CFG->themewww .'/'. current_theme() ?>/favicon.ico" />
    <meta name="description" content="<?php echo strip_tags(format_text($SITE->summary, FORMAT_HTML)) ?>" />
</head>
<body id="<?php echo $PAGE->pagetype ?>" class="<?php echo $PAGE->bodyclasses ?>">
<?php echo $OUTPUT->standard_top_of_body_html() ?>

<div id="page">

    <div id="header-home" class="clearfix">
        <h1 class="headermain"><?php echo $PAGE->heading ?></h1>
        <div class="headermenu"><?php
        if ($menu) {
            echo $menu;
        } else {
            echo $OUTPUT->login_info();
        }
        ?></div>
    </div>
    <hr />
<!-- END OF HEADER -->

<!-- Note, we will not be using tables for layout evenutally. However, for now
     I have enough other things to worry about that I don't have time to make
     a multi-column cross-browser layout too, so this is a temporary hack. -->
    <table id="layout-table" summary="layout">
        <tr>
            <?php if ($PAGE->blocks->region_has_content('side-pre')) { ?>
            <td id="region-side-pre" class="block-region">
                <?php echo $OUTPUT->blocks_for_region('side-pre') ?>
            </td>
            <?php } ?>
            <td id="content">
                [MAIN CONTENT GOES HERE]
            </td>
            <?php if ($PAGE->blocks->region_has_content('side-post')) { ?>
            <td id="region-side-post" class="block-region">
                <?php echo $OUTPUT->blocks_for_region('side-post') ?>
            </td>
            <?php } ?>
        </tr>
    </table>

<!-- START OF FOOTER -->
    <div id="footer" class="clearfix">
        <p class="helplink">
        <?php echo page_doc_link(get_string('moodledocslink')) ?>
        </p>

        <?php
        echo $OUTPUT->login_info();
        echo $OUTPUT->home_link();
        echo $OUTPUT->standard_footer_html();
        ?>
    </div>
</div>
<?php echo $OUTPUT->standard_end_of_body_html() ?>
</body>
</html>