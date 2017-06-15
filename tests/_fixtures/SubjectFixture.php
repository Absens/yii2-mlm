<?php
/**
 * @author     Jirka Svoboda <code@svobik.com>
 * @copyright  2017 © svobik.com
 * @license    https://www.svobik.com/license.md
 * @timestamp  01/06/2017 17:04
 */

namespace dlds\mlm\tests\_fixtures;

use dlds\mlm\app\models\Subject;
use yii\test\ActiveFixture;

class SubjectFixture extends ActiveFixture
{

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->modelClass = Subject::className();

        parent::init();
    }
}