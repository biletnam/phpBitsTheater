<?php
use BitsTheater\scenes\Account as MyScene;
/* @var $recite MyScene */
/* @var $v MyScene */
use com\blackmoonit\Strings;
use com\blackmoonit\Widgets;
use com\blackmoonit\FinallyBlock;

$h = $v->cueActor('Fragments', 'get', 'csrf_header_jquery');
$recite->includeMyHeader($h);
$w = '';

$v->jsCode = <<<EOD
$(document).ready(function(){
var d = new BitsAuthBasicAccounts('{$v->getSiteURL('account/ajajCreate')}','{$v->getSiteURL('account/ajajUpdate')}').setup();

EOD;
//closer of above ready function done in FinallyBlock
?>
<!-- fake fields are a workaround for chrome autofill finding the dialog fields -->
<input style="display:none" type="email" name="fakeemailautofill"/>
<input style="display:none" type="password" name="fakepasswordautofill"/>
<?php

print($v->cueActor('Fragments', 'get', 'js-dialog_error'));
print($v->cueActor('Fragments', 'get', 'accounts-dialog_account',
		array( 'auth_groups' => $v->auth_groups )
));

$w .= "<h2>{$v->getRes('account/title_acctlist')}</h2>\n";
$w .= $v->renderMyUserMsgsAsString();

$theAddButton = Widgets::buildButton('btn_add_account')->addClass('btn-primary');
$theAddButton->append($v->getRes('account/label_button_add_account'));
if (!$v->isAllowed('account','create'))
	$theAddButton->addClass('invisible');
$w .= $theAddButton->render();
$w .= '<br />' . PHP_EOL;

$w .= '<div class="panel panel-default">';
//$w .= '<div class="panel-heading">Panel heading</div>';

$thePager = $v->getPagerHtml($v->_action);
$w .= $thePager;
$w .= '<table class="db-display table">';

$w .= '<thead class="rowh">';
foreach ($v->table_cols as $theFieldName => $theColInfo) {
	$w .= $v->getColHeaderHTML($theFieldName, $theColInfo['style']);
}
$w .= "</thead>\n";
print($w);

print("<tbody>\n");
//since we can get exceptions in for loop, let us use a FinallyBlock
$w = '</tbody></table>';
$w .= $thePager;
$w .= '<br /></div>' . PHP_EOL;
$theFinalEnclosure = new FinallyBlock(function($v, $w) {
	//print anything left in our "what to print" buffer
	print($w);
	//print out our JS code we may have at end of html body
	print($v->createJsTagBlock($v->jsCode.'});'));
	//print out normal footer stuff
	print(str_repeat('<br />',8));
	$v->includeMyFooter();
}, $v, $w);

//results have *not* been fetched yet, so place inside a TRY block
//  just in case we run into a problem
if (!empty($v->results)) try {
	for ( $theRow = $v->results->fetch(); ($theRow !== false); $theRow = $v->results->fetch() ) {
		$r = '<tr class="'.$v->_rowClass.'">';
		foreach( $v->table_cols as $theFieldname => $theColInfo) {
			$r .= $v->getColCellValue($theFieldname, $theRow) . "\n";
		}
		$r .= "</tr>\n";
		print($r);
	}//end foreach
} catch (Exception $e) {
	$v->errorLog($v->_actor->myClassName.'::'.$v->_action.' failed: '.$e->getMessage() );
	$v->addUserMsg($e->getMessage(), $v::USER_MSG_ERROR);
}
//if the data printed out ok, place a button after the table
print($w); //close the table

$w = '<a href="#" class="scrollup">'.$v->getRes('generic/label_jump_to_top').'</a>';

$theFinalEnclosure->updateArgs($v, $w);
//end (FinallyBlock will print out $w and footer)
