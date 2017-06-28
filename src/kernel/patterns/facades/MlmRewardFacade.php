<?php

/**
 * @author     Jirka Svoboda <code@svobik.com>
 * @copyright  2017 © svobik.com
 * @license    https://www.svobik.com/license.md
 * @timestamp  13/06/2017 09:10
 */

namespace dlds\mlm\kernel\patterns\facades;

use Codeception\Util\Debug;
use dlds\mlm\kernel\exceptions\MlmRewardBuilderError;
use dlds\mlm\kernel\interfaces\MlmParticipantInterface;
use dlds\mlm\kernel\interfaces\MlmRewardInterface;
use dlds\mlm\kernel\interfaces\MlmSubjectInterface;
use dlds\mlm\kernel\interfaces\queries\MlmRewardQueryInterface;
use dlds\mlm\kernel\MlmPocketItem;
use dlds\mlm\kernel\patterns\builders\directors\MlmRewardDirector;
use dlds\mlm\kernel\patterns\builders\interfaces\MlmRewardBuilderInterface;
use dlds\mlm\kernel\patterns\builders\MlmRewardBasicBuilder;
use dlds\mlm\kernel\patterns\builders\MlmRewardCustomBuilder;
use dlds\mlm\kernel\patterns\builders\MlmRewardExtraBuilder;
use dlds\mlm\Mlm;
use yii\base\Exception;
use yii\helpers\ArrayHelper;

/**
 * Class MlmRewardFacade
 * @package dlds\mlm\kernel\patterns\facades
 */
abstract class MlmRewardFacade
{
    const GEN_ALL = 0;
    const GEN_CUSTOM = 5;
    const GEN_BASIC = 10;
    const GEN_EXTRA = 20;

    /**
     * Approves all rewards found by given query
     * when it is expecting approval
     * @param MlmRewardQueryInterface $q
     * @return integer
     */
    public static function approveAll(MlmRewardQueryInterface $q, $delay)
    {
        Mlm::trace($q->createCommand()->rawSql);

        $rewards = $q->all();

        $total = 0;

        foreach ($rewards as $rwd) {

            Mlm::trace(sprintf('Approving %s [%s]', get_class($rwd), $rwd->__mlmPrimaryKey()));

            if (!$rwd->__mlmExpectingApproval($delay) || !$rwd->__mlmApprove()->__mlmSave()) {
                Mlm::trace(sprintf('FAILED approval %s [%s]', get_class($rwd), $rwd->__mlmPrimaryKey()));
                continue;
            }

            $total += 1;
        }

        return $total;
    }

    /**
     * Denies all rewards found by given query
     * when it is expecting approval
     * @param MlmRewardQueryInterface $q
     * @return integer
     */
    public static function denyAll(MlmRewardQueryInterface $q, $delay)
    {
        Mlm::trace($q->createCommand()->rawSql);

        $rewards = $q->all();

        $total = 0;

        foreach ($rewards as $rwd) {

            Mlm::trace(sprintf('Denying %s [%s]', get_class($rwd), $rwd->__mlmPrimaryKey()));

            if (!$rwd->__mlmExpectingDeny($delay) || !$rwd->__mlmDeny()->__mlmSave()) {
                Mlm::trace(sprintf('FAILED deny %s [%s]', get_class($rwd), $rwd->__mlmPrimaryKey()));
                continue;
            }

            $total += 1;
        }

        return $total;
    }

