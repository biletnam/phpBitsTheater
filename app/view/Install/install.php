<?php
use com\blackmoonit\Widgets;
$recite->includeMyHeader();

$w = '<div align="left">';
$w .= 'Please enter the install passphrase to continue: ';
$w .= '<input type="text" class="post" name="installpw" value="" size="60" maxlength="255" required><br />';
$w .= $recite->continue_button;
$w .= '</div>';

$form_html = Widgets::createHtmlForm($recite->form_name,$recite->next_action,$w,'',false);
print $form_html;

$recite->includeMyFooter();
