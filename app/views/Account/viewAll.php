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

print($v->cueActor('Fragments', 'get', 'accounts-dialog_account',
		array( 'auth_groups' => $v->auth_groups )
));

$w .= "<h2>{$v->getRes('account/title_acctlist')}</h2>\n";
$w .= $v->renderMyUserMsgsAsString();

$clsCannotCreate = ($v->isAllowed('account','create')) ? '' : 'invisible';
$labelBtn = $v->getRes('account/label_button_add_account');
$w .= '<button id="btn_add_account" type="button" class="btn btn-primary '.$clsCannotCreate.'">'.$labelBtn.'</button>';
$w .= '<br />'."\n";

$w .= '<div class="panel panel-default">';
//$w .= '<div class="panel-heading">Panel heading</div>';

$w .= '<table class="db-display table">';

$w .= '<thead class="rowh">';
foreach( $v->table_cols as $theFieldname => $theColInfo) {
	$w .= $v->getColHeaderTextForSortableField('view_all',
			$theFieldname, $theColInfo['style']
	);
}
$w .= "</thead>\n";
print($w);

print("<tbody>\n");
//since we can get exceptions in for loop, let us use a FinallyBlock
$w = "</tbody>\n";
$w .= "</table><br/>\n";
$w .= "</div>\n";
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
try {
	for ( $theRow = $v->results->fetch(); ($theRow !== false); $theRow = $v->results->fetch() ) {
		$r = '<tr class="'.$v->_rowClass.'">';
		foreach( $v->table_cols as $theFieldname => $theColInfo) {
			$r .= $v->getColCellValue($theFieldname, $theRow) . "\n";
		}
		$r .= "</tr>\n";
		print($r);
	}//end foreach
} catch (Exception $e) {
	$v->debugLog('account/view_all failed: ' . $e->getMessage() );
	throw $e;
}
//if the data printed out ok, place a button after the table
print($w); //close the table

$w = '<a href="#" class="scrollup">'.$v->getRes('generic/label_jump_to_top').'</a>';

$theFinalEnclosure->updateArgs($v, $w);
//end (FinallyBlock will print out $w and footer)