    /**
     * Generates all allowed rewards for given subject
     * @param MlmSubjectInterface $subject
     * @return integer
     */
    public static function generateAll(MlmSubjectInterface $subject)
    {
        Mlm::trace(sprintf('Generating for %s [%s] at %s', get_class($subject), $subject->__mlmPrimaryKey(), time()), true);

        Mlm::pocket()->clear();

        $size = Mlm::pocket()->size();

        foreach (static::generatorProcedures() as $procedure) {

            $transaction = \Yii::$app->db->beginTransaction();

            try {

                call_user_func($procedure, $subject);

                $transaction->commit();

            } catch (MlmRewardBuilderError $e) {

                $transaction->rollBack();

                Mlm::trace($e->getErrors(true));
                \Yii::error($e->getErrors(true));

            } catch (Exception $e) {

                $transaction->rollBack();

                Mlm::trace($e->getMessage());
                \Yii::error($e->getMessage());
            }
        }

        return Mlm::pocket()->size($size);
    }

    /**
     * Creates basic rewards for given subject
     * ---
     * @param MlmSubjectInterface $subject
     * @return bool|integer
     */
    public static function generateBasic(MlmSubjectInterface $subject)
    {
        if (!$subject->__mlmCanRewardByBasic($subject)) {

            Mlm::trace(sprintf('Prevented BASIC at %s', time()));

            return false;
        }

        Mlm::trace(sprintf('Generating BASIC at %s', time()));

        $builder = MlmRewardBasicBuilder::instance($subject);

        $profiteers = MlmSubjectFacade::profiteersBasic($subject);

        return static::generate($builder, $profiteers);
    }

    /**
     * Creates extra rewards for given subject
     * ---
     * @param MlmSubjectInterface $subject
     * @return bool|integer
     */
    public static function generateExtra(MlmSubjectInterface $subject)
    {
        if (!$subject->__mlmCanRewardByExtra($subject)) {

            Mlm::trace(sprintf('Prevented EXTRA at %s', time()));

            return false;
        }

        Mlm::trace(sprintf('Generating EXTRA at %s', time()));

        $builder = MlmRewardExtraBuilder::instance($subject);

        $profiteers = MlmSubjectFacade::profiteersExtra($subject);

        return static::generate($builder, $profiteers);

    }

    /**
     * Creates custom rewards for given subject
     * ---
     * @param MlmSubjectInterface $subject
     * @return bool|integer
     */
    public static function generateCustom(MlmSubjectInterface $subject)
    {
        if (!$subject->__mlmCanRewardByCustom($subject)) {

            Mlm::trace(sprintf('Prevented CUSTOM at %s', time()));

            return false;
        }

        Mlm::trace(sprintf('Generating CUSTOM at %s', time()));

        $builder = MlmRewardCustomBuilder::instance($subject);

        $profiteers = MlmSubjectFacade::profiteersCustom($subject);

        return static::generate($builder, $profiteers);
    }

    /**
     * Process generation
     * @param MlmRewardBuilderInterface $builder
     * @param MlmParticipantInterface[] $profiteers
     * @return bool|integer
     * @throws MlmRewardBuilderError
     */
    protected static function generate(MlmRewardBuilderInterface $builder, array $profiteers)
    {
        if (!$profiteers) {
            return false;
        }

        $pocket = Mlm::pocket();

        $size = $pocket->size();

        $director = MlmRewardDirector::instance();

        foreach ($profiteers as $p) {

            $result = $director->build($builder, $p);

            if (!$result->__mlmValue() && Mlm::cfgSkipWorthless()) {
                \Yii::info(['Reward has been skipped due its worthless', $result->__mlmAttributes()], Mlm::cfgKey());
                continue;
            }

            if (!$result || !$result->__mlmSave()) {
                throw MlmRewardBuilderError::factory($builder, 'Not able to save builder result.');
            }

            \Yii::trace(['Reward has been sucessfully created', $result->__mlmAttributes()], Mlm::cfgKey());

            $pocket->add(new MlmPocketItem($result));
        }

        return $pocket->size($size);
    }

    /**
     * @return array
     */
    protected static function generatorProcedures()
    {
        return [
            function ($subject) {
                return static::generateBasic($subject);
            },
            function ($subject) {
                return static::generateExtra($subject);
            },
            function ($subject) {
                return static::generateCustom($subject);
            },
        ];
    }

}
