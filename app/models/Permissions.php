<?php

namespace BitsTheater\models;
use BitsTheater\models\PropCloset\AuthPermissions as BaseModel;
{//namespace begin

class Permissions extends BaseModel
{
	/**
	 * The name of the model which can be used in IDirected::getProp().
	 * @var string
	 * @since BitsTheater 3.6.1
	 */
	const MODEL_NAME = __CLASS__ ;
	
}//end class

}//end namespace